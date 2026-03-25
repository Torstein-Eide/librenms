<?php

$units = '';
$units_descr = 'Operations/sec';
$total_units = 'B';
$colours_in = 'greens';
$multiplier = '1';
$colours_out = 'blues';

$ds_in = 'reads';
$ds_out = 'writes';

$nototal = 1;

require 'includes/html/graphs/application/btrfs_fs_diskio_common.inc.php';

foreach ($rrd_list as $index => $rrd) {
    $rrd_list[$index]['ds_in'] = $ds_in;
    $rrd_list[$index]['ds_out'] = $ds_out;
}

require 'includes/html/graphs/generic_multi_seperated.inc.php';
