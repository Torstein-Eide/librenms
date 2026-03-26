<?php

$name = 'btrfs';
$unit_text = 'errors';
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
        'descr' => 'Scrub Read',
        'ds' => 'scrub_t_read',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Scrub Csum',
        'ds' => 'scrub_t_csum',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Scrub Verify',
        'ds' => 'scrub_t_verify',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Scrub Uncorrectable',
        'ds' => 'scrub_t_uncorrectable',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Scrub Unverified',
        'ds' => 'scrub_t_unverified',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Scrub Corrected',
        'ds' => 'scrub_t_corrected',
    ],
];

require 'includes/html/graphs/generic_multi_line.inc.php';
