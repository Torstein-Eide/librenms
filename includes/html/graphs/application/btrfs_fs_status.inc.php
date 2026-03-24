<?php

require 'includes/html/graphs/common.inc.php';

$name = 'btrfs';
$unit_text = 'status';
$colours = 'mixed';
$graph_params->scale_min = 0;
$graph_params->scale_max = 3;

$fs = $vars['fs'] ?? null;
if (! is_string($fs) || $fs === '') {
    return;
}

$fs_rrd_id = $app->data['fs_rrd_key'][$fs] ?? $fs;
$rrd_filename = App\Facades\Rrd::name($device['hostname'], ['app', $name, $app->app_id, $fs_rrd_id]);

if (! App\Facades\Rrd::checkRrdExists($rrd_filename)) {
    return;
}

$rrd_options[] = 'DEF:io=' . $rrd_filename . ':io_status_code:AVERAGE';
$rrd_options[] = 'DEF:scrub=' . $rrd_filename . ':scrub_status_code:AVERAGE';
$rrd_options[] = 'DEF:balance=' . $rrd_filename . ':balance_status_code:AVERAGE';

$rrd_options[] = 'LINE1.25:io#' . App\Facades\LibrenmsConfig::get("graph_colours.$colours.0") . ":'IO'";
$rrd_options[] = 'LINE1.25:scrub#' . App\Facades\LibrenmsConfig::get("graph_colours.$colours.1") . ":'Scrub'";
$rrd_options[] = 'LINE1.25:balance#' . App\Facades\LibrenmsConfig::get("graph_colours.$colours.2") . ":'Balance'";
