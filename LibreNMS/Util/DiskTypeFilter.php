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
    private const FALLBACK_TYPE = ['view' => 'physical', 'subtype' => 'other'];

    /**
     * File structure:
     * 1) Public selection helpers (normalizeSelection(), subtypesFor(), matches())
     * 2) Public classification entrypoint (classify())
     * 3) OS group dispatcher and per-group handlers (unix(), unknown())
     * 4) Per-disk unix classifier (classifyUnixDisk())
     * 5) Cached subgroup checks for scalable os_group_sub logic (hasOsGroupSub(), detectBsdFamily())
     */

    /** @var array<string, string> */
    private array $osGroupHandlers = [
        'unix' => 'unix',
    ];

    /** @var array<string, string> */
    private array $osGroupSubResolvers = [
        'bsd_family' => 'detectBsdFamily',
    ];

    /** @var array<string, bool> */
    private array $osGroupSubCache = [];

    /** @var array{os_or_sys_descr?: string|null} */
    private array $context = [];

    /**
     * @param array{os_or_sys_descr?: string|null} $context
     */
    private function __construct(array $context = [])
    {
        $this->context = $context;
    }

    /**
     * @return array{view: 'physical'|'logical'|'all', subtype: string}
     */
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

    /**
     * @return list<string>
     */
    public static function subtypesFor(string $view): array
    {
        return match ($view) {
            'physical' => ['all', 'sd_family', 'nvme', 'mmcblk', 'memory', 'other'],
            'logical' => ['all', 'partitions', 'dm', 'sw_raid', 'loop', 'other'],
            default => ['all'],
        };
    }

    /**
     * @param array<int|string, string> $diskNames
     * @param array{os_or_sys_descr?: string|null} $context
     * @return array<int|string, array{view: 'physical'|'logical', subtype: string}>
     */
    public static function classify(array $diskNames, ?string $osGroup = null, array $context = []): array
    {
        $filter = new self($context);

        return $filter->classifyByOsGroup($diskNames, $osGroup);
    }

    /**
     * @param array<int|string, string> $diskNames
     * @return array<int|string, array{view: 'physical'|'logical', subtype: string}>
     */
    private function classifyByOsGroup(array $diskNames, ?string $osGroup): array
    {
        $handlerName = $this->osGroupHandlers[strtolower((string) $osGroup)] ?? null;
        if ($handlerName === null) {
            return $this->unknown($diskNames);
        }

        return $this->{$handlerName}($diskNames);
    }

    /**
     * @param array<int|string, string> $diskNames
     * @return array<int|string, array{view: 'physical'|'logical', subtype: string}>
     */
    private function unix(array $diskNames): array
    {
        $classified = [];
        foreach ($diskNames as $key => $diskName) {
            $classified[$key] = $this->classifyUnixDisk($diskName);
        }

        return $classified;
    }

    /**
     * @param array<int|string, string> $diskNames
     * @return array<int|string, array{view: 'physical'|'logical', subtype: string}>
     */
    private function unknown(array $diskNames): array
    {
        $classified = [];
        foreach ($diskNames as $key => $_diskName) {
            $classified[$key] = self::FALLBACK_TYPE;
        }

        return $classified;
    }

    /**
     * @return array{view: 'physical'|'logical', subtype: string}
     */
    private function classifyUnixDisk(string $diskName): array
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

        if ($this->hasOsGroupSub('bsd_family')) {
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

        return self::FALLBACK_TYPE;
    }

    /**
     * @param array{view: string, subtype: string} $diskType
     */
    public static function matches(array $diskType, string $selectedView, string $selectedSubtype): bool
    {
        if ($selectedView !== 'all' && $diskType['view'] !== $selectedView) {
            return false;
        }

        return $selectedView === 'all' || $selectedSubtype === 'all' || $diskType['subtype'] === $selectedSubtype;
    }

    private function hasOsGroupSub(string $subCheck): bool
    {
        if (array_key_exists($subCheck, $this->osGroupSubCache)) {
            return $this->osGroupSubCache[$subCheck];
        }

        $resolver = $this->osGroupSubResolvers[$subCheck] ?? null;
        if ($resolver === null) {
            $this->osGroupSubCache[$subCheck] = false;

            return false;
        }

        $value = $this->{$resolver}();
        $this->osGroupSubCache[$subCheck] = $value;

        return $value;
    }

    private function detectBsdFamily(): bool
    {
        $osOrSysDescr = $this->context['os_or_sys_descr'] ?? null;
        if ($osOrSysDescr === null) {
            return false;
        }

        $normalized = strtolower($osOrSysDescr);

        if (in_array($normalized, self::BSD_FAMILY, true)) {
            return true;
        }

        foreach (self::BSD_FAMILY as $bsd) {
            if (str_contains($normalized, $bsd)) {
                return true;
            }
        }

        return false;
    }
}
