{{-- NVMe per-disk "Metadata" view: static identity/capability metadata that --}}
{{-- rarely changes, namespaces & LBA formats, and power states. Inherits closures --}}
{{-- ($panelStart, $panelEnd, $tableRow, $tooltipForLabel) and $data, $device, --}}
{{-- $selectedDisk, $smartUrl from the parent smart.blade.php. --}}
@php
    $disk = $data->disk($selectedDisk);
    $idx  = $disk['idx'];
    $info = $disk['info'];

    $fmtInt   = static fn ($v) => is_numeric($v) ? number_format((int) $v, 0, '.', ' ') : null;
    $fmtBytes = static fn ($v) => is_numeric($v) ? \LibreNMS\Util\Number::formatBi((int) $v) : null;
    $yesNo    = static fn ($v) => $v === null ? null : ((int) $v ? 'Yes' : 'No');
@endphp

<div class="tw:grid tw:grid-cols-1 tw:md:grid-cols-2 tw:gap-4">
    {{-- ============================== Left column ============================== --}}
    <div class="tw:min-w-0">
        {{-- Metadata (NVMe identity / capacity details) --}}
        @php
            $unalloc = $info['unallocated_nvm_capacity_bytes'] ?? null;

            $rows = [
                'NVMe Version'  => $info['nvme_version'] ?? null,
                'Controller ID' => $fmtInt($info['controller_id'] ?? null),
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
                'Unallocated'   => $fmtBytes($unalloc),
                'Namespaces'    => $fmtInt($info['namespace_count'] ?? null),
                'Max Transfer'  => isset($info['max_data_transfer_pages']) && is_numeric($info['max_data_transfer_pages'])
                    ? $fmtInt($info['max_data_transfer_pages']) . ' pages' : null,
                'Link Speed'    => isset($info['current_link_speed']) && is_numeric($info['current_link_speed'])
                    ? sprintf('%.1f GT/s', $info['current_link_speed'] / 10)
                        . (isset($info['max_link_speed']) && is_numeric($info['max_link_speed']) && (int) $info['max_link_speed'] !== (int) $info['current_link_speed']
                            ? sprintf(' (max %.1f GT/s)', $info['max_link_speed'] / 10) : '')
                    : null,
                'Link Width'    => isset($info['current_link_width']) && is_numeric($info['current_link_width'])
                    ? 'x' . (int) $info['current_link_width']
                        . (isset($info['max_link_width']) && is_numeric($info['max_link_width']) && (int) $info['max_link_width'] !== (int) $info['current_link_width']
                            ? ' (max x' . (int) $info['max_link_width'] . ')' : '')
                    : null,
                'Last Poll Result' => $disk['last_poll_result'] !== null ? $data->decode('poll_result', $disk['last_poll_result']) : null,
            ];
            $rows = array_filter($rows, fn ($v) => $v !== null && $v !== '');
        @endphp
        @if($rows !== [])
        @php
            $panelStart('Metadata');
            echo '<table class="table table-condensed table-hover" style="width:100%">';
            foreach ($rows as $label => $value) {
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
        @endif

        {{-- Capabilities --}}
        @if(! empty($disk['nvme_capability']))
        @php
            $cap = $disk['nvme_capability'];

            // Bit labels from the SmartmonNvmeOptionalAdminCommands, SmartmonNvmeOptionalNvmCommands
            // and SmartmonNvmeLogPageAttributes TEXTUAL-CONVENTIONs in SMARTMON-TC-MIB.
            $nvmeCapFlags = [
                'optional_admin_cmd_raw' => [
                    0  => 'Security Send/Receive',
                    1  => 'Format NVM',
                    2  => 'Firmware Commit/Download',
                    3  => 'Namespace Management',
                    4  => 'Device Self-test',
                    5  => 'Directives',
                    6  => 'MI Send/Receive',
                    7  => 'Virtualization Management',
                    8  => 'Doorbell Buffer Config',
                    9  => 'Get LBA Status',
                    10 => 'Command and Feature Lockdown',
                ],
                'optional_nvm_cmd_raw' => [
                    0 => 'Compare',
                    1 => 'Write Uncorrectable',
                    2 => 'Dataset Management',
                    3 => 'Write Zeroes',
                    4 => 'Save/Select Feature Non-zero',
                    5 => 'Reservations',
                    6 => 'Timestamp',
                    7 => 'Verify',
                    8 => 'Copy',
                ],
                'log_page_attrs_raw' => [
                    0 => 'SMART/Health Per Namespace',
                    1 => 'Commands & Effects Log',
                    2 => 'Extended Get Log Page',
                    3 => 'Telemetry Log',
                    4 => 'Persistent Event Log',
                    5 => 'Supported Log Pages Log',
                    6 => 'Telemetry Data Area 4',
                ],
            ];

            $nvmeCapSectionLabels = [
                'optional_admin_cmd_raw' => 'Optional Admin Commands',
                'optional_nvm_cmd_raw'   => 'Optional NVM Commands',
                'log_page_attrs_raw'     => 'Log Page Attributes',
            ];

            // Short MIB acronym (smartmonNvme*Raw OBJECT-TYPEs in SMARTMON-NVME-MIB), shown
            // next to the section heading.
            $nvmeCapSectionAcronyms = [
                'optional_admin_cmd_raw' => 'OACS',
                'optional_nvm_cmd_raw'   => 'ONCS',
                'log_page_attrs_raw'     => 'LPA',
            ];

            // One sub-table per section; kept whole (not split) within the CSS column layout below.
            $capSection = static function (string $heading, string $rows): string {
                if ($rows === '') {
                    return '';
                }

                $headingHtml = htmlspecialchars($heading);

                return '<div style="break-inside:avoid-column;-webkit-column-break-inside:avoid;margin-bottom:14px">'
                    . '<div style="font-weight:bold;border-bottom:1px solid #ddd;margin-bottom:4px;padding-bottom:2px">' . $headingHtml . '</div>'
                    . '<table class="table table-condensed table-hover" style="width:auto;margin-bottom:0">' . $rows . '</table>'
                    . '</div>';
            };

            $panelStart('Capabilities');

            $sectionsHtml = '';

            $fwRows = '';
            if (($slots = $fmtInt($cap['firmware_slot_count'] ?? null)) !== null) {
                $fwRows .= $tableRow('Firmware Slots', htmlspecialchars($slots), $tooltipForLabel('Firmware Slots'));
            }
            if (($fwReset = $yesNo($cap['firmware_reset_required'] ?? null)) !== null) {
                $fwRows .= $tableRow('FW Reset Req.', $fwReset === 'Yes' ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>', $tooltipForLabel('FW Reset Req.'));
            }
            $sectionsHtml .= $capSection('Firmware', $fwRows);

            foreach ($nvmeCapFlags as $col => $bits) {
                if (! isset($cap[$col])) {
                    continue;
                }
                $raw = (int) $cap[$col];
                $rows = '';
                foreach ($bits as $bit => $label) {
                    $val = ($raw >> $bit) & 1;
                    $icon = $val ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>';
                    $rows .= $tableRow($label, $icon, $tooltipForLabel($label));
                }
                $acronym = $nvmeCapSectionAcronyms[$col] ?? '';
                $heading = $nvmeCapSectionLabels[$col] . ($acronym !== '' ? ' (' . $acronym . ')' : '');
                $sectionsHtml .= $capSection($heading, $rows);
            }

            echo '<div style="column-width:260px;column-count:2;column-gap:18px">' . $sectionsHtml . '</div>';
            $panelEnd();
        @endphp
        @endif
    </div>

    {{-- ============================= Right column ============================= --}}
    <div class="tw:min-w-0">
        {{-- Namespaces & LBA Formats --}}
        @if(! empty($disk['nvme_namespaces']))
        @php
            $panelStart('Namespaces & LBA Formats');
            echo '<div class="table-responsive"><table class="table table-condensed table-hover"><thead><tr>'
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
            echo '</tbody></table></div>';
            $panelEnd();
        @endphp
        @endif

        {{-- Power States --}}
        @if(! empty($disk['nvme_power_states']))
        @php
            $lpsBadge = '';
            if (isset($info['link_power_state'])) {
                $lpsBadge = '<span class="label label-default" style="cursor:help;text-decoration:underline dotted" title="'
                    . htmlspecialchars($tooltipForLabel('Link Power State'), ENT_QUOTES) . '">'
                    . htmlspecialchars($data->decode('link_power_state', $info['link_power_state'])) . '</span>';
            }
            $panelStart('Power States', $lpsBadge);
            echo '<div class="table-responsive"><table class="table table-condensed table-hover"><thead><tr>'
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
            echo '</tbody></table></div>';
            $panelEnd();
        @endphp
        @endif
    </div>
</div>
