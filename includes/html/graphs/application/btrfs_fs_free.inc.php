<?php

require_once base_path('includes/html/pages/btrfs-common.inc.php');

$name = 'btrfs';
$unit_text = 'bytes';
$colours = 'psychedelic';
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 15;

$fs_param = $vars['fs'] ?? null;
if (! is_string($fs_param) || $fs_param === '') {
    return;
}

$discovery_fs = \LibreNMS\Plugins\Btrfs\btrfs_get_discovery_by_uuid($app, $fs_param);
$fs_rrd_id = is_array($discovery_fs) ? ($discovery_fs['rrd_key'] ?? $fs_param) : $fs_param;
$rrd_filename = App\Facades\Rrd::name($device['hostname'], ['app', $name, $app->app_id, $fs_rrd_id]);

$rrd_list = [
    [
        'filename' => $rrd_filename,
        'descr' => 'Free (estimated)',
        'ds' => 'free_estimated',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Free (estimated min)',
        'ds' => 'free_estimated_min',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Free (statfs/df)',
        'ds' => 'free_statfs_df',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Missing Devices',
        'ds' => 'device_missing',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Device Slack',
        'ds' => 'device_slack',
    ],
];

require 'includes/html/graphs/generic_multi_line.inc.php';
