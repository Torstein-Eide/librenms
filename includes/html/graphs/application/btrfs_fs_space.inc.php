<?php

$name = 'btrfs';
$unit_text = 'bytes';
$colours = 'psychedelic';
$printtotal = 0;
$nototal = 1;
$graph_params->scale_min = 0;

$fs_entry = $app->data['filesystems'][$vars['fs']] ?? null;
$fs_rrd_id = is_array($fs_entry) ? ($fs_entry['rrd_key'] ?? $vars['fs']) : $vars['fs'];
$rrd_filename = \App\Facades\Rrd::name($device['hostname'], ['app', $name, $app->app_id, $fs_rrd_id]);

$rrd_list = [
    [
        'filename' => $rrd_filename,
        'descr' => 'Data',
        'ds' => 'usage_data',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Metadata',
        'ds' => 'usage_metadata',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'System',
        'ds' => 'usage_system',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Unallocated',
        'ds' => 'usage_unallocated',
        'colour' => '99999955',
    ],
];

require 'includes/html/graphs/generic_multi_simplex_seperated.inc.php';
