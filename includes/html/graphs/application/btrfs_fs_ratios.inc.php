<?php

$name = 'btrfs';
$unit_text = 'ratio';
$colours = 'psychedelic';
$dostack = 0;
$printtotal = 0;
$addarea = 0;
$transparency = 15;

$fs_rrd_id = $app->data['fs_rrd_key'][$vars['fs']] ?? $vars['fs'];
$rrd_filename = \App\Facades\Rrd::name($device['hostname'], ['app', $name, $app->app_id, $fs_rrd_id]);

$rrd_list = [
    [
        'filename' => $rrd_filename,
        'descr' => 'Data Ratio',
        'ds' => 'data_ratio',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Metadata Ratio',
        'ds' => 'metadata_ratio',
    ],
];

require 'includes/html/graphs/generic_multi_line.inc.php';
