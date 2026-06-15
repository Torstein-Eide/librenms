{{-- NVMe per-disk detail. Included from smart.blade.php for NVMe disks. --}}
{{-- Inherits closures ($panelStart, $panelEnd, $tableRow, $stateBadge, $tempBadge, $wearBadge) --}}
{{-- and $data, $device, $selectedDisk, $disk, $viewMode from the parent view. --}}
@php
    $idx      = $disk['idx'];
    $info     = $disk['info'];
    $health   = $disk['health'];
    $now      = \App\Facades\LibrenmsConfig::get('time.now');

    $showGraphs = $viewMode === 'graphs';
    $showDetailed = $viewMode === 'detailed';

    $healthSensor = $data->healthSensor($selectedDisk);
    $diskSensors = $data->diskSensors($selectedDisk);
    $tempSensors = array_filter($diskSensors, static fn ($s) => $s->sensor_class === 'temperature');
    // SMART/Health panel table: temperature + the percent sensors (Available Spare,
    // Percentage Used). Temperatures first, then the rest, each in index order.
    $panelSensors = array_filter($diskSensors, static fn ($s) => in_array($s->sensor_class, ['temperature', 'percent'], true));
    usort($panelSensors, static fn ($a, $b) => ($a->sensor_class === 'temperature' ? 0 : 1) <=> ($b->sensor_class === 'temperature' ? 0 : 1));
    $overall = $health['overall_status'] ?? null;
    $healthBadge = match ((int) $overall) {
        1 => '<span class="label label-default">Passed</span>',
        2 => '<span class="label label-danger">Failed</span>',
        3 => '<span class="label label-warning">Warning</span>',
        4 => '<span class="label label-warning">Unavailable</span>',
        default => '',
    };

    $fmtInt  = static fn ($v) => is_numeric($v) ? number_format((int) $v, 0, '.', ' ') : null;
    $fmtBytes = static fn ($v) => is_numeric($v) ? \LibreNMS\Util\Number::formatBi((int) $v) : null;
    $yesNo   = static fn ($v) => $v === null ? null : ((int) $v ? 'Yes' : 'No');

    // Self-test panel badge: in-progress operation while running, otherwise the
    // result of the most recent completed self-test.
    $curOp  = (int) ($health['current_selftest_op'] ?? 0);
    $curStr = trim((string) ($health['current_selftest_str'] ?? ''));
    $curPct = $health['current_selftest_pct'] ?? null;
    $selftestBadge = '';
    if ($curOp !== 0) {
        $txt = $curStr !== '' ? $curStr : 'Self-test in progress';
        if (is_numeric($curPct)) {
            $txt .= ' ' . (int) $curPct . '%';
        }
        $selftestBadge = '<span class="label label-info">' . htmlspecialchars($txt) . '</span>';
    } elseif (! empty($disk['selftests'])) {
        $latest = null;
        foreach ($disk['selftests'] as $st) {
            if ($latest === null || (int) ($st['power_on_hours'] ?? 0) >= (int) ($latest['power_on_hours'] ?? 0)) {
                $latest = $st;
            }
        }
        $rt = trim((string) ($latest['result_text'] ?? ''));
        if ($rt === '') {
            $rt = (string) ($latest['result'] ?? '');
        }
        if ($rt !== '') {
            // Gray (default) for a clean pass; warning otherwise.
            $ok = stripos($rt, 'without error') !== false || stripos($rt, 'success') !== false || stripos($rt, 'completed') !== false;
            $selftestBadge = '<span class="label label-' . ($ok ? 'default' : 'warning') . '">' . htmlspecialchars($rt) . '</span>';
        }
    }
@endphp

@if(! $showGraphs)
<style>
    .smart-panels { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-start; margin-bottom:15px }
    .smart-panels .panel { flex:0 0 auto; margin-bottom:0 }
    .smart-panels table { white-space:nowrap }
    /* Full-width zero-height element forces following panels onto a new row. */
    .smart-row-break { flex-basis:100%; height:0; margin:0; padding:0 }
