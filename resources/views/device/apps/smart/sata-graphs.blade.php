{{-- SATA/SAS per-disk "Graphs" view: full graph set with jump nav. --}}
{{-- Inherits closures ($panelStart, $panelEnd) and $data, $device, $selectedDisk, --}}
{{-- $smartUrl from the parent smart.blade.php. --}}
@php
    $disk = $data->disk($selectedDisk);
    $idx  = $disk['idx'];

    $now      = \App\Facades\LibrenmsConfig::get('time.now');
    $appId    = $data->app->app_id;
    $anchorPrefix = 'smart-device-' . $idx . '-graph-';
    $tempSensor   = $data->temperatureSensor($selectedDisk);
    $healthSensor = $data->healthSensor($selectedDisk);
    $healthBadge  = $stateBadge($healthSensor, 'SMART overall-health self-assessment test result.');
    $specs        = $data->attributeGraphSpecs($selectedDisk);
    $hasBig5      = $data->hasBig5Rrd($selectedDisk);
    $hasOther     = $data->hasOtherRrd($selectedDisk);
    $hasPowerState = $data->hasPowerStateRrd($selectedDisk);
    $errorSpecs    = array_filter($specs, static fn ($spec) => preg_match('/error/i', $spec['raw_name']));
    $shutdownSpecs = array_filter($specs, static fn ($spec) => preg_match('/shutdown/i', $spec['raw_name']));
    $graphBase    = $smartUrl((string) $selectedDisk);

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
    if ($hasPowerState) { $sections[] = [$anchorPrefix . 'power-state', 'Power State']; }
    if ($powerSpec)    { $sections[] = [$anchorPrefix . 'power', 'Power-on Hours']; }
    if ($hasBig5)       { $sections[] = [$anchorPrefix . 'big5', 'Reliability / Age (Big 5 ATA Attributes)']; }
    if ($hasOther)      { $sections[] = [$anchorPrefix . 'other', 'Other']; }
    if ($errorSpecs !== [])    { $sections[] = [$anchorPrefix . 'errors', 'Error Attributes']; }
    if ($shutdownSpecs !== []) { $sections[] = [$anchorPrefix . 'unsafe-shut', 'Unsafe Shutdowns']; }
    $sections[] = [$anchorPrefix . 'lba-units', 'LBA Units'];
    $sections[] = [$anchorPrefix . 'diskio', 'Disk I/O'];
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
            'id'     => $appId, 'type' => "smart_{$type}",
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
        if ($shortSensor) { $stParts[] = 'Short: ' . $shortSensor->formatValue(); }
        if ($longSensor)  { $stParts[] = 'Long: '  . $longSensor->formatValue(); }
        $appGraph('disk_selftest', 'Self-test Age', $anchorPrefix . 'selftest', $stParts !== [] ? implode(' | ', $stParts) : '');
    }
    if ($hasPowerState) {
        $powerState = $disk['power_state'] ?? null;
        $powerStateBadge = $powerState !== null ? $data->decode('power_state', $powerState) : '';
        $appGraph('disk_power_state', 'Power State', $anchorPrefix . 'power-state', $powerStateBadge);
    }
    if ($powerSpec) {
        $appGraph('sata_attr_value', 'Power-on Hours', $anchorPrefix . 'power', $data->powerHeader($disk), [
            'attr_id'     => '9',
            'attr_thresh' => $powerSpec['thresh'] !== null ? (string) $powerSpec['thresh'] : '',
            'rate_unit'   => $powerSpec['rate_unit'] ?? '',
        ]);
    }
    if ($hasBig5) {
        $appGraph('sata_big5', 'Reliability / Age (Big 5 ATA Attributes)', $anchorPrefix . 'big5', $data->reliabilityHeader($disk));
    }
    if ($hasOther) {
        $appGraph('sata_other', 'Other', $anchorPrefix . 'other');
    }
    if ($errorSpecs !== []) {
        $appGraph('disk_errors', 'Error Attributes', $anchorPrefix . 'errors');
    }
    if ($shutdownSpecs !== []) {
        $appGraph('disk_unsafe_shut', 'Unsafe Shutdowns', $anchorPrefix . 'unsafe-shut');
    }
    $appGraph('disk_lba_units', 'LBA Units', $anchorPrefix . 'lba-units');
    $appGraph('disk_diskio', 'Disk I/O', $anchorPrefix . 'diskio');

    // Per-attribute graphs with a "Scale from zero" toggle (id 9 is shown above as Power-on Hours).
    // Forecast/trend overlay is not a UI toggle here -- like sensor/port_bits graphs,
    // it shows automatically when the graph's end time extends into the future
    // (see includes/html/pages/graphs.inc.php's "set to future date" hint).
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
        echo '<a id="' . $anchorPrefix . 'attributes" style="position:relative;top:-70px;display:block;visibility:hidden"></a>';
        echo '<h4 style="margin:20px 0 8px;border-bottom:1px solid #ddd;padding-bottom:6px">Attributes'
            . '<label style="float:right;font-size:13px;font-weight:normal;margin-bottom:0;cursor:pointer;margin-left:14px">'
            . '<input type="checkbox" id="' . $toggleId . '" checked onchange="smartAttrScaleToggle(this,\'' . $wrapperId . '\')"> Scale from zero</label></h4>';
        echo '<div id="' . $wrapperId . '">';
        foreach ($attrSpecs as $spec) {
            $appGraph('sata_attr_value', $spec['title'], $anchorPrefix . 'attr-' . $spec['id'], $spec['header'], [
                'attr_id'     => (string) $spec['id'],
                'attr_thresh' => $spec['thresh'] !== null ? (string) $spec['thresh'] : '',
                'rate_unit'   => $spec['rate_unit'] ?? '',
            ]);
        }
        echo '</div>';
    }
@endphp
