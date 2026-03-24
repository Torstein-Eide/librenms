<?php

require 'includes/html/graphs/common.inc.php';

$colours = 'mixed';
$unit_text = 'Bytes';
$rrd_filename = Rrd::name($device['hostname'], ['app', 'btrfs', $app->app_id, 'overall']);

$array = [
    'used' => ['descr' => 'Used'],
    'free_estimated' => ['descr' => 'Free (Estimated)'],
    'device_size' => ['descr' => 'Device Size'],
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
