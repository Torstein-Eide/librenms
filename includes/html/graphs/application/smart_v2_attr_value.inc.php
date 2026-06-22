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
$dsNormalized = $dsRaw . 'Normalized';

// Which DS actually exist decides the variant -- pollSataDeviceRrd() (Common.php)
// writes a different DS shape per smartmonSataAttrFormat: plain id{N} for
// single-value formats, id{N}Hi/Lo/Sum for the div formats (raw24div24/32), or
// id{N}P0..P5 for the byte/word multi-part formats (raw8, raw16, raw16raw16,
// raw24raw8). See Common.php's attrFormatSubValues()/attrFormatSingleValue().
$existingDs = Rrd::listDatasets($rrd_filename);
$hasRaw = in_array($dsRaw, $existingDs, true);
$hasNormalized = in_array($dsNormalized, $existingDs, true);
$hasDiv = in_array($dsRaw . 'Hi', $existingDs, true) && in_array($dsRaw . 'Lo', $existingDs, true);
$partSuffixes = array_values(array_filter(
    ['P5', 'P4', 'P3', 'P2', 'P1', 'P0'],
    fn (string $suffix) => in_array($dsRaw . $suffix, $existingDs, true)
));

if (! $hasRaw && ! $hasNormalized && ! $hasDiv && $partSuffixes === []) {
    throw new RrdGraphException('Requested SMART attribute not found in RRD');
}

$normalizedColor = session('applied_site_style') == 'dark' ? '#f2f2f2' : '#272b30';
$rawColor = '#ff9a9a66';
$thresh = $vars['attr_thresh'] ?? null;
$normMax = 255.0;

// rate_unit: 'second' for COUNTER attributes whose average rate exceeds
// 3600 raw-units/hour (i.e. >1/s on average — rrdtool already auto-rates
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

/** Max peak across multiple DS over the graphed period (null if none produced data). */
$fetchMaxAcross = static function (array $dsNames) use ($fetchDsMax, $rrd_filename, $graph_params): ?float {
    $peak = null;
    foreach ($dsNames as $ds) {
        $dsPeak = $fetchDsMax($rrd_filename, $ds, $graph_params->from, $graph_params->to);
        if ($dsPeak !== null && ($peak === null || $dsPeak > $peak)) {
            $peak = $dsPeak;
        }
    }

    return $peak;
};

$rrd_options[] = 'COMMENT:Series               Last      Min      Max\n';

/**
 * DEF/CDEF/LINE for Normalized, scaled against $rawMax: same peak-lock trick
 * used by all three branches below -- the left axis is forced to
 * [0, $rawMax] so the right axis's slope maps exactly to the 0-255
 * normalized range. Falls back to an independent right axis when there's no
 * usable peak (raw is 0/unavailable across the whole graphed period).
 */
$plotNormalizedAgainstPeak = function (?float $rawMax) use (&$rrd_options, $graph_params, $normalizedColor, $normMax, $dsNormalized, $rrd_filename): void {
    $rrd_options[] = "DEF:normalized={$rrd_filename}:{$dsNormalized}:AVERAGE";
    if ($rawMax !== null && $rawMax > 0) {
        $slope = rtrim(rtrim(sprintf('%.18f', $normMax / $rawMax), '0'), '.');
        $graph_params->right_axis = $slope . ':0';
        $graph_params->scale_max = (int) ceil($rawMax);
        $graph_params->scale_rigid = true;

        $rrd_options[] = 'CDEF:norm_display=normalized,' . $rawMax . ',*,' . $normMax . ',/';
        $rrd_options[] = 'LINE2:norm_display' . $normalizedColor . ':Normalized  ';
    } else {
        $graph_params->right_axis = '1:0';
        $rrd_options[] = 'LINE2:normalized' . $normalizedColor . ':Normalized  ';
    }
    $rrd_options[] = 'GPRINT:normalized:LAST:%8.1lf';
    $rrd_options[] = 'GPRINT:normalized:MIN:%8.1lf';
    $rrd_options[] = 'GPRINT:normalized:MAX:%8.1lf\l';
};

