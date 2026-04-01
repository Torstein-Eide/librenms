<?php

require_once base_path('includes/html/pages/btrfs-common.inc.php');

require 'includes/html/graphs/common.inc.php';

$name = 'btrfs';
$unit_text = 'errors';
$graph_params->scale_min = 0;
$graph_params->base = 1000;
$colours = 'psychedelic';

$fs_param = $vars['fs'] ?? null;
if (! is_string($fs_param) || $fs_param === '') {
    return;
}

$discovery_fs = \LibreNMS\Plugins\Btrfs\btrfs_get_discovery_by_uuid($app, $fs_param);
$fs_rrd_id = is_array($discovery_fs)
    ? ($discovery_fs['rrd_key'] ?? strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $fs), '_')))
    : strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $fs), '_'));
if (! is_string($fs_rrd_id) || $fs_rrd_id === '') {
    $fs_rrd_id = 'root';
}
$device_map = is_array($discovery_fs) ? ($discovery_fs['devices'] ?? []) : [];

if (! is_array($device_map) || count($device_map) === 0) {
    return;
}

$error_ds = [
    'io_t_corruption',
    'io_t_flush',
    'io_t_generation',
    'io_t_read',
    'io_t_write',
    'scrub_t_read',
    'scrub_t_csum',
    'scrub_t_verify',
    'scrub_t_uncorrectable',
    'scrub_t_unverified',
    'scrub_t_corrected',
];

$rrd_options[] = 'COMMENT:Device                       Now       Min       Max      Avg\\l';

$dev_index = 0;
foreach ($device_map as $dev_id => $dev_path) {
    $rrd_filename = App\Facades\Rrd::name($device['hostname'], ['app', $name, $app->app_id, $fs_rrd_id . '_device_' . $dev_id]);
    if (! App\Facades\Rrd::checkRrdExists($rrd_filename)) {
        continue;
    }

    $def_ids = [];
    foreach ($error_ds as $ds_index => $ds) {
        $def_id = 'd' . $dev_index . '_' . $ds_index;
        $safe_def_id = 'z' . $dev_index . '_' . $ds_index;
        $rrd_options[] = 'DEF:' . $def_id . '=' . $rrd_filename . ':' . $ds . ':AVERAGE';
        $rrd_options[] = 'CDEF:' . $safe_def_id . '=' . $def_id . ',UN,PREV,' . $def_id . ',IF';
        $def_ids[] = $safe_def_id;
    }

    $sum_expr = \LibreNMS\Plugins\Btrfs\build_sum_expr($def_ids);
    if ($sum_expr === null) {
        $dev_index++;
        continue;
    }

    $sum_id = 's' . $dev_index;
    $rrd_options[] = 'CDEF:' . $sum_id . '=' . $sum_expr;

    $colour = App\Facades\LibrenmsConfig::get("graph_colours.$colours.$dev_index") ?? '888888';
    $safe_descr = LibreNMS\Data\Store\Rrd::fixedSafeDescr((string) $dev_path, 24);
    $rrd_options[] = 'LINE1.25:' . $sum_id . '#' . $colour . ':' . $safe_descr;
    $rrd_options[] = 'GPRINT:' . $sum_id . ':LAST:%8.2lf%s';
    $rrd_options[] = 'GPRINT:' . $sum_id . ':MIN:%9.2lf%s';
    $rrd_options[] = 'GPRINT:' . $sum_id . ':MAX:%9.2lf%s';
    $rrd_options[] = 'GPRINT:' . $sum_id . ':AVERAGE:%9.2lf%s\\l';

    $dev_index++;
}

$rrd_options[] = 'HRULE:0#555555';
