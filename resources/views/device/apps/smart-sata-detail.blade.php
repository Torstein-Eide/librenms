{{-- SATA/SAS per-disk detail. Included from smart.blade.php for non-NVMe disks. --}}
{{-- Inherits closures ($panelStart, $panelEnd, $tableRow, $tooltipForLabel, $labelWithTooltip, --}}
{{-- $formatHoursAgo, $stateBadge) and $data, $device, $selectedDisk, $linkArray, $viewMode from the parent view. --}}
    @php
        $disk    = $data->disk($selectedDisk);
        $idx     = $disk['idx'];
        $info    = $disk['info'];
        $health  = $disk['health'];
        $powerOnHours = $data->powerOnHours($disk);
        $healthSensor = $data->healthSensor($selectedDisk);
        $healthBadge  = $stateBadge($healthSensor, 'SMART overall-health self-assessment test result.');

        // Self-test panel badge (running / passed / failed).
        $execRaw   = $health['selftest_exec_status_raw'] ?? null;
        $remaining = $health['selftest_remaining_pct'] ?? null;
        if ((int) $execRaw === 15 || (is_numeric($remaining) && (int) $remaining > 0)) {
            $donePct = is_numeric($remaining) ? max(0, min(100, 100 - (int) $remaining)) : null;
            $selftestPanelBadge = '<span class="label label-info">Running' . ($donePct !== null ? " {$donePct}%" : '') . '</span>';
        } elseif ($execRaw !== null) {
            $selftestPanelBadge = (int) $execRaw === 0
                ? '<span class="label label-default">Passed</span>'
                : '<span class="label label-warning">' . htmlspecialchars($data->decode('selftest_exec', (int) $execRaw)) . '</span>';
        } else {
            $selftestPanelBadge = $healthBadge;
        }

        $showDetailed = $viewMode === 'detailed';
        $showPanels   = $viewMode !== 'graphs';

        $devStatKnownPanels = [
            'General Statistics',
            'Free-Fall Statistics',
            'Rotating Media Statistics',
            'General Errors Statistics',
            'Transport Statistics',
            'FARM Log Header',
            'FARM Drive Information',
            'FARM Workload Statistics',
            'FARM Error Statistics',
            'FARM Environment Statistics',
            'FARM Reliability Statistics',
        ];
        $devStatUnknownPages = [];
        foreach ($disk['dev_stats'] as $page) {
            $pn = $page['page_name'] ?: $data->decode('dev_stat_page', $page['page_num']);
            if (in_array($pn, \LibreNMS\Agent\Unix\Smart\HtmlData::DEV_STAT_SKIP_PAGES, true)) { continue; }
            if (! in_array($pn, $devStatKnownPanels, true)) {
                $devStatUnknownPages[] = $pn;
            }
        }
    @endphp

    @if($showPanels)
    <style>
        .smart-panels { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-start; margin-bottom:15px }
        .smart-panels .panel { flex:0 0 auto; margin-bottom:0 }
        .smart-panels table { white-space:nowrap }
    </style>
    @if(! empty($devStatUnknownPages))
    <div class="alert alert-warning" style="padding:5px 10px;margin-bottom:10px;font-size:12px">
        <strong>Unrecognized device statistics page(s). no panel defined:</strong>
        {{ implode(', ', $devStatUnknownPages) }}
    </div>
    @endif
    <div class="smart-panels">
        {{-- Identity --}}
        <div>
            @php
                $panelStart(htmlspecialchars($data->deviceLabel($disk)), $healthBadge);
                echo '<table class="table table-condensed table-hover" style="width:auto">';
                $cap = $info['user_capacity_bytes'] ?? null;
                $rot = $info['rotation_rate'] ?? null;

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
                    'Model Family'    => $disk['model_family']   ?? null,
                    'Model'           => $disk['model_name']     ?? null,
                    'Serial'          => $disk['serial_number']  ?? null,
                    'Firmware'        => $disk['firmware_version'] ?? null,
                    'WWN'             => $disk['wwn']            ?? null,
                    'Device'          => $disk['device_name']   ?? null,
                    'Path'            => $pathCell,
                    'Capacity'        => is_numeric($cap) ? \LibreNMS\Util\Number::formatBi((int) $cap) : null,
                    'Power On Hours'  => $powerOnHours !== null ? number_format($powerOnHours, 0, '.', ' ') : null,
                    'Power Cycles'    => isset($health['power_cycles']) && is_numeric($health['power_cycles']) ? number_format((int) $health['power_cycles'], 0, '.', ' ') : null,
                    'Interface Speed' => $data->interfaceSpeed($info),
                    'Rotation Rate'   => is_numeric($rot) ? ((int) $rot === 0 ? 'Solid State Device' : ((int) $rot) . ' RPM') : null,
                    'Form Factor'     => isset($info['form_factor']) ? $data->decode('form_factor', $info['form_factor']) : null,
                    'ATA Version'     => isset($info['ata_version']) ? $data->decode('ata_version', $info['ata_version']) : null,
                    'SATA Version'    => isset($info['sata_version']) ? $data->decode('sata_version', $info['sata_version']) : null,
                    'Logical Block'   => isset($info['logical_block_size']) && is_numeric($info['logical_block_size']) ? \LibreNMS\Util\Number::formatSi((int) $info['logical_block_size'], 0, 0, 'B') : null,
                    'Physical Block'  => isset($info['physical_block_size']) && is_numeric($info['physical_block_size']) ? \LibreNMS\Util\Number::formatSi((int) $info['physical_block_size'], 0, 0, 'B') : null,
                    'In smartctl DB'  => ($info['in_smartctl_database'] ?? null) !== null ? (((int) $info['in_smartctl_database']) ? 'Yes' : 'No') : null,
                    'Last Poll'       => $disk['last_poll_time'] ?? null,
                    'Last Poll Result' => $disk['last_poll_result'] !== null ? $data->decode('poll_result', $disk['last_poll_result']) : null,
                ];
                foreach ($rows as $label => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    // HtmlString values (e.g. the tooltipped Path) are already safe HTML.
                    $cell = $value instanceof \Illuminate\Support\HtmlString
                        ? (string) $value : htmlspecialchars((string) $value);
                    echo $tableRow($label, $cell, $tooltipForLabel($label));
                }
                echo '</table>';
                $panelEnd();
            @endphp
        </div>

        {{-- Self-test Log (Selective Self-test Spans embedded) --}}
        @if(! empty($disk['selftests']) || isset($info['selftest_polling_short_minutes']) || ! empty($disk['selective_test']))
        <div>
            @php
                $panelStart('Self-test Log', $selftestPanelBadge);
                $pollingRows = [];
                if (isset($info['selftest_polling_short_minutes']) && is_numeric($info['selftest_polling_short_minutes'])) {
                    $pollingRows[] = 'Short: ' . (int) $info['selftest_polling_short_minutes'] . ' min';
                }
                if (isset($info['selftest_polling_extended_minutes']) && is_numeric($info['selftest_polling_extended_minutes'])) {
                    $pollingRows[] = 'Extended: ' . (int) $info['selftest_polling_extended_minutes'] . ' min';
                }
                if (isset($info['selftest_polling_conveyance_minutes']) && is_numeric($info['selftest_polling_conveyance_minutes'])) {
                    $pollingRows[] = 'Conveyance: ' . (int) $info['selftest_polling_conveyance_minutes'] . ' min';
                }
                if ($pollingRows !== []) {
                    echo '<p style="margin-bottom:6px"><strong>Est. polling minutes:</strong> ' . htmlspecialchars(implode(' / ', $pollingRows)) . '</p>';
                }
                if (! empty($disk['selftests'])) {
                    $hasLba = false;
                    foreach ($disk['selftests'] as $e) {
                        if (is_numeric($e['lba_first_error'] ?? null) && (int) $e['lba_first_error'] > 0) {
                            $hasLba = true;
                            break;
                        }
                    }
                    echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover">';
                    echo '<thead><tr><th>Hours</th><th>Type</th><th>Status</th><th>Remaining Percent</th>'
                        . ($hasLba ? '<th>First LBA Error</th>' : '') . '</tr></thead><tbody>';
                    foreach ($disk['selftests'] as $entry) {
                        $h = $entry['power_on_hours'] ?? null;
                        $hoursCell = (string) ($h ?? '');
                        if ($powerOnHours !== null && is_numeric($h)) {
                            $delta = $powerOnHours - (int) $h;
                            $hoursCell = $delta > 0 ? $formatHoursAgo($delta) . " ({$h})" : "<0 hour ({$h})";
                        }
                        $rem = $entry['remaining_pct'] ?? null;
                        $remCell = (is_numeric($rem) && (int) $rem > 0) ? ((int) $rem) . '%' : '';
                        $lba = $entry['lba_first_error'] ?? null;
                        echo '<tr>'
                            . '<td>' . htmlspecialchars($hoursCell) . '</td>'
                            . '<td>' . htmlspecialchars($data->decode('selftest_type', $entry['test_type'] ?? null)) . '</td>'
                            . '<td>' . htmlspecialchars($data->decode('selftest_result', $entry['result'] ?? null)) . '</td>'
                            . '<td>' . htmlspecialchars($remCell) . '</td>'
                            . ($hasLba ? '<td>' . (is_numeric($lba) && (int) $lba > 0 ? htmlspecialchars((string) $lba) : '') . '</td>' : '')
                            . '</tr>';
                    }
                    echo '</tbody></table></div>';
                }
                if (! empty($disk['selective_test'])) {
                    echo '<h5 style="margin-top:12px;margin-bottom:6px"><strong>Selective Self-test Spans</strong></h5>';
                    echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover" style="width:auto">';
                    echo '<thead><tr><th>Slot</th><th>LBA Min</th><th>LBA Max</th><th>Status</th></tr></thead><tbody>';
                    foreach ($disk['selective_test'] as $entry) {
                        echo '<tr>'
                            . '<td>' . htmlspecialchars((string) ($entry['slot'] ?? '')) . '</td>'
                            . '<td>' . htmlspecialchars((string) ($entry['lba_min'] ?? '')) . '</td>'
                            . '<td>' . htmlspecialchars((string) ($entry['lba_max'] ?? '')) . '</td>'
                            . '<td>' . htmlspecialchars((string) ($entry['status_value'] ?? '')) . '</td>'
                            . '</tr>';
                    }
                    echo '</tbody></table></div>';
                }
                $panelEnd();
            @endphp
        </div>
        @endif

        {{-- Offline Data Collection --}}
        @php
            $offlineSecs = $info['offline_collection_completion_secs'] ?? null;
            $offlineStatus = $health['offline_collection_status'] ?? null;
        @endphp
        @if($offlineSecs !== null || $offlineStatus !== null)
        <div>
            @php
                $panelStart('Offline Data Collection');
                echo '<table class="table table-condensed table-hover" style="width:auto">';
                if ($offlineStatus !== null && is_numeric($offlineStatus)) {
                    $autoEnabled = ((int) $offlineStatus & 0x80) !== 0;
                    $statusText = $data->decode('offline_status', (int) $offlineStatus & 0x7f);
                    echo $tableRow('Status', htmlspecialchars($statusText), $tooltipForLabel('Status'));
                    echo $tableRow(
                        'Auto Offline Data Collection',
                        $autoEnabled ? '<span class="text-success">Enabled</span>' : '<span class="text-muted">Disabled</span>',
                        $tooltipForLabel('Auto Offline Data Collection')
                    );
                }
                if ($offlineSecs !== null && is_numeric($offlineSecs)) {
                    echo $tableRow('Total Time to Complete', htmlspecialchars((int) $offlineSecs . ' s'), $tooltipForLabel('Total Time to Complete'));
                }
                echo '</table>';
                $panelEnd();
            @endphp
        </div>
        @endif

        {{-- Health / SCT (detailed only) --}}
        @if($showDetailed)
        <div>
            @php
                $panelStart('Health &amp; SCT', $healthBadge);
                echo '<table class="table table-condensed table-hover" style="width:auto">';
                $sctTemps = [];
                foreach (['sct_temp_lifetime_min' => 'Lifetime Min', 'sct_temp_lifetime_max' => 'Lifetime Max'] as $col => $lbl) {
                    if (isset($health[$col]) && is_numeric($health[$col])) {
                        $sctTemps[] = $lbl . ': ' . (int) $health[$col] . '°C';
                    }
                }
                $hrows = [
                    'SMART Status'         => isset($health['overall_status']) ? ((int) $health['overall_status'] === 1 ? 'Passed' : 'Not passed') : null,
                    'Pending Defects'      => $health['pending_defects_count'] ?? null,
                    'SCT Lifetime Temp'    => $sctTemps !== [] ? implode(' / ', $sctTemps) : null,
                    'SCT Over-limit Count' => $health['sct_temp_over_limit_count'] ?? null,
                    'SCT Under-limit Count' => $health['sct_temp_under_limit_count'] ?? null,
                ];
                foreach ($hrows as $label => $value) {
                    if ($value !== null && $value !== '') {
                        echo $tableRow($label, htmlspecialchars((string) $value), $tooltipForLabel($label));
                    }
                }
                echo '</table>';
                $panelEnd();
            @endphp
        </div>
        @endif
    </div>

    {{-- Attributes --}}
    @if(! empty($disk['attributes']))
        @php
            $panelStart('SMART Attributes');

            $attrAppId = $data->app->app_id;
            $attrNow   = \App\Facades\LibrenmsConfig::get('time.now');
            $attrFrom  = \App\Facades\LibrenmsConfig::get('time.day');
            $tblId     = 'smart-attr-tbl-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $idx);

            // Toolbar: text filter + failing-only toggle.
            echo '<div style="margin-bottom:8px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">'
                . '<input type="text" class="form-control input-sm" style="width:220px" placeholder="Filter attributes…"'
                . ' oninput="smartAttrFilter(\'' . $tblId . '\')" id="' . $tblId . '-q">'
                . '<label style="font-weight:normal;margin:0;cursor:pointer"><input type="checkbox" id="' . $tblId . '-fail"'
                . ' onchange="smartAttrFilter(\'' . $tblId . '\')"> Failing / failed only</label>'
                . '<span id="' . $tblId . '-flags" style="font-family:monospace"><span class="text-muted" style="font-size:12px;font-family:initial">Flags:</span> '
                . implode(' ', array_map(static fn ($f) => '<label style="font-weight:normal;margin:0;cursor:pointer">'
                    . '<input type="checkbox" value="' . $f . '" onchange="smartAttrFilter(\'' . $tblId . '\')"> ' . $f . '</label>', ['P', 'O', 'S', 'R', 'C', 'K']))
                . '</span>'
                . '<span class="text-muted" style="font-size:12px">Click a column header to sort.</span>'
                . '</div>';

            echo '<div class="table-responsive"><table id="' . $tblId . '" class="table table-condensed table-hover smart-attr-table">';
            echo '<thead><tr>'
                . '<th class="smart-attr-sort" data-type="num" onclick="smartAttrSort(this)" style="cursor:pointer">ID</th>'
                . '<th class="smart-attr-sort" data-type="str" onclick="smartAttrSort(this)" style="cursor:pointer">Name</th>'
                . '<th class="smart-attr-sort" data-type="num" onclick="smartAttrSort(this)" style="cursor:pointer">Status</th>'
                . '<th>Trend</th>'
                . '<th>Flags</th>'
                . '<th class="smart-attr-sort" data-type="num" onclick="smartAttrSort(this)" style="cursor:pointer">Value</th>'
                . '<th class="smart-attr-sort" data-type="num" onclick="smartAttrSort(this)" style="cursor:pointer">Worst</th>'
                . '<th class="smart-attr-sort" data-type="num" onclick="smartAttrSort(this)" style="cursor:pointer">Thresh</th>'
                . '<th class="smart-attr-sort" data-type="num" onclick="smartAttrSort(this)" style="cursor:pointer">Raw</th>'
                . '</tr></thead><tbody>';

            $dark = session('applied_site_style') === 'dark';

            foreach ($disk['attributes'] as $attr) {
                $status = (int) ($attr['status'] ?? 0);
                $statusLabel = $status === -1 ? 'NA' : $data->decode('attr_status', $attr['status'] ?? null);

                // Row shading by status (dark-mode aware): 2 red, 3 light red, -1 muted.
                $rowStyle = match ($status) {
                    2  => $dark ? 'background-color:#5a2a2a' : 'background-color:#f2a8a8',
                    3  => $dark ? 'background-color:#3f2a2c' : 'background-color:#fbdede',
                    -1 => $dark ? 'background-color:#15171a' : 'background-color:#f4f4f4',
                    default => '',
                };
                $isFail = ($status === 2 || $status === 3) ? '1' : '0';

                $thresh   = $attr['value_threshold'] ?? null;
                $value    = $attr['value_norm'] ?? null;
                $worst    = $attr['value_worst'] ?? null;
                $rawNum   = is_numeric($attr['value_raw'] ?? null) ? (float) $attr['value_raw'] : 0;
                $rawDisp  = $data->formatRawSpaced($attr['value_raw_string'] ?? $attr['value_raw'] ?? '');
                $attrId   = (int) ($attr['attribute_id'] ?? 0);
                $name     = str_replace('_', ' ', (string) ($attr['name'] ?? ''));

                $flagLines = $data->attributeFlagLines($attr);
                $flagsTip  = htmlspecialchars(implode("\n", $flagLines), ENT_QUOTES);
                $flagsRaw  = $data->attributeFlagsPositional($attr);
                $flagsStr  = htmlspecialchars($flagsRaw);
                $flagsCell = $flagLines !== []
                    ? '<span data-toggle="tooltip" data-placement="top" title="' . $flagsTip . '" style="cursor:default;border-bottom:1px dotted;font-family:monospace">' . $flagsStr . '</span>'
                    : '<span style="font-family:monospace">' . $flagsStr . '</span>';

                $valueTip = 'Normalized value (1–253, higher is better)';
                if (is_numeric($thresh) && is_numeric($value)) {
                    $valueTip .= (float) $value < (float) $thresh ? "\nFAIL: below threshold " . $thresh : "\nOK: above threshold " . $thresh;
                }
                $valueCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars($valueTip, ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . htmlspecialchars((string) ($value ?? '')) . '</span>';

                $worstTip = 'Worst normalized value ever recorded';
                if (is_numeric($thresh) && is_numeric($worst)) {
                    $worstTip .= (float) $worst < (float) $thresh ? "\nFAIL: below threshold " . $thresh : "\nOK: above threshold " . $thresh;
                }
                $worstCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars($worstTip, ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . htmlspecialchars((string) ($worst ?? '')) . '</span>';

                $threshCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars('Failure threshold - attribute fails when Value drops below this', ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . htmlspecialchars((string) ($thresh ?? '')) . '</span>';
                $rawCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars('Raw hardware reading - vendor-specific meaning', ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . htmlspecialchars($rawDisp) . '</span>';

                $statusBadge = match ($status) {
                    1  => '<span class="label label-default">' . htmlspecialchars($statusLabel) . '</span>',
                    2  => '<span class="label label-danger">' . htmlspecialchars($statusLabel) . '</span>',
                    3  => '<span class="label" style="background-color:#e8857f">' . htmlspecialchars($statusLabel) . '</span>',
                    default => '<span class="text-muted">' . htmlspecialchars($statusLabel) . '</span>',
                };

                // In-row mini graph: the same smart_v2_attributes graph, 60x15.
                $mini = '';
                if ($attrId > 0) {
                    $miniSrc = 'graph.php?type=application_smart_v2_attributes'
                        . '&id=' . rawurlencode((string) $attrAppId)
                        . '&disk=' . rawurlencode((string) $idx)
                        . '&attr_id=' . $attrId
                        . '&has_raw=1&has_norm=1&legend=no'
                        . '&from=' . rawurlencode((string) $attrFrom)
                        . '&to=' . rawurlencode((string) $attrNow)
                        . '&width=60&height=15';
                    $mini = '<img loading="lazy" width="60" height="15" src="' . htmlspecialchars($miniSrc, ENT_QUOTES) . '" alt="trend" style="display:block">';
                }

                echo '<tr style="' . $rowStyle . '" data-fail="' . $isFail . '" data-flags="' . htmlspecialchars($flagsRaw, ENT_QUOTES) . '">'
                    . '<td data-sort="' . $attrId . '">' . $attrId . '</td>'
                    . '<td data-sort="' . htmlspecialchars($name, ENT_QUOTES) . '">' . htmlspecialchars($name) . '</td>'
                    . '<td data-sort="' . $status . '">' . $statusBadge . '</td>'
                    . '<td>' . $mini . '</td>'
                    . '<td>' . $flagsCell . '</td>'
                    . '<td data-sort="' . htmlspecialchars((string) ($value ?? ''), ENT_QUOTES) . '">' . $valueCell . '</td>'
                    . '<td data-sort="' . htmlspecialchars((string) ($worst ?? ''), ENT_QUOTES) . '">' . $worstCell . '</td>'
                    . '<td data-sort="' . htmlspecialchars((string) ($thresh ?? ''), ENT_QUOTES) . '">' . $threshCell . '</td>'
                    . '<td data-sort="' . $rawNum . '">' . $rawCell . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table></div>';

            echo <<<'JS'
<script>
function smartAttrFilter(tblId) {
    var t = document.getElementById(tblId); if (!t) return;
    var q = (document.getElementById(tblId + '-q').value || '').toLowerCase();
    var failOnly = document.getElementById(tblId + '-fail').checked;
    var flagBox = document.getElementById(tblId + '-flags');
    var flags = flagBox ? Array.prototype.map.call(flagBox.querySelectorAll('input:checked'), function (c) { return c.value; }) : [];
    Array.prototype.forEach.call(t.tBodies[0].rows, function (r) {
        var hit = !q || r.textContent.toLowerCase().indexOf(q) !== -1;
        var fail = !failOnly || r.getAttribute('data-fail') === '1';
        var rf = r.getAttribute('data-flags') || '';
        var flagOk = flags.every(function (f) { return rf.indexOf(f) !== -1; });
        r.style.display = (hit && fail && flagOk) ? '' : 'none';
    });
}
function smartAttrSort(th) {
    var table = th.closest('table');
    var head = th.parentNode;
    var idx = Array.prototype.indexOf.call(head.children, th);
    var type = th.getAttribute('data-type') || 'str';
    var asc = !th.classList.contains('asc');
    Array.prototype.forEach.call(head.children, function (h) { h.classList.remove('asc', 'desc'); });
    th.classList.add(asc ? 'asc' : 'desc');
    var tbody = table.tBodies[0];
    var rows = Array.prototype.slice.call(tbody.rows);
    var key = function (r) {
        var c = r.cells[idx];
        return c.getAttribute('data-sort') !== null ? c.getAttribute('data-sort') : c.textContent.trim();
    };
    rows.sort(function (a, b) {
        var av = key(a), bv = key(b);
        if (type === 'num') { return (asc ? 1 : -1) * ((parseFloat(av) || 0) - (parseFloat(bv) || 0)); }
        return (asc ? 1 : -1) * String(av).localeCompare(String(bv));
    });
    rows.forEach(function (r) { tbody.appendChild(r); });
}
</script>
JS;
            $panelEnd();
        @endphp
    @endif

    @if($showDetailed)
        {{-- Error log --}}
        @if(! empty($disk['errors']))
            @php
                $panelStart('SMART Error Log', (string) count($disk['errors']));
                echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover">';
                echo '<thead><tr><th>#</th><th>Hours</th><th>Type</th><th>Device State</th><th>Previous Commands</th></tr></thead><tbody>';
                foreach ($disk['errors'] as $entry) {
                    $entryNum = (int) ($entry['entry_num'] ?? 0);
                    $h = $entry['lifetime_hours'] ?? null;
                    $hoursCell = (string) ($h ?? '');
                    if ($powerOnHours !== null && is_numeric($h)) {
                        $delta = $powerOnHours - (int) $h;
                        $hoursCell = $delta > 0 ? $formatHoursAgo($delta) . " ({$h})" : "<0 hour ({$h})";
                    }
                    $cmds = $disk['error_cmds'][$entryNum] ?? [];
                    $cmdHtml = '';
                    if ($cmds !== []) {
                        $cmdHtml = '<table class="table table-condensed" style="margin:0;background:transparent">'
                            . '<thead><tr><th>Cmd</th><th>LBA</th><th>Count</th><th>Feature</th><th>Uptime (ms)</th></tr></thead><tbody>';
                        foreach ($cmds as $cmd) {
                            $cmdHtml .= '<tr>'
                                . '<td>' . htmlspecialchars((string) ($cmd['description'] ?? $cmd['reg_command'] ?? '')) . '</td>'
                                . '<td>' . htmlspecialchars((string) ($cmd['reg_lba'] ?? '')) . '</td>'
                                . '<td>' . htmlspecialchars((string) ($cmd['reg_count'] ?? '')) . '</td>'
                                . '<td>' . htmlspecialchars((string) ($cmd['reg_feature'] ?? '')) . '</td>'
                                . '<td>' . htmlspecialchars((string) ($cmd['powerup_ms'] ?? '')) . '</td>'
                                . '</tr>';
                        }
                        $cmdHtml .= '</tbody></table>';
                    }
                    echo '<tr>'
                        . '<td>' . htmlspecialchars((string) ($entry['error_count'] ?? $entryNum)) . '</td>'
                        . '<td>' . htmlspecialchars($hoursCell) . '</td>'
                        . '<td>' . htmlspecialchars((string) ($entry['error_type'] ?? '')) . '</td>'
                        . '<td>' . htmlspecialchars($data->decode('device_state', $entry['device_state'] ?? null)) . '</td>'
                        . '<td>' . $cmdHtml . '</td>'
                        . '</tr>';
                }
                echo '</tbody></table></div>';
                $panelEnd();
            @endphp
        @endif

        {{-- Device statistics (one panel per page, flex row) --}}
        @php
            $fmtStatVal  = static function ($v, string $statName = ''): string {
                // Only "Date and Time TimeStamp" is a real Unix epoch value (milliseconds, per the
                // "Set Date and Time Timestamp" command spec). Other *timestamp fields (e.g.
                // lowest/highest_poh_timestamp) are POH hour bounds, not epoch time.
                $normStatName = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $statName)));
                if ($normStatName === 'date and time timestamp' && is_numeric($v)) {
                    return htmlspecialchars(\Carbon\Carbon::createFromTimestampMsUTC((int) $v)->toDateTimeString() . ' UTC');
                }
                if (is_numeric($v) && abs((float) $v) >= 1000000) {
                    return \LibreNMS\Util\Number::formatSi((float) $v, 2, 0, '');
                }
                return htmlspecialchars((string) ($v ?? ''));
            };
            $fmtStatName = static function (string $s): string {
                static $exactMap = [
                    'poh'  => 'Power-on hours',
                    'spoh' => 'Spin power-on hours',
                ];
                static $wordMap = [
                    'dvga'  => 'Delta Variable Gain Amplifier',
                    'rvga'  => 'Running Average Variable Gain Amplifier',
                    'fvga'  => 'Filter Variable Gain Amplifier',
                    'dos'   => 'Directed Offline Scan',
                    'isp'   => 'Intermediate Super Parity',
                    'h2sat' => 'Head Self-Assessment Test',
                    'mr'    => 'Magneto Resistive',
                ];
                if (isset($exactMap[$s])) {
                    return htmlspecialchars($exactMap[$s]);
                }
                $words = array_map(
                    static fn ($w) => $wordMap[$w] ?? ucfirst($w),
                    explode('_', strtolower($s))
                );
                return htmlspecialchars(implode(' ', $words));
            };
            $fmtStatLabel = static function (string $s) use ($fmtStatName, $labelWithTooltip): string {
                $label = html_entity_decode($fmtStatName($s), ENT_QUOTES);

                return $labelWithTooltip($label);
            };
            $fmtFarmStatLabel = static function (string $s) use ($fmtStatName, $labelWithTooltip, $tooltipForLabel): string {
                $label = html_entity_decode($fmtStatName($s), ENT_QUOTES);

                return $labelWithTooltip($label, $tooltipForLabel($label));
            };
            $fmtMilli = static function ($v, string $unit): string {
                if ($v === null || $v === '') { return ''; }
                return htmlspecialchars(number_format((float) $v / 1000, 3)) . ' ' . $unit;
            };

            $farmSubTables = static function (string $pageName, array $rows) use ($fmtStatVal): array {
                if (! str_starts_with($pageName, 'FARM ')) {
                    return ['scalars' => $rows, 'groups' => []];
                }
                $byName   = [];
                foreach ($rows as $r) { $byName[$r['stat_name'] ?? ''] = $r; }
                $scalars  = [];
                $groups   = [];
                $extract  = [];
                $consumed = [];

                if ($pageName === 'FARM Environment Statistics') {
                    $tempMap = [
                        'curent_temp'        => ['instant', 'current'],
                        'highest_temp'       => ['instant', 'highest'],
                        'lowest_temp'        => ['instant', 'lowest'],
                        'average_temp'       => ['short',   'average'],
                        'highest_short_temp' => ['short',   'highest'],
                        'lowest_short_temp'  => ['short',   'lowest'],
                        'average_long_temp'  => ['long',    'average'],
                        'highest_long_temp'  => ['long',    'highest'],
                        'lowest_long_temp'   => ['long',    'lowest'],
                    ];
                    $tempData = [];
                    foreach ($tempMap as $stat => [$row, $col]) {
                        if (isset($byName[$stat])) {
                            $tempData[$row][$col] = $byName[$stat]['value'];
                            $consumed[$stat]      = true;
                        }
                    }
                    if ($tempData) {
                        $groups[] = ['title' => 'Temperature (°C)', 'type' => 'temp_matrix', 'data' => $tempData];
                    }

                    $limitData = [];
                    foreach ([['max_temp','over_temp_time','Maximum'],['min_temp','under_temp_time','Minimum']] as [$lStat,$tStat,$label]) {
                        if (isset($byName[$lStat], $byName[$tStat])) {
                            $limitData[] = ['label' => $label, 'limit' => $byName[$lStat]['value'], 'time' => $byName[$tStat]['value']];
                            $consumed[$lStat] = $consumed[$tStat] = true;
                        }
                    }
                    if ($limitData) {
                        $groups[] = ['title' => 'Operating Limits', 'type' => 'limits', 'data' => $limitData];
                    }

                    $voltageRails = [
                        '12V (mV)' => ['Current' => 'current_12v_in_mv', 'Minimum' => 'minimum_12v_in_mv', 'Maximum' => 'maximum_12v_in_mv'],
                        '5V (mV)'  => ['Current' => 'current_5v_in_mv',  'Minimum' => 'minimum_5v_in_mv',  'Maximum' => 'maximum_5v_in_mv'],
                    ];
                    $voltData = [];
                    foreach ($voltageRails as $label => $statCols) {
                        $row = ['label' => $label];
                        foreach ($statCols as $col => $stat) {
                            $row[$col] = isset($byName[$stat]) ? $byName[$stat]['value'] : null;
                            if (isset($byName[$stat])) { $consumed[$stat] = true; }
                        }
                        $voltData[] = $row;
                    }
                    if ($voltData) {
                        $groups[] = ['title' => 'Voltage', 'type' => 'voltage', 'data' => $voltData];
                    }

                    $powerRails = [
                        '12V' => ['Average' => 'average_12v_power', 'Minimum' => 'minimum_12v_power', 'Maximum' => 'maximum_12v_power'],
                        '5V'  => ['Average' => 'average_5v_power',  'Minimum' => 'minimum_5v_power',  'Maximum' => 'maximum_5v_power'],
                    ];
                    $powerData = [];
                    foreach ($powerRails as $label => $statCols) {
                        $row = ['label' => $label];
                        foreach ($statCols as $col => $stat) {
                            $row[$col] = isset($byName[$stat]) ? $byName[$stat]['value'] : null;
                            if (isset($byName[$stat])) { $consumed[$stat] = true; }
                        }
                        $powerData[] = $row;
                    }
                    if (isset($byName['current_motor_power'])) {
                        $powerData[] = ['label' => 'Motor', 'Average' => null, 'Minimum' => null, 'Maximum' => null, 'Current' => $byName['current_motor_power']['value']];
                        $consumed['current_motor_power'] = true;
                    }
                    if ($powerData) {
                        $groups[] = ['title' => 'Power', 'type' => 'power', 'data' => $powerData];
                    }

                } elseif ($pageName === 'FARM Error Statistics') {
                    $flashEvents = [];
                    $cumulHead   = [];
                    foreach ($rows as $r) {
                        $stat = $r['stat_name'] ?? '';
                        if (preg_match('/^flash_led_event_(\d+)\.(.+)$/', $stat, $m)) {
                            $flashEvents[(int) $m[1]][$m[2]] = $r['value'];
                            $consumed[$stat] = true;
                        } elseif (preg_match('/^cum_lifetime_unrecoverable_by_head_(\d+)\.(.+)$/', $stat, $m)) {
                            $cumulHead[(int) $m[1]][$m[2]] = $r['value'];
                            $consumed[$stat] = true;
                        }
                    }
                    if ($flashEvents) {
                        ksort($flashEvents);
                        $extract[] = ['title' => 'Flash LED events', 'type' => 'flash_led', 'source' => $pageName,
                            'data' => ['events' => $flashEvents, 'fields' => array_keys(reset($flashEvents))]];
                    }
                    if ($cumulHead) {
                        ksort($cumulHead);
                        $extract[] = ['title' => 'Cumulative lifetime unrecoverable errors by head', 'type' => 'cum_head', 'source' => $pageName,
                            'data' => ['heads' => $cumulHead, 'fields' => array_keys(reset($cumulHead))]];
                    }

                } elseif ($pageName === 'FARM Reliability Statistics') {
                    $byHead = [];
                    foreach ($rows as $r) {
                        $stat = $r['stat_name'] ?? '';
                        if (preg_match('/^(.+)_by_head_(\d+)$/', $stat, $m) ||
                            preg_match('/^(.+)_from_head_(\d+)$/', $stat, $m)) {
                            $byHead[$m[1]][(int) $m[2]] = $r['value'];
                            $consumed[$stat] = true;
                        }
                    }
                    if ($byHead) {
                        $allHeads = [];
                        foreach ($byHead as $vals) { $allHeads = array_merge($allHeads, array_keys($vals)); }
                        $allHeads = array_values(array_unique($allHeads));
                        sort($allHeads);
                        $extract[] = ['title' => 'By head', 'type' => 'by_head', 'source' => $pageName,
                            'data' => ['metrics' => $byHead, 'heads' => $allHeads]];
                    }

                    // Group attr_<name>_raw / <name>_normalized / <name>_worst triples into
                    // a SMART-attributes-style Name/Normalized/Worst table.
                    $attrCandidates = [];
                    foreach ($rows as $r) {
                        $stat = $r['stat_name'] ?? '';
                        if (preg_match('/^attr_(.+)_raw$/', $stat, $m)) {
                            $attrCandidates[$m[1]]['raw'] = ['stat' => $stat, 'value' => $r['value']];
                        } elseif (preg_match('/^(.+)_normalized$/', $stat, $m)) {
                            $attrCandidates[$m[1]]['normalized'] = ['stat' => $stat, 'value' => $r['value']];
                        } elseif (preg_match('/^(.+)_worst$/', $stat, $m)) {
                            $attrCandidates[$m[1]]['worst'] = ['stat' => $stat, 'value' => $r['value']];
                        }
                    }
                    $attrRows = [];
                    foreach ($attrCandidates as $key => $fields) {
                        if (isset($fields['normalized']) || isset($fields['worst'])) {
                            // FARM's bare "error_rate" stat is the Seagate Read Error Rate attribute.
                            $labelKey = $key === 'error_rate' ? 'read_error_rate' : $key;
                            $attrRows[$labelKey] = [
                                'normalized' => $fields['normalized']['value'] ?? null,
                                'worst'      => $fields['worst']['value'] ?? null,
                                'raw'        => $fields['raw']['value'] ?? null,
                            ];
                            foreach ($fields as $f) { $consumed[$f['stat']] = true; }
                        }
                    }
                    if ($attrRows) {
                        $groups[] = ['title' => '', 'type' => 'attr_table', 'data' => $attrRows];
                    }

                } elseif ($pageName === 'FARM Workload Statistics') {
                    $radRows = [];
                    foreach ($rows as $r) {
                        $stat = $r['stat_name'] ?? '';
                        if (preg_match('/^(read|write)_commands_by_radius_(\d+)_(\d+)$/', $stat, $m)) {
                            $range = $m[2] . '-' . $m[3] . '%';
                            $radRows[$range][$m[1]] = $r['value'];
                            $consumed[$stat] = true;
                        }
                    }
                    if ($radRows) {
                        $groups[] = ['title' => 'Commands by disk radius', 'type' => 'by_radius',
                            'data' => $radRows];
                    }
                }

                foreach ($rows as $r) {
                    if (! isset($consumed[$r['stat_name'] ?? ''])) {
                        $scalars[] = $r;
                    }
                }
                return ['scalars' => $scalars, 'groups' => $groups, 'extract' => $extract];
            };

            $renderSubTable = static function (array $group, bool $skipTitle = false, bool $fullWidth = false) use ($fmtStatVal, $fmtStatName, $fmtFarmStatLabel, $fmtMilli, $labelWithTooltip): void {
                $type  = $group['type'];
                $data  = $group['data'];
                $title = htmlspecialchars($group['title']);
                if (! $skipTitle && $title !== '') {
                    echo '<h5 style="margin:14px 0 6px;font-size:14px;font-weight:600">' . $title . '</h5>';
                }

                $tblStyle = ($fullWidth ? 'width:100%' : 'width:auto') . ';font-size:12px';

                if ($type === 'temp_matrix') {
                    $horizons = ['instant' => 'Instant', 'short' => 'Short-term avg', 'long' => 'Long-term avg'];
                    $cols     = ['current' => 'Current', 'average' => 'Average', 'highest' => 'Highest', 'lowest' => 'Lowest'];
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th></th>';
                    foreach ($cols as $col => $colLabel) { echo '<th>' . $colLabel . '</th>'; }
                    echo '</tr></thead><tbody>';
                    foreach ($horizons as $rowKey => $rowLabel) {
                        if (! isset($data[$rowKey])) { continue; }
                        $tooltip = match ($rowKey) {
                            'instant' => 'Current device temperature at read time.',
                            'short' => 'Average of the most recent 144 ten-minute samples over a 24-hour period.',
                            'long' => 'Average of the most recent 42 short-term daily averages; valid after about 1008 hours.',
                            default => '',
                        };
                        echo '<tr><td><strong>' . $labelWithTooltip($rowLabel, $tooltip) . '</strong></td>';
                        foreach ($cols as $col => $_) {
                            $v = $data[$rowKey][$col] ?? null;
                            echo '<td>' . ($v !== null ? $fmtStatVal($v) : '<span class="text-muted">-</span>') . '</td>';
                        }
                        echo '</tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'limits') {
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th></th><th>Limit (°C)</th><th>Time over (min)</th></tr></thead><tbody>';
                    foreach ($data as $row) {
                        $tooltipLabel = $row['label'] === 'Maximum' ? 'Specified maximum operating temperature' : 'Specified minimum operating temperature';
                        echo '<tr><td><strong>' . $labelWithTooltip($row['label'], $tooltipLabel === 'Specified maximum operating temperature'
                            ? 'Manufacturer-specified maximum operating temperature for the device.'
                            : 'Manufacturer-specified minimum operating temperature for the device.') . '</strong></td>'
                            . '<td>' . $fmtStatVal($row['limit']) . '</td>'
                            . '<td>' . $fmtStatVal($row['time']) . '</td></tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'voltage') {
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Rail</th><th>Current</th><th>Minimum</th><th>Maximum</th></tr></thead><tbody>';
                    foreach ($data as $row) {
                        $tooltip = str_starts_with($row['label'], '12V')
                            ? 'Voltage readings for the 12V power line: current, minimum observed, and maximum observed.'
                            : 'Voltage readings for the 5V power line: current, minimum observed, and maximum observed.';
                        echo '<tr><td><strong>' . $labelWithTooltip($row['label'], $tooltip) . '</strong></td>'
                            . '<td>' . $fmtMilli($row['Current'], 'V') . '</td>'
                            . '<td>' . $fmtMilli($row['Minimum'], 'V') . '</td>'
                            . '<td>' . $fmtMilli($row['Maximum'], 'V') . '</td></tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'power') {
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Rail</th><th>Current</th><th>Average</th><th>Minimum</th><th>Maximum</th></tr></thead><tbody>';
                    foreach ($data as $row) {
                        $tooltip = match ($row['label']) {
                            '12V' => 'Power readings in watts for the 12V power line: average, minimum, and maximum.',
                            '5V' => 'Power readings in watts for the 5V power line: average, minimum, and maximum.',
                            'Motor' => 'Current motor power scalar value used by the servo to keep the motor spinning.',
                            default => '',
                        };
                        echo '<tr><td><strong>' . $labelWithTooltip($row['label'], $tooltip) . '</strong></td>'
                            . '<td>' . $fmtMilli($row['Current'] ?? null, 'W') . '</td>'
                            . '<td>' . $fmtMilli($row['Average'] ?? null, 'W') . '</td>'
                            . '<td>' . $fmtMilli($row['Minimum'] ?? null, 'W') . '</td>'
                            . '<td>' . $fmtMilli($row['Maximum'] ?? null, 'W') . '</td></tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'flash_led') {
                    $events = $data['events'];
                    $fields = $data['fields'];
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Field</th>';
                    foreach (array_keys($events) as $ev) { echo '<th>Event ' . $ev . '</th>'; }
                    echo '</tr></thead><tbody>';
                    foreach ($fields as $field) {
                        echo '<tr><td>' . $fmtFarmStatLabel($field) . '</td>';
                        foreach ($events as $ev => $_) {
                            echo '<td>' . $fmtStatVal($events[$ev][$field] ?? null) . '</td>';
                        }
                        echo '</tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'cum_head') {
                    $heads  = $data['heads'];
                    $fields = $data['fields'];
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th></th>';
                    foreach (array_keys($heads) as $h) { echo '<th>H' . $h . '</th>'; }
                    echo '</tr></thead><tbody>';
                    foreach ($fields as $f) {
                        echo '<tr><td>' . $fmtFarmStatLabel($f) . '</td>';
                        foreach ($heads as $h => $vals) { echo '<td>' . $fmtStatVal($vals[$f] ?? null) . '</td>'; }
                        echo '</tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'by_head') {
                    $metrics = $data['metrics'];
                    $heads   = $data['heads'];
                    $avgMetrics = ['write_workload_power_on_time'];
                    echo '<table class="table table-condensed table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Metric</th>';
                    foreach ($heads as $h) { echo '<th style="text-align:right">H' . $h . '</th>'; }
                    echo '<th style="text-align:right">Total / Avg</th></tr></thead><tbody>';
                    foreach ($metrics as $metric => $headVals) {
                        $numVals = array_filter(
                            array_map(static fn ($h) => $headVals[$h] ?? null, $heads),
                            static fn ($v) => is_numeric($v)
                        );
                        $rowMax   = $numVals ? max($numVals) : 0;
                        $rowMin   = $numVals ? min($numVals) : 0;
                        $rowRange = $rowMax - $rowMin;
                        $isAvg    = in_array($metric, $avgMetrics, true);
                        $aggregate = $numVals
                            ? ($isAvg
                                ? array_sum($numVals) / count($numVals)
                                : array_sum($numVals))
                            : null;
                        echo '<tr><td>' . $fmtFarmStatLabel($metric) . '</td>';
                        foreach ($heads as $h) {
                            $v   = $headVals[$h] ?? null;
                            $pct = ($rowRange > 0 && is_numeric($v))
                                ? round(($v - $rowMin) / $rowRange * 100)
                                : 0;
                            $bg  = ($rowMax > 0 && $pct > 0)
                                ? ' style="text-align:right;background:linear-gradient(to top,rgba(70,130,180,0.22) ' . $pct . '%,transparent ' . $pct . '%)"'
                                : ' style="text-align:right"';
                            echo '<td' . $bg . '>' . $fmtStatVal($v) . '</td>';
                        }
                        $aggDisplay = $aggregate !== null ? $fmtStatVal(round($aggregate)) : '';
                        echo '<td style="text-align:right;font-weight:600">' . $aggDisplay . ($isAvg ? ' <small class="text-muted">avg</small>' : '') . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'attr_table') {
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Name</th><th>Normalized</th><th>Worst</th><th>Raw</th></tr></thead><tbody>';
                    foreach ($data as $key => $vals) {
                        echo '<tr><td>' . $fmtFarmStatLabel($key) . '</td>'
                            . '<td>' . $fmtStatVal($vals['normalized'] ?? null) . '</td>'
                            . '<td>' . $fmtStatVal($vals['worst'] ?? null) . '</td>'
                            . '<td>' . $fmtStatVal($vals['raw'] ?? null) . '</td></tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'by_radius') {
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Radius</th><th>Read</th><th>Write</th></tr></thead><tbody>';
                    foreach ($data as $range => $vals) {
                        echo '<tr><td>' . $labelWithTooltip((string) $range, 'Read and write command counts grouped by their approximate disk-radius location.') . '</td>'
                            . '<td>' . $fmtStatVal($vals['read'] ?? null) . '</td>'
                            . '<td>' . $fmtStatVal($vals['write'] ?? null) . '</td></tr>';
                    }
                    echo '</tbody></table>';
                }
            };

            $isFarmPage   = static fn (string $pn): bool => str_starts_with($pn, 'FARM ');
            $skipRows = \LibreNMS\Agent\Unix\Smart\HtmlData::DEV_STAT_SKIP_ROWS;

            $devStatPanelPages = [];
            foreach ($disk['dev_stats'] as $page) {
                $pn = $page['page_name'] ?: $data->decode('dev_stat_page', $page['page_num']);
                if (in_array($pn, \LibreNMS\Agent\Unix\Smart\HtmlData::DEV_STAT_SKIP_PAGES, true)) { continue; }
                if (! in_array($pn, $devStatKnownPanels, true)) { continue; }
                $isFarm = $isFarmPage($pn);
                $rows = array_filter(
                    $page['rows'],
                    static fn ($r) => ($isFarm || ($r['valid'] ?? 1) != 0)
                        && ! in_array((string) ($r['stat_name'] ?? ''), $skipRows, true)
                );
                if (! $rows) { continue; }
                $devStatPanelPages[] = ['page_name' => $pn, 'rows' => array_values($rows)];
            }
        @endphp
        @if(! empty($devStatPanelPages))
        @php $devStatExtractPanels = []; @endphp
        <div class="smart-panels">
            @foreach($devStatPanelPages as $devPage)
            <div>
                @php
                    $pageName = $devPage['page_name'];
                    $panelStart(htmlspecialchars($pageName));
                    if (str_starts_with($pageName, 'FARM ')) {
                        echo '<p style="font-size:11px;margin:0 0 8px">'
                            . '<a href="https://github.com/Seagate/openSeaChest/wiki/Drive-Health-and-SMART" target="_blank" rel="noopener">Seagate FARM reference</a>'
                            . '</p>';
                    }
                    $sub = $farmSubTables($pageName, $devPage['rows']);

                    if ($sub['scalars']) {
                        echo '<table class="table table-condensed table-striped table-hover" style="width:auto">';
                        echo '<thead><tr><th>Statistic</th><th>Value</th></tr></thead><tbody>';
                        foreach ($sub['scalars'] as $r) {
                            $statLabel = str_starts_with($pageName, 'FARM ')
                                ? $fmtFarmStatLabel((string) ($r['stat_name'] ?? ''))
                                : $fmtStatLabel((string) ($r['stat_name'] ?? ''));
                            echo '<tr><td>' . $statLabel . '</td>'
                                . '<td>' . $fmtStatVal($r['value'] ?? null, (string) ($r['stat_name'] ?? '')) . '</td></tr>';
                        }
                        echo '</tbody></table>';
                    }
                    foreach ($sub['groups'] as $group) {
                        $renderSubTable($group);
                    }
                    foreach ($sub['extract'] as $ep) {
                        $devStatExtractPanels[] = $ep;
                    }
                    $panelEnd();
                @endphp
            </div>
            @endforeach
            @php
                // Merge by_head + cum_head into one panel
                $byHeadIdx  = null;
                $cumHeadIdx = null;
                foreach ($devStatExtractPanels as $i => $ep) {
                    if ($ep['type'] === 'by_head')  { $byHeadIdx  = $i; }
                    if ($ep['type'] === 'cum_head') { $cumHeadIdx = $i; }
                }
                if ($byHeadIdx !== null && $cumHeadIdx !== null) {
                    $cumEp = $devStatExtractPanels[$cumHeadIdx];
                    $cumMetrics = [];
                    foreach ($cumEp['data']['fields'] as $f) {
                        foreach ($cumEp['data']['heads'] as $h => $vals) {
                            $cumMetrics[$f][$h] = $vals[$f] ?? null;
                        }
                    }
                    $devStatExtractPanels[$byHeadIdx]['data']['metrics'] = array_merge(
                        $devStatExtractPanels[$byHeadIdx]['data']['metrics'],
                        $cumMetrics
                    );
                    $devStatExtractPanels[$byHeadIdx]['source'] =
                        $devStatExtractPanels[$byHeadIdx]['source'] . ' &amp; ' . htmlspecialchars($cumEp['source']);
                    $devStatExtractPanels[$byHeadIdx]['title'] = 'Per-head statistics';
                    unset($devStatExtractPanels[$cumHeadIdx]);
                }
            @endphp
            @foreach($devStatExtractPanels as $ep)
            <div style="flex: 0 0 100%; width: 100%">
                @php
                    $panelStart(htmlspecialchars($ep['title']));
                    echo '<p style="font-size:11px;margin:0 0 8px">'
                        . 'Data from <em>' . $ep['source'] . '</em>'
                        . ' &mdash; <a href="https://github.com/Seagate/openSeaChest/wiki/Drive-Health-and-SMART" target="_blank" rel="noopener">Seagate FARM reference</a>'
                        . '</p>';
                    $renderSubTable($ep, true, true);
                    $panelEnd();
                @endphp
            </div>
            @endforeach
        </div>
        @endif

        {{-- Pending Defects --}}
        @if(! empty($disk['pending_defects']))
            @php
                $panelStart('Pending Defects', (string) count($disk['pending_defects']));
                echo '<table class="table table-condensed table-hover" style="width:auto">';
                echo '<thead><tr><th>#</th><th>LBA</th></tr></thead><tbody>';
                foreach ($disk['pending_defects'] as $pd) {
                    echo '<tr><td>' . htmlspecialchars((string) ($pd['entry_num'] ?? ''))
                        . '</td><td>' . htmlspecialchars((string) ($pd['lba'] ?? '')) . '</td></tr>';
                }
                echo '</tbody></table>';
                $panelEnd();
            @endphp
        @endif

        @if($showDetailed)
        {{-- Last row: SATA PHY Event Counters, Error Recovery Control, Capabilities, Log Directory --}}
        @php
            $capGroups = [
                'Self-test' => [
                    'capability_selftests_supported'  => 'Self-tests supported',
                    'capability_conveyance_supported' => 'Conveyance self-test',
                    'capability_selective_supported'  => 'Selective self-test',
                ],
                'Offline Data Collection' => [
                    'capability_exec_offline_immediate' => 'Exec offline immediate',
                    'capability_offline_aborted_on_cmd' => 'Offline aborted on command',
                    'capability_offline_surface_scan'   => 'Offline surface scan',
                ],
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
            $capRows = array_filter($capFields, fn ($col) => isset($info[$col]), ARRAY_FILTER_USE_KEY);

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

            // One sub-table per group; kept whole (not split) within the CSS column layout below.
            $capSection = static function (string $heading, string $rows): string {
                if ($rows === '') {
                    return '';
                }

                return '<div style="break-inside:avoid-column;-webkit-column-break-inside:avoid;margin-bottom:14px">'
                    . '<div style="font-weight:bold;border-bottom:1px solid #ddd;margin-bottom:4px;padding-bottom:2px">' . htmlspecialchars($heading) . '</div>'
                    . '<table class="table table-condensed table-hover" style="width:auto;margin-bottom:0">' . $rows . '</table>'
                    . '</div>';
            };
        @endphp
        @if(! empty($disk['phy_events']) || ! empty($disk['erc']) || $capRows !== [] || $featureRows !== [] || ! empty($disk['log_dir']))
        <div class="smart-panels">
            @if(! empty($disk['erc']))
            <div>
                @php
                    $panelStart('Error Recovery Control (SCT ERC)');
                    echo '<table class="table table-condensed table-hover" style="width:auto">';
                    foreach ($disk['erc'] as $direction => $row) {
                        $label = $data->decode('erc_direction', $direction);
                        $ds = $row['deciseconds'] ?? null;
                        $val = ($row['enabled'] ?? 0)
                            ? (is_numeric($ds) ? number_format($ds / 10, 1) . ' s' : 'Enabled')
                            : 'Disabled';
                        echo $tableRow($label, htmlspecialchars($val), $tooltipForLabel($label));
                    }
                    echo '</table>';
                    $panelEnd();
                @endphp
            </div>
            @endif

            @if($capRows !== [] || $featureRows !== [])
            <div>
                @php
                    $panelStart('Capabilities');
                    $sectionsHtml = '';
                    if ($featureRows !== []) {
                        $rows = '';
                        foreach ($featureRows as $label => $value) {
                            $rows .= $tableRow($label, htmlspecialchars((string) $value), $tooltipForLabel($label));
                        }
                        $sectionsHtml .= $capSection('Drive Features', $rows);
                    }
                    foreach ($capGroups as $heading => $cols) {
                        $rows = '';
                        foreach ($cols as $col => $label) {
                            if (! isset($info[$col])) {
                                continue;
                            }
                            $val = (int) $info[$col];
                            $icon = $val ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>';
                            $rows .= $tableRow($label, $icon, $tooltipForLabel($label));
                        }
                        $sectionsHtml .= $capSection($heading, $rows);
                    }
                    echo '<div style="column-width:auto;column-count:3;column-gap:18px">' . $sectionsHtml . '</div>';
                    $panelEnd();
                @endphp
            </div>
            @endif

            @if(! empty($disk['log_dir']))
            <div>
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
            </div>
            @endif

            @if(! empty($disk['phy_events']))
            <div>
                @php
                    $panelStart('SATA PHY Event Counters');
                    echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover" style="width:auto">';
                    echo '<thead><tr><th>ID</th><th>Name</th><th>Value</th></tr></thead><tbody>';
                    foreach ($disk['phy_events'] as $ev) {
                        $val = (string) ($ev['value'] ?? '');
                        if (($ev['overflow'] ?? 0)) { $val .= ' <span class="text-warning">(overflow)</span>'; }
                        echo '<tr><td>' . htmlspecialchars((string) ($ev['event_id'] ?? '')) . '</td>'
                            . '<td>' . htmlspecialchars((string) ($ev['name'] ?? '')) . '</td>'
                            . '<td>' . $val . '</td></tr>';
                    }
                    echo '</tbody></table></div>';
                    $panelEnd();
                @endphp
            </div>
            @endif
        </div>
        @endif
        @endif
    @endif
    @endif

    {{-- Graphs --}}
    @php
        $now      = \App\Facades\LibrenmsConfig::get('time.now');
        $appId    = $data->app->app_id;
        $anchorPrefix = 'smart-device-' . $idx . '-graph-';
        $tempSensor   = $data->temperatureSensor($selectedDisk);
        $healthSensor = $data->healthSensor($selectedDisk);
        $specs        = $data->attributeGraphSpecs($selectedDisk);
        $hasBig5      = $data->hasBig5Rrd($selectedDisk);
        $hasOther     = $data->hasOtherRrd($selectedDisk);
        $graphBase    = \LibreNMS\Util\Url::generate($linkArray + ['disk' => (string) $selectedDisk]);

        $diskSensors  = $data->diskSensors($selectedDisk);
        $wearSensor   = $diskSensors[$idx . '_wear'] ?? null;
        $statusSensor = $data->selftestStatusSensor($selectedDisk);
        $shortSensor  = $diskSensors[$idx . '_selftest_short'] ?? null;
        $longSensor   = $diskSensors[$idx . '_selftest_long'] ?? null;
        $hasSelftest  = $shortSensor !== null || $longSensor !== null;

        // Power-on hours is ATA attribute 9; it rides the single per-disk attribute RRD.
        $powerSpec = $specs[9] ?? null;

        // Build jump-nav section list.
        $sections = [];
        if ($tempSensor)   { $sections[] = [$anchorPrefix . 'temperature', 'Temperature']; }
        if ($healthSensor) { $sections[] = [$anchorPrefix . 'health', 'Health']; }
        if ($wearSensor)   { $sections[] = [$anchorPrefix . 'wear', 'Wear Remaining']; }
        if ($statusSensor) { $sections[] = [$anchorPrefix . 'selftest-status', 'Self-test Status']; }
        if ($hasSelftest)  { $sections[] = [$anchorPrefix . 'selftest', 'Self-test Age']; }
        if ($powerSpec)    { $sections[] = [$anchorPrefix . 'power', 'Power-on Hours']; }
        if ($hasBig5)  { $sections[] = [$anchorPrefix . 'big5', 'Reliability / Age (Big 5 ATA Attributes)']; }
        if ($hasOther) { $sections[] = [$anchorPrefix . 'other', 'Other']; }
        foreach ($specs as $spec) {
            if ($spec['id'] === 9) { continue; }
            $sections[] = [$anchorPrefix . 'attr-' . $spec['id'], $spec['title']];
        }

        $jumpItems = '';
        foreach ($sections as [$sid, $stitle]) {
            $jumpItems .= '<div style="break-inside:avoid-column;-webkit-column-break-inside:avoid;padding:1px 0">'
                . '<a href="' . htmlspecialchars($graphBase . '#' . $sid, ENT_QUOTES) . '">' . htmlspecialchars($stitle) . '</a></div>';
        }
        if ($jumpItems !== '') {
            echo '<div class="panel panel-default"><div class="panel-body" style="padding:10px 15px">'
                . '<strong>Jump to graph:</strong><div style="column-width:260px;column-gap:18px;margin-top:6px">'
                . $jumpItems . '</div></div></div>';
        }

        $anchor = static function (string $id): void {
            echo '<a id="' . htmlspecialchars($id) . '" style="position:relative;top:-70px;display:block;visibility:hidden"></a>';
        };

        $appGraph = static function (string $type, string $title, string $anchorId, string $headerBadge = '', array $extra = []) use ($appId, $idx, $now, $panelStart, $panelEnd, $anchor) {
            $graph_array = array_merge([
                'height' => '100', 'width' => '215', 'to' => $now,
                'id'     => $appId, 'type' => "application_{$type}",
                'disk'   => $idx, 'scale_min' => '0',
            ], $extra);
            $badge = $headerBadge !== '' ? '<span class="text-muted">' . htmlspecialchars($headerBadge) . '</span>' : '';
            $anchor($anchorId);
            $panelStart(htmlspecialchars($title), $badge);
            echo '<div class="row">';
            include 'includes/html/print-graphrow.inc.php';
            echo '</div>';
            $panelEnd();
        };

        $sensorGraph = static function ($sensor, string $title, string $anchorId, string $badge = '') use ($now, $panelStart, $panelEnd, $anchor) {
            $graph_array = [
                'height' => '100', 'width' => '215', 'to' => $now,
                'id'     => $sensor->sensor_id, 'type' => 'sensor_' . $sensor->sensor_class, 'legend' => 'no',
            ];
            $anchor($anchorId);
            $panelStart(htmlspecialchars($title), $badge);
            echo '<div class="row">';
            include 'includes/html/print-graphrow.inc.php';
            echo '</div>';
            $panelEnd();
        };

        if ($tempSensor) {
            $sensorGraph($tempSensor, 'Temperature', $anchorPrefix . 'temperature');
        }
        if ($healthSensor) {
            $sensorGraph($healthSensor, 'Health', $anchorPrefix . 'health', $healthBadge);
        }
        if ($wearSensor) {
            $sensorGraph($wearSensor, 'Wear Remaining', $anchorPrefix . 'wear');
        }
        if ($statusSensor) {
            $sensorGraph($statusSensor, 'Self-test Status', $anchorPrefix . 'selftest-status');
        }
        if ($hasSelftest) {
            $stParts = [];
            if ($shortSensor) { $stParts[] = 'Short: ' . (string) ($shortSensor->sensor_current ?? '-'); }
            if ($longSensor)  { $stParts[] = 'Long: '  . (string) ($longSensor->sensor_current ?? '-'); }
            $appGraph('smart_v2_selftest', 'Self-test Age', $anchorPrefix . 'selftest', $stParts !== [] ? implode(' | ', $stParts) : '');
        }
        if ($powerSpec) {
            $appGraph('smart_v2_attributes', 'Power-on Hours', $anchorPrefix . 'power', $data->powerHeader($disk), [
                'attr_id'     => '9',
                'attr_thresh' => $powerSpec['thresh'] !== null ? (string) $powerSpec['thresh'] : '',
                'has_raw'     => $powerSpec['has_raw'] ? '1' : '0',
                'has_norm'    => $powerSpec['has_norm'] ? '1' : '0',
            ]);
        }
        if ($hasBig5) {
            $appGraph('smart_v2_big5', 'Reliability / Age (Big 5 ATA Attributes)', $anchorPrefix . 'big5', $data->reliabilityHeader($disk));
        }
        if ($hasOther) {
            $appGraph('smart_v2_other', 'Other', $anchorPrefix . 'other');
        }

        // Per-attribute graphs with a "Scale from zero" toggle (id 9 is shown above as Power-on Hours).
        $attrSpecs = array_filter($specs, static fn ($spec) => $spec['id'] !== 9);
        if ($attrSpecs !== []) {
            $wrapperId = 'smart-attr-graphs-' . htmlspecialchars($idx);
            $toggleId  = 'smart-attr-scale-' . htmlspecialchars($idx);
            echo '<script>
function smartAttrScaleToggle(cb, wrapperId) {
    var w = document.getElementById(wrapperId); if (!w) return;
    w.querySelectorAll("img.graph-image").forEach(function(img) {
        if (cb.checked) {
            if (img.src.indexOf("scale_min=") === -1) { img.src += (img.src.indexOf("?") !== -1 ? "&" : "?") + "scale_min=0"; }
        } else { img.src = img.src.replace(/[&?]scale_min=[^&]*/g, ""); }
    });
}
</script>';
            echo '<h4 style="margin:20px 0 8px;border-bottom:1px solid #ddd;padding-bottom:6px">Attributes'
                . '<label style="float:right;font-size:13px;font-weight:normal;margin-bottom:0;cursor:pointer">'
                . '<input type="checkbox" id="' . $toggleId . '" checked onchange="smartAttrScaleToggle(this,\'' . $wrapperId . '\')"> Scale from zero</label></h4>';
            echo '<div id="' . $wrapperId . '">';
            foreach ($attrSpecs as $spec) {
                $appGraph('smart_v2_attributes', $spec['title'], $anchorPrefix . 'attr-' . $spec['id'], $spec['header'], [
                    'attr_id'     => (string) $spec['id'],
                    'attr_thresh' => $spec['thresh'] !== null ? (string) $spec['thresh'] : '',
                    'has_raw'     => $spec['has_raw'] ? '1' : '0',
                    'has_norm'    => $spec['has_norm'] ? '1' : '0',
                ]);
            }
            echo '</div>';
        }
    @endphp
