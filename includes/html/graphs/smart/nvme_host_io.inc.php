<?php

// NVMe host read/write command counts, DERIVE rate (commands/s).
// Reads above axis (green), writes below axis (blue).
// Follows the same pattern as generic_multi_bits_separated but with correct units.

use App\Facades\Rrd;
use LibreNMS\Data\Store\Rrd as RrdStore;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart_nvme', $app->app_id, $vars['disk']]);

if (! Rrd::checkRrdExists($rrd_filename)) {
    return;
}
$point = Rrd::lastUpdate($rrd_filename);
$avail = ($point !== null && is_array($point->data ?? null)) ? array_keys($point->data) : [];
$hasRd = $avail === [] || in_array('host_rd', $avail, true);
$hasWr = $avail === [] || in_array('host_wr', $avail, true);
if (! $hasRd && ! $hasWr) {
    return;
}

// Allow negative Y values for writes rendered below the axis.
unset($vars['scale_min']);

$unit_text = 'commands/s';
require 'includes/html/graphs/common.inc.php';

$stacked = generate_stacked_graphs();

if ($width > 1500) {
    $descr_len = 40;
} elseif ($width >= 500) {
    $descr_len = 8 + min(40, (int) round(($width - 320) / 15));
} else {
    $descr_len = 8 + min(20, (int) round(($width - 260) / 9.5));
}

$colour_in = 'ffa420';
$colour_out = '9b59b6';

if ($width > 500) {
    $rrd_options[] = sprintf('COMMENT:%s', substr(str_pad($unit_text, $descr_len + 5), 0, $descr_len + 5));
    $rrd_options[] = sprintf('COMMENT:%12s', 'Current');
    $rrd_options[] = sprintf('COMMENT:%10s', 'Average');
    $rrd_options[] = sprintf("COMMENT:%10s\l", 'Maximum');
} else {
    $rrd_options[] = sprintf('COMMENT:%s', substr(str_pad($unit_text, $descr_len + 5), 0, $descr_len + 5));
    $rrd_options[] = sprintf('COMMENT:%12s', 'Now');
    $rrd_options[] = sprintf('COMMENT:%10s', 'Avg');
    $rrd_options[] = sprintf("COMMENT:%10s\l", 'Max');
}

$descr_rd = RrdStore::fixedSafeDescr('Read', $descr_len) . '  In';
$descr_wr = RrdStore::fixedSafeDescr('Write', $descr_len) . ' Out';

if ($hasRd) {
    $rrd_options[] = 'DEF:in0=' . $rrd_filename . ':host_rd:AVERAGE';
    $rrd_options[] = 'CDEF:inB0=in0,1,*';
    $rrd_options[] = 'AREA:inB0#' . $colour_in . $stacked['transparency'] . ':' . $descr_rd;
    $rrd_options[] = 'GPRINT:inB0:LAST:%6.2lf%s';
    $rrd_options[] = 'GPRINT:inB0:AVERAGE:%6.2lf%s';
    $rrd_options[] = "GPRINT:inB0:MAX:%6.2lf%s\l";
    $rrd_options[] = "COMMENT:\l";
}

if ($hasWr) {
    $rrd_options[] = 'DEF:out0=' . $rrd_filename . ':host_wr:AVERAGE';
    $rrd_options[] = 'CDEF:outB0=out0,1,*';
    $rrd_options[] = 'CDEF:outB0_neg=outB0,-1,*';
    $rrd_options[] = 'HRULE:0#' . $colour_out . ':' . $descr_wr;
    $rrd_options[] = 'GPRINT:outB0:LAST:%6.2lf%s';
    $rrd_options[] = 'GPRINT:outB0:AVERAGE:%6.2lf%s';
    $rrd_options[] = "GPRINT:outB0:MAX:%6.2lf%s\l";
    $rrd_options[] = "COMMENT:\l";
    $rrd_options[] = 'AREA:outB0_neg#' . $colour_out . $stacked['transparency'];
}

$rrd_options[] = 'HRULE:0#999999';
unset($stacked);
