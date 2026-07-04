{{-- NVMe per-disk "Graphs" view. Included from smart.blade.php for NVMe disks --}}
{{-- when viewMode is 'graphs'. Inherits closures ($panelStart, $panelEnd) and --}}
{{-- $data, $device, $selectedDisk, $disk from the parent view. --}}
@php
    $health = $disk['health'];
    $now    = \App\Facades\LibrenmsConfig::get('time.now');

    $healthSensor = $data->healthSensor($selectedDisk);
    $diskSensors  = $data->diskSensors($selectedDisk);
    $tempSensors  = array_filter($diskSensors, static fn ($s) => $s->sensor_class === 'temperature');
@endphp

@php
    $idx          = $disk['idx'];
    $anchorPrefix = 'smart-device-' . $idx . '-graph-';
    $graphBase    = $smartUrl((string) $selectedDisk);
    $anchor       = static function (string $id): void {
        echo '<a id="' . htmlspecialchars($id) . '" style="position:relative;top:-70px;display:block;visibility:hidden"></a>';
    };

    $nvSensorGraph = static function ($sensor, string $title, string $anchorId, string $badge = '') use ($now, $panelStart, $panelEnd, $device, $anchor) {
        if (! $sensor) { return; }
        $graph_array = [
            'height' => '100', 'width' => '215', 'to' => $now,
            'id' => $sensor->sensor_id, 'type' => 'sensor_' . $sensor->sensor_class, 'legend' => 'no',
        ];
        $badgeHtml = $badge !== '' ? '<span class="text-muted">' . htmlspecialchars($badge) . '</span>' : '';
        $anchor($anchorId);
        $panelStart(htmlspecialchars($title), $badgeHtml);
        echo '<div class="row">';
        include 'includes/html/print-graphrow.inc.php';
        echo '</div>';
        $panelEnd();
    };

    $nvAppGraph = static function (string $type, string $title, string $anchorId, array $extra = [], string $badge = '') use ($now, $data, $disk, $panelStart, $panelEnd, $anchor) {
        $graph_array = array_merge([
            'height' => '100', 'width' => '215', 'to' => $now,
            'id' => $data->app->app_id, 'type' => 'smart_' . $type,
            'disk' => $disk['idx'], 'scale_min' => '0',
        ], $extra);
        $badgeHtml = $badge !== '' ? '<span class="text-muted">' . htmlspecialchars($badge) . '</span>' : '';
        $anchor($anchorId);
        $panelStart(htmlspecialchars($title), $badgeHtml);
        echo '<div class="row">';
        include 'includes/html/print-graphrow.inc.php';
        echo '</div>';
        $panelEnd();
    };

    $fmtI = static fn ($v) => is_numeric($v) ? number_format((int) $v, 0, '.', ' ') : '-';
    $du = 512000; // NVMe data-unit size in bytes

    // Find Available Spare and Percentage Used sensors.
    $spareSensor   = null;
    $pctUsedSensor = null;
    foreach ($diskSensors as $s) {
        if ($s->sensor_class !== 'percent') { continue; }
        $nm = $data->shortSensorName($s, $disk);
        if ($spareSensor === null && stripos($nm, 'Spare') !== false) {
            $spareSensor = $s;
        } elseif ($pctUsedSensor === null && (stripos($nm, 'Percentage Used') !== false || stripos($nm, '% Used') !== false)) {
            $pctUsedSensor = $s;
        }
    }

    $statusSensor = $data->selftestStatusSensor($selectedDisk);

    // Build jump-nav section list, in the same order the graphs are rendered below.
    $sections = [[$anchorPrefix . 'temperature', 'Temperature']];
    if ($spareSensor)   { $sections[] = [$anchorPrefix . 'spare', 'Available Spare']; }
    if ($pctUsedSensor) { $sections[] = [$anchorPrefix . 'pct-used', 'Percentage Used']; }
    $sections[] = [$anchorPrefix . 'lba-units', 'LBA / Data Units'];
    $sections[] = [$anchorPrefix . 'host-io', 'Host I/O'];
    $sections[] = [$anchorPrefix . 'diskio', 'Disk I/O'];
    $sections[] = [$anchorPrefix . 'errors', 'Media Errors'];
    $sections[] = [$anchorPrefix . 'crit-warn', 'Critical Warning'];
    $sections[] = [$anchorPrefix . 'ctrl-busy', 'Controller Busy'];
    $sections[] = [$anchorPrefix . 'temp-time', 'Temp Threshold Time'];
    $sections[] = [$anchorPrefix . 'pwr-hours', 'Power On Hours'];
    $sections[] = [$anchorPrefix . 'pwr-cycles', 'Power Cycles'];
    $sections[] = [$anchorPrefix . 'unsafe-shut', 'Unsafe Shutdowns'];
    $sections[] = [$anchorPrefix . 'health', 'Health'];
    if ($statusSensor) { $sections[] = [$anchorPrefix . 'selftest-status', 'Self-test Status']; }

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

    // Combined temperature (overlaid sensors + warn/crit limit lines).
    $compositeTemp = null;
    foreach ($tempSensors as $ts) {
        if (stripos($data->shortSensorName($ts, $disk), 'Composite') !== false) { $compositeTemp = $ts; break; }
    }
    $tempBadge = ($compositeTemp && is_numeric($compositeTemp->sensor_current))
        ? number_format((float) $compositeTemp->sensor_current, 1) . '°C' : '';
    $nvAppGraph('disk_temp', 'Temperature', $anchorPrefix . 'temperature', [], $tempBadge);

    // Available Spare - sensor graph (includes limit/threshold lines).
    if ($spareSensor) {
        $spareBadge = is_numeric($spareSensor->sensor_current)
            ? number_format((float) $spareSensor->sensor_current, 0) . '%' : '';
        $spareThresh = $health['available_spare_threshold'] ?? null;
        if (is_numeric($spareThresh)) {
            $spareBadge .= ($spareBadge !== '' ? ' / ' : '') . 'thresh ' . (int) $spareThresh . '%';
        }
        $nvSensorGraph($spareSensor, 'Available Spare', $anchorPrefix . 'spare', $spareBadge);
    }
    // Percentage Used - sensor graph.
    if ($pctUsedSensor) {
        $pctBadge = is_numeric($pctUsedSensor->sensor_current)
            ? number_format((float) $pctUsedSensor->sensor_current, 0) . '%' : '';
        $nvSensorGraph($pctUsedSensor, 'Percentage Used', $anchorPrefix . 'pct-used', $pctBadge);
    }

    // SMART/Health log metric breakdowns (from the smart_nvme RRD).
    $duRd = isset($health['data_units_read']) && is_numeric($health['data_units_read'])
        ? \LibreNMS\Util\Number::formatBi((int) $health['data_units_read'] * $du) : null;
    $duWr = isset($health['data_units_written']) && is_numeric($health['data_units_written'])
        ? \LibreNMS\Util\Number::formatBi((int) $health['data_units_written'] * $du) : null;
    $duParts = array_filter([$duRd !== null ? 'R: ' . $duRd : null, $duWr !== null ? 'W: ' . $duWr : null]);
    $nvAppGraph('disk_lba_units', 'LBA / Data Units', $anchorPrefix . 'lba-units', ['rrd' => 'smart_nvme'], implode(' / ', $duParts));

    $hrRd = is_numeric($health['host_read_commands'] ?? null) ? $fmtI($health['host_read_commands']) : null;
    $hrWr = is_numeric($health['host_write_commands'] ?? null) ? $fmtI($health['host_write_commands']) : null;
    $ioParts = array_filter([$hrRd !== null ? 'R: ' . $hrRd : null, $hrWr !== null ? 'W: ' . $hrWr : null]);
    $nvAppGraph('nvme_host_io', 'Host I/O', $anchorPrefix . 'host-io', [], implode(' / ', $ioParts));
    $nvAppGraph('disk_diskio', 'Disk I/O', $anchorPrefix . 'diskio', ['rrd' => 'smart_nvme']);

    $medErrBadge = is_numeric($health['media_errors'] ?? null) ? $fmtI($health['media_errors']) : '';
    $nvAppGraph('nvme_attr_value', 'Media Errors', $anchorPrefix . 'errors', ['metric' => 'media_errors'], $medErrBadge);

    $cwBadge = isset($health['critical_warning']) && (int) $health['critical_warning'] !== 0
        ? '0x' . dechex((int) $health['critical_warning']) : '';
    $nvAppGraph('nvme_attr_value', 'Critical Warning', $anchorPrefix . 'crit-warn', ['metric' => 'crit_warn'], $cwBadge);

    $ctrlBusy = is_numeric($health['controller_busy_time'] ?? null) ? $fmtI($health['controller_busy_time']) . ' min total' : '';
    $nvAppGraph('nvme_attr_value', 'Controller Busy', $anchorPrefix . 'ctrl-busy', ['metric' => 'ctrl_busy'], $ctrlBusy);

    $warnTime = is_numeric($health['warning_temp_time'] ?? null) ? $fmtI($health['warning_temp_time']) . ' min' : null;
    $critTime = is_numeric($health['critical_comp_time'] ?? null) ? $fmtI($health['critical_comp_time']) . ' min' : null;
    $tmpParts = array_filter([$warnTime !== null ? 'Warn: ' . $warnTime : null, $critTime !== null ? 'Crit: ' . $critTime : null]);
    $nvAppGraph('nvme_temp_time', 'Temp Threshold Time', $anchorPrefix . 'temp-time', [], implode(' / ', $tmpParts) . ($tmpParts !== [] ? ' total' : ''));

    $pohBadge = is_numeric($health['power_on_hours'] ?? null) ? $fmtI($health['power_on_hours']) . ' h' : '';
    $nvAppGraph('nvme_attr_value', 'Power On Hours', $anchorPrefix . 'pwr-hours', ['metric' => 'pwr_hours'], $pohBadge);

    $pcBadge = is_numeric($health['power_cycles'] ?? null) ? $fmtI($health['power_cycles']) : '';
    $nvAppGraph('nvme_attr_value', 'Power Cycles', $anchorPrefix . 'pwr-cycles', ['metric' => 'pwr_cycles'], $pcBadge);

    $usBadge = is_numeric($health['unsafe_shutdowns'] ?? null) ? $fmtI($health['unsafe_shutdowns']) : '';
    $nvAppGraph('nvme_attr_value', 'Unsafe Shutdowns', $anchorPrefix . 'unsafe-shut', ['metric' => 'unsafe_shut'], $usBadge);

    // Health state and self-test status (per-sensor temperatures are already covered
    // by the combined "Temperature" graph above).
    $nvSensorGraph($healthSensor, 'Health', $anchorPrefix . 'health');
    if ($statusSensor) {
        $nvSensorGraph($statusSensor, 'Self-test Status', $anchorPrefix . 'selftest-status');
    }
@endphp
