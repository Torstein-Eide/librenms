{{-- NVMe per-disk "Basic" view: identity, health sensors and stats, NVMe data units graph. --}}
{{-- Inherits closures ($panelStart, $panelEnd, $tableRow, $tooltipForLabel, --}}
{{-- $labelWithTooltip, $stateBadge, $tempBadge, $percentBadge, --}}
{{-- $selftestBadge) and $data, $device, $selectedDisk, $smartUrl --}}
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
                'type'   => 'smart_disk_lba_units',
                'rrd'    => 'smart_nvme',
                'disk'   => $disk['idx'],
                'from'   => $duFrom,
                'to'     => $duNow,
                'legend' => 'no',
            ]);
            $duGraph = \LibreNMS\Util\Url::lazyGraphTag($duGraphArray, 'tw:w-full tw:h-auto');
            $duLink  = \LibreNMS\Util\Url::generate($duGraphArray, ['page' => 'graphs', 'width' => null, 'height' => null]);
            $duGraph = '<a href="' . htmlspecialchars($duLink, ENT_QUOTES) . '">' . $duGraph . '</a>';

            // Current rate (B/s) from the last RRD interval. 1 NVMe data unit = 512 000 B.
            $du = 512000;
            $duRrdFile = \App\Facades\Rrd::name($device['hostname'], ['app', 'smart_nvme', $data->app->app_id, $disk['idx']]);
            $duRates   = \App\Facades\Rrd::getLastRates($duRrdFile, ['du_rd', 'du_wr']);
            $fmtDuRate = static fn (float $r): string => \LibreNMS\Util\Number::formatSi($r * $du, 2, 0, 'B') . '/s';
            $duRdRate = $duRates?->get('du_rd');
            $duWrRate = $duRates?->get('du_wr');
            $duRd = is_numeric($duRdRate) ? $fmtDuRate((float) $duRdRate) : null;
            $duWr = is_numeric($duWrRate) ? $fmtDuRate((float) $duWrRate) : null;
            $duParts = array_filter([$duRd !== null ? 'R: ' . $duRd : null, $duWr !== null ? 'W: ' . $duWr : null]);
            $duBadge = $duParts !== [] ? '<span class="text-muted">' . htmlspecialchars(implode(' / ', $duParts)) . '</span>' : '';

            $panelStart('<i class="fa fa-database" style="margin-right:6px"></i>LBAs Written/Read', $duBadge);
            echo $graphPopup('smart_disk_lba_units', ['id' => $data->app->app_id, 'rrd' => 'smart_nvme', 'disk' => $disk['idx']], $duGraph, $popupTitle($disk, 'LBAs Written/Read', $duParts !== [] ? implode(' / ', $duParts) : null));
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
            // Wraps $content (label text, value badge, or the mini graph image) in
            // the same hover-preview link that points at the sensor's graph.
            // sensor_descr already carries the "SMART {model} {serial} ({drive})"
            // identity prefix $popupTitle() itself adds -- shortSensorName() strips
            // it back off so the popup title doesn't repeat it twice.
            $sensorGraphLink = static function ($s, string $content) use ($graphPopup, $popupTitle, $data, $disk): string {
                return $graphPopup('sensor_' . $s->sensor_class, ['id' => $s->sensor_id], $content, $popupTitle($disk, $data->shortSensorName($s, $disk), $s->formatValue()));
            };
            $sensorMini = static function ($s) use ($graphPopup, $popupTitle, $data, $disk): string {
                return $graphPopup('sensor_' . $s->sensor_class, ['id' => $s->sensor_id], null, $popupTitle($disk, $data->shortSensorName($s, $disk), $s->formatValue()));
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
                'media_errors' => 'nvme_errors',
                'unsafe_shut'  => 'nvme_unsafe_shut',
                'pwr_hours'    => 'nvme_pwr_hours',
                'pwr_cycles'   => 'nvme_pwr_cycles',
            ];
            $statLink = static function (string $ds) use ($hNow, $hFrom, $data, $disk, $dsToType): string {
                $type = $dsToType[$ds] ?? null;
                if ($type === null) { return ''; }

                return \LibreNMS\Util\Url::generate([
                    'id'   => $data->app->app_id,
                    'type' => 'smart_' . $type,
                    'disk' => $disk['idx'],
                    'from' => $hFrom,
                    'to'   => $hNow,
                    'page' => 'graphs',
                ]);
            };
            // Wraps $content (label text, value badge, or the mini graph image) in
            // the same hover-preview link that points at the stat's graph. $label/$value
            // are the human-readable name and formatted current value (from $statRows
            // below), not the raw $ds key.
            $statGraphLink = static function (string $ds, ?string $content, string $label, ?string $value) use ($graphPopup, $data, $disk, $popupTitle, $dsToType): string {
                $type = $dsToType[$ds] ?? null;
                if ($type === null) { return $content ?? ''; }

                return $graphPopup('smart_' . $type, ['id' => $data->app->app_id, 'disk' => $disk['idx']], $content, $popupTitle($disk, $label, $value));
            };
            $nvMetricGraph = static function (string $ds, string $label, ?string $value) use ($statGraphLink, $dsToType): string {
                if (($dsToType[$ds] ?? null) === null) { return ''; }

                return $statGraphLink($ds, null, $label, $value);
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

            $healthGraphsLink = $smartUrl((string) $selectedDisk);
            $healthHeader = '<a href="' . htmlspecialchars($healthGraphsLink, ENT_QUOTES) . '" '
                . 'onclick="document.cookie=\'' . htmlspecialchars($viewCookie, ENT_QUOTES) . '=graphs; path=/; max-age=31536000; samesite=lax\';" '
                . 'style="color:inherit"><i class="fa fa-heartbeat" style="margin-right:6px"></i>Health</a>';
            $panelStart($healthHeader);
            echo '<table class="table table-condensed table-hover" style="width:100%">';
            foreach ($healthSensors as $s) {
                $nm = $sensorGraphLink($s, htmlspecialchars($data->shortSensorName($s, $disk)));
                $badge = $sensorGraphLink($s, $sensorBadge($s));
                echo $rowOpenTag($sensorLink($s))
                    . '<td style="white-space:nowrap"><i class="fa ' . $sensorIcon($s->sensor_class) . ' text-muted" style="margin-right:6px"></i>' . $nm . '</td>'
                    . '<td style="width:110px">' . $sensorMini($s) . '</td>'
                    . '<td style="text-align:right">' . $badge . '</td>'
                    . '</tr>';
            }
            foreach ($statRows as [$label, $value, $ds]) {
                if ($value === null) { continue; }
                $tip = $nvTips[$label] ?? '';
                $nameCell = $tip !== ''
                    ? '<abbr style="cursor:help;text-decoration:underline dotted" title="' . htmlspecialchars($tip, ENT_QUOTES) . '">' . htmlspecialchars($label) . '</abbr>'
                    : htmlspecialchars($label);
                $valueCell = '<span class="label label-default">' . htmlspecialchars($value) . '</span>';
                if ($ds !== null) {
                    $nameCell = $statGraphLink($ds, $nameCell, $label, $value);
                    $valueCell = $statGraphLink($ds, $valueCell, $label, $value);
                }
                $graphCell = $ds !== null ? $nvMetricGraph($ds, $label, $value) : '';
                echo $rowOpenTag($ds !== null ? $statLink($ds) : '')
                    . '<td style="white-space:nowrap"><i class="fa fa-line-chart text-muted" style="margin-right:6px"></i>' . $nameCell . '</td>'
                    . '<td style="width:110px">' . $graphCell . '</td>'
                    . '<td style="text-align:right">' . $valueCell . '</td>'
                    . '</tr>';
            }

            // Estimated Lifetime / DWPD summary rows.
            $lifetimeYears = $data->estimatedLifetimeYears($disk);
            if ($lifetimeYears !== null) {
                echo '<tr><td style="white-space:nowrap"><i class="fa fa-hourglass-half text-muted" style="margin-right:6px"></i>'
                    . $labelWithTooltip('Estimated Lifetime', $tooltipForLabel('Estimated Lifetime')) . '</td>'
                    . '<td colspan="2" style="text-align:right">' . htmlspecialchars(number_format($lifetimeYears, 1)) . ' years</td></tr>';
            }
            $dwpd = $data->dwpd($disk);
            if ($dwpd !== null) {
                echo '<tr><td style="white-space:nowrap"><i class="fa fa-database text-muted" style="margin-right:6px"></i>'
                    . $labelWithTooltip('DWPD', $tooltipForLabel('DWPD')) . '</td>'
                    . '<td colspan="2" style="text-align:right">' . htmlspecialchars(number_format($dwpd, 3)) . '</td></tr>';
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
    echo '<thead><tr><th>' . $labelWithTooltip('#', '64-bit incrementing error count: a unique ID for this error, starting at 1 and retained across power-off. 0 means an invalid/lost entry.') . '</th>'
        . '<th>Status</th><th>LBA</th><th>NSID</th><th>Time</th></tr></thead><tbody>';
    foreach ($disk['nvme_errors'] as $e) {
        $status = trim((string) ($e['status_string'] ?? '')) !== '' ? $e['status_string'] : (string) ($e['status_field'] ?? '-');
        echo '<tr>'
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
