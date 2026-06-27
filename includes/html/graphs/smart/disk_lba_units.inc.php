<?php

// Disk throughput (B/s, SI-scaled): SATA Total LBAs Written/Read (attr 241/242)
// or NVMe Data Units Read/Written (1 DU = 512 000 B). Block size is read from
// the database (smart_sata_info.logical_block_size for SATA, 512 000 B fixed
// for NVMe). Reads above axis (green), writes below axis (blue).
// 95th-percentile lines drawn in red; footer shows cumulative bytes transferred.

use App\Facades\Rrd;
use LibreNMS\Data\Store\Rrd as RrdStore;

$isNvme = ($vars['rrd'] ?? '') === 'smart_nvme';

if ($isNvme) {
    $rrd_filename = Rrd::name($device['hostname'], ['app', 'smart_nvme', $app->app_id, $vars['disk']]);
    if (! Rrd::checkRrdExists($rrd_filename)) {
        return;
    }
    $point = Rrd::lastUpdate($rrd_filename);
    $avail = ($point !== null && is_array($point->data ?? null)) ? array_keys($point->data) : [];
    $hasRd = $avail === [] || in_array('du_rd', $avail, true);
    $hasWr = $avail === [] || in_array('du_wr', $avail, true);
    $dsRd = 'du_rd';
    $dsWr = 'du_wr';
    $blockSize = 512000.0;
} else {
    $rrd_filename = Rrd::name($device['hostname'], ['app', 'smart', $app->app_id, $vars['disk']]);
    if (! Rrd::checkRrdExists($rrd_filename)) {
        return;
    }
    $point = Rrd::lastUpdate($rrd_filename);
    $avail = ($point !== null && is_array($point->data ?? null)) ? array_keys($point->data) : [];
    $hasRd = $avail === [] || in_array('id242', $avail, true);
    $hasWr = $avail === [] || in_array('id241', $avail, true);
    $dsRd = 'id242';
    $dsWr = 'id241';
    $blockSize = (float) (($disk['info']['logical_block_size'] ?? null) ?: 512);
}

if (! $hasRd && ! $hasWr) {
    return;
}

$pct = App\Facades\LibrenmsConfig::get('percentile_value');

// Allow negative Y values for writes rendered below the axis.
unset($vars['scale_min']);

$unit_text = 'B/s';
require 'includes/html/graphs/common.inc.php';

$stacked = generate_stacked_graphs();

$descr_len = 5; // labels are always "Read"/"Write" — no disk name in the description

$colour_in  = App\Facades\LibrenmsConfig::get('graph_colours.greens.0');
$colour_out = App\Facades\LibrenmsConfig::get('graph_colours.blues.0');

if ($width > 500) {
    $rrd_options[] = sprintf('COMMENT:%s', substr(str_pad('B/s', $descr_len + 5), 0, $descr_len + 5));
    $rrd_options[] = sprintf('COMMENT:%12s', 'Current');
    $rrd_options[] = sprintf('COMMENT:%10s', 'Average');
    $rrd_options[] = sprintf('COMMENT:%10s', 'Maximum');
    $rrd_options[] = sprintf("COMMENT:%10s\l", $pct . 'th %');
} else {
    $rrd_options[] = sprintf('COMMENT:%s', substr(str_pad('B/s', $descr_len + 5), 0, $descr_len + 5));
    $rrd_options[] = sprintf('COMMENT:%12s', 'Now');
    $rrd_options[] = sprintf('COMMENT:%10s', 'Avg');
    $rrd_options[] = sprintf('COMMENT:%10s', 'Max');
    $rrd_options[] = sprintf("COMMENT:%10s\l", $pct . '%');
}

$descr_rd = RrdStore::fixedSafeDescr('Read', $descr_len) . '  In';
$descr_wr = RrdStore::fixedSafeDescr('Write', $descr_len) . ' Out';

