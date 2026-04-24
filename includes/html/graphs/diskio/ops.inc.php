<?php

$ds_in = 'reads';
$ds_out = 'writes';

require 'includes/html/graphs/device/diskio_common.inc.php';

if (is_numeric($vars['id'] ?? null)) {
    $rrd_filename = $rrd_list[0]['filename'];
    $colour_area_in = 'FF3300';
    $colour_line_in = 'FF0000';
    $colour_area_out = 'FF6633';
    $colour_line_out = 'CC3300';
    $colour_area_in_max = 'FF6633';
    $colour_area_out_max = 'FF9966';
    $graph_max = 1;
    $unit_text = 'Ops/sec';

    require 'includes/html/graphs/generic_duplex.inc.php';
} else {
    $units_descr = 'Ops/sec';
    $multiplier = '1';
    $nototal = 1;
    $graph_params->title = strip_tags($title ?? '') . ' :: Ops/sec';

    $ops_in_colours = ['FF3300', 'FF4D1A', 'FF6633', 'FF8059'];
    $ops_out_colours = ['FF6633', 'FF7F50', 'FF9966', 'FFB380'];

    foreach ($rrd_list as $index => $rrd) {
        $rrd_list[$index]['ds_in'] = $ds_in;
        $rrd_list[$index]['ds_out'] = $ds_out;
        $colour_index = $index % count($ops_in_colours);
        $rrd_list[$index]['colour_area_in'] = $ops_in_colours[$colour_index];
        $rrd_list[$index]['colour_area_out'] = $ops_out_colours[$colour_index];
    }

    require 'includes/html/graphs/generic_multi_seperated.inc.php';
}
