<?php

use App\Facades\Rrd;
use App\Models\Sensor;
use LibreNMS\Agent\Unix\Smart\HtmlData;

$tempSensors = Sensor::where('device_id', $device['device_id'])
    ->whereIn('sensor_type', ['smart_temperature', 'smart_mib_temperature'])
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
$addarea = 1;
$transparency = 15;

// Map MIB-driven temp sensors (sensor_index "{diskIdx}_temp_N") back to their
// disk so the legend can use the saved naming template / label mode — same
// as the per-disk views — instead of the raw discovery-time sensor_descr.
// Legacy rev1 "smart_temperature" sensors have no disk_key to map to, so
// they keep falling back to their stored sensor_descr.
$htmlData = isset($app) ? HtmlData::forDevice($app, $device) : null;
$diskByIdx = [];
$labelMode = 'device';
if ($htmlData !== null) {
    $labelCookie = 'smart_label_mode_' . $device['device_id'];
    $labelModes = $htmlData->labelModes();
    $labelMode = isset($_COOKIE[$labelCookie]) && isset($labelModes[$_COOKIE[$labelCookie]]) ? $_COOKIE[$labelCookie] : 'device';
    foreach ($htmlData->diskKeys() as $diskKey) {
        $diskByIdx[$htmlData->diskIndex($diskKey)] = $diskKey;
    }
}

$mibSensorCountByDiskIdx = [];
foreach ($tempSensors as $sensor) {
    if ($sensor->sensor_type === 'smart_mib_temperature' && preg_match('/^(.*)_temp_\d+$/', (string) $sensor->sensor_index, $m)) {
        $mibSensorCountByDiskIdx[$m[1]] = ($mibSensorCountByDiskIdx[$m[1]] ?? 0) + 1;
    }
}

$rrd_list = [];
foreach ($tempSensors as $sensor) {
    $rrd_filename = Rrd::name($device['hostname'], ['sensor', $sensor->sensor_class, $sensor->sensor_type, $sensor->sensor_index]);
    if (! Rrd::checkRrdExists($rrd_filename)) {
        continue;
    }

    $descr = $sensor->sensor_descr;
    if ($sensor->sensor_type === 'smart_mib_temperature' && preg_match('/^(.*)_temp_\d+$/', (string) $sensor->sensor_index, $m)) {
        $diskKey = $diskByIdx[$m[1]] ?? null;
        $disk = $diskKey !== null ? $htmlData->disk($diskKey) : null;
        if ($disk !== null) {
            $descr = $htmlData->displayLabel($disk, $labelMode);
            if (($mibSensorCountByDiskIdx[$m[1]] ?? 0) > 1) {
                $descr .= ' ' . $htmlData->shortSensorName($sensor, $disk);
            }
        }
    }

    $rrd_list[] = [
        'filename' => $rrd_filename,
        'descr'    => $descr,
        'ds'       => 'sensor',
    ];
}

if (empty($rrd_list)) {
    return;
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
