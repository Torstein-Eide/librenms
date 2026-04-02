<?php

require 'includes/html/graphs/common.inc.php';

$scale_min = 0;
$colours = 'mixed';
$unit_text = 'Volts';
$unitlen = 10;
$bigdescrlen = 15;
$smalldescrlen = 15;
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 33;
$rrd_filename = Rrd::name($device['hostname'], ['app', 'ups-nut', $app->app_id]);
$array = [
    'out_voltage' => ['descr' => 'Output', 'colour' => '006699'],
    'in_voltage' => ['descr' => 'Input', 'colour' => '630606'],
];

$i = 0;

foreach ($array as $ds => $var) {
    $rrd_list[$i]['filename'] = $rrd_filename;
    $rrd_list[$i]['descr'] = $var['descr'];
    $rrd_list[$i]['ds'] = $ds;
    $rrd_list[$i]['colour'] = $var['colour'];
    $i++;
}

require 'includes/html/graphs/generic_v3_multiline_float.inc.php';
