<?php

// V2 power-on hours graph — reads from smart_power RRD (DS: hours)
// $vars['disk'] is the pre-computed diskIndex (safe chars only, max 80), set by renderDriveGraphs()

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart_power', $app->app_id, $vars['disk']]);

$name = 'smart';
$unit_text = '';
$unitlen = 20;
$bigdescrlen = 20;
$smalldescrlen = 20;
$colours = 'mega';
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 15;

$rrd_list = [];
if (Rrd::checkRrdExists($rrd_filename)) {
    $rrd_list[] = [
        'filename' => $rrd_filename,
        'descr'    => 'Power-on Hours',
        'ds'       => 'hours',
    ];
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
