<?php

require 'includes/html/graphs/common.inc.php';

$name = 'btrfs';
$unit_text = 'errors';
$graph_params->scale_min = 0;
$graph_params->base = 1000;
$colours = 'psychedelic';

$fs = $vars['fs'] ?? null;
if (! is_string($fs) || $fs === '') {
    return;
}

$fs_rrd_id = $app->data['fs_rrd_key'][$fs] ?? $fs;
$device_map = $app->data['device_map'][$fs] ?? [];

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

$build_sum_expr = static function (array $ids): ?string {
    if (count($ids) === 0) {
        return null;
    }

    $expr = array_shift($ids);
    foreach ($ids as $id) {
        $expr .= ',' . $id . ',+';
    }

    return $expr;
};

$rrd_options[] = 'COMMENT:Device                       Now       Min       Max      Avg\\l';

$dev_index = 0;
foreach ($device_map as $dev_id => $dev_path) {
    $rrd_filename = \App\Facades\Rrd::name($device['hostname'], ['app', $name, $app->app_id, $fs_rrd_id, 'device_' . $dev_id]);
    if (! \App\Facades\Rrd::checkRrdExists($rrd_filename)) {
        continue;
    }

    $def_ids = [];
    foreach ($error_ds as $ds_index => $ds) {
        $def_id = 'd' . $dev_index . '_' . $ds_index;
        $rrd_options[] = 'DEF:' . $def_id . '=' . $rrd_filename . ':' . $ds . ':AVERAGE';
        $def_ids[] = $def_id;
    }

    $sum_expr = $build_sum_expr($def_ids);
    if ($sum_expr === null) {
        $dev_index++;
        continue;
    }

    $sum_id = 's' . $dev_index;
    $rrd_options[] = 'CDEF:' . $sum_id . '=' . $sum_expr;
    $rrd_options[] = 'VDEF:' . $sum_id . '_last=' . $sum_id . ',LAST';
    $rrd_options[] = 'VDEF:' . $sum_id . '_min=' . $sum_id . ',MINIMUM';
    $rrd_options[] = 'VDEF:' . $sum_id . '_max=' . $sum_id . ',MAXIMUM';
    $rrd_options[] = 'VDEF:' . $sum_id . '_avg=' . $sum_id . ',AVERAGE';

    $colour = App\Facades\LibrenmsConfig::get("graph_colours.$colours.$dev_index") ?? '888888';
    $safe_descr = LibreNMS\Data\Store\Rrd::fixedSafeDescr((string) $dev_path, 24);
    $rrd_options[] = 'LINE1.25:' . $sum_id . '#' . $colour . ':' . $safe_descr;
    $rrd_options[] = 'GPRINT:' . $sum_id . '_last:%8.2lf%s';
    $rrd_options[] = 'GPRINT:' . $sum_id . '_min:%9.2lf%s';
    $rrd_options[] = 'GPRINT:' . $sum_id . '_max:%9.2lf%s';
    $rrd_options[] = 'GPRINT:' . $sum_id . '_avg:%9.2lf%s\\l';

    $dev_index++;
}

$rrd_options[] = 'HRULE:0#555555';
