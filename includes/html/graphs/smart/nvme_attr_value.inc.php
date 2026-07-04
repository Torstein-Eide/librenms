<?php

// Generic single-DS NVMe health-log metric grapher, the NVMe counterpart to
// sata_attr_value.inc.php: one template, $vars['metric'] selects the DS.
// See HtmlData::nvmeAttrMetrics() for the metric catalog (shared with
// auth.inc.php's metric-selector dropdown).

use App\Facades\Rrd;
use LibreNMS\Agent\Unix\Smart\HtmlData;
use LibreNMS\Data\Store\Rrd as RrdStore;
use LibreNMS\Exceptions\RrdGraphException;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart_nvme', $app->app_id, $vars['disk']]);

$metric = (string) ($vars['metric'] ?? '');
$metrics = HtmlData::nvmeAttrMetrics();
if (! isset($metrics[$metric])) {
    throw new RrdGraphException('Missing/unknown NVMe metric');
}
$spec = $metrics[$metric];

require 'includes/html/graphs/common.inc.php';

if (! Rrd::checkRrdExists($rrd_filename)) {
    throw new RrdGraphException('No SMART NVMe RRD file');
}

// listDatasets() (one-shot process reading the file header directly), not
// lastUpdate() -- see disk_power_state.inc.php for why lastUpdate()'s
// persistent-pipe read can truncate output on wide RRDs.
if (! in_array($metric, Rrd::listDatasets($rrd_filename), true)) {
    throw new RrdGraphException('Requested NVMe metric not found in RRD');
}

$graph_params->vertical_label = $spec['unit'];
$descr = RrdStore::fixedSafeDescr($spec['label'], 22);

switch ($spec['kind']) {
    case 'pct_time':
        // ctrl_busy is a DERIVE of accumulated minutes; x6000 (60s/min x 100) = % of time.
        $graph_params->scale_min = 0;
        $graph_params->scale_max = 100;
        $graph_params->scale_rigid = true;

        $rrd_options[] = "DEF:raw={$rrd_filename}:{$metric}:AVERAGE";
        $rrd_options[] = "DEF:rawmn={$rrd_filename}:{$metric}:MIN";
        $rrd_options[] = "DEF:rawmx={$rrd_filename}:{$metric}:MAX";
        $rrd_options[] = 'CDEF:val=raw,6000,*';
        $rrd_options[] = 'CDEF:valmn=rawmn,6000,*';
        $rrd_options[] = 'CDEF:valmx=rawmx,6000,*';
        $rrd_options[] = 'COMMENT:                         Now      Min      Max\l';
        $rrd_options[] = 'AREA:val#ffa42044:';
        $rrd_options[] = 'LINE2:val#ffa420:' . $descr;
        $rrd_options[] = 'GPRINT:val:LAST:%8.1lf%%';
        $rrd_options[] = 'GPRINT:valmn:MIN:%8.1lf%%';
        $rrd_options[] = 'GPRINT:valmx:MAX:%8.1lf%%\l';
        $rrd_options[] = 'HRULE:100#66666688:100%:dashes';

        break;
    case 'bitmask':
        // Critical Warning bitmask (Health Information Log, byte 0). Value 0 = no warnings.
        $graph_params->scale_min = 0;

        $rrd_options[] = "DEF:val={$rrd_filename}:{$metric}:AVERAGE";
        $rrd_options[] = "DEF:valmn={$rrd_filename}:{$metric}:MIN";
        $rrd_options[] = "DEF:valmx={$rrd_filename}:{$metric}:MAX";
        $rrd_options[] = 'COMMENT:                         Last      Min      Max\l';
        $rrd_options[] = 'AREA:val#ff666633';
        $rrd_options[] = 'LINE2:val#cc0000:' . $descr;
        $rrd_options[] = 'GPRINT:val:LAST:%8.0lf';
        $rrd_options[] = 'GPRINT:valmn:MIN:%8.0lf';
        $rrd_options[] = 'GPRINT:valmx:MAX:%8.0lf\l';
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

        break;
    default: // gauge
        $graph_params->scale_min = 0;

        $rrd_options[] = "DEF:val={$rrd_filename}:{$metric}:AVERAGE";
        $rrd_options[] = "DEF:valmn={$rrd_filename}:{$metric}:MIN";
        $rrd_options[] = "DEF:valmx={$rrd_filename}:{$metric}:MAX";
        $rrd_options[] = 'COMMENT:                         Now      Min      Max\l';
        $rrd_options[] = 'AREA:val#9a9aff44:';
        $rrd_options[] = 'LINE2:val#3a3aff:' . $descr;
        $rrd_options[] = 'GPRINT:val:LAST:%8.1lf%s';
        $rrd_options[] = 'GPRINT:valmn:MIN:%8.1lf%s';
        $rrd_options[] = 'GPRINT:valmx:MAX:%8.1lf%s\l';
}
