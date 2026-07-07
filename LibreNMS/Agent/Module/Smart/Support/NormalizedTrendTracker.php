<?php

namespace LibreNMS\Agent\Module\Smart\Support;

use LibreNMS\Data\Store\Rrd;
use LibreNMS\Util\RrdForecastSupport;
use LibreNMS\Util\RrdTrendForecast;

/**
 * "Time until Normalized crosses Thresh" tracking for one disk's
 * smart_sata_attributes rows: fits two independent straight-line trends (a
 * 1-month and a 6-month lookback, both ending "now") against each eligible
 * attribute's Normalized DS, and persists the resulting day-count estimates.
 *
 * Runs at discovery time only (RrdTrendForecast::computeTrendFromSeries()'s
 * fit barely moves between 5-minute polls over a 1-6 month window, so
 * recomputing it every poll -- like AttributeRateTracker's rate_8h/24h/168h/672h,
 * whose windows are short enough to actually shift meaningfully per poll --
 * would just be repeated cost for the same answer). See
 * SataHandler::syncSataAttributeRates() for the equivalent rate-of-change
 * tracker this mirrors, and the SATA Basic view's attribute table
 * (HtmlData::attrNormalizedTrendRanges()), which only ever reads these
 * persisted columns back -- never fetches RRD history itself.
 */
final class NormalizedTrendTracker
{
    private const ONE_MONTH_SECONDS = 2678400; // matches ConfigRepository's time.month
    private const SIX_MONTH_SECONDS = 16070400; // matches ConfigRepository's time.sixmonth

    /** Temperature attributes: own dedicated sensor graph, not a meaningful Normalized-crossing projection -- same exclusion as sata_attr_value.inc.php's $showNormalizedTrend. */
    private const NO_TREND_ATTRS = [194, 190];

    /**
     * @param  array<int, array{threshold: float|null, status: int|null}>  $attrs  attribute_id => this attribute's device-reported failure threshold and status
     */
    public static function sync(int $appId, int $deviceId, string $diskKey, string $rrdFilename, array $attrs): void
    {
        $rrd = app(Rrd::class);
        $existing = array_flip($rrd->listDatasets($rrdFilename));

        $dsToAttr = [];
        foreach ($attrs as $id => $attr) {
            $thresh = $attr['threshold'];
            if ($attr['status'] === -1
                || in_array($id, self::NO_TREND_ATTRS, true)
                || ! is_numeric($thresh) || (float) $thresh <= 0) {
                continue;
            }
            $ds = 'id' . $id . 'Normalized';
            if (isset($existing[$ds])) {
                $dsToAttr[$ds] = $id;
            }
        }

        if ($dsToAttr === []) {
            return;
        }

        $now = time();
        // One multi-DS fetch covering the longer window for every eligible attribute on
        // this disk, instead of one rrdtool exec per attribute (a disk can carry 20-30
        // tracked attributes, all living in the same RRD file) -- the 1-month fit below
        // reuses the tail of this same fetched series rather than fetching again.
        $sixMonthSeries = RrdForecastSupport::fetchAverageSeriesMulti($rrdFilename, array_keys($dsToAttr), $now - self::SIX_MONTH_SECONDS, $now);
        $oneMonthCutoff = $now - self::ONE_MONTH_SECONDS;

        foreach ($dsToAttr as $ds => $id) {
            $series6mo = $sixMonthSeries[$ds] ?? [];
            $series1mo = array_filter($series6mo, static fn (int $ts) => $ts >= $oneMonthCutoff, ARRAY_FILTER_USE_KEY);
            $thresh = (float) $attrs[$id]['threshold'];

            $fit1mo = RrdTrendForecast::computeTrendFromSeries($series1mo, $now, $thresh);
            $fit6mo = RrdTrendForecast::computeTrendFromSeries($series6mo, $now, $thresh);

            DbSync::upsert('smart_sata_attributes', [
                'app_id'         => $appId,
                'device_id'      => $deviceId,
                'disk_key'       => $diskKey,
                'attribute_id'   => $id,
                'trend_days_1mo' => $fit1mo['daysUntil'] ?? null,
                'trend_days_6mo' => $fit6mo['daysUntil'] ?? null,
            ], ['app_id', 'disk_key', 'attribute_id']);
        }
    }
}
