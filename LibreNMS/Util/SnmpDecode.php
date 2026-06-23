<?php

namespace LibreNMS\Util;

/**
 * Generic SNMP scalar decoding for standard textual conventions, usable by
 * any poller/discovery code (not tied to a specific MIB or app):
 *
 * - parseBitsValue()/parseDateAndTime(): SNMPv2-TC BITS and DateAndTime.
 * - applySensorScaleCol()/sensorScaleColumns(): RFC 3433 (ENTITY-SENSOR-MIB)
 *   scale/precision math, parameterized by the caller's own scale-exponent
 *   enum map since that enum's exact values are MIB-specific.
 */
final class SnmpDecode
{
    /**
     * Parse a SNMP BITS value to an integer.
     * Handles hex strings ("E0", "0xE0"), raw integers, and decimal strings.
     */
    public static function parseBitsValue(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        if (is_int($raw)) {
            return $raw;
        }
        $str = trim((string) $raw);
        if ($str === '') {
            return null;
        }
        if (! preg_match('/^(?:0x)?([0-9A-Fa-f]+)$/', $str, $m)) {
            return null;
        }

        return (int) hexdec($m[1]);
    }

    /**
     * Convert a SNMP DateAndTime string (e.g. "2026-6-6,22:15:11.0,+2:0")
     * to a MySQL-compatible datetime string ("2026-06-06 22:15:11"), or null.
     */
    public static function parseDateAndTime(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $pattern = '/^(\d{4})-(\d{1,2})-(\d{1,2}),(\d{1,2}):(\d{2}):(\d{2})(?:\.\d+)?(?:,[+-]\d+:\d+)?$/';
        if (! preg_match($pattern, trim((string) $raw), $m)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
    }

    /**
     * Compute the actual physical value from an ENTITY-SENSOR-MIB-shaped row
     * (RFC 3433): actual value = col × 10^(scale_exponent − precision).
     *
     * @param  array<int, int>  $scaleExpMap  the MIB's scale enum => power of 10 exponent
     */
    public static function applySensorScaleCol(array $row, string $valueCol, string $scaleCol, string $precisionCol, array $scaleExpMap): ?float
    {
        $raw = $row[$valueCol] ?? null;
        if ($raw === null) {
            return null;
        }

        $scaleEnum = (int) ($row[$scaleCol] ?? 9); // units(9 = 10^0)
        $precision = (int) ($row[$precisionCol] ?? 0);
        $exp = $scaleExpMap[$scaleEnum] ?? 0;

        return (float) $raw * (10 ** ($exp - $precision));
    }

    /**
     * Translate a scale enum + precision into sensor_divisor / sensor_multiplier
     * so a poller can scale a raw sensor value at poll time (e.g. milli(8)
     * precision 0 → divisor 1000: 12169 → 12.169). Both keys are always
     * returned so a re-discovery resets any stale factor.
     *
     * @param  array<int, int>  $scaleExpMap  the MIB's scale enum => power of 10 exponent
     * @return array{sensor_divisor: int, sensor_multiplier: int}
     */
    public static function sensorScaleColumns(array $row, string $scaleCol, string $precisionCol, array $scaleExpMap): array
    {
        $scaleEnum = (int) ($row[$scaleCol] ?? 9);
        $precision = (int) ($row[$precisionCol] ?? 0);
        $exp = ($scaleExpMap[$scaleEnum] ?? 0) - $precision;

        return $exp >= 0
            ? ['sensor_divisor' => 1, 'sensor_multiplier' => 10 ** $exp]
            : ['sensor_divisor' => 10 ** (-$exp), 'sensor_multiplier' => 1];
    }
}
