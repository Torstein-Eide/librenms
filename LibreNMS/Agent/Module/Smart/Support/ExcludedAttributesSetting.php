<?php

namespace LibreNMS\Agent\Module\Smart\Support;

use Illuminate\Support\Facades\DB;

/**
 * Resolves which ATA attributes to exclude as candidate input to the rotating-disk
 * (HDD) Wear sensor (see SataHandler::rotatingWearPercent()): a global default list
 * (smart_app_settings app_id=0 row's wear_excluded_attributes column) that an app's
 * own disk_wear_excluded_attributes JSON map can override per disk_key. Shared between the
 * settings controller (for display/editing) and SataHandler (for the actual
 * computation), so both agree on the same list.
 *
 * This has no effect on discovery, polling, RRD storage, or the attribute
 * table/graphs -- every attribute is always fully discovered/polled/stored/shown
 * regardless of this setting. It only decides which attributes are ignored when
 * scanning for the lowest normalized value to use as the Wear sensor's reading.
 *
 * Each entry: {"type": "name"|"regex"|"id", "pattern": string, "ids": int[]|null,
 * "min_id": int|null, "comment": string|null}. For type "id", pattern is the exact
 * numeric attribute ID to match, independent of name (e.g. excluding id 5 or 17
 * outright when a vendor reports one of them as spare-capacity data under a name
 * that wouldn't match the regex entries below). For "name"/"regex", "ids"/"min_id"
 * optionally gate the match by the attribute's numeric ID too (mirrors
 * smartmon_agentx.py's _guess_sata_wear ID checks for the spare/endurance
 * attributes below); when both are absent, the entry matches on name alone
 * regardless of ID.
 */
final class ExcludedAttributesSetting
{
    /** Sentinel app_id for the global default row, same convention as naming_template. */
    public const GLOBAL_APP_ID = 0;

    /** Built-in exclude list used until an install customizes wear_excluded_attributes. */
    private const DEFAULTS = [
        [
            'type' => 'regex', 'pattern' => '/temp/i',
            'comment' => 'Temperature fluctuates independently of mechanical wear.',
        ],
        [
            'type' => 'name', 'pattern' => 'Head Flying Hours',
            'comment' => 'Workload/usage counter, not a wear indicator by itself.',
        ],
        [
            'type' => 'name', 'pattern' => 'Total LBAs Written',
            'comment' => 'Workload counter (bytes written) that would give a busy but healthy drive a falsely low Wear score.',
        ],
        [
            'type' => 'name', 'pattern' => 'Total LBAs Read',
            'comment' => 'Workload counter (bytes read) that would give a busy but healthy drive a falsely low Wear score.',
        ],
        [
            'type' => 'name', 'pattern' => 'Free Fall Event Count',
            'comment' => 'Shock/vibration tally from an accelerometer, not itself a wear signal.',
        ],
        [
            'type' => 'name', 'pattern' => 'UDMA CRC Error Count',
            'comment' => 'Usually reflects a cabling/interface problem rather than drive wear.',
        ],
        [
            'type' => 'name', 'pattern' => 'Load Cycle Count',
            'comment' => 'Normal head park/unpark counter, not a reliable wear signal by itself.',
        ],
        [
            'type' => 'regex', 'pattern' => '/^Spare_Blocks/i', 'ids' => [5, 17], 'min_id' => 100,
            'comment' => 'Typically SSD-specific spare-capacity attribute that doesn\'t reflect wear on a rotating disk.',
        ],
        [
            'type' => 'id', 'pattern' => '5',
            'comment' => 'Normally Reallocated_Sector_Ct, a spare-sector-usage signal (part of the spare indicator, not a wear indicator).',
        ],
        [
            'type' => 'id', 'pattern' => '17',
            'comment' => 'Some vendors report spare-capacity/TRIM data under this id (e.g. as "Current_TRIM_Percent").',
        ],
        [
            'type' => 'regex', 'pattern' => '/^SSD_Life_Left/i', 'min_id' => 100,
            'comment' => 'Typically SSD-specific spare attribute.',
        ],
        [
            'type' => 'regex', 'pattern' => '/^Wear_Leveling/i', 'min_id' => 100,
            'comment' => 'Typically SSD-specific spare attribute.',
        ],
    ];

    /**
     * @return array<int, array{type: string, pattern: string, ids?: array<int,int>, min_id?: int, comment?: ?string}>
     */
    public static function resolve(int $appId, string $diskKey): array
    {
        $globalRaw = DB::table('smart_app_settings')->where('app_id', self::GLOBAL_APP_ID)->value('wear_excluded_attributes');
        $global = $globalRaw !== null ? (json_decode((string) $globalRaw, true) ?: []) : self::DEFAULTS;

        if ($appId === self::GLOBAL_APP_ID) {
            return $global;
        }

        $diskMapRaw = DB::table('smart_app_settings')->where('app_id', $appId)->value('disk_wear_excluded_attributes');
        $diskMap = $diskMapRaw !== null ? (json_decode((string) $diskMapRaw, true) ?: []) : [];

        return array_key_exists($diskKey, $diskMap) ? $diskMap[$diskKey] : $global;
    }

    /**
     * @param  array<int, array{type: string, pattern: string, ids?: array<int,int>, min_id?: int}>  $entries
     */
    public static function isExcluded(?string $attrName, ?int $attrId, array $entries): bool
    {
        foreach ($entries as $entry) {
            $type = $entry['type'] ?? 'name';

            if ($type === 'id') {
                $idPattern = $entry['pattern'] ?? '';
                if ($attrId !== null && is_numeric($idPattern) && $attrId === (int) $idPattern) {
                    return true;
                }

                continue;
            }

            if ($attrName === null || $attrName === '' || ! self::entryMatchesName($attrName, $entry)) {
                continue;
            }

            $ids = $entry['ids'] ?? null;
            $minId = $entry['min_id'] ?? null;

            if ($ids === null && $minId === null) {
                return true;
            }

            if ($attrId !== null
                && (($ids !== null && in_array($attrId, $ids, true)) || ($minId !== null && $attrId >= $minId))) {
                return true;
            }
        }

        return false;
    }

    private static function entryMatchesName(string $attrName, array $entry): bool
    {
        $type = $entry['type'] ?? 'name';
        $pattern = (string) ($entry['pattern'] ?? '');

        if ($pattern === '') {
            return false;
        }

        if ($type === 'regex') {
            return @preg_match($pattern, $attrName) === 1;
        }

        return self::normalize($attrName) === self::normalize($pattern);
    }

    /** Case-insensitive, underscore/space-agnostic comparison key. */
    private static function normalize(string $name): string
    {
        return strtolower(trim(str_replace('_', ' ', $name)));
    }
}