</style>
<div class="smart-panels">

    {{-- Identity --}}
    <div>
        @php
            $panelStart(htmlspecialchars($data->deviceLabel($disk)), $healthBadge);
            echo '<table class="table table-condensed table-hover" style="width:auto">';
            $cap = $info['total_nvm_capacity_bytes'] ?? null;
            $unalloc = $info['unallocated_nvm_capacity_bytes'] ?? null;

            // smartmonDeviceUris is a whitespace-separated list of file:// URIs; show the
            // primary device path with the full list (scheme stripped) in a tooltip.
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

            $rows = [
                'Model'           => $disk['model_name'] ?? null,
                'Serial'          => $disk['serial_number'] ?? null,
                'Firmware'        => $disk['firmware_version'] ?? null,
                'WWN'             => $disk['wwn'] ?? null,
                'Device'          => $disk['device_name'] ?? null,
                'Path'            => $pathCell,
                'NVMe Version'    => $info['nvme_version'] ?? null,
                'Controller ID'   => $fmtInt($info['controller_id'] ?? null),
                'PCI Vendor'      => isset($info['pci_vendor_id']) && is_numeric($info['pci_vendor_id'])
                    ? (($pciName = $data->pciVendorName((int) $info['pci_vendor_id'])) !== null
                        ? $pciName . sprintf(' (0x%04X)', (int) $info['pci_vendor_id'])
                        : sprintf('0x%04X', (int) $info['pci_vendor_id']))
                    : null,
                'IEEE OUI'        => isset($info['ieee_oui']) && is_numeric($info['ieee_oui'])
                    ? (($ouiName = $data->ouiVendorName((int) $info['ieee_oui'])) !== null
                        ? new \Illuminate\Support\HtmlString(
                            '<abbr style="cursor:help;text-decoration:underline dotted" title="'
                            . htmlspecialchars($ouiName, ENT_QUOTES) . '">'
                            . htmlspecialchars(\Illuminate\Support\Str::limit($ouiName, 28, '…', preserveWords: true))
                            . '</abbr> ' . sprintf('(0x%06X)', (int) $info['ieee_oui']))
                        : sprintf('0x%06X', (int) $info['ieee_oui']))
                    : null,
                'Capacity'        => $fmtBytes($cap),
                'Unallocated'     => $fmtBytes($unalloc),
                'Namespaces'      => $fmtInt($info['namespace_count'] ?? null),
                'Max Transfer'    => isset($info['max_data_transfer_pages']) && is_numeric($info['max_data_transfer_pages'])
                    ? $fmtInt($info['max_data_transfer_pages']) . ' pages' : null,
                'Last Poll'       => $disk['last_poll_time'] ?? null,
                'Last Poll Result' => $disk['last_poll_result'] !== null ? $data->decode('poll_result', $disk['last_poll_result']) : null,
            ];
            foreach ($rows as $label => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                // HtmlString values (e.g. tooltipped vendor names) are already safe HTML.
                $cell = $value instanceof \Illuminate\Support\HtmlString
                    ? (string) $value : htmlspecialchars((string) $value);
                echo $tableRow($label, $cell);
            }
            echo '</table>';

            // Small hint when a vendor-name lookup database is not installed.
            $missingDbs = [];
            if (isset($info['pci_vendor_id']) && is_numeric($info['pci_vendor_id']) && ! $data->pciIdsAvailable()) {
                $missingDbs[] = 'pci.ids (pciutils)';
            }
            if (isset($info['ieee_oui']) && is_numeric($info['ieee_oui']) && ! $data->ouiDbAvailable()) {
                $missingDbs[] = 'oui.txt (ieee-data)';
            }
            if ($missingDbs !== []) {
                echo '<div class="small text-muted" style="margin-top:4px">'
                    . '<i class="fa fa-exclamation-triangle"></i> Vendor names unavailable — install '
                    . htmlspecialchars(implode(', ', $missingDbs)) . '</div>';
            }
            $panelEnd();
        @endphp
    </div>

    {{-- Self-test log — row #1, immediately after the status/identity panel --}}
    @if(! empty($disk['selftests']) || $curOp !== 0)
    <div>
        @php
            $panelStart('Self-test Log', $selftestBadge);
            $hasLba = false;
            foreach ($disk['selftests'] as $e) {
                if (is_numeric($e['failing_lba'] ?? null) && (int) $e['failing_lba'] > 0) {
                    $hasLba = true;
                    break;
                }
            }
            $curPoh = $health['power_on_hours'] ?? null;
            echo '<table class="table table-condensed table-hover"><thead><tr>'
                . '<th>Hours</th><th>Type</th><th>Status</th><th>Remaining Percent</th>'
                . ($hasLba ? '<th>First LBA Error</th>' : '') . '</tr></thead><tbody>';
            foreach ($disk['selftests'] as $st) {
                $type = match ((int) ($st['test_type'] ?? 0)) { 1 => 'Short', 2 => 'Extended', 255 => 'Vendor', default => '-' };
                $result = trim((string) ($st['result_text'] ?? '')) !== '' ? $st['result_text'] : (string) ($st['result'] ?? '-');
                $lba = $st['failing_lba'] ?? null;
                $h = $st['power_on_hours'] ?? null;
                $hoursCell = (string) ($h ?? '-');
                if (is_numeric($curPoh) && is_numeric($h)) {
                    $delta = (int) $curPoh - (int) $h;
                    $hoursCell = $delta > 0 ? $formatHoursAgo($delta) . " ({$h})" : "<0 hour ({$h})";
                }
                echo '<tr><td>' . htmlspecialchars($hoursCell) . '</td>'
                    . '<td>' . htmlspecialchars($type) . '</td>'
                    . '<td>' . htmlspecialchars((string) $result) . '</td>'
                    . '<td></td>'
                    . ($hasLba ? '<td>' . (is_numeric($lba) && (int) $lba > 0 ? htmlspecialchars((string) $lba) : '') . '</td>' : '')
                    . '</tr>';
            }
            echo '</tbody></table>';
            $panelEnd();
        @endphp
    </div>
    @endif

    {{-- SMART / Health log — row #2 (own row) --}}
    <div class="smart-row-break"></div>
    <div>
        @php
            $panelStart('SMART / Health', $healthBadge);
            $du = 512000; // standard NVMe data-unit size in bytes
            // Critical Warning is surfaced by the Health sensor/badge, so it is not repeated here.
            // label => [display value, smart_v2_nvme single DS (one-line graph) or null for no graph]
            $rows = [
                'Controller Busy'  => [isset($health['controller_busy_time']) && is_numeric($health['controller_busy_time'])
                    ? $fmtInt($health['controller_busy_time']) . ' min' : null, 'ctrl_busy'],
                'Media Errors'     => [$fmtInt($health['media_errors'] ?? null), 'media_errors'],
                'Error Log Entries' => [$fmtInt($health['num_err_log_entries'] ?? null), 'err_log_cnt'],
                'Unsafe Shutdowns' => [$fmtInt($health['unsafe_shutdowns'] ?? null), 'unsafe_shut'],
                'Power On Hours'   => [$fmtInt($health['power_on_hours'] ?? null), 'pwr_hours'],
                'Power Cycles'     => [$fmtInt($health['power_cycles'] ?? null), 'pwr_cycles'],
                // Data/Host throughput counters last.
                'Data Read'        => [isset($health['data_units_read']) && is_numeric($health['data_units_read'])
                    ? $fmtBytes((int) $health['data_units_read'] * $du) : null, 'du_rd'],
                'Data Written'     => [isset($health['data_units_written']) && is_numeric($health['data_units_written'])
                    ? $fmtBytes((int) $health['data_units_written'] * $du) : null, 'du_wr'],
                'Host Reads'       => [$fmtInt($health['host_read_commands'] ?? null), 'host_rd'],
                'Host Writes'      => [$fmtInt($health['host_write_commands'] ?? null), 'host_wr'],
            ];

            // Mini graph (click → graphs page, hover → day/week/month/year popup) for a
            // smart_v2_nvme metric group, reading the per-disk smart_nvme RRD.
            $healthFrom = \App\Facades\LibrenmsConfig::get('time.day');
            $nvMetricGraph = static function (string $metric, string $label) use ($now, $healthFrom, $data, $disk, $device) {
                return \LibreNMS\Util\Url::graphPopup([
                    'id'          => $data->app->app_id,
                    'type'        => 'application_smart_v2_nvme',
                    'disk'        => $disk['idx'],
                    'metric'      => $metric,
                    'from'        => $healthFrom,
                    'to'          => $now,
                    'width'       => 60,
                    'height'      => 15,
                    'legend'      => 'no',
                    'popup_title' => htmlspecialchars($device['hostname'] . ' - ' . $label),
                ]);
            };

            // Health stat rows (Data Read, Host Reads, …) and sensors share the one
            // table below.
            $hasStats = false;
            foreach ($rows as [$rv]) {
                if ($rv !== null && $rv !== '') {
                    $hasStats = true;
                    break;
                }
            }

            // Sensors: current, mini graph, limits, and (Composite temperature only)
            // time spent over the warning / critical thresholds. The health stat
            // rows are appended into the same table.
            if (! empty($panelSensors) || $hasStats) {
                $unit = static fn ($s) => $s->sensor_class === 'percent' ? '%' : '°C';
                // Value with the sensor's unit; '-' when unset.
                $fmtMeasure = static fn ($s, $v) => is_numeric($v)
                    ? rtrim(rtrim(sprintf('%.1f', (float) $v), '0'), '.') . $unit($s) : '-';
                // Warn/critical use the high limit when present, else the low limit
                // (Available Spare alerts when it drops below its low thresholds).
                $warnOf = static fn ($s) => $s->sensor_limit_warn ?? $s->sensor_limit_low_warn;
                $critOf = static fn ($s) => $s->sensor_limit ?? $s->sensor_limit_low;
                $tmin = static fn ($v) => is_numeric($v) ? $fmtInt($v) . ' min' : '';
                $from = \App\Facades\LibrenmsConfig::get('time.day');
                echo '<table class="table table-condensed table-hover"><thead><tr>'
                    . '<th>Name</th><th>Current</th><th>Graph</th><th>Warn</th><th>Critical</th>'
                    . '<th>Warn Time Over</th><th>Critical Time Over</th></tr></thead><tbody>';
                foreach ($panelSensors as $s) {
                    // Strip the redundant "SMART <disk label> " prefix, leaving just
                    // the sensor name (e.g. Composite, Sensor 1, Available Spare).
                    $nm = $data->shortSensorName($s, $disk);
                    $isComposite = $s->sensor_class === 'temperature' && stripos($nm, 'Composite') !== false;
                    // Click → graphs page, hover → day/week/month/year popup (as on device overview).
                    $img = \LibreNMS\Util\Url::graphPopup([
                        'id'          => $s->sensor_id,
                        'type'        => 'sensor_' . $s->sensor_class,
                        'from'        => $from,
                        'to'          => $now,
                        'width'       => 60,
                        'height'      => 15,
                        'legend'      => 'no',
                        'popup_title' => htmlspecialchars($device['hostname'] . ' - ' . $nm),
                    ]);
                    echo '<tr><td>' . htmlspecialchars($nm) . '</td>'
                        . '<td>' . htmlspecialchars($fmtMeasure($s, $s->sensor_current)) . '</td>'
                        . '<td>' . $img . '</td>'
                        . '<td>' . htmlspecialchars($fmtMeasure($s, $warnOf($s))) . '</td>'
                        . '<td>' . htmlspecialchars($fmtMeasure($s, $critOf($s))) . '</td>'
                        . '<td>' . htmlspecialchars($isComposite ? $tmin($health['warning_temp_time'] ?? null) : '') . '</td>'
                        . '<td>' . htmlspecialchars($isComposite ? $tmin($health['critical_comp_time'] ?? null) : '') . '</td></tr>';
                }
                // Health stat rows (moved in): Name, value, single-DS mini graph; no limit columns.
                foreach ($rows as $label => [$value, $metric]) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $graph = $metric !== null ? $nvMetricGraph($metric, $label) : '';
                    echo '<tr><td>' . htmlspecialchars($label) . '</td>'
                        . '<td>' . htmlspecialchars((string) $value) . '</td>'
                        . '<td>' . $graph . '</td>'
                        . '<td></td><td></td><td></td><td></td></tr>';
                }
                echo '</tbody></table>';
            }
            $panelEnd();
        @endphp
    </div>

    {{-- Namespaces & LBA Formats — row #3 (own row), detailed view only --}}
    @if($showDetailed && ! empty($disk['nvme_namespaces']))
    <div class="smart-row-break"></div>
    <div>
        @php
            $panelStart('Namespaces & LBA Formats');
            echo '<table class="table table-condensed table-hover"><thead><tr>'
                . '<th>NS</th><th>Size</th><th>Capacity</th><th>Used</th>'
                . '<th>Fmt</th><th>Cur</th><th>Data</th><th>Meta</th><th>Rel Perf</th></tr></thead><tbody>';

            // Group LBA formats by namespace id.
            $fmtByNs = [];
            foreach ($disk['nvme_lba_formats'] ?? [] as $lf) {
                $fmtByNs[(int) ($lf['ns_id'] ?? 0)][] = $lf;
            }

            $emptyNsCells = '<td></td><td></td><td></td><td></td>';
            $fmtCells = static function (array $lf): string {
                return '<td>' . htmlspecialchars((string) ($lf['format_id'] ?? '-')) . '</td>'
                    . '<td>' . ($lf['current'] !== null ? ((int) $lf['current'] ? '✓' : '') : '') . '</td>'
                    . '<td>' . htmlspecialchars(is_numeric($lf['data_size_bytes'] ?? null) ? $lf['data_size_bytes'] . ' B' : '-') . '</td>'
                    . '<td>' . htmlspecialchars(is_numeric($lf['metadata_size_bytes'] ?? null) ? $lf['metadata_size_bytes'] . ' B' : '-') . '</td>'
                    . '<td>' . htmlspecialchars((string) ($lf['relative_performance'] ?? '-')) . '</td>';
            };

            foreach ($disk['nvme_namespaces'] as $ns) {
                $nsId = (int) ($ns['ns_id'] ?? 0);
                $lba = is_numeric($ns['lba_data_size'] ?? null) ? (int) $ns['lba_data_size'] : null;
                $toBytes = static fn ($blocks) => ($lba && is_numeric($blocks)) ? \LibreNMS\Util\Number::formatBi((int) $blocks * $lba) : '-';
                $nsCells = '<td>' . $nsId . '</td>'
                    . '<td>' . htmlspecialchars($toBytes($ns['nsze'] ?? null)) . '</td>'
                    . '<td>' . htmlspecialchars($toBytes($ns['ncap'] ?? null)) . '</td>'
                    . '<td>' . htmlspecialchars($toBytes($ns['nuse'] ?? null)) . '</td>';

                $formats = $fmtByNs[$nsId] ?? [];
                if ($formats === []) {
                    echo '<tr>' . $nsCells . '<td>-</td><td></td><td>-</td><td>-</td><td>-</td></tr>';
                    continue;
                }
                $first = true;
                foreach ($formats as $lf) {
                    echo '<tr>' . ($first ? $nsCells : $emptyNsCells) . $fmtCells($lf) . '</tr>';
                    $first = false;
                }
            }
            echo '</tbody></table>';
            $panelEnd();
        @endphp
    </div>
    @endif

    {{-- Error log — new row after Namespaces --}}
    @if(! empty($disk['nvme_errors']))
    <div class="smart-row-break"></div>
    <div>
        @php
            $panelStart('Error Log', '<span class="label label-warning">' . count($disk['nvme_errors']) . '</span>');
            echo '<table class="table table-condensed table-hover"><thead><tr>'
                . '<th>#</th><th>Count</th><th>Status</th><th>LBA</th><th>NSID</th><th>Time</th></tr></thead><tbody>';
            foreach ($disk['nvme_errors'] as $e) {
                $status = trim((string) ($e['status_string'] ?? '')) !== '' ? $e['status_string'] : (string) ($e['status_field'] ?? '-');
                echo '<tr><td>' . htmlspecialchars((string) ($e['entry_num'] ?? '-')) . '</td>'
                    . '<td>' . htmlspecialchars($fmtInt($e['error_count'] ?? null) ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars((string) $status) . '</td>'
                    . '<td>' . htmlspecialchars((string) ($e['lba'] ?? '-')) . '</td>'
                    . '<td>' . htmlspecialchars((string) ($e['ns_id'] ?? '-')) . '</td>'
                    . '<td>' . htmlspecialchars((string) ($e['error_time'] ?? '-')) . '</td></tr>';
            }
            echo '</tbody></table>';
            $panelEnd();
        @endphp
    </div>
    @endif

    @if($showDetailed)
    {{-- Power states --}}
    @if(! empty($disk['nvme_power_states']))
    <div>
        @php
            $panelStart('Power States');
            echo '<table class="table table-condensed table-hover"><thead><tr>'
                . '<th>PS</th><th>Op</th><th>Max</th><th>Active</th><th>Idle</th><th>Entry</th><th>Exit</th></tr></thead><tbody>';
            foreach ($disk['nvme_power_states'] as $ps) {
                $mw = static fn ($v) => is_numeric($v) ? rtrim(rtrim(sprintf('%.4f', (int) $v / 1000), '0'), '.') . ' W' : '-';
                $us = static fn ($v) => is_numeric($v) ? number_format((int) $v) . ' µs' : '-';
                echo '<tr><td>' . htmlspecialchars((string) ($ps['state_id'] ?? '-')) . '</td>'
                    . '<td>' . ($ps['operational'] !== null ? ((int) $ps['operational'] ? 'Y' : 'N') : '-') . '</td>'
                    . '<td>' . htmlspecialchars($mw($ps['max_power_mw'] ?? null)) . '</td>'
                    . '<td>' . htmlspecialchars($mw($ps['active_power_mw'] ?? null)) . '</td>'
                    . '<td>' . htmlspecialchars($mw($ps['idle_power_mw'] ?? null)) . '</td>'
                    . '<td>' . htmlspecialchars($us($ps['entry_latency_us'] ?? null)) . '</td>'
                    . '<td>' . htmlspecialchars($us($ps['exit_latency_us'] ?? null)) . '</td></tr>';
            }
            echo '</tbody></table>';
            $panelEnd();
        @endphp
    </div>
    @endif

    {{-- Capability --}}
    @if(! empty($disk['nvme_capability']))
    <div>
        @php
            $cap = $disk['nvme_capability'];
            $panelStart('Capabilities');
            echo '<table class="table table-condensed table-hover" style="width:auto">';
            $rows = [
                'Firmware Slots'   => $fmtInt($cap['firmware_slot_count'] ?? null),
                'FW Reset Req.'    => $yesNo($cap['firmware_reset_required'] ?? null),
                'Optional Admin'   => $cap['optional_admin_cmd_text'] ?? null,
                'Optional NVM'     => $cap['optional_nvm_cmd_text'] ?? null,
                'Log Page Attrs'   => $cap['log_page_attrs_text'] ?? null,
            ];
            foreach ($rows as $label => $value) {
                if ($value !== null && $value !== '') {
                    echo $tableRow($label, htmlspecialchars((string) $value));
                }
            }
            echo '</table>';
            $panelEnd();
        @endphp
    </div>
    @endif
    @endif

</div>
@endif

@if($showGraphs)
@php
    $nvSensorGraph = static function ($sensor, string $title) use ($now, $panelStart, $panelEnd, $device) {
        if (! $sensor) { return; }
        $graph_array = [
            'height' => '100', 'width' => '215', 'to' => $now,
            'id' => $sensor->sensor_id, 'type' => 'sensor_' . $sensor->sensor_class, 'legend' => 'no',
        ];
        $panelStart(htmlspecialchars($title));
        echo '<div class="row">';
        include 'includes/html/print-graphrow.inc.php';
        echo '</div>';
        $panelEnd();
    };

    $nvAppGraph = static function (string $type, string $title, array $extra = []) use ($now, $data, $disk, $panelStart, $panelEnd, $device) {
        $graph_array = array_merge([
            'height' => '100', 'width' => '215', 'to' => $now,
            'id' => $data->app->app_id, 'type' => 'application_' . $type,
            'disk' => $disk['idx'], 'scale_min' => '0',
        ], $extra);
        $panelStart(htmlspecialchars($title));
        echo '<div class="row">';
        include 'includes/html/print-graphrow.inc.php';
        echo '</div>';
        $panelEnd();
    };

    // Combined temperature (overlaid sensors + warn/crit limit lines).
    $nvAppGraph('smart_v2_temp', 'Temperature');
    // Wear / available spare.
    $nvAppGraph('smart_v2_nvme_wear', 'Wear / Spare');
    // SMART/Health log metric breakdowns (from the smart_nvme RRD).
    $nvAppGraph('smart_v2_nvme', 'Data Units', ['metric' => 'data_units']);
    $nvAppGraph('smart_v2_nvme', 'Host I/O', ['metric' => 'host_io']);
    $nvAppGraph('smart_v2_nvme', 'Errors', ['metric' => 'errors']);
    $nvAppGraph('smart_v2_nvme', 'Power', ['metric' => 'power']);
    $nvAppGraph('smart_v2_nvme', 'Controller Busy', ['metric' => 'controller_busy']);
    $nvAppGraph('smart_v2_nvme', 'Temp Threshold Time', ['metric' => 'temp_time']);
    // Health state and per-sensor temperatures (each with its own limit lines).
    $nvSensorGraph($healthSensor, 'Health');
    foreach ($tempSensors as $sensor) {
        $nvSensorGraph($sensor, (string) $sensor->sensor_descr);
    }
@endphp
@endif
