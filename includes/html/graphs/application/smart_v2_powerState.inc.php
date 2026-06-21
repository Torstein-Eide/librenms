<?php

// SMART live power-saving state. Values map to SmartmonDevicePowerState:
// 0 unknown, 1 active, 2 idleA, 3 idleB, 4 idleC, 5 standbyY,
// 6 standbyZ, 7 sleeping, 8 standby.

use App\Facades\Rrd;
use LibreNMS\Data\Store\Rrd as RrdStore;
use LibreNMS\Exceptions\RrdGraphException;

$rrdName = ($vars['rrd'] ?? '') === 'smart_nvme' ? 'smart_nvme' : 'smart';
$rrd_filename = Rrd::name($device['hostname'], ['app', $rrdName, $app->app_id, $vars['disk']]);

require 'includes/html/graphs/common.inc.php';

if (! Rrd::checkRrdExists($rrd_filename)) {
    throw new RrdGraphException('No SMART RRD file');
}

// Use listDatasets() (one-shot process reading the file header directly),
// not lastUpdate() — its persistent-pipe read can truncate the output for
// files with many DS (this RRD can carry 50+ SATA attribute DS plus
// power_state), making a present dataset look missing.
if (! in_array('power_state', Rrd::listDatasets($rrd_filename), true)) {
    throw new RrdGraphException('SMART power_state dataset not found in RRD');
}

$graph_params->vertical_label = 'Power state';
$graph_params->scale_min = 0;
$graph_params->scale_max = 8;
$graph_params->scale_rigid = true;

$rrd_options[] = 'DEF:state=' . $rrd_filename . ':power_state:AVERAGE';
$rrd_options[] = 'DEF:statemin=' . $rrd_filename . ':power_state:MIN';
$rrd_options[] = 'DEF:statemax=' . $rrd_filename . ':power_state:MAX';
$rrd_options[] = 'COMMENT:State                Last      Min      Max\n';

$descr = RrdStore::fixedSafeDescr('Power State', 20);
$rrd_options[] = 'AREA:state#7fc97f33';
$rrd_options[] = 'LINE2:state#1b7837:' . $descr;
$rrd_options[] = 'GPRINT:state:LAST:%8.0lf';
$rrd_options[] = 'GPRINT:statemin:MIN:%8.0lf';
$rrd_options[] = 'GPRINT:statemax:MAX:%8.0lf\l';

$powerStateLabels = [1 => 'Active', 2 => 'Idle A', 3 => 'Idle B', 4 => 'Idle C', 5 => 'Standby Y', 6 => 'Standby Z', 7 => 'Sleeping', 8 => 'Standby'];
$legend = implode('  ', array_map(static fn ($value, $label) => $value . '=' . $label, array_keys($powerStateLabels), $powerStateLabels));
$rrd_options[] = 'COMMENT:' . RrdStore::safeDescr($legend) . '\l';
