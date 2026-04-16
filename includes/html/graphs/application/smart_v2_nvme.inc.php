<?php

// V2 NVMe health graph — reads from smart_nvme RRD
// DS: pct_used, avail_spare, media_errors (percentage/count metrics)
// $vars['disk'] is the pre-computed diskIndex (safe chars only, max 80), set by renderDriveGraphs()

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart_nvme', $app->app_id, $vars['disk']]);

$name = 'smart';
$unit_text = '';
$unitlen = 20;
$bigdescrlen = 20;
$smalldescrlen = 20;
$colours = 'mega';
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 15;

$rrd_list = [];
if (Rrd::checkRrdExists($rrd_filename)) {
    foreach ([
        'pct_used'     => '% Used',
        'avail_spare'  => 'Available Spare %',
        'media_errors' => 'Media Errors',
    ] as $ds => $descr) {
        $rrd_list[] = [
            'filename' => $rrd_filename,
            'descr'    => $descr,
            'ds'       => $ds,
        ];
    }
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
