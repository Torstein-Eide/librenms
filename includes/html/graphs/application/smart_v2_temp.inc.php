<?php

// V2 temperature graph — reads from smart_power RRD (DS: temp)
// $vars['disk'] is the pre-computed diskIndex (safe chars only, max 80), set by renderDriveGraphs()

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart_power', $app->app_id, $vars['disk']]);

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
if (Rrd::checkRrdExists($rrd_filename)) {
    $rrd_list[] = [
        'filename' => $rrd_filename,
        'descr'    => 'Temperature (°C)',
        'ds'       => 'temp',
    ];
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
