<?php

$name = 'btrfs';
$unit_text = 'errors/s';
$colours = 'psychedelic';
$dostack = 0;
$printtotal = 0;
$addarea = 0;
$transparency = 15;

$fs_entry = $app->data['filesystems'][$vars['fs']] ?? null;
$fs_rrd_id = is_array($fs_entry) ? ($fs_entry['rrd_key'] ?? $vars['fs']) : $vars['fs'];
$dev_rrd_id = $vars['dev'];
$rrd_filename = \App\Facades\Rrd::name($device['hostname'], ['app', $name, $app->app_id, $fs_rrd_id, 'device_' . $dev_rrd_id]);

$rrd_list = [
    [
        'filename' => $rrd_filename,
        'descr' => 'Corruption',
        'ds' => 'io_d_corruption',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Flush IO',
        'ds' => 'io_d_flush',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Generation',
        'ds' => 'io_d_generation',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Read IO',
        'ds' => 'io_d_read',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Write IO',
        'ds' => 'io_d_write',
    ],
];

require 'includes/html/graphs/generic_multi_line.inc.php';
