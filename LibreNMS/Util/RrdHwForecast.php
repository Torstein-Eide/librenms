<?php

namespace LibreNMS\Util;

use LibreNMS\Data\Store\Rrd as RrdStore;

/**
 * Holt-Winters forecast overlay (HWPREDICT/DEVPREDICT/FAILURES) for one DS,
 * drawn when its RRD file was created with Holt-Winters forecasting enabled
 * (see SataHandler::hwForecastRra()/HwForecastSetting). Seasonal over a 1-day
 * period here, so it's tuned for short-term aberrant-sample flagging (the
 * FAILURES ticks), not a long-term drift estimate -- see RrdTrendForecast for
 * that. Split out from RrdTrendForecast so the two overlays can be worked on
 * independently.
 */
final class RrdHwForecast
{
    /**
     * Append the Holt-Winters band for one DS onto $rrd_options.
     *
     * $varSuffix must be unique per call within a single graph invocation so the
     * rrdtool variable names emitted for multiple overlaid series don't collide.
     *
     * $thresholdRawValue/$persistedRatePerHour, if both given, produce a "days
     * until threshold" COMMENT: HWPREDICT has no long-term drift figure of its
     * own to solve that from, so this instead projects forward from
     * $persistedRatePerHour -- a rate the caller already computed and persisted
     * elsewhere (e.g. AttributeRateTracker's rate_672h, the same
     * average-change-per-hour figure shown in the settings threshold tables) --
     * and the series' actual last value. Always uses $ds's own native domain,
     * never the display remap below.
     *
     * $displayScaleMultiplier/$displayScaleDivisor, if both given, remap the
     * plotted band via ",{mult},*,{div},/" before drawing -- for overlaying a
     * second DS (e.g. Normalized) into another series' shared pixel space. See
     * RrdTrendForecast::append() for the full explanation.
     */
    public static function append(array &$rrd_options, string $rrdFilename, string $ds, string $varSuffix, float $multiplier, string $color, ?float $thresholdRawValue = null, ?float $persistedRatePerHour = null, ?float $displayScaleMultiplier = null, ?float $displayScaleDivisor = null): void
    {
        $v = $varSuffix;
        $m = RrdForecastSupport::fmt($multiplier);
        $rrd_options[] = "DEF:{$v}predraw={$rrdFilename}:{$ds}:HWPREDICT";
        $rrd_options[] = "DEF:{$v}devraw={$rrdFilename}:{$ds}:DEVPREDICT";
        $rrd_options[] = "DEF:{$v}failraw={$rrdFilename}:{$ds}:FAILURES";
        $rrd_options[] = "CDEF:{$v}pred={$v}predraw,{$m},*";
        $rrd_options[] = "CDEF:{$v}dev={$v}devraw,{$m},*";
        $rrd_options[] = "CDEF:{$v}hwup={$v}pred,{$v}dev,2,*,+";
        $rrd_options[] = "CDEF:{$v}hwlo={$v}pred,{$v}dev,2,*,-";

        // $color distinguishes which DS's band this is when more than one overlay is
        // drawn on the same graph (e.g. Raw + Normalized).
        [$upPlot, $loPlot] = RrdForecastSupport::remap($rrd_options, $v, ['hwup', 'hwlo'], $displayScaleMultiplier, $displayScaleDivisor);
        $rrd_options[] = "LINE1:{$upPlot}{$color}:HW forecast (1-day):dashes";
        $rrd_options[] = "GPRINT:{$v}pred:LAST:%5.1lf%s";
        $rrd_options[] = "GPRINT:{$v}pred:MIN:%5.1lf%S";
        $rrd_options[] = "GPRINT:{$v}pred:MAX:%5.1lf%S\\l";
        $rrd_options[] = "LINE1:{$loPlot}{$color}:+/- deviation:dashes";
        $rrd_options[] = "VDEF:{$v}devavg={$v}dev,AVERAGE";
        $rrd_options[] = "GPRINT:{$v}devavg:%5.1lf%s (avg)\\l";
        $rrd_options[] = "TICK:{$v}failraw#ff000022:1.0:Aberrant samples\\l";

        self::appendRateBasedCrossing($rrd_options, $rrdFilename, $ds, $multiplier, $thresholdRawValue, $persistedRatePerHour);
    }

    private static function appendRateBasedCrossing(array &$rrd_options, string $rrdFilename, string $ds, float $multiplier, ?float $thresholdRawValue, ?float $ratePerHour): void
    {
        if ($thresholdRawValue === null || $ratePerHour === null || abs($ratePerHour) <= 1e-9) {
            return;
        }

        $now = time();
        $series = RrdForecastSupport::fetchAverageSeries($rrdFilename, $ds, $now - 7200, $now, $multiplier);
        if ($series === []) {
            return;
        }
        $lastValue = $series[array_key_last($series)];

        $daysUntil = ($thresholdRawValue - $lastValue) / ($ratePerHour * 24);
        // SI-suffixed (Number::formatSi), same convention as this legend's GPRINT
        // %s/%S values -- these are plain PHP-formatted COMMENT text, not GPRINT, so
        // they'd otherwise show long unscaled numbers when a threshold/rate is large.
        $threshDisplayText = trim(Number::formatSi($thresholdRawValue, 2, 0, ''));
        $dayRate = $ratePerHour * 24;
        $trendText = 'trend: ' . ($dayRate >= 0 ? '+' : '') . trim(Number::formatSi($dayRate, 3, 0, '')) . '/day (persisted rate)';
        $trendText .= $daysUntil > 0
            ? sprintf('    crosses threshold %s in ~ %d days', $threshDisplayText, (int) round($daysUntil))
            : '    already past threshold trajectory';

        $rrd_options[] = 'COMMENT:' . RrdStore::safeDescr($trendText) . '\l';
    }
}