if ($hasRd) {
    $rrd_options[] = 'DEF:in0=' . $rrd_filename . ':' . $dsRd . ':AVERAGE';
    $rrd_options[] = 'DEF:in0_max=' . $rrd_filename . ':' . $dsRd . ':MAX';
    $rrd_options[] = 'CDEF:inBs=in0,' . $blockSize . ',*';
    $rrd_options[] = 'CDEF:inBs_max=in0_max,' . $blockSize . ',*';
    $rrd_options[] = 'VDEF:inBsLast=inBs,LAST';
    $rrd_options[] = 'VDEF:inBsAvg=inBs,AVERAGE';
    $rrd_options[] = 'VDEF:inBsMax=inBs_max,MAXIMUM';
    $rrd_options[] = 'VDEF:percentile_in=inBs,' . $pct . ',PERCENT';
    // Expand the scalar P95 into a constant time series for reliable LINE1 rendering.
    $rrd_options[] = 'CDEF:pct_in_line=inBs,inBs,-,percentile_in,+';
    $rrd_options[] = 'VDEF:percentile_in_draw=pct_in_line,FIRST';
    $rrd_options[] = 'VDEF:totin=inBs,TOTAL';
    $rrd_options[] = 'AREA:inBs#' . $colour_in . $stacked['transparency'] . ':' . $descr_rd;
    $rrd_options[] = 'GPRINT:inBsLast:%6.2lf%sB/s';
    $rrd_options[] = 'GPRINT:inBsAvg:%6.2lf%sB/s';
    $rrd_options[] = 'GPRINT:inBsMax:%6.2lf%sB/s';
    $rrd_options[] = "GPRINT:percentile_in:%6.2lf%sB/s\l";
}

if ($hasWr) {
    $rrd_options[] = 'DEF:out0=' . $rrd_filename . ':' . $dsWr . ':AVERAGE';
    $rrd_options[] = 'DEF:out0_max=' . $rrd_filename . ':' . $dsWr . ':MAX';
    $rrd_options[] = 'CDEF:outBs=out0,' . $blockSize . ',*';
    $rrd_options[] = 'CDEF:outBs_max=out0_max,' . $blockSize . ',*';
    $rrd_options[] = 'CDEF:outBs_neg=outBs,-1,*';
    $rrd_options[] = 'VDEF:outBsLast=outBs,LAST';
    $rrd_options[] = 'VDEF:outBsAvg=outBs,AVERAGE';
    $rrd_options[] = 'VDEF:outBsMax=outBs_max,MAXIMUM';
    $rrd_options[] = 'VDEF:percentile_out=outBs,' . $pct . ',PERCENT';
    // Expand the scalar P95 into a constant time series at -P95 so LINE1 can draw it below axis.
    // Pattern mirrors generic_data.inc.php: subtract-self gives 0, then subtract P95 (stacked=-1).
    $rrd_options[] = 'CDEF:pct_out_line=outBs_neg,outBs_neg,-,percentile_out,-1,*,+';
    $rrd_options[] = 'VDEF:percentile_out_neg=pct_out_line,FIRST';
    $rrd_options[] = 'VDEF:totout=outBs,TOTAL';
    $rrd_options[] = 'HRULE:0#' . $colour_out . ':' . $descr_wr;
    $rrd_options[] = 'GPRINT:outBsLast:%6.2lf%sB/s';
    $rrd_options[] = 'GPRINT:outBsAvg:%6.2lf%sB/s';
    $rrd_options[] = 'GPRINT:outBsMax:%6.2lf%sB/s';
    $rrd_options[] = "GPRINT:percentile_out:%6.2lf%sB/s\l";
    $rrd_options[] = 'AREA:outBs_neg#' . $colour_out . $stacked['transparency'];
}

// 95th-percentile lines drawn in red (matching generic_data / port bits style).
if ($hasRd) {
    $rrd_options[] = 'LINE1:percentile_in_draw#aa0000';
}
if ($hasWr) {
    $rrd_options[] = 'LINE1:percentile_out_neg#aa0000';
}

// Footer: cumulative bytes transferred over the graphed period.
if ($hasRd && $hasWr) {
    $rrd_options[] = 'CDEF:totalBs=inBs,outBs,+';
    $rrd_options[] = 'VDEF:tot=totalBs,TOTAL';
    $rrd_options[] = 'GPRINT:tot:Total %6.2lf%sB';
    $rrd_options[] = 'GPRINT:totin:(In %6.2lf%sB';
    $rrd_options[] = "GPRINT:totout:Out %6.2lf%sB)\l";
} elseif ($hasRd) {
    $rrd_options[] = "GPRINT:totin:Total (In) %6.2lf%sB\l";
} elseif ($hasWr) {
    $rrd_options[] = "GPRINT:totout:Total (Out) %6.2lf%sB\l";
}

$rrd_options[] = 'HRULE:0#999999';
unset($stacked);
