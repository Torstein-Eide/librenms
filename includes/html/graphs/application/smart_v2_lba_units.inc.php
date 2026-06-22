<?php

// SATA/SAS Total LBAs Written/Read (ATA attributes 241/242) — COUNTER rate (LBA/s).
// Reads above axis (green), writes below axis (blue). A second set of GPRINTs
// converts the same rate to B/s using the disk's logical block size.
// Follows the same visual pattern as smart_v2_nvme_data_units.

use App\Facades\Rrd;
use LibreNMS\Data\Store\Rrd as RrdStore;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart', $app->app_id, $vars['disk']]);

if (! Rrd::checkRrdExists($rrd_filename)) {
    return;
}
$point = Rrd::lastUpdate($rrd_filename);
$avail = ($point !== null && is_array($point->data ?? null)) ? array_keys($point->data) : [];
$hasRd = $avail === [] || in_array('id242', $avail, true);
$hasWr = $avail === [] || in_array('id241', $avail, true);
if (! $hasRd && ! $hasWr) {
    return;
}

$blockSize = isset($vars['block_size']) && is_numeric($vars['block_size']) ? (float) $vars['block_size'] : 512.0;

// Allow negative Y values for writes rendered below the axis.
unset($vars['scale_min']);

$unit_text = 'Logical block address';
$unit_label = 'LBA/S';
require 'includes/html/graphs/common.inc.php';

$stacked = generate_stacked_graphs();

if ($width > 1500) {
    $descr_len = 40;
} elseif ($width >= 500) {
    $descr_len = 8 + min(40, (int) round(($width - 320) / 15));
} else {
    $descr_len = 8 + min(20, (int) round(($width - 260) / 9.5));
}

$colour_in = App\Facades\LibrenmsConfig::get('graph_colours.greens.0');
$colour_out = App\Facades\LibrenmsConfig::get('graph_colours.blues.0');

if ($width > 500) {
    $rrd_options[] = sprintf('COMMENT:%s', substr(str_pad($unit_text . ' (B/s)', $descr_len + 5), 0, $descr_len + 5));
    $rrd_options[] = sprintf('COMMENT:%12s', 'Current');
    $rrd_options[] = sprintf('COMMENT:%10s', 'Average');
    $rrd_options[] = sprintf("COMMENT:%10s\l", 'Maximum');
} else {
    $rrd_options[] = sprintf('COMMENT:%s', substr(str_pad($unit_text . ' (B/s)', $descr_len + 5), 0, $descr_len + 5));
    $rrd_options[] = sprintf('COMMENT:%12s', 'Now');
    $rrd_options[] = sprintf('COMMENT:%10s', 'Avg');
    $rrd_options[] = sprintf("COMMENT:%10s\l", 'Max');
}

$descr_rd = RrdStore::fixedSafeDescr('Read', $descr_len) . '  In';
$descr_wr = RrdStore::fixedSafeDescr('Write', $descr_len) . ' Out';

if ($hasRd) {
    $rrd_options[] = 'DEF:in0=' . $rrd_filename . ':id242:AVERAGE';
    $rrd_options[] = 'CDEF:inB0=in0,' . $blockSize . ',*';
    $rrd_options[] = 'AREA:in0#' . $colour_in . $stacked['transparency'] . ':' . $descr_rd;
    $rrd_options[] = 'GPRINT:in0:LAST:%6.2lf%s';
    $rrd_options[] = 'GPRINT:in0:AVERAGE:%6.2lf%s';
    $rrd_options[] = 'GPRINT:in0:MAX:%6.2lf%s';
    $rrd_options[] = 'GPRINT:inB0:LAST:(%6.2lf%sB/s)';
    $rrd_options[] = 'GPRINT:inB0:AVERAGE:(%6.2lf%sB/s)';
    $rrd_options[] = "GPRINT:inB0:MAX:(%6.2lf%sB/s)\l";
}

if ($hasWr) {
    $rrd_options[] = 'DEF:out0=' . $rrd_filename . ':id241:AVERAGE';
    $rrd_options[] = 'CDEF:outB0=out0,' . $blockSize . ',*';
    $rrd_options[] = 'CDEF:out0_neg=out0,-1,*';
    $rrd_options[] = 'HRULE:0#' . $colour_out . ':' . $descr_wr;
    $rrd_options[] = 'GPRINT:out0:LAST:%6.2lf%s';
    $rrd_options[] = 'GPRINT:out0:AVERAGE:%6.2lf%s';
    $rrd_options[] = 'GPRINT:out0:MAX:%6.2lf%s';
    $rrd_options[] = 'GPRINT:outB0:LAST:(%6.2lf%sB/s)';
    $rrd_options[] = 'GPRINT:outB0:AVERAGE:(%6.2lf%sB/s)';
    $rrd_options[] = "GPRINT:outB0:MAX:(%6.2lf%sB/s)\l";
    $rrd_options[] = 'AREA:out0_neg#' . $colour_out . $stacked['transparency'];
}

$rrd_options[] = 'HRULE:0#999999';
unset($stacked);
