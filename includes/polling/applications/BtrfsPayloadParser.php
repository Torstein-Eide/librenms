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
 *
 * All methods accept the full $tables array and $fs_uuid for direct lookup.
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
        $capacity = $tables['filesystem_capacity'][$fs_uuid] ?? [];

        return [
            'device_size_bytes' => $capacity['device_size'] ?? null,
            'device_allocated_bytes' => $capacity['device_allocated'] ?? null,
            'device_unallocated_bytes' => $capacity['device_unallocated'] ?? null,
            'used_bytes' => $capacity['used'] ?? null,
            'free_estimated_bytes' => $capacity['free_estimated'] ?? null,
            'free_estimated_min_bytes' => $capacity['free_estimated_min'] ?? null,
            'free_statfs_df_bytes' => $capacity['free_statfs_df'] ?? null,
            'global_reserve_bytes' => $capacity['global_reserve'] ?? null,
            'global_reserve_used_bytes' => $capacity['global_reserve_used'] ?? null,
            'device_missing_bytes' => $capacity['device_missing'] ?? 0,
            'device_slack_bytes' => $capacity['device_slack'] ?? 0,
            'data_ratio' => $capacity['data_ratio'] ?? null,
            'metadata_ratio' => $capacity['metadata_ratio'] ?? null,
        ];
    }

    public function getFsInfo(array $tables, string $fs_uuid): array
    {
        $fs_row = $tables['filesystems'][$fs_uuid] ?? [];

        return [
            'mountpoint' => $fs_row['mountpoint'] ?? $fs_row['primary_mountpoint'] ?? '',
            'label' => $fs_row['label'] ?? '',
            'total_devices' => $fs_row['total_devices'] ?? null,
            'fs_bytes_used' => $fs_row['bytes_used'] ?? null,
        ];
    }

    public function extractFilesystemUuid(array $tables, string $fs_uuid): string
    {
        return $fs_uuid;
    }

    public function filesystemHasMissingDevice(array $tables, string $fs_uuid): bool
    {
        $capacity = $tables['filesystem_capacity'][$fs_uuid] ?? [];
        $missing_bytes = $capacity['device_missing'] ?? 0;
        if (is_numeric($missing_bytes) && (float) $missing_bytes > 0) {
            return true;
        }

        $devices = $tables['filesystem_devices'][$fs_uuid] ?? [];
        foreach ($devices as $dev_row) {
            if (! is_array($dev_row)) {
                continue;
            }

            if (! empty($dev_row['missing'])) {
                return true;
            }
        }

        return false;
    }

    public function normalizeDevicePath(?string $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $trimmed = trim($path);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    public function missingDeviceKey(string $devid): string
    {
        return '<missing disk #' . $devid . '>';
    }

    public function isMissingDeviceEntry(array $entry): bool
    {
        return ! empty($entry['missing']) || ! empty($entry['device_missing']);
    }

    public function extractDeviceStats(array $tables, string $fs_uuid): array
    {
        $devices = [];
        $fs_devices = $tables['filesystem_devices'][$fs_uuid] ?? [];

        foreach ($fs_devices as $devid => $dev_row) {
            if (! is_array($dev_row)) {
                continue;
            }

            $devid = (string) $devid;
            $path = $this->normalizeDevicePath($dev_row['device_path'] ?? null);
            if ($path === '') {
                continue;
            }

            $is_missing = $this->isMissingDeviceEntry($dev_row);
            if ($is_missing) {
                $path = $this->missingDeviceKey($devid);
            }

            $devices[$path] = [
                'devid' => (int) $devid,
                'missing' => $is_missing,
                'corruption_errs' => $dev_row['corruption_errs'] ?? null,
                'flush_io_errs' => $dev_row['flush_io_errs'] ?? null,
                'generation_errs' => $dev_row['generation_errs'] ?? null,
                'read_io_errs' => $dev_row['read_io_errs'] ?? null,
                'write_io_errs' => $dev_row['write_io_errs'] ?? null,
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
        $fs_devices = $tables['filesystem_devices'][$fs_uuid] ?? [];

        foreach ($fs_devices as $devid => $dev_row) {
            if (! is_array($dev_row)) {
                continue;
            }

            $devid = (string) $devid;
            $path = $this->normalizeDevicePath($dev_row['device_path'] ?? null);
            if ($path === '') {
                continue;
            }

            $is_missing = $this->isMissingDeviceEntry($dev_row);
            if ($is_missing) {
                $path = $this->missingDeviceKey($devid);
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
        $fs_devices = $tables['filesystem_devices'][$fs_uuid] ?? [];

        foreach ($fs_devices as $devid => $dev_row) {
            if (! is_array($dev_row)) {
                continue;
            }

            $devid = (string) $devid;
            $path = $this->normalizeDevicePath($dev_row['device_path'] ?? null);
            if ($path === '') {
                continue;
            }

            $is_missing = $this->isMissingDeviceEntry($dev_row);
            if ($is_missing) {
                $path = $this->missingDeviceKey($devid);
            }

            $row = $this->makeDeviceUsageRow($dev_row['size'] ?? null);
            $row['device_slack'] = $dev_row['slack'] ?? 0;
            $row['unallocated'] = $dev_row['unallocated'] ?? null;
            $row['data_bytes'] = (float) ($dev_row['data'] ?? 0);
            $row['metadata_bytes'] = (float) ($dev_row['metadata'] ?? 0);
            $row['system_bytes'] = (float) ($dev_row['system'] ?? 0);

            if (is_array($dev_row['raid_profiles'] ?? null)) {
                foreach ($dev_row['raid_profiles'] as $profile_key => $profile_value) {
                    if (is_string($profile_key) && is_numeric($profile_value)) {
                        $row['type_values'][$profile_key] = $profile_value;
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
        $profiles = $tables['filesystem_profiles'][$fs_uuid] ?? [];

        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $profile_key = $profile['profile'] ?? null;
            $bytes = $profile['bytes'] ?? null;

            if (is_string($profile_key) && is_numeric($bytes)) {
                $totals[$profile_key] = ($totals[$profile_key] ?? 0) + $bytes;
            }
        }

        ksort($totals);

        return $totals;
    }

    public function extractSysBlockMetadata(array $tables, string $fs_uuid): array
    {
        return [];
    }

    public function toNumericBytes($value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $parsed = \LibreNMS\Util\Number::toBytes($value);
            if (! is_nan($parsed)) {
                return (float) $parsed;
            }
        }

        return null;
    }

    public function toBoolFlag($value): ?bool
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

    public function extractRunningFlag(array $data): ?bool
    {
        $RUNNING_FLAG_KEYS = ['running', 'is_running', 'in_progress'];
        $RUNNING_STATUS_TOKENS = ['running', 'in-progress', 'in_progress'];
        $FINISHED_STATUS_TOKENS = ['finished', 'done', 'idle', 'stopped', 'completed'];

        foreach ($RUNNING_FLAG_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                $flag = $this->toBoolFlag($data[$key]);
                if ($flag !== null) {
                    return $flag;
                }
            }
        }

        $status = strtolower(trim((string) ($data['status'] ?? '')));
        if (in_array($status, $RUNNING_STATUS_TOKENS, true)) {
            return true;
        }
        if (in_array($status, $FINISHED_STATUS_TOKENS, true)) {
            return false;
        }

        return null;
    }

    public function getScrubStatusDevicesForPath(array $scrub_devices, array $show_devices_by_path): array
    {
        $result = [];

        foreach ($scrub_devices as $devid => $dev_row) {
            if (! is_array($dev_row)) {
                continue;
            }

            $devid = (string) $devid;
            $path = $this->normalizeDevicePath($dev_row['path'] ?? null);
            if ($path === '') {
                $path = $devid;
            }

            $is_missing = $this->isMissingDeviceEntry($dev_row);
            if ($is_missing) {
                $path = $this->missingDeviceKey($devid);
            }

            $result[$path] = $dev_row;
        }

        return $result;
    }

    public function getFilesystemsByMountpoint(array $tables): array
    {
        $result = [];

        foreach ($tables['filesystems'] ?? [] as $fs_uuid => $fs_row) {
            if (! is_array($fs_row)) {
                continue;
            }

            $mountpoint = (string) ($fs_row['mountpoint'] ?? $fs_row['primary_mountpoint'] ?? '');
            if ($mountpoint !== '') {
                $result[$mountpoint] = $fs_uuid;
            }
        }

        return $result;
    }
}
