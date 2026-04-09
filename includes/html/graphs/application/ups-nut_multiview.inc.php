<?php

use App\Models\Sensor;
use LibreNMS\Exceptions\RrdGraphException;

require_once 'includes/html/graphs/application/ups-nut-common.inc.php';
require 'includes/html/graphs/common.inc.php';

$view = is_string($vars['view'] ?? null) ? $vars['view'] : 'voltage';
$upsName = is_string($vars['nutups'] ?? null) ? $vars['nutups'] : null;

if ($upsName === null || $upsName === '') {
    throw new RrdGraphException('Missing UPS selector');
}

$viewMap = [
    'voltage' => [
        'unit_text' => 'Volts',
        'sensor_class' => 'voltage',
        'series' => [
            'output_voltage' => ['descr' => 'Output', 'colour' => '006699'],
            'input_voltage' => ['descr' => 'Input', 'colour' => '630606'],
            'battery_voltage' => ['descr' => 'Battery', 'colour' => '009933'],
            'bypass_voltage' => ['descr' => 'Bypass', 'colour' => '666666'],
        ],
    ],
    'frequency' => [
        'unit_text' => 'Hertz',
        'sensor_class' => 'frequency',
        'series' => [
            'output_frequency' => ['descr' => 'Output', 'colour' => 'cc6600'],
            'bypass_frequency' => ['descr' => 'Bypass', 'colour' => '666666'],
        ],
    ],
    'power' => [
        'unit_text' => 'Watts',
        'sensor_class' => 'power',
        'series' => [],
    ],
    'load' => [
        'unit_text' => 'Percent',
        'sensor_class' => 'load',
        'series' => [
            'load' => ['descr' => 'Load', 'colour' => '663399'],
        ],
    ],
    'charge' => [
        'unit_text' => 'Percent',
        'sensor_class' => 'charge',
        'series' => [
            'charge' => ['descr' => 'Charge', 'colour' => '339999'],
        ],
    ],
    'current' => [
        'unit_text' => 'Amps',
        'sensor_class' => 'current',
        'series' => [],
    ],
    'temperature' => [
        'unit_text' => 'Celsius',
        'sensor_class' => 'temperature',
        'series' => [
            'battery_temperature' => ['descr' => 'Battery', 'colour' => '009933'],
            'ups_temperature' => ['descr' => 'UPS', 'colour' => 'cc6600'],
            'ambient_temperature' => ['descr' => 'Ambient', 'colour' => '666699'],
        ],
    ],
];

$multiview = $viewMap[$view] ?? $viewMap['voltage'];
$sensorClass = $multiview['sensor_class'];
$prefixes = array_unique([
    strtolower($upsName) . '_',
    strtolower(str_replace('-', '_', $upsName)) . '_',
    strtolower(str_replace('_', '-', $upsName)) . '_',
    strtolower(str_replace([' ', '-'], '_', $upsName)) . '_',
]);
$groupFilter = is_string($vars['group'] ?? null) ? $vars['group'] : null;
$sensors = Sensor::where('device_id', $device['device_id'])
    ->where('sensor_class', $sensorClass)
    ->get();

$sensors = $sensors->filter(function ($sensor) use ($prefixes) {
    $sensorIndex = strtolower((string) $sensor->sensor_index);
    foreach ($prefixes as $prefix) {
        if (str_starts_with($sensorIndex, $prefix)) {
            return true;
        }
    }

    return false;
})->values();

if ($groupFilter) {
    $sensors = $sensors->filter(
        fn ($sensor) => upsNutGetSensorGroupKey($upsName, (string) $sensor->sensor_index) === $groupFilter
    )->values();
}


$sensors = $sensors->keyBy('sensor_index');

$colours = 'mixed';
$unit_text = $multiview['unit_text'];
$unitlen = 10;
$bigdescrlen = 15;
$smalldescrlen = 15;
$dostack = 0;
$printtotal = 0;
$addarea = 0;
$transparency = 33;
$array = $multiview['series'];
$rrd_list = [];

$i = 0;

if (in_array($view, ['voltage', 'frequency', 'power', 'current', 'temperature'], true)) {
    foreach ($sensors->sortBy('sensor_descr') as $sensor) {
        $rrd_filename = get_sensor_rrd($device, $sensor);
        if (! Rrd::checkRrdExists($rrd_filename)) {
            continue;
        }

        $rrd_list[$i]['filename'] = $rrd_filename;
        $rrd_list[$i]['descr'] = $sensor->sensor_descr;
        $rrd_list[$i]['ds'] = 'sensor';
        $rrd_list[$i]['colour'] = null;
        $i++;
    }
} else {
    foreach ($array as $indexSuffix => $var) {
        $sensor = null;
        foreach ($prefixes as $prefix) {
            $sensor = $sensors->get($prefix . $indexSuffix);
            if ($sensor) {
                break;
            }
        }
        if (! $sensor) {
            continue;
        }

        $rrd_filename = get_sensor_rrd($device, $sensor);
        if (! Rrd::checkRrdExists($rrd_filename)) {
            continue;
        }

        $rrd_list[$i]['filename'] = $rrd_filename;
        $rrd_list[$i]['descr'] = $var['descr'];
        $rrd_list[$i]['ds'] = 'sensor';
        $rrd_list[$i]['colour'] = $var['colour'];
        $i++;
    }
}

if (empty($rrd_list)) {
    throw new RrdGraphException('No matching sensor RRDs');
}

require 'includes/html/graphs/generic_v3_multiline_float.inc.php';
