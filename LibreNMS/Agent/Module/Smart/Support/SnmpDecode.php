<?php

namespace LibreNMS\Agent\Module\Smart\Support;

/**
 * SMART-MIB-specific SNMP value decoding that isn't generic enough to live in
 * {@see \LibreNMS\Util\SnmpDecode}: coercing enum/typed SNMP scalars used
 * throughout the Smart module, extracting a scalar from a SnmpQuery table()
 * leaf, and the SMARTMON-*-MIB BITS/TruthValue conventions. Shared by the
 * SATA/NVMe (and future SAS) pipelines without depending on Common itself.
 */
final class SnmpDecode
{
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
}
