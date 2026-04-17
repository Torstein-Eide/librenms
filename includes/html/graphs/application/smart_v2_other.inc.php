<?php

// V2 additional ATA SMART attributes graph.
// $vars['disk'] is the pre-computed diskIndex (safe chars only, max 80).

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart', $app->app_id, $vars['disk']]);

$name = 'smart';
$unit_text = '';
$unitlen = 10;
$bigdescrlen = 18;
$smalldescrlen = 18;
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
        'id10'  => 'Spin Retry Count',
        'id183' => 'Runtime Bad Block',
        'id184' => 'End-to-End Error',
        'id196' => 'Reall Evnt Cnt',
        'id199' => 'UDMA CRC Err Count',
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

if (empty($rrd_list)) {
    return;
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
