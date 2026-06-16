<?php

// NVMe media errors and error log entry count — GAUGE counters.

use App\Facades\Rrd;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart_nvme', $app->app_id, $vars['disk']]);

$name         = 'smart';
$unit_text    = '';
$unitlen      = 20;
$bigdescrlen  = 22;
$smalldescrlen = 22;
$colours      = 'mega';
$dostack      = 0;
$printtotal   = 0;
$addarea      = 1;
$transparency = 15;

$rrd_list = [];
if (Rrd::checkRrdExists($rrd_filename)) {
    $point = Rrd::lastUpdate($rrd_filename);
    $avail = ($point !== null && is_array($point->data ?? null)) ? array_keys($point->data) : [];
    foreach (['media_errors' => 'Media Errors', 'err_log_cnt' => 'Error Log Entries'] as $ds => $descr) {
        if ($avail !== [] && ! in_array($ds, $avail, true)) {
            continue;
        }
        $rrd_list[] = ['filename' => $rrd_filename, 'descr' => $descr, 'ds' => $ds];
    }
}

if (empty($rrd_list)) {
    return;
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
