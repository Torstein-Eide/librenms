<?php

// NVMe power-on hours, GAUGE counter.

use App\Facades\Rrd;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart_nvme', $app->app_id, $vars['disk']]);

$name = 'smart';
$unit_text = 'h';
$unitlen = 3;
$bigdescrlen = 22;
$smalldescrlen = 22;
$colours = 'mega';
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 15;

$rrd_list = [];
if (Rrd::checkRrdExists($rrd_filename)) {
    $point = Rrd::lastUpdate($rrd_filename);
    $avail = ($point !== null && is_array($point->data ?? null)) ? array_keys($point->data) : [];
    if ($avail === [] || in_array('pwr_hours', $avail, true)) {
        $rrd_list[] = ['filename' => $rrd_filename, 'descr' => 'Power-on Hours', 'ds' => 'pwr_hours'];
    }
}

if (empty($rrd_list)) {
    return;
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
