<?php

use App\Facades\Rrd;
use App\Models\Sensor;
use LibreNMS\Agent\Unix\Smart\HtmlData;

$name = 'smart';
$unit_text = '°C';
$unitlen = 6;
$bigdescrlen = 25;
$smalldescrlen = 25;
$colours = 'mega';
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 15;

// MIB-driven disks can report several temperature sensors (composite + a
// per-zone sensor each), but this overview graph shows one line per drive.
// It uses the same canonical per-disk sensor (HtmlData::temperatureSensor()) as
// the per-disk table/view, relabeled per the saved naming template / label
// mode. Legacy rev1 "smart_temperature" sensors have no disk_key to map to,
// so they keep falling back to their stored sensor_descr.
$htmlData = isset($app) ? HtmlData::forDevice($app, $device) : null;
$labelMode = 'device';
if ($htmlData !== null) {
    $labelCookie = 'smart_label_mode_' . $device['device_id'];
    $labelModes = $htmlData->labelModes();
    $labelMode = isset($_COOKIE[$labelCookie]) && isset($labelModes[$_COOKIE[$labelCookie]]) ? $_COOKIE[$labelCookie] : 'device';
}

$rrd_list = [];
$mibSensorIds = [];

if ($htmlData !== null) {
    foreach ($htmlData->diskKeys() as $diskKey) {
        $sensor = $htmlData->temperatureSensor($diskKey);
        if ($sensor === null) {
            continue;
        }

        $rrd_filename = Rrd::name($device['hostname'], ['sensor', $sensor->sensor_class, $sensor->sensor_type, $sensor->sensor_index]);
        if (! Rrd::checkRrdExists($rrd_filename)) {
            continue;
        }

        $disk = $htmlData->disk($diskKey);
        $rrd_list[] = [
            'filename' => $rrd_filename,
            'descr'    => $disk !== null ? $htmlData->displayLabel($disk, $labelMode) : $sensor->sensor_descr,
            'ds'       => 'sensor',
        ];
        $mibSensorIds[] = $sensor->sensor_id;
    }
}

$tempSensors = Sensor::where('device_id', $device['device_id'])
    ->where('sensor_type', 'smart_temperature')
    ->whereNotIn('sensor_id', $mibSensorIds)
    ->orderBy('sensor_descr')
    ->get();

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
}

if (empty($rrd_list)) {
    return;
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
