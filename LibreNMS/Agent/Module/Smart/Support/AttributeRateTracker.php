<?php

namespace LibreNMS\Agent\Module\Smart\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LibreNMS\Data\Store\Rrd;

/**
 * Rate-of-change tracking for one disk's smart_sata_attributes rows: computes
 * average raw-value change per hour over the 8h/24h/168h/672h lookback
 * windows from RRD history, persists it, and resolves rate_status (-1/1/2)
 * against the configured rate-of-change threshold.
 *
 * Extracted so both the SNMP-MIB path (SataHandler, which can additionally
 * resolve a per-attribute DS name from smartmonSataAttrFormat and a
 * device-reported status) and the Unix-agent JSON path (SmartJsonV1, which
 * only ever has a fixed attribute ID with no format/status data, so every
 * attribute's DS is just "id{N}" and its device-reported status is always
 * unknown) can share the exact same RRD-history-fetch and threshold logic --
 * that logic only reads back the id{N} DS this class's caller already wrote,
 * so it never needed anything handler-specific to begin with.
 *
 * combineStatus()/resolveRateStatus()/loadThresholdRows() are public (not
 * just called internally by sync()) because SataHandler's poll-time path
 * (syncSataAttributeRowsPoll()) also re-evaluates rate_status against the
 * rate_8h/24h/168h/672h values discovery already persisted, without
 * recomputing them from RRD history every poll.
 */
final class AttributeRateTracker
{
    private const RATE_WINDOWS = [
        '8h' => 28800,
        '24h' => 86400,
        '168h' => 604800,
        '672h' => 2419200,
    ];

    /**
     * @param  array<int, array{ds: string|null, status: int|null}>  $attrs  attribute_id => the RRD dataset to track for rate-of-change (null if this attribute's format has no single trackable value), and its device-reported status (null if unavailable)
     */
    public static function sync(int $appId, int $deviceId, string $hostname, string $diskKey, string $rrdFilename, array $attrs): void
    {
        $rrd = app(Rrd::class);
        $now = time();

        $rrdTypes = DB::table('smart_sata_attributes')
            ->where('app_id', $appId)
            ->where('disk_key', $diskKey)
            ->pluck('rrd_type', 'attribute_id');

        $counterDs = [];
        $gaugeDs = [];
        foreach ($attrs as $id => $attr) {
            $ds = $attr['ds'];
            if ($ds === null) {
                continue;
            }
            // id{N}Hi (div-format sub-DS) is always written GAUGE by pollSataDeviceRrd(),
            // regardless of what rrd_type says about the base attribute.
            if ($ds === 'id' . $id && ($rrdTypes[$id] ?? null) === 'COUNTER') {
                $counterDs[] = $ds;
            } else {
                $gaugeDs[] = $ds;
            }
        }

        [$ratesByDs, $failedWindows] = self::fetchAttributeRates($rrd, $rrdFilename, $counterDs, $gaugeDs, $now);
        $thresholdRows = self::loadThresholdRows($appId, $diskKey);

        // A window whose rrdtool fetch failed outright (timeout, process error) keeps
        // whatever rate was last persisted for it instead of being nulled out. A
        // transient fetch failure must not be indistinguishable from "no data".
        $previousRates = $failedWindows !== []
            ? DB::table('smart_sata_attributes')
                ->where('app_id', $appId)
                ->where('disk_key', $diskKey)
                ->get(['attribute_id', 'rate_8h', 'rate_24h', 'rate_168h', 'rate_672h'])
                ->keyBy('attribute_id')
            : collect();

        foreach ($attrs as $id => $attr) {
            $ds = $attr['ds'];
            $previous = $previousRates->get($id);
            // No trackable single-valued dataset for this attribute's format (e.g. raw8/raw16
            // split into independent id{N}P0..P5 byte/word counters) -- leave rates null rather
            // than guessing at a combined value.
            $rates = $ds === null ? ['8h' => null, '24h' => null, '168h' => null, '672h' => null] : [
                '8h' => $ratesByDs[$ds]['8h'] ?? ($failedWindows['8h'] ?? false ? $previous?->rate_8h : null),
                '24h' => $ratesByDs[$ds]['24h'] ?? ($failedWindows['24h'] ?? false ? $previous?->rate_24h : null),
                '168h' => $ratesByDs[$ds]['168h'] ?? ($failedWindows['168h'] ?? false ? $previous?->rate_168h : null),
                '672h' => $ratesByDs[$ds]['672h'] ?? ($failedWindows['672h'] ?? false ? $previous?->rate_672h : null),
            ];
            $rateStatus = self::resolveRateStatus($thresholdRows, $id, $rates);

            DbSync::upsert('smart_sata_attributes', [
                'app_id'       => $appId,
                'device_id'    => $deviceId,
                'disk_key'     => $diskKey,
                'attribute_id' => $id,
                'rate_8h'      => $rates['8h'],
                'rate_24h'     => $rates['24h'],
                'rate_168h'    => $rates['168h'],
                'rate_672h'    => $rates['672h'],
                'status'       => self::combineStatus($attr['status'], $rateStatus),
                'rate_status'  => $rateStatus,
            ], ['app_id', 'disk_key', 'attribute_id']);
        }
    }

