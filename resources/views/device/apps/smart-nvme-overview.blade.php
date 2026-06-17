{{-- NVMe per-disk "Overview" view: identity, health sensors and stats, self-test log. --}}
{{-- Inherits closures ($panelStart, $panelEnd, $tableRow, $tooltipForLabel, --}}
{{-- $labelWithTooltip, $formatHoursAgo, $stateBadge, $tempBadge, $percentBadge, --}}
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
            $unalloc    = $info['unallocated_nvm_capacity_bytes'] ?? null;

            $rows = [
                'Model'         => $disk['model_name']       ?? null,
                'Serial'        => $disk['serial_number']    ?? null,
                'Firmware'      => $disk['firmware_version'] ?? null,
                'WWN'           => $disk['wwn']              ?? null,
                'Device'        => $disk['device_name']      ?? null,
                'Path'          => $pathCell,
                'NVMe Version'  => $info['nvme_version']     ?? null,
                'Controller ID' => isset($info['controller_id']) && is_numeric($info['controller_id'])
                    ? number_format((int) $info['controller_id'], 0, '.', ' ') : null,
                'PCI Vendor'    => isset($info['pci_vendor_id']) && is_numeric($info['pci_vendor_id'])
                    ? (($pciName = $data->pciVendorName((int) $info['pci_vendor_id'])) !== null
                        ? $pciName . sprintf(' (0x%04X)', (int) $info['pci_vendor_id'])
                        : sprintf('0x%04X', (int) $info['pci_vendor_id']))
                    : null,
                'IEEE OUI'      => isset($info['ieee_oui']) && is_numeric($info['ieee_oui'])
                    ? (($ouiName = $data->ouiVendorName((int) $info['ieee_oui'])) !== null
                        ? new \Illuminate\Support\HtmlString(
                            '<abbr style="cursor:help;text-decoration:underline dotted" title="'
                            . htmlspecialchars($ouiName, ENT_QUOTES) . '">'
                            . htmlspecialchars(\Illuminate\Support\Str::limit($ouiName, 28, '…', preserveWords: true))
                            . '</abbr> ' . sprintf('(0x%06X)', (int) $info['ieee_oui']))
                        : sprintf('0x%06X', (int) $info['ieee_oui']))
                    : null,
                'Capacity'      => $fmtBytes($capBytes),
                'Unallocated'   => $fmtBytes($unalloc),
                'Namespaces'    => isset($info['namespace_count']) && is_numeric($info['namespace_count'])
                    ? number_format((int) $info['namespace_count'], 0, '.', ' ') : null,
                'Max Transfer'  => isset($info['max_data_transfer_pages']) && is_numeric($info['max_data_transfer_pages'])
                    ? number_format((int) $info['max_data_transfer_pages'], 0, '.', ' ') . ' pages' : null,
                'Last Poll'     => $disk['last_poll_time'] ?? null,
                'Last Poll Result' => $disk['last_poll_result'] !== null ? $data->decode('poll_result', $disk['last_poll_result']) : null,
            ];

            $identityTitle = '<i class="fa fa-microchip" style="margin-right:6px"></i>'
                . htmlspecialchars(trim((string) ($disk['model_name'] ?? '')) . ' ' . (string) ($disk['serial_number'] ?? ''))
                . ' (' . htmlspecialchars($data->deviceLabel($disk)) . ')';

            $panelStart($identityTitle, $healthBadge);
            echo '<table class="table table-condensed table-hover" style="width:100%">';
            foreach ($rows as $label => $value) {
                if ($value === null || $value === '') { continue; }
                $cell = $value instanceof \Illuminate\Support\HtmlString
                    ? (string) $value : htmlspecialchars((string) $value);
                echo $tableRow($label, $cell, $tooltipForLabel($label));
            }
            echo '</table>';

            // Hint when vendor-name databases are not installed.
            $missingDbs = [];
            if (isset($info['pci_vendor_id']) && is_numeric($info['pci_vendor_id']) && ! $data->pciIdsAvailable()) {
                $missingDbs[] = 'pci.ids (pciutils)';
            }
            if (isset($info['ieee_oui']) && is_numeric($info['ieee_oui']) && ! $data->ouiDbAvailable()) {
                $missingDbs[] = 'oui.txt (ieee-data)';
            }
            if ($missingDbs !== []) {
                echo '<div class="small text-muted" style="margin-top:4px">'
                    . '<i class="fa fa-exclamation-triangle"></i> Vendor names unavailable. install: '
                    . htmlspecialchars(implode(', ', $missingDbs)) . '</div>';
            }

            $panelEnd();
        @endphp
    </div>

    {{-- ============================= Right column ============================= --}}
    <div class="tw:min-w-0">
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
            $sensorMini = static function ($s) use ($hNow, $hFrom, $device): string {
                $g = ['id' => $s->sensor_id, 'type' => 'sensor_' . $s->sensor_class, 'from' => $hFrom, 'to' => $hNow, 'legend' => 'no', 'width' => 210, 'height' => 100];
                $overlib = generate_overlib_content($g, $device['hostname'] . ' - ' . $s->sensor_descr);
                $linkArr = $g; $linkArr['page'] = 'graphs'; unset($linkArr['width'], $linkArr['height'], $linkArr['legend']);
                $link = \LibreNMS\Util\Url::generate($linkArr);
                $g['width'] = 100; $g['height'] = 20; $g['bg'] = 'ffffff00';
                return \LibreNMS\Util\Url::overlibLink($link, \LibreNMS\Util\Url::lazyGraphTag($g), $overlib);
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
            $nvMetricGraph = static function (string $ds) use ($hNow, $hFrom, $data, $disk, $device, $dsToType): string {
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
                $linkArr = $g; $linkArr['page'] = 'graphs'; unset($linkArr['width'], $linkArr['height'], $linkArr['legend']);
                $link = \LibreNMS\Util\Url::generate($linkArr);
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
                echo '<tr>'
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
                echo '<tr>'
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

{{-- ====================== Full-width: Self-test Log ====================== --}}
@php
    $curOp  = (int) ($health['current_selftest_op'] ?? 0);
    $curStr = trim((string) ($health['current_selftest_str'] ?? ''));
    $curPct = $health['current_selftest_pct'] ?? null;

    $stBadge = '';
    if ($curOp !== 0) {
        $txt = $curStr !== '' ? $curStr : 'Self-test in progress';
        if (is_numeric($curPct)) { $txt .= ' ' . (int) $curPct . '%'; }
        $stBadge = '<span class="label label-info">' . htmlspecialchars($txt) . '</span>';
    } elseif (! empty($disk['selftests'])) {
        $latest = null;
        foreach ($disk['selftests'] as $st) {
            if ($latest === null || (int) ($st['power_on_hours'] ?? 0) >= (int) ($latest['power_on_hours'] ?? 0)) {
                $latest = $st;
            }
        }
        $rt = trim((string) ($latest['result_text'] ?? '')) ?: (string) ($latest['result'] ?? '');
        if ($rt !== '') {
            $ok = stripos($rt, 'without error') !== false || stripos($rt, 'success') !== false || stripos($rt, 'completed') !== false;
            $stBadge = '<span class="label label-' . ($ok ? 'default' : 'warning') . '">' . htmlspecialchars($rt) . '</span>';
        }
    }

    $panelStart('Self-test Log', $stBadge);
    if (empty($disk['selftests']) && $curOp === 0) {
        echo '<div class="small text-muted" style="padding:4px 2px">'
            . '<i class="fa fa-info-circle"></i> No self-test data reported — this drive may not support self-tests.</div>';
    } else {
        $curPoh = $health['power_on_hours'] ?? null;
        $hasLba = false;
        foreach ($disk['selftests'] as $e) {
            if (is_numeric($e['failing_lba'] ?? null) && (int) $e['failing_lba'] > 0) { $hasLba = true; break; }
        }
        echo '<div class="table-responsive"><table class="table table-condensed table-hover">';
        echo '<thead><tr><th>Hours</th><th>Type</th><th>Status</th><th>Remaining %</th>'
            . ($hasLba ? '<th>First LBA Error</th>' : '') . '</tr></thead><tbody>';
        foreach ($disk['selftests'] as $st) {
            $type   = match ((int) ($st['test_type'] ?? 0)) { 1 => 'Short', 2 => 'Extended', 255 => 'Vendor', default => '-' };
            $result = trim((string) ($st['result_text'] ?? '')) !== '' ? $st['result_text'] : (string) ($st['result'] ?? '-');
            $h      = $st['power_on_hours'] ?? null;
            $hoursCell = (string) ($h ?? '-');
            if (is_numeric($curPoh) && is_numeric($h)) {
                $delta = (int) $curPoh - (int) $h;
                $hoursCell = $delta > 0 ? $formatHoursAgo($delta) . " ({$h})" : "<0 hour ({$h})";
            }
            $lba = $st['failing_lba'] ?? null;
            echo '<tr>'
                . '<td>' . htmlspecialchars($hoursCell) . '</td>'
                . '<td>' . htmlspecialchars($type) . '</td>'
                . '<td>' . htmlspecialchars((string) $result) . '</td>'
                . '<td></td>'
                . ($hasLba ? '<td>' . (is_numeric($lba) && (int) $lba > 0 ? htmlspecialchars((string) $lba) : '') . '</td>' : '')
                . '</tr>';
        }
        echo '</tbody></table></div>';
    }
    $panelEnd();
@endphp

{{-- ====================== Full-width: Error Log ====================== --}}
@if(! empty($disk['nvme_errors']))
@php
    $panelStart('Error Log', '<span class="label label-warning">' . count($disk['nvme_errors']) . '</span>');
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
