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

$dev = $vars['dev'] ?? null;
if (! is_string($dev) || $dev === '') {
    return;
}

$discovery_fs = \LibreNMS\Plugins\Btrfs\btrfs_get_discovery_by_uuid($app, $fs_param);
$fs_rrd_id = is_array($discovery_fs) ? ($discovery_fs['rrd_key'] ?? $fs_param) : $fs_param;
$dev_rrd_id = $dev;
$rrd_filename = App\Facades\Rrd::name($device['hostname'], ['app', $name, $app->app_id, $fs_rrd_id . '_device_' . $dev_rrd_id]);

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