    /**
     * Average change per hour, per RRD dataset, for every lookback window.
     *
     * 5 rrdtool subprocesses total per disk, regardless of how many SMART
     * attributes it has:
     *  - 1 shared call for GAUGE's "current" end-of-window boundary probe
     *    ([now-probe, now]) -- identical for every lookback window (probe is
     *    far smaller than the shortest window, 8h), so it's fetched once
     *    here instead of once per window as before.
     *  - 4 calls, one per lookback window, each combining that window's
     *    COUNTER full-window rate AND its GAUGE start-of-window boundary
     *    probe into a single rrdtool invocation via
     *    Rrd::getMultiWindowAverages() (per-dataset DEF start=/end=
     *    overrides), instead of two separate calls per window.
     * Trade-off: a process failure (timeout/crash) on one of the 4 per-window
     * calls now fails BOTH that window's COUNTER and GAUGE datasets together,
     * where they previously failed independently -- an acceptable loss of
     * fault isolation for a rare failure mode, in exchange for a third of the
     * subprocess count (was up to 12: 4 COUNTER + 8 GAUGE boundary probes).
     * Looping a single dataset per call here previously spawned one
     * subprocess per attribute per window, which exhausted the open-file
     * limit on disks with 30+ attributes -- still avoided.
     *
     * @param  array<string>  $counterDs
     * @param  array<string>  $gaugeDs
     * @return array{0: array<string, array<string, float>>, 1: array<string, bool>} [dataset => window suffix => rate, window suffix => fetch failed]
     */
    private static function fetchAttributeRates(Rrd $rrd, string $filename, array $counterDs, array $gaugeDs, int $now): array
    {
        $ratesByDs = [];
        $failedWindows = [];
        $probe = 600; // 10 minutes, well above the default 5-minute poll step, and every RATE_WINDOWS entry

        $endVals = [];
        if ($gaugeDs !== []) {
            $endVals = $rrd->getWindowAverages($filename, $gaugeDs, $now - $probe, $now);
            if ($endVals === null) {
                Log::error("smart_mib: fetchAttributeRates: gauge current-value fetch FAILED file={$filename}, keeping previously persisted rates for every window");
                foreach (self::RATE_WINDOWS as $suffix => $seconds) {
                    $failedWindows[$suffix] = true;
                }
                $endVals = [];
                $gaugeDs = [];
            }
        }

        foreach (self::RATE_WINDOWS as $suffix => $seconds) {
            $start = $now - $seconds;
            $hours = $seconds / 3600;

            $windows = array_fill_keys($counterDs, [$start, $now])
                + array_fill_keys($gaugeDs, [$start, min($start + $probe, $now)]);
            if ($windows === []) {
                continue;
            }

            $combined = $rrd->getMultiWindowAverages($filename, $windows);
            if ($combined === null) {
                $failedWindows[$suffix] = true;
                Log::error("smart_mib: fetchAttributeRates: fetch FAILED for window={$suffix} file={$filename}, keeping previously persisted rates for this window");
                continue;
            }

            foreach ($counterDs as $ds) {
                if (isset($combined[$ds])) {
                    $ratesByDs[$ds][$suffix] = $combined[$ds] * 3600;
                }
            }
            foreach ($gaugeDs as $ds) {
                if (isset($combined[$ds], $endVals[$ds])) {
                    $ratesByDs[$ds][$suffix] = ($endVals[$ds] - $combined[$ds]) / $hours;
                }
            }
        }

        return [$ratesByDs, $failedWindows];
    }

    /**
     * Fold rate_status into the displayed `status`: a rate-of-change breach (rate_status=2)
     * escalates status to 4 (Rate exceeded) even if the device itself reports the attribute
     * fine. A device-reported notRelevant(-1), meaning the disk has no failure threshold
     * for this attribute, is treated as ok(1) once a rate-of-change threshold is enabled and
     * not breached, since the rate threshold then stands in for the missing device one.
     * $rawStatus is null when the data source has no device-reported status at all (e.g.
     * SmartJsonV1's payload) -- status then stays null unless a rate breach escalates it.
     */
    public static function combineStatus(?int $rawStatus, int $rateStatus): ?int
    {
        if ($rateStatus === 2) {
            return 4;
        }

        if ($rawStatus === -1 && $rateStatus === 1) {
            return 1;
        }

        return $rawStatus;
    }

