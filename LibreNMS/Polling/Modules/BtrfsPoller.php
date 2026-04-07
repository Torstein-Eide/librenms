<?php

namespace LibreNMS\Polling\Modules;

require_once base_path('includes/app-log.php');

use App\Models\Application;
use App\Models\Eventlog;
use App\Models\Sensor;
use App\Models\SensorToStateIndex;
use App\Models\StateTranslation;
use App_log;
use LibreNMS\Enum\Severity;
use LibreNMS\Exceptions\JsonAppException;
use LibreNMS\RRD\RrdDefinition;

// =============================================================================
// BtrfsStatusMapper
// =============================================================================

/**
 * BtrfsStatusMapper computes IO/Scrub/Balance status codes from parsed btrfs data.
 *
 * Status code contract:
 *   0 = OK (healthy)
 *   1 = Running (normal transient state)
 *  -1 = Unknown
 *   2 = N/A (no data available)
 *   3 = Error (problem detected)
 *   4 = Missing (device absent)
 *
 * LibreNMS generic mapping:
 *   generic 0 = OK
 *   generic 1 = Warning (Running maps here)
 *   generic 2 = Critical (Error, Missing map here)
 *   generic 3 = Unknown (N/A maps here)
 */
class BtrfsStatusMapper
{
    public function getIoStatusCode(bool $has_device_data, bool $has_error, bool $has_missing): int
    {
        if (! $has_device_data) {
            return BtrfsSensorSync::STATUS_NA;
        }

        if ($has_missing) {
            return BtrfsSensorSync::STATUS_MISSING;
        }

        return $has_error ? BtrfsSensorSync::STATUS_ERROR : BtrfsSensorSync::STATUS_OK;
    }

    public function getScrubStatusCode(bool $has_scrub_data, bool $has_error, bool $is_running): int
    {
        if (! $has_scrub_data) {
            return BtrfsSensorSync::STATUS_NA;
        }

        if ($has_error) {
            return BtrfsSensorSync::STATUS_ERROR;
        }

        if ($is_running) {
            return BtrfsSensorSync::STATUS_RUNNING;
        }

        return BtrfsSensorSync::STATUS_OK;
    }

    public function getDevIoStatusCode(bool $has_data, bool $has_error, bool $is_missing): int
    {
        if ($is_missing) {
            return BtrfsSensorSync::STATUS_MISSING;
        }

        if (! $has_data) {
            return BtrfsSensorSync::STATUS_NA;
        }

        return $has_error ? BtrfsSensorSync::STATUS_ERROR : BtrfsSensorSync::STATUS_OK;
    }

    public function getDevScrubStatusCode(bool $has_data, bool $has_error): int
    {
        if (! $has_data) {
            return BtrfsSensorSync::STATUS_NA;
        }

        if ($has_error) {
            return BtrfsSensorSync::STATUS_ERROR;
        }

        return BtrfsSensorSync::STATUS_OK;
    }

    public function getStatusText(int $status_code): string
    {
        return match ($status_code) {
            BtrfsSensorSync::STATUS_RUNNING => 'Running',
            BtrfsSensorSync::STATUS_NA => 'N/A',
            BtrfsSensorSync::STATUS_ERROR => 'Error',
            BtrfsSensorSync::STATUS_MISSING => 'Missing',
            BtrfsSensorSync::STATUS_UNKNOWN => 'Unknown',
            default => 'OK',
        };
    }

    public function deriveAppStatusCode(bool $has_missing, bool $has_error, bool $has_running, bool $has_data): int
    {
        if ($has_missing) {
            return BtrfsSensorSync::STATUS_MISSING;
        }
        if ($has_error) {
            return BtrfsSensorSync::STATUS_ERROR;
        }
        if ($has_running) {
            return BtrfsSensorSync::STATUS_RUNNING;
        }
        if ($has_data) {
            return BtrfsSensorSync::STATUS_OK;
        }

        return BtrfsSensorSync::STATUS_NA;
    }

    public function getBalanceStatusCodeFromFlat(array $balance_status): int
    {
        if (empty($balance_status)) {
            return BtrfsSensorSync::STATUS_OK;   // no data = not running = idle
        }

        if (! empty($balance_status['is_running'])) {
            return BtrfsSensorSync::STATUS_RUNNING;
        }

        $message = $balance_status['message'] ?? '';
        if ($message !== '' && ! str_contains(strtolower($message), 'no balance found')) {
            return BtrfsSensorSync::STATUS_ERROR;
        }

        return BtrfsSensorSync::STATUS_OK;   // "no balance found" = idle
    }
}

// =============================================================================
// BtrfsPayloadParser
// =============================================================================

/**
 * BtrfsPayloadParser extracts data from btrfs unix-agent payloads.
 *
 * Operates directly on the grouped payload format:
 * - data.tables.FS.<uuid>.filesystem
 * - data.tables.FS.<uuid>.filesystem_capacity
 * - data.tables.FS.<uuid>.usage_ratio
 * - data.tables.FS.<uuid>.filesystem_profiles
 * - data.tables.FS.<uuid>.filesystem_devices
 * - data.tables.FS.<uuid>.scrub_status
 * - data.tables.FS.<uuid>.scrub_status_devices
 * - data.tables.FS.<uuid>.balance_status
 */
class BtrfsPayloadParser
{
    public function normalizeId(string $value): string
    {
        $id = preg_replace('/[^A-Za-z0-9._-]/', '_', $value);

        return trim((string) $id, '_');
    }

    public function getFsInfo(array $tables, string $fs_uuid): array
    {
        $fs = $tables['FS'][$fs_uuid]['filesystem'] ?? [];

        return [
            'mountpoint' => $fs['mountpoint'] ?? $fs['primary_mountpoint'] ?? '',
            'label' => $fs['label'] ?? '',
            'total_devices' => $fs['total_devices'] ?? null,
            'fs_bytes_used' => $fs['bytes_used'] ?? null,
        ];
    }

    public function extractBalanceStatus(array $tables, string $fs_uuid): array
    {
        return $tables['FS'][$fs_uuid]['balance_status'] ?? [];
    }

