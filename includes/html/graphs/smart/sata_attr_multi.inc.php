<?php

use App\Facades\LibrenmsConfig;
use App\Facades\Rrd;
use Illuminate\Support\Facades\DB;
use LibreNMS\Agent\Unix\Smart\HtmlData;
use LibreNMS\Util\Number;
use LibreNMS\Util\RrdTrendForecast;

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
$dsNormalized = $dsName . 'Normalized';

// COUNTER-typed attributes (see AttributeRateTracker/HtmlData::attributeRateUnit()):
// pollSataDeviceRrd() writes their plain id{N} DS as RRD type COUNTER so
// rrdtool can compute rrd_8h/24h/168h/672h rates natively. That means an
// AVERAGE fetch of id{N} always returns a rate, never the stored raw value --
// for a slowly-incrementing attribute (e.g. a handful of power cycles a day)
// that rate rounds down to 0 in the legend. Scale/relabel it as a rate rather
// than pretending it's the raw count, same as sata_attr_value.inc.php.
$rateUnit = isset($vars['rate_unit']) ? (string) $vars['rate_unit'] : '';
$rateMultiplier = match ($rateUnit) {
    'hour' => 3600.0,
    'second' => 1.0,
    default => null,
};
$rawLabelSuffix = match ($rateUnit) {
    'hour' => ' (changes/hour)',
    'second' => ' (changes/second)',
    default => '',
};

// Representative alert threshold, Normalized 0-110 scale -- passed through from
// overview-graphs.blade.php the same way rate_unit is. Same attr_id+attr_name pins
// to the same underlying vendor attribute across disks, so its configured
// threshold is consistent; the first matching disk's stands in for all of them.
$threshRaw = $vars['attr_thresh'] ?? null;
$thresh = (is_numeric($threshRaw) && (float) $threshRaw > 0) ? (float) $threshRaw : null;

[$unit_text, $unit_label] = HtmlData::attributeUnitLabel($attrName ?? '');
$graph_params->vertical_label = ($unit_label !== '' ? "{$unit_text} ({$unit_label})" : $unit_text) . $rawLabelSuffix;

/**
 * Left axis tick format, SI-suffixed to match the period's raw magnitude (e.g.
 * "433.7M" instead of rrdtool's default raw digit count) -- same convention as
 * sata_attr_value.inc.php. No-op when there's no usable peak.
 */
$applyLeftAxisFormat = function (?float $rawMax) use (&$graph_params, $unit_label): void {
    if ($rawMax === null || $rawMax <= 0) {
        return;
    }
    $graph_params->left_axis_format = '%5.1lf' . trim(substr(Number::formatSi($rawMax, 0, 0, ''), -1) . $unit_label);
};

$scale_min = '0';
require 'includes/html/graphs/common.inc.php';

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

$entries = [];
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

    $entries[] = [
        'filename'      => $rrd_filename,
        'descr'         => $descr . $labelSuffix,
        'ds'            => $ds,
        // Only the plain id{N} DS is ever written RRD-type COUNTER -- the Hi/P-part
        // fallbacks above are always GAUGE (already the real stored magnitude), so
        // the rate CDEF/relabel below must not be applied to them.
        'isCounterDs'   => $ds === $dsName && $rateMultiplier !== null,
        // Normalized is always written alongside idXX regardless of format (see
        // pollSataDeviceRrd()), so this is independent of which $ds variant above matched.
        'hasNormalized' => in_array($dsNormalized, $existingDs, true),
    ];
}

if (empty($entries)) {
    return;
}

/**
 * Fetch the MAX consolidation peak for $ds over the graphed period. Returns
 * null if rrdtool is unavailable or the DS produces no valid data. Same
 * convention as sata_attr_value.inc.php's own copy.
 */
$fetchDsMax = static function (string $file, string $ds, int $start, int $end): ?float {
    $bin = LibrenmsConfig::get('rrdtool', 'rrdtool');
    $cmd = escapeshellcmd($bin) . ' fetch ' . escapeshellarg($file)
          . ' MAX --start ' . $start . ' --end ' . $end;
    exec($cmd . ' 2>/dev/null', $lines, $rc);
    if ($rc !== 0 || empty($lines)) {
        return null;
    }

    $header = array_shift($lines);
    $dsNames = preg_split('/\s+/', trim($header));
    $col = array_search($ds, $dsNames, true);
    if ($col === false) {
        return null;
    }

    $peak = null;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = preg_split('/:\s*|\s+/', $line);
        array_shift($parts); // remove timestamp
        $val = $parts[$col] ?? null;
        if ($val === null || $val === 'nan' || $val === '-nan') {
            continue;
        }
        $fval = (float) $val;
        if ($peak === null || $fval > $peak) {
            $peak = $fval;
        }
    }

    return $peak;
};

