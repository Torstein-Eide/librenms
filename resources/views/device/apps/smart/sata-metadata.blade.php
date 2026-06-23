{{-- SATA/SAS per-disk "Metadata" view: static identity/capability metadata that --}}
{{-- rarely changes, plus the FARM header pages. Inherits closures ($panelStart, --}}
{{-- $panelEnd, $tableRow, $tooltipForLabel, $labelWithTooltip) and $data, $device, --}}
{{-- $selectedDisk, $smartUrl from the parent smart.blade.php. --}}
@php
    $disk = $data->disk($selectedDisk);
    $idx  = $disk['idx'];
    $info = $disk['info'];
@endphp

<div class="tw:grid tw:grid-cols-1 tw:md:grid-cols-2 tw:gap-4">
    {{-- ============================== Left column ============================== --}}
    <div class="tw:min-w-0">
        {{-- Metadata (high-level interface / geometry) --}}
        @php
            $rot  = $info['rotation_rate'] ?? null;
            $rows = [
                'Interface Speed' => $data->interfaceSpeed($info),
                'Rotation Rate'   => is_numeric($rot) ? ((int) $rot === 0 ? 'Solid State Device' : ((int) $rot) . ' RPM') : null,
                'Form Factor'     => isset($info['form_factor']) ? $data->decode('form_factor', $info['form_factor']) : null,
                'ATA Version'     => isset($info['ata_version']) ? $data->decode('ata_version', $info['ata_version']) : null,
                'SATA Version'    => isset($info['sata_version']) ? $data->decode('sata_version', $info['sata_version']) : null,
                'Logical Block'   => isset($info['logical_block_size']) && is_numeric($info['logical_block_size']) ? \LibreNMS\Util\Number::formatSi((int) $info['logical_block_size'], 0, 0, 'B') : null,
                'Physical Block'  => isset($info['physical_block_size']) && is_numeric($info['physical_block_size']) ? \LibreNMS\Util\Number::formatSi((int) $info['physical_block_size'], 0, 0, 'B') : null,
            ];
            $rows = array_filter($rows, fn ($v) => $v !== null && $v !== '');
        @endphp
        @if($rows !== [])
        @php
            $panelStart('Metadata');
            echo '<table class="table table-condensed table-hover" style="width:100%">';
            foreach ($rows as $label => $value) {
                echo $tableRow($label, htmlspecialchars((string) $value), $tooltipForLabel($label));
            }
            echo '</table>';
            $panelEnd();
        @endphp
        @endif

        {{-- Capabilities & Features --}}
        @php
            // Self-test and Offline Data Collection capability groups live on the Self-test view.
            $capGroups = [
                'Logging' => [
                    'capability_error_logging_supported' => 'Error logging',
                    'capability_gp_logging_supported'    => 'GP logging',
                    'capability_attr_autosave'           => 'Attribute autosave',
                ],
                'SMART Command Transport' => [
                    'sct_error_recovery_supported'  => 'SCT error recovery control',
                    'sct_feature_control_supported' => 'SCT feature control',
                    'sct_data_table_supported'      => 'SCT data table',
                ],
            ];
            $capFields = array_merge(...array_values($capGroups));
            $capRows   = array_filter($capFields, fn ($col) => isset($info[$col]), ARRAY_FILTER_USE_KEY);

            $featureRows = [
                'SMART'           => ($info['smart_available'] ?? null) !== null ? (((int) $info['smart_available']) ? 'Available' : 'Not available') : null,
                'SMART Enabled'   => ($info['smart_enabled'] ?? null) !== null ? (((int) $info['smart_enabled']) ? 'Yes' : 'No') : null,
                'Write Cache'     => ($info['write_cache_enabled'] ?? null) !== null ? (((int) $info['write_cache_enabled']) ? 'Enabled' : 'Disabled') : null,
                'Read Look-ahead' => ($info['read_lookahead_enabled'] ?? null) !== null ? (((int) $info['read_lookahead_enabled']) ? 'Enabled' : 'Disabled') : null,
                'TRIM'            => ($info['trim_supported'] ?? null) !== null ? (((int) $info['trim_supported']) ? 'Supported' : 'Not supported') : null,
                'APM'             => $data->apmLabel($info) !== '-' ? $data->apmLabel($info) : null,
                'Security'        => $data->securityLabel($info) !== '-' ? $data->securityLabel($info) : null,
            ];
            $featureRows = array_filter($featureRows, fn ($v) => $v !== null && $v !== '');
        @endphp
        @if($capRows !== [] || $featureRows !== [])
        @php
            $panelStart('Capabilities &amp; Features');
            echo '<table class="table table-condensed table-hover" style="width:100%">';
            foreach ($featureRows as $label => $value) {
                echo $tableRow($label, htmlspecialchars((string) $value), $tooltipForLabel($label));
            }
            foreach ($capGroups as $heading => $cols) {
                $groupRows = '';
                foreach ($cols as $col => $label) {
                    if (! isset($info[$col])) { continue; }
                    $icon = (int) $info[$col] ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>';
                    $groupRows .= $tableRow($label, $icon, $tooltipForLabel($label));
                }
                if ($groupRows !== '') {
                    echo '<tr><td colspan="2" style="padding-top:10px;font-weight:bold;border-bottom:1px solid #ddd">' . htmlspecialchars($heading) . '</td></tr>';
                    echo $groupRows;
                }
            }
            echo '</table>';
            $panelEnd();
        @endphp
        @endif
    </div>

    {{-- ============================= Right column ============================= --}}
    <div class="tw:min-w-0">
        {{-- Log Directory --}}
        @if(! empty($disk['log_dir']))
        @php
            $panelStart('Log Directory', (string) count($disk['log_dir']));
            echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover" style="width:auto">';
            echo '<thead><tr><th>Address</th><th>Name</th><th>Readable</th><th>Writable</th><th>GP Sectors</th><th>SMART Sectors</th></tr></thead><tbody>';
            foreach ($disk['log_dir'] as $entry) {
                $rd = $entry['readable'] ?? null;
                $wr = $entry['writable'] ?? null;
                echo '<tr>'
                    . '<td>0x' . sprintf('%02X', (int) ($entry['log_address'] ?? 0)) . '</td>'
                    . '<td>' . htmlspecialchars((string) ($entry['name'] ?? '')) . '</td>'
                    . '<td>' . ($rd !== null ? ((int) $rd ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>') : '') . '</td>'
                    . '<td>' . ($wr !== null ? ((int) $wr ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>') : '') . '</td>'
                    . '<td>' . htmlspecialchars((string) ($entry['gp_sectors'] ?? '')) . '</td>'
                    . '<td>' . htmlspecialchars((string) ($entry['smart_sectors'] ?? '')) . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table></div>';
            $panelEnd();
        @endphp
        @endif

        {{-- Error Recovery Control (SCT ERC) --}}
        @if(! empty($disk['erc']))
        @php
            $panelStart('Error Recovery Control (SCT ERC)');
            echo '<table class="table table-condensed table-hover" style="width:100%">';
            foreach ($disk['erc'] as $direction => $row) {
                $label = $data->decode('erc_direction', $direction);
                $ds    = $row['deciseconds'] ?? null;
                $val   = ($row['enabled'] ?? 0)
                    ? (is_numeric($ds) ? number_format($ds / 10, 1) . ' s' : 'Enabled')
                    : 'Disabled';
                echo $tableRow($label, htmlspecialchars($val), $tooltipForLabel($label));
            }
            echo '</table>';
            $panelEnd();
        @endphp
        @endif

        {{-- FARM header pages (Drive Information / Log Header) --}}
        @php
            $farmPages   = ['FARM Drive Information', 'FARM Log Header'];
            $fmtStatName = static fn (string $s): string => htmlspecialchars(ucwords(trim(str_replace('_', ' ', $s))));
            $fmtStatVal  = static function ($v): string {
                if (is_numeric($v) && abs((float) $v) >= 1000000) {
                    return \LibreNMS\Util\Number::formatSi((float) $v, 2, 0, '');
                }
                return htmlspecialchars((string) ($v ?? ''));
            };

            foreach ($disk['dev_stats'] as $page) {
                $pn = $page['page_name'] ?: $data->decode('dev_stat_page', $page['page_num']);
                if (! in_array($pn, $farmPages, true)) { continue; }
                $pageRows = array_filter(
                    $page['rows'],
                    static fn ($r) => ! in_array((string) ($r['stat_name'] ?? ''), \LibreNMS\Agent\Unix\Smart\HtmlData::DEV_STAT_SKIP_ROWS, true)
                );
                if (! $pageRows) { continue; }

                $panelStart(htmlspecialchars($pn));
                echo '<table class="table table-condensed table-striped table-hover" style="width:100%;font-size:12px">';
                echo '<thead><tr><th>Statistic</th><th>Value</th></tr></thead><tbody>';
                foreach ($pageRows as $r) {
                    echo '<tr><td>' . $labelWithTooltip(html_entity_decode($fmtStatName((string) ($r['stat_name'] ?? '')), ENT_QUOTES)) . '</td>'
                        . '<td>' . $fmtStatVal($r['value'] ?? null) . '</td></tr>';
                }
                echo '</tbody></table>';
                $panelEnd();
            }
        @endphp
    </div>
</div>
