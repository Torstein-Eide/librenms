<?php

// Power cycle count: NVMe via smart_nvme/pwr_cycles DS; SATA via smart/id12 DS (ATA attribute 12).

use App\Facades\Rrd;

$isNvme = ($vars['rrd'] ?? '') === 'smart_nvme';
$rrd_filename = Rrd::name($device['hostname'], ['app', $isNvme ? 'smart_nvme' : 'smart', $app->app_id, $vars['disk']]);
$ds = $isNvme ? 'pwr_cycles' : 'id12';

$name = 'smart';
$unit_text = 'cycles';
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
    $avail = ($point !== null && is_array($point->data ?? null)) ? array_keys($point->data) : [];
    if ($avail === [] || in_array($ds, $avail, true)) {
        $rrd_list[] = ['filename' => $rrd_filename, 'descr' => 'Power Cycles', 'ds' => $ds];
    }
}

if (empty($rrd_list)) {
    return;
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
