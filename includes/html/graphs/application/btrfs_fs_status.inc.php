<?php

require_once base_path('includes/html/pages/btrfs-common.inc.php');

require 'includes/html/graphs/common.inc.php';

$name = 'btrfs';
$unit_text = 'status';
$colours = 'mixed';
$graph_params->scale_min = 0;
$graph_params->scale_max = 3;

$fs_param = $vars['fs'] ?? null;
if (! is_string($fs_param) || $fs_param === '') {
    return;
}

$discovery_fs = \LibreNMS\Plugins\Btrfs\btrfs_get_discovery_by_uuid($app, $fs_param);
$fs = $fs_param;
$fs_rrd_id = is_array($discovery_fs) ? ($discovery_fs['rrd_key'] ?? $fs) : $fs;
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
