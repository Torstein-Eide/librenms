<?php

namespace LibreNMS\Polling\Modules;

/**
 * BtrfsPayloadParser extracts data from btrfs unix-agent payloads.
 *
 * Operates directly on the normalized flat-table payload format:
 * - data.tables.filesystems
 * - data.tables.filesystem_capacity
 * - data.tables.filesystem_profiles
 * - data.tables.filesystem_devices
 * - data.tables.scrub_status_filesystems
 * - data.tables.scrub_status_devices
 * - data.tables.balance_status_filesystems
 */
class BtrfsPayloadParser
{
    private $normalize_id;

    public function __construct()
    {
        $this->normalize_id = static function (string $value): string {
            $id = preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
            $id = trim((string) $id, '_');

            return $id === '' ? 'id' : $id;
        };
    }

    public function normalizeId(string $value): string
    {
        return ($this->normalize_id)($value);
    }

    public function normalizeOverall(array $tables, string $fs_uuid): array
    {
        $c = $tables['filesystem_capacity'][$fs_uuid] ?? [];

        return [
            'device_size_bytes' => $c['device_size'] ?? null,
            'device_allocated_bytes' => $c['device_allocated'] ?? null,
            'device_unallocated_bytes' => $c['device_unallocated'] ?? null,
            'used_bytes' => $c['used'] ?? null,
            'free_estimated_bytes' => $c['free_estimated'] ?? null,
            'free_estimated_min_bytes' => $c['free_estimated_min'] ?? null,
            'free_statfs_df_bytes' => $c['free_statfs_df'] ?? null,
            'global_reserve_bytes' => $c['global_reserve'] ?? null,
            'global_reserve_used_bytes' => $c['global_reserve_used'] ?? null,
            'device_missing_bytes' => $c['device_missing'] ?? 0,
            'device_slack_bytes' => $c['device_slack'] ?? 0,
            'data_ratio' => $c['data_ratio'] ?? null,
            'metadata_ratio' => $c['metadata_ratio'] ?? null,
        ];
    }

    public function getFsInfo(array $tables, string $fs_uuid): array
    {
        $fs = $tables['filesystems'][$fs_uuid] ?? [];

        return [
            'mountpoint' => $fs['mountpoint'] ?? $fs['primary_mountpoint'] ?? '',
            'label' => $fs['label'] ?? '',
            'total_devices' => $fs['total_devices'] ?? null,
            'fs_bytes_used' => $fs['bytes_used'] ?? null,
        ];
    }

    public function filesystemHasMissingDevice(array $tables, string $fs_uuid): bool
    {
        $c = $tables['filesystem_capacity'][$fs_uuid] ?? [];
        if (($c['device_missing'] ?? 0) > 0) {
            return true;
        }

        foreach ($tables['filesystem_devices'][$fs_uuid] ?? [] as $dev) {
            if (! is_array($dev)) {
                continue;
            }
            if (! empty($dev['missing'])) {
                return true;
            }
        }

        return false;
    }

    public function extractDeviceStats(array $tables, string $fs_uuid): array
    {
        $devices = [];
        foreach ($tables['filesystem_devices'][$fs_uuid] ?? [] as $devid => $dev) {
            if (! is_array($dev)) {
                continue;
            }

            $devid = (string) $devid;
            $path = $this->pathOrMissing($dev, $devid, $dev['device_path'] ?? null);
            if ($path === null) {
                continue;
            }

            $devices[$path] = [
                'devid' => (int) $devid,
                'missing' => $this->isMissing($dev),
                'corruption_errs' => $dev['corruption_errs'] ?? null,
                'flush_io_errs' => $dev['flush_io_errs'] ?? null,
                'generation_errs' => $dev['generation_errs'] ?? null,
                'read_io_errs' => $dev['read_io_errs'] ?? null,
                'write_io_errs' => $dev['write_io_errs'] ?? null,
            ];
        }

        return $devices;
    }

    public function extractScrubStatus(array $tables, string $fs_uuid): array
    {
        return $tables['scrub_status_filesystems'][$fs_uuid] ?? [];
    }

    public function extractScrubDevices(array $tables, string $fs_uuid): array
    {
        return $tables['scrub_status_devices'][$fs_uuid] ?? [];
    }

    public function extractBalanceStatus(array $tables, string $fs_uuid): array
    {
        return $tables['balance_status_filesystems'][$fs_uuid] ?? [];
    }

    public function extractShowDevices(array $tables, string $fs_uuid): array
    {
        $devices = [];
        foreach ($tables['filesystem_devices'][$fs_uuid] ?? [] as $devid => $dev) {
            if (! is_array($dev)) {
                continue;
            }

            $devid = (string) $devid;
            $path = $this->pathOrMissing($dev, $devid, $dev['device_path'] ?? null);
            if ($path === null) {
                continue;
            }

            $devices[$path] = (int) $devid;
        }

        return $devices;
    }

