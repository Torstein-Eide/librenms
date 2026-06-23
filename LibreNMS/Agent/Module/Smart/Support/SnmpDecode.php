<?php

namespace LibreNMS\Agent\Module\Smart\Support;

/**
 * Stateless SNMP value decoding shared by the SMART MIB discovery/poll pipeline:
 * coercing enum/typed SNMP scalars to plain PHP values. Extracted from
 * {@see \LibreNMS\Agent\Module\Smart\Common} so the SATA/NVMe (and future SAS)
 * pipelines can reuse the same decoders without depending on Common itself.
 */
final class SnmpDecode
{
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
     * Compute the actual physical value from a SENSOR-MIB row.
     * Actual value = col × 10^(scale_exponent − precision)
     *
     * @param  array<int, int>  $scaleExpMap  SmartmonSensorScale enum => power of 10 exponent
     */
    public static function applySensorScaleCol(array $row, string $valueCol, array $scaleExpMap): ?float
    {
        $raw = $row[$valueCol] ?? null;
        if ($raw === null) {
            return null;
        }

        // smartmonSensorScale is an enum returned as a name ("units(9)") when MIBs load.
        $scaleEnum = self::intValue($row['smartmonSensorScale'] ?? null) ?? 9; // units(9 = 10^0)
        $precision = self::intValue($row['smartmonSensorPrecision'] ?? null) ?? 0;
        $exp = $scaleExpMap[$scaleEnum] ?? 0;

        return (float) $raw * (10 ** ($exp - $precision));
    }

    /**
     * Translate smartmonSensorScale + precision into sensor_divisor / sensor_multiplier
     * so updateSensorValues() can scale the raw smartmonSensorValue at poll time
     * (e.g. milli(8) precision 0 → divisor 1000: 12169 → 12.169). Both keys are
     * always returned so a re-discovery resets any stale factor.
     *
     * @param  array<int, int>  $scaleExpMap  SmartmonSensorScale enum => power of 10 exponent
     * @return array{sensor_divisor: int, sensor_multiplier: int}
     */
    public static function sensorScaleColumns(array $row, array $scaleExpMap): array
    {
        $scaleEnum = self::intValue($row['smartmonSensorScale'] ?? null) ?? 9;
        $precision = self::intValue($row['smartmonSensorPrecision'] ?? null) ?? 0;
        $exp = ($scaleExpMap[$scaleEnum] ?? 0) - $precision;

        return $exp >= 0
            ? ['sensor_divisor' => 1, 'sensor_multiplier' => 10 ** $exp]
            : ['sensor_divisor' => 10 ** (-$exp), 'sensor_multiplier' => 1];
    }

    /**
     * Extract the scalar value from a SnmpQuery table() leaf.
     *
     * A single-column walk grouped with table($n) yields leaves of the form
     * [columnName => value]; return the scalar (preferring the named column),
     * or the value itself when it is already a scalar.
     */
    public static function leafValue(mixed $leaf, string $col): mixed
    {
        if (! is_array($leaf)) {
            return $leaf;
        }
        if (array_key_exists($col, $leaf)) {
            return $leaf[$col];
        }

        return $leaf === [] ? null : reset($leaf);
    }

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

    /** Convert SNMPv2 TruthValue to 1/0/null. TruthValue enum: true(1), false(2). */
    public static function snmpTruthValue(mixed $value): ?int
    {
        $int = self::intValue($value);
        if ($int === null) {
            return null;
        }

        return $int === 1 ? 1 : 0;
    }

    /**
     * Parse an SNMP BITS value (e.g. the OACS/ONCS/LPA bitmaps in SMARTMON-TC-MIB) into a
     * plain integer where bit N is set iff the MIB's bit(N) is set, directly usable
     * with `($raw >> $bit) & 1` against the same bit indexes the TEXTUAL-CONVENTION defines.
     *
     * net-snmp renders BITS values as e.g. "E8 00 securitySendReceive(0) formatNvm(1) ...":
     * hex octets (MSB-first per RFC 2578, not directly usable as an integer) followed by
     * the named set bits. The "(n)" suffixes are the authoritative bit indexes, so prefer
     * those; fall back to decoding the hex octets if a build's snmp options hide them.
     */
    public static function bitsValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match_all('/\((\d+)\)/', $value, $matches) > 0) {
            $raw = 0;
            foreach ($matches[1] as $bit) {
                $raw |= 1 << (int) $bit;
            }

            return $raw;
        }

        $hex = preg_replace('/^(?:BITS|Hex-STRING|STRING):\s*/i', '', $value);
        if (! preg_match('/^(?:[0-9A-Fa-f]{2}[\s:]*)+$/', $hex)) {
            return null;
        }
        $hex = preg_replace('/[\s:]+/', '', $hex);
        if ($hex === '' || strlen($hex) % 2 !== 0) {
            return null;
        }

        $raw = 0;
        foreach (str_split($hex, 2) as $byteIdx => $byteHex) {
            $byte = hexdec($byteHex);
            for ($bitInByte = 0; $bitInByte < 8; $bitInByte++) {
                if (($byte >> (7 - $bitInByte)) & 1) {
                    $raw |= 1 << ($byteIdx * 8 + $bitInByte);
                }
            }
        }

        return $raw;
    }

    public static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || preg_match('/^(?:STRING:\s*)?\d{4}-\d{1,2}-\d{1,2},/', $value)) {
            return null;
        }

        if (preg_match('/\((-?\d+)\)$/', $value, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/:\s*(-?\d+)/', $value, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/^(-?\d+)(?:\s|$)/', $value, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
