<?php

// V2 NVMe health log graph — reads from smart_nvme RRD.
// DS translation table (RRDtool names are max 19 chars):
//   avail_spare   ← available_spare       (GAUGE 0-100 %)
//   pct_used      ← percentage_used       (GAUGE 0-100 %)
//   crit_warn     ← critical_warning      (GAUGE, bitmask)
//   ctrl_busy     ← controller_busy_time  (DERIVE, minutes)
//   crit_cmp_t    ← critical_comp_time    (DERIVE, minutes)
//   du_rd         ← data_units_read       (DERIVE, ×1000 × 512 B)
//   du_wr         ← data_units_written    (DERIVE, ×1000 × 512 B)
//   host_rd       ← host_reads            (DERIVE, commands)
//   host_wr       ← host_writes           (DERIVE, commands)
//   media_errors  ← media_errors          (GAUGE)
//   err_log_cnt   ← num_err_log_entries   (GAUGE)
//   pwr_cycles    ← power_cycles          (GAUGE)
//   pwr_hours     ← power_on_hours        (GAUGE)
//   unsafe_shut   ← unsafe_shutdowns      (GAUGE)
//   warn_tmp_t    ← warning_temp_time     (DERIVE, minutes)
//
// $vars['disk'] is the pre-computed diskIndex (safe chars only, max 80).

use App\Facades\Rrd;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart_nvme', $app->app_id, $vars['disk']]);
$metric = strtolower(trim((string) ($vars['metric'] ?? 'all')));

$name = 'smart';
$unit_text = '';
$unitlen = 20;
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
    $availableDs = [];
    if ($point !== null && is_array($point->data ?? null)) {
        $availableDs = array_keys($point->data);
    }

    $allDs = [
        'avail_spare'  => 'Available Spare %',
        'pct_used'     => '% Used',
        'crit_warn'    => 'Critical Warning',
        'media_errors' => 'Media Errors',
        'err_log_cnt'  => 'Error Log Entries',
        'pwr_cycles'   => 'Power Cycles',
        'pwr_hours'    => 'Power-on Hours',
        'unsafe_shut'  => 'Unsafe Shutdowns',
        'ctrl_busy'    => 'Ctrl Busy Time (min/s)',
        'crit_cmp_t'   => 'Crit Temp Time (min/s)',
        'warn_tmp_t'   => 'Warn Temp Time (min/s)',
        'du_rd'        => 'Data Units Read/s',
        'du_wr'        => 'Data Units Written/s',
        'host_rd'      => 'Host Reads/s',
        'host_wr'      => 'Host Writes/s',
    ];

    $groupDs = [
        'media_errors' => ['media_errors'],
        'data_units' => ['du_rd', 'du_wr'],
        'host_io' => ['host_rd', 'host_wr'],
        'controller_busy' => ['ctrl_busy'],
        'errors' => ['media_errors', 'err_log_cnt'],
        'power' => ['pwr_cycles', 'pwr_hours', 'unsafe_shut'],
        'temp_time' => ['warn_tmp_t', 'crit_cmp_t'],
    ];

    $selectedDs = $groupDs[$metric] ?? array_keys($allDs);

    foreach ($selectedDs as $ds) {
        $descr = $allDs[$ds] ?? null;
        if ($descr === null) {
            continue;
        }
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
