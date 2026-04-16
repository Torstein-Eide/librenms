<?php

use App\Facades\Rrd;
use LibreNMS\Exceptions\RrdGraphException;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart', $app->app_id, $vars['disk']]);
$attrId = isset($vars['attr_id']) ? (int) $vars['attr_id'] : 0;
if ($attrId <= 0) {
    throw new RrdGraphException('Missing SMART attribute id');
}

$scale_min = '0';
require 'includes/html/graphs/common.inc.php';

$graph_params->right_axis = '1:0';
$graph_params->right_axis_label = 'Normalized';
$graph_params->vertical_label = 'Raw';

if (! Rrd::checkRrdExists($rrd_filename)) {
    throw new RrdGraphException('No SMART attributes RRD file');
}

$dsRaw = 'id' . $attrId;
$dsNormalized = $dsRaw . 'Normalized';
$hasRaw = ($vars['has_raw'] ?? '0') === '1';
$hasNormalized = ($vars['has_norm'] ?? '0') === '1';
if (! $hasRaw && ! $hasNormalized) {
    throw new RrdGraphException('Requested SMART attribute not found in RRD');
}

$rrd_options[] = 'COMMENT:Series               Last      Min      Max\n';

$normalizedColor = session('applied_site_style') == 'dark' ? '#f2f2f2' : '#272b30';
$rawColor = '#ff9a9a';

if ($hasRaw) {
    $rrd_options[] = "DEF:raw={$rrd_filename}:{$dsRaw}:AVERAGE";
    $rrd_options[] = 'LINE1.5:raw' . $rawColor . ':Raw                ';
    $rrd_options[] = 'GPRINT:raw:LAST:%8.1lf';
    $rrd_options[] = 'GPRINT:raw:MIN:%8.1lf';
    $rrd_options[] = 'GPRINT:raw:MAX:%8.1lf\l';
}

if ($hasNormalized) {
    $rrd_options[] = "DEF:normalized={$rrd_filename}:{$dsNormalized}:AVERAGE";
    $rrd_options[] = 'LINE2:normalized' . $normalizedColor . ':Normalized         :dashes';
    $rrd_options[] = 'GPRINT:normalized:LAST:%8.1lf';
    $rrd_options[] = 'GPRINT:normalized:MIN:%8.1lf';
    $rrd_options[] = 'GPRINT:normalized:MAX:%8.1lf\l';
}

$thresh = $vars['attr_thresh'] ?? null;
if ($hasNormalized && is_numeric($thresh)) {
    $threshold = (float) $thresh;
    $rrd_options[] = 'COMMENT:Alert thresholds\:';
    $rrd_options[] = 'LINE1.5:' . $threshold . '#005bdf:low_warn = ' . rtrim(rtrim(number_format($threshold, 2, '.', ''), '0'), '.') . ':dashes';
}
