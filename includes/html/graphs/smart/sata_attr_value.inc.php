<?php

use App\Facades\LibrenmsConfig;
use App\Facades\Rrd;
use Illuminate\Support\Facades\DB;
use LibreNMS\Exceptions\RrdGraphException;
use LibreNMS\Util\Number;
use LibreNMS\Util\RrdTrendForecast;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'smart', $app->app_id, $vars['disk']]);
$attrId = isset($vars['attr_id']) ? (int) $vars['attr_id'] : 0;
if ($attrId <= 0) {
    throw new RrdGraphException('Missing SMART attribute id');
}

$attrRow = DB::table('smart_sata_attributes')
    ->where('app_id', $app->app_id)
    ->where('disk_key', $vars['disk'])
    ->where('attribute_id', $attrId)
    ->first(['name', 'rate_672h', 'rate_168h', 'rate_24h', 'rate_8h']);
$attrName = (string) ($attrRow->name ?? '');

// Longest available lookback window wins -- it's the smoothest estimate of
// long-term drift, same preference order the settings threshold tables use
// when picking which Δ column to trust for a "trend" read. Only used to feed
// RrdTrendForecast's HWPREDICT branch a "crosses threshold in ~N days"
// estimate (see below); the linear-fallback branch derives its own slope and
// ignores this.
$persistedRatePerHour = null;
if ($attrRow !== null) {
    foreach (['rate_672h', 'rate_168h', 'rate_24h', 'rate_8h'] as $column) {
        if (is_numeric($attrRow->$column ?? null)) {
            $persistedRatePerHour = (float) $attrRow->$column;
            break;
        }
    }
}

/**
 * Keyword -> [vertical-axis description, unit] for the Raw line's label.
 * SMART attribute names are vendor-defined text, not a typed unit -- this is
 * a best-effort guess from well-known ATA attribute name patterns
 * (smartmontools' drivedb.h naming). Order matters: most specific pattern
 * first, since e.g. "Seek Time Performance" must match /performance/ before
 * a generic time/hours rule below it would otherwise misfire, and
 * "Uncorrectable Error Cnt" must match /uncorrect/ as an error, not get
 * caught by a broader sector rule.
 */
$attrUnitRules = [
    '/temperature/i'                                 => ['Temperature', '°C'],
    '/load-in time/i'                                 => ['Load Time', 'ms'],
    '/spin.?up.?time/i'                                 => ['Spin-up Time', 'ms'],
    '/performance/i'                                  => ['Performance', ''],
    '/helium level/i'                                 => ['Helium Level', '%'],
    //   '/helium condition/i'                             => ['Helium Level', ''],
    '/(health monitor|head health)/i'                 => ['Health', ''],
    '/(wear leveling|media wear)/i'                   => ['Wear', '%'],
    '/rdwr ratio/i'                                   => ['Ratio', '%'],
    '/workld timer/i'                                 => ['Time', 'min'],
    '/(hours)/i'                                      => ['Time', 'h'],
    '/(total.?lbas.?(written|read)|nand.?writes)/i'      => ['Data', ''],
    //    '/disk shift/i'                                   => ['Shift', ''],
    //    '/pressure limit/i'                               => ['Pressure', ''],
    //    '/(exception mode|throttle)/i'                    => ['Status', ''],
    //    '/sector/i'                                       => ['Sectors', ''],
    //    '/(rsvd blk|bad block)/i'                         => ['Blocks', ''],
    //    '/(error|crc|g-sense|timeout|fail|uncorrect)/i'   => ['Errors', ''],
    //    '/(count|cycle|retry|retract|recovery|downshift)/i' => ['Count', ''],
];

$unit_text = 'Raw';
$unit_label = '';
foreach ($attrUnitRules as $pattern => $textLabel) {
    if (preg_match($pattern, $attrName)) {
        [$unit_text, $unit_label] = $textLabel;
        break;
    }
}

$verticalLabel = $unit_label !== '' ? "{$unit_text} ({$unit_label})" : $unit_text;

