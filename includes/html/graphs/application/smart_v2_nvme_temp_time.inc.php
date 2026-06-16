<?php

// NVMe temperature threshold time — warning and critical, as % of poll interval.
// warn_tmp_t / crit_cmp_t are DERIVE of accumulated minutes; ×6 000 = % of time.

use App\Facades\Rrd;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart_nvme', $app->app_id, $vars['disk']]);

$vertical_label = '% of time over threshold';
$scale_min = '0';
$scale_max = '100';

require 'includes/html/graphs/common.inc.php';

if (! Rrd::checkRrdExists($rrd_filename)) {
    return;
}
$point = Rrd::lastUpdate($rrd_filename);
$avail = ($point !== null && is_array($point->data ?? null)) ? array_keys($point->data) : [];

$dsMap = [
    'warn_tmp_t' => ['Warn Temp Time', 'ffa420'],
    'crit_cmp_t' => ['Crit Temp Time', 'ff4500'],
];

$hasAny = false;
$rrd_options[] = 'COMMENT:                         Now      Min      Max      Avg\l';

foreach ($dsMap as $ds => [$label, $colour]) {
    if ($avail !== [] && ! in_array($ds, $avail, true)) {
        continue;
    }
    $hasAny = true;
    // Short RRDtool variable names (no underscores needed, just short).
    $v = ($ds === 'warn_tmp_t') ? 'wtt' : 'cct';

    $rrd_options[] = "DEF:{$v}={$rrd_filename}:{$ds}:AVERAGE";
    $rrd_options[] = "DEF:{$v}mn={$rrd_filename}:{$ds}:MIN";
    $rrd_options[] = "DEF:{$v}mx={$rrd_filename}:{$ds}:MAX";
    $rrd_options[] = "CDEF:{$v}p={$v},6000,*";
    $rrd_options[] = "CDEF:{$v}mnp={$v}mn,6000,*";
    $rrd_options[] = "CDEF:{$v}mxp={$v}mx,6000,*";
    $rrd_options[] = "AREA:{$v}p#{$colour}44:";
    $rrd_options[] = "LINE2:{$v}p#{$colour}:" . str_pad($label, 22);
    $rrd_options[] = "GPRINT:{$v}p:LAST:%8.1lf%%";
    $rrd_options[] = "GPRINT:{$v}mnp:MIN:%8.1lf%%";
    $rrd_options[] = "GPRINT:{$v}mxp:MAX:%8.1lf%%";
    $rrd_options[] = "GPRINT:{$v}p:AVERAGE:%8.1lf%%\\n";
}

if (! $hasAny) {
    return;
}
$rrd_options[] = 'HRULE:100#66666688:100%:dashes';
