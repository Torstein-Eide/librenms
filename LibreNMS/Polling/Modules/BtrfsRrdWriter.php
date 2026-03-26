<?php

namespace LibreNMS\Polling\Modules;

use LibreNMS\RRD\RrdDefinition;

/**
 * BtrfsRrdWriter handles RRD definitions and write operations for btrfs metrics.
 *
 * RRD structure:
 * - app-btrfs-{app_id}-{fs_name}: filesystem-level space/usage/status metrics
 * - app-btrfs-{app_id}-{fs_name}-device_{devid}: per-device IO/scrub/usage
 * - app-btrfs-{app_id}-{fs_name}-type_{type_id}: dynamic type series (e.g., raid profiles)
 * - app-btrfs-{app_id}-{fs_name}-device_{devid}-type_{type_id}: per-device dynamic types
 * - sensor-btrfs-{sensor_index}: synthetic sensor values
 */
class BtrfsRrdWriter
{
    public const DS_IO_STATUS = 'io_status_code';
    public const DS_SCRUB_STATUS = 'scrub_status_code';
    public const DS_BALANCE_STATUS = 'balance_status_code';
    public const DS_SCRUB_BYTES = 'scrub_bytes_scrubbe';

    public array $fsSpaceDatasets;
    public RrdDefinition $fsRrdDef;
    public RrdDefinition $deviceRrdDef;
    public RrdDefinition $dynamicTypeRrdDef;
    public array $ioErrorKeys;
    public array $scrubErrorKeys;