/**
 * Left axis tick format, SI-suffixed to match the period's raw magnitude
 * (e.g. "433.7M" instead of rrdtool's default raw digit count) with
 * $unit_label tacked on, mirroring the sensor graphs' left_axis_format
 * convention. No-op when there's no usable peak (raw is 0/unavailable
 * across the whole graphed period) -- rrdtool's default formatting is fine
 * for that case.
 */
$applyLeftAxisFormat = function (?float $rawMax) use (&$graph_params, $unit_label): void {
    if ($rawMax === null || $rawMax <= 0) {
        return;
    }
    $graph_params->left_axis_format = '%5.1lf' . trim(substr(Number::formatSi($rawMax, 0, 0, ''), -1) . $unit_label);
};

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
// Faded version of Normalized's own color for its trend overlay, so the two
// visibly belong together instead of competing for a distinct hue -- same
// alpha-suffix convention $rawColor below already uses.
$normalizedTrendColor = $normalizedColor . '66';
$rawColor = '#ff9a9a66';
$threshRaw = $vars['attr_thresh'] ?? null;
$thresh = (is_numeric($threshRaw) && (float) $threshRaw > 0) ? $threshRaw : null;
$normMax = 110.0;

// rate_unit: 'second' for COUNTER attributes whose average rate exceeds
// 3600 raw-units/hour (i.e. >1/s on average; rrdtool already auto-rates
// COUNTER DS to per-second on read), 'hour' for slower COUNTER attributes;
// '' / unset for GAUGE attributes, which carry no rate semantics. See
// HtmlData::attributeRateUnit().
$rateUnit = $vars['rate_unit'] ?? '';
$rateMultiplier = $rateUnit === 'hour' ? 3600.0 : 1.0;

$rawLabelSuffix = match ($rateUnit) {
    'hour' => ' (changes/hour)',
    'second' => ' (changes/second)',
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

$rrd_options[] = 'COMMENT:Series           Last    Min     Max     Avg/Trend\n';

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
    $rrd_options[] = 'GPRINT:normalized:LAST:%5.1lf%s';
    $rrd_options[] = 'GPRINT:normalized:MIN:%5.1lf%S';
    $rrd_options[] = 'GPRINT:normalized:MAX:%5.1lf%S\l';
};

