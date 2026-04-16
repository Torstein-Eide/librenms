<?php

// V2 reliability attributes graph — reads from smart (ATA attributes) RRD
// Covers the "Big 5" SMART attributes that indicate impending failure.
// $vars['disk'] is the pre-computed diskIndex (safe chars only, max 80), set by renderDriveGraphs()

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart', $app->app_id, $vars['disk']]);

$name = 'smart';
$unit_text = '';
$unitlen = 10;
$bigdescrlen = 25;
$smalldescrlen = 25;
$colours = 'mega';
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 15;

$rrd_list = [];
if (Rrd::checkRrdExists($rrd_filename)) {
    $availableDs = [];
    $point = Rrd::lastUpdate($rrd_filename);
    if ($point !== null && is_array($point->data ?? null)) {
        $availableDs = array_keys($point->data);
    }

    foreach ([
        'id5'   => 'Reallocated Sector Ct',
        'id187' => 'Reported Uncorrectable',
        'id188' => 'Command Timeout',
        'id197' => 'Current Pending Sector',
        'id198' => 'Offline Uncorrectable',
    ] as $ds => $descr) {
        if ($availableDs !== [] && ! in_array($ds, $availableDs, true)) {
            continue;
        }

        $rrd_list[] = [
            'filename' => $rrd_filename,
            'descr'    => $descr,
            'ds'       => $ds,
        ];
    }
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
