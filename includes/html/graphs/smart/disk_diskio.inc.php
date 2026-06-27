<?php

// Fallback disk I/O graph (read/write B/s) for a SMART disk, used on the Basic
// view in place of the Total LBAs Written/Read panel when the drive doesn't
// report ATA attributes 241/242. Sourced from ucd_diskio (UCD-DISKIO-MIB),
// matched to this disk's device name/path.
// 95th-percentile lines drawn in red; footer shows cumulative bytes transferred.

use App\Facades\LibrenmsConfig;
use App\Facades\Rrd;
use Illuminate\Support\Facades\DB;
use LibreNMS\Data\Store\Rrd as RrdStore;
use LibreNMS\Exceptions\RrdGraphException;

require 'includes/html/graphs/common.inc.php';
require 'includes/html/graphs/application/app_diskio_common.inc.php';

$diskIdx = (string) ($vars['disk'] ?? '');
if ($diskIdx === '') {
    throw new RrdGraphException('Missing disk');
}

// Mirrors LibreNMS\Agent\Module\Smart\Common::mibDiskIndex(). The disk_key is
// sanitized into the same safe-character index used everywhere else in the app.
$mibDiskIndex = static fn (string $key): string => substr((string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key), 0, 80);

$diskRow = null;
foreach (DB::table('smart_devices')->where('app_id', $app->app_id)->get(['disk_key', 'device_name', 'device_path']) as $row) {
    if ($mibDiskIndex($row->disk_key) === $diskIdx) {
        $diskRow = $row;
        break;
    }
}
if ($diskRow === null) {
    throw new RrdGraphException('Unknown disk');
}

$candidates = [];
foreach ([$diskRow->device_path, $diskRow->device_name] as $cand) {
    $cand = trim((string) $cand);
    if ($cand === '') {
        continue;
    }
    $candidates[] = $cand;
    $candidates[] = ltrim((string) preg_replace('#^/dev/#', '', $cand), '/');
    $candidates[] = basename($cand);
}
$candidates = array_values(array_unique($candidates));
if ($candidates === []) {
    throw new RrdGraphException('No device name for disk');
}

// NVMe: the controller device (e.g. nvme0) lives in smart_devices, but
// ucd_diskio tracks the namespace device (e.g. nvme0n1). Find matching
// namespace entries and prepend them so they are preferred over the
// controller name, which ucd_diskio never sees.
if (($vars['rrd'] ?? '') === 'smart_nvme') {
    $nsExtras = [];
    foreach ($candidates as $cand) {
        foreach (DB::table('ucd_diskio')
            ->where('device_id', $device['device_id'])
            ->where('diskio_descr', 'like', $cand . 'n%')
            ->orderBy('diskio_descr')
            ->pluck('diskio_descr') as $descr) {
            $nsExtras[] = (string) $descr;
        }
    }
    $candidates = array_values(array_unique(array_merge($nsExtras, $candidates)));
}

$rrd_list = app_diskio_build_rrd_list($device, [$candidates], 'disk');
$rrd_filename = $rrd_list[0]['filename'];

$pct = LibrenmsConfig::get('percentile_value');

// Allow negative Y values for writes rendered below the axis.
unset($vars['scale_min']);

$stacked = generate_stacked_graphs();

$descr_len = 5; // labels are always "Read"/"Write" — no disk name in the description

$colour_in  = LibrenmsConfig::get('graph_colours.greens.0');
$colour_out = LibrenmsConfig::get('graph_colours.blues.0');

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

// ucd_diskio DS 'read' is already in bytes — no block-size multiplication needed.
$rrd_options[] = 'DEF:in0=' . $rrd_filename . ':read:AVERAGE';
$rrd_options[] = 'DEF:in0_max=' . $rrd_filename . ':read:MAX';
$rrd_options[] = 'CDEF:inBs=in0,1,*';
$rrd_options[] = 'CDEF:inBs_max=in0_max,1,*';
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

// ucd_diskio DS 'written' is already in bytes.
$rrd_options[] = 'DEF:out0=' . $rrd_filename . ':written:AVERAGE';
$rrd_options[] = 'DEF:out0_max=' . $rrd_filename . ':written:MAX';
$rrd_options[] = 'CDEF:outBs=out0,1,*';
$rrd_options[] = 'CDEF:outBs_max=out0_max,1,*';
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

// 95th-percentile lines drawn in red (matching generic_data / port bits style).
$rrd_options[] = 'LINE1:percentile_in_draw#aa0000';
$rrd_options[] = 'LINE1:percentile_out_neg#aa0000';

// Footer: cumulative bytes transferred over the graphed period.
$rrd_options[] = 'CDEF:totalBs=inBs,outBs,+';
$rrd_options[] = 'VDEF:tot=totalBs,TOTAL';
$rrd_options[] = 'GPRINT:tot:Total %6.2lf%sB';
$rrd_options[] = 'GPRINT:totin:(In %6.2lf%sB';
$rrd_options[] = "GPRINT:totout:Out %6.2lf%sB)\l";

$rrd_options[] = 'HRULE:0#999999';
unset($stacked);
