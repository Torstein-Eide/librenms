<?php

// Combined per-disk temperature graph: overlays every temperature sensor that
// belongs to one SMART disk (sensor_index "{disk}_temp_N") and draws the
// warning/critical limit lines. $vars['disk'] is the pre-computed diskIndex.
// Visual style follows sensor/generic.inc.php (variance band, LINE2, GPRINT columns).

use App\Facades\Rrd;
use App\Models\Sensor;
use LibreNMS\Data\Store\Rrd as RrdStore;

$diskIdx = (string) ($vars['disk'] ?? '');

$tempSensors = Sensor::where('device_id', $device['device_id'])
    ->whereIn('sensor_type', ['smart_temperature', 'smart_mib_temperature'])
    ->where('sensor_index', 'like', $diskIdx . '_temp_%')
    ->orderBy('sensor_descr')
    ->get();

// Derive the shared "SMART {model} {serial} " prefix from ALL sensors on this disk
// so we can strip it even when there is only one temperature sensor.
$allDescrs = Sensor::where('device_id', $device['device_id'])
    ->where('sensor_index', 'like', $diskIdx . '_%')
    ->pluck('sensor_descr')
    ->map(fn ($d) => (string) $d)
    ->all();

$diskPrefix = '';
if (count($allDescrs) >= 2) {
    $pfx = $allDescrs[0];
    foreach (array_slice($allDescrs, 1) as $d) {
        $maxLen = min(strlen($pfx), strlen($d));
        $i = 0;
        while ($i < $maxLen && $pfx[$i] === $d[$i]) {
            $i++;
        }
        $pfx = substr($pfx, 0, $i);
    }
    if (($sp = strrpos($pfx, ' ')) !== false) {
        $pfx = substr($pfx, 0, $sp + 1);
    }
    if (strlen($pfx) > 5) {
        $diskPrefix = $pfx;
    }
}

$vertical_label = '°C';
require 'includes/html/graphs/common.inc.php';

// Dark-mode aware colors matching sensor/generic.inc.php.
$sensor_color     = session('applied_site_style') === 'dark' ? '#f2f2f2' : '#272b30';
$background_color = session('applied_site_style') === 'dark' ? '#272b30' : '#ffffff';
$variance_color   = session('applied_site_style') === 'dark' ? '#3e444c'  : '#c5c5c5';

$colours  = \App\Facades\LibrenmsConfig::get('graph_colours.mega') ?? [];
$nColours = count($colours);

$warn    = null;
$crit    = null;
$entries = [];
foreach ($tempSensors as $sensor) {
    $fn = Rrd::name($device['hostname'], ['sensor', $sensor->sensor_class, $sensor->sensor_type, $sensor->sensor_index]);
    if (! Rrd::checkRrdExists($fn)) {
        continue;
    }
    $descr = (string) $sensor->sensor_descr;
    if ($diskPrefix !== '' && str_starts_with($descr, $diskPrefix)) {
        $descr = substr($descr, strlen($diskPrefix));
    } else {
        $descr = (string) preg_replace('/^SMART\s+/', '', $descr);
    }
    $entries[] = ['filename' => $fn, 'descr' => ltrim($descr)];
    if ($sensor->sensor_limit_warn !== null) {
        $warn = $warn === null ? (float) $sensor->sensor_limit_warn : min($warn, (float) $sensor->sensor_limit_warn);
    }
    if ($sensor->sensor_limit !== null) {
        $crit = $crit === null ? (float) $sensor->sensor_limit : min($crit, (float) $sensor->sensor_limit);
    }
}

if (empty($entries)) {
    return;
}

$isSingle = count($entries) === 1;

// DEFs for every sensor.
foreach ($entries as $idx => $e) {
    $rrd_options[] = "DEF:avg{$idx}={$e['filename']}:sensor:AVERAGE";
    $rrd_options[] = "DEF:mx{$idx}={$e['filename']}:sensor:MAX";
    $rrd_options[] = "DEF:mn{$idx}={$e['filename']}:sensor:MIN";
}

// Variance band: AREA max fills to max; AREA min clips below min with background
// colour, giving a min-max range band. Only for single sensor to avoid overlap.
if ($isSingle) {
    $rrd_options[] = 'AREA:mx0' . $variance_color;
    $rrd_options[] = 'AREA:mn0' . $background_color;
}

// Limit lines (LINE1.5 dashed, matching sensor/generic.inc.php).
if ($warn !== null || $crit !== null) {
    $rrd_options[] = 'COMMENT:Alert thresholds\:';
    if ($warn !== null) {
        $rrd_options[] = 'LINE1.5:' . $warn . '#ffa420:high_warn = ' . rtrim(rtrim(sprintf('%.1f', $warn), '0'), '.') . '°C:dashes';
    }
    if ($crit !== null) {
        $rrd_options[] = 'LINE1.5:' . $crit . '#ff0000:high = ' . rtrim(rtrim(sprintf('%.1f', $crit), '0'), '.') . '°C:dashes';
    }
}

// Column header.
$rrd_options[] = 'COMMENT:\n';
$rrd_options[] = 'COMMENT:' . RrdStore::fixedSafeDescr('', 26) . '       Now         Avg        Min        Max\n';

// Lines + GPRINT per sensor.
foreach ($entries as $idx => $e) {
    $colour = $isSingle ? $sensor_color : ('#' . ($colours[$idx % $nColours] ?? '272b30'));
    $descr  = RrdStore::fixedSafeDescr($e['descr'], 25);
    $rrd_options[] = "LINE2:avg{$idx}{$colour}:{$descr}";
    $rrd_options[] = "GPRINT:avg{$idx}:LAST:%7.1lf%S°C";
    $rrd_options[] = "GPRINT:avg{$idx}:AVERAGE:%7.1lf%S°C";
    $rrd_options[] = "GPRINT:avg{$idx}:MIN:%7.1lf%S°C";
    $rrd_options[] = "GPRINT:avg{$idx}:MAX:%7.1lf%S°C\\l";
}
