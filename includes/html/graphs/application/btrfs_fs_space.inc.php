<?php

require_once base_path('includes/html/pages/btrfs-common.inc.php');

$name = 'btrfs';
$unit_text = 'bytes';
$colours = 'psychedelic';
$printtotal = 0;
$nototal = 1;
$graph_params->scale_min = 0;

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
