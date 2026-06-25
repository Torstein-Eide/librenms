<?php

use App\Facades\LibrenmsConfig;
use App\Facades\Rrd;
use LibreNMS\Exceptions\RrdGraphException;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart', $app->app_id, $vars['disk']]);
$attrId = isset($vars['attr_id']) ? (int) $vars['attr_id'] : 0;
if ($attrId <= 0) {
    throw new RrdGraphException('Missing SMART attribute id');
}

require 'includes/html/graphs/common.inc.php';

if (! Rrd::checkRrdExists($rrd_filename)) {
    throw new RrdGraphException('No SMART attributes RRD file');
}

$dsRaw = 'id' . $attrId;
$dsHi = $dsRaw . 'Hi';
$dsLo = $dsRaw . 'Lo';

// raw24div24/raw24div32 only -- pollSataDeviceRrd() (Common.php) writes
// id{N}Hi/Lo/Sum for these attributes (no plain id{N}); everything else
// is handled by attr_value.inc.php.
$existingDs = Rrd::listDatasets($rrd_filename);
if (! in_array($dsHi, $existingDs, true) || ! in_array($dsLo, $existingDs, true)) {
    throw new RrdGraphException('Requested SMART attribute is not a div-format attribute');
}

$rawColor = '#ff9a9a66';
$thresh = $vars['attr_thresh'] ?? null;
$normMax = 255.0;

// rate_unit: 'second' for COUNTER attributes whose average rate exceeds
// 3600 raw-units/hour (i.e. >1/s on average; rrdtool already auto-rates
// COUNTER DS to per-second on read), 'hour' for slower COUNTER attributes;
// '' / unset for GAUGE attributes, which carry no rate semantics. See
// HtmlData::attributeRateUnit().
$rateUnit = $vars['rate_unit'] ?? '';
$rateMultiplier = $rateUnit === 'hour' ? 3600.0 : 1.0;
$rawLabelSuffix = match ($rateUnit) {
    'hour' => ' (changes/hour)',
    'second' => ' (changes/secound)',
    default => '',
};

/**
 * Fetch the MAX consolidation peak for $ds over the graphed period.
 * Returns null if rrdtool is unavailable or the DS produces no valid data.
 */
$fetchDsMax = static function (string $file, string $ds, int $start, int $end): ?float {
    $bin = LibrenmsConfig::get('rrdtool', 'rrdtool');
    $cmd = escapeshellcmd($bin) . ' fetch ' . escapeshellarg($file)
          . ' MAX --start ' . $start . ' --end ' . $end;
    exec($cmd . ' 2>/dev/null', $lines, $rc);
    if ($rc !== 0 || empty($lines)) {
        return null;
    }

    // First non-empty line is the DS header.
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
        // "timestamp: v0 v1 v2 ..."
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

$rrd_options[] = 'COMMENT:Series               Last      Min      Max\n';

// raw24div24/raw24div32: Hi and Lo are independent 24/32-bit counters that
// often differ by orders of magnitude, so Lo is plotted against its own
// right axis (peak-locked to Lo's own fetched range) instead of sharing
// Hi/Div's left axis. No Normalized line here -- see attr_value.inc.php
// for the attribute's other graph (Hi + Div only, against Normalized).
$hiMax = $fetchDsMax($rrd_filename, $dsHi, $graph_params->from, $graph_params->to);
if ($hiMax !== null) {
    $hiMax *= $rateMultiplier;
}
$loMax = $fetchDsMax($rrd_filename, $dsLo, $graph_params->from, $graph_params->to);
if ($loMax !== null) {
    $loMax *= $rateMultiplier;
}

// Hi can legitimately sit at 0 for the whole graphed period (e.g. its
// counter just hasn't incremented yet) -- left unguarded, the left axis
// would auto-range near 0 while Lo (potentially large) gets plotted
// unscaled, blowing the chart out. Floor the left axis at 10 in that case
// so there's always a sane scale to peak-lock the right axis against.
$leftMax = ($hiMax !== null && $hiMax > 0) ? $hiMax : 10.0;

$graph_params->right_axis_label = 'Lo';
$graph_params->vertical_label = 'Raw' . $rawLabelSuffix;

$rrd_options[] = "DEF:hi_raw={$rrd_filename}:{$dsHi}:AVERAGE";
$rrd_options[] = "DEF:lo_raw={$rrd_filename}:{$dsLo}:AVERAGE";
$rrd_options[] = "CDEF:hi=hi_raw,{$rateMultiplier},*";
$rrd_options[] = "CDEF:lo=lo_raw,{$rateMultiplier},*";
$rrd_options[] = 'CDEF:div=lo_raw,0,EQ,UNKN,hi_raw,lo_raw,/,IF';

if (is_numeric($thresh)) {
    $threshDisplay = round((float) $thresh * $leftMax / $normMax, 4);
    $rrd_options[] = 'COMMENT:Alert thresholds\:';
    $rrd_options[] = 'LINE1.5:' . $threshDisplay . '#005bdf:low_warn = ' . rtrim(rtrim(number_format((float) $thresh, 2, '.', ''), '0'), '.') . '\l:dashes';
}

$rrd_options[] = 'LINE1.5:hi#9aff9a:Hi          ';
$rrd_options[] = 'GPRINT:hi:LAST:%8.1lf';
$rrd_options[] = 'GPRINT:hi:MIN:%8.1lf';
$rrd_options[] = 'GPRINT:hi:MAX:%8.1lf\l';
$rrd_options[] = 'LINE1.5:div#9a9aff:Div         ';
$rrd_options[] = 'GPRINT:div:LAST:%8.1lf';
$rrd_options[] = 'GPRINT:div:MIN:%8.1lf';
$rrd_options[] = 'GPRINT:div:MAX:%8.1lf\l';

$graph_params->scale_max = (int) ceil($leftMax);
$graph_params->scale_rigid = true;

if ($loMax !== null && $loMax > 0) {
    // Same peak-lock trick used for Normalized in attr_value.inc.php,
    // just with Lo's own fetched peak as the right-axis target range
    // instead of a fixed 0-255 normalized scale.
    //
    // Both literals must be formatted as plain decimals, not PHP's default
    // float-to-string cast. leftMax/loMax can differ by many orders of
    // magnitude (e.g. the Hi=0 floor-of-10 case against a huge Lo peak),
    // and PHP switches to scientific notation ("4.13E-8") for very small
    // floats, which rrdtool's CDEF/--right-axis parsers can't read.
    $slope = rtrim(rtrim(sprintf('%.18f', $loMax / $leftMax), '0'), '.');
    $multiplier = rtrim(rtrim(sprintf('%.18f', $leftMax / $loMax), '0'), '.');
    $graph_params->right_axis = $slope . ':0';
    $rrd_options[] = 'CDEF:lo_display=lo,' . $multiplier . ',*';
} else {
    $graph_params->right_axis = '1:0';
    $rrd_options[] = 'CDEF:lo_display=lo';
}
// GPRINT reads the true (rate-adjusted) "lo" series; "lo_display" only
// repositions the LINE into the left axis's plotting space.
$rrd_options[] = 'LINE1.5:lo_display' . $rawColor . ':Lo          ';
$rrd_options[] = 'GPRINT:lo:LAST:%8.1lf';
$rrd_options[] = 'GPRINT:lo:MIN:%8.1lf';
$rrd_options[] = 'GPRINT:lo:MAX:%8.1lf\l';
