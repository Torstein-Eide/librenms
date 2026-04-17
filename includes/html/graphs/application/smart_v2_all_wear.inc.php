<?php

use App\Facades\Rrd;
use App\Models\Sensor;

$wearSensors = Sensor::where('device_id', $device['device_id'])
    ->where('sensor_type', 'smart_wear')
    ->orderBy('sensor_descr')
    ->get();

$name = 'smart';
$unit_text = '%';
$unitlen = 6;
$bigdescrlen = 25;
$smalldescrlen = 25;
$colours = 'mega';
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 15;

$rrd_list = [];
foreach ($wearSensors as $sensor) {
    $rrd_filename = Rrd::name($device['hostname'], ['sensor', $sensor->sensor_class, $sensor->sensor_type, $sensor->sensor_index]);
    if (Rrd::checkRrdExists($rrd_filename)) {
        $rrd_list[] = [
            'filename' => $rrd_filename,
            'descr'    => $sensor->sensor_descr,
            'ds'       => 'sensor',
        ];
    }
}

if (empty($rrd_list)) {
    return;
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
