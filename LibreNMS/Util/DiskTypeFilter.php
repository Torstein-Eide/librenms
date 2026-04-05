<?php

/* LibreNMS Disk Type Filter Utility
 *
 * This utility class provides disk drive classification and filtering functionality for the
 * Disk Type monitoring pages. It categorizes drives into physical/logical views with specific
 * subtypes based on naming patterns and OS family.
 */

namespace LibreNMS\Util;

final class DiskTypeFilter
{
    private const BSD_FAMILY = ['freebsd', 'openbsd', 'netbsd', 'dragonfly'];

    public static function normalizeSelection(?string $view, ?string $subtype): array
    {
        if (! in_array((string) $view, ['physical', 'logical', 'all'], true)) {
            $view = 'physical';
        }

        if ($view === 'all') {
            return ['view' => 'all', 'subtype' => 'all'];
        }

        if (! in_array((string) $subtype, self::subtypesFor($view), true)) {
            $subtype = 'all';
        }

        return ['view' => $view, 'subtype' => $subtype];
    }

    public static function subtypesFor(string $view): array
    {
        return match ($view) {
            'physical' => ['all', 'sd_family', 'nvme', 'mmcblk', 'memory', 'other'],
            'logical' => ['all', 'partitions', 'dm', 'sw_raid', 'loop', 'other'],
            default => ['all'],
        };
    }

    public static function classify(string $diskName, ?string $os = null): array
    {
        // Device mapper (dm-0, dm-1, etc.)
        if (preg_match('/^dm-\d+$/i', $diskName)) {
            return ['view' => 'logical', 'subtype' => 'dm'];
        }

        // Image-backed loop devices (loop0, loop1, etc.)
        if (preg_match('/^loop\d+$/i', $diskName)) {
            return ['view' => 'logical', 'subtype' => 'loop'];
        }

        // Memory-backed devices: ram*, zram* (Linux), md* (BSD)
        if (preg_match('/^(ram|zram)\d+$/i', $diskName)) {
            return ['view' => 'physical', 'subtype' => 'memory'];
        }
        if ($os !== null && in_array($os, self::BSD_FAMILY, true)) {
            if (preg_match('/^md\d+$/i', $diskName)) {
                return ['view' => 'physical', 'subtype' => 'memory'];
            }
        }

        // Software RAID: ccd*, vnd* (BSD), md* (Linux)
        if (preg_match('/^(ccd|vnd|md)\d+$/i', $diskName)) {
            return ['view' => 'logical', 'subtype' => 'sw_raid'];
        }

        // Partitions and virtual devices: sda1, nvme0n1p1, mmcblk0p1, da0p1, da0s1a, ad0s1e, etc.
        if (preg_match('/^(sd[a-z]+\d+|hd[a-z]+\d+|vd[a-z]+\d+|xvd[a-z]+\d+)$/i', $diskName)
            || preg_match('/^nvme\d+n\d+p\d+$/i', $diskName)
            || preg_match('/^mmcblk\d+p\d+$/i', $diskName)
            || preg_match('/^(da|wd|ad|cd|fd|gm|vn)\d+[sp]\d+/i', $diskName)) {
            return ['view' => 'logical', 'subtype' => 'partitions'];
        }

        // Physical drive families: sd*, hd*, vd*, xvd*, da*, ad*, ada*
        if (preg_match('/^((x?vd|sd|hd)[a-z]+|(da|ad|ada)\d+)$/i', $diskName)) {
            return ['view' => 'physical', 'subtype' => 'sd_family'];
        }

        // NVMe physical devices: nvme0n1, nvme1n1, etc.
        if (preg_match('/^nvme\d+n\d+$/i', $diskName)) {
            return ['view' => 'physical', 'subtype' => 'nvme'];
        }

        // MMC/SD physical devices: mmcblk0, mmcblk1, etc.
        if (preg_match('/^mmcblk\d+$/i', $diskName)) {
            return ['view' => 'physical', 'subtype' => 'mmcblk'];
        }

        return ['view' => 'physical', 'subtype' => 'other'];
    }

    public static function matches(array $diskType, string $selectedView, string $selectedSubtype): bool
    {
        if ($selectedView !== 'all' && $diskType['view'] !== $selectedView) {
            return false;
        }

        return $selectedView === 'all' || $selectedSubtype === 'all' || $diskType['subtype'] === $selectedSubtype;
    }
}
