<?php

$name = 'btrfs';
$unit_text = 'B/s';
$colours = 'mixed';
$dostack = 0;
$printtotal = 0;
$addarea = 0;
$transparency = 15;
$graph_params->scale_min = 0;

$fs_entry = $app->data['filesystems'][$vars['fs']] ?? null;
$fs_rrd_id = is_array($fs_entry) ? ($fs_entry['rrd_key'] ?? $vars['fs']) : $vars['fs'];
$rrd_filename = \App\Facades\Rrd::name($device['hostname'], ['app', $name, $app->app_id, $fs_rrd_id]);

$rrd_list = [
    [
        'filename' => $rrd_filename,
        'descr' => 'Scrub Rate',
        'ds' => 'scrub_bytes_scrubbe',
    ],
];

require 'includes/html/graphs/generic_multi_line.inc.php';
