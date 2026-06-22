{{-- SATA/SAS per-disk "Basic" view: identity, health sensors, pending defects, --}}
{{-- and SMART attributes. SCT ERC lives on the Metadata view; device statistics  --}}
{{-- and PHY event counters live on the Statistics view.                           --}}
{{-- Inherits closures ($panelStart, $panelEnd, $tableRow, $tooltipForLabel, --}}
{{-- $labelWithTooltip, $formatHoursAgo, $stateBadge, $tempBadge, $wearBadge, --}}
{{-- $percentBadge, $selftestBadge) and $data, $device, $selectedDisk, $smartUrl, --}}
{{-- $currentUrl, $viewCookie from the parent smart.blade.php. --}}
@php
    $disk    = $data->disk($selectedDisk);
    $idx     = $disk['idx'];
    $info    = $disk['info'];
    $health  = $disk['health'];

    $powerOnHours = $data->powerOnHours($disk);
    $healthSensor = $data->healthSensor($selectedDisk);
    $healthBadge  = $stateBadge($healthSensor, 'SMART overall-health self-assessment test result.');

    // Navigate to another disk view mode: sets the cookie the sub-nav reads, then the
    // href (the current URL) reloads the page with that mode picked up.
    $gotoModeAttr = static function (string $mode) use ($viewCookie): string {
        return 'document.cookie=\'' . htmlspecialchars($viewCookie, ENT_QUOTES) . '=' . $mode
            . '; path=/; max-age=31536000; samesite=lax\';';
    };
    $gotoRowOpen = static function (string $mode) use ($gotoModeAttr, $currentUrl): string {
        return '<tr style="cursor:pointer" onclick="' . $gotoModeAttr($mode) . 'window.location=\''
            . htmlspecialchars($currentUrl, ENT_QUOTES) . '\';">';
    };
    $gotoHeaderLink = static function (string $mode, string $inner) use ($gotoModeAttr, $currentUrl): string {
        return '<a href="' . htmlspecialchars($currentUrl, ENT_QUOTES) . '" onclick="' . $gotoModeAttr($mode) . '" style="color:inherit">' . $inner . '</a>';
    };
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

        {{-- LBAs Written/Read (ATA attributes 241/242) — not all drives report these. --}}
        @php
            $attrById = [];
            foreach ($disk['attributes'] as $a) {
                $attrById[(int) ($a['attribute_id'] ?? 0)] = $a;
            }
            $lbaWriteAttr = $attrById[241] ?? null;
            $lbaReadAttr  = $attrById[242] ?? null;
        @endphp
        @if($lbaWriteAttr !== null || $lbaReadAttr !== null)
        @php
            $blockSize = is_numeric($info['logical_block_size'] ?? null) ? (int) $info['logical_block_size'] : 512;

            $luNow  = \App\Facades\LibrenmsConfig::get('time.now');
            $luFrom = \App\Facades\LibrenmsConfig::get('time.day');
            $luGraphArray = \App\Http\Controllers\Device\Tabs\OverviewController::setGraphWidth([
                'id'         => $data->app->app_id,
                'type'       => 'application_smart_v2_lba_units',
                'disk'       => $idx,
                'block_size' => $blockSize,
                'from'       => $luFrom,
                'to'         => $luNow,
                'legend'     => 'no',
            ]);
            $luGraph = \LibreNMS\Util\Url::lazyGraphTag($luGraphArray, 'tw:w-full tw:h-auto');

            $luLinkArray = $luGraphArray;
            $luLinkArray['page'] = 'graphs';
            unset($luLinkArray['height'], $luLinkArray['width']);
            $luLink = \LibreNMS\Util\Url::generate($luLinkArray);

            $luOverlibArray = $luGraphArray;
            $luOverlibArray['width'] = 210;
            $luOverlib = generate_overlib_content($luOverlibArray, $device['hostname'] . ' - LBAs Written/Read');

            // Current rate (LBA/s + B/s) from the last RRD interval, not the lifetime total.
            $luRrdFile = \App\Facades\Rrd::name($device['hostname'], ['app', 'smart', $data->app->app_id, $idx]);
            $luRates   = \App\Facades\Rrd::getLastRates($luRrdFile, ['id241', 'id242']);
            $luRdRate  = $lbaReadAttr !== null ? $luRates?->get('id242') : null;
            $luWrRate  = $lbaWriteAttr !== null ? $luRates?->get('id241') : null;
            $fmtLbaRate = static fn (float $r): string => \LibreNMS\Util\Number::formatSi($r, 2, 0, 'LBA') . '/s ('
                . \LibreNMS\Util\Number::formatSi($r * $blockSize, 2, 0, 'B') . '/s)';
            $luRd = is_numeric($luRdRate) ? $fmtLbaRate((float) $luRdRate) : null;
            $luWr = is_numeric($luWrRate) ? $fmtLbaRate((float) $luWrRate) : null;
            $luParts = array_filter([$luRd !== null ? 'R: ' . $luRd : null, $luWr !== null ? 'W: ' . $luWr : null]);
            $luBadge = $luParts !== [] ? '<span class="text-muted">' . htmlspecialchars(implode(' / ', $luParts)) . '</span>' : '';

            $panelStart('<i class="fa fa-database" style="margin-right:6px"></i>LBAs Written/Read', $luBadge);
            echo \LibreNMS\Util\Url::overlibLink($luLink, $luGraph, $luOverlib);
            $panelEnd();
        @endphp
        @else
        {{-- No LBA-count attributes — fall back to a generic disk I/O graph (UCD-DISKIO-MIB), if available. --}}
        @php
            $diskioCandidates = [];
            foreach ([$disk['device_path'] ?? null, $disk['device_name'] ?? null] as $cand) {
                $cand = trim((string) $cand);
                if ($cand === '') { continue; }
                $diskioCandidates[] = $cand;
                $diskioCandidates[] = ltrim((string) preg_replace('#^/dev/#', '', $cand), '/');
                $diskioCandidates[] = basename($cand);
            }
            $diskioCandidates = array_values(array_unique($diskioCandidates));
            $diskioDescr = $diskioCandidates !== [] ? \Illuminate\Support\Facades\DB::table('ucd_diskio')
                ->where('device_id', $device['device_id'])
                ->whereIn('diskio_descr', $diskioCandidates)
                ->value('diskio_descr') : null;
        @endphp
        @if($diskioDescr !== null)
        @php
            $dioNow  = \App\Facades\LibrenmsConfig::get('time.now');
            $dioFrom = \App\Facades\LibrenmsConfig::get('time.day');
            $dioGraphArray = \App\Http\Controllers\Device\Tabs\OverviewController::setGraphWidth([
                'id'     => $data->app->app_id,
                'type'   => 'application_smart_v2_diskio',
                'disk'   => $idx,
                'from'   => $dioFrom,
                'to'     => $dioNow,
                'legend' => 'no',
            ]);
            $dioGraph = \LibreNMS\Util\Url::lazyGraphTag($dioGraphArray, 'tw:w-full tw:h-auto');

            $dioLinkArray = $dioGraphArray;
            $dioLinkArray['page'] = 'graphs';
            unset($dioLinkArray['height'], $dioLinkArray['width']);
            $dioLink = \LibreNMS\Util\Url::generate($dioLinkArray);

            $dioOverlibArray = $dioGraphArray;
            $dioOverlibArray['width'] = 210;
            $dioOverlib = generate_overlib_content($dioOverlibArray, $device['hostname'] . ' - Disk I/O');

            $dioRrdFile = \App\Facades\Rrd::name($device['hostname'], ['ucd_diskio', $diskioDescr]);
            $dioRates   = \App\Facades\Rrd::getLastRates($dioRrdFile, ['read', 'written']);
            $dioRd = is_numeric($dioRates?->get('read')) ? \LibreNMS\Util\Number::formatSi((float) $dioRates->get('read'), 2, 0, 'B') . '/s' : null;
            $dioWr = is_numeric($dioRates?->get('written')) ? \LibreNMS\Util\Number::formatSi((float) $dioRates->get('written'), 2, 0, 'B') . '/s' : null;
            $dioParts = array_filter([$dioRd !== null ? 'R: ' . $dioRd : null, $dioWr !== null ? 'W: ' . $dioWr : null]);
            $dioBadge = $dioParts !== [] ? '<span class="text-muted">' . htmlspecialchars(implode(' / ', $dioParts)) . '</span>' : '';

            $panelStart('<i class="fa fa-exchange" style="margin-right:6px"></i>Disk I/O', $dioBadge);
            echo \LibreNMS\Util\Url::overlibLink($dioLink, $dioGraph, $dioOverlib);
            $panelEnd();
        @endphp
        @endif
        @endif

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
                'state'       => 'fa-bullseye',
                'temperature' => 'fa-thermometer-half',
                'percent'     => 'fa-tachometer',
                'power'       => 'fa-power-off',
                'voltage'     => 'fa-bolt',
                default       => 'fa-line-chart',
            };
            $sensorBadge = static function ($s) use ($stateBadge, $tempBadge, $percentBadge, $selftestBadge): string {
                if ($s->sensor_class === 'runtime'
                    && (str_ends_with((string) $s->sensor_index, '_selftest_short')
                        || str_ends_with((string) $s->sensor_index, '_selftest_long'))) {
                    return $selftestBadge($s);
                }

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
            // Wraps $content (label text, value badge, or the mini graph image) in
            // the same hover-preview link that points at the sensor's graph.
            $sensorGraphLink = static function ($s, string $content) use ($hNow, $hFrom, $device): string {
                $g = ['id' => $s->sensor_id, 'type' => 'sensor_' . $s->sensor_class, 'from' => $hFrom, 'to' => $hNow, 'legend' => 'no', 'width' => 210, 'height' => 100];
                $overlib = generate_overlib_content($g, $device['hostname'] . ' - ' . $s->sensor_descr);
                $linkArr = $g; $linkArr['page'] = 'graphs'; unset($linkArr['width'], $linkArr['height'], $linkArr['legend']);
                $link = \LibreNMS\Util\Url::generate($linkArr);

                return \LibreNMS\Util\Url::overlibLink($link, $content, $overlib);
            };
            $sensorMini = static function ($s) use ($sensorGraphLink, $hNow, $hFrom): string {
                $g = ['id' => $s->sensor_id, 'type' => 'sensor_' . $s->sensor_class, 'from' => $hFrom, 'to' => $hNow, 'legend' => 'no', 'width' => 100, 'height' => 20, 'bg' => 'ffffff00'];

                return $sensorGraphLink($s, \LibreNMS\Util\Url::lazyGraphTag($g));
            };

            // All disk sensors, status (state) sensors first.
            $healthSensors = $data->diskSensors($selectedDisk);
            uasort($healthSensors, static fn ($a, $b) => (($a->sensor_class === 'state') ? 0 : 1) <=> (($b->sensor_class === 'state') ? 0 : 1));

            $healthHeader = $gotoHeaderLink('tables', '<i class="fa fa-heartbeat" style="margin-right:6px"></i>Health');
            $panelStart($healthHeader, $healthBadge);
            echo '<table class="table table-condensed table-hover" style="width:100%">';
            foreach ($healthSensors as $s) {
                $isSelftestAge = $s->sensor_class === 'runtime'
                    && (str_ends_with((string) $s->sensor_index, '_selftest_short')
                        || str_ends_with((string) $s->sensor_index, '_selftest_long'));
                $label = htmlspecialchars($data->shortSensorName($s, $disk));
                $badge = $sensorBadge($s);
                // Self-test age rows already navigate elsewhere on row click — don't
                // also wrap their label/value in the (different) graph link.
                echo ($isSelftestAge ? $gotoRowOpen('selftest') : '<tr>')
                    . '<td style="white-space:nowrap"><i class="fa ' . $sensorIcon($s->sensor_class) . ' text-muted" style="margin-right:6px"></i>' . ($isSelftestAge ? $label : $sensorGraphLink($s, $label)) . '</td>'
                    . '<td style="width:110px">' . $sensorMini($s) . '</td>'
                    . '<td style="text-align:right">' . ($isSelftestAge ? $badge : $sensorGraphLink($s, $badge)) . '</td>'
                    . '</tr>';
            }

            // Live power state and power lifetime summary rows.
            if (($disk['power_state'] ?? null) !== null) {
                $powerState = $data->decode('power_state', $disk['power_state']);
                $powerStateGraphArgs = [
                    'id'          => $data->app->app_id,
                    'type'        => 'application_smart_v2_powerState',
                    'disk'        => $idx,
                    'from'        => $hFrom,
                    'to'          => $hNow,
                    'width'       => 100,
                    'height'      => 20,
                    'legend'      => 'no',
                    'popup_title' => htmlspecialchars($device['hostname'] . ' - Power State'),
                ];
                $powerStateLabel = \LibreNMS\Util\Url::graphPopup($powerStateGraphArgs, $labelWithTooltip('Power State', $tooltipForLabel('Power State')));
                $powerStateMini = \LibreNMS\Util\Url::graphPopup($powerStateGraphArgs);
                $powerStateValue = \LibreNMS\Util\Url::graphPopup($powerStateGraphArgs, htmlspecialchars($powerState));
                echo '<tr><td style="white-space:nowrap"><i class="fa fa-plug text-muted" style="margin-right:6px"></i>'
                    . $powerStateLabel . '</td>'
                    . '<td style="width:110px">' . $powerStateMini . '</td>'
                    . '<td style="text-align:right">' . $powerStateValue . '</td></tr>';
            }
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

            // Offline Data Collection summary row.
            $odcStatus = $health['offline_collection_status'] ?? null;
            if ($odcStatus !== null && is_numeric($odcStatus)) {
                $odcAuto   = ((int) $odcStatus & 0x80) !== 0;
                $odcBadge  = $odcAuto
                    ? '<span class="label label-default">Enabled</span>'
                    : '<span class="label" style="background-color:#e8857f">Disabled</span>';
                $odcVal    = $odcBadge . ', ' . htmlspecialchars($data->decode('offline_status', (int) $odcStatus & 0x7f));
                echo $gotoRowOpen('selftest') . '<td style="white-space:nowrap"><i class="fa fa-clock-o text-muted" style="margin-right:6px"></i>'
                    . $labelWithTooltip('Offline Data Collection', $tooltipForLabel('Offline Data Collection')) . '</td>'
                    . '<td colspan="2" style="text-align:right">' . $odcVal . '</td></tr>';
            }

            // SMART Attributes overall (NA-status attributes excluded).
            if (! empty($disk['attributes'])) {
                $attrCount   = 0;
                $attrFailing = 0;
                $attrFailed  = 0;
                foreach ($disk['attributes'] as $a) {
                    $st = (int) ($a['status'] ?? 0);
                    if ($st === -1) { continue; }
                    $attrCount++;
                    if ($st === 2) { $attrFailing++; }
                    elseif ($st === 3) { $attrFailed++; }
                }
                $attrVal = 'Total: <span class="label label-default">' . $attrCount . '</span>'
                    . ' &nbsp; Has failed: <span class="label label-' . ($attrFailed > 0 ? 'warning' : 'default') . '">' . $attrFailed . '</span>'
                    . ' &nbsp; Is failing: <span class="label label-' . ($attrFailing > 0 ? 'danger' : 'default') . '">' . $attrFailing . '</span>';
                $attrAnchorId = 'smart-attributes-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $idx);
                echo '<tr style="cursor:pointer" onclick="document.getElementById(\'' . $attrAnchorId . '\').scrollIntoView({behavior:\'smooth\'});">'
                    . '<td style="white-space:nowrap"><i class="fa fa-list text-muted" style="margin-right:6px"></i>'
                    . '<abbr style="cursor:help;text-decoration:underline dotted" title="Attributes with NA status excluded">SMART Attributes Overall</abbr></td>'
                    . '<td colspan="2" style="text-align:right">' . $attrVal . '</td></tr>';
            }

            echo '</table>';
            $panelEnd();
        @endphp

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
    $attrAnchorId = 'smart-attributes-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $idx);
    echo '<a id="' . $attrAnchorId . '" style="position:relative;top:-70px;display:block;visibility:hidden"></a>';

    // Header: link through to the Attributes section of the Graphs view, plus a
    // total/failed/failing summary (NA-status attributes counted separately).
    $attrTotalAll = count($disk['attributes']);
    $attrCount    = 0;
    $attrFailing  = 0;
    $attrFailed   = 0;
    foreach ($disk['attributes'] as $a) {
        $st = (int) ($a['status'] ?? 0);
        if ($st === -1) { continue; }
        $attrCount++;
        if ($st === 2) { $attrFailing++; }
        elseif ($st === 3) { $attrFailed++; }
    }
    $attrSummaryBadge = '<span class="text-muted">Total: <span class="label label-default">' . $attrCount . ' / ' . $attrTotalAll . '</span>'
        . ' &nbsp; Failed: <span class="label label-' . ($attrFailed > 0 ? 'warning' : 'default') . '">' . $attrFailed . '</span>'
        . ' &nbsp; Failing: <span class="label label-' . ($attrFailing > 0 ? 'danger' : 'default') . '">' . $attrFailing . '</span></span>';

    $attrGraphHref   = htmlspecialchars($currentUrl, ENT_QUOTES) . '#smart-device-' . $idx . '-graph-attributes';
    $attrHeaderLink  = '<a href="' . $attrGraphHref . '" onclick="' . $gotoModeAttr('graphs') . '" style="color:inherit">SMART Attributes</a>';

    $panelStart($attrHeaderLink, $attrSummaryBadge);

    $attrAppId = $data->app->app_id;
    $attrNow   = \App\Facades\LibrenmsConfig::get('time.now');
    $attrFrom  = \App\Facades\LibrenmsConfig::get('time.week');
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
        . '<th class="smart-attr-sort" data-type="num" onclick="smartAttrSort(this)" style="cursor:pointer;max-width:48px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="Average raw value change over the last 8 hours (per-second for high-volume counters, per-hour otherwise)">&Delta; 8h</th>'
        . '<th class="smart-attr-sort" data-type="num" onclick="smartAttrSort(this)" style="cursor:pointer;max-width:48px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="Average raw value change over the last 24 hours (per-second for high-volume counters, per-hour otherwise)">&Delta; 24h</th>'
        . '<th class="smart-attr-sort" data-type="num" onclick="smartAttrSort(this)" style="cursor:pointer;max-width:48px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="Average raw value change over the last 168 hours / 1 week (per-second for high-volume counters, per-hour otherwise)">&Delta; 1w</th>'
        . '<th class="smart-attr-sort" data-type="num" onclick="smartAttrSort(this)" style="cursor:pointer;max-width:48px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="Average raw value change over the last 672 hours / 1 month (per-second for high-volume counters, per-hour otherwise)">&Delta; 1mo</th>'
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
        $rawFull  = (string) ($attr['value_raw_string'] ?? $attr['value_raw'] ?? '');
        $rawDisp  = $data->formatRawSI($rawFull);
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

        $rawFormat = isset($attr['format']) && is_numeric($attr['format']) ? (int) $attr['format'] : null;
        $rawTip = 'Raw hardware reading - vendor-specific meaning'
            . "\nFormat: " . $data->decode('attr_format', $rawFormat)
            . "\nFull raw: " . $rawFull;
        $rawCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars($rawTip, ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . htmlspecialchars($rawDisp) . '</span>';

        $statusBadge = match ($status) {
            1  => '<span class="label label-default">' . htmlspecialchars($statusLabel) . '</span>',
            2  => '<span class="label label-danger">' . htmlspecialchars($statusLabel) . '</span>',
            3  => '<span class="label" style="background-color:#e8857f">' . htmlspecialchars($statusLabel) . '</span>',
            4  => '<span class="label label-warning">' . htmlspecialchars($statusLabel) . '</span>',
            default => '<span class="text-muted">' . htmlspecialchars($statusLabel) . '</span>',
        };

        // rate_8h/24h/168h/672h are persisted in raw-units-per-hour. attributeRateUnit()
        // switches high-volume counters (avg > 3600/h, i.e. >1/s) to a per-second display;
        // it's the same lookup the mini-graph uses to pick its DS unit.
        $rateUnit    = $data->attributeRateUnit($attr);
        $rateDivisor = $rateUnit === 'second' ? 3600.0 : 1.0;
        $rateSuffix  = $rateUnit === 'second' ? '/s' : ($rateUnit === 'hour' ? '/h' : '');

        $fmtRate = static function ($v) use ($rateDivisor, $rateSuffix) {
            if (! is_numeric($v)) {
                return '-';
            }
            $v = (float) $v / $rateDivisor;

            $num = abs($v) >= 1000
                ? str_replace(' ', '', \LibreNMS\Util\Number::formatSi($v, 1, 0, ''))
                : number_format($v, $v == (int) $v ? 0 : 1);

            return $num . $rateSuffix;
        };
        $rateCellStyle = 'max-width:48px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap';
        $rateTitle = static function ($v) use ($rateDivisor, $rateSuffix) {
            if (! is_numeric($v)) {
                return '';
            }
            $v = (float) $v / $rateDivisor;

            return abs($v) >= 1000 ? 'Raw: ' . number_format($v, 2) . $rateSuffix : '';
        };
        $rate8h   = $attr['rate_8h'] ?? null;
        $rate24h  = $attr['rate_24h'] ?? null;
        $rate168h = $attr['rate_168h'] ?? null;
        $rate672h = $attr['rate_672h'] ?? null;

        // In-row mini graph: the same smart_v2_attr_value graph, 60x15.
        // Click → graphs page, hover → day/week/month/year popup (as on device overview).
        $mini = '';
        if ($attrId > 0) {
            $mini = \LibreNMS\Util\Url::graphPopup([
                'id'          => $attrAppId,
                'type'        => 'application_smart_v2_attr_value',
                'disk'        => $idx,
                'attr_id'     => $attrId,
                'rate_unit'   => $attr['rate_unit'] ?? '',
                'from'        => $attrFrom,
                'to'          => $attrNow,
                'width'       => 60,
                'height'      => 15,
                'legend'      => 'no',
                'popup_title' => htmlspecialchars($device['hostname'] . ' - ' . $name),
            ]);
        }

        // raw24div24/raw24div32 (Hi/Lo split into two separate graphs, see
        // smart_v2_attr_value.inc.php) get a second mini graph here for Lo's
        // own scale, since Hi and Lo often differ by orders of magnitude.
        $miniDiv = '';
        if ($attrId > 0 && in_array($rawFormat, [12, 13], true)) {
            $miniDiv = \LibreNMS\Util\Url::graphPopup([
                'id'          => $attrAppId,
                'type'        => 'application_smart_v2_attr_div',
                'disk'        => $idx,
                'attr_id'     => $attrId,
                'rate_unit'   => $attr['rate_unit'] ?? '',
                'from'        => $attrFrom,
                'to'          => $attrNow,
                'width'       => 60,
                'height'      => 15,
                'legend'      => 'no',
                'popup_title' => htmlspecialchars($device['hostname'] . ' - ' . $name . ' (Hi/Lo)'),
            ]);
        }

        echo '<tr style="' . $rowStyle . '" data-fail="' . $isFail . '" data-flags="' . htmlspecialchars($flagsRaw, ENT_QUOTES) . '">'
            . '<td data-sort="' . $attrId . '">' . $attrId . '</td>'
            . '<td data-sort="' . htmlspecialchars($name, ENT_QUOTES) . '">' . htmlspecialchars($name) . '</td>'
            . '<td data-sort="' . $status . '">' . $statusBadge . '</td>'
            . '<td>' . $mini . ($miniDiv !== '' ? '<br>' . $miniDiv : '') . '</td>'
            . '<td>' . $flagsCell . '</td>'
            . '<td data-sort="' . htmlspecialchars((string) ($value ?? ''), ENT_QUOTES) . '">' . $valueCell . '</td>'
            . '<td data-sort="' . htmlspecialchars((string) ($worst ?? ''), ENT_QUOTES) . '">' . $worstCell . '</td>'
            . '<td data-sort="' . htmlspecialchars((string) ($thresh ?? ''), ENT_QUOTES) . '">' . $threshCell . '</td>'
            . '<td data-sort="' . $rawNum . '">' . $rawCell . '</td>'
            . '<td style="' . $rateCellStyle . '" title="' . htmlspecialchars($rateTitle($rate8h), ENT_QUOTES) . '" data-sort="' . htmlspecialchars((string) ($rate8h ?? ''), ENT_QUOTES) . '">' . $fmtRate($rate8h) . '</td>'
            . '<td style="' . $rateCellStyle . '" title="' . htmlspecialchars($rateTitle($rate24h), ENT_QUOTES) . '" data-sort="' . htmlspecialchars((string) ($rate24h ?? ''), ENT_QUOTES) . '">' . $fmtRate($rate24h) . '</td>'
            . '<td style="' . $rateCellStyle . '" title="' . htmlspecialchars($rateTitle($rate168h), ENT_QUOTES) . '" data-sort="' . htmlspecialchars((string) ($rate168h ?? ''), ENT_QUOTES) . '">' . $fmtRate($rate168h) . '</td>'
            . '<td style="' . $rateCellStyle . '" title="' . htmlspecialchars($rateTitle($rate672h), ENT_QUOTES) . '" data-sort="' . htmlspecialchars((string) ($rate672h ?? ''), ENT_QUOTES) . '">' . $fmtRate($rate672h) . '</td>'
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