// Left axis tick format only -- with 1 LINE per disk (not 2), there's no second
// plotted series that would need a right axis/peak-lock at all; Normalized is read
// as plain text below, never drawn.
$rawMax = null;
foreach ($entries as $e) {
    $peak = $fetchDsMax($e['filename'], $e['ds'], $graph_params->from, $graph_params->to);
    if ($peak === null) {
        continue;
    }
    if ($e['isCounterDs']) {
        $peak *= $rateMultiplier;
    }
    if ($rawMax === null || $peak > $rawMax) {
        $rawMax = $peak;
    }
}
$applyLeftAxisFormat($rawMax);

$hasAnyNormalized = array_reduce($entries, fn (bool $carry, array $e): bool => $carry || $e['hasNormalized'], false);

// Header caption padded to $labelWidth + 8 -- same recipe
// generic_multi_line_exact_numbers.inc.php uses for its own header/row alignment.
$labelWidth = 20;
$rrd_options[] = 'COMMENT:' . str_pad('Series', $labelWidth + 8)
    . 'Last       Min        Max       ' . ($hasAnyNormalized ? 'Norm      Trend\n' : '\n');

// Threshold is a single representative value (same attr_id+attr_name across disks --
// see the note on $thresh above), not per-disk RRD data, so it's printed once here
// rather than repeated as a column on every one of what could be 100+ disk rows.
if ($hasAnyNormalized && is_numeric($thresh)) {
    $rrd_options[] = 'COMMENT:Normalized alert threshold\: ' . rtrim(rtrim(number_format($thresh, 2, '.', ''), '0'), '.') . '\l';
}

$colour_iter = 0;
foreach ($entries as $i => $e) {
    if (! LibrenmsConfig::get("graph_colours.mega.$colour_iter")) {
        $colour_iter = 0;
    }
    $colour = LibrenmsConfig::get("graph_colours.mega.$colour_iter");
    $colour_iter++;

    $label = Rrd::fixedSafeDescr($e['descr'], $labelWidth);

    // One LINE per disk -- Normalized/Trend below are extra legend columns on this
    // same row (invisible DEF + GPRINT/COMMENT text only), not additional plotted
    // lines, so this stays exactly one graph line per disk regardless of how many
    // disks are being compared.
    $rawVar = 'raw' . $i;
    $rrd_options[] = "DEF:{$rawVar}_src={$e['filename']}:{$e['ds']}:AVERAGE";
    $rrd_options[] = $e['isCounterDs']
        ? "CDEF:{$rawVar}={$rawVar}_src,{$rateMultiplier},*"
        : "CDEF:{$rawVar}={$rawVar}_src";

    $rrd_options[] = "LINE1.5:{$rawVar}#{$colour}:{$label}";
    $rrd_options[] = "GPRINT:{$rawVar}:LAST:%8.1lf%s";
    $rrd_options[] = "GPRINT:{$rawVar}:MIN:%8.1lf%s";
    $rrd_options[] = "GPRINT:{$rawVar}:MAX:%8.1lf%s";

    if (! $e['hasNormalized']) {
        $rrd_options[] = 'COMMENT:\\l';

        continue;
    }

    // Normalized's current value, and the trend/crossing estimate, as plain text
    // columns on this same row -- computeTrendSummary() is a pure PHP regression
    // over a plain rrdtool fetch (no VDEF/LINE of its own), and the Normalized DEF
    // below is never drawn (no LINE/AREA references it), only read via GPRINT.
    $normVar = 'norm' . $i;
    $rrd_options[] = "DEF:{$normVar}={$e['filename']}:{$dsNormalized}:AVERAGE";
    $rrd_options[] = "GPRINT:{$normVar}:LAST:%8.1lf";

    $trendSummary = RrdTrendForecast::computeTrendSummary($e['filename'], $dsNormalized, 1.0, $graph_params->from, $graph_params->to, $thresh);
    $trendText = Rrd::safeDescr($trendSummary['rateText']) . ($trendSummary['crossingText'] !== null ? ', ' . Rrd::safeDescr($trendSummary['crossingText']) : '');
    $rrd_options[] = 'COMMENT:  ' . $trendText . '\\l';
}
