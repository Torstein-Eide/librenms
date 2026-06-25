<?php

// Power-on hours: NVMe via smart_nvme/pwr_hours DS; SATA via smart/id9 DS (ATA attribute 9).

use App\Facades\Rrd;

$isNvme = ($vars['rrd'] ?? '') === 'smart_nvme';
$rrd_filename = Rrd::name($device['hostname'], ['app', $isNvme ? 'smart_nvme' : 'smart', $app->app_id, $vars['disk']]);
$ds = $isNvme ? 'pwr_hours' : 'id9';

$name = 'smart';
$unit_text = 'h';
$unitlen = 3;
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
        $rrd_list[] = ['filename' => $rrd_filename, 'descr' => 'Power-on Hours', 'ds' => $ds];
    }
}

if (empty($rrd_list)) {
    return;
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
