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
    $overall = $health['overall_status'] ?? null;
    $healthBadge = match ((int) $overall) {
        1 => '<span class="label label-success">Passed</span>',
        2 => '<span class="label label-danger">Failed</span>',
        3 => '<span class="label label-warning">Warning</span>',
        4 => '<span class="label label-warning">Unavailable</span>',
        default => '',
    };

    $fmtInt  = static fn ($v) => is_numeric($v) ? number_format((int) $v, 0, '.', ' ') : null;
    $fmtBytes = static fn ($v) => is_numeric($v) ? \LibreNMS\Util\Number::formatBi((int) $v) : null;
    $yesNo   = static fn ($v) => $v === null ? null : ((int) $v ? 'Yes' : 'No');
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

    {{-- Namespaces --}}
    @if(! empty($disk['nvme_namespaces']))
    <div>
        @php
            $panelStart('Namespaces');
            echo '<table class="table table-condensed table-hover"><thead><tr>'
                . '<th>NS</th><th>Size</th><th>Capacity</th><th>Used</th><th>LBA Size</th></tr></thead><tbody>';
            foreach ($disk['nvme_namespaces'] as $ns) {
                $lba = is_numeric($ns['lba_data_size'] ?? null) ? (int) $ns['lba_data_size'] : null;
                $toBytes = static fn ($blocks) => ($lba && is_numeric($blocks)) ? \LibreNMS\Util\Number::formatBi((int) $blocks * $lba) : '-';
                echo '<tr><td>' . htmlspecialchars((string) ($ns['ns_id'] ?? '-')) . '</td>'
                    . '<td>' . htmlspecialchars($toBytes($ns['nsze'] ?? null)) . '</td>'
                    . '<td>' . htmlspecialchars($toBytes($ns['ncap'] ?? null)) . '</td>'
                    . '<td>' . htmlspecialchars($toBytes($ns['nuse'] ?? null)) . '</td>'
                    . '<td>' . ($lba !== null ? $lba . ' B' : '-') . '</td></tr>';
            }
            echo '</tbody></table>';
            $panelEnd();
        @endphp
    </div>
    @endif

    {{-- Self-test log --}}
    @if(! empty($disk['selftests']))
    <div>
        @php
            $panelStart('Self-test Log');
            $hasLba = false;
            foreach ($disk['selftests'] as $e) {
                if (is_numeric($e['failing_lba'] ?? null) && (int) $e['failing_lba'] > 0) {
                    $hasLba = true;
                    break;
                }
            }
            echo '<table class="table table-condensed table-hover"><thead><tr>'
                . '<th>Hours</th><th>Type</th><th>Status</th><th>Remaining Percent</th>'
                . ($hasLba ? '<th>First LBA Error</th>' : '') . '</tr></thead><tbody>';
            foreach ($disk['selftests'] as $st) {
                $type = match ((int) ($st['test_type'] ?? 0)) { 1 => 'Short', 2 => 'Extended', 255 => 'Vendor', default => '-' };
                $result = trim((string) ($st['result_text'] ?? '')) !== '' ? $st['result_text'] : (string) ($st['result'] ?? '-');
                $lba = $st['failing_lba'] ?? null;
                echo '<tr><td>' . htmlspecialchars($fmtInt($st['power_on_hours'] ?? null) ?? '-') . '</td>'
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

    {{-- LBA formats --}}
    @if(! empty($disk['nvme_lba_formats']))
    <div>
        @php
            $panelStart('LBA Formats');
            echo '<table class="table table-condensed table-hover"><thead><tr>'
                . '<th>NS</th><th>Fmt</th><th>Current</th><th>Data</th><th>Meta</th><th>Rel Perf</th></tr></thead><tbody>';
            foreach ($disk['nvme_lba_formats'] as $lf) {
                echo '<tr><td>' . htmlspecialchars((string) ($lf['ns_id'] ?? '-')) . '</td>'
                    . '<td>' . htmlspecialchars((string) ($lf['format_id'] ?? '-')) . '</td>'
                    . '<td>' . ($lf['current'] !== null ? ((int) $lf['current'] ? '✓' : '') : '') . '</td>'
                    . '<td>' . htmlspecialchars(is_numeric($lf['data_size_bytes'] ?? null) ? $lf['data_size_bytes'] . ' B' : '-') . '</td>'
                    . '<td>' . htmlspecialchars(is_numeric($lf['metadata_size_bytes'] ?? null) ? $lf['metadata_size_bytes'] . ' B' : '-') . '</td>'
                    . '<td>' . htmlspecialchars((string) ($lf['relative_performance'] ?? '-')) . '</td></tr>';
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
    $tempSensor = $data->temperatureSensor($selectedDisk);
    $diskSensors = $data->diskSensors($selectedDisk);

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

    $nvSensorGraph($tempSensor, 'Temperature');
    $nvSensorGraph($healthSensor, 'Health');
    foreach ($diskSensors as $sIdx => $sensor) {
        if ($sensor->sensor_class === 'percent') {
            $nvSensorGraph($sensor, (string) $sensor->sensor_descr);
        }
    }
    $nvAppGraph('smart_v2_nvme', 'NVMe SMART/Health');
    $nvAppGraph('smart_v2_nvme', 'Data Units', ['metric' => 'data_units']);
    $nvAppGraph('smart_v2_nvme', 'Host I/O', ['metric' => 'host_io']);
@endphp
@endif
