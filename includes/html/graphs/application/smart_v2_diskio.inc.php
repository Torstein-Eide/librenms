<?php

// Fallback disk I/O graph (read/write bits) for a SMART disk, used on the Basic
// view in place of the Total LBAs Written/Read panel when the drive doesn't
// report ATA attributes 241/242. Sourced from ucd_diskio (UCD-DISKIO-MIB),
// matched to this disk's device name/path.

use Illuminate\Support\Facades\DB;
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

$rrd_list = app_diskio_build_rrd_list($device, [$candidates], 'disk');

$units = 'bps';
$total_units = 'B';
$colours_in = 'greens';
$multiplier = '8';
$colours_out = 'blues';
$nototal = 1;
$ds_in = 'read';
$ds_out = 'written';

foreach ($rrd_list as $i => $row) {
    $rrd_list[$i]['ds_in'] = $ds_in;
    $rrd_list[$i]['ds_out'] = $ds_out;
}

require 'includes/html/graphs/generic_multi_bits_separated.inc.php';
