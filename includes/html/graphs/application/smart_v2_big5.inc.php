<?php

// V2 reliability attributes graph — reads from smart (ATA attributes) RRD
// Covers the "Big 5" SMART attributes that indicate impending failure.
// $vars['disk'] is the pre-computed diskIndex (safe chars only, max 80), set by renderDriveGraphs()

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart', $app->app_id, $vars['disk']]);

$name = 'smart';
$unit_text = 'count';
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
    // Each disk's RRD only carries DS for the attribute IDs that disk actually
    // reports — listDatasets() reads the file header directly, so a disk
    // missing one of these "Big 5" attributes is skipped instead of failing
    // the graph with an "unknown DS" error.
    $availableDs = Rrd::listDatasets($rrd_filename);

    foreach ([
        'id5'   => 'Reallocated Sector Ct',
        'id187' => 'Reported Uncorrectable',
        'id188' => 'Command Timeout',
        'id197' => 'Current Pending Sector',
        'id198' => 'Offline Uncorrectable',
    ] as $ds => $descr) {
        if (! in_array($ds, $availableDs, true)) {
            continue;
        }

        $rrd_list[] = [
            'filename' => $rrd_filename,
            'descr'    => $descr,
            'ds'       => $ds,
        ];
    }
}

if (empty($rrd_list)) {
    return;
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
