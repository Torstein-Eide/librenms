<?php

// Error count graph: NVMe via smart_nvme/media_errors DS;
// SATA via smart RRD for all attributes whose name matches /error/i.

use App\Facades\Rrd;
use Illuminate\Support\Facades\DB;

$isNvme = ($vars['rrd'] ?? '') === 'smart_nvme';

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

if ($isNvme) {
    $rrd_filename = Rrd::name($device['hostname'], ['app', 'smart_nvme', $app->app_id, $vars['disk']]);
    if (Rrd::checkRrdExists($rrd_filename)) {
        $point = Rrd::lastUpdate($rrd_filename);
        $avail = ($point !== null && is_array($point->data ?? null)) ? array_keys($point->data) : [];
        if ($avail === [] || in_array('media_errors', $avail, true)) {
            $rrd_list[] = ['filename' => $rrd_filename, 'descr' => 'Media Errors', 'ds' => 'media_errors'];
        }
    }
} else {
    // SATA: find all attributes whose name contains "error" (case-insensitive)
    // and plot each as a separate line from the smart RRD.
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
            $point = Rrd::lastUpdate($rrd_filename);
            $avail = ($point !== null && is_array($point->data ?? null)) ? array_keys($point->data) : [];

            $errorAttrs = DB::table('smart_sata_attributes')
                ->where('app_id', $app->app_id)
                ->where('disk_key', $diskKey)
                ->whereRaw('LOWER(name) LIKE ?', ['%error%'])
                ->orderBy('attribute_id')
                ->get(['attribute_id', 'name']);

            foreach ($errorAttrs as $attr) {
                $ds = 'id' . (int) $attr->attribute_id;
                if ($avail !== [] && ! in_array($ds, $avail, true)) {
                    continue;
                }
                $rrd_list[] = [
                    'filename' => $rrd_filename,
                    'descr'    => str_replace('_', ' ', (string) $attr->name),
                    'ds'       => $ds,
                ];
            }
        }
    }
}

if (empty($rrd_list)) {
    return;
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
