<?php

use App\Facades\Rrd;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart_nvme', $app->app_id, $vars['disk']]);
$wearLimit = isset($vars['avail_spare_threshold']) && is_numeric($vars['avail_spare_threshold'])
    ? (int) $vars['avail_spare_threshold']
    : null;

$scale_min = '0';
$vertical_label = $wearLimit !== null ? 'Wear (spare limit ' . $wearLimit . '%)' : 'Wear (%)';

require 'includes/html/graphs/common.inc.php';

if (! Rrd::checkRrdExists($rrd_filename)) {
    return;
}

$point = Rrd::lastUpdate($rrd_filename);
$availableDs = ($point !== null && is_array($point->data ?? null)) ? array_keys($point->data) : [];

$dsMap = [
    'avail_spare' => ['label' => 'Available Spare %', 'colour' => '00b300'],
    'pct_used'    => ['label' => '% Used',            'colour' => 'cc0000'],
];

$stack = '';
$rrd_options[] = 'COMMENT:                         Now      Min      Max      Avg\l';

foreach ($dsMap as $ds => $info) {
    if ($availableDs !== [] && ! in_array($ds, $availableDs, true)) {
        continue;
    }

    $colour = $info['colour'];
    $label = str_pad($info['label'], 20);

    $rrd_options[] = "DEF:{$ds}={$rrd_filename}:{$ds}:AVERAGE";
    $rrd_options[] = "DEF:{$ds}min={$rrd_filename}:{$ds}:MIN";
    $rrd_options[] = "DEF:{$ds}max={$rrd_filename}:{$ds}:MAX";

    $rrd_options[] = "AREA:{$ds}#{$colour}BB:{$label}{$stack}";
    $rrd_options[] = "GPRINT:{$ds}:LAST:%8.1lf%%";
    $rrd_options[] = "GPRINT:{$ds}min:MIN:%8.1lf%%";
    $rrd_options[] = "GPRINT:{$ds}max:MAX:%8.1lf%%";
    $rrd_options[] = "GPRINT:{$ds}:AVERAGE:%8.1lf%%\\n";

    $stack = ':STACK';
}

if ($wearLimit !== null) {
    $rrd_options[] = 'HRULE:' . $wearLimit . '#005bdf:Available spare limit = ' . $wearLimit . '%\\l:dashes';
}