    public function __construct()
    {
        $this->fsSpaceDatasets = [
            'device_size' => 'device_size_bytes',
            'device_allocated' => 'device_allocated_bytes',
            'device_unallocated' => 'device_unallocated_bytes',
            'used' => 'used_bytes',
            'free_estimated' => 'free_estimated_bytes',
            'free_estimated_min' => 'free_estimated_min_bytes',
            'free_statfs_df' => 'free_statfs_df_bytes',
            'global_reserve' => 'global_reserve_bytes',
            'global_reserve_used' => 'global_reserve_used_bytes',
            'device_missing' => 'device_missing_bytes',
            'device_slack' => 'device_slack_bytes',
            'data_ratio' => 'data_ratio',
            'metadata_ratio' => 'metadata_ratio',
        ];

        $this->fsRrdDef = RrdDefinition::make();
        foreach ($this->fsSpaceDatasets as $ds => $key) {
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
            ->addDataset(self::DS_BALANCE_STATUS, 'GAUGE', 0);

        $this->deviceRrdDef = RrdDefinition::make()
            ->addDataset('io_d_corruption', 'DERIVE', 0)
            ->addDataset('io_d_flush', 'DERIVE', 0)
            ->addDataset('io_d_generation', 'DERIVE', 0)
            ->addDataset('io_d_read', 'DERIVE', 0)
            ->addDataset('io_d_write', 'DERIVE', 0)
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
            ->addDataset('usage_size', 'GAUGE', 0)
            ->addDataset('usage_slack', 'GAUGE', 0)
            ->addDataset('usage_unallocated', 'GAUGE', 0)
            ->addDataset('usage_data', 'GAUGE', 0)
            ->addDataset('usage_metadata', 'GAUGE', 0)
            ->addDataset('usage_system', 'GAUGE', 0);

        $this->dynamicTypeRrdDef = RrdDefinition::make()
            ->addDataset('value', 'GAUGE', 0);

        $this->ioErrorKeys = ['corruption_errs', 'flush_io_errs', 'generation_errs', 'read_io_errs', 'write_io_errs'];
        $this->scrubErrorKeys = ['read_errors', 'csum_errors', 'verify_errors', 'uncorrectable_errors', 'unverified_errors', 'missing', 'device_missing'];
    }

    public function writeFsRrd(array $device, string $app_name, int $app_id, string $fs_rrd_id, array $fields, array $tags = []): void
    {
        $rrd_name = ['app', $app_name, $app_id, $fs_rrd_id];
        $default_tags = [
            'name' => $app_name,
            'app_id' => $app_id,
            'rrd_def' => $this->fsRrdDef,
            'rrd_name' => $rrd_name,
        ];
        $merged_tags = array_merge($default_tags, $tags);
        app('Datastore')->put($device, 'app', $merged_tags, $fields);
    }

    public function writeDeviceRrd(array $device, string $app_name, int $app_id, string $fs_rrd_id, string $dev_id, array $fields, array $tags = []): void
    {
        $rrd_name = ['app', $app_name, $app_id, $fs_rrd_id, 'device_' . $dev_id];
        $default_tags = [
            'name' => $app_name,
            'app_id' => $app_id,
            'rrd_def' => $this->deviceRrdDef,
            'rrd_name' => $rrd_name,
        ];
        $merged_tags = array_merge($default_tags, $tags);
        app('Datastore')->put($device, 'app', $merged_tags, $fields);
    }

    public function writeTypeRrd(array $device, string $app_name, int $app_id, string $fs_rrd_id, string $type_id, float $value, array $tags = []): void
    {
        $rrd_name = ['app', $app_name, $app_id, $fs_rrd_id, 'type_' . $type_id];
        $default_tags = [
            'name' => $app_name,
            'app_id' => $app_id,
            'rrd_def' => $this->dynamicTypeRrdDef,
            'rrd_name' => $rrd_name,
        ];
        $merged_tags = array_merge($default_tags, $tags);
        app('Datastore')->put($device, 'app', $merged_tags, ['value' => $value]);
    }

    public function writeDevTypeRrd(array $device, string $app_name, int $app_id, string $fs_rrd_id, string $dev_id, string $type_id, float $value, array $tags = []): void
    {
        $rrd_name = ['app', $app_name, $app_id, $fs_rrd_id, 'device_' . $dev_id, 'type_' . $type_id];
        $default_tags = [
            'name' => $app_name,
            'app_id' => $app_id,
            'rrd_def' => $this->dynamicTypeRrdDef,
            'rrd_name' => $rrd_name,
        ];
        $merged_tags = array_merge($default_tags, $tags);
        app('Datastore')->put($device, 'app', $merged_tags, ['value' => $value]);
    }

    public function sumDeviceErrors(array $dev_stats): float
    {
        $total = 0.0;
        foreach ($this->ioErrorKeys as $key) {
            $total += (float) ($dev_stats[$key] ?? 0);
        }

        return $total;
    }

    public function sumScrubErrors(array $scrub_stats): float
    {
        $total = 0.0;
        foreach ($this->scrubErrorKeys as $key) {
            $total += (float) ($scrub_stats[$key] ?? 0);
        }

        return $total;
    }

    public function hasDeviceError(array $dev_stats): bool
    {
        foreach ($this->ioErrorKeys as $key) {
            if (isset($dev_stats[$key]) && is_numeric($dev_stats[$key]) && (float) $dev_stats[$key] > 0) {
                return true;
            }
        }

        return false;
    }

    public function hasScrubError(array $scrub_stats): bool
    {
        foreach ($this->scrubErrorKeys as $key) {
            if (isset($scrub_stats[$key]) && is_numeric($scrub_stats[$key]) && (float) $scrub_stats[$key] > 0) {
                return true;
            }
        }

        return false;
    }

    public function buildDeviceFields(array $dev_stats, array $scrub_stats, array $usage_stats): array
    {
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

    public function sumUsageTotals(array $usage_devices): array
    {
        $totals = [
            'usage_device_size' => 0,
            'usage_unallocated' => 0,
            'usage_data' => 0,
            'usage_metadata' => 0,
            'usage_system' => 0,
        ];

        foreach ($usage_devices as $usage_stats) {
            $totals['usage_device_size'] += (float) ($usage_stats['device_size'] ?? 0);
            $totals['usage_unallocated'] += (float) ($usage_stats['unallocated'] ?? 0);
            $totals['usage_data'] += (float) ($usage_stats['data_bytes'] ?? 0);
            $totals['usage_metadata'] += (float) ($usage_stats['metadata_bytes'] ?? 0);
            $totals['usage_system'] += (float) ($usage_stats['system_bytes'] ?? 0);
        }

        return $totals;
    }
}
