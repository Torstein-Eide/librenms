<?php

require_once base_path('includes/html/pages/btrfs-common.inc.php');

$name = 'btrfs';
$unit_text = 'errors/s';
$colours = 'psychedelic';
$dostack = 0;
$printtotal = 0;
$addarea = 0;
$transparency = 15;

$fs_param = $vars['fs'] ?? null;
if (! is_string($fs_param) || $fs_param === '') {
    return;
}

$dev = $vars['dev'] ?? null;
if (! is_string($dev) || $dev === '') {
    return;
}

$discovery_fs = \LibreNMS\Plugins\Btrfs\btrfs_get_discovery_by_uuid($app, $fs_param);
$fs_rrd_id = is_array($discovery_fs) ? ($discovery_fs['rrd_key'] ?? $fs_param) : $fs_param;
$fs = $fs_param;
$dev_rrd_id = $dev;
$rrd_filename = App\Facades\Rrd::name($device['hostname'], ['app', $name, $app->app_id, $fs_rrd_id . '_device_' . $dev_rrd_id]);

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
