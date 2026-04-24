<?php

$diskio_rrd_base = 'ucd_diskio';

require 'includes/html/graphs/device/diskio_common.inc.php';

$unit_text = 'Disk Load';
$units = '%%';
$float_precision = 1;

if (is_numeric($vars['id'] ?? null)) {
    $rrd_filename = $rrd_list[0]['filename'];
    $graph_params->title = strip_tags($title ?? '') . ' :: Load Average';
    $rrd_list = [
        [
            'filename' => $rrd_filename,
            'descr' => '1m',
            'ds' => 'la1',
            'colour' => '3B82F6',
        ],
        [
            'filename' => $rrd_filename,
            'descr' => '5m',
            'ds' => 'la5',
            'colour' => '1D4ED8',
        ],
        [
            'filename' => $rrd_filename,
            'descr' => '15m',
            'ds' => 'la15',
            'colour' => '1E3A8A',
        ],
    ];
} else {
    $ds = in_array($vars['diskio_ds'] ?? '', ['la1', 'la5', 'la15']) ? $vars['diskio_ds'] : 'la1';

    $rrd_list = array_values(array_filter($rrd_list, fn ($rrd) => in_array($ds, App\Facades\Rrd::listDatasets($rrd['filename']))));
    if (empty($rrd_list)) {
        throw new LibreNMS\Exceptions\RrdGraphException('No drives with load average data');
    }

    $dsLabel = ['la1' => '1m', 'la5' => '5m', 'la15' => '15m'][$ds];
    $graph_params->title = strip_tags($title ?? '') . ' :: Load Average (' . $dsLabel . ')';
    foreach ($rrd_list as $index => $rrd) {
        $rrd_list[$index]['ds'] = $ds;
    }
    $colours = 'blues';
}

require 'includes/html/graphs/generic_multi_line.inc.php';
