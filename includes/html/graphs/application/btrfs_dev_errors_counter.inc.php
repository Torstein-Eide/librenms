<?php

$name = 'btrfs';
$unit_text = 'errors';
$colours = 'psychedelic';
$dostack = 0;
$printtotal = 0;
$addarea = 0;
$transparency = 15;

$fs_rrd_id = $app->data['fs_rrd_key'][$vars['fs']] ?? $vars['fs'];
$dev_rrd_id = $app->data['dev_rrd_key'][$vars['fs']][$vars['dev']] ?? $vars['dev'];
$rrd_filename = \App\Facades\Rrd::name($device['hostname'], ['app', $name, $app->app_id, $fs_rrd_id, 'device_' . $dev_rrd_id]);

$rrd_list = [
    [
        'filename' => $rrd_filename,
        'descr' => 'Corruption',
        'ds' => 'io_t_corruption',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Flush IO',
        'ds' => 'io_t_flush',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Generation',
        'ds' => 'io_t_generation',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Read IO',
        'ds' => 'io_t_read',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Write IO',
        'ds' => 'io_t_write',
    ],
];

require 'includes/html/graphs/generic_multi_line.inc.php';
