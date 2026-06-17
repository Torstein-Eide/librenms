{{-- SATA/SAS per-disk "Overview" view — same data as smart-sata-detail, laid out --}}
{{-- like the device Overview tab (includes/html/pages/device/overview.inc.php): a --}}
{{-- responsive two-column grid of stacked panels, with full-width tables below. --}}
{{-- Inherits closures ($panelStart, $panelEnd, $tableRow, $tooltipForLabel, --}}
{{-- $labelWithTooltip, $formatHoursAgo, $stateBadge, $tempBadge, $wearBadge, --}}
{{-- $percentBadge, $selftestBadge) and $data, $device, $selectedDisk, $linkArray --}}
{{-- from the parent smart.blade.php. --}}
@php
    $disk    = $data->disk($selectedDisk);
    $idx     = $disk['idx'];
    $info    = $disk['info'];
    $health  = $disk['health'];

    $powerOnHours = $data->powerOnHours($disk);
    $healthSensor = $data->healthSensor($selectedDisk);
    $healthBadge  = $stateBadge($healthSensor, 'SMART overall-health self-assessment test result.');
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

            $capBytes = $info['user_capacity_bytes'] ?? null;
            $capCell  = is_numeric($capBytes)
                ? \LibreNMS\Util\Number::formatBi((int) $capBytes) . ' (' . \LibreNMS\Util\Number::formatSi((int) $capBytes, 0, 0, 'B') . ')'
                : null;

            $rows = [
                'Model Family'    => $disk['model_family']     ?? null,
                'Model'           => $disk['model_name']       ?? null,
                'Serial'          => $disk['serial_number']    ?? null,
                'Firmware'        => $disk['firmware_version'] ?? null,
                'WWN'             => $disk['wwn']              ?? null,
                'Device'          => $disk['device_name']     ?? null,
                'Path'            => $pathCell,
                'Capacity'        => $capCell,
            ];

            // Header: drive icon + "Model Serial (device)" with the health badge pulled right.
            $rotRate    = $info['rotation_rate'] ?? null;
            $isSsd      = is_numeric($rotRate) && (int) $rotRate === 0;
            $identityHd = trim((string) ($disk['model_name'] ?? '') . ' ' . (string) ($disk['serial_number'] ?? ''));
            $identityHd = $identityHd !== '' ? $identityHd : $data->deviceLabel($disk);
            $identityTitle = '<i class="fa ' . ($isSsd ? 'fa-microchip' : 'fa-hdd-o') . '" style="margin-right:6px"></i>'
                . htmlspecialchars($identityHd) . ' (' . htmlspecialchars($data->deviceLabel($disk)) . ')';

            $panelStart($identityTitle, $healthBadge);
            echo '<table class="table table-condensed table-hover" style="width:100%">';
            foreach ($rows as $label => $value) {
                if ($value === null || $value === '') { continue; }
                $cell = $value instanceof \Illuminate\Support\HtmlString
                    ? (string) $value : htmlspecialchars((string) $value);
                echo $tableRow($label, $cell, $tooltipForLabel($label));
            }
            echo '</table>';
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

        {{-- Health (all disk sensors + offline collection + attribute summary) --}}
        @php
            $sensorIcon = static fn (string $class): string => match ($class) {
                'state'       => 'fa-heartbeat',
                'temperature' => 'fa-thermometer-half',
                'percent'     => 'fa-tachometer',
                default       => 'fa-line-chart',
            };
            $sensorBadge = static function ($s) use ($stateBadge, $tempBadge, $percentBadge): string {
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

            // All disk sensors, status (state) sensors first.
            $healthSensors = $data->diskSensors($selectedDisk);
            uasort($healthSensors, static fn ($a, $b) => (($a->sensor_class === 'state') ? 0 : 1) <=> (($b->sensor_class === 'state') ? 0 : 1));

            $panelStart('<i class="fa fa-heartbeat" style="margin-right:6px"></i>Health', $healthBadge);
            echo '<table class="table table-condensed table-hover" style="width:100%">';
            foreach ($healthSensors as $s) {
                echo '<tr>'
                    . '<td style="white-space:nowrap"><i class="fa ' . $sensorIcon($s->sensor_class) . ' text-muted" style="margin-right:6px"></i>' . htmlspecialchars($data->shortSensorName($s, $disk)) . '</td>'
                    . '<td style="width:110px">' . $sensorMini($s) . '</td>'
                    . '<td style="text-align:right">' . $sensorBadge($s) . '</td>'
                    . '</tr>';
            }

            // Power On / Power Cycles summary rows.
            if ($powerOnHours !== null) {
                echo '<tr><td style="white-space:nowrap"><i class="fa fa-power-off text-muted" style="margin-right:6px"></i>'
                    . $labelWithTooltip('Power On', $tooltipForLabel('Power On Hours')) . '</td>'
                    . '<td colspan="2" style="text-align:right">' . htmlspecialchars(number_format($powerOnHours, 0, '.', ' ')) . ' h</td></tr>';
            }
            if (isset($health['power_cycles']) && is_numeric($health['power_cycles'])) {
                echo '<tr><td style="white-space:nowrap"><i class="fa fa-refresh text-muted" style="margin-right:6px"></i>'
                    . $labelWithTooltip('Power Cycles', $tooltipForLabel('Power Cycles')) . '</td>'
                    . '<td colspan="2" style="text-align:right">' . htmlspecialchars(number_format((int) $health['power_cycles'], 0, '.', ' ')) . '</td></tr>';
            }

            // Offline Data Collection summary row.
            $odcStatus = $health['offline_collection_status'] ?? null;
            if ($odcStatus !== null && is_numeric($odcStatus)) {
                $odcAuto = ((int) $odcStatus & 0x80) !== 0;
                $odcVal  = ($odcAuto ? '<span class="text-success">Enabled</span>' : '<span class="text-muted">Disabled</span>')
                    . ' &mdash; ' . htmlspecialchars($data->decode('offline_status', (int) $odcStatus & 0x7f));
                echo '<tr><td style="white-space:nowrap"><i class="fa fa-clock-o text-muted" style="margin-right:6px"></i>'
                    . $labelWithTooltip('Offline Data Collection', $tooltipForLabel('Auto Offline Data Collection')) . '</td>'
                    . '<td colspan="2" style="text-align:right">' . $odcVal . '</td></tr>';
            }

            // SMART Attributes overall (NA-status attributes excluded).
            if (! empty($disk['attributes'])) {
                $attrCount = 0; $attrBad = 0;
                foreach ($disk['attributes'] as $a) {
                    $st = (int) ($a['status'] ?? 0);
                    if ($st === -1) { continue; }
                    $attrCount++;
                    if ($st === 2 || $st === 3) { $attrBad++; }
                }
                $attrVal = '<span class="label label-default">' . $attrCount . '</span> / '
                    . '<span class="label label-' . ($attrBad > 0 ? 'danger' : 'default') . '">' . $attrBad . '</span> / '
                    . '<span class="label label-success">' . ($attrCount - $attrBad) . '</span>';
                echo '<tr><td style="white-space:nowrap"><i class="fa fa-list text-muted" style="margin-right:6px"></i>'
                    . '<abbr style="cursor:help;text-decoration:underline dotted" title="Total / Failing / Healthy (attributes with NA status excluded)">SMART Attributes Overall</abbr></td>'
                    . '<td colspan="2" style="text-align:right">' . $attrVal . '</td></tr>';
            }

            echo '</table>';
            $panelEnd();
        @endphp

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

        {{-- SATA PHY Event Counters --}}
        @if(! empty($disk['phy_events']))
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
    </div>
</div>

{{-- ====================== Full-width: SMART Attributes ====================== --}}
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
        // Click → graphs page, hover → day/week/month/year popup (as on device overview).
        $mini = '';
        if ($attrId > 0) {
            $mini = \LibreNMS\Util\Url::graphPopup([
                'id'          => $attrAppId,
                'type'        => 'application_smart_v2_attributes',
                'disk'        => $idx,
                'attr_id'     => $attrId,
                'has_raw'     => 1,
                'has_norm'    => 1,
                'from'        => $attrFrom,
                'to'          => $attrNow,
                'width'       => 60,
                'height'      => 15,
                'legend'      => 'no',
                'popup_title' => htmlspecialchars($device['hostname'] . ' - ' . $name),
            ]);
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

{{-- ====================== Full-width: SMART Error Log ====================== --}}
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

{{-- ============== Full-width: Device Statistics (1:1 with the Detailed view) ============== --}}
<style>
    .smart-panels { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-start; margin-bottom:15px }
    .smart-panels .panel { flex:0 0 auto; margin-bottom:0 }
    .smart-panels table { white-space:nowrap }
</style>
@php
    // FARM header pages live on the dedicated Metadata view instead.
    $metadataPages = ['FARM Drive Information', 'FARM Log Header'];
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
@endphp
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
                if (in_array($pn, \LibreNMS\Agent\Unix\Smart\HtmlData::DEV_STAT_SKIP_PAGES, true) || in_array($pn, $metadataPages, true)) { continue; }
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
