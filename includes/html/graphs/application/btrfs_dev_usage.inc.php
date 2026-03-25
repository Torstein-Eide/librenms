<?php

$name = 'btrfs';
$unit_text = 'bytes';
$colours = 'psychedelic';
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 15;

$fs_rrd_id = $app->data['fs_rrd_key'][$vars['fs']] ?? $vars['fs'];
$dev_rrd_id = $vars['dev'];
$rrd_filename = \App\Facades\Rrd::name($device['hostname'], ['app', $name, $app->app_id, $fs_rrd_id, 'device_' . $dev_rrd_id]);

$rrd_list = [
    [
        'filename' => $rrd_filename,
        'descr' => 'Device Size',
        'ds' => 'usage_size',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Unallocated',
        'ds' => 'usage_unallocated',
    ],
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
        'descr' => 'Device Slack',
        'ds' => 'usage_slack',
    ],
];

require 'includes/html/graphs/generic_multi_line.inc.php';
