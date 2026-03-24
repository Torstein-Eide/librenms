<?php

require 'includes/html/graphs/common.inc.php';

$name = 'btrfs';
$unit_text = 'status';
$colours = 'mixed';
$graph_params->scale_min = 0;
$graph_params->scale_max = 3;

$rrd_filename = App\Facades\Rrd::name($device['hostname'], ['app', $name, $app->app_id, 'overall']);

if (! App\Facades\Rrd::checkRrdExists($rrd_filename)) {
    throw new LibreNMS\Exceptions\RrdGraphException("No Data file $rrd_filename");
}

$rrd_options[] = 'DEF:all=' . $rrd_filename . ':status_code:AVERAGE';
$rrd_options[] = 'DEF:io=' . $rrd_filename . ':io_status_code:AVERAGE';
$rrd_options[] = 'DEF:scrub=' . $rrd_filename . ':scrub_status_code:AVERAGE';
$rrd_options[] = 'DEF:balance=' . $rrd_filename . ':balance_status_code:AVERAGE';

$rrd_options[] = 'LINE1.25:all#' . App\Facades\LibrenmsConfig::get("graph_colours.$colours.0") . ":'Overall'";
$rrd_options[] = 'LINE1.25:io#' . App\Facades\LibrenmsConfig::get("graph_colours.$colours.1") . ":'IO'";
$rrd_options[] = 'LINE1.25:scrub#' . App\Facades\LibrenmsConfig::get("graph_colours.$colours.2") . ":'Scrub'";
$rrd_options[] = 'LINE1.25:balance#' . App\Facades\LibrenmsConfig::get("graph_colours.$colours.3") . ":'Balance'";
