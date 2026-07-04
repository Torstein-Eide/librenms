<?php

namespace LibreNMS\Agent\Module\Smart\Support;

use Illuminate\Support\Facades\DB;

/**
 * Resolves whether Holt-Winters aberrant-behavior forecasting should be
 * enabled for a given app/device's per-disk RRD (see
 * SataHandler::pollSataDeviceRrd()): a global default (smart_app_settings
 * app_id=0 row) that any specific app's own row can override. Shared between
 * the settings controller (for display) and SataHandler (for RRD creation
 * gating), so both agree on the same value.
 *
 * Enabled by default: when on, the per-disk RRD file is created with an
 * HWPREDICT-inclusive RRA list instead of the global rrd_rra default, applied
 * file-wide to every DS in that file (see SataHandler::hwForecastRra()).
 */
final class HwForecastSetting
{
    /** Sentinel app_id for the global default row, same convention as naming_template. */
    public const GLOBAL_APP_ID = 0;

    public static function resolve(int $appId): bool
    {
        $global = (bool) (DB::table('smart_app_settings')->where('app_id', self::GLOBAL_APP_ID)->value('enable_hw_forecast') ?? true);

        if ($appId === self::GLOBAL_APP_ID) {
            return $global;
        }

        $override = DB::table('smart_app_settings')->where('app_id', $appId)->value('enable_hw_forecast');

        return $override !== null ? (bool) $override : $global;
    }
}
