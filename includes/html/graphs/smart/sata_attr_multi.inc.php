<?php

use App\Facades\Rrd;
use Illuminate\Support\Facades\DB;
use LibreNMS\Agent\Unix\Smart\HtmlData;

$attrId = isset($vars['attr_id']) ? (int) $vars['attr_id'] : 0;
if ($attrId <= 0) {
    return;
}

// Numbered SMART attributes above the standardized "Big 5" are vendor-defined,
// so the same numeric ID can mean a different counter on different disk
// vendors/models. When given, attr_name restricts this graph to disks whose
// smart_sata_attributes.name exactly matches, so mismatched same-ID counters
// are never plotted together as if they were the same metric.
$attrName = isset($vars['attr_name']) ? trim((string) $vars['attr_name']) : null;

$dsName = 'id' . $attrId;

// COUNTER-typed attributes (see AttributeRateTracker/HtmlData::attributeRateUnit()):
// pollSataDeviceRrd() writes their plain id{N} DS as RRD type COUNTER so
// rrdtool can compute rrd_8h/24h/168h/672h rates natively. That means an
// AVERAGE fetch of id{N} always returns a rate, never the stored raw value --
// for a slowly-incrementing attribute (e.g. a handful of power cycles a day)
// that rate rounds down to 0 in the legend. sata_attr_value.inc.php (the
// per-disk graph) already handles this by relabeling id{N} as a changes/hour
// or changes/second line instead of pretending it's the raw count; mirror
// that here rather than showing a broken "0" for every disk.
$rateUnit = isset($vars['rate_unit']) ? (string) $vars['rate_unit'] : '';
$rateMultiplier = match ($rateUnit) {
    'hour' => 3600.0,
    'second' => 1.0,
    default => null,
};

$name = 'smart';
$unit_text = match ($rateUnit) {
    'hour' => 'Rate/h',
    'second' => 'Rate/s',
    default => '',
};
$unitlen = 10;
$bigdescrlen = 25;
$smalldescrlen = 25;
$colours = 'mega';
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 15;

// Mirrors LibreNMS\Agent\Module\Smart\Common::mibDiskIndex(). The disk_key is
// sanitized into the same safe-character index used everywhere else in the app.
$mibDiskIndex = static fn (string $key): string => substr((string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key), 0, 80);

// Disk label, same as the per-disk views: respects the saved naming
// template / label-mode cookie (including the "Custom" mode) instead of
// always showing the raw device_name.
$htmlData = HtmlData::forDevice($app, $device);
$labelCookie = 'smart_label_mode_' . $device['device_id'];
$labelModes = $htmlData->labelModes();
$labelMode = isset($_COOKIE[$labelCookie]) && isset($labelModes[$_COOKIE[$labelCookie]]) ? $_COOKIE[$labelCookie] : 'device';

// Find disks that actually carry this ATA attribute (only SATA/SAT disks have
// numbered SMART attributes; NVMe never does), straight from the DB rather
// than reverse-parsing RRD filenames on disk.
$disks = DB::table('smart_devices')
    ->where('smart_devices.app_id', $app->app_id)
    ->whereIn('smart_devices.protocol_type', [1, 2]) // SmartmonDeviceType: ata=1, sat=2
    ->whereExists(function ($query) use ($attrId, $attrName, $app) {
        $query->select(DB::raw(1))
            ->from('smart_sata_attributes')
            ->whereColumn('smart_sata_attributes.disk_key', 'smart_devices.disk_key')
            ->where('smart_sata_attributes.app_id', $app->app_id)
            ->where('smart_sata_attributes.attribute_id', $attrId);
        if ($attrName !== null && $attrName !== '') {
            $query->where('smart_sata_attributes.name', $attrName);
        }
    })
    ->get(['disk_key', 'device_name']);

$rrd_list = [];
foreach ($disks as $disk) {
    $idx = $mibDiskIndex($disk->disk_key);
    $rrd_filename = Rrd::name($device['hostname'], ['app', 'smart', $app->app_id, $idx]);
    if (! Rrd::checkRrdExists($rrd_filename)) {
        continue;
    }

    // The same attribute ID can have a different RRD dataset shape per disk,
    // depending on that disk's smartmonSataAttrFormat (see Common.php's
    // pollSataDeviceRrd()): plain id{N}, div id{N}Hi/Lo/Sum, or multi-part
    // id{N}P0..P5. Pick the closest single-value proxy when the plain DS
    // isn't there, so disks on different formats can still be compared.
    $existingDs = Rrd::listDatasets($rrd_filename);
    $ds = null;
    $labelSuffix = '';
    if (in_array($dsName, $existingDs, true)) {
        $ds = $dsName;
    } elseif (in_array($dsName . 'Hi', $existingDs, true)) {
        $ds = $dsName . 'Hi';
        $labelSuffix = ' (Hi)';
    } else {
        foreach (['P5', 'P4', 'P3', 'P2', 'P1', 'P0'] as $suffix) {
            if (in_array($dsName . $suffix, $existingDs, true)) {
                $ds = $dsName . $suffix;
                $labelSuffix = ' (' . $suffix . ')';
                break;
            }
        }
    }
    if ($ds === null) {
        continue;
    }

    $diskData = $htmlData->disk($disk->disk_key);
    $descr = $diskData !== null
        ? $htmlData->displayLabel($diskData, $labelMode)
        : (trim((string) $disk->device_name) !== '' ? $disk->device_name : $disk->disk_key);

    // Only the plain id{N} DS is ever written RRD-type COUNTER -- the Hi/P-part
    // fallbacks above are always GAUGE (already the real stored magnitude), so
    // the rate CDEF/relabel must not be applied to them.
    $entry = [
        'filename' => $rrd_filename,
        'descr'    => $descr . $labelSuffix,
        'ds'       => $ds,
    ];
    if ($ds === $dsName && $rateMultiplier !== null) {
        $entry['multiplier'] = $rateMultiplier;
        $entry['descr'] .= $rateUnit === 'hour' ? ' (/h)' : ' (/s)';
    }
    $rrd_list[] = $entry;
}

if (empty($rrd_list)) {
    return;
}

$scale_min = '0';
require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
