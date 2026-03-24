<?php

$name = 'btrfs';
$unit_text = 'errors/s';
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
        'descr' => 'Scrub Read',
        'ds' => 'scrub_c_read',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Scrub Csum',
        'ds' => 'scrub_c_csum',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Scrub Verify',
        'ds' => 'scrub_c_verify',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Scrub Uncorrectable',
        'ds' => 'scrub_c_uncorrectable',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Scrub Unverified',
        'ds' => 'scrub_c_unverified',
    ],
    [
        'filename' => $rrd_filename,
        'descr' => 'Scrub Corrected',
        'ds' => 'scrub_c_corrected',
    ],
];

require 'includes/html/graphs/generic_multi_line.inc.php';
