<?php

use App\Facades\Rrd;
use LibreNMS\Agent\Unix\Smart\HtmlData;

$name = 'smart';
$unit_text = 'Time since';
$unit_label = 'h';
$unitlen = 6;
$bigdescrlen = 25;
$smalldescrlen = 25;
$colours = 'mega';
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 15;

// Hours since last Short self-test per disk, relabeled per the saved naming
// template / label mode, same approach as the all-temperatures overview graph.
$htmlData = isset($app) ? HtmlData::forDevice($app, $device) : null;
$labelMode = 'device';
if ($htmlData !== null) {
    $labelCookie = 'smart_label_mode_' . $device['device_id'];
    $labelModes = $htmlData->labelModes();
    $labelMode = isset($_COOKIE[$labelCookie]) && isset($labelModes[$_COOKIE[$labelCookie]]) ? $_COOKIE[$labelCookie] : 'device';
}

$rrd_list = [];

if ($htmlData !== null) {
    foreach ($htmlData->diskKeys() as $diskKey) {
        $sensor = $htmlData->selftestAgeSensor($diskKey, 'short');
        if ($sensor === null) {
            continue;
        }

        $rrd_filename = Rrd::name($device['hostname'], ['sensor', $sensor->sensor_class, $sensor->sensor_type, $sensor->sensor_index]);
        if (! Rrd::checkRrdExists($rrd_filename)) {
            continue;
        }

        $disk = $htmlData->disk($diskKey);
        $rrd_list[] = [
            'filename' => $rrd_filename,
            'descr'    => $disk !== null ? $htmlData->displayLabel($disk, $labelMode) : $sensor->sensor_descr,
            'ds'       => 'sensor',
        ];
    }
}

if (empty($rrd_list)) {
    return;
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