if ($hasDiv) {
    // raw24div24/raw24div32: Hi and Lo are independent 24/32-bit counters. A
    // computed Div=Hi/Lo ratio line (gap when Lo=0) is more useful than the
    // stored Sum (=Hi+Lo) DS, which isn't plotted. Right axis peak-locks to
    // Hi's max -- Div is a ratio, not a raw magnitude, so it's excluded from
    // that calculation. Lo isn't shown here at all -- it's on its own
    // separate graph (attr_div.inc.php), since Hi/Lo often differ
    // by orders of magnitude and don't share an axis well.
    $dsHi = $dsRaw . 'Hi';
    $dsLo = $dsRaw . 'Lo';
    $rawMax = $fetchMaxAcross([$dsHi]);
    if ($rawMax !== null) {
        $rawMax *= $rateMultiplier;
    }

    $graph_params->right_axis_label = 'Normalized';
    $graph_params->vertical_label = $verticalLabel . $rawLabelSuffix;
    $applyLeftAxisFormat($rawMax);

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
    $rrd_options[] = 'GPRINT:hi:LAST:%5.1lf%s';
    $rrd_options[] = 'GPRINT:hi:MIN:%5.1lf%S';
    $rrd_options[] = 'GPRINT:hi:MAX:%5.1lf%S\l';
    $rrd_options[] = 'LINE1.5:div#9a9aff:Div         ';
    $rrd_options[] = 'GPRINT:div:LAST:%5.1lf%s';
    $rrd_options[] = 'GPRINT:div:MIN:%5.1lf%S';
    $rrd_options[] = 'GPRINT:div:MAX:%5.1lf%S\l';

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
    $graph_params->vertical_label = $verticalLabel . $rawLabelSuffix;
    $applyLeftAxisFormat($rawMax);

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
        $rrd_options[] = "GPRINT:{$suffix}:LAST:%5.1lf%s";
        $rrd_options[] = "GPRINT:{$suffix}:MIN:%5.1lf%S";
        $rrd_options[] = "GPRINT:{$suffix}:MAX:%5.1lf%S\\l";
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
    if (in_array($rateUnit, ['hour', 'second'], true)) {
        // VDEF+HRULE, not a CDEF:TRENDNAN line: TRENDNAN's window would need
        // to reach back before $from to fill, which the DEF never fetches,
        // so it draws as a single dot near the right edge instead of a
        // line. VDEF's AVERAGE reduces the whole graphed period to one
        // scalar and HRULE draws it as a flat line across the full width.
        $rrd_options[] = 'VDEF:p_avg=rawDisplay,AVERAGE';
    }

    $graph_params->right_axis_label = 'Normalized';
    $graph_params->vertical_label = $verticalLabel . $rawLabelSuffix;

    $rawMax = $fetchMaxAcross([$dsRaw]);
    if ($rawMax !== null) {
        $rawMax *= $rateMultiplier;
    }
    $applyLeftAxisFormat($rawMax);

    if ($rawMax !== null && $rawMax > 0) {
        // Lock the left axis to the actual period max so right-axis top = 255 exactly.
        // Format as plain decimal. rrdtool rejects scientific notation for --right-axis.
        $slope = rtrim(rtrim(sprintf('%.18f', $normMax / $rawMax), '0'), '.');
        $graph_params->right_axis = $slope . ':0';
        $graph_params->scale_max = (int) ceil($rawMax);
        $graph_params->scale_rigid = true;

        $rrd_options[] = "DEF:normalized={$rrd_filename}:{$dsNormalized}:AVERAGE";
        $rrd_options[] = 'CDEF:norm_display=normalized,' . $rawMax . ',*,' . $normMax . ',/';

        // Legend rows grouped by series below (Raw's own rows, then everything
        // Normalized's), rather than interleaved -- Raw's threshold/rate context
        // stayed next to Normalized's data before, which read as bouncing between
        // the two series.
        $rrd_options[] = 'LINE1.5:rawDisplay' . $rawColor . ':Raw         ';
        $rrd_options[] = 'GPRINT:rawDisplay:LAST:%5.1lf%s';
        // %s (not %S) on MIN/MAX -- Raw's own scale, not locked to LAST's. A COUNTER
        // attribute's LAST (current rate) can be orders of magnitude smaller than its
        // historical MAX, so %S here previously left MAX showing a long unscaled
        // number instead of an SI-suffixed one.
        $rrd_options[] = 'GPRINT:rawDisplay:MIN:%5.1lf%s';
        $rrd_options[] = 'GPRINT:rawDisplay:MAX:%5.1lf%s';
        if (in_array($rateUnit, ['hour', 'second'], true)) {
            // Merged into Raw's own row (Avg/Trend column) instead of a separate
            // "Period average rate" line -- HRULE kept for the visual reference line,
            // just without its own legend text.
            $rrd_options[] = 'HRULE:p_avg#ff6600';
            $rrd_options[] = 'GPRINT:p_avg:  %5.1lf%s' . ($rateUnit === 'second' ? '/s' : '/h') . '\l';
        } else {
            $rrd_options[] = 'COMMENT:\l';
        }
        $rrd_options[] = 'COMMENT:\l';

        $showNormalizedTrend = $to > time() && ! in_array($attrId, [194, 190], true);
        $normalizedTrend = null;
        // Paint drawn before the Normalized line itself so Normalized paints on top of
        // (not under) its own trend overlay; legend text grouped after Normalized's own
        // Last/Min/Max row instead -- see the "Trend/forecast overlay" note below.
        if ($showNormalizedTrend) {
            RrdTrendForecast::appendPaint($rrd_options, $rrd_filename, $dsNormalized, 'nhw', 1.0, $normalizedTrendColor, $rawMax, $normMax);
            $normalizedTrend = RrdTrendForecast::computeTrendSummary($rrd_filename, $dsNormalized, 1.0, $graph_params->from, $graph_params->to, is_numeric($thresh) ? (float) $thresh : null);
        }
        $rrd_options[] = 'LINE2:norm_display' . $normalizedColor . ':Normalized  ';
        $rrd_options[] = 'GPRINT:normalized:LAST:%5.1lf%s';
        $rrd_options[] = 'GPRINT:normalized:MIN:%5.1lf%S';
        $rrd_options[] = 'GPRINT:normalized:MAX:%5.1lf%S';
        // Avg/Trend column: the day-rate computed alongside the trend line's crossing
        // estimate (see RrdTrendForecast::computeTrendSummary()), not repeated again
        // in the trend legend line below.
        $rrd_options[] = $normalizedTrend !== null
            ? 'COMMENT:  ' . Rrd::safeDescr($normalizedTrend['rateText']) . '\l'
            : 'COMMENT:\l';
        if ($showNormalizedTrend) {
            RrdTrendForecast::appendLegend($rrd_options, 'nhw', $normalizedTrend['crossingText'] ?? null);
        }
        if (is_numeric($thresh)) {
            $threshDisplay = round((float) $thresh * $rawMax / $normMax, 4);
            $rrd_options[] = 'COMMENT:Alert thresholds\:';
            $rrd_options[] = 'LINE1.5:' . $threshDisplay . '#005bdf:low_warn = ' . rtrim(rtrim(number_format((float) $thresh, 2, '.', ''), '0'), '.') . '\l:dashes';
        }
    } else {
        // Raw is 0 or unavailable. Both sit naturally in the 0-255 range.
        $graph_params->right_axis = '1:0';

        $rrd_options[] = "DEF:normalized={$rrd_filename}:{$dsNormalized}:AVERAGE";
        // Legend rows grouped by series below (Raw's own rows, then everything
        // Normalized's), rather than interleaved.
        $rrd_options[] = 'LINE1.5:rawDisplay' . $rawColor . ':Raw         ';
        $rrd_options[] = 'GPRINT:rawDisplay:LAST:%5.1lf%s';
        // %s (not %S) on MIN/MAX -- Raw's own scale, not locked to LAST's. A COUNTER
        // attribute's LAST (current rate) can be orders of magnitude smaller than its
        // historical MAX, so %S here previously left MAX showing a long unscaled
        // number instead of an SI-suffixed one.
        $rrd_options[] = 'GPRINT:rawDisplay:MIN:%5.1lf%s';
        $rrd_options[] = 'GPRINT:rawDisplay:MAX:%5.1lf%s';
        if (in_array($rateUnit, ['hour', 'second'], true)) {
            // Merged into Raw's own row (Avg/Trend column) instead of a separate
            // "Period average rate" line -- HRULE kept for the visual reference line,
            // just without its own legend text.
            $rrd_options[] = 'HRULE:p_avg#ff6600';
            $rrd_options[] = 'GPRINT:p_avg:  %5.1lf%s' . ($rateUnit === 'second' ? '/s' : '/h') . '\l';
        } else {
            $rrd_options[] = 'COMMENT:\l';
        }
        $rrd_options[] = 'COMMENT:\l';

        // No remap here -- Normalized is plotted directly (no rawMax to scale
        // against), so its trend overlay needs none either.
        $showNormalizedTrend = $to > time() && ! in_array($attrId, [194, 190], true);
        $normalizedTrend = null;
        if ($showNormalizedTrend) {
            RrdTrendForecast::appendPaint($rrd_options, $rrd_filename, $dsNormalized, 'nhw', 1.0, $normalizedTrendColor);
            $normalizedTrend = RrdTrendForecast::computeTrendSummary($rrd_filename, $dsNormalized, 1.0, $graph_params->from, $graph_params->to, is_numeric($thresh) ? (float) $thresh : null);
        }
        $rrd_options[] = 'LINE2:normalized' . $normalizedColor . ':Normalized  ';
        $rrd_options[] = 'GPRINT:normalized:LAST:%5.1lf%s';
        $rrd_options[] = 'GPRINT:normalized:MIN:%5.1lf%S';
        $rrd_options[] = 'GPRINT:normalized:MAX:%5.1lf%S';
        $rrd_options[] = $normalizedTrend !== null
            ? 'COMMENT:  ' . Rrd::safeDescr($normalizedTrend['rateText']) . '\l'
            : 'COMMENT:\l';
        if ($showNormalizedTrend) {
            RrdTrendForecast::appendLegend($rrd_options, 'nhw', $normalizedTrend['crossingText'] ?? null);
        }
        if (is_numeric($thresh)) {
            $threshold = (float) $thresh;
            $rrd_options[] = 'COMMENT:Alert thresholds\:';
            $rrd_options[] = 'LINE1.5:' . $threshold . '#005bdf:low_warn = ' . rtrim(rtrim(number_format($threshold, 2, '.', ''), '0'), '.') . '\l:dashes';
        }
    }
} elseif ($hasRaw) {
    $graph_params->vertical_label = $verticalLabel . $rawLabelSuffix;
    $rawMax = $fetchMaxAcross([$dsRaw]);
    if ($rawMax !== null) {
        $rawMax *= $rateMultiplier;
    }
    $applyLeftAxisFormat($rawMax);

    $rrd_options[] = "DEF:raw={$rrd_filename}:{$dsRaw}:AVERAGE";
    $rrd_options[] = "CDEF:rawDisplay=raw,{$rateMultiplier},*";
    if (in_array($rateUnit, ['hour', 'second'], true)) {
        $rrd_options[] = 'VDEF:p_avg=rawDisplay,AVERAGE';
    }
    $rrd_options[] = 'LINE1.5:rawDisplay' . $rawColor . ':Raw         ';
    $rrd_options[] = 'GPRINT:rawDisplay:LAST:%5.1lf%s';
    // %s (not %S) on MIN/MAX -- see the note on the other Raw GPRINT triplets above.
    $rrd_options[] = 'GPRINT:rawDisplay:MIN:%5.1lf%s';
    $rrd_options[] = 'GPRINT:rawDisplay:MAX:%5.1lf%s';
    if (in_array($rateUnit, ['hour', 'second'], true)) {
        // Merged into Raw's own row (Avg/Trend column) -- see the note on the other
        // Raw blocks above.
        $rrd_options[] = 'HRULE:p_avg#ff6600';
        $rrd_options[] = 'GPRINT:p_avg:  %5.1lf%s' . ($rateUnit === 'second' ? '/s' : '/h') . '\l';
    } else {
        $rrd_options[] = 'COMMENT:\l';
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
    $rrd_options[] = 'GPRINT:normalized:LAST:%5.1lf%s';
    $rrd_options[] = 'GPRINT:normalized:MIN:%5.1lf%S';
    $rrd_options[] = 'GPRINT:normalized:MAX:%5.1lf%S\l';
}

/**
 * Normalized's forecast/trend overlay is drawn inline, inside the
 * `hasRaw && hasNormalized` branch above (see its RrdTrendForecast::appendPaint()/
 * computeTrendSummary()/appendLegend() calls) -- not here -- specifically so its
 * LINE draws *before* the Normalized data line itself: rrdtool paints in
 * emission order, so Normalized needs to come after its own overlay to render
 * on top of it, not the other way round. computeTrendSummary()'s day-rate is
 * also embedded directly into Normalized's own Last/Min/Max row (its Avg/Trend
 * column) rather than repeated in the trend legend line.
 * Excludes the temperature attributes (194/190), which have their own
 * dedicated graph (see HwForecastSetting's "except temperature" behavior).
 * Shown when the graph's end time extends into the future (same convention as
 * the sensor and port_bits graphs -- see includes/html/pages/graphs.inc.php's
 * "set to future date" hint), not behind a separate UI toggle.
 *
 * The Raw overlay (against $dsRaw, using $threshRawValue converted from
 * Normalized's 0-110 scale and $persistedRatePerHour) is temporarily disabled
 * -- see the commented-out block that used to live here -- since Normalized is
 * the more meaningful trajectory to project and the two fighting for attention
 * made it harder to read either. Re-enable by restoring that call (also inline,
 * before the Raw data line, for the same draw-order reason) if Raw-side
 * trending is wanted again.
 */