    public function extractUsageTypeTotals(array $tables, string $fs_uuid): array
    {
        $totals = [];
        foreach ($tables['FS'][$fs_uuid]['filesystem_profiles'] ?? [] as $profile) {
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

    public function extractScrubStatus(array $tables, string $fs_uuid): array
    {
        return $tables['FS'][$fs_uuid]['scrub_status'] ?? [];
    }

    public function extractScrubDevices(array $tables, string $fs_uuid): array
    {
        return $tables['FS'][$fs_uuid]['scrub_status_devices'] ?? [];
    }

    public function processSingleDeviceScrub(array $deviceScrubData, ?float $previousProgress): array
    {
        $data = $this->normalizeDeviceScrubStatus($deviceScrubData, $previousProgress);

        // ops_status: -1 = unknown, 0 = idle, 1 = running
        $ops = (int) ($data['ops_status'] ?? -1);
        $running = $ops === 1;

        $health = $this->computeScrubHealth($data);

        $progress = $data['progress_percent'] ?? null;
        $bytesScrubbed = $data['bytes_scrubbed'] ?? null;
        $scrubStarted = $data['scrub_started'] ?? null;

        return [
            'running' => $running,
            'ops' => $ops,
            'health' => $health,
            'progress' => is_numeric($progress) ? (float) $progress : null,
            'bytes_scrubbed' => is_numeric($bytesScrubbed) ? (float) $bytesScrubbed : null,
            'scrub_started' => is_numeric($scrubStarted) ? (int) $scrubStarted : null,
            'data' => $data,
        ];
    }

    private function normalizeDeviceScrubStatus(array $deviceScrubData, ?float $previousProgress): array
    {
        $data = $deviceScrubData;

        if (($data['status'] ?? null) === null) {
            if ($previousProgress !== null && $previousProgress >= 100.0) {
                $data['status'] = 'finished';
                $data['progress_percent'] = 100;
            }
        }

        if (array_key_exists('progress_percent', $data) && $data['progress_percent'] === null) {
            if (strtolower(trim($data['status'] ?? '')) === 'finished') {
                $data['progress_percent'] = 100;
            }
        }

        return $data;
    }

    public function computeScrubHealth(array $scrub_device): int
    {
        $uncorrectable = (int) ($scrub_device['uncorrectable_errors'] ?? 0);
        $super_errors = (int) ($scrub_device['super_errors'] ?? 0);
        $malloc_errors = (int) ($scrub_device['malloc_errors'] ?? 0);

        if ($uncorrectable > 0 || $super_errors > 0 || $malloc_errors > 0) {
            return 2;
        }

        $corrected = (int) ($scrub_device['corrected_errors'] ?? 0);
        $read_errors = (int) ($scrub_device['read_errors'] ?? 0);
        $verify_errors = (int) ($scrub_device['verify_errors'] ?? 0);
        $unverified = (int) ($scrub_device['unverified_errors'] ?? 0);

        if ($corrected > 0 || $read_errors > 0 || $verify_errors > 0 || $unverified > 0) {
            return 1;
        }

        return 0;
    }

    public function getFilesystemsByMountpoint(array $tables): array
    {
        $result = [];
        foreach ($tables['FS'] ?? [] as $fs_uuid => $group) {
            $fs = is_array($group) ? ($group['filesystem'] ?? []) : [];
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

// =============================================================================
// BtrfsRrdWriter
// =============================================================================

/**
 * BtrfsRrdWriter handles RRD definitions and write operations for btrfs metrics.
 *
 * Extends AppRrdWriter to provide btrfs-specific:
 * - Filesystem-level, device-level, and dynamic type RRD definitions
 * - Field builders for IO errors, scrub stats, and usage
 * - Error key arrays and aggregation helpers
 *
 * RRD structure:
 * - app-btrfs-{app_id}-{fs_name}: filesystem-level space/usage/status metrics
 * - app-btrfs-{app_id}-{fs_name}-device_{devid}: per-device IO/scrub/usage
 * - app-btrfs-{app_id}-{fs_name}-type_{type_id}: dynamic type series (e.g., raid profiles)
 * - app-btrfs-{app_id}-{fs_name}-device_{devid}-type_{type_id}: per-device dynamic types
 * - sensor-btrfs-{sensor_index}: synthetic sensor values
 */
class BtrfsRrdWriter extends AppRrdWriter
{
    public const DS_IO_STATUS = 'io_status_code';
    public const DS_SCRUB_STATUS = 'scrub_status_code';
    public const DS_SCRUB_OPERATION = 'scrub_operation';
    public const DS_SCRUB_HEALTH = 'scrub_health';
    public const DS_BALANCE_STATUS = 'balance_status_code';
    public const DS_SCRUB_BYTES = 'scrub_bytes_done';

    public const FS_SPACE_DATASETS = [
        'device_size',
        'device_allocated',
        'device_unallocated',
        'used',
        'free_estimated',
        'free_estimated_min',
        'free_statfs_df',
        'global_reserve',
        'global_reserve_used',
        'device_missing',
        'device_slack',
        'data_ratio',
        'metadata_ratio',
    ];

    private RrdDefinition $fsRrdDef;
    private RrdDefinition $deviceRrdDef;
    private RrdDefinition $dynamicTypeRrdDef;
    private array $ioErrorKeys;
    private array $scrubErrorKeys;

    private static array $callCounters = [
        'writeFsRrd' => 0,
        'writeDeviceRrd' => 0,
        'writeTypeRrd' => 0,
        'writeDevTypeRrd' => 0,
        'sumDeviceErrors' => 0,
        'hasDeviceError' => 0,
        'hasAnyDeviceError' => 0,
        'buildDeviceFields' => 0,
        'buildDeviceTableRow' => 0,
    ];

    public static function getCallCounters(): array
    {
        return self::$callCounters;
    }

    public static function resetCallCounters(): void
    {
        foreach (self::$callCounters as $key => $value) {
            self::$callCounters[$key] = 0;
        }
    }

    public static function printCallCounters(): void
    {
        echo "BtrfsRrdWriter call counters:\n";
        foreach (self::$callCounters as $method => $count) {
            echo " btrfs $method: $count\n";
        }
    }

    public function __construct()
    {
        $this->fsRrdDef = RrdDefinition::make();
        foreach (self::FS_SPACE_DATASETS as $ds) {
            $this->fsRrdDef->addDataset($ds, 'GAUGE', 0);
        }
        $this->fsRrdDef
            ->addDataset('usage_device_size', 'GAUGE', 0)
            ->addDataset('usage_unallocated', 'GAUGE', 0)
            ->addDataset('usage_data', 'GAUGE', 0)
            ->addDataset('usage_metadata', 'GAUGE', 0)
            ->addDataset('usage_system', 'GAUGE', 0)
            ->addDataset(self::DS_SCRUB_BYTES, 'COUNTER', 0)
            ->addDataset(self::DS_IO_STATUS, 'GAUGE', 0)
            ->addDataset(self::DS_SCRUB_STATUS, 'GAUGE', 0)
            ->addDataset(self::DS_SCRUB_OPERATION, 'GAUGE', 0)
            ->addDataset(self::DS_SCRUB_HEALTH, 'GAUGE', 0)
            ->addDataset(self::DS_BALANCE_STATUS, 'GAUGE', 0);

        $this->deviceRrdDef = RrdDefinition::make()
            ->addDataset('io_d_corruption', 'DERIVE', 0)
            ->addDataset('io_d_flush'      , 'DERIVE', 0)
            ->addDataset('io_d_generation', 'DERIVE', 0)
            ->addDataset('io_d_read',       'DERIVE', 0)
            ->addDataset('io_d_write',      'DERIVE', 0)
            ->addDataset('io_t_corruption', 'GAUGE', 0)
            ->addDataset('io_t_flush', 'GAUGE', 0)
            ->addDataset('io_t_generation', 'GAUGE', 0)
            ->addDataset('io_t_read', 'GAUGE', 0)
            ->addDataset('io_t_write', 'GAUGE', 0)
            ->addDataset('scrub_t_read', 'GAUGE', 0)
            ->addDataset('scrub_t_csum', 'GAUGE', 0)
            ->addDataset('scrub_t_verify', 'GAUGE', 0)
            ->addDataset('scrub_t_uncorrectable', 'GAUGE', 0)
            ->addDataset('scrub_t_unverified', 'GAUGE', 0)
            ->addDataset('scrub_t_corrected', 'GAUGE', 0)
            ->addDataset('scrub_d_read', 'DERIVE', 0)
            ->addDataset('scrub_d_csum', 'DERIVE', 0)
            ->addDataset('scrub_d_verify', 'DERIVE', 0)
            ->addDataset('scrub_d_uncorrectable', 'DERIVE', 0)
            ->addDataset('scrub_d_unverified', 'DERIVE', 0)
            ->addDataset('scrub_d_corrected', 'DERIVE', 0)
            ->addDataset(self::DS_SCRUB_OPERATION, 'GAUGE', 0)
            ->addDataset(self::DS_SCRUB_HEALTH, 'GAUGE', 0)
            ->addDataset('usage_size', 'GAUGE', 0)
            ->addDataset('usage_slack', 'GAUGE', 0)
            ->addDataset('usage_unallocated', 'GAUGE', 0)
            ->addDataset('usage_data', 'GAUGE', 0)
            ->addDataset('usage_metadata', 'GAUGE', 0)
            ->addDataset('usage_system', 'GAUGE', 0);

        $this->dynamicTypeRrdDef = RrdDefinition::make()
            ->addDataset('value', 'GAUGE', 0);

        $this->ioErrorKeys = ['corruption_errs', 'flush_io_errs', 'generation_errs', 'read_io_errs', 'write_io_errs'];
        // Only numeric scrub error counters belong here; status flags like missing/device_missing are booleans.
        $this->scrubErrorKeys = ['read_errors', 'csum_errors', 'verify_errors', 'uncorrectable_errors', 'unverified_errors'];
    }

    protected function defineDatasets(): array
    {
        return array_fill_keys(self::FS_SPACE_DATASETS, 'GAUGE');
    }

    public function buildFields(array $data): array
    {
        return $data;
    }

    public function writeFsRrd(array $device, string $app_name, int $app_id, string $fs_rrd_id, array $fields, array $tags = []): void
    {
        self::$callCounters['writeFsRrd']++;
        $this->write($device, $app_name, $app_id, $fs_rrd_id, $fields, array_merge($tags, ['rrd_def' => $this->fsRrdDef]));
    }

    public function writeDeviceRrd(array $device, string $app_name, int $app_id, string $fs_rrd_id, string $dev_id, array $fields, array $tags = []): void
    {
        self::$callCounters['writeDeviceRrd']++;
        $this->write($device, $app_name, $app_id, $fs_rrd_id . '_device_' . $dev_id, $fields, array_merge($tags, ['rrd_def' => $this->deviceRrdDef]));
    }

    public function writeTypeRrd(array $device, string $app_name, int $app_id, string $fs_rrd_id, string $type_id, float $value, array $tags = []): void
    {
        self::$callCounters['writeTypeRrd']++;
        $this->write($device, $app_name, $app_id, $fs_rrd_id . '-type_' . $type_id, ['value' => $value], array_merge($tags, ['rrd_def' => $this->dynamicTypeRrdDef]));
    }

    public function writeDevTypeRrd(array $device, string $app_name, int $app_id, string $fs_rrd_id, string $dev_id, string $type_id, float $value, array $tags = []): void
    {
        self::$callCounters['writeDevTypeRrd']++;
        $this->write($device, $app_name, $app_id, $fs_rrd_id . '_device_' . $dev_id . '_type_' . $type_id, ['value' => $value], array_merge($tags, ['rrd_def' => $this->dynamicTypeRrdDef]));
    }

    public function sumDeviceErrors(array $dev_stats): float
    {
        self::$callCounters['sumDeviceErrors']++;

        return $this->sumErrors($dev_stats, $this->ioErrorKeys);
    }

    public function hasDeviceError(array $dev_stats): bool
    {
        self::$callCounters['hasDeviceError']++;

        return $this->hasErrors($dev_stats, $this->ioErrorKeys);
    }

    public function hasAnyDeviceError(array $devices): bool
    {
        self::$callCounters['hasAnyDeviceError']++;
        foreach ($devices as $dev) {
            if ($this->hasErrors($dev, $this->ioErrorKeys)) {
                return true;
            }
        }

        return false;
    }

    public function buildDeviceFields(array $dev_stats, array $scrub_stats, array $usage_stats, ?int $scrub_operation = null, ?int $scrub_health = null): array
    {
        self::$callCounters['buildDeviceFields']++;

        return [
            'io_d_corruption' => $dev_stats['corruption_errs'] ?? null,
            'io_d_flush' => $dev_stats['flush_io_errs'] ?? null,
            'io_d_generation' => $dev_stats['generation_errs'] ?? null,
            'io_d_read' => $dev_stats['read_io_errs'] ?? null,
            'io_d_write' => $dev_stats['write_io_errs'] ?? null,
            'io_t_corruption' => $dev_stats['corruption_errs'] ?? null,
            'io_t_flush' => $dev_stats['flush_io_errs'] ?? null,
            'io_t_generation' => $dev_stats['generation_errs'] ?? null,
            'io_t_read' => $dev_stats['read_io_errs'] ?? null,
            'io_t_write' => $dev_stats['write_io_errs'] ?? null,
            'scrub_t_read' => $scrub_stats['read_errors'] ?? null,
            'scrub_t_csum' => $scrub_stats['csum_errors'] ?? null,
            'scrub_t_verify' => $scrub_stats['verify_errors'] ?? null,
            'scrub_t_uncorrectable' => $scrub_stats['uncorrectable_errors'] ?? null,
            'scrub_t_unverified' => $scrub_stats['unverified_errors'] ?? null,
            'scrub_t_corrected' => $scrub_stats['corrected_errors'] ?? null,
            'scrub_d_read' => $scrub_stats['read_errors'] ?? null,
            'scrub_d_csum' => $scrub_stats['csum_errors'] ?? null,
            'scrub_d_verify' => $scrub_stats['verify_errors'] ?? null,
            'scrub_d_uncorrectable' => $scrub_stats['uncorrectable_errors'] ?? null,
            'scrub_d_unverified' => $scrub_stats['unverified_errors'] ?? null,
            'scrub_d_corrected' => $scrub_stats['corrected_errors'] ?? null,
            self::DS_SCRUB_OPERATION => $scrub_operation,
            self::DS_SCRUB_HEALTH => $scrub_health,
            'usage_size' => $usage_stats['device_size'] ?? null,
            'usage_slack' => $usage_stats['device_slack'] ?? null,
            'usage_unallocated' => $usage_stats['unallocated'] ?? null,
            'usage_data' => $usage_stats['data_bytes'] ?? null,
            'usage_metadata' => $usage_stats['metadata_bytes'] ?? null,
            'usage_system' => $usage_stats['system_bytes'] ?? null,
        ];
    }

    public function buildDeviceTableRow(string $dev_path, $device_numeric_id, array $dev_stats, array $usage_stats): array
    {
        self::$callCounters['buildDeviceTableRow']++;

        return [
            'path' => $dev_path,
            'devid' => $device_numeric_id,
            'missing' => $dev_stats['missing'] ?? null,
            'errors' => [
                'write_io_errs' => $dev_stats['write_io_errs'] ?? null,
                'read_io_errs' => $dev_stats['read_io_errs'] ?? null,
                'flush_io_errs' => $dev_stats['flush_io_errs'] ?? null,
                'corruption_errs' => $dev_stats['corruption_errs'] ?? null,
                'generation_errs' => $dev_stats['generation_errs'] ?? null,
            ],
            'usage' => [
                'size' => $usage_stats['device_size'] ?? null,
                'slack' => $usage_stats['device_slack'] ?? null,
                'unallocated' => $usage_stats['unallocated'] ?? null,
                'data' => $usage_stats['data_bytes'] ?? null,
                'metadata' => $usage_stats['metadata_bytes'] ?? null,
                'system' => $usage_stats['system_bytes'] ?? null,
            ],
            'raid_profiles' => $usage_stats['type_values'] ?? [],
        ];
    }

}

// =============================================================================
// BtrfsSensorSync
// =============================================================================

/**
 * BtrfsSensorSync handles sensor creation, update, and deletion for btrfs sensors.
 *
 * Sensor types:
 * - state: IO/Scrub/Balance status sensors — poller_type='agent', sensor_type = unique btrfs state name
 * - count: IO error count sensors — poller_type='agent', sensor_type = COUNT_SENSOR_IO_ERRORS
 *
 * Discovery uses the app('sensor-discovery') pipeline:
 *   discoverStateSensor() / discoverCountSensor() → buffer sensors
 *   syncDiscoveredSensors()                       → creates new, updates existing, deletes removed
 *
 * Polling updates values directly:
 *   writeStateSensorRrd()    — updates sensor_current in DB + writes RRD (every poll)
 *   updateCountSensorValue() — updates sensor_current in DB + writes RRD (every poll)
 */
class BtrfsSensorSync
{
    public const STATUS_OK = 0;
    public const STATUS_RUNNING = 1;
    public const STATUS_NA = 2;
    public const STATUS_ERROR = 3;
    public const STATUS_MISSING = 4;
    public const STATUS_UNKNOWN = -1;

    public const STATE_SENSOR_HEALTH_FS = 'btrfsFsHealthState';
    public const STATE_SENSOR_HEALTH_DEVICE = 'btrfsDeviceHealthState';
    public const STATE_SENSOR_SCRUB = 'btrfsScrubStatusState';
    public const STATE_SENSOR_SCRUB_OPS = 'btrfsScrubOpsState';
    public const STATE_SENSOR_BALANCE = 'btrfsBalanceStatusState';

    public const COUNT_SENSOR_IO_ERRORS = 'btrfsIoErrors'; 

    public const COUNT_SENSOR_WARN_LEVEL = 5;
    public const COUNT_SENSOR_ERROR_LEVEL = 10;

    private const STATE_SENSOR_TYPES = [
        self::STATE_SENSOR_HEALTH_FS,
        self::STATE_SENSOR_HEALTH_DEVICE,
        self::STATE_SENSOR_SCRUB,
        self::STATE_SENSOR_SCRUB_OPS,
        self::STATE_SENSOR_BALANCE,
    ];

    private const COUNT_SENSOR_TYPES = [
        self::COUNT_SENSOR_IO_ERRORS, 
    ];

    // ==========================================================================
    // Discovery — buffer sensors and sync to DB
    // ==========================================================================

    /**
     * Reset the sensor-discovery singleton so sensors from other modules
     * do not bleed into this app's sync() calls.
     * Call once before any discoverStateSensor() / discoverCountSensor() calls.
     */
    public function resetDiscoveryBuffer(): void
    {
        app()->forgetInstance('sensor-discovery');
    }

    /**
     * Buffer a state sensor in the discovery singleton.
     * Call syncDiscoveredSensors() after all sensors are buffered.
     */
    public function discoverStateSensor(
        array $device,
        string $sensorIndex,
        string $sensorType,
        string $sensorDescr,
        int $sensorCurrent,
        string $sensorGroup
    ): void {
        if ($sensorType === self::STATE_SENSOR_HEALTH_FS || $sensorType === self::STATE_SENSOR_HEALTH_DEVICE) {
            $translations = [
                StateTranslation::define('OK', 0, Severity::Ok),
                StateTranslation::define('IO Error', 1, Severity::Warning),
                StateTranslation::define('Missing Device', 2, Severity::Error),
                StateTranslation::define('Missing Filesystem', 3, Severity::Error),
            ];
        } elseif ($sensorType === self::STATE_SENSOR_SCRUB_OPS || $sensorType === self::STATE_SENSOR_BALANCE) {
            $translations = [
                StateTranslation::define('Idle', 0, Severity::Ok),
                StateTranslation::define('Running', 1, Severity::Warning),
            ];
        } else {
            $translations = [
                StateTranslation::define('N/A', self::STATUS_UNKNOWN, Severity::Unknown),
                StateTranslation::define('OK', self::STATUS_OK, Severity::Ok),
                StateTranslation::define('Running', self::STATUS_RUNNING, Severity::Warning),
                StateTranslation::define('N/A', self::STATUS_NA, Severity::Unknown),
                StateTranslation::define('Error', self::STATUS_ERROR, Severity::Error),
                StateTranslation::define('Missing', self::STATUS_MISSING, Severity::Error),
            ];
        }

        app('sensor-discovery')
            ->discover(new Sensor([
                'device_id'          => $device['device_id'],
                'sensor_class'       => 'state',
                'poller_type'        => 'agent',
                'sensor_type'        => $sensorType,
                'sensor_index'       => $sensorIndex,
                'sensor_oid'         => 'app:btrfs:' . $sensorIndex,
                'sensor_descr'       => $sensorDescr,
                'sensor_current'     => $sensorCurrent,
                'group'              => $sensorGroup,
            ]))
            ->withStateTranslations($sensorType, $translations);
    }

    /**
     * Buffer a count sensor in the discovery singleton.
     * Call syncDiscoveredSensors() after all sensors are buffered.
     */
    public function discoverCountSensor(
        array $device,
        string $sensorIndex,
        string $sensorDescr,
        float $sensorCurrent,
        string $sensorGroup
    ): void {
        app('sensor-discovery')->discover(new Sensor([
            'device_id'          => $device['device_id'],
            'sensor_class'       => 'count',
            'poller_type'        => 'agent',
            'sensor_type'        => self::COUNT_SENSOR_IO_ERRORS,
            'sensor_index'       => $sensorIndex,
            'sensor_oid'         => 'app:btrfs:' . $sensorIndex,
            'sensor_descr'       => $sensorDescr,
            'sensor_current'     => $sensorCurrent,
            'sensor_limit_warn'  => self::COUNT_SENSOR_WARN_LEVEL,
            'sensor_limit'       => self::COUNT_SENSOR_ERROR_LEVEL,
            'sensor_divisor'     => 1,
            'sensor_multiplier'  => 1,
            'group'              => $sensorGroup,
        ]));
    }

    /**
     * Sync all buffered sensors to the DB: creates new sensors, updates existing
     * ones, and deletes sensors whose indexes are no longer in the buffer.
     * One sync() call per sensor_type scopes the delete to that type only.
     * Must be called after all discoverStateSensor() / discoverCountSensor() calls.
     */
    public function syncDiscoveredSensors(): void
    {
        foreach (self::STATE_SENSOR_TYPES as $sensorType) {
            app('sensor-discovery')->sync(sensor_type: $sensorType);
        }
        app('sensor-discovery')->sync(sensor_type: self::COUNT_SENSOR_IO_ERRORS);
    }

    // ==========================================================================
    // Polling — update values every poll
    // ==========================================================================

    /**
     * Update sensor_current in the DB and write the RRD for a state sensor.
     * Called every poll so graphs and alert evaluations stay current.
     * sensor_current is stored directly; the RRD writer receives the same value.
     */
    public function writeStateSensorRrd(
        array $device,
        string $sensorIndex, 
        string $sensorType, 
        string $sensorDescr, 
        int $sensorCurrent): void
    {
        Sensor::withoutGlobalScopes()
            ->where('device_id', $device['device_id'])
            ->where('sensor_class', 'state')
            ->where('poller_type', 'agent')
            ->where('sensor_type', $sensorType)
            ->where('sensor_index', $sensorIndex)
            ->update(['sensor_current' => $sensorCurrent]);

        $this->writeSensorRrd($device, 'state', $sensorType, $sensorIndex, $sensorDescr, (float) $sensorCurrent);
    }

    /**
     * Update sensor_current in the DB and write the RRD for a count sensor.
     * Called every poll to keep the error count current.
     */
    public function updateCountSensorValue(
        array $device,
        string $sensorIndex,
        string $sensorDescr,
        float $sensorCurrent
    ): void {
        Sensor::withoutGlobalScopes()
            ->where('device_id', $device['device_id'])
            ->where('sensor_class', 'count')
            ->where('poller_type', 'agent')
            ->where('sensor_type', self::COUNT_SENSOR_IO_ERRORS)
            ->where('sensor_index', $sensorIndex)
            ->update(['sensor_current' => $sensorCurrent]);

        $this->writeSensorRrd($device, 'count', self::COUNT_SENSOR_IO_ERRORS, $sensorIndex, $sensorDescr, $sensorCurrent);
    }

    // ==========================================================================
    // Cleanup
    // ==========================================================================

    /**
     * Delete all btrfs state and count sensors for a device.
     * Called when the agent payload version is unsupported or invalid.
     */
    public function deleteAllStateAndCountSensors(array $device): void
    {
        $stateSensors = Sensor::withoutGlobalScopes()
            ->where('device_id', $device['device_id'])
            ->where('sensor_class', 'state')
            ->where('poller_type', 'agent')
            ->whereIn('sensor_type', self::STATE_SENSOR_TYPES)
            ->get();

        $stateSensorIds = $stateSensors->pluck('sensor_id');

        SensorToStateIndex::whereIn('sensor_id', $stateSensorIds)->delete();
        Sensor::withoutGlobalScopes()
            ->whereIn('sensor_id', $stateSensorIds)
            ->delete();

        Sensor::withoutGlobalScopes()
            ->where('device_id', $device['device_id'])
            ->where('sensor_class', 'count')
            ->where('poller_type', 'agent')
            ->whereIn('sensor_type', self::COUNT_SENSOR_TYPES)
            ->delete();
    }

    // ==========================================================================
    // Internals
    // ==========================================================================

    private function writeSensorRrd(array $device, string $sensorClass, string $sensorType, string $sensorIndex, string $sensorDescr, float $sensorCurrent): void
    {
        app('Datastore')->put($device, 'sensor', [
            'sensor_class' => $sensorClass,
            'sensor_type'  => $sensorType,
            'sensor_index' => $sensorIndex,
            'sensor_descr' => $sensorDescr,
            'rrd_def'      => RrdDefinition::make()->addDataset('sensor', 'GAUGE'),
            'rrd_name'     => ['sensor', $sensorClass, $sensorType, $sensorIndex],
        ], ['sensor' => $sensorCurrent]);
    }

}

// =============================================================================
// Discovery
// =============================================================================

/**
 * Handles discovery tasks for btrfs:
 * - Detect filesystem add/remove events and log to eventlog
 * - Detect device add/remove events per filesystem and log to eventlog
 * - Clean up obsolete sensors for filesystems/devices that no longer exist
 * - Cache filesystem/device structure for polling loop optimization
 *
 * Discovery is run:
 * - On first poll (when no previous state exists)
 * - When filesystem or device count increases
 * - Periodically (every N polls based on interval)
 *
 * All discovery state is stored in app->data['discovery']:
 *   - schema_version: Structure version number
 *   - poll_count: Polls since last discovery ran (reset to 0 when discovery runs)
 *   - last_run: Unix timestamp of last discovery
 *   - counters: Current counts from agent payload
 *   - filesystems: Cached filesystem structure for change detection
 */
class BtrfsDiscovery
{
    private const SCHEMA_VERSION = 1;

    private array $working = [];

    public function __construct(
        private readonly BtrfsPayloadParser $parser,
        private readonly BtrfsSensorSync $sensorSync,
    ) {
    }

    /**
     * Execute the full discovery scan. Only called when the caller has already
     * determined that discovery is due (skip check done externally).
     */
    public function discover(array $device, Application $app, array $tables, array $discovery, array $currentCounters): void
    {
        App_log::section('DISCOVERY');
        App_log::info('Running discovery', ['fs_count' => $currentCounters['filesystems']]);

        $previousFilesystems = $discovery['filesystems'] ?? [];
        $currentFsByMountpoint = $this->parser->getFilesystemsByMountpoint($tables);

        // Snapshot the current filesystem+device structure for change detection
        $currentFilesystems = $this->discoverBuildFilesystemState($currentFsByMountpoint, $tables);

        $this->working = [
            'device' => $device,
            'app' => $app,
            'discovery' => $discovery,
            'current_counters' => $currentCounters,
            'previous_filesystems' => $previousFilesystems,
            'current_filesystems' => $currentFilesystems,
        ];

        // Reset the discovery buffer so sensors from other modules do not bleed into sync().
        $this->sensorSync->resetDiscoveryBuffer();

        // Detect and log add/remove events, then sync sensors for the current topology.
        $this->discoverFilesystemsAddRemove();
        $this->discoverDevicesAddRemove();
        $this->discoverExpectedSensors();

        $this->working['discovery']['last_run'] = time();
        $this->working['discovery']['counters'] = $this->working['current_counters'];
        $this->working['discovery']['filesystems'] = $this->working['current_filesystems'];
        $this->saveDiscoveryData($this->working['app'], $this->working['discovery']);

        App_log::info('Discovery completed', ['fs_count' => count($this->working['current_filesystems'])]);
    }

    /**
     * Load previous discovery state and migrate schema if needed.
     */
    public function initDiscoveryState(Application $app): array
    {
        // Previous discovery state is stored in the LibreNMS DB via app->data, not in the agent payload
        $previous = $app->data['discovery'] ?? null;

        // First poll: seed with an empty state
        $discovery = $previous ?? [
            'schema_version' => self::SCHEMA_VERSION,
            'last_run' => 0,
            'counters' => ['filesystems' => 0, 'devices' => 0, 'backing_devices' => 0],
            'filesystems' => [],
            'sensor_paths' => ['filesystems' => []],
        ];

        // Keep one canonical discovery format for new data.
        // Legacy schema migration is intentionally deferred.
        $discovery['schema_version'] = self::SCHEMA_VERSION;
        $discovery['last_run'] = $discovery['last_run'] ?? 0;
        $discovery['counters'] = $discovery['counters'] ?? ['filesystems' => 0, 'devices' => 0, 'backing_devices' => 0];
        $discovery['filesystems'] = $discovery['filesystems'] ?? [];
        $discovery['sensor_paths'] = $discovery['sensor_paths'] ?? ['filesystems' => []];

        return $discovery;
    }

    /**
     * Persist discovery state into app->data['discovery'].
     */
    public function saveDiscoveryData(Application $app, array $discovery): void
    {
        $data = $app->data ?? [];
        $data['discovery'] = $discovery;
        $app->data = $data;
    }

    /**
     * Returns true if discovery should be skipped this poll.
     * Forces discovery (returns false) on first poll, when filesystem or device
     * count has increased, or when the interval has elapsed.
     *
     * @param  array  $currentCounters  Counts from the agent payload (filesystems, devices, backing_devices)
     */
    public function shouldSkip(array $currentCounters, array $discovery, int $pollCount, int $interval): bool
    {
        // true = skip discovery, false = run discovery
        if (empty($discovery['filesystems'])) {
            App_log::info('No previous discovery data - first poll, discovery will run');

            return false;  // force: first poll
        }

        if ($currentCounters['filesystems'] > ($discovery['counters']['filesystems'] ?? 0)) {
            App_log::info('Filesystem count increased - discovery will run', [
                'current' => $currentCounters['filesystems'],
                'previous' => $discovery['counters']['filesystems'] ?? 0,
            ]);

            return false;  // force: new filesystem added
        }

        if ($currentCounters['devices'] > ($discovery['counters']['devices'] ?? 0)) {
            App_log::info('Device count increased - discovery will run', [
                'current' => $currentCounters['devices'],
                'previous' => $discovery['counters']['devices'] ?? 0,
            ]);

            return false;  // force: new device added
        }

        // poll_count is reset to 0 each time discovery runs, so it means
        // "polls since last discovery". interval=0 disables periodic discovery.
        if ($interval === 0) {
            return true;   // periodic discovery disabled
        }

        if ($pollCount < $interval) {
            return true;   // timer not reached
        }

        return false;      // interval reached, run
    }

    /**
     * Extract current filesystem/device/backing-device counts from the agent payload.
     * Falls back to counting table rows when explicit counters are absent.
     */
    public function extractCounters(array $agentOutput, array $tables): array
    {
        $fsGroups = $tables['FS'] ?? [];
        $fsCount = 0;
        $deviceCount = 0;
        $backingCount = 0;

        foreach ($fsGroups as $group) {
            if (! is_array($group) || ! is_array($group['filesystem'] ?? null)) {
                continue;
            }

            $fsCount++;
            $deviceCount += count($group['filesystem_devices'] ?? []);
            $backingCount += count($group['backing_devices'] ?? []);
        }

        return [
            'filesystems' => (int) ($agentOutput['counters']['filesystems'] ?? $fsCount),
            'devices' => (int) ($agentOutput['counters']['devices'] ?? $deviceCount),
            'backing_devices' => (int) ($agentOutput['counters']['backing_devices'] ?? $backingCount),
        ];
    }

    /**
     * Build filesystem state structure with all info needed by polling loop.
     *
     * @param  array  $fsByMountpoint  Filesystem UUIDs keyed by mountpoint
     * @return array Filesystem state keyed by UUID
     */
    private function discoverBuildFilesystemState(array $fsByMountpoint, array $tables): array
    {
        $state = [];
        App_log::section('Discovery: FS state');
        foreach ($fsByMountpoint as $fsMountpoint => $fsUuid) {
            $fsGroup = $tables['FS'][$fsUuid] ?? [];
            $fsRow = $fsGroup['filesystem'] ?? [];
            if (! is_array($fsRow)) {
                continue;
            }

            // RRD key is the first 8 chars of the UUID, normalised to a safe identifier
            $rrdKey = $this->parser->normalizeId(substr($fsUuid, 0, 8));
            if ($rrdKey === '') {
                App_log::warning('Skipping filesystem with invalid rrd_key', [
                    'fs_mountpoint' => $fsMountpoint,
                    'fs_uuid' => $fsUuid,
                ]);
                continue;
            }

            // Build a devid→path map so the polling loop can detect device changes
            $fsInfo = $this->parser->getFsInfo($tables, $fsUuid);
            $displayName = ($fsInfo['label'] ?? '') !== ''
                ? (string) $fsInfo['label']
                : ($fsMountpoint === '/' ? 'root' : (string) $fsMountpoint);
            $rawFsDevices = $fsGroup['filesystem_devices'] ?? [];
            $deviceMap = [];
            $deviceMetadata = [];
            $deviceCount = 0;

            $devPathToBacking = [];
            foreach ($fsGroup['backing_devices'] ?? [] as $backingPath => $backingInfo) {
                if (is_array($backingInfo)) {
                    $devPathToBacking[$backingPath] = $backingInfo;
                }
            }

            foreach ($rawFsDevices as $devId => $rawDevice) {
                if (! is_array($rawDevice)) {
                    continue;
                }

                $devId = (string) $devId;
                if (! ctype_digit($devId)) {
                    continue;
                }
                $devPath = trim((string) ($rawDevice['device_path'] ?? ''));
                if ($devPath === '') {
                    $devPath = 'missing(' . $devId . ')';
                }

                $deviceMap[(string) $devId] = $devPath;
                $deviceCount++;

                $backingPath = is_array($rawDevice) ? ($rawDevice['backing_device_path'] ?? null) : null;
                $sysBlock = $fsGroup['devices'][$devPath] ?? null;
                $deviceMetadata[(string) $devId] = [
                    'backing' => $backingPath !== null ? ($devPathToBacking[$backingPath] ?? null) : null,
                    'backing_path' => $backingPath,
                    'sys_block' => $sysBlock,
                ];
            }

            $state[$fsUuid] = [
                'mountpoint' => $fsMountpoint,
                'label' => $fsInfo['label'] ?? '',
                'display_name' => $displayName,
                'rrd_key' => $rrdKey,
                'total_devices' => (int) ($fsRow['total_devices'] ?? 0),
                'device_count' => $deviceCount,
                'devices' => $deviceMap,
                'device_metadata' => $deviceMetadata,
            ];
        }

        App_log::info('Filesystem state built', [
            'count' => count($state),
            'uuids' => array_keys($state),
        ]);

        return $state;
    }

    /**
     * Detect and log filesystem add/remove events.
     */
    private function discoverFilesystemsAddRemove(): void
    {
        App_log::section('discovery FS');
        // Set-difference gives us the added and removed UUIDs in one pass each
        $previousFsUuids = array_keys($this->working['previous_filesystems']);
        $currentFsUuids = array_keys($this->working['current_filesystems']);
        $added = array_diff($currentFsUuids, $previousFsUuids);
        $removed = array_diff($previousFsUuids, $currentFsUuids);

        foreach ($added as $fsUuid) {
            $this->logApplicationEvent('Filesystem added: ' . $fsUuid);
        }

        foreach ($removed as $fsUuid) {
            $this->logApplicationEvent('Filesystem removed: ' . $fsUuid);
        }

        if (empty($added) && empty($removed)) {
            App_log::warning('No filesystem changes detected');
        }
    }

    /**
     * Detect and log device add/remove events per filesystem.
     */
    private function discoverDevicesAddRemove(): void
    {
        App_log::section('discovery devices');
        $previousFilesystems = $this->working['previous_filesystems'];
        $currentFilesystems = $this->working['current_filesystems'];

        foreach ($currentFilesystems as $fsUuid => $currentData) {
            // Fall back to empty device list for new filesystems not in previous state
            $previousData = $previousFilesystems[$fsUuid] ?? ['devices' => []];
            $previousDevices = $previousData['devices'] ?? [];
            $currentDevices = $currentData['devices'] ?? [];

            // Compare by devid so a device that moved to a different path is treated as removed+added
            $addedDevs = array_diff(array_keys($currentDevices), array_keys($previousDevices));
            $removedDevs = array_diff(array_keys($previousDevices), array_keys($currentDevices));

            foreach ($addedDevs as $devId) {
                $devPath = $currentDevices[$devId] ?? 'unknown';
                $this->logApplicationEvent('Device added: ' . $devPath . ' on ' . $fsUuid);
            }

            foreach ($removedDevs as $devId) {
                $devPath = $previousDevices[$devId] ?? 'unknown';
                $this->logApplicationEvent('Device removed: ' . $devPath . ' on ' . $fsUuid);
            }
        }
    }

    private function logApplicationEvent(string $message, Severity $severity = Severity::Info): void
    {
        Eventlog::log('BtrFS ' . $message, $this->working['device']['device_id'], 'application', $severity);
    }

    /**
     * Discover all sensors expected for the current filesystem/device topology.
     *
     * Buffers every sensor into the app('sensor-discovery') singleton using placeholder
     * values; the polling loop overwrites sensor_current with the real value each poll.
     * After all sensors are buffered, syncDiscoveredSensors() creates new sensors,
     * updates existing ones, and deletes any whose indexes are no longer present.
     */
    private function discoverExpectedSensors(): void
    {
        App_log::section('Discovery: SENSORS');

        $sensorCount = 0;
        $device = $this->working['device'];
        $currentFilesystems = $this->working['current_filesystems'];
        $sensorPathsByFilesystem = [];

        foreach ($currentFilesystems as $fsUuid => $fsData) {
            $rrdId = $fsData['rrd_key'];
            if ($rrdId === '') {
                continue;
            }

            $label = $fsData['label'] ?? '';
            $mountpoint = $fsData['mountpoint'] ?? '';
            $displayName = $label !== '' ? $label : ($mountpoint === '/' ? 'root' : $mountpoint);

            // Filesystem-level state sensors.
            // group = "btrfs::$displayName" — the "btrfs" top level identifies the source
            // application on the overview page; $displayName is the filesystem label (e.g.
            // "volum1").  sensor_descr includes $displayName as a prefix so it is
            // self-contained on the health page and in alerts; the overview strips
            // $displayName when rendering under its heading (e.g. "volum1 IO" → "IO").
            $fsGroup = "BtrFS $displayName";
            $this->sensorSync->discoverStateSensor($device, "$rrdId.health",
                BtrfsSensorSync::STATE_SENSOR_HEALTH_FS,
                "$fsGroup Health",
                0,
                $fsGroup);
            $sensorPathsByFilesystem[$fsUuid]['state']["$rrdId.health"] = 'status.health';

            $this->sensorSync->discoverStateSensor($device, "$rrdId.scrub",
                BtrfsSensorSync::STATE_SENSOR_SCRUB,
                "$fsGroup Scrub",
                BtrfsSensorSync::STATUS_NA,
                $fsGroup);
            $sensorPathsByFilesystem[$fsUuid]['state']["$rrdId.scrub"] = 'status.scrub';

            $this->sensorSync->discoverStateSensor($device, "$rrdId.scrub_ops",
                BtrfsSensorSync::STATE_SENSOR_SCRUB_OPS,
                "$fsGroup Scrub Ops",
                0,
                $fsGroup);
            $sensorPathsByFilesystem[$fsUuid]['state']["$rrdId.scrub_ops"] = 'status.scrub_ops';

            $this->sensorSync->discoverStateSensor($device, "$rrdId.balance",
                BtrfsSensorSync::STATE_SENSOR_BALANCE,
                "$fsGroup Balance Ops",
                0,
                $fsGroup);
            $sensorPathsByFilesystem[$fsUuid]['state']["$rrdId.balance"] = 'status.balance';

            // Filesystem-level IO error count sensor (different sensor class; same group).
            $this->sensorSync->discoverCountSensor($device, $rrdId,
                "$fsGroup IO Errors",
                0,
                $fsGroup);
            $sensorPathsByFilesystem[$fsUuid]['count'][$rrdId] = 'counts.io_errors';
            $sensorCount += 5;

            // Per-device sensors.
            // group = "BtrFS $displayName::Devices" nests all block devices under the
            // filesystem heading on the overview page.  sensor_descr is fully self-contained
            // (e.g. "BtrFS volum1 /dev/sdc IO") so it is unambiguous on the health page and
            // in alerts without any group context.
            foreach ($fsData['devices'] as $devId => $devPath) {
                $devGroup = "BtrFS $displayName::Devices";
                $this->sensorSync->discoverStateSensor($device, "$rrdId.dev.$devId.health",
                    BtrfsSensorSync::STATE_SENSOR_HEALTH_DEVICE,
                    "BtrFS $displayName $devPath Health",
                    0,
                    $devGroup);
                $sensorPathsByFilesystem[$fsUuid]['state']["$rrdId.dev.$devId.health"] = "devices.$devId.status.health";

                $this->sensorSync->discoverStateSensor($device, "$rrdId.dev.$devId.scrub",
                    BtrfsSensorSync::STATE_SENSOR_SCRUB,
                    "BtrFS $displayName $devPath Scrub",
                    BtrfsSensorSync::STATUS_NA,
                    $devGroup);
                $sensorPathsByFilesystem[$fsUuid]['state']["$rrdId.dev.$devId.scrub"] = "devices.$devId.status.scrub";

                $this->sensorSync->discoverCountSensor($device, "$rrdId.dev.$devId",
                    "BtrFS $displayName $devPath IO Errors",
                    0,
                    $devGroup);
                $sensorPathsByFilesystem[$fsUuid]['count']["$rrdId.dev.$devId"] = "devices.$devId.counts.io_errors";
                $sensorCount += 3;
            }
        }

        $this->working['discovery']['sensor_paths'] = ['filesystems' => $sensorPathsByFilesystem];

        App_log::info('Sensors discovered', ['count' => $sensorCount]);

        // Sync: creates new sensors, updates existing, deletes sensors not in the buffer.
        $this->sensorSync->syncDiscoveredSensors();
    }
}

// =============================================================================
// Poller
// =============================================================================

class BtrfsPoller
{
    // =============================================================================
    // Constants
    // =============================================================================

    // Minimum progress percentage at which a null scrub status is treated as finished (RAID5/6 fallback)
    private const SCRUB_ASSUMED_DONE_THRESHOLD = 90;

    // Run discovery every N polls (0 = disabled, discovery only on topology changes)
    private const DISCOVERY_INTERVAL = 3;

    // Maximum length for filesystem labels stored in app->data
    private const MAX_LABEL_LENGTH = 256;

    // Maximum length for the overall status text stored in app->data
    private const MAX_STATUS_TEXT_LENGTH = 64;

    // =============================================================================
    // Instance Properties
    // All properties are set once during initialize() and read throughout polling.
    // =============================================================================

    // Device array passed to poll(); set once in poll().
    private array $device = [];

    // The LibreNMS Application model for this btrfs app; set once in poll().
    private Application $app;

    // Parsed JSON payload from the btrfs agent; set in fetchPayload().
    private array $appPayload = [];

    // Accumulator: the app->data structure being built for this poll. Persisted to
    // the DB at the end of polling via $this->app->data = $this->newData.
    private array $newData = [];

    // Working state set per-filesystem during processFilesystem(); reset at the start
    // of each filesystem loop. Holds fs context ($working['fs']) and running metrics
    // accumulators ($working['acc']) shared across processFilesystem() and processDevices().
    private array $working = [];

    // =============================================================================
    // Service Objects
    // Instantiated once in initialize(); used throughout polling.
    // =============================================================================

    private BtrfsPayloadParser $parser;
    private BtrfsStatusMapper $mapper;
    private BtrfsSensorSync $sensorSync;
    private BtrfsRrdWriter $rrdWriter;
    private BtrfsDiscovery $discovery;

    private ?int $aggIoStatusMax = null;
    private ?int $aggScrubStatusMax = null;
    private ?int $aggBalanceStatusMax = null;

    /**
     * Main entry point: fetch payload, run discovery, process all filesystems, persist app data.
     */
    public function poll(array $device, Application $app): void
    {
        // Store device and app references for use in all private methods.
        $this->device = $device;
        $this->app = $app;

        // Initialise logging context for this poll cycle.
        App_log::setApp('btrfs');
        App_log::setHostname($device['hostname'] ?? 'unknown');
        App_log::setLevel('DEBUG');

        // Reset RRD call counters so each poll starts with a clean baseline.
        BtrfsRrdWriter::resetCallCounters();

        // Instantiate all service objects (parser, mapper, sensor sync, RRD writer, aggregator, discovery).
        $this->initialize();

        // Attempt to fetch and parse the JSON payload from the btrfs agent.
        // Returns the btrfs agent protocol version on success, throws on failure.
        // Initialise reference parameters before the call — PHP requires the caller's variable
        // to already be an array when passed by reference.
        $agentOutput = [];
        $oldData = [];
        try {
            $fetchResult = $this->fetchPayload($agentOutput, $oldData);
        } catch (JsonAppException) {
            return;
        }

        // Seed the new app->data structure, preserving the existing discovery block so it is
        // carried forward even if discovery is skipped this poll.
        $this->newData = [
            'discovery' => $this->app->data['discovery'] ?? null,
            'filesystems' => [],
        ];
        $this->aggIoStatusMax = null;
        $this->aggScrubStatusMax = null;
        $this->aggBalanceStatusMax = null;

        // Run discovery if due (detects topology changes and syncs sensor rows to DB).
        $this->runDiscovery();

        // Reset per-poll accumulators and iterate over every filesystem discovered.
        $this->working = [
            'acc' => ['metrics' => []],
            'FSdata' => $this->appPayload['data']['tables'] ?? [],
            'fs' => [],
        ]; 
        foreach (array_keys($this->app->data['discovery']['filesystems'] ?? []) as $fsUuid) {
            $this->processFilesystem($fsUuid);
        }

        // Compute overall app status, assemble the complete app->data, write RRD, and persist.
        $this->persistAppData($fetchResult);

        // Log RRD call statistics for this poll cycle.
        BtrfsRrdWriter::printCallCounters();
    }

    /**
     * Instantiate all service objects used during polling.
     */
    private function initialize(): void
    {
        $this->parser = new BtrfsPayloadParser();
        $this->mapper = new BtrfsStatusMapper();
        $this->sensorSync = new BtrfsSensorSync();
        $this->rrdWriter = new BtrfsRrdWriter();
        $this->discovery = new BtrfsDiscovery($this->parser, $this->sensorSync);
    }

    /**
     * Fetch and validate the agent JSON payload.
     *
     * Returns the btrfs agent protocol version (int >= 1) on success.
     *
     * @param  array &$agentOutput  Populated with the 'data' section of the payload.
     * @param  array &$oldData      Populated with the previous app->data for reference.
     * @throws JsonAppException    If payload retrieval/parsing fails or payload version is unsupported.
     */
    private function fetchPayload(array &$agentOutput, array &$oldData): int
    {
        // Initialise output parameters so they are always defined on failure paths.
        $agentOutput = [];
        $oldData = [];

        // Attempt to retrieve and parse the JSON payload from the btrfs agent.
        try {
            $this->appPayload = json_app_get($this->device, 'btrfs', 1);

            // json_app_get() returns null when no data is available (no exception thrown).
            if ($this->appPayload === null) {
                throw new JsonAppException('No data received from agent', -1);
            }

            $agentOutput = $this->appPayload['data'] ?? [];
            $oldData = $this->app->data ?? [];
        } catch (JsonAppException $e) {
            // Agent returned no data or the payload was malformed; mark app as unavailable.
            echo PHP_EOL . 'btrfs:' . $e->getCode() . ':' . $e->getMessage() . PHP_EOL;
            update_application($this->app, $e->getCode() . ':' . $e->getMessage(), []);

            throw $e;
        }

        // Determine the btrfs agent protocol version from the payload.
        $tables = $agentOutput['tables'] ?? [];
        $fsList = $tables['FS'] ?? [];
        $btrfsDevVersion = (int) ($agentOutput['version'] ?? $this->appPayload['version'] ?? 0);

        // Treat a version-0 payload with filesystem rows as a valid protocol v1 agent.
        if ($btrfsDevVersion < 1 && count($fsList) > 0) {
            $btrfsDevVersion = 1;
        }

        // Reject payloads that report no version and contain no filesystem data.
        if ($btrfsDevVersion < 1) {
            $this->sensorSync->deleteAllStateAndCountSensors($this->device);
            $this->app->data = [];
            update_application($this->app, 'Unsupported btrfs agent payload version', ['status_code' => BtrfsSensorSync::STATUS_NA]);

            throw new JsonAppException('Unsupported btrfs agent payload version', -1);
        }

        return $btrfsDevVersion;
    }

    /**
     * Decide whether discovery is due and run it if so.
     *
     * Discovery runs when:
     * - This is the first poll (no cached discovery state)
     * - The filesystem or device count in the payload has increased
     * - The periodic interval (DISCOVERY_INTERVAL polls) has elapsed
     *
     * Discovery syncs sensor rows to the DB (creates new, updates existing, deletes removed).
     * The polling loop then updates sensor_current and writes RRDs every poll regardless.
     */
    private function runDiscovery(): void
    {
        $tables = $this->appPayload['data']['tables'] ?? [];

        // Load or seed the discovery state and bump the poll-count (polls since last discovery).
        $discovery = $this->discovery->initDiscoveryState($this->app);
        $discovery['poll_count'] = ($discovery['poll_count'] ?? 0) + 1;
        $pollCount = $discovery['poll_count'];

        // Extract authoritative filesystem/device/backing-device counts from the agent payload.
        $currentCounters = $this->discovery->extractCounters($this->appPayload['data'], $tables);

        App_log::debug('Discovery state loaded', [
            'current_fs_count' => $currentCounters['filesystems'],
            'previous_fs_count' => count($discovery['filesystems'] ?? []),
        ]);

        // Decide whether to run discovery this poll, or skip and persist the incremented poll_count.
        if ($this->discovery->shouldSkip($currentCounters, $discovery, $pollCount, self::DISCOVERY_INTERVAL)) {
            App_log::info('Discovery skipped - not yet due', [
                'polls_since_last_discovery' => $pollCount,
                'interval' => self::DISCOVERY_INTERVAL,
            ]);
            // Carry the bumped count forward even when skipping so the interval timer advances.
            $this->discovery->saveDiscoveryData($this->app, $discovery);
        } else {
            // Reset poll_count before discovery so it persists as 0 (polls since last discovery ran).
            $discovery['poll_count'] = 0;
            $this->discovery->discover($this->device, $this->app, $tables, $discovery, $currentCounters);
        }

        // Carry the discovery block into the new app->data (preserved even when discovery was skipped).
        $this->newData['discovery'] = $this->app->data['discovery'] ?? $discovery;

    }

    /**
     * Process a single filesystem: extract space/scrub/balance data, write RRDs,
     * upsert sensors (when structure has changed), process per-device data, and
     * build the filesystem entry for app->data persistence.
     */
    private function processFilesystem(string $fsUuid): void
    {
        $cachedFs = $this->app->data['discovery']['filesystems'][$fsUuid] ?? [];
        $this->working['fs'] = [
            'uuid' => $fsUuid,
            'rrd_id' => $cachedFs['rrd_key'] ?? '',
            'name' => $cachedFs['mountpoint'] ?? '',
            'input' => [],
            'acc' => [],
            'status' => [],
            'output' => [],
            'display_name' => $cachedFs['display_name'] ?? '',
        ];
        $rrdId = (string) ($this->working['fs']['rrd_id'] ?? '');
        $mountpoint = (string) ($this->working['fs']['name'] ?? $fsUuid);

        // If this discovered filesystem is absent from payload, keep previous fs data,
        // mark it as "missing filesystem", force health/status sensor values, and return.
        if (! isset($this->working['FSdata']['FS'][$fsUuid])) {
            if (! ($this->newData['discovery']['filesystems'][$fsUuid]['payload_missing'] ?? false)) {
                App_log::error('Missing filesystem in payload', [
                    'fs_uuid' => $fsUuid,
                    'mountpoint' => $mountpoint,
                ]);
                $this->logApplicationEvent('Missing filesystem in payload: ' . $mountpoint . ' (' . $fsUuid . ')', Severity::Error);
            }

            $this->newData['discovery']['filesystems'][$fsUuid]['payload_missing'] = true;

            $ioStatusCode = -1;
            $scrubStatusCode = -1;
            $balanceStatusCode = -1;
            $scrubOperation = -1;
            $balanceOperation = -1;
            $fsScrubHealth = -1;
            $fsHealthCode = 3;

            $this->aggregateStatuses(BtrfsSensorSync::STATUS_MISSING, $scrubStatusCode, $balanceStatusCode);

            $existingFsData = $this->app->data['filesystems'][$fsUuid] ?? [];
            if (! is_array($existingFsData)) {
                $existingFsData = [];
            }
            // Copy previous per-filesystem data forward and only override status/health fields.
            $existingFsData['table'] = is_array($existingFsData['table'] ?? null) ? $existingFsData['table'] : [];
            $existingFsData['table'][BtrfsRrdWriter::DS_IO_STATUS] = $ioStatusCode;
            $existingFsData['table'][BtrfsRrdWriter::DS_SCRUB_STATUS] = $scrubStatusCode;
            $existingFsData['table'][BtrfsRrdWriter::DS_SCRUB_OPERATION] = $scrubOperation;
            $existingFsData['table'][BtrfsRrdWriter::DS_SCRUB_HEALTH] = $fsScrubHealth;
            $existingFsData['table'][BtrfsRrdWriter::DS_BALANCE_STATUS] = $balanceStatusCode;
            $existingFsData['missing_filesystem'] = true;
            $existingFsData['status_text'] = 'missing filesystem';
            $this->newData['filesystems'][$fsUuid] = $existingFsData;

            $sensorContext = [
                'status' => [
                    'health' => $fsHealthCode,
                    'scrub' => $scrubStatusCode,
                    'scrub_ops' => $scrubOperation,
                    'balance' => $balanceOperation,
                ],
                'counts' => [
                    'io_errors' => null,
                ],
                'devices' => [],
            ];
            $this->updateSensorsFromDiscoveryMap($fsUuid, $sensorContext);
            if ($rrdId !== '') {
                $fsMetricPrefix = 'fs_' . $rrdId . '_';
                $this->working['acc']['metrics'][$fsMetricPrefix . BtrfsRrdWriter::DS_IO_STATUS] = $ioStatusCode;
                $this->working['acc']['metrics'][$fsMetricPrefix . BtrfsRrdWriter::DS_SCRUB_STATUS] = $scrubStatusCode;
                $this->working['acc']['metrics'][$fsMetricPrefix . BtrfsRrdWriter::DS_SCRUB_OPERATION] = $scrubOperation;
                $this->working['acc']['metrics'][$fsMetricPrefix . BtrfsRrdWriter::DS_SCRUB_HEALTH] = $fsScrubHealth;
                $this->working['acc']['metrics'][$fsMetricPrefix . BtrfsRrdWriter::DS_BALANCE_STATUS] = $balanceStatusCode;
            }

            return;
        }

        $this->newData['discovery']['filesystems'][$fsUuid]['payload_missing'] = false;
        
        // Copy filesystem capacity and usage_ratio from payload and build the RRD field list.
        $overall = array_merge(
            $this->working['FSdata']['FS'][$fsUuid]['filesystem_capacity'] ?? [],
            $this->working['FSdata']['FS'][$fsUuid]['usage_ratio'] ?? []
        );
        $fields = [];
        foreach (BtrfsRrdWriter::FS_SPACE_DATASETS as $ds) {
            $fields[$ds] = $overall[$ds] ?? null;
        }

        // Build the metric prefix used for update_application() output (e.g. "fs_84294f69_device_size").
        $fsMetricPrefix = 'fs_' . $this->working['fs']['rrd_id'] . '_';

        // Extract per-filesystem tables that are used after device processing.
        $this->working['fs']['input']['usage_type_totals'] = $this->parser->extractUsageTypeTotals($this->working['FSdata'], $this->working['fs']['uuid']);

        // Retrieve the previous scrub state so this poll can detect counter/session resets.
        $previousScrubState = $this->app->data['filesystems'][$fsUuid]['scrub']['status'] ?? [];
        $fsPreviousProgress = is_numeric($previousScrubState['progress'] ?? null) ? (float) $previousScrubState['progress'] : null;
        $this->working['fs']['previous_progress'] = $fsPreviousProgress;

        // Normalise the raw scrub status from the agent, applying RAID5/6 fallback logic.
        $scrubNormalized = $this->normalizeStatus(
            $this->parser->extractScrubStatus($this->working['FSdata'], $this->working['fs']['uuid']),
            $fsPreviousProgress
        );
        $this->working['fs']['input']['scrub_normalized'] = $scrubNormalized;
        $this->working['fs']['scrub_status'] = $scrubNormalized['fs_scrub_status'];
        $scrubBytesScrubbed = $scrubNormalized['bytes_scrubbed'];
        $scrubStarted = $scrubNormalized['started'];
        $scrubProgress = $scrubNormalized['progress'];

        // Detect counter/session resets before writing the scrub bytes RRD.
        $scrubBytesForRrd = $this->bytesForRrd(
            $scrubBytesScrubbed,
            $scrubStarted,
            $this->app->data['filesystems'][$fsUuid]['scrub']['status'] ?? []
        );

        // Persist canonical scrub state keys so next poll can detect resets.
        $scrubStatusSnapshot = [
            'bytes' => $scrubBytesScrubbed,
            'scrub_started' => $scrubStarted,
            'progress' => $scrubProgress,
        ];

        // Extract and normalise the balance status for this filesystem.
        $fsBalanceStatus = $this->parser->extractBalanceStatus($this->working['FSdata'], $this->working['fs']['uuid']);
        $this->working['fs']['input']['balance_status'] = $fsBalanceStatus;
        $balanceStatusCode = $this->mapper->getBalanceStatusCodeFromFlat($fsBalanceStatus);

        // Process all devices: append per-device values into shared fs working state.
        $this->processDevices();

        $devResult = [
            'device_tables' => $this->working['fs']['acc']['device_tables'],
            'metrics' => $this->working['fs']['acc']['device_metrics'],
            'fs_io_errors_sum' => $this->working['fs']['acc']['total_io_errors'],
            'io_status_code' => $this->working['fs']['status']['io_status_code'],
            'scrub_status_code' => $this->working['fs']['status']['scrub_status_code'],
            'fs_scrub_health' => $this->working['fs']['acc']['fs_scrub_health'],
            'device_sensor_values' => $this->working['fs']['acc']['device_sensor_values'],
        ];

        // Unpack aggregated results from processDevices().
        $ioStatusCode = $devResult['io_status_code'];
        $scrubStatusCode = $devResult['scrub_status_code'];
        $fsScrubHealth = $devResult['fs_scrub_health'];

        // Copy accumulated per-device usage totals into filesystem fields and metrics.
        $usageTotals = $this->working['fs']['acc']['usage_totals'];
        foreach ($usageTotals as $k => $v) {
            $fields[$k] = $v;
            $this->working['acc']['metrics'][$fsMetricPrefix . $k] = $v;
        }

        // Add the scrub bytes (rate) to the fields and metrics.
        $fields[BtrfsRrdWriter::DS_SCRUB_BYTES] = $scrubBytesForRrd;
        $this->working['acc']['metrics'][$fsMetricPrefix . BtrfsRrdWriter::DS_SCRUB_BYTES] = $scrubBytesForRrd;

        // Write one RRD per RAID profile type (e.g. data_single, metadata_raid1) on this filesystem.
        foreach ($this->working['fs']['input']['usage_type_totals'] as $typeKey => $typeValue) {
            $typeId = $this->parser->normalizeId((string) $typeKey);
            $this->rrdWriter->writeTypeRrd($this->device, 'btrfs', $this->app->app_id, $this->working['fs']['rrd_id'], $typeId, $typeValue);
            $this->working['acc']['metrics'][$fsMetricPrefix . 'type_' . $typeId] = $typeValue;
        }

        $balanceOperation = ! empty($fsBalanceStatus['is_running']) ? 1 : 0;
        $scrubOperation = ! empty($this->working['fs']['scrub_status']['ops_status']) ? 1 : 0;
        $fsHealthCode = $this->working['fs']['has_missing'] ? 2 : ($ioStatusCode === BtrfsSensorSync::STATUS_ERROR ? 1 : 0);

        // Append IO/scrub/balance status codes to filesystem RRD fields.
        foreach ([
            BtrfsRrdWriter::DS_IO_STATUS => $ioStatusCode,
            BtrfsRrdWriter::DS_SCRUB_STATUS => $scrubStatusCode,
            BtrfsRrdWriter::DS_SCRUB_OPERATION => $scrubOperation,
            BtrfsRrdWriter::DS_SCRUB_HEALTH => $fsScrubHealth,
            BtrfsRrdWriter::DS_BALANCE_STATUS => $balanceStatusCode,
        ] as $dataset => $value) {
            $fields[$dataset] = $value;
        }

        // Feed the aggregator so persistAppData() can derive the overall application status.
        $this->aggregateStatuses($ioStatusCode, $scrubStatusCode, $balanceStatusCode);

        // Update all filesystem and device sensors from discovery path maps.
        $sensorContext = [
            'status' => [
                'health' => $fsHealthCode,
                'scrub' => $scrubStatusCode,
                'scrub_ops' => $scrubOperation,
                'balance' => $balanceOperation,
            ],
            'counts' => [
                'io_errors' => $devResult['fs_io_errors_sum'],
            ],
            'devices' => $devResult['device_sensor_values'],
        ];
        $this->updateSensorsFromDiscoveryMap($fsUuid, $sensorContext);

        // Write the consolidated filesystem-level RRD (all space/status fields in one file).
        $this->rrdWriter->writeFsRrd($this->device, 'btrfs', $this->app->app_id, $this->working['fs']['rrd_id'], $fields);

        // Mirror RRD fields into the update_application() metrics map.
        foreach ($fields as $field => $value) {
            $this->working['acc']['metrics'][$fsMetricPrefix . $field] = $value;
        }

        // Merge per-device metrics into the filesystem metrics map.
        foreach ($devResult['metrics'] as $k => $v) {
            $this->working['acc']['metrics'][$fsMetricPrefix . $k] = $v;
        }

        // Assemble and persist the complete per-filesystem poll data block.
        $this->newData['filesystems'][$fsUuid] = [
            'fs_bytes_used' => $this->working['FSdata']['FS'][$fsUuid]['filesystem']['bytes_used'] ?? null,
            'device_missing' => $overall['device_missing'] ?? 0,
            'table' => $fields,
            'device_tables' => $devResult['device_tables'],
            'profiles' => $this->working['fs']['input']['usage_type_totals'],
            'scrub' => [
                'status' => $scrubStatusSnapshot,
                'state' => $this->working['fs']['scrub_status'],
                'devices' => $this->working['fs']['acc']['scrub_devices_data'],
                'operation' => $scrubOperation,
                'health' => $fsScrubHealth,
            ],
            'balance' => [
                'status' => $fsBalanceStatus,
            ],
        ];

    }

    /**
     * Process all devices for a filesystem.
     *
     * For each device discovered in the payload, this method:
     * - Extracts and normalises device stats, usage, and scrub data
     * - Writes per-device RRDs (device file and per-RAID-profile files)
     * - Builds the device table row used by the UI
     * - Accumulates IO error counts for the filesystem
     * - Logs new errors to the eventlog
     * - Updates IO and scrub state sensor values (sensor rows created during discovery)
     * - Derives filesystem-level IO and scrub status codes
     *
     */
    private function processDevices(): void
    {
        $fsUuid = $this->working['fs']['uuid'];

        $this->working['fs']['has_missing'] = ! empty($this->working['FSdata']['FS'][$fsUuid]['filesystem']['missing']);

        $rawFsDevices = $this->working['FSdata']['FS'][$fsUuid]['filesystem_devices'] ?? [];
        $devicePathById = $this->app->data['discovery']['filesystems'][$fsUuid]['devices'] ?? [];

        // Output accumulators shared across helpers via working fs state.
        $this->working['fs']['acc']['device_tables'] = [];
        $this->working['fs']['acc']['device_metrics'] = [];
        $this->working['fs']['acc']['total_io_errors'] = 0.0;
        $this->working['fs']['acc']['device_sensor_values'] = [];
        $this->working['fs']['acc']['scrub_devices_data'] = [];
        $this->working['fs']['acc']['usage_totals'] = [
            'usage_device_size' => 0.0,
            'usage_unallocated' => 0.0,
            'usage_data' => 0.0,
            'usage_metadata' => 0.0,
            'usage_system' => 0.0,
        ];

        // Track scrub state across all devices to derive the filesystem-level scrub status.
        $this->working['fs']['acc']['has_scrub_data'] = false;
        $this->working['fs']['acc']['scrub_has_error'] = false;
        $this->working['fs']['acc']['scrub_is_running'] = (int) ($this->working['fs']['scrub_status']['ops_status'] ?? -1) === 1;
        $this->working['fs']['acc']['fs_scrub_health'] = 0;
        $this->working['fs']['acc']['io_has_error'] = false;
        $this->working['fs']['input']['usage_devices'] = [];
        $this->working['fs']['input']['raw_scrub_devices'] = $this->working['FSdata']['FS'][$fsUuid]['scrub_status_devices'] ?? [];

        $this->working['fs']['input']['device_path_by_id'] = [];
        foreach ($devicePathById as $devId => $devPath) {
            $devId = (string) $devId;
            if (! ctype_digit($devId)) {
                continue;
            }

            $this->working['fs']['input']['device_path_by_id'][$devId] = (string) $devPath;
        }
 
        // -------------------------------------------------------------------------
        // Per-device loop
        // -------------------------------------------------------------------------
        foreach (array_keys($this->working['fs']['input']['device_path_by_id']) as $devId) {
            $this->processDevice($devId);
        }

        // -------------------------------------------------------------------------
        // Derive filesystem-level status codes from per-device aggregates
        // -------------------------------------------------------------------------
        $hasDeviceData = ! empty($this->working['fs']['input']['device_path_by_id']);
        $ioHasError = $this->working['fs']['acc']['io_has_error'];
        $this->working['fs']['status']['io_status_code'] = $this->mapper->getIoStatusCode($hasDeviceData, $ioHasError, $this->working['fs']['has_missing']);
        $this->working['fs']['status']['scrub_status_code'] = $this->mapper->getScrubStatusCode(
            $this->working['fs']['acc']['has_scrub_data'],
            $this->working['fs']['acc']['scrub_has_error'],
            $this->working['fs']['acc']['scrub_is_running']
        );
    }

    private function processDevice(string $devId): void
    {
        $fsUuid = $this->working['fs']['uuid'];
        $devPath = $this->working['fs']['input']['device_path_by_id'][$devId] ?? null;
        if (! is_string($devPath) || $devPath === '') {
            return;
        }
 
        if ($this->working['FSdata']['FS'][$fsUuid]['filesystem_devices'][$devId]['missing'] ?? null) {
            $previousUsage = $this->app->data['filesystems'][$fsUuid]['device_tables'][$devId]['usage'] ?? [];
            $usageStats = [
                'device_size' => $previousUsage['size'] ?? null,
                'device_slack' => $previousUsage['slack'] ?? null,
                'unallocated' => $previousUsage['unallocated'] ?? null,
                'data_bytes' => (float) ($previousUsage['data'] ?? 0),
                'metadata_bytes' => (float) ($previousUsage['metadata'] ?? 0),
                'system_bytes' => (float) ($previousUsage['system'] ?? 0),
                'device_missing' => $this->working['FSdata']['FS'][$fsUuid]['filesystem_devices'][$devId]['device_missing'] ?? null,
                'backing_device_path' => null,
                'type_values' => is_array($this->app->data['filesystems'][$fsUuid]['device_tables'][$devId]['raid_profiles'] ?? null)
                    ? $this->app->data['filesystems'][$fsUuid]['device_tables'][$devId]['raid_profiles']
                    : [],
            ];
            $this->working['fs']['input']['usage_devices'][$devPath] = $usageStats;
            $this->working['fs']['has_missing'] = true;

            $rawDevice = $this->working['FSdata']['FS'][$fsUuid]['filesystem_devices'][$devId] ?? [];
            if (! is_array($rawDevice)) {
                $rawDevice = [];
            }
            $devStats = [
                'devid' => (int) $devId,
                'missing' => true,
                'corruption_errs' => $rawDevice['corruption_errs'] ?? null,
                'flush_io_errs' => $rawDevice['flush_io_errs'] ?? null,
                'generation_errs' => $rawDevice['generation_errs'] ?? null,
                'read_io_errs' => $rawDevice['read_io_errs'] ?? null,
                'write_io_errs' => $rawDevice['write_io_errs'] ?? null,
            ];
            $deviceNumericId = $devStats['devid'] ?? $devId;

            $this->working['fs']['acc']['usage_totals']['usage_device_size'] += (float) ($usageStats['device_size'] ?? 0);
            $this->working['fs']['acc']['usage_totals']['usage_unallocated'] += (float) ($usageStats['unallocated'] ?? 0);
            $this->working['fs']['acc']['usage_totals']['usage_data'] += (float) ($usageStats['data_bytes'] ?? 0);
            $this->working['fs']['acc']['usage_totals']['usage_metadata'] += (float) ($usageStats['metadata_bytes'] ?? 0);
            $this->working['fs']['acc']['usage_totals']['usage_system'] += (float) ($usageStats['system_bytes'] ?? 0);

            $hasDeviceError = $this->rrdWriter->hasDeviceError($devStats);
            $devIoStatusCode = $this->mapper->getDevIoStatusCode(count($devStats) > 0, $hasDeviceError, true);
            if ($hasDeviceError) {
                $this->working['fs']['acc']['io_has_error'] = true;
            }

            $this->working['fs']['acc']['device_tables'][$devId] = $this->rrdWriter->buildDeviceTableRow($devPath, $deviceNumericId, $devStats, $usageStats);
            $this->working['fs']['acc']['device_tables'][$devId]['io_status_code'] = $devIoStatusCode;
            $this->working['fs']['acc']['device_tables'][$devId]['scrub_status_code'] = BtrfsSensorSync::STATUS_NA;

            $ioErrs = (float) $this->rrdWriter->sumDeviceErrors($devStats);
            $this->working['fs']['acc']['device_sensor_values'][$devId] = [
                'status' => [
                    'health' => 2,
                    'scrub' => BtrfsSensorSync::STATUS_NA,
                ],
                'counts' => [
                    'io_errors' => $ioErrs,
                ],
            ];
            $this->working['fs']['acc']['total_io_errors'] += $ioErrs;

            return;
        }

        $rawScrubDevices =  $this->working['fs']['input']['raw_scrub_devices'] ?? []; 
        $rawDevice = $this->working['FSdata']['FS'][$fsUuid]['filesystem_devices'][$devId] ?? [];
        if (! is_array($rawDevice)) {
            $rawDevice = [];
        }

        $devStats = [
            'devid' => (int) $devId,
            'missing' => ! empty($rawDevice['missing']),
            'corruption_errs' => $rawDevice['corruption_errs'] ?? null,
            'flush_io_errs' => $rawDevice['flush_io_errs'] ?? null,
            'generation_errs' => $rawDevice['generation_errs'] ?? null,
            'read_io_errs' => $rawDevice['read_io_errs'] ?? null,
            'write_io_errs' => $rawDevice['write_io_errs'] ?? null,
        ];
 
        $usageStats = [
            'device_size' => $rawDevice['size'] ?? null,
            'device_slack' => $rawDevice['slack'] ?? 0,
            'unallocated' => $rawDevice['unallocated'] ?? null,
            'data_bytes' => (float) ($rawDevice['data'] ?? 0),
            'metadata_bytes' => (float) ($rawDevice['metadata'] ?? 0),
            'system_bytes' => (float) ($rawDevice['system'] ?? 0),
            'device_missing' => $rawDevice['device_missing'] ?? null,
            'backing_device_path' => $rawDevice['backing_device_path'] ?? null,
            'type_values' => [],
        ];
        if (is_array($rawDevice['profiles'] ?? null)) {
            foreach ($rawDevice['profiles'] as $profileEntry) {
                if (is_array($profileEntry) && is_string($profileEntry['profile'] ?? null) && is_numeric($profileEntry['bytes'] ?? null)) {
                    $usageStats['type_values'][$profileEntry['profile']] = $profileEntry['bytes'];
                }
            }
        } elseif (is_array($rawDevice['raid_profiles'] ?? null)) {
            foreach ($rawDevice['raid_profiles'] as $profileEntry) {
                if (is_array($profileEntry) && is_string($profileEntry['profile'] ?? null) && is_numeric($profileEntry['bytes'] ?? null)) {
                    $usageStats['type_values'][$profileEntry['profile']] = $profileEntry['bytes'];
                }
            }
        }
        $this->working['fs']['input']['usage_devices'][$devPath] = $usageStats;
        $this->working['fs']['acc']['usage_totals']['usage_device_size'] += (float) ($usageStats['device_size'] ?? 0);
        $this->working['fs']['acc']['usage_totals']['usage_unallocated'] += (float) ($usageStats['unallocated'] ?? 0);
        $this->working['fs']['acc']['usage_totals']['usage_data'] += (float) ($usageStats['data_bytes'] ?? 0);
        $this->working['fs']['acc']['usage_totals']['usage_metadata'] += (float) ($usageStats['metadata_bytes'] ?? 0);
        $this->working['fs']['acc']['usage_totals']['usage_system'] += (float) ($usageStats['system_bytes'] ?? 0);
        $devStats['missing'] = (bool) ($devStats['missing'] ?? false);
        $deviceNumericId = $devStats['devid'] ?? $devId;
        $scrubData = $rawScrubDevices[$devId] ?? null;

        if (is_array($scrubData)) {
            $processedScrubData = $this->parser->processSingleDeviceScrub($scrubData, $this->working['fs']['previous_progress']);
            $this->working['fs']['acc']['scrub_devices_data'][$devId] = array_merge($scrubData, [
                'ops_status' => $processedScrubData['ops'],
                'health' => $processedScrubData['health'],
            ]);
        }

        // No scrub data for this device: write the table row with no scrub status and continue.
        if ($scrubData === null || ! is_array($scrubData)) {
            $hasDeviceError = $this->rrdWriter->hasDeviceError($devStats);
            $devIoStatusCode = $this->mapper->getDevIoStatusCode(
                count($devStats) > 0,
                $hasDeviceError,
                $devStats['missing']
            );
            if ($hasDeviceError) {
                $this->working['fs']['acc']['io_has_error'] = true;
            }
            
            $this->working['fs']['acc']['device_tables'][$devId] = $this->rrdWriter->buildDeviceTableRow($devPath, $deviceNumericId, $devStats, $usageStats);
            $this->working['fs']['acc']['device_tables'][$devId]['io_status_code'] = $devIoStatusCode;
            $this->working['fs']['acc']['device_tables'][$devId]['scrub_status_code'] = BtrfsSensorSync::STATUS_NA;

            $ioErrs = (float) $this->rrdWriter->sumDeviceErrors($devStats);
            $this->working['fs']['acc']['device_sensor_values'][$devId] = [
                'status' => [
                    'health' => ! empty($devStats['missing']) ? 2 : ($devIoStatusCode === BtrfsSensorSync::STATUS_ERROR ? 1 : 0),
                    'scrub' => BtrfsSensorSync::STATUS_NA,
                ],
                'counts' => [
                    'io_errors' => $ioErrs,
                ],
            ];

            $this->working['fs']['acc']['total_io_errors'] += $ioErrs;

            return;
        }

        // Process the raw scrub data into derived fields (running, health, bytes, progress).
        $processedScrub = $this->parser->processSingleDeviceScrub($scrubData, $this->working['fs']['previous_progress']);
        $this->working['fs']['acc']['has_scrub_data'] = true;

        // Track the worst health and running state across all devices for the fs-level status.
        if ($processedScrub['running'] === true) {
            $this->working['fs']['acc']['scrub_is_running'] = true;
        }
        if ($processedScrub['health'] > $this->working['fs']['acc']['fs_scrub_health']) {
            $this->working['fs']['acc']['fs_scrub_health'] = $processedScrub['health'];
        }
        if ($processedScrub['health'] === 2) {
            $this->working['fs']['acc']['scrub_has_error'] = true;
        }

        // Build per-device RRD fields and write the device RRD file.
        $devFields = $this->rrdWriter->buildDeviceFields(
            $devStats,
            $processedScrub['data'],
            $usageStats,
            $processedScrub['ops'],
            $processedScrub['health']
        );
        $this->rrdWriter->writeDeviceRrd($this->device, 'btrfs', $this->app->app_id, $this->working['fs']['rrd_id'], $devId, $devFields);

        // Write per-device per-RAID-profile RRDs (one file per type on this device).
        $devTypeValues = $usageStats['type_values'] ?? [];
        if (is_array($devTypeValues)) {
            foreach ($devTypeValues as $typeKey => $typeValue) {
                if (! is_numeric($typeValue)) {
                    continue;
                }
                $typeId = $this->parser->normalizeId((string) $typeKey);
                $this->rrdWriter->writeDevTypeRrd($this->device, 'btrfs', $this->app->app_id, $this->working['fs']['rrd_id'], $devId, $typeId, $typeValue);
            }
        }

        // Build the device table row for the UI.
        $this->working['fs']['acc']['device_tables'][$devId] = $this->rrdWriter->buildDeviceTableRow($devPath, $deviceNumericId, $devStats, $usageStats);

        // Sum IO errors and log a one-time eventlog entry when errors first appear.
        $ioErrs = $this->rrdWriter->sumDeviceErrors($devStats);
        if ($this->rrdWriter->hasDeviceError($devStats)) {
            $this->working['fs']['acc']['io_has_error'] = true;
        }
        if ($ioErrs > 0) {
            $this->logApplicationEvent('Device errors detected on ' . $this->working['fs']['name'] . ' (' . $devPath . ')', Severity::Error);
        }
        $this->working['fs']['acc']['total_io_errors'] += (float) $ioErrs;

        // Publish device RRD field values into the per-filesystem metrics map.
        $devMetricPrefix = 'device_' . $devId . '_';
        foreach ($devFields as $field => $value) {
            $this->working['fs']['acc']['device_metrics'][$devMetricPrefix . $field] = $value;
        }

        // Derive per-device IO and scrub status codes for the table row and sensors.
        $devIoStatusCode = $this->mapper->getDevIoStatusCode(
            count($devStats) > 0,
            $this->rrdWriter->hasDeviceError($devStats),
            $devStats['missing']
        );
        $devScrubStatusCode = $this->mapper->getDevScrubStatusCode(
            true,
            $processedScrub['health'] > 0
        );

        $this->working['fs']['acc']['device_tables'][$devId]['io_status_code'] = $devIoStatusCode;
        $this->working['fs']['acc']['device_tables'][$devId]['scrub_status_code'] = $devScrubStatusCode;

        $devHealthCode = ! empty($devStats['missing']) ? 2 : ($devIoStatusCode === BtrfsSensorSync::STATUS_ERROR ? 1 : 0);
        $this->working['fs']['acc']['device_sensor_values'][$devId] = [
            'status' => [
                'health' => $devHealthCode,
                'scrub' => $devScrubStatusCode,
            ],
            'counts' => [
                'io_errors' => (float) $ioErrs,
            ],
        ];
    }

    /**
     * Derive the overall app status, assemble the full app->data structure,
     * and call update_application() to persist metrics and status.
     */
    private function persistAppData(int $btrfsDevVersion): void
    {
        // Derive app-level state from max status codes seen across filesystems.
        // Status ordering assumed by max() aggregation:
        //   -1 Unknown, 0 OK, 1 Running, 2 N/A, 3 Error, 4 Missing.
        $hasData = $this->aggIoStatusMax !== null || $this->aggScrubStatusMax !== null || $this->aggBalanceStatusMax !== null;
        $hasMissing = $this->aggIoStatusMax === BtrfsSensorSync::STATUS_MISSING
            || $this->aggScrubStatusMax === BtrfsSensorSync::STATUS_MISSING
            || $this->aggBalanceStatusMax === BtrfsSensorSync::STATUS_MISSING;
        $hasError = $hasMissing
            || $this->aggIoStatusMax === BtrfsSensorSync::STATUS_ERROR
            || $this->aggScrubStatusMax === BtrfsSensorSync::STATUS_ERROR
            || $this->aggBalanceStatusMax === BtrfsSensorSync::STATUS_ERROR;
        $hasRunning = $this->aggScrubStatusMax === BtrfsSensorSync::STATUS_RUNNING
            || $this->aggBalanceStatusMax === BtrfsSensorSync::STATUS_RUNNING;

        $appStatusCode = $this->mapper->deriveAppStatusCode(
            $hasMissing,
            $hasError,
            $hasRunning,
            $hasData
        );
        $this->working['acc']['metrics']['status_code'] = $appStatusCode;
        $appStatusText = $this->mapper->getStatusText($appStatusCode);

        $agentData = $this->appPayload['data'] ?? [];

        // Persist the top-level app fields.
        $this->newData['schema_version'] = 7;
        $this->newData['status_code'] = $appStatusCode;
        $this->newData['status_text'] = self::truncate($appStatusText, self::MAX_STATUS_TEXT_LENGTH);
        $this->newData['btrfs_dev_version'] = $btrfsDevVersion;
        $this->newData['version'] = $agentData['version'] ?? ($this->appPayload['version'] ?? null);

        // Write the complete app->data to the DB before update_application() (update_application
        // may re-read $this->app->data internally).
        $this->app->data = $this->newData;

        // Persist metrics to RRD and update the application row in the DB with the computed
        // overall status text and all collected metric values.
        update_application($this->app, $appStatusText, $this->working['acc']['metrics']);
    }

    private function aggregateStatuses(int $ioStatusCode, int $scrubStatusCode, int $balanceStatusCode): void
    {
        // Max-based category aggregation across all processed filesystems.
        $this->aggIoStatusMax = $this->aggIoStatusMax === null ? $ioStatusCode : max($this->aggIoStatusMax, $ioStatusCode);
        $this->aggScrubStatusMax = $this->aggScrubStatusMax === null ? $scrubStatusCode : max($this->aggScrubStatusMax, $scrubStatusCode);
        $this->aggBalanceStatusMax = $this->aggBalanceStatusMax === null ? $balanceStatusCode : max($this->aggBalanceStatusMax, $balanceStatusCode);
    }

    /**
     * Update discovered state and count sensors for one filesystem from path-based values.
     */
    private function updateSensorsFromDiscoveryMap(string $fsUuid, array $context): void
    {
        $maps = $this->app->data['discovery']['sensor_paths']['filesystems'][$fsUuid] ?? [];
        $stateMap = $maps['state'] ?? [];
        $countMap = $maps['count'] ?? [];

        if (empty($stateMap) && empty($countMap)) {
            return;
        }

        $sensorIndexes = array_values(array_unique(array_merge(array_keys($stateMap), array_keys($countMap))));
        $sensors = Sensor::withoutGlobalScopes()
            ->where('device_id', $this->device['device_id'])
            ->where('poller_type', 'agent')
            ->whereIn('sensor_index', $sensorIndexes)
            ->get()
            ->keyBy('sensor_index');

        foreach ($stateMap as $sensorIndex => $path) {
            $sensor = $sensors->get($sensorIndex);
            if (! $sensor instanceof Sensor) {
                continue;
            }

            $value = $this->valueByPath($context, $path);
            if (! is_numeric($value)) {
                continue;
            }

            $this->sensorSync->writeStateSensorRrd(
                $this->device,
                $sensorIndex,
                (string) $sensor->sensor_type,
                (string) $sensor->sensor_descr,
                (int) $value
            );
        }

        foreach ($countMap as $sensorIndex => $path) {
            $sensor = $sensors->get($sensorIndex);
            if (! $sensor instanceof Sensor) {
                continue;
            }

            $value = $this->valueByPath($context, $path);
            if (! is_numeric($value)) {
                continue;
            }

            $this->sensorSync->updateCountSensorValue(
                $this->device,
                $sensorIndex,
                (string) $sensor->sensor_descr,
                (float) $value
            );
        }
    }

    /**
     * Resolve a dotted path (a.b.c) in nested arrays.
     */
    private function valueByPath(array $data, string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function logApplicationEvent(string $message, Severity $severity = Severity::Info): void
    {
        Eventlog::log('BtrFS ' . $message, $this->device['device_id'], 'application', $severity);
    }

    /**
     * Truncate a string to at most $maxLength multibyte characters. Returns null unchanged.
     */
    private static function truncate(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            return mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    /**
     * Normalize raw scrub status from the agent payload.
     *
     * Handles RAID5/6 edge cases where non-active mirror devices report null status:
     * if the previous progress was at or above the done threshold the scrub is assumed
     * finished. Also fills in progress_percent=100 when status is 'finished' but the
     * field is missing, and skips a null bytes_scrubbed so the last known value is kept.
     *
     * @return array{fs_scrub_status: array, bytes_scrubbed: ?float, started: ?string, progress: ?float}
     */
    private function normalizeStatus(array $rawStatus, ?float $previousProgress): array
    {
        $scrubBytesScrubbed = null;
        $scrubStarted = null;
        $scrubProgress = null;

        // Handle Raid5/6 non-active devices with null status
        // If status is null but old progress >= threshold, assume finished
        if (($rawStatus['status'] ?? null) === null) {
            if ($previousProgress !== null && $previousProgress >= self::SCRUB_ASSUMED_DONE_THRESHOLD) {
                $rawStatus['status'] = 'finished';
                $rawStatus['progress_percent'] = '100';
                $scrubProgress = 100.0;
            }
        }

        // Set progress_percent to 100 if null and status is finished
        if (array_key_exists('progress_percent', $rawStatus) && $rawStatus['progress_percent'] === null) {
            if (strtolower(trim($rawStatus['status'] ?? '')) === 'finished') {
                $rawStatus['progress_percent'] = '100';
            }
        }

        // Only update bytes_scrubbed when the agent provides a non-null numeric value;
        // a null value means "no data this poll", so the previous value is preserved by
        // bytesForRrd() returning null (skipping the RRD write).
        if (array_key_exists('bytes_scrubbed', $rawStatus)
            && $rawStatus['bytes_scrubbed'] !== null
            && is_numeric($rawStatus['bytes_scrubbed'])
        ) {
            $scrubBytesScrubbed = (float) $rawStatus['bytes_scrubbed'];
        }

        $scrubStartedRaw = $rawStatus['scrub_started'] ?? null;
        if (is_numeric($scrubStartedRaw)) {
            $scrubStarted = (int) $scrubStartedRaw;
        }

        // Get current progress
        if ($scrubProgress === null && is_numeric($rawStatus['progress_percent'] ?? null)) {
            $scrubProgress = (float) $rawStatus['progress_percent'];
        }

        return [
            'fs_scrub_status' => $rawStatus,
            'bytes_scrubbed' => $scrubBytesScrubbed,
            'started' => $scrubStarted,
            'progress' => $scrubProgress,
        ];
    }

    /**
     * Detect a scrub counter or session reset and return the safe bytes value for the RRD.
     *
     * RRD DERIVE datasets record the *rate of change* of a counter. If the counter resets
     * (btrfs scrub restarts) the bytes value drops to 0, producing a large negative spike
     * in the RRD. This method detects resets by comparing the new value against the last
     * known state persisted in app->data and returns null when a reset is detected, signalling
     * the RRD writer to skip this sample.
     *
     * Two reset patterns are detected:
     * - Counter reset: bytes decreased within the same scrub session (unexpected, should not happen
     *   but is handled for safety)
     * - Session reset: a new scrub session started ($started changed) and the byte counter
     *   restarted from a lower value
     *
     * @param  float|null $bytes    Current bytes_scrubbed from the agent payload.
     * @param  int|null   $started  Current scrub_started timestamp from the agent payload.
     * @param  array      $oldState Previous scrub status block persisted in app->data.
     * @return float|null           Safe value for the RRD, or null if a reset was detected.
     */
    private function bytesForRrd(?float $bytes, ?int $started, array $oldState): ?float
    {
        $bytesForRrd = $bytes;

        // Recover the previous state values persisted by the previous poll.
        $previousBytes = is_numeric($oldState['bytes'] ?? null)
            ? (float) $oldState['bytes']
            : null;
        $previousStarted = is_numeric($oldState['scrub_started'] ?? null)
            ? (int) $oldState['scrub_started']
            : null;

        if ($bytesForRrd !== null && $previousBytes !== null) {
            // Counter reset: bytes went backwards within the same scrub session.
            $counterReset = $bytesForRrd < $previousBytes;

            // Session reset: a new scrub started ($started changed) and the byte counter restarted
            // from a lower value than before.
            $sessionReset = $started !== null
                && $previousStarted !== null
                && $started !== $previousStarted
                && $bytesForRrd <= $previousBytes;

            // Return null to tell the RRD writer to skip this sample rather than record a spike.
            if ($counterReset || $sessionReset) {
                $bytesForRrd = null;
            }
        }

        return $bytesForRrd;
    }
}

// =============================================================================
// Entry point shim
// =============================================================================

function btrfs_poll_app(array $device, Application $app): void
{
    (new BtrfsPoller())->poll($device, $app);
}