    /**
     * Resolve smart_sata_attributes.rate_status for one attribute: -1 (no rate-of-change
     * threshold enabled for this disk/attribute), 1 (enabled, no window exceeds it), or
     * 2 (enabled, at least one window exceeds it). Independent of the device-reported
     * `status` column, so polling and discovery never fight over the same field.
     *
     * @param  Collection<int, object>  $thresholdRows  this disk's rows, from loadThresholdRows()
     */
    public static function resolveRateStatus(Collection $thresholdRows, int $attrId, array $rates): int
    {
        $rows = $thresholdRows->where('attribute_id', $attrId);
        $diskRow = $rows->firstWhere('disk_key', '!=', '');
        $globalRow = $rows->firstWhere('disk_key', '');

        // Per-disk override decides alerting on/off when present; otherwise the global
        // default's switch applies. Muting here short-circuits before any limit check,
        // so a configured warn_rate_* never alerts while its row is switched off.
        $alertEnabled = (bool) (($diskRow->alert_enabled ?? null) ?? ($globalRow->alert_enabled ?? null) ?? true);
        if (! $alertEnabled) {
            return -1;
        }

        $limits = self::effectiveLimits($thresholdRows, $attrId);
        if (! self::hasEnabledThreshold($limits)) {
            return -1;
        }

        return self::rateExceedsThreshold($limits, $rates) ? 2 : 1;
    }

    /**
     * Every smart_attribute_thresholds row that can apply to this disk: its own per-disk
     * overrides plus every global-default row (app_id=0, disk_key=''). Fetched once per
     * disk so effectiveLimits() can look up a given attribute_id in memory rather than
     * re-querying per attribute. This runs in the poller hot path.
     */
    public static function loadThresholdRows(int $appId, string $diskKey): Collection
    {
        return DB::table('smart_attribute_thresholds')
            ->where(function ($q) use ($appId, $diskKey) {
                $q->where(['app_id' => $appId, 'disk_key' => $diskKey])
                    ->orWhere(['app_id' => 0, 'disk_key' => '']);
            })
            ->get();
    }

    /**
     * Effective rate-of-change limit per window, merged column-by-column: the per-disk
     * override wins for a given window only when it's actually enabled there; otherwise
     * that window falls back to the global default. A single ::first() pick between the
     * two rows would let an override with no enabled windows fully shadow a configured
     * global default, instead of falling back to it.
     *
     * @param  Collection<int, object>  $thresholdRows  this disk's rows, from loadThresholdRows()
     * @return array<string, float|null> window suffix (8h/24h/168h/672h) => limit
     */
    private static function effectiveLimits(Collection $thresholdRows, int $attrId): array
    {
        $rows = $thresholdRows->where('attribute_id', $attrId);
        $diskRow = $rows->firstWhere('disk_key', '!=', '');
        $globalRow = $rows->firstWhere('disk_key', '');

        $limits = [];
        foreach (['8h' => 'warn_rate_8h', '24h' => 'warn_rate_24h', '168h' => 'warn_rate_168h', '672h' => 'warn_rate_672h'] as $suffix => $column) {
            $limits[$suffix] = ($diskRow !== null ? self::thresholdLimit($diskRow, $column) : null)
                ?? ($globalRow !== null ? self::thresholdLimit($globalRow, $column) : null);
        }

        return $limits;
    }

    /**
     * A configured warn_rate_* limit, or null if unset/0. 0 means "no limit" (disabled),
     * not "warn on any change", so it must not be treated as an active threshold.
     */
    private static function thresholdLimit(object $threshold, string $column): ?float
    {
        $value = $threshold->$column ?? null;

        return $value !== null && (float) $value > 0 ? (float) $value : null;
    }

    /** True if any window has an enabled rate-of-change limit. */
    private static function hasEnabledThreshold(array $limits): bool
    {
        foreach ($limits as $limit) {
            if ($limit !== null) {
                return true;
            }
        }

        return false;
    }

    /** True if any rate window exceeds its effective limit. */
    private static function rateExceedsThreshold(array $limits, array $rates): bool
    {
        foreach ($limits as $suffix => $limit) {
            $rate = $rates[$suffix] ?? null;
            if ($limit !== null && $rate !== null && abs($rate) > $limit) {
                return true;
            }
        }

        return false;
    }
}
