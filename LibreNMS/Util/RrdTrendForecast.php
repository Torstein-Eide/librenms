<?php

namespace LibreNMS\Util;

use App\Facades\LibrenmsConfig;
use App\Facades\Rrd;
use LibreNMS\Data\Store\Rrd as RrdStore;
use LibreNMS\Exceptions\RrdException;

/**
 * Shared "Trend / Forecast" overlay for per-disk SMART RRD graphs: prefers the
 * RRD's own Holt-Winters forecast (HWPREDICT/DEVPREDICT/FAILURES) when the file
 * was created with it enabled (see SataHandler::hwForecastRra()/HwForecastSetting)
 * -- it models seasonal behavior and already flags aberrant samples, rather than
 * a single straight-line slope. Falls back to a least-squares linear projection
 * computed in PHP from a plain rrdtool fetch of the graphed period when the RRD
 * has no HWPREDICT RRA (forecasting disabled, or an RRD type -- e.g. NVMe --
 * that never gets one). Originated in sata_attr_value.inc.php's per-attribute
 * trend overlay; generalized here for reuse across the other per-disk SMART
 * graphs (includes/html/graphs/smart/*.inc.php).
 */
final class RrdTrendForecast
{
    /**
     * Append a Trend/Forecast overlay for one DS onto $rrd_options.
     *
     * $varSuffix must be unique per call within a single graph invocation (e.g. an
     * increasing index) so the rrdtool variable names emitted for multiple
     * overlaid series don't collide.
     *
     * $thresholdRawValue, if given, is only used by the linear-fallback path (the
     * RRD's own Holt-Winters forecast has no comparable "days until threshold"
     * concept): when the projection's slope is non-zero, a COMMENT line is
     * appended reporting the trend rate and, if the threshold hasn't already
     * been crossed, an estimated number of days until it is. Callers own the
     * unit/scale conversion (e.g. SMART normalized-vs-raw) -- this class only
     * does the generic slope math.
     */
    public static function append(array &$rrd_options, string $rrdFilename, string $ds, string $varSuffix, float $multiplier, int $from, int $to, string $color = '#ff6600', ?float $thresholdRawValue = null): void
    {
        try {
            $hasHwRra = Rrd::hasRraConsolidationFunction($rrdFilename, 'HWPREDICT');
        } catch (RrdException) {
            // A failed `rrdtool info` shouldn't break graph rendering -- fall back to the
            // linear projection, which only needs a plain `rrdtool fetch` of the file.
            $hasHwRra = false;
        }

        if ($hasHwRra) {
            self::appendHoltWinters($rrd_options, $rrdFilename, $ds, $varSuffix, $multiplier);

            return;
        }

        self::appendLinear($rrd_options, $rrdFilename, $ds, $varSuffix, $multiplier, $from, $to, $color, $thresholdRawValue);
    }

    /** Format a float as a plain decimal (never scientific notation, which rrdtool's CDEF/LINE parsers reject). */
    private static function fmt(float $value): string
    {
        return rtrim(rtrim(sprintf('%.18f', $value), '0'), '.') ?: '0';
    }

    private static function appendHoltWinters(array &$rrd_options, string $rrdFilename, string $ds, string $v, float $multiplier): void
    {
        $m = self::fmt($multiplier);
        $rrd_options[] = "DEF:{$v}predraw={$rrdFilename}:{$ds}:HWPREDICT";
        $rrd_options[] = "DEF:{$v}devraw={$rrdFilename}:{$ds}:DEVPREDICT";
        $rrd_options[] = "DEF:{$v}failraw={$rrdFilename}:{$ds}:FAILURES";
        $rrd_options[] = "CDEF:{$v}pred={$v}predraw,{$m},*";
        $rrd_options[] = "CDEF:{$v}dev={$v}devraw,{$m},*";
        $rrd_options[] = "CDEF:{$v}hwup={$v}pred,{$v}dev,2,*,+";
        $rrd_options[] = "CDEF:{$v}hwlo={$v}pred,{$v}dev,2,*,-";
        $rrd_options[] = "LINE1:{$v}hwup#8888ff::dashes";
        $rrd_options[] = "LINE1:{$v}hwlo#8888ff::dashes";
        $rrd_options[] = "TICK:{$v}failraw#ff000022:1.0";
        $rrd_options[] = 'COMMENT:band = HW prediction +/- 2 deviations (needs ~2 days data)\l';
    }

    private static function appendLinear(array &$rrd_options, string $rrdFilename, string $ds, string $v, float $multiplier, int $from, int $to, string $color, ?float $thresholdRawValue): void
    {
        $series = self::fetchAverageSeries($rrdFilename, $ds, $from, $to, $multiplier);
        if (count($series) < 2) {
            return;
        }

        $n = count($series);
        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        foreach ($series as $x => $y) {
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }
        $denom = $n * $sumXX - $sumX * $sumX;
        if (abs($denom) <= 1e-9) {
            return;
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / $denom;
        $intercept = ($sumY - $slope * $sumX) / $n;

        $rrd_options[] = "CDEF:{$v}trend=TIME," . self::fmt($slope) . ',*,' . self::fmt($intercept) . ',+';
        $rrd_options[] = "LINE1:{$v}trend{$color}::dashes";

        $trendText = sprintf('trend: %+.3g/day', $slope * 86400);
        if ($thresholdRawValue !== null && abs($slope) > 1e-12) {
            $crossTs = ($thresholdRawValue - $intercept) / $slope;
            $daysUntil = ($crossTs - $to) / 86400;
            $threshDisplayText = rtrim(rtrim(number_format($thresholdRawValue, 2, '.', ''), '0'), '.');
            $trendText .= $daysUntil > 0
                ? sprintf('    crosses threshold %s in ~ %d days', $threshDisplayText, (int) round($daysUntil))
                : '    already past threshold trajectory';
        }
        $rrd_options[] = 'COMMENT:' . RrdStore::safeDescr($trendText) . '\l';
    }

    /** @return array<int, float> timestamp => AVERAGE value (already multiplier-scaled) */
    private static function fetchAverageSeries(string $rrdFilename, string $ds, int $from, int $to, float $multiplier): array
    {
        $bin = LibrenmsConfig::get('rrdtool', 'rrdtool');
        $cmd = escapeshellcmd($bin) . ' fetch ' . escapeshellarg($rrdFilename)
              . ' AVERAGE --start ' . $from . ' --end ' . $to;
        exec($cmd . ' 2>/dev/null', $lines, $rc);
        if ($rc !== 0 || empty($lines)) {
            return [];
        }

        $header = array_shift($lines);
        $dsNames = preg_split('/\s+/', trim($header));
        $col = array_search($ds, $dsNames, true);
        if ($col === false) {
            return [];
        }

        $series = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || ! preg_match('/^(\d+):\s*(.*)$/', $line, $m)) {
                continue;
            }
            $val = preg_split('/\s+/', trim($m[2]))[$col] ?? null;
            if ($val === null || ! is_numeric($val)) {
                continue;
            }
            $series[(int) $m[1]] = (float) $val * $multiplier;
        }

        return $series;
    }
}