    public function makeDeviceUsageRow($device_size = null): array
    {
        return [
            'device_size' => $device_size,
            'device_slack' => null,
            'unallocated' => null,
            'data_bytes' => 0,
            'metadata_bytes' => 0,
            'system_bytes' => 0,
            'type_values' => [],
        ];
    }

    public function extractDeviceUsage(array $tables, string $fs_uuid): array
    {
        $devices = [];
        foreach ($tables['filesystem_devices'][$fs_uuid] ?? [] as $devid => $dev) {
            if (! is_array($dev)) {
                continue;
            }

            $devid = (string) $devid;
            $path = $this->pathOrMissing($dev, $devid, $dev['device_path'] ?? null);
            if ($path === null) {
                continue;
            }

            $row = $this->makeDeviceUsageRow($dev['size'] ?? null);
            $row['device_slack'] = $dev['slack'] ?? 0;
            $row['unallocated'] = $dev['unallocated'] ?? null;
            $row['data_bytes'] = (float) ($dev['data'] ?? 0);
            $row['metadata_bytes'] = (float) ($dev['metadata'] ?? 0);
            $row['system_bytes'] = (float) ($dev['system'] ?? 0);

            $dev_profiles = $dev['profiles'] ?? $dev['raid_profiles'] ?? null;
            if (is_array($dev_profiles)) {
                foreach ($dev_profiles as $profile_entry) {
                    if (is_array($profile_entry) && is_string($profile_entry['profile'] ?? null) && is_numeric($profile_entry['bytes'] ?? null)) {
                        $row['type_values'][$profile_entry['profile']] = $profile_entry['bytes'];
                    }
                }
            }

            $devices[$path] = $row;
        }

        return $devices;
    }

    public function extractUsageTypeTotals(array $tables, string $fs_uuid): array
    {
        $totals = [];
        foreach ($tables['filesystem_profiles'][$fs_uuid] ?? [] as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $pk = $profile['profile'] ?? null;
            $bytes = $profile['bytes'] ?? null;
            if (is_string($pk) && is_numeric($bytes)) {
                $totals[$pk] = ($totals[$pk] ?? 0) + $bytes;
            }
        }
        ksort($totals);

        return $totals;
    }

    public function extractSysBlockMetadata(array $tables, string $fs_uuid): array
    {
        return [];
    }

    public function extractRunningFlag(array $data): ?bool
    {
        foreach (['running', 'is_running', 'in_progress'] as $key) {
            if (array_key_exists($key, $data)) {
                $flag = $this->toBool($data[$key]);
                if ($flag !== null) {
                    return $flag;
                }
            }
        }

        $status = strtolower(trim((string) ($data['status'] ?? '')));
        foreach (['running', 'in-progress', 'in_progress'] as $t) {
            if ($status === $t) {
                return true;
            }
        }
        foreach (['finished', 'done', 'idle', 'stopped', 'completed'] as $t) {
            if ($status === $t) {
                return false;
            }
        }

        return null;
    }

    public function getScrubStatusDevicesForPath(array $scrub_devices, array $show_devices_by_path): array
    {
        $result = [];
        foreach ($scrub_devices as $devid => $dev) {
            if (! is_array($dev)) {
                continue;
            }

            $devid = (string) $devid;
            $path = $this->pathOrMissing($dev, $devid, $dev['path'] ?? null);
            if ($path === null) {
                $path = $devid;
            }

            $result[$path] = $dev;
        }

        return $result;
    }

    public function getFilesystemsByMountpoint(array $tables): array
    {
        $result = [];
        foreach ($tables['filesystems'] ?? [] as $fs_uuid => $fs) {
            if (! is_array($fs)) {
                continue;
            }
            $mountpoint = (string) ($fs['mountpoint'] ?? $fs['primary_mountpoint'] ?? '');
            if ($mountpoint !== '') {
                $result[$mountpoint] = $fs_uuid;
            }
        }

        return $result;
    }

    private function isMissing(array $entry): bool
    {
        return ! empty($entry['missing']) || ! empty($entry['device_missing']);
    }

    private function toBool($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int) $value) !== 0;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'yes', 'y'], true)) {
                return true;
            }
            if (in_array($v, ['0', 'false', 'no', 'n'], true)) {
                return false;
            }
        }

        return null;
    }

    private function pathOrMissing(array $entry, string $devid, ?string $path): ?string
    {
        $normalized = $this->normalizePath($path);
        if ($normalized !== null) {
            return $this->isMissing($entry) ? $this->missingKey($devid) : $normalized;
        }

        return null;
    }

    private function normalizePath(?string $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }
        $trimmed = trim($path);

        return $trimmed === '' ? null : $trimmed;
    }

    private function missingKey(string $devid): string
    {
        return '<missing disk #' . $devid . '>';
    }
}
