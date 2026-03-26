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

$fs_rrd_id = $app->data['fs_rrd_key'][$fs] ?? strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $fs), '_'));
if (! is_string($fs_rrd_id) || $fs_rrd_id === '') {
    $fs_rrd_id = 'root';
}
$device_map = $app->data['device_map'][$fs] ?? [];

if (! is_array($device_map) || count($device_map) === 0) {
    return;
}

$error_types = [
    'io_t_corruption' => 'IO Corruption',
    'io_t_flush' => 'IO Flush',
    'io_t_generation' => 'IO Generation',
    'io_t_read' => 'IO Read',
    'io_t_write' => 'IO Write',
    'scrub_t_read' => 'Scrub Read',
    'scrub_t_csum' => 'Scrub Csum',
    'scrub_t_verify' => 'Scrub Verify',
    'scrub_t_uncorrectable' => 'Scrub Uncorrectable',
    'scrub_t_unverified' => 'Scrub Unverified',
    'scrub_t_corrected' => 'Scrub Corrected',
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

$rrd_options[] = 'COMMENT:Type                         Now       Min       Max      Avg\l';

$type_index = 0;
foreach ($error_types as $ds => $descr) {
    $def_ids = [];
    $dev_index = 0;

    foreach ($device_map as $dev_id => $unused_dev_path) {
        $rrd_filename = \App\Facades\Rrd::name($device['hostname'], ['app', $name, $app->app_id, $fs_rrd_id, 'device_' . $dev_id]);
        if (! \App\Facades\Rrd::checkRrdExists($rrd_filename)) {
            continue;
        }

        $def_id = 'd' . $type_index . '_' . $dev_index;
        $safe_def_id = 'z' . $type_index . '_' . $dev_index;
        $rrd_options[] = 'DEF:' . $def_id . '=' . $rrd_filename . ':' . $ds . ':AVERAGE';
        $rrd_options[] = 'CDEF:' . $safe_def_id . '=' . $def_id . ',UN,PREV,' . $def_id . ',IF';
        $def_ids[] = $safe_def_id;
        $dev_index++;
    }

    $sum_expr = $build_sum_expr($def_ids);
    if ($sum_expr === null) {
        $type_index++;
        continue;
    }

    $sum_id = 's' . $type_index;
    $rrd_options[] = 'CDEF:' . $sum_id . '=' . $sum_expr;

    $colour = App\Facades\LibrenmsConfig::get("graph_colours.$colours.$type_index") ?? '888888';
    $safe_descr = LibreNMS\Data\Store\Rrd::fixedSafeDescr($descr, 24);
    $rrd_options[] = 'LINE1.25:' . $sum_id . '#' . $colour . ':' . $safe_descr;
    $rrd_options[] = 'GPRINT:' . $sum_id . ':LAST:%8.2lf%s';
    $rrd_options[] = 'GPRINT:' . $sum_id . ':MIN:%9.2lf%s';
    $rrd_options[] = 'GPRINT:' . $sum_id . ':MAX:%9.2lf%s';
    $rrd_options[] = 'GPRINT:' . $sum_id . ':AVERAGE:%9.2lf%s\\l';

    $type_index++;
}

$rrd_options[] = 'HRULE:0#555555';
