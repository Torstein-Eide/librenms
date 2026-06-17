{{-- SATA/SAS per-disk "Graphs" view: full graph set with jump nav. --}}
{{-- Inherits closures ($panelStart, $panelEnd) and $data, $device, $selectedDisk, --}}
{{-- $linkArray from the parent smart.blade.php. --}}
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
        if ($shortSensor) { $stParts[] = 'Short: ' . $shortSensor->formatValue(); }
        if ($longSensor)  { $stParts[] = 'Long: '  . $longSensor->formatValue(); }
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
