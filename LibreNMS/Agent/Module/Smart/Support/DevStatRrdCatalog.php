<?php

namespace LibreNMS\Agent\Module\Smart\Support;

/**
 * Fixed allowlist of ATA Device Statistics log (GP Log 0x04) entries that get
 * an RRD DS when the "log extra Device Statistics" setting is enabled for a
 * disk. Keyed by "{page_num}:{stat_offset}", matching smart_sata_dev_stats
 * rows synced from SMARTMON-SATA-MIB::smartmonSataDevStatTable.
 *
 * Offsets verified against smartmontools/src/ataprint.cpp (devstat_info_0x0N
 * tables): each stat sits in an 8-byte slot starting at offset 0x008 (the
 * first 8 bytes of a page are its header), so offset = 0x008 + 8*(n-1) for
 * the nth stat on the page. Cross-checked against that file's
 * set_json_globals_from_device_statistics(), which hardcodes several of the
 * same offsets independently.
 *
 * Deliberately excluded: Lifetime Power-On Resets, Power-on Hours (already
 * covered by existing power_cycle_count/power_on_time-style sensors),
 * Logical Sectors Read, Date and Time TimeStamp (not a meaningful RRD metric).
 */
final class DevStatRrdCatalog
{
    /**
     * @return array<string, array{page: int, offset: int, ds: string, type: string, label: string}>
     */
    public static function entries(): array
    {
        return [
            '1:24' => ['page' => 1, 'offset' => 24, 'ds' => 'p1o24', 'type' => 'COUNTER', 'label' => 'Logical Sectors Written'],
            '1:32' => ['page' => 1, 'offset' => 32, 'ds' => 'p1o32', 'type' => 'COUNTER', 'label' => 'Number of Write Commands'],
            '1:48' => ['page' => 1, 'offset' => 48, 'ds' => 'p1o48', 'type' => 'COUNTER', 'label' => 'Number of Read Commands'],
            '1:64' => ['page' => 1, 'offset' => 64, 'ds' => 'p1o64', 'type' => 'GAUGE', 'label' => 'Pending Error Count'],
            '1:72' => ['page' => 1, 'offset' => 72, 'ds' => 'p1o72', 'type' => 'GAUGE', 'label' => 'Workload Utilization'],
            '1:80' => ['page' => 1, 'offset' => 80, 'ds' => 'p1o80', 'type' => 'GAUGE', 'label' => 'Utilization Usage Rate'],
            '1:88' => ['page' => 1, 'offset' => 88, 'ds' => 'p1o88', 'type' => 'GAUGE', 'label' => 'Resource Availability'],
            '1:96' => ['page' => 1, 'offset' => 96, 'ds' => 'p1o96', 'type' => 'GAUGE', 'label' => 'Random Write Resources Used'],

            '2:8' => ['page' => 2, 'offset' => 8, 'ds' => 'p2o8', 'type' => 'COUNTER', 'label' => 'Number of Free-Fall Events Detected'],
            '2:16' => ['page' => 2, 'offset' => 16, 'ds' => 'p2o16', 'type' => 'COUNTER', 'label' => 'Overlimit Shock Events'],

            '3:8' => ['page' => 3, 'offset' => 8, 'ds' => 'p3o8', 'type' => 'COUNTER', 'label' => 'Spindle Motor Power-on Hours'],
            '3:16' => ['page' => 3, 'offset' => 16, 'ds' => 'p3o16', 'type' => 'COUNTER', 'label' => 'Head Flying Hours'],
            '3:24' => ['page' => 3, 'offset' => 24, 'ds' => 'p3o24', 'type' => 'COUNTER', 'label' => 'Head Load Events'],
            '3:32' => ['page' => 3, 'offset' => 32, 'ds' => 'p3o32', 'type' => 'COUNTER', 'label' => 'Number of Reallocated Logical Sectors'],
            '3:40' => ['page' => 3, 'offset' => 40, 'ds' => 'p3o40', 'type' => 'COUNTER', 'label' => 'Read Recovery Attempts'],
            '3:48' => ['page' => 3, 'offset' => 48, 'ds' => 'p3o48', 'type' => 'COUNTER', 'label' => 'Number of Mechanical Start Failures'],
            '3:56' => ['page' => 3, 'offset' => 56, 'ds' => 'p3o56', 'type' => 'GAUGE', 'label' => 'Number of Realloc. Candidate Logical Sectors'],
            '3:64' => ['page' => 3, 'offset' => 64, 'ds' => 'p3o64', 'type' => 'COUNTER', 'label' => 'Number of High Priority Unload Events'],

            '4:8' => ['page' => 4, 'offset' => 8, 'ds' => 'p4o8', 'type' => 'COUNTER', 'label' => 'Number of Reported Uncorrectable Errors'],
            '4:16' => ['page' => 4, 'offset' => 16, 'ds' => 'p4o16', 'type' => 'COUNTER', 'label' => 'Resets Between Cmd Acceptance and Completion'],
            '4:24' => ['page' => 4, 'offset' => 24, 'ds' => 'p4o24', 'type' => 'GAUGE', 'label' => 'Physical Element Status Changed'],

            '6:8' => ['page' => 6, 'offset' => 8, 'ds' => 'p6o8', 'type' => 'COUNTER', 'label' => 'Number of Hardware Resets'],
            '6:16' => ['page' => 6, 'offset' => 16, 'ds' => 'p6o16', 'type' => 'COUNTER', 'label' => 'Number of ASR Events'],
            '6:24' => ['page' => 6, 'offset' => 24, 'ds' => 'p6o24', 'type' => 'COUNTER', 'label' => 'Number of Interface CRC Errors'],

            '7:8' => ['page' => 7, 'offset' => 8, 'ds' => 'p7o8', 'type' => 'GAUGE', 'label' => 'Percentage Used Endurance Indicator'],
        ];
    }

    public static function key(int $page, int $offset): string
    {
        return "{$page}:{$offset}";
    }
}
