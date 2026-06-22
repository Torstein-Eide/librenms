<?php

// Live power-saving state per disk, read directly from each disk's app RRD
// (power_state is an app-level dataset, not a SENSOR-MIB sensor — see
// includes/html/graphs/application/smart_v2_powerState.inc.php for the
// per-disk single-line version and its state-code legend).
// Values map to SmartmonDevicePowerState: 0 unknown, 1 active, 2 idleA,
// 3 idleB, 4 idleC, 5 standbyY, 6 standbyZ, 7 sleeping, 8 standby.

use App\Facades\Rrd;
use LibreNMS\Agent\Unix\Smart\HtmlData;

$name = 'smart';
$unit_text = '';
$unitlen = 6;
$bigdescrlen = 25;
$smalldescrlen = 25;
$colours = 'mega';
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 15;

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
        if (! $htmlData->hasPowerStateRrd($diskKey)) {
            continue;
        }

        $disk = $htmlData->disk($diskKey);
        if ($disk === null) {
            continue;
        }

        $rrdName = $htmlData->isNvme($disk) ? 'smart_nvme' : 'smart';
        $rrd_filename = Rrd::name($device['hostname'], ['app', $rrdName, $app->app_id, $disk['idx']]);
        if (! Rrd::checkRrdExists($rrd_filename)) {
            continue;
        }

        $rrd_list[] = [
            'filename' => $rrd_filename,
            'descr'    => $htmlData->displayLabel($disk, $labelMode),
            'ds'       => 'power_state',
        ];
    }
}

if (empty($rrd_list)) {
    return;
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';

$powerStateLabels = [1 => 'Active', 2 => 'Idle A', 3 => 'Idle B', 4 => 'Idle C', 5 => 'Standby Y', 6 => 'Standby Z', 7 => 'Sleeping', 8 => 'Standby'];
$legend = implode('  ', array_map(static fn ($value, $label) => $value . '=' . $label, array_keys($powerStateLabels), $powerStateLabels));
$rrd_options[] = 'COMMENT:' . \LibreNMS\Data\Store\Rrd::safeDescr($legend) . '\l';
