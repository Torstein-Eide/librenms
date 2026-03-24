<?php

require 'includes/html/graphs/common.inc.php';

$colours = 'mixed';
$unit_text = 'errors';
$rrd_filename = Rrd::name($device['hostname'], ['app', 'btrfs', $app->app_id, 'overall']);

$array = [
    'io_errors_total' => ['descr' => 'IO Errors'],
    'scrub_errors_total' => ['descr' => 'Scrub Errors'],
];

$i = 0;
$rrd_list = [];
if (Rrd::checkRrdExists($rrd_filename)) {
    foreach ($array as $ds => $var) {
        $rrd_list[$i]['filename'] = $rrd_filename;
        $rrd_list[$i]['descr'] = $var['descr'];
        $rrd_list[$i]['ds'] = $ds;
        $rrd_list[$i]['colour'] = App\Facades\LibrenmsConfig::get("graph_colours.$colours.$i");
        $i++;
    }
} else {
    throw new LibreNMS\Exceptions\RrdGraphException("No Data file $rrd_filename");
}

require 'includes/html/graphs/generic_multi_line.inc.php';
