<?php

// Combined per-disk temperature graph: overlays every temperature sensor that
// belongs to one SMART disk (sensor_index "{disk}_temp_N") and draws the
// warning/critical limit lines. $vars['disk'] is the pre-computed diskIndex.

use App\Facades\Rrd;
use App\Models\Sensor;

$diskIdx = (string) ($vars['disk'] ?? '');

$tempSensors = Sensor::where('device_id', $device['device_id'])
    ->whereIn('sensor_type', ['smart_temperature', 'smart_mib_temperature'])
    ->where('sensor_index', 'like', $diskIdx . '_temp_%')
    ->orderBy('sensor_descr')
    ->get();

$name = 'smart';
$unit_text = '°C';
$unitlen = 6;
$bigdescrlen = 25;
$smalldescrlen = 25;
$colours = 'mega';
$dostack = 0;
$printtotal = 0;
$addarea = 0;
$transparency = 15;

$rrd_list = [];
$warn = null;
$crit = null;
foreach ($tempSensors as $sensor) {
    $rrd_filename = Rrd::name($device['hostname'], ['sensor', $sensor->sensor_class, $sensor->sensor_type, $sensor->sensor_index]);
    if (! Rrd::checkRrdExists($rrd_filename)) {
        continue;
    }
    $rrd_list[] = [
        'filename' => $rrd_filename,
        'descr'    => $sensor->sensor_descr,
        'ds'       => 'sensor',
    ];
    // Use the tightest (lowest) warning / critical limit across the disk's sensors.
    if ($sensor->sensor_limit_warn !== null) {
        $warn = $warn === null ? (float) $sensor->sensor_limit_warn : min($warn, (float) $sensor->sensor_limit_warn);
    }
    if ($sensor->sensor_limit !== null) {
        $crit = $crit === null ? (float) $sensor->sensor_limit : min($crit, (float) $sensor->sensor_limit);
    }
}

if (empty($rrd_list)) {
    return;
}

// generic_multi_line_exact_numbers populates $rrd_options; the graph framework
// renders it after this include returns, so we can append the limit lines here.
require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';

if ($warn !== null) {
    $rrd_options[] = 'HRULE:' . $warn . '#ffa420:Warn ' . rtrim(rtrim(sprintf('%.1f', $warn), '0'), '.') . '°C:dashes';
}
if ($crit !== null) {
    $rrd_options[] = 'HRULE:' . $crit . '#ff0000:Crit ' . rtrim(rtrim(sprintf('%.1f', $crit), '0'), '.') . '°C:dashes';
}
