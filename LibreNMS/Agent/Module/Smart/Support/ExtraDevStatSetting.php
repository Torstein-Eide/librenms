<?php

namespace LibreNMS\Agent\Module\Smart\Support;

use Illuminate\Support\Facades\DB;

/**
 * Resolves whether the allowlisted Device Statistics DS (see
 * DevStatRrdCatalog) should be logged to RRD for a given app/device:
 * a global default (smart_app_settings app_id=0 row) that any specific app's
 * own row can override. Shared between the settings controller (for display)
 * and SataHandler (for RRD write/retrofit gating), so both agree on the same
 * value.
 */
final class ExtraDevStatSetting
{
    /** Sentinel app_id for the global default row, same convention as naming_template. */
    public const GLOBAL_APP_ID = 0;

    public static function resolve(int $appId): bool
    {
        $global = (bool) (DB::table('smart_app_settings')->where('app_id', self::GLOBAL_APP_ID)->value('log_extra_dev_stats') ?? false);

        if ($appId === self::GLOBAL_APP_ID) {
            return $global;
        }

        $override = DB::table('smart_app_settings')->where('app_id', $appId)->value('log_extra_dev_stats');

        return $override !== null ? (bool) $override : $global;
    }
}
