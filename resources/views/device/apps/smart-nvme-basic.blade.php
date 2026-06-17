{{-- NVMe per-disk "Basic" view: identity, health sensors and stats, NVMe data units graph. --}}
{{-- Inherits closures ($panelStart, $panelEnd, $tableRow, $tooltipForLabel, --}}
{{-- $labelWithTooltip, $stateBadge, $tempBadge, $percentBadge, --}}
{{-- $selftestBadge) and $data, $device, $selectedDisk, $linkArray --}}
{{-- from the parent smart.blade.php. --}}
@php
    $disk    = $data->disk($selectedDisk);
    $idx     = $disk['idx'];
    $info    = $disk['info'];
    $health  = $disk['health'];

    $healthSensor = $data->healthSensor($selectedDisk);
    $healthBadge  = $stateBadge($healthSensor, 'NVMe overall-health self-assessment result.');
    $diskSensors  = $data->diskSensors($selectedDisk);

    $fmtInt  = static fn ($v) => is_numeric($v) ? number_format((int) $v, 0, '.', ' ') : null;
    $fmtBytes = static fn ($v) => is_numeric($v) ? \LibreNMS\Util\Number::formatBi((int) $v) : null;
@endphp

<div class="tw:grid tw:grid-cols-1 tw:md:grid-cols-2 tw:gap-4">
    {{-- ============================== Left column ============================== --}}
    <div class="tw:min-w-0">
        {{-- Identity --}}
        @php
            $paths = array_map(
                static fn ($u) => preg_replace('#^file://#', '', $u),
                preg_split('/\s+/', trim((string) ($disk['uris'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: []
            );
            $primaryPath = $disk['device_path'] ?? ($paths[0] ?? null);
            $pathCell = $primaryPath;
            if ($primaryPath !== null && count($paths) > 1) {
                $pathCell = new \Illuminate\Support\HtmlString(
                    '<abbr style="cursor:help;text-decoration:underline dotted" title="'
                    . htmlspecialchars(implode("\n", $paths), ENT_QUOTES) . '">'
                    . htmlspecialchars((string) $primaryPath) . ' (+' . (count($paths) - 1) . ')</abbr>'
                );
            }

            $capBytes   = $info['total_nvm_capacity_bytes'] ?? null;
            $capCell    = is_numeric($capBytes)
                ? $fmtBytes($capBytes) . ' (' . \LibreNMS\Util\Number::formatSi((int) $capBytes, 0, 0, 'B') . ')'
                : null;

            $rows = [
                'Model'         => $disk['model_name']       ?? null,
                'Serial'        => $disk['serial_number']    ?? null,
                'Firmware'      => $disk['firmware_version'] ?? null,
                'WWN'           => $disk['wwn']              ?? null,
                'Path'          => $pathCell,
                'Capacity'      => $capCell,
            ];

            $identityTitle = '<i class="fa fa-microchip" style="margin-right:6px"></i>'
                . htmlspecialchars(trim((string) ($disk['model_name'] ?? '')) . ' ' . (string) ($disk['serial_number'] ?? ''))
                . ' (' . htmlspecialchars($data->deviceLabel($disk)) . ')';

            $identityRows = [];
            foreach ($rows as $label => $value) {
                if ($value === null || $value === '') { continue; }
                $cell = $value instanceof \Illuminate\Support\HtmlString
                    ? (string) $value : htmlspecialchars((string) $value);
                $tooltip = $label === 'Capacity' ? '' : $tooltipForLabel($label);
                $identityRows[] = $tableRow($label, $cell, $tooltip);
            }

            $panelStart($identityTitle, $healthBadge);
            $half = (int) ceil(count($identityRows) / 2);
            $colA = array_slice($identityRows, 0, $half);
            $colB = array_slice($identityRows, $half);
            echo '<div class="tw:flex tw:flex-wrap tw:gap-4">';
            echo '<table class="table table-condensed table-hover" style="flex:1 1 0;width:100%;min-width:0">' . implode('', $colA) . '</table>';
            if ($colB !== []) {
                echo '<table class="table table-condensed table-hover" style="flex:1 1 0;width:100%;min-width:0">' . implode('', $colB) . '</table>';
            }
            echo '</div>';
            $panelEnd();
        @endphp

        {{-- NVMe Data Units --}}
        @php
            $duNow  = \App\Facades\LibrenmsConfig::get('time.now');
            $duFrom = \App\Facades\LibrenmsConfig::get('time.day');
            $duGraphArray = \App\Http\Controllers\Device\Tabs\OverviewController::setGraphWidth([
                'id'     => $data->app->app_id,
                'type'   => 'application_smart_v2_nvme_data_units',
                'disk'   => $disk['idx'],
                'from'   => $duFrom,
                'to'     => $duNow,
                'legend' => 'no',
            ]);
            $duGraph = \LibreNMS\Util\Url::lazyGraphTag($duGraphArray, 'tw:w-full tw:h-auto');

            $duLinkArray = $duGraphArray;
            $duLinkArray['page'] = 'graphs';
            unset($duLinkArray['height'], $duLinkArray['width']);
            $duLink = \LibreNMS\Util\Url::generate($duLinkArray);

            $duOverlibArray = $duGraphArray;
            $duOverlibArray['width'] = 210;
            $duOverlib = generate_overlib_content($duOverlibArray, $device['hostname'] . ' - NVMe Data Units');

            $du = 512000; // standard NVMe data-unit size in bytes
            $duRd = isset($health['data_units_read']) && is_numeric($health['data_units_read'])
                ? \LibreNMS\Util\Number::formatBi((int) $health['data_units_read'] * $du) : null;
            $duWr = isset($health['data_units_written']) && is_numeric($health['data_units_written'])
                ? \LibreNMS\Util\Number::formatBi((int) $health['data_units_written'] * $du) : null;
            $duParts = array_filter([$duRd !== null ? 'R: ' . $duRd : null, $duWr !== null ? 'W: ' . $duWr : null]);
            $duBadge = $duParts !== [] ? '<span class="text-muted">' . htmlspecialchars(implode(' / ', $duParts)) . '</span>' : '';

            $panelStart('<i class="fa fa-database" style="margin-right:6px"></i>NVMe Data Units', $duBadge);
            echo \LibreNMS\Util\Url::overlibLink($duLink, $duGraph, $duOverlib);
            $panelEnd();
        @endphp
    </div>

    {{-- ============================= Right column ============================= --}}
    <div class="tw:min-w-0">
        {{-- Disk Load (reserved for a future disk load / utilisation graph) --}}
        @php
            $panelStart('<i class="fa fa-tachometer" style="margin-right:6px"></i>Disk Load');
            echo '<p class="text-muted" style="margin:0">Reserved for disk load graph.</p>';
            $panelEnd();
        @endphp

        {{-- Health (all disk sensors + NVMe health stats) --}}
        @php
            $sensorIcon = static fn (string $class): string => match ($class) {
                'state'       => 'fa-bullseye',
                'temperature' => 'fa-thermometer-half',
                'percent'     => 'fa-tachometer',
                default       => 'fa-line-chart',
            };
            $sensorBadge = static function ($s) use ($stateBadge, $tempBadge, $percentBadge, $selftestBadge): string {
                return match ($s->sensor_class) {
                    'state'       => $stateBadge($s),
                    'temperature' => $tempBadge($s),
                    'percent'     => $percentBadge($s),
                    default       => is_numeric($s->sensor_current)
                        ? '<span class="label label-default">' . htmlspecialchars($s->formatValue()) . '</span>'
                        : '<span class="text-muted">-</span>',
                };
            };

            $hNow  = \App\Facades\LibrenmsConfig::get('time.now');
            $hFrom = \App\Facades\LibrenmsConfig::get('time.day');
            $sensorLink = static function ($s) use ($hNow, $hFrom): string {
                return \LibreNMS\Util\Url::generate([
                    'id' => $s->sensor_id, 'type' => 'sensor_' . $s->sensor_class,
                    'from' => $hFrom, 'to' => $hNow, 'page' => 'graphs',
                ]);
            };
            $sensorMini = static function ($s) use ($hNow, $hFrom, $device, $sensorLink): string {
                $g = ['id' => $s->sensor_id, 'type' => 'sensor_' . $s->sensor_class, 'from' => $hFrom, 'to' => $hNow, 'legend' => 'no', 'width' => 210, 'height' => 100];
                $overlib = generate_overlib_content($g, $device['hostname'] . ' - ' . $s->sensor_descr);
                $link = $sensorLink($s);
                $g['width'] = 100; $g['height'] = 20; $g['bg'] = 'ffffff00';
                return \LibreNMS\Util\Url::overlibLink($link, \LibreNMS\Util\Url::lazyGraphTag($g), $overlib);
            };
            $rowOpenTag = static function (string $link): string {
                return $link !== ''
                    ? '<tr style="cursor:pointer" onclick="window.location=\'' . htmlspecialchars($link, ENT_QUOTES) . '\'">'
                    : '<tr>';
            };

            // Sensors: state first, then temperature, then percent.
            $healthSensors = $diskSensors;
            uasort($healthSensors, static function ($a, $b) {
                $rank = ['state' => 0, 'temperature' => 1, 'percent' => 2];
                return ($rank[$a->sensor_class] ?? 9) <=> ($rank[$b->sensor_class] ?? 9);
            });

            // NVMe health stats to show as plain rows (no sensor, no limits).
            static $dsToType = [
                'media_errors' => 'smart_v2_nvme_errors',
                'unsafe_shut'  => 'smart_v2_nvme_unsafe_shut',
                'pwr_hours'    => 'smart_v2_nvme_pwr_hours',
                'pwr_cycles'   => 'smart_v2_nvme_pwr_cycles',
            ];
            $statLink = static function (string $ds) use ($hNow, $hFrom, $data, $disk, $dsToType): string {
                $type = $dsToType[$ds] ?? null;
                if ($type === null) { return ''; }

                return \LibreNMS\Util\Url::generate([
                    'id'   => $data->app->app_id,
                    'type' => 'application_' . $type,
                    'disk' => $disk['idx'],
                    'from' => $hFrom,
                    'to'   => $hNow,
                    'page' => 'graphs',
                ]);
            };
            $nvMetricGraph = static function (string $ds) use ($hNow, $hFrom, $data, $disk, $device, $dsToType, $statLink): string {
                $type = $dsToType[$ds] ?? null;
                if ($type === null) { return ''; }
                $g = [
                    'id'     => $data->app->app_id,
                    'type'   => 'application_' . $type,
                    'disk'   => $disk['idx'],
                    'from'   => $hFrom,
                    'to'     => $hNow,
                    'legend' => 'no',
                    'width'  => 210,
                    'height' => 100,
                ];
                $overlib = generate_overlib_content($g, $device['hostname'] . ' - ' . $ds);
                $link = $statLink($ds);
                $g['width'] = 100; $g['height'] = 20; $g['bg'] = 'ffffff00';
                return \LibreNMS\Util\Url::overlibLink($link, \LibreNMS\Util\Url::lazyGraphTag($g), $overlib);
            };

            $nvTips = [
                'Media Errors'      => 'Media and data-integrity errors detected by the controller (NVMe SMART/Health log).',
                'Unsafe Shutdowns'  => 'Power-loss events where the drive was not cleanly shut down (NVMe SMART/Health log).',
                'Power On Hours'    => 'Hours the device has been powered on, accumulated across boot cycles (NVMe SMART/Health log).',
                'Power Cycles'      => 'Number of power-on resets / unique startups (NVMe SMART/Health log).',
            ];
            $statRows = [
                // label, value, ds key
                ['Media Errors',      $fmtInt($health['media_errors'] ?? null),            'media_errors'],
                ['Unsafe Shutdowns',  $fmtInt($health['unsafe_shutdowns'] ?? null),        'unsafe_shut'],
                ['Power On Hours',    $fmtInt($health['power_on_hours'] ?? null) !== null
                    ? $fmtInt($health['power_on_hours']) . ' h' : null,                    'pwr_hours'],
                ['Power Cycles',      $fmtInt($health['power_cycles'] ?? null),            'pwr_cycles'],
            ];

            $panelStart('<i class="fa fa-heartbeat" style="margin-right:6px"></i>Health', $healthBadge);
            echo '<table class="table table-condensed table-hover" style="width:100%">';
            foreach ($healthSensors as $s) {
                $nm = $data->shortSensorName($s, $disk);
                echo $rowOpenTag($sensorLink($s))
                    . '<td style="white-space:nowrap"><i class="fa ' . $sensorIcon($s->sensor_class) . ' text-muted" style="margin-right:6px"></i>' . htmlspecialchars($nm) . '</td>'
                    . '<td style="width:110px">' . $sensorMini($s) . '</td>'
                    . '<td style="text-align:right">' . $sensorBadge($s) . '</td>'
                    . '</tr>';
            }
            foreach ($statRows as [$label, $value, $ds]) {
                if ($value === null) { continue; }
                $tip = $nvTips[$label] ?? '';
                $nameCell = $tip !== ''
                    ? '<abbr style="cursor:help;text-decoration:underline dotted" title="' . htmlspecialchars($tip, ENT_QUOTES) . '">' . htmlspecialchars($label) . '</abbr>'
                    : htmlspecialchars($label);
                $graphCell = $ds !== null ? $nvMetricGraph($ds) : '';
                echo $rowOpenTag($ds !== null ? $statLink($ds) : '')
                    . '<td style="white-space:nowrap"><i class="fa fa-line-chart text-muted" style="margin-right:6px"></i>' . $nameCell . '</td>'
                    . '<td style="width:110px">' . $graphCell . '</td>'
                    . '<td style="text-align:right"><span class="label label-default">' . htmlspecialchars($value) . '</span></td>'
                    . '</tr>';
            }
            echo '</table>';
            $panelEnd();
        @endphp
    </div>
</div>

{{-- ====================== Full-width: Error Log ====================== --}}
@if(! empty($disk['nvme_errors']))
@php
    $panelStart('Error Log',  count($disk['nvme_errors']) );
    echo '<div class="table-responsive"><table class="table table-condensed table-hover">';
    echo '<thead><tr><th>#</th><th>Count</th><th>Status</th><th>LBA</th><th>NSID</th><th>Time</th></tr></thead><tbody>';
    foreach ($disk['nvme_errors'] as $e) {
        $status = trim((string) ($e['status_string'] ?? '')) !== '' ? $e['status_string'] : (string) ($e['status_field'] ?? '-');
        echo '<tr>'
            . '<td>' . htmlspecialchars((string) ($e['entry_num'] ?? '-')) . '</td>'
            . '<td>' . htmlspecialchars($fmtInt($e['error_count'] ?? null) ?? '-') . '</td>'
            . '<td>' . htmlspecialchars((string) $status) . '</td>'
            . '<td>' . htmlspecialchars((string) ($e['lba'] ?? '-')) . '</td>'
            . '<td>' . htmlspecialchars((string) ($e['ns_id'] ?? '-')) . '</td>'
            . '<td>' . htmlspecialchars((string) ($e['error_time'] ?? '-')) . '</td>'
            . '</tr>';
    }
    echo '</tbody></table></div>';
    $panelEnd();
@endphp
@endif
