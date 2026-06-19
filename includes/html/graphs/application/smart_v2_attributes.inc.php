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
$hasRaw = ($vars['has_raw'] ?? '0') === '1';
$hasNormalized = ($vars['has_norm'] ?? '0') === '1';
if (! $hasRaw && ! $hasNormalized) {
    throw new RrdGraphException('Requested SMART attribute not found in RRD');
}

$normalizedColor = session('applied_site_style') == 'dark' ? '#f2f2f2' : '#272b30';
$rawColor = '#ff9a9a66';
$thresh = $vars['attr_thresh'] ?? null;
$normMax = 255.0;

// rate_unit: 'hour' for newly-detected ("Count" in the name) counters, graphed
// in changes/hour; 'second' for the original fixed-list counters (unchanged
// behaviour — rrdtool already auto-rates COUNTER DS to per-second on read);
// '' / unset for GAUGE attributes, which carry no rate semantics.
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
$fetchRawMax = static function (string $file, string $ds, int $start, int $end): ?float {
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

$rawMax = $hasRaw ? $fetchRawMax($rrd_filename, $dsRaw, $graph_params->from, $graph_params->to) : null;
if ($rawMax !== null) {
    $rawMax *= $rateMultiplier;
}

$rrd_options[] = 'COMMENT:Series               Last      Min      Max\n';

if ($hasRaw && $hasNormalized) {
    $rrd_options[] = "DEF:raw={$rrd_filename}:{$dsRaw}:AVERAGE";
    $rrd_options[] = "DEF:normalized={$rrd_filename}:{$dsNormalized}:AVERAGE";
    $rrd_options[] = "CDEF:rawDisplay=raw,{$rateMultiplier},*";
    if ($rateUnit === 'hour') {
        $rrd_options[] = 'CDEF:p_avg_1h=rawDisplay,3600,TRENDNAN';
    }

    $graph_params->right_axis_label = 'Normalized';
    $graph_params->vertical_label = 'Raw' . $rawLabelSuffix;

    if ($rawMax !== null && $rawMax > 0) {
        // Lock the left axis to the actual period max so right-axis top = 255 exactly.
        // Format as plain decimal — rrdtool rejects scientific notation for --right-axis.
        $slope = rtrim(rtrim(sprintf('%.18f', $normMax / $rawMax), '0'), '.');
        $graph_params->right_axis = $slope . ':0';
        $graph_params->scale_max = (int) ceil($rawMax);
        $graph_params->scale_rigid = true;

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
