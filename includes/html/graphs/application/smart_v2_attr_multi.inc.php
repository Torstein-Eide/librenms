<?php

use App\Facades\Rrd;

$attrId = isset($vars['attr_id']) ? (int) $vars['attr_id'] : 0;
if ($attrId <= 0) {
    return;
}

$dsName = 'id' . $attrId;
$disks  = Rrd::getRrdApplicationArrays($device, $app->app_id, 'smart');

$name = 'smart';
$unit_text = '';
$unitlen = 10;
$bigdescrlen = 25;
$smalldescrlen = 25;
$colours = 'mega';
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 15;

$rrd_list = [];
foreach ($disks as $disk) {
    $rrd_filename = Rrd::name($device['hostname'], ['app', 'smart', $app->app_id, $disk]);
    if (! Rrd::checkRrdExists($rrd_filename)) {
        continue;
    }

    $point = Rrd::lastUpdate($rrd_filename);
    if ($point === null || ! is_array($point->data ?? null)) {
        continue;
    }

    if (! array_key_exists($dsName, $point->data)) {
        continue;
    }

    $rrd_list[] = [
        'filename' => $rrd_filename,
        'descr'    => $disk,
        'ds'       => $dsName,
    ];
}

if (empty($rrd_list)) {
    return;
}

$scale_min = '0';
require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
