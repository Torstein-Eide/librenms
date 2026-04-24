<?php

$ds_in = 'read';
$ds_out = 'written';

require 'includes/html/graphs/device/diskio_common.inc.php';

if (is_numeric($vars['id'] ?? null)) {
    $rrd_filename = $rrd_list[0]['filename'];
    $colour_area_in = 'CDEB8B';
    $colour_line_in = '006600';
    $colour_area_out = 'C3D9FF';
    $colour_line_out = '4096EE';
    $unit_text = 'Bytes/sec';

    require 'includes/html/graphs/generic_duplex.inc.php';
} else {
    $units = 'bps';
    $total_units = 'B';
    $colours_in = 'greens';
    $multiplier = '8';
    $colours_out = 'blues';
    $nototal = 1;
    $graph_params->title = strip_tags($title ?? '') . ' :: bps';

    foreach ($rrd_list as $index => $rrd) {
        $rrd_list[$index]['ds_in'] = $ds_in;
        $rrd_list[$index]['ds_out'] = $ds_out;
    }

    require 'includes/html/graphs/generic_multi_seperated.inc.php';
}
