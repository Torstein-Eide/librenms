<?php

// V2 self-test age graph — hours since last Short and Extended self-tests.
// Modelled on includes/html/graphs/sensor/runtime.inc.php.
// Reads from sensor RRDs written by the smart poller (not app RRDs).
// $vars['disk'] is the pre-computed diskIndex, set by renderDriveGraphs().

use App\Facades\Rrd;
use LibreNMS\Data\Store\Rrd as RrdStore;

$scale_min = '0';
require 'includes/html/graphs/common.inc.php';

$rrd_options[] = 'COMMENT:                         Last      Max\n';

$entries = [
    'smart_selftest_short' => ['label' => 'Short',    'colour' => 'cc0000'],
    'smart_selftest_long'  => ['label' => 'Extended', 'colour' => '0000cc'],
];

$i = 0;
foreach ($entries as $sensorType => $meta) {
    $sensorIndex = $vars['disk'] . '_' . substr($sensorType, strlen('smart_'));
    $rrdFile = Rrd::name($device['hostname'], ['sensor', 'runtime', $sensorType, $sensorIndex]);
    if (! Rrd::checkRrdExists($rrdFile)) {
        continue;
    }
    $id = 'ds' . $i;
    $descr = RrdStore::fixedSafeDescr($meta['label'], 20);
    $rrd_options[] = "DEF:{$id}={$rrdFile}:sensor:AVERAGE";
    $rrd_options[] = 'LINE1.5:' . $id . '#' . $meta['colour'] . ':' . $descr;
    $rrd_options[] = 'GPRINT:' . $id . ':LAST:%5.0lfh';
    $rrd_options[] = 'GPRINT:' . $id . ':MAX:%5.0lfh\l';
    $i++;
}
