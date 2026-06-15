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

    // Current self-test operation (in progress). 0 = none.
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
    }
@endphp

@if(! $showGraphs)
<style>
    .smart-panels { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-start; margin-bottom:15px }
    .smart-panels .panel { flex:0 0 auto; margin-bottom:0 }
    .smart-panels table { white-space:nowrap }
</style>
<div class="smart-panels">

    {{-- Identity --}}
    <div>
        @php
            $panelStart(htmlspecialchars($data->deviceLabel($disk)), $healthBadge);
            echo '<table class="table table-condensed table-hover" style="width:auto">';
            $cap = $info['total_nvm_capacity_bytes'] ?? null;
            $unalloc = $info['unallocated_nvm_capacity_bytes'] ?? null;
            $rows = [
                'Model'           => $disk['model_name'] ?? null,
                'Serial'          => $disk['serial_number'] ?? null,
                'Firmware'        => $disk['firmware_version'] ?? null,
                'WWN'             => $disk['wwn'] ?? null,
                'Device'          => $disk['device_name'] ?? null,
                'Path'            => $disk['device_path'] ?? null,
                'NVMe Version'    => $info['nvme_version'] ?? null,
                'Controller ID'   => $fmtInt($info['controller_id'] ?? null),
                'PCI Vendor'      => isset($info['pci_vendor_id']) && is_numeric($info['pci_vendor_id'])
                    ? sprintf('0x%04X', (int) $info['pci_vendor_id']) : null,
                'IEEE OUI'        => isset($info['ieee_oui']) && is_numeric($info['ieee_oui'])
                    ? sprintf('0x%06X', (int) $info['ieee_oui']) : null,
                'Capacity'        => $fmtBytes($cap),
                'Unallocated'     => $fmtBytes($unalloc),
                'Namespaces'      => $fmtInt($info['namespace_count'] ?? null),
                'Max Transfer'    => isset($info['max_data_transfer_pages']) && is_numeric($info['max_data_transfer_pages'])
                    ? $fmtInt($info['max_data_transfer_pages']) . ' pages' : null,
                'Power On Hours'  => $fmtInt($health['power_on_hours'] ?? null),
                'Power Cycles'    => $fmtInt($health['power_cycles'] ?? null),
                'Last Poll'       => $disk['last_poll_time'] ?? null,
                'Last Poll Result' => $disk['last_poll_result'] !== null ? $data->decode('poll_result', $disk['last_poll_result']) : null,
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

    {{-- SMART / Health log --}}
    <div>
        @php
            $panelStart('SMART / Health', $healthBadge);
            echo '<table class="table table-condensed table-hover" style="width:auto">';
            $cw = $health['critical_warning'] ?? null;
            $du = 512000; // standard NVMe data-unit size in bytes
            $rows = [
                'Critical Warning' => $cw !== null ? ((int) $cw === 0 ? 'None' : 'Set (0x' . dechex((int) $cw) . ')') : null,
                'Data Read'        => isset($health['data_units_read']) && is_numeric($health['data_units_read'])
                    ? $fmtBytes((int) $health['data_units_read'] * $du) : null,
                'Data Written'     => isset($health['data_units_written']) && is_numeric($health['data_units_written'])
                    ? $fmtBytes((int) $health['data_units_written'] * $du) : null,
                'Host Reads'       => $fmtInt($health['host_read_commands'] ?? null),
                'Host Writes'      => $fmtInt($health['host_write_commands'] ?? null),
                'Controller Busy'  => isset($health['controller_busy_time']) && is_numeric($health['controller_busy_time'])
                    ? $fmtInt($health['controller_busy_time']) . ' min' : null,
                'Media Errors'     => $fmtInt($health['media_errors'] ?? null),
                'Error Log Entries' => $fmtInt($health['num_err_log_entries'] ?? null),
                'Unsafe Shutdowns' => $fmtInt($health['unsafe_shutdowns'] ?? null),
                'Warning Temp Time' => isset($health['warning_temp_time']) && is_numeric($health['warning_temp_time'])
                    ? $fmtInt($health['warning_temp_time']) . ' min' : null,
                'Critical Temp Time' => isset($health['critical_comp_time']) && is_numeric($health['critical_comp_time'])
                    ? $fmtInt($health['critical_comp_time']) . ' min' : null,
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

    {{-- Temperatures --}}
    @if(! empty($tempSensors))
    <div>
        @php
            $panelStart('Temperatures');
            echo '<table class="table table-condensed table-hover"><thead><tr>'
                . '<th>Sensor</th><th>Current</th><th>Warn</th><th>Crit</th></tr></thead><tbody>';
            $tdeg = static fn ($v) => is_numeric($v) ? rtrim(rtrim(sprintf('%.1f', (float) $v), '0'), '.') . '°C' : '-';
            foreach ($tempSensors as $s) {
                // Strip the "SMART <label> " prefix to leave just the sensor name (e.g. Composite).
                $nm = preg_replace('/^SMART\s+/', '', (string) $s->sensor_descr);
                echo '<tr><td>' . htmlspecialchars($nm) . '</td>'
                    . '<td>' . htmlspecialchars($tdeg($s->sensor_current)) . '</td>'
                    . '<td>' . htmlspecialchars($tdeg($s->sensor_limit_warn)) . '</td>'
                    . '<td>' . htmlspecialchars($tdeg($s->sensor_limit)) . '</td></tr>';
            }
            echo '</tbody></table>';
            $panelEnd();
        @endphp
    </div>
    @endif

    {{-- Namespaces & LBA Formats (combined, grouped by namespace) --}}
    @if(! empty($disk['nvme_namespaces']))
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

    {{-- Self-test log --}}
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

    {{-- Error log --}}
    @if(! empty($disk['nvme_errors']))
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