if ($hasDiv) {
    // raw24div24/raw24div32: Hi and Lo are independent 24/32-bit counters. A
    // computed Div=Hi/Lo ratio line (gap when Lo=0) is more useful than the
    // stored Sum (=Hi+Lo) DS, which isn't plotted. Right axis peak-locks to
    // Hi's max -- Div is a ratio, not a raw magnitude, so it's excluded from
    // that calculation. Lo isn't shown here at all -- it's on its own
    // separate graph (smart_v2_attr_div.inc.php), since Hi/Lo often differ
    // by orders of magnitude and don't share an axis well.
    $dsHi = $dsRaw . 'Hi';
    $dsLo = $dsRaw . 'Lo';
    $rawMax = $fetchMaxAcross([$dsHi]);
    if ($rawMax !== null) {
        $rawMax *= $rateMultiplier;
    }

    $graph_params->right_axis_label = 'Normalized';
    $graph_params->vertical_label = 'Raw' . $rawLabelSuffix;

    $rrd_options[] = "DEF:hi_raw={$rrd_filename}:{$dsHi}:AVERAGE";
    $rrd_options[] = "DEF:lo_raw={$rrd_filename}:{$dsLo}:AVERAGE";
    $rrd_options[] = "CDEF:hi=hi_raw,{$rateMultiplier},*";
    $rrd_options[] = 'CDEF:div=lo_raw,0,EQ,UNKN,hi_raw,lo_raw,/,IF';

    if (is_numeric($thresh)) {
        $threshDisplay = $rawMax !== null && $rawMax > 0 ? round((float) $thresh * $rawMax / $normMax, 4) : (float) $thresh;
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

    if ($hasNormalized) {
        $plotNormalizedAgainstPeak($rawMax);
    } elseif ($rawMax !== null && $rawMax > 0) {
        $graph_params->right_axis = (255.0 / $rawMax) . ':0';
        $graph_params->scale_max = (int) ceil($rawMax);
        $graph_params->scale_rigid = true;
    }
} elseif ($partSuffixes !== []) {
    // raw8/raw16/raw16raw16/raw24raw8: independent byte/word counters at fixed
    // bit positions within the packed register. Plot every present P-suffix
    // line with the same rate-unit treatment as the plain branch below.
    // Right axis peak-locks to the max across all present part lines.
    $partDs = array_map(fn (string $suffix) => $dsRaw . $suffix, $partSuffixes);
    $rawMax = $fetchMaxAcross($partDs);
    if ($rawMax !== null) {
        $rawMax *= $rateMultiplier;
    }

    $graph_params->right_axis_label = 'Normalized';
    $graph_params->vertical_label = 'Raw' . $rawLabelSuffix;

    $colors = ['#ff9a9a', '#9aff9a', '#9a9aff', '#ffff9a', '#ff9aff', '#9affff'];
    if (is_numeric($thresh)) {
        $threshDisplay = $rawMax !== null && $rawMax > 0 ? round((float) $thresh * $rawMax / $normMax, 4) : (float) $thresh;
        $rrd_options[] = 'COMMENT:Alert thresholds\:';
        $rrd_options[] = 'LINE1.5:' . $threshDisplay . '#005bdf:low_warn = ' . rtrim(rtrim(number_format((float) $thresh, 2, '.', ''), '0'), '.') . '\l:dashes';
    }

    foreach ($partSuffixes as $i => $suffix) {
        $ds = $dsRaw . $suffix;
        $color = $colors[$i % count($colors)];
        $rrd_options[] = "DEF:{$suffix}_raw={$rrd_filename}:{$ds}:AVERAGE";
        $rrd_options[] = "CDEF:{$suffix}={$suffix}_raw,{$rateMultiplier},*";
        $rrd_options[] = "LINE1.5:{$suffix}{$color}:" . str_pad($suffix, 12);
        $rrd_options[] = "GPRINT:{$suffix}:LAST:%8.1lf";
        $rrd_options[] = "GPRINT:{$suffix}:MIN:%8.1lf";
        $rrd_options[] = "GPRINT:{$suffix}:MAX:%8.1lf\\l";
    }

    if ($hasNormalized) {
        $plotNormalizedAgainstPeak($rawMax);
    } elseif ($rawMax !== null && $rawMax > 0) {
        $graph_params->right_axis = (255.0 / $rawMax) . ':0';
        $graph_params->scale_max = (int) ceil($rawMax);
        $graph_params->scale_rigid = true;
    }
} elseif ($hasRaw && $hasNormalized) {
    $rrd_options[] = "DEF:raw={$rrd_filename}:{$dsRaw}:AVERAGE";
    $rrd_options[] = "CDEF:rawDisplay=raw,{$rateMultiplier},*";
    if ($rateUnit === 'hour') {
        $rrd_options[] = 'CDEF:p_avg_1h=rawDisplay,3600,TRENDNAN';
    }

    $graph_params->right_axis_label = 'Normalized';
    $graph_params->vertical_label = 'Raw' . $rawLabelSuffix;

    $rawMax = $fetchMaxAcross([$dsRaw]);
    if ($rawMax !== null) {
        $rawMax *= $rateMultiplier;
    }

    if ($rawMax !== null && $rawMax > 0) {
        // Lock the left axis to the actual period max so right-axis top = 255 exactly.
        // Format as plain decimal — rrdtool rejects scientific notation for --right-axis.
        $slope = rtrim(rtrim(sprintf('%.18f', $normMax / $rawMax), '0'), '.');
        $graph_params->right_axis = $slope . ':0';
        $graph_params->scale_max = (int) ceil($rawMax);
        $graph_params->scale_rigid = true;

        $rrd_options[] = "DEF:normalized={$rrd_filename}:{$dsNormalized}:AVERAGE";
        $rrd_options[] = 'CDEF:norm_display=normalized,' . $rawMax . ',*,' . $normMax . ',/';

        if (is_numeric($thresh)) {
            $threshDisplay = round((float) $thresh * $rawMax / $normMax, 4);
            $rrd_options[] = 'COMMENT:Alert thresholds\:';
            $rrd_options[] = 'LINE1.5:' . $threshDisplay . '#005bdf:low_warn = ' . rtrim(rtrim(number_format((float) $thresh, 2, '.', ''), '0'), '.') . '\l:dashes';
        }

        $rrd_options[] = 'LINE1.5:rawDisplay' . $rawColor . ':Raw         ';
        $rrd_options[] = 'GPRINT:rawDisplay:LAST:%8.1lf';
        $rrd_options[] = 'GPRINT:rawDisplay:MIN:%8.1lf';
        $rrd_options[] = 'GPRINT:rawDisplay:MAX:%8.1lf\l';
        if ($rateUnit === 'hour') {
            $rrd_options[] = 'LINE2:p_avg_1h#ff6600:1h avg      ';
            $rrd_options[] = 'GPRINT:p_avg_1h:LAST:%8.1lf';
            $rrd_options[] = 'GPRINT:p_avg_1h:MIN:%8.1lf';
            $rrd_options[] = 'GPRINT:p_avg_1h:MAX:%8.1lf\l';
        }
        $rrd_options[] = 'LINE2:norm_display' . $normalizedColor . ':Normalized  ';
        $rrd_options[] = 'GPRINT:normalized:LAST:%8.1lf';
        $rrd_options[] = 'GPRINT:normalized:MIN:%8.1lf';
        $rrd_options[] = 'GPRINT:normalized:MAX:%8.1lf\l';
    } else {
        // Raw is 0 or unavailable — both sit naturally in the 0-255 range.
        $graph_params->right_axis = '1:0';

        if (is_numeric($thresh)) {
            $threshold = (float) $thresh;
            $rrd_options[] = 'COMMENT:Alert thresholds\:';
            $rrd_options[] = 'LINE1.5:' . $threshold . '#005bdf:low_warn = ' . rtrim(rtrim(number_format($threshold, 2, '.', ''), '0'), '.') . '\l:dashes';
        }

        $rrd_options[] = "DEF:normalized={$rrd_filename}:{$dsNormalized}:AVERAGE";
        $rrd_options[] = 'LINE1.5:rawDisplay' . $rawColor . ':Raw         ';
        $rrd_options[] = 'GPRINT:rawDisplay:LAST:%8.1lf';
        $rrd_options[] = 'GPRINT:rawDisplay:MIN:%8.1lf';
        $rrd_options[] = 'GPRINT:rawDisplay:MAX:%8.1lf\l';
        if ($rateUnit === 'hour') {
            $rrd_options[] = 'LINE2:p_avg_1h#ff6600:1h avg      ';
            $rrd_options[] = 'GPRINT:p_avg_1h:LAST:%8.1lf';
            $rrd_options[] = 'GPRINT:p_avg_1h:MIN:%8.1lf';
            $rrd_options[] = 'GPRINT:p_avg_1h:MAX:%8.1lf\l';
        }
        $rrd_options[] = 'LINE2:normalized' . $normalizedColor . ':Normalized  ';
        $rrd_options[] = 'GPRINT:normalized:LAST:%8.1lf';
        $rrd_options[] = 'GPRINT:normalized:MIN:%8.1lf';
        $rrd_options[] = 'GPRINT:normalized:MAX:%8.1lf\l';
    }
} elseif ($hasRaw) {
    $graph_params->vertical_label = 'Raw' . $rawLabelSuffix;

    $rrd_options[] = "DEF:raw={$rrd_filename}:{$dsRaw}:AVERAGE";
    $rrd_options[] = "CDEF:rawDisplay=raw,{$rateMultiplier},*";
    if ($rateUnit === 'hour') {
        $rrd_options[] = 'CDEF:p_avg_1h=rawDisplay,3600,TRENDNAN';
    }
    $rrd_options[] = 'LINE1.5:rawDisplay' . $rawColor . ':Raw         ';
    $rrd_options[] = 'GPRINT:rawDisplay:LAST:%8.1lf';
    $rrd_options[] = 'GPRINT:rawDisplay:MIN:%8.1lf';
    $rrd_options[] = 'GPRINT:rawDisplay:MAX:%8.1lf\l';
    if ($rateUnit === 'hour') {
        $rrd_options[] = 'LINE2:p_avg_1h#ff6600:1h avg      ';
        $rrd_options[] = 'GPRINT:p_avg_1h:LAST:%8.1lf';
        $rrd_options[] = 'GPRINT:p_avg_1h:MIN:%8.1lf';
        $rrd_options[] = 'GPRINT:p_avg_1h:MAX:%8.1lf\l';
    }
} else {
    $graph_params->vertical_label = 'Normalized';

    if (is_numeric($thresh)) {
        $threshold = (float) $thresh;
        $rrd_options[] = 'COMMENT:Alert thresholds\:';
        $rrd_options[] = 'LINE1.5:' . $threshold . '#005bdf:low_warn = ' . rtrim(rtrim(number_format($threshold, 2, '.', ''), '0'), '.') . '\l:dashes';
    }

    $rrd_options[] = "DEF:normalized={$rrd_filename}:{$dsNormalized}:AVERAGE";
    $rrd_options[] = 'LINE2:normalized' . $normalizedColor . ':Normalized  ';
    $rrd_options[] = 'GPRINT:normalized:LAST:%8.1lf';
    $rrd_options[] = 'GPRINT:normalized:MIN:%8.1lf';
    $rrd_options[] = 'GPRINT:normalized:MAX:%8.1lf\l';
}
