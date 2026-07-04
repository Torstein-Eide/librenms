<?php

// Error count graph: SATA via smart RRD for all attributes whose name matches
// /error/i, each plotted as its own line. NVMe's equivalent is the
// 'media_errors' metric on nvme_attr_value.inc.php.

use App\Facades\Rrd;
use Illuminate\Support\Facades\DB;

$name = 'smart';
$unit_text = 'errors';
$unitlen = 20;
$bigdescrlen = 22;
$smalldescrlen = 22;
$colours = 'mega';
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 15;

$rrd_list = [];

$diskIdx = (string) ($vars['disk'] ?? '');
$mibDiskIndex = static fn (string $key): string => substr((string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key), 0, 80);

$diskKey = null;
foreach (DB::table('smart_devices')->where('app_id', $app->app_id)->get(['disk_key']) as $row) {
    if ($mibDiskIndex($row->disk_key) === $diskIdx) {
        $diskKey = $row->disk_key;
        break;
    }
}

if ($diskKey !== null) {
    $rrd_filename = Rrd::name($device['hostname'], ['app', 'smart', $app->app_id, $vars['disk']]);
    if (Rrd::checkRrdExists($rrd_filename)) {
        $avail = Rrd::listDatasets($rrd_filename);

        $errorAttrs = DB::table('smart_sata_attributes')
            ->where('app_id', $app->app_id)
            ->where('disk_key', $diskKey)
            ->whereRaw('LOWER(name) LIKE ?', ['%error%'])
            ->orderBy('attribute_id')
            ->get(['attribute_id', 'name']);

        foreach ($errorAttrs as $attr) {
            $dsBase = 'id' . (int) $attr->attribute_id;
            $dsHi   = $dsBase . 'Hi';
            if ($avail !== []) {
                if (in_array($dsBase, $avail, true)) {
                    $ds = $dsBase;
                } elseif (in_array($dsHi, $avail, true)) {
                    $ds = $dsHi;
                } else {
                    continue;
                }
            } else {
                $ds = $dsBase;
            }
            $rrd_list[] = [
                'filename' => $rrd_filename,
                'descr'    => str_replace('_', ' ', (string) $attr->name),
                'ds'       => $ds,
            ];
        }
    }
}

if (empty($rrd_list)) {
    return;
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
