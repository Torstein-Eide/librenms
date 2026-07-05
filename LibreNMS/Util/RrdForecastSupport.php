<?php

namespace LibreNMS\Util;

use App\Facades\LibrenmsConfig;

/**
 * Small helpers shared by the SMART per-disk RRD forecast overlays:
 * RrdTrendForecast's native long-term line and RrdHwForecast's Holt-Winters band.
 */
final class RrdForecastSupport
{
    /** Format a float as a plain decimal (never scientific notation, which rrdtool's CDEF/LINE parsers reject). */
    public static function fmt(float $value): string
    {
        return rtrim(rtrim(sprintf('%.18f', $value), '0'), '.') ?: '0';
    }

    /**
     * Emit a ",{mult},*,{div},/" CDEF remapping each of $cdefNames (each "{v}{name}")
     * into another series' shared pixel space, when both scale args are given; a
     * no-op passthrough (returns the original names) when either is null. Mirrors
     * the same remap trick sata_attr_value.inc.php's own norm_display CDEF uses.
     *
     * @param  array<int, string>  $cdefNames  suffixes (without the $v prefix) of already-defined CDEFs to remap
     * @return array<int, string> the CDEF names to actually plot, same order as $cdefNames
     */
    public static function remap(array &$rrd_options, string $v, array $cdefNames, ?float $multiplier, ?float $divisor): array
    {
        if ($multiplier === null || $divisor === null) {
            return array_map(static fn (string $name) => "{$v}{$name}", $cdefNames);
        }

        $m = self::fmt($multiplier);
        $d = self::fmt($divisor);
        $plotNames = [];
        foreach ($cdefNames as $name) {
            $plotName = "{$v}{$name}disp";
            $rrd_options[] = "CDEF:{$plotName}={$v}{$name},{$m},*,{$d},/";
            $plotNames[] = $plotName;
        }

        return $plotNames;
    }

    /** @return array<int, float> timestamp => AVERAGE value (already multiplier-scaled) */
    public static function fetchAverageSeries(string $rrdFilename, string $ds, int $from, int $to, float $multiplier): array
    {
        $bin = LibrenmsConfig::get('rrdtool', 'rrdtool');
        $cmd = escapeshellcmd($bin) . ' fetch ' . escapeshellarg($rrdFilename)
              . ' AVERAGE --start ' . $from . ' --end ' . $to;
        exec($cmd . ' 2>/dev/null', $lines, $rc);
        if ($rc !== 0 || empty($lines)) {
            return [];
        }

        $header = array_shift($lines);
        $dsNames = preg_split('/\s+/', trim($header));
        $col = array_search($ds, $dsNames, true);
        if ($col === false) {
            return [];
        }

        $series = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || ! preg_match('/^(\d+):\s*(.*)$/', $line, $m)) {
                continue;
            }
            $val = preg_split('/\s+/', trim($m[2]))[$col] ?? null;
            if ($val === null || ! is_numeric($val)) {
                continue;
            }
            $series[(int) $m[1]] = (float) $val * $multiplier;
        }

        return $series;
    }
}
