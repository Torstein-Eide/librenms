<?php

$units = 'bps';
$total_units = 'B';
$colours_in = 'greens';
$multiplier = '8';
$colours_out = 'blues';

$nototal = 1;
$ds_in = 'read';
$ds_out = 'written';

require 'includes/html/graphs/device/diskio_common.inc.php';

foreach ($rrd_list as $index => $rrd) {
    $rrd_list[$index]['ds_in'] = $ds_in;
    $rrd_list[$index]['ds_out'] = $ds_out;
}

require 'includes/html/graphs/generic_multi_bits_separated.inc.php';
