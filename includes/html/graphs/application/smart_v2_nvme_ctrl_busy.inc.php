<?php

// NVMe controller busy time, rendered as % of poll interval.
// ctrl_busy is a DERIVE of accumulated minutes; ×6 000 (60 s/min × 100) = % of time.

use App\Facades\Rrd;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart_nvme', $app->app_id, $vars['disk']]);

$vertical_label = '% of time';
$scale_min = '0';
$scale_max = '100';

require 'includes/html/graphs/common.inc.php';

if (! Rrd::checkRrdExists($rrd_filename)) {
    return;
}
$point = Rrd::lastUpdate($rrd_filename);
$avail = ($point !== null && is_array($point->data ?? null)) ? array_keys($point->data) : [];
if ($avail !== [] && ! in_array('ctrl_busy', $avail, true)) {
    return;
}

$rrd_options[] = 'DEF:cb=' . $rrd_filename . ':ctrl_busy:AVERAGE';
$rrd_options[] = 'DEF:cbmn=' . $rrd_filename . ':ctrl_busy:MIN';
$rrd_options[] = 'DEF:cbmx=' . $rrd_filename . ':ctrl_busy:MAX';
$rrd_options[] = 'CDEF:cbp=cb,6000,*';
$rrd_options[] = 'CDEF:cbmnp=cbmn,6000,*';
$rrd_options[] = 'CDEF:cbmxp=cbmx,6000,*';
$rrd_options[] = 'COMMENT:                         Now      Min      Max      Avg\l';
$rrd_options[] = 'AREA:cbp#00b3d044:';
$rrd_options[] = 'LINE2:cbp#00b3d0:' . str_pad('Controller Busy', 22);
$rrd_options[] = 'GPRINT:cbp:LAST:%8.1lf%%';
$rrd_options[] = 'GPRINT:cbmnp:MIN:%8.1lf%%';
$rrd_options[] = 'GPRINT:cbmxp:MAX:%8.1lf%%';
$rrd_options[] = 'GPRINT:cbp:AVERAGE:%8.1lf%%\n';
$rrd_options[] = 'HRULE:100#66666688:100%:dashes';
