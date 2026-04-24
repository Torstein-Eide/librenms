<?php

$diskio_rrd_base = 'ucd_diskio';

require 'includes/html/graphs/device/diskio_common.inc.php';

if (is_numeric($vars['id'] ?? null)) {
    $rrd_filename = $rrd_list[0]['filename'];
    $ds = 'busy_usec';
    $multiplier = '10000';
    $multiplier_action = '/';
    $unit_text = 'Busy Time';
    $line_text = 'Percent';
    $colour_area = 'FECACA';
    $colour_line = 'DC2626';
    $units = '%';
    $float_precision = 1;
    $graph_params->scale_min = 0;
    $graph_params->scale_max = 100;
    $graph_params->scale_rigid = true;
    $graph_params->title = strip_tags($title ?? '') . ' :: Busy';

    require 'includes/html/graphs/generic_simplex.inc.php';
} else {
    $rrd_list = array_values(array_filter($rrd_list, function ($rrd) {
        $ds = App\Facades\Rrd::listDatasets($rrd['filename']);

        return empty($ds) || in_array('busy_usec', $ds);
    }));
    if (empty($rrd_list)) {
        throw new LibreNMS\Exceptions\RrdGraphException('No drives with busy_usec data');
    }

    $unit_text = 'Busy Time';
    $units = '%';
    $float_precision = 1;
    $graph_params->scale_min = 0;
    $graph_params->scale_max = 100;
    $graph_params->scale_rigid = true;
    $graph_params->title = strip_tags($title ?? '') . ' :: Busy Time';
    $colours = 'reds';

    foreach ($rrd_list as $index => $rrd) {
        $rrd_list[$index]['ds'] = 'busy_usec';
        $rrd_list[$index]['cdef'] = '10000,/';
    }

    require 'includes/html/graphs/generic_multi_line.inc.php';
}
