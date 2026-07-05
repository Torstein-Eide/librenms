<?php

namespace LibreNMS\Util;

use LibreNMS\Data\Store\Rrd as RrdStore;

/**
 * Shared "Trend / Forecast" overlay for per-disk SMART RRD graphs: always draws a
 * long-term straight-line trend (rrdtool's own LSLSLOPE/LSLINT least-squares fit,
 * per https://hints.jeb.be/2009/12/04/trend-prediction-with-rrdtool/, fit across
 * every visible sample), then additionally draws the RRD's own Holt-Winters
 * forecast band (see RrdHwForecast) when the file was created with it enabled --
 * HWPREDICT is seasonal over one day there, so it's not a substitute for this
 * long-term line, only a complement to it for short-term aberrant-sample
 * flagging. When there's no HWPREDICT RRA (forecasting disabled, or an RRD type
 * -- e.g. NVMe -- that never gets one), a "days until threshold" estimate is
 * derived in PHP from a plain rrdtool fetch instead, since rrdtool's native fit
 * has no ready-made threshold-crossing output. Originated in
 * sata_attr_value.inc.php's per-attribute trend overlay; generalized here for
 * reuse across the other per-disk SMART graphs (includes/html/graphs/smart/*.inc.php).
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
     * $thresholdRawValue, if given, is used for a "days until threshold" estimate:
     * the linear-fallback path solves it directly from the slope/intercept it
     * just regressed. The Holt-Winters path (RrdHwForecast) instead uses
     * $persistedRatePerHour -- see that class for details. Both estimates always
     * use $ds's own native domain, never the display remap below.
     *
     * $displayScaleMultiplier/$displayScaleDivisor, if both given, remap every
     * plotted line via ",{mult},*,{div},/" before drawing -- for overlaying a
     * second DS (e.g. Normalized) into another series' shared pixel space, the
     * same way callers already remap that series itself (see
     * sata_attr_value.inc.php's norm_display CDEF). Leave both null to plot
     * $ds's own values directly (the common case).
     *
     * Convenience wrapper that draws the trend line and prints its legend text
     * back-to-back. Call appendPaint()/appendLegend() separately instead when the
     * two need to land in different places -- e.g. sata_attr_value.inc.php draws
     * the line before its own Normalized line (so Normalized paints on top of it)
     * but prints the legend text after Normalized's own Last/Min/Max row (so the
     * two group together in the legend).
     */
    public static function append(array &$rrd_options, string $rrdFilename, string $ds, string $varSuffix, float $multiplier, int $from, int $to, string $color = '#ff6600', ?float $thresholdRawValue = null, ?float $persistedRatePerHour = null, ?float $displayScaleMultiplier = null, ?float $displayScaleDivisor = null): void
    {
        self::appendPaint($rrd_options, $rrdFilename, $ds, $varSuffix, $multiplier, $color, $displayScaleMultiplier, $displayScaleDivisor);
        $summary = self::computeTrendSummary($rrdFilename, $ds, $multiplier, $from, $to, $thresholdRawValue);
        self::appendLegend($rrd_options, $varSuffix, $summary['crossingText']);
    }

    /**
     * Long-term straight-line trend, fit natively by rrdtool across every sample
     * visible in the graph (LSLSLOPE/LSLINT operate against the sample's
     * sequential COUNT, not real time -- see the blog post linked in the class
     * docblock). Drawn unconditionally, HWPREDICT RRA or not: when the graph's
     * end date is in the future, COUNT keeps incrementing across those
     * future/unknown rows same as its real ones, so this line projects straight
     * across that whole future span -- unlike RrdHwForecast's one-day-seasonal
     * band, which only ever looks about one seasonal period ahead.
     *
     * Draws the LINE with no legend text of its own -- see appendLegend() for
     * that, called separately so a caller can group the two apart in the
     * paint/legend order without duplicating a row.
     */
    public static function appendPaint(array &$rrd_options, string $rrdFilename, string $ds, string $varSuffix, float $multiplier, string $color = '#ff6600', ?float $displayScaleMultiplier = null, ?float $displayScaleDivisor = null): void
    {
        $v = $varSuffix;
        $m = RrdForecastSupport::fmt($multiplier);
        $rrd_options[] = "DEF:{$v}ltraw={$rrdFilename}:{$ds}:AVERAGE";
        $rrd_options[] = "CDEF:{$v}ltval={$v}ltraw,{$m},*";
        $rrd_options[] = "VDEF:{$v}ltslope={$v}ltval,LSLSLOPE";
        $rrd_options[] = "VDEF:{$v}ltint={$v}ltval,LSLINT";
        $rrd_options[] = "VDEF:{$v}ltcorrel={$v}ltval,LSLCORREL";
        // ",POP," discards ltval's own value at each step -- it's only there so COUNT
        // is evaluated against the same series length/timestamps as ltval.
        $rrd_options[] = "CDEF:{$v}ltrend={$v}ltval,POP,{$v}ltslope,COUNT,*,{$v}ltint,+";

        [$trendPlot] = RrdForecastSupport::remap($rrd_options, $v, ['ltrend'], $displayScaleMultiplier, $displayScaleDivisor);
        // rrdtool has no literal "dotted" line style -- a short dash/gap pair is the
        // closest approximation. Empty legend (nothing between the last two colons):
        // see appendLegend().
        $rrd_options[] = "LINE2:{$trendPlot}{$color}::dashes=1,3";
    }

    /**
     * Compact one-line legend for the trend line drawn by appendPaint() (same
     * $varSuffix): LSLCORREL (fit quality, -1..1, scale-independent) since the
     * line's slope isn't in an easily-labeled unit here (its "x" axis is sample
     * count, not seconds), plus $crossingText (from computeTrendSummary()) if
     * given. The day-rate itself isn't repeated here -- it's meant to be shown
     * once, on the series' own Last/Min/Max row (see computeTrendSummary()'s
     * 'rateText'). VDEF/CDEF availability to GPRINT doesn't depend on call
     * order, only $rrd_options's final content, so this can be positioned
     * anywhere relative to appendPaint() -- including after another series'
     * legend rows, to group the two.
     */
    public static function appendLegend(array &$rrd_options, string $varSuffix, ?string $crossingText = null, string $label = 'Long-term trend'): void
    {
        // No LAST/MIN/MAX here -- the fitted line's value range isn't a meaningful
        // "reading" the way a real data series' is; LSLCORREL (fit quality) is.
        $line = "{$label} (fit r=%.2lf)";
        if ($crossingText !== null) {
            $line .= '   ' . RrdStore::safeDescr($crossingText);
        }
        $rrd_options[] = "GPRINT:{$varSuffix}ltcorrel:{$line}\\l";
    }

    /**
     * Least-squares regression (plain PHP, over a fresh rrdtool fetch of the
     * graphed period) giving both the day-rate text meant for the series' own
     * Last/Min/Max row, and -- only when $thresholdRawValue is given -- a "days
     * until threshold" crossing estimate meant for appendLegend(). Separate from
     * appendPaint()'s native LSLSLOPE/LSLINT fit (which has no ready-made way to
     * turn into a crossing date, and fits against sample COUNT rather than real
     * time, so isn't in per-day units either).
     *
     * Never returns null/omits a row -- too little data, a degenerate fit, or a
     * flat (zero) trend all resolve to an explicit 'unknown' rather than silently
     * showing nothing, and a crossing estimate beyond 10 years is capped to
     * '>10 years' rather than printing a huge, not-really-meaningful day count.
     *
     * @return array{rateText: string, crossingText: ?string} crossingText is only
     *     null when $thresholdRawValue itself is null (no threshold to project against)
     */
    public static function computeTrendSummary(string $rrdFilename, string $ds, float $multiplier, int $from, int $to, ?float $thresholdRawValue): array
    {
        $unknown = ['rateText' => 'unknown', 'crossingText' => $thresholdRawValue !== null ? 'unknown' : null];

        $series = RrdForecastSupport::fetchAverageSeries($rrdFilename, $ds, $from, $to, $multiplier);
        if (count($series) < 2) {
            return $unknown;
        }

        // x is shifted to seconds-relative-to-$to (rather than used as a raw ~1.7e9
        // epoch timestamp) before summing -- otherwise sumX/sumXX reach ~1e11/1e20,
        // and $denom/$intercept below become the difference of two nearly-equal
        // huge numbers, losing almost all precision in a float64 (catastrophic
        // cancellation). That previously produced wildly wrong crossing estimates
        // despite a plausible-looking slope. Shifting doesn't change the slope
        // (invariant to an x-origin shift); it just makes $intercept directly the
        // fitted value *at* $to, so the crossing offset is $to plus a small,
        // well-conditioned correction.
        $n = count($series);
        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        foreach ($series as $x => $y) {
            $xr = $x - $to;
            $sumX += $xr;
            $sumY += $y;
            $sumXY += $xr * $y;
            $sumXX += $xr * $xr;
        }
        $denom = $n * $sumXX - $sumX * $sumX;
        if (abs($denom) <= 1e-9) {
            return $unknown;
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / $denom;
        if (abs($slope) <= 1e-12) {
            // A genuinely flat trend has no meaningful rate or crossing date --
            // "unknown" rather than "+0.00/day", which would misleadingly imply a
            // measured-but-zero rate rather than "can't tell from this data".
            return $unknown;
        }
        $intercept = ($sumY - $slope * $sumX) / $n;

        // SI-suffixed (Number::formatSi), same convention as this legend's GPRINT
        // %s/%S values -- these are plain PHP-formatted text, not GPRINT, so they'd
        // otherwise show long unscaled numbers when a rate/threshold is large.
        $dayRate = $slope * 86400;
        $rateText = ($dayRate >= 0 ? '+' : '') . trim(Number::formatSi($dayRate, 3, 0, '')) . '/day';

        $crossingText = null;
        if ($thresholdRawValue !== null) {
            // $intercept is the fitted value *at $to*, not at now -- $to is the
            // graph's end time, which is already in the future here (this overlay
            // only runs when $to > time()). Converting the crossing point back to
            // an absolute timestamp and measuring the gap from the *actual*
            // current time (not $to) is what makes "in N days" mean "N days from
            // today", matching RrdHwForecast::appendRateBasedCrossing()'s same
            // now-relative convention -- otherwise a graph window already set far
            // into the future silently undercounts the remaining days by however
            // far out $to already is.
            $crossTs = $to + ($thresholdRawValue - $intercept) / $slope;
            $daysUntil = ($crossTs - time()) / 86400;
            $threshDisplayText = trim(Number::formatSi($thresholdRawValue, 2, 0, ''));
            $crossingText = match (true) {
                $daysUntil > 3650 => sprintf('crosses threshold %s in >10 years', $threshDisplayText),
                $daysUntil > 0 => sprintf('crosses threshold %s in %d days', $threshDisplayText, (int) round($daysUntil)),
                default => 'already past threshold trajectory',
            };
        }

        return ['rateText' => $rateText, 'crossingText' => $crossingText];
    }
}
