<?php
/* LibreNMS Disk Type Filter Utility
 *
 * This utility class provides disk drive classification and filtering functionality for the
 * Disk Type monitoring pages. It categorizes drives into physical/logical views with specific
 * subtypes based on naming patterns.
 */
namespace LibreNMS\Util;

final class DiskTypeFilter
{
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
            'physical' => ['all', 'sd_family', 'nvme', 'mmcblk', 'other'],
            'logical' => ['all', 'partitions', 'dm', 'md', 'loop', 'other'],
            default => ['all'],
        };
    }

    public static function classify(string $diskName): array
    {
        // Do 1. logical drive classification as some logical drive names can match physical drive patterns (e.g., sda1, nvme0n1p1, mmcblk0p1, etc.)
        // 2. physical drive classification for those that don't match logical patterns
        // 3. default to physical/other for any that don't match specific patterns, ensuring all drives are classified for filtering purposes
        // linux device mapper (dm-0, dm-1, etc.)
        if (preg_match('/^dm-\d+$/i', $diskName)) {
            return ['view' => 'logical', 'subtype' => 'dm'];
        }
        // Linux loopback devices (loop0, loop1, etc.)
        if (preg_match('/^loop\d+$/i', $diskName)) {
            return ['view' => 'logical', 'subtype' => 'loop'];
        }
        // Linux software RAID devices (md0, md1, etc.)
        if (preg_match('/^md\d+$/i', $diskName)) {
            return ['view' => 'logical', 'subtype' => 'md'];
        }
        // partitions and virtual devices: sda1, nvme0n1p1, mmcblk0p1, da0p1, da0s1a, wd0d, ad0s1e, etc.
        if (preg_match('/^(sd[a-z]+\d+|hd[a-z]+\d+|vd[a-z]+\d+|xvd[a-z]+\d+)$/i', $diskName)
            || preg_match('/^nvme\d+n\d+p\d+$/i', $diskName)
            || preg_match('/^mmcblk\d+p\d+$/i', $diskName)
            // Unix/FreeBSD/OpenBSD/NetBSD: da0p1, da0s1a, wd0d, ad0s1e, etc.
            || preg_match('/^(da|wd|ad|cd|fd|md|gm|vn)\d+[sp]\d+/i', $diskName))
            {
            return ['view' => 'logical', 'subtype' => 'partitions'];
        }
        // - Linux physical drive families: sd*, hd*, vd*, xvd* (covers most SCSI/SATA, IDE, and virtio block devices)
        // - BSD physical drive patterns: da*, wd*, ad* (covers most SCSI/SATA and IDE devices on BSD systems)
        if (preg_match('/^((x?vd|sd|hd)[a-z]+|(da|ad|ada|wd)\d+)$/i', $diskName)) {
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
        // ## Unix/FreeBSD/OpenBSD/NetBSD physical drives, nummber is whole disk.
        // mostly legacy patterns but can still be found in use: cd0, fd0, md0, gm0, vn0, etc.
        // no need to do since the default case will catch these and classify as physical/other, but leaving here for clarity and potential future subtype classification if desired.
        // if (preg_match('/^(cd|fd|md|gm|vn)\d+/i', $diskName)) {
        //     return ['view' => 'physical', 'subtype' => 'other'];
        // }
        // default classification for anything that doesn't match above patterns
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
