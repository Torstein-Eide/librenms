<?php

// NVMe Critical Warning bitmask (Health Information Log, byte 0).
// Value 0 = no warnings. Non-zero bits indicate:
//   0x01 spare below threshold  0x02 temperature exceeded
//   0x04 reliability degraded   0x08 media read-only
//   0x10 volatile backup failed 0x20 PMR read-only

use App\Facades\Rrd;
use LibreNMS\Data\Store\Rrd as RrdStore;
use LibreNMS\Exceptions\RrdGraphException;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart_nvme', $app->app_id, $vars['disk']]);

require 'includes/html/graphs/common.inc.php';

if (! Rrd::checkRrdExists($rrd_filename)) {
    throw new RrdGraphException('No SMART NVMe RRD file');
}

if (! in_array('crit_warn', Rrd::listDatasets($rrd_filename), true)) {
    throw new RrdGraphException('crit_warn dataset not found in RRD');
}

$graph_params->vertical_label = 'Critical Warning (bitmask)';
$graph_params->scale_min = 0;

$rrd_options[] = 'DEF:cw=' . $rrd_filename . ':crit_warn:AVERAGE';
$rrd_options[] = 'DEF:cwmin=' . $rrd_filename . ':crit_warn:MIN';
$rrd_options[] = 'DEF:cwmax=' . $rrd_filename . ':crit_warn:MAX';
$rrd_options[] = 'COMMENT:' . RrdStore::fixedSafeDescr('', 20) . '     Last      Min      Max\n';

$rrd_options[] = 'AREA:cw#ff666633';
$rrd_options[] = 'LINE2:cw#cc0000:' . RrdStore::fixedSafeDescr('Critical Warning', 20);
$rrd_options[] = 'GPRINT:cw:LAST:%8.0lf';
$rrd_options[] = 'GPRINT:cwmin:MIN:%8.0lf';
$rrd_options[] = 'GPRINT:cwmax:MAX:%8.0lf\l';
$rrd_options[] = 'HRULE:0#00b300:OK (value = 0)\n';

$bits = [
    '0x01' => 'Spare below threshold',
    '0x02' => 'Temperature exceeded',
    '0x04' => 'Reliability degraded',
    '0x08' => 'Media read-only',
    '0x10' => 'Volatile backup failed',
    '0x20' => 'PMR read-only',
];
foreach ($bits as $mask => $label) {
    $rrd_options[] = 'COMMENT:' . RrdStore::safeDescr('  ' . $mask . ' = ' . $label) . '\n';
}
