<?php

use App\Models\Eventlog;
use LibreNMS\Enum\Severity;
use LibreNMS\Exceptions\JsonAppException;
use LibreNMS\RRD\RrdDefinition;

// Poller responsibilities for btrfs app data:
// - parse unix-agent JSON payload into stable table/metric structures
// - publish per-filesystem/per-device/app-level RRD datasets
// - maintain synthetic btrfs state sensors (IO/Scrub/Balance)
// - store compact app->data used by device/global btrfs pages
//
// High-level flow:
//
//   json_app_get() payload
//            |
//            v
//   define helpers + RRD/state metadata
//            |
//            v
//   ensure state indexes/translations (idempotent)
//            |
//            v
//   for each filesystem:
//     - normalize overall metrics
//     - build canonical structured app data
//     - compute IO/Scrub/Balance status codes
//     - write filesystem RRD
//     - upsert filesystem state sensors
//            |
//            v
//   for each device in filesystem:
//     - extract io/scrub/usage stats
//     - write device + dynamic type RRDs
//     - compute device status codes
//     - upsert device state sensors
//            |
//            v
//   aggregate app-level totals + overall RRD
//            |
//            v
//   persist compact app->data + update_application()
$name = 'btrfs';

$normalize_id = static function (string $value): string {
    // Keep IDs filename-safe and readable (v1 naming, no hash suffix).
    $id = preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
    $id = trim((string) $id, '_');

    return $id === '' ? 'id' : $id;
};

try {
    $all_return = json_app_get($device, $name, 1);
    $btrfs = $all_return['data'];
} catch (JsonAppException $e) {
    echo PHP_EOL . $name . ':' . $e->getCode() . ':' . $e->getMessage() . PHP_EOL;
    update_application($app, $e->getCode() . ':' . $e->getMessage(), []); // Set empty metrics and error message

    return;
}

$filesystems = $btrfs['filesystems'] ?? [];

$delete_all_btrfs_state_sensors = static function (array $device): void {
    $sensor_types = ['btrfsIoStatusState', 'btrfsScrubStatusState', 'btrfsBalanceStatusState'];

    dbDelete(
        'sensors_to_state_indexes',
        '`sensor_id` IN (SELECT `sensor_id` FROM `sensors` WHERE `device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` IN (?,?,?))',
        [$device['device_id'], 'state', 'agent', $sensor_types[0], $sensor_types[1], $sensor_types[2]]
    );
    dbDelete(
        'sensors',
        '`device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` IN (?,?,?)',
        [$device['device_id'], 'state', 'agent', $sensor_types[0], $sensor_types[1], $sensor_types[2]]
    );
};

$btrfs_dev_version_raw = $all_return['btrfs_dev_version'] ?? ($all_return['version'] ?? null);
$btrfs_dev_version = is_numeric($btrfs_dev_version_raw) ? (int) $btrfs_dev_version_raw : 0;
if ($btrfs_dev_version < 1) {
    $delete_all_btrfs_state_sensors($device);
    $app->data = [];
    update_application($app, 'Unsupported btrfs agent payload version', ['status_code' => 2]);

    return;
}

$fs_space_datasets = [
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

$fs_rrd_def = RrdDefinition::make();
foreach ($fs_space_datasets as $ds => $key) {
    $fs_rrd_def->addDataset($ds, 'GAUGE', 0);
}
$fs_rrd_def
    ->addDataset('usage_device_size', 'GAUGE', 0)
    ->addDataset('usage_unallocated', 'GAUGE', 0)
    ->addDataset('usage_data', 'GAUGE', 0)
    ->addDataset('usage_metadata', 'GAUGE', 0)
    ->addDataset('usage_system', 'GAUGE', 0)
    ->addDataset('io_status_code', 'GAUGE', 0)
    ->addDataset('scrub_status_code', 'GAUGE', 0)
    ->addDataset('balance_status_code', 'GAUGE', 0);

$get_balance_status_code = static function (array $fs): int {
    // Status code map: 0=OK, 1=Running, 2=N/A, 3=Error.
    // For balance, rc=0 indicates no active balance and maps to N/A.
    $command = $fs['commands']['balance_status'] ?? null;
    if (! is_array($command)) {
        return 2; // NA
    }

    if (is_numeric($command['rc'] ?? null) && (int) $command['rc'] === 0) {
        return 2; // NA
    }

    $data = $command['data'] ?? [];
    if (! is_array($data)) {
        return 2; // NA
    }

    $status = strtolower(trim((string) ($data['status'] ?? '')));
    if ($status === 'running') {
        return 1;
    }
    if (in_array($status, ['error', 'failed', 'failure'], true)) {
        return 3;
    }

    $lines = $data['lines'] ?? [];
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line_text = strtolower((string) $line);
            if (str_contains($line_text, 'no balance found')) {
                return 2;
            }
        }
    }

    if ($status !== '') {
        return 0;
    }

    $profiles = $data['profiles'] ?? [];

    return is_array($profiles) && count($profiles) > 0 ? 0 : 2;
};

$normalize_overall = static function (array $fs): array {
    // Normalize filesystem_usage.overall to stable field names consumed by
    // metrics, RRD writes, and page tables.
    $overall = $fs['commands']['filesystem_usage']['data']['overall'] ?? [];

    return [
        'device_size_bytes' => $overall['device_size'] ?? null,
        'device_allocated_bytes' => $overall['device_allocated'] ?? null,
        'device_unallocated_bytes' => $overall['device_unallocated'] ?? null,
        'used_bytes' => $overall['used'] ?? null,
        'free_estimated_bytes' => $overall['free_estimated']['bytes'] ?? null,
        'free_estimated_min_bytes' => $overall['free_estimated']['min_bytes'] ?? null,
        'free_statfs_df_bytes' => $overall['free_statfs_df'] ?? null,
        'global_reserve_bytes' => $overall['global_reserve']['bytes'] ?? null,
        'global_reserve_used_bytes' => $overall['global_reserve']['used_bytes'] ?? null,
        'device_missing_bytes' => $overall['device_missing'] ?? null,
        'device_slack_bytes' => $overall['device_slack'] ?? null,
        'data_ratio' => $overall['data_ratio'] ?? null,
        'metadata_ratio' => $overall['metadata_ratio'] ?? null,
    ];
};

$normalize_device_path = static function (?string $path): ?string {
    if (! is_string($path)) {
        return null;
    }

    $trimmed = trim($path);
    if ($trimmed === '') {
        return null;
    }

    if (str_contains(strtolower($trimmed), '<missing disk>')) {
        return '<missing disk>';
    }

    return $trimmed;
};

$missing_device_key = static function (string $devid): string {
    return '<missing disk #' . $devid . '>';
};

$extract_device_stats = static function (array $fs) use ($normalize_device_path, $missing_device_key): array {
    // Extract per-device IO error counters from devinfo only.
    // Current parser stores devinfo at filesystem root (`$fs['devinfo']`).
    // Keep a legacy fallback for older command-wrapped layouts.
    $devinfo = $fs['devinfo'] ?? ($fs['commands']['devinfo']['data']['devinfo'] ?? []);
    if (! is_array($devinfo)) {
        return [];
    }

    $path_by_devid = [];
    $show_devices = $fs['commands']['filesystem_show']['data']['devices'] ?? [];
    if (is_array($show_devices)) {
        foreach ($show_devices as $show_device) {
            if (! is_array($show_device)) {
                continue;
            }

            $show_devid = $show_device['devid'] ?? null;
            $show_path = $normalize_device_path($show_device['path'] ?? null);
            if (! is_scalar($show_devid) || (string) $show_devid === '' || ! is_string($show_path) || $show_path === '') {
                continue;
            }

            $show_devid = (string) $show_devid;
            if ($show_path === '<missing disk>') {
                $show_path = $missing_device_key($show_devid);
            }

            $path_by_devid[$show_devid] = $show_path;
        }
    }

    $devices = [];
    foreach ($devinfo as $devinfo_key => $entry) {
        if (! is_array($entry)) {
            continue;
        }

        $devid = (string) (is_scalar($devinfo_key) ? $devinfo_key : ($entry['id'] ?? ''));
        if ($devid === '') {
            continue;
        }

        $is_missing = ((int) ($entry['missing'] ?? 0) === 1);
        $path = $path_by_devid[$devid] ?? ($is_missing ? $missing_device_key($devid) : null);
        if (! is_string($path) || $path === '') {
            $path = 'devid-' . $devid;
        }

        $error_stats = $entry['error_stats'] ?? [];
        if (! is_array($error_stats)) {
            $error_stats = [];
        }

        $devices[$path] = [
            'devid' => $devid,
            'missing' => $is_missing,
            'corruption_errs' => $error_stats['corruption_errs'] ?? null,
            'flush_io_errs' => $error_stats['flush_errs'] ?? null,
            'generation_errs' => $error_stats['generation_errs'] ?? null,
            'read_io_errs' => $error_stats['read_errs'] ?? null,
            'write_io_errs' => $error_stats['write_errs'] ?? null,
        ];
    }

    return $devices;
};

$extract_scrub_device_stats = static function (array $fs) use ($normalize_device_path, $missing_device_key): array {
    // Extract scrub per-device counters from scrub_status_devices only.
    // This keeps scrub device semantics consistent across versions.
    $devices = [];
    $raw_devices = $fs['commands']['scrub_status_devices']['data']['devices'] ?? [];

    if (! is_array($raw_devices)) {
        return $devices;
    }

    foreach ($raw_devices as $key => $entry) {
        if (! is_array($entry)) {
            continue;
        }

        $devid = $entry['id'] ?? null;
        if (! is_scalar($devid) || (string) $devid === '') {
            if (is_scalar($key) && (string) $key !== '') {
                $devid = (string) $key;
            }
        }
        $devid = is_scalar($devid) ? (string) $devid : null;

        $path = $normalize_device_path($entry['path'] ?? null);
        if ($path === '<missing disk>' && is_string($devid) && $devid !== '') {
            $path = $missing_device_key($devid);
        }
        if (! is_string($path) || $path === '') {
            $path = $normalize_device_path(is_string($key) ? $key : null);
        }
        if ($path === '<missing disk>' && is_string($devid) && $devid !== '') {
            $path = $missing_device_key($devid);
        }
        if (! is_string($path) || $path === '') {
            continue;
        }

        $devices[$path] = [
            'path' => $entry['path'] ?? $path,
            'id' => $devid ?? ($entry['id'] ?? null),
            'section' => $entry['section'] ?? null,
            'has_status_suffix' => $entry['has_status_suffix'] ?? null,
            'has_stats' => $entry['has_stats'] ?? null,
            'no_stats_available' => $entry['no_stats_available'] ?? null,
            'scrub_started' => $entry['scrub_started'] ?? null,
            'status' => $entry['status'] ?? null,
            'duration' => $entry['duration'] ?? null,
            'data_extents_scrubbed' => $entry['data_extents_scrubbed'] ?? null,
            'tree_extents_scrubbed' => $entry['tree_extents_scrubbed'] ?? null,
            'data_bytes_scrubbed' => $entry['data_bytes_scrubbed'] ?? null,
            'tree_bytes_scrubbed' => $entry['tree_bytes_scrubbed'] ?? null,
            'read_errors' => $entry['read_errors'] ?? null,
            'csum_errors' => $entry['csum_errors'] ?? null,
            'verify_errors' => $entry['verify_errors'] ?? null,
            'no_csum' => $entry['no_csum'] ?? null,
            'csum_discards' => $entry['csum_discards'] ?? null,
            'super_errors' => $entry['super_errors'] ?? null,
            'malloc_errors' => $entry['malloc_errors'] ?? null,
            'uncorrectable_errors' => $entry['uncorrectable_errors'] ?? null,
            'unverified_errors' => $entry['unverified_errors'] ?? null,
            'corrected_errors' => $entry['corrected_errors'] ?? null,
            'missing' => $entry['missing'] ?? null,
            'device_missing' => $entry['device_missing'] ?? null,
            'last_physical' => $entry['last_physical'] ?? null,
        ];
    }

    return $devices;
};

$extract_device_usage = static function (array $fs) use ($normalize_device_path, $missing_device_key): array {
    // Build per-device usage totals and capture dynamic type buckets
    // (data_*, metadata_*, system_*) for dedicated RRD series.
    $entries = $fs['commands']['device_usage']['data']['devices'] ?? [];
    if (! is_array($entries)) {
        return [];
    }

    $devices = [];
    foreach ($entries as $entry) {
        if (! is_array($entry)) {
            continue;
        }

        $devid = $entry['id'] ?? null;
        $devid = is_scalar($devid) && (string) $devid !== '' ? (string) $devid : null;

        $path = $normalize_device_path($entry['path'] ?? null);
        if ($path === '<missing disk>' && is_string($devid) && $devid !== '') {
            $path = $missing_device_key($devid);
        }
        if (! is_string($path) || $path === '') {
            continue;
        }

        $data_bytes = 0;
        $metadata_bytes = 0;
        $system_bytes = 0;
        $type_values = [];
        foreach ($entry as $k => $v) {
            if (! is_string($k) || ! is_numeric($v)) {
                continue;
            }

            if (str_starts_with($k, 'data_')) {
                $data_bytes += $v;
                $type_values[$k] = ($type_values[$k] ?? 0) + $v;
            } elseif (str_starts_with($k, 'metadata_')) {
                $metadata_bytes += $v;
                $type_values[$k] = ($type_values[$k] ?? 0) + $v;
            } elseif (str_starts_with($k, 'system_')) {
                $system_bytes += $v;
                $type_values[$k] = ($type_values[$k] ?? 0) + $v;
            }
        }

        $devices[$path] = [
            'device_size' => $entry['device_size'] ?? null,
            'device_slack' => $entry['device_slack'] ?? null,
            'unallocated' => $entry['unallocated'] ?? null,
            'data_bytes' => $data_bytes,
            'metadata_bytes' => $metadata_bytes,
            'system_bytes' => $system_bytes,
            'type_values' => $type_values,
        ];
    }

    return $devices;
};

$extract_usage_type_totals = static function (array $fs): array {
    // Aggregate dynamic usage types across devices for filesystem-level graphs.
    $entries = $fs['commands']['device_usage']['data']['devices'] ?? [];
    if (! is_array($entries)) {
        return [];
    }

    $totals = [];
    foreach ($entries as $entry) {
        if (! is_array($entry)) {
            continue;
        }

        foreach ($entry as $k => $v) {
            if (! is_string($k) || ! is_numeric($v)) {
                continue;
            }

            if (str_starts_with($k, 'data_') || str_starts_with($k, 'metadata_') || str_starts_with($k, 'system_')) {
                $totals[$k] = ($totals[$k] ?? 0) + $v;
            }
        }
    }

    ksort($totals);

    return $totals;
};

$filesystem_has_missing_device = static function (array $fs): bool {
    $missing_bytes = $fs['commands']['filesystem_usage']['data']['overall']['device_missing'] ?? 0;
    if (is_numeric($missing_bytes) && (float) $missing_bytes > 0) {
        return true;
    }

    $devices = $fs['commands']['filesystem_show']['data']['devices'] ?? [];
    if (! is_array($devices)) {
        return false;
    }

    foreach ($devices as $device_entry) {
        if (! is_array($device_entry)) {
            continue;
        }

        $path = strtolower((string) ($device_entry['path'] ?? ''));
        if ($path !== '' && str_contains($path, '<missing disk>')) {
            return true;
        }
    }

    return false;
};

$extract_filesystem_uuid = static function (array $fs): string {
    // Preferred UUID source is filesystem_show.
    // Redundant fallbacks handle parser/output drift across btrfs versions.
    $candidates = [
        $fs['commands']['filesystem_show']['data']['uuid'] ?? null,
        $fs['commands']['scrub_status']['data']['uuid'] ?? null,
        $fs['commands']['scrub_status_devices']['data']['uuid'] ?? null,
        $fs['uuid'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (! is_string($candidate)) {
            continue;
        }

        $uuid = trim($candidate);
        if ($uuid !== '') {
            return $uuid;
        }
    }

    return '';
};

$device_rrd_def = RrdDefinition::make()
    ->addDataset('io_d_corruption', 'DERIVE', 0)
    ->addDataset('io_d_flush', 'DERIVE', 0)
    ->addDataset('io_d_generation', 'DERIVE', 0)
    ->addDataset('io_d_read', 'DERIVE', 0)
    ->addDataset('io_d_write', 'DERIVE', 0)
    ->addDataset('io_c_corruption', 'COUNTER', 0)
    ->addDataset('io_c_flush', 'COUNTER', 0)
    ->addDataset('io_c_generation', 'COUNTER', 0)
    ->addDataset('io_c_read', 'COUNTER', 0)
    ->addDataset('io_c_write', 'COUNTER', 0)
    ->addDataset('io_t_corruption', 'GAUGE', 0)
    ->addDataset('io_t_flush', 'GAUGE', 0)
    ->addDataset('io_t_generation', 'GAUGE', 0)
    ->addDataset('io_t_read', 'GAUGE', 0)
    ->addDataset('io_t_write', 'GAUGE', 0)
    ->addDataset('scrub_c_read', 'COUNTER', 0)
    ->addDataset('scrub_c_csum', 'COUNTER', 0)
    ->addDataset('scrub_c_verify', 'COUNTER', 0)
    ->addDataset('scrub_c_uncorrectable', 'COUNTER', 0)
    ->addDataset('scrub_c_unverified', 'COUNTER', 0)
    ->addDataset('scrub_c_corrected', 'COUNTER', 0)
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

$dynamic_type_rrd_def = RrdDefinition::make()
    ->addDataset('value', 'GAUGE', 0);

$overall_rrd_def = RrdDefinition::make()
    ->addDataset('used', 'GAUGE', 0)
    ->addDataset('free_estimated', 'GAUGE', 0)
    ->addDataset('device_size', 'GAUGE', 0)
    ->addDataset('io_errors_total', 'GAUGE', 0)
    ->addDataset('scrub_errors_total', 'GAUGE', 0)
    ->addDataset('status_code', 'GAUGE', 0)
    ->addDataset('io_status_code', 'GAUGE', 0)
    ->addDataset('scrub_status_code', 'GAUGE', 0)
    ->addDataset('balance_status_code', 'GAUGE', 0);

$io_error_keys = ['corruption_errs', 'flush_io_errs', 'generation_errs', 'read_io_errs', 'write_io_errs'];
$scrub_error_keys = ['read_errors', 'csum_errors', 'verify_errors', 'uncorrectable_errors', 'unverified_errors', 'missing', 'device_missing'];

$ensure_state_index = static function (string $state_name, array $states): ?int {
    // Ensure state index/translations exist for synthetic state sensors.
    // Writes are idempotent and only occur for missing/changed rows.
    $state_index_id = dbFetchCell('SELECT `state_index_id` FROM `state_indexes` WHERE `state_name` = ?', [$state_name]);
    $created_index = false;

    if (! $state_index_id) {
        $state_index_id = dbInsert(['state_name' => $state_name], 'state_indexes');
        $created_index = (bool) $state_index_id;
    }

    if (! $state_index_id) {
        return null;
    }

    // Idempotent sync to reduce DB churn:
    // 1) Read all existing translations for this state index once.
    // 2) Compare expected vs stored values in memory.
    // 3) Write only missing rows or rows with changed fields.
    // Unchanged rows are intentionally skipped.
    $existing_translations = dbFetchRows(
        'SELECT `state_translation_id`, `state_value`, `state_descr`, `state_draw_graph`, `state_generic_value` FROM `state_translations` WHERE `state_index_id` = ?',
        [$state_index_id]
    );
    $translations_by_value = [];
    foreach ($existing_translations as $row) {
        $translations_by_value[(int) $row['state_value']] = $row;
    }

    $created = 0;
    $updated = 0;

    foreach ($states as $state) {
        $state_value = (int) $state['value'];
        $existing = $translations_by_value[$state_value] ?? null;
        $translation = [
            'state_index_id' => $state_index_id,
            'state_descr' => $state['descr'],
            'state_draw_graph' => $state['graph'],
            'state_value' => $state_value,
            'state_generic_value' => $state['generic'],
        ];

        if ($existing) {
            $needs_update = (string) $existing['state_descr'] !== (string) $translation['state_descr']
                || (int) $existing['state_draw_graph'] !== (int) $translation['state_draw_graph']
                || (int) $existing['state_generic_value'] !== (int) $translation['state_generic_value'];

            if ($needs_update) {
                dbUpdate($translation, 'state_translations', '`state_translation_id` = ?', [(int) $existing['state_translation_id']]);
                $updated++;
            }
        } else {
            dbInsert($translation, 'state_translations');
            $created++;
        }
    }

    if ($created_index || $created > 0 || $updated > 0) {
        echo ' btrfs-state: ' . $state_name
            . ' index=' . (int) $state_index_id
            . ' created_index=' . ($created_index ? 'yes' : 'no')
            . ' created=' . $created
            . ' updated=' . $updated . PHP_EOL;
    }

    return (int) $state_index_id;
};

$status_states = [
    ['value' => 0, 'generic' => 0, 'graph' => 0, 'descr' => 'OK'],
    ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'Running'],
    ['value' => 2, 'generic' => 3, 'graph' => 0, 'descr' => 'N/A'],
    ['value' => 3, 'generic' => 2, 'graph' => 0, 'descr' => 'Error'],
    ['value' => 4, 'generic' => 2, 'graph' => 0, 'descr' => 'Missing'],
];

$io_state_index_id = $ensure_state_index('btrfsIoStatusState', $status_states);
$scrub_state_index_id = $ensure_state_index('btrfsScrubStatusState', $status_states);
$balance_state_index_id = $ensure_state_index('btrfsBalanceStatusState', $status_states);

$upsert_state_sensor = static function (array $device, string $sensor_index, string $sensor_type, string $sensor_descr, int $sensor_current, ?int $state_index_id, string $sensor_group): void {
    // Idempotent sensor upsert keyed by (device, class, poller_type, type, sensor_index).
    // This preserves sensor identity across polls so state history and RRD continuity remain intact.
    $sensor_id = dbFetchCell(
        'SELECT `sensor_id` FROM `sensors` WHERE `device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` = ? AND `sensor_index` = ?',
        [$device['device_id'], 'state', 'agent', $sensor_type, $sensor_index]
    );

    $data = [
        'poller_type' => 'agent',
        'sensor_class' => 'state',
        'device_id' => $device['device_id'],
        'sensor_oid' => 'app:btrfs:' . $sensor_index,
        'sensor_index' => $sensor_index,
        'sensor_type' => $sensor_type,
        'sensor_descr' => $sensor_descr,
        'sensor_divisor' => 1,
        'sensor_multiplier' => 1,
        'sensor_current' => $sensor_current,
        'group' => $sensor_group,
        'rrd_type' => 'GAUGE',
    ];

    if ($sensor_id) {
        dbUpdate($data, 'sensors', '`sensor_id` = ?', [$sensor_id]);
    } else {
        $sensor_id = dbInsert($data, 'sensors');
    }

    $sensor_rrd_name = get_sensor_rrd_name($device, $data);
    $sensor_rrd_def = RrdDefinition::make()->addDataset('sensor', 'GAUGE');
    // Keep standard sensor RRD output for compatibility with Health/State views.
    app('Datastore')->put($device, 'sensor', [
        'sensor_class' => 'state',
        'sensor_type' => $sensor_type,
        'sensor_descr' => $sensor_descr,
        'rrd_def' => $sensor_rrd_def,
        'rrd_name' => $sensor_rrd_name,
    ], ['sensor' => $sensor_current]);

    if (! $sensor_id || ! $state_index_id) {
        return;
    }

    $link_id = dbFetchCell('SELECT `sensors_to_state_translations_id` FROM `sensors_to_state_indexes` WHERE `sensor_id` = ?', [$sensor_id]);
    if ($link_id) {
        dbUpdate(['state_index_id' => $state_index_id], 'sensors_to_state_indexes', '`sensors_to_state_translations_id` = ?', [$link_id]);
    } else {
        dbInsert(['sensor_id' => $sensor_id, 'state_index_id' => $state_index_id], 'sensors_to_state_indexes');
    }
};

$delete_state_sensor = static function (array $device, string $sensor_index, string $sensor_type): void {
    // Remove obsolete synthetic sensor and state mapping rows.
    dbDelete(
        'sensors_to_state_indexes',
        '`sensor_id` IN (SELECT `sensor_id` FROM `sensors` WHERE `device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` = ? AND `sensor_index` = ?)',
        [$device['device_id'], 'state', 'agent', $sensor_type, $sensor_index]
    );
    dbDelete(
        'sensors',
        '`device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` = ? AND `sensor_index` = ?',
        [$device['device_id'], 'state', 'agent', $sensor_type, $sensor_index]
    );
};

$cleanup_legacy_btrfs_state_sensors = static function (array $device): void {
    // Cleanup legacy group='btrfs' sensors from older iterations.
    $legacy_types = ['btrfsIoStatusState', 'btrfsScrubStatusState', 'btrfsBalanceStatusState'];

    foreach ($legacy_types as $sensor_type) {
        dbDelete(
            'sensors_to_state_indexes',
            '`sensor_id` IN (SELECT `sensor_id` FROM `sensors` WHERE `device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` = ? AND `group` = ?)',
            [$device['device_id'], 'state', 'agent', $sensor_type, 'btrfs']
        );
        dbDelete(
            'sensors',
            '`device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` = ? AND `group` = ?',
            [$device['device_id'], 'state', 'agent', $sensor_type, 'btrfs']
        );
    }
};

$cleanup_obsolete_btrfs_state_sensors = static function (array $device, array $expected_sensor_indexes): void {
    $sensor_types = ['btrfsIoStatusState', 'btrfsScrubStatusState', 'btrfsBalanceStatusState'];
    $rows = dbFetchRows(
        'SELECT `sensor_id`, `sensor_type`, `sensor_index` FROM `sensors` WHERE `device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` IN (?,?,?)',
        [$device['device_id'], 'state', 'agent', $sensor_types[0], $sensor_types[1], $sensor_types[2]]
    );

    foreach ($rows as $row) {
        $sensor_id = $row['sensor_id'] ?? null;
        $sensor_type = (string) ($row['sensor_type'] ?? '');
        $sensor_index = (string) ($row['sensor_index'] ?? '');
        if (! is_numeric($sensor_id) || $sensor_type === '' || $sensor_index === '') {
            continue;
        }

        if (isset($expected_sensor_indexes[$sensor_type][$sensor_index])) {
            continue;
        }

        dbDelete('sensors_to_state_indexes', '`sensor_id` = ?', [$sensor_id]);
        dbDelete('sensors', '`sensor_id` = ?', [$sensor_id]);
    }
};

$cleanup_legacy_btrfs_state_sensors($device);

$metrics = [];
$device_map = [];
$filesystem_meta = [];
$filesystem_tables = [];
$filesystem_data_types = [];
$device_tables = [];
$scrub_status_fs = [];
$scrub_status_devices = [];
$balance_status_fs = [];
$filesystem_uuid = [];
$old_filesystem_uuid = $app->data['filesystem_uuid'] ?? [];
$fs_rrd_key = [];
$dev_rrd_key = [];
$device_error_seen = $app->data['device_error_seen'] ?? [];
$expected_sensor_indexes = [
    'btrfsIoStatusState' => [],
    'btrfsScrubStatusState' => [],
    'btrfsBalanceStatusState' => [],
];

// Overview (sum across all filesystems)
$overview_totals = array_fill_keys(array_keys($fs_space_datasets), 0);
unset($overview_totals['data_ratio'], $overview_totals['metadata_ratio']); // ratios don't sum meaningfully

foreach ($filesystems as $fs) {
    // First pass: compute app-level overview totals across filesystems.
    $overall = $normalize_overall($fs);
    foreach ($overview_totals as $ds => $unused) {
        $key = $fs_space_datasets[$ds];
        if (isset($overall[$key]) && is_numeric($overall[$key])) {
            $overview_totals[$ds] += $overall[$key];
        }
    }
}

// Per filesystem
$fs_names = [];
$app_has_data = false;
$app_has_missing = false;
$app_has_running = false;
$app_has_error = false;
$app_io_has_data = false;
$app_io_missing = false;
$app_io_has_error = false;
$app_scrub_has_data = false;
$app_scrub_has_error = false;
$app_scrub_running = false;
$app_balance_has_data = false;
$app_balance_has_error = false;
$app_balance_running = false;
$app_io_errors_total = 0.0;
$app_scrub_errors_total = 0.0;
foreach ($filesystems as $fs_name => $fs) {
    // Main per-filesystem pass: parse command payloads, write RRD data,
    // compute status codes, and sync synthetic state sensors.
    $fs_names[] = $fs_name;
    $overall = $normalize_overall($fs);
    $fs_show = $fs['commands']['filesystem_show']['data'] ?? [];
    $fs_label = trim((string) ($fs_show['label'] ?? ''));
    if ($fs_label !== '') {
        $fs_display_name = $fs_label;
    } elseif ($fs_name === '/') {
        $fs_display_name = 'root';
    } else {
        $fs_display_name = $fs_name;
    }

    $filesystem_meta[$fs_name] = [
        'mountpoint' => $fs['mountpoint'] ?? $fs_name,
        'label' => $fs_label,
        'total_devices' => $fs_show['total_devices'] ?? null,
        'fs_bytes_used' => $fs_show['fs_bytes_used'] ?? null,
    ];

    $fs_uuid = $extract_filesystem_uuid($fs);
    if ($fs_uuid === '' && isset($old_filesystem_uuid[$fs_name]) && is_string($old_filesystem_uuid[$fs_name])) {
        // Preserve last known UUID if current payload omits it.
        $fs_uuid = $old_filesystem_uuid[$fs_name];
    }
    $filesystem_uuid[$fs_name] = $fs_uuid;
    $fs_uuid_compact = preg_replace('/[^A-Fa-f0-9]/', '', $fs_uuid);
    $fs_rrd_id = strlen((string) $fs_uuid_compact) >= 10
        ? strtolower(substr((string) $fs_uuid_compact, 0, 10))
        : $normalize_id((string) $fs_name);
    $fs_rrd_key[$fs_name] = $fs_rrd_id;

    // app_id comes from the existing applications row for this device/app_type
    // (discovered and loaded by LibreNMS before this poller include runs).
    // Example filename segment: app-btrfs-13-* where 13 === $app->app_id.
    $rrd_name = ['app', $name, $app->app_id, $fs_rrd_id];
    $fields = [];
    foreach ($fs_space_datasets as $ds => $key) {
        $fields[$ds] = $overall[$key] ?? null;
    }

    $fs_metric_prefix = 'fs_' . $fs_rrd_id . '_';

    $devices = $extract_device_stats($fs);
    $scrub_devices = $extract_scrub_device_stats($fs);
    $usage_devices = $extract_device_usage($fs);
    $usage_type_totals = $extract_usage_type_totals($fs);
    $filesystem_data_types[$fs_name] = $usage_type_totals;

    $fs_scrub_status = $fs['commands']['scrub_status']['data'] ?? [];
    $scrub_status_fs[$fs_name] = is_array($fs_scrub_status) ? $fs_scrub_status : [];

    $raw_scrub_devices = $fs['commands']['scrub_status_devices']['data']['devices'] ?? [];
    $scrub_status_devices[$fs_name] = [];
    if (is_array($raw_scrub_devices)) {
        foreach ($raw_scrub_devices as $raw_key => $raw_entry) {
            if (! is_array($raw_entry)) {
                continue;
            }

            $raw_id = $raw_entry['id'] ?? null;
            if (! is_scalar($raw_id) || (string) $raw_id === '') {
                if (is_scalar($raw_key) && (string) $raw_key !== '') {
                    $raw_id = (string) $raw_key;
                }
            }

            if (! is_scalar($raw_id) || (string) $raw_id === '') {
                continue;
            }

            $scrub_status_devices[$fs_name][(string) $raw_id] = $raw_entry;
        }
    }

    $fs_balance_status = $fs['commands']['balance_status']['data'] ?? [];
    $balance_status_fs[$fs_name] = is_array($fs_balance_status) ? $fs_balance_status : [];

    $show_devices_by_path = [];
    $show_devices = $fs['commands']['filesystem_show']['data']['devices'] ?? [];
    if (is_array($show_devices)) {
        foreach ($show_devices as $show_device) {
            if (! is_array($show_device)) {
                continue;
            }

            $show_path = $normalize_device_path($show_device['path'] ?? null);
            $show_devid = $show_device['devid'] ?? null;
            if (! is_string($show_path) || $show_path === '' || ! is_scalar($show_devid) || (string) $show_devid === '') {
                continue;
            }

            $show_devid = (string) $show_devid;
            if ($show_path === '<missing disk>') {
                $show_path = $missing_device_key($show_devid);
            }

            $show_devices_by_path[$show_path] = $show_devid;
        }
    }

    $fs_has_missing = $filesystem_has_missing_device($fs);

    $has_device_data = count($devices) > 0;
    $has_scrub_data = count($scrub_devices) > 0 || isset($fs['commands']['scrub_status']);

    $io_has_error = false;
    foreach ($devices as $device_stats) {
        foreach ($io_error_keys as $error_key) {
            if (isset($device_stats[$error_key]) && is_numeric($device_stats[$error_key]) && (float) $device_stats[$error_key] > 0) {
                $io_has_error = true;
                break 2;
            }
        }
    }

    $scrub_has_error = false;
    $scrub_is_running = false;
    foreach ($scrub_devices as $scrub_device) {
        if (strtolower((string) ($scrub_device['status'] ?? '')) === 'running') {
            $scrub_is_running = true;
        }

        foreach ($scrub_error_keys as $error_key) {
            if (isset($scrub_device[$error_key]) && is_numeric($scrub_device[$error_key]) && (float) $scrub_device[$error_key] > 0) {
                $scrub_has_error = true;
                break 2;
            }
        }
    }

    $scrub_status_text = strtolower(trim((string) ($fs['commands']['scrub_status']['data']['status'] ?? '')));
    if ($scrub_status_text === 'running') {
        $scrub_is_running = true;
    }

    $io_status_code = $has_device_data ? ($io_has_error ? 3 : 0) : 2;
    $scrub_status_code = $has_scrub_data ? ($scrub_has_error ? 3 : ($scrub_is_running ? 1 : 0)) : 2;
    if ($fs_has_missing) {
        // Missing device should dominate IO state, but keep scrub state driven
        // by scrub output so scrub overview can still show running/error/ok.
        $io_status_code = 4;
    }

    $usage_totals = [
        'usage_device_size' => 0,
        'usage_unallocated' => 0,
        'usage_data' => 0,
        'usage_metadata' => 0,
        'usage_system' => 0,
    ];
    foreach ($usage_devices as $usage_stats) {
        $usage_totals['usage_device_size'] += (float) ($usage_stats['device_size'] ?? 0);
        $usage_totals['usage_unallocated'] += (float) ($usage_stats['unallocated'] ?? 0);
        $usage_totals['usage_data'] += (float) ($usage_stats['data_bytes'] ?? 0);
        $usage_totals['usage_metadata'] += (float) ($usage_stats['metadata_bytes'] ?? 0);
        $usage_totals['usage_system'] += (float) ($usage_stats['system_bytes'] ?? 0);
    }

    foreach ($usage_totals as $k => $v) {
        $fields[$k] = $v;
        $metrics[$fs_metric_prefix . $k] = $v;
    }

    foreach ($usage_type_totals as $type_key => $type_value) {
        // Write filesystem-level dynamic type series to isolated one-DS RRDs.
        $type_id = $normalize_id((string) $type_key);
        $type_rrd_name = ['app', $name, $app->app_id, $fs_rrd_id, 'type_' . $type_id];
        $type_tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $dynamic_type_rrd_def, 'rrd_name' => $type_rrd_name];
        app('Datastore')->put($device, 'app', $type_tags, ['value' => $type_value]);
        $metrics[$fs_metric_prefix . 'type_' . $type_id] = $type_value;
    }

    $balance_status_code = $get_balance_status_code($fs);
    $balance_status_text = trim((string) ($fs['commands']['balance_status']['data']['status'] ?? ''));
    $publish_balance_state = $balance_status_text !== '';
    $fields['io_status_code'] = $io_status_code;
    $fields['scrub_status_code'] = $scrub_status_code;
    $fields['balance_status_code'] = $balance_status_code;
    $metrics[$fs_metric_prefix . 'io_status_code'] = $io_status_code;
    $metrics[$fs_metric_prefix . 'scrub_status_code'] = $scrub_status_code;
    $metrics[$fs_metric_prefix . 'balance_status_code'] = $balance_status_code;

    $app_has_data = $app_has_data || $io_status_code !== 2 || $scrub_status_code !== 2 || $balance_status_code !== 2;
    $app_has_missing = $app_has_missing || $io_status_code === 4;
    $app_has_running = $app_has_running || $scrub_status_code === 1 || $balance_status_code === 1;
    $app_has_error = $app_has_error || $io_status_code === 3 || $scrub_status_code === 3 || $balance_status_code === 3 || $io_status_code === 4 || $scrub_status_code === 4 || $balance_status_code === 4;
    $app_io_has_data = $app_io_has_data || $io_status_code !== 2;
    $app_io_missing = $app_io_missing || $io_status_code === 4;
    $app_io_has_error = $app_io_has_error || $io_status_code === 3 || $io_status_code === 4;
    $app_scrub_has_data = $app_scrub_has_data || $scrub_status_code !== 2;
    $app_scrub_has_error = $app_scrub_has_error || $scrub_status_code === 3;
    $app_scrub_running = $app_scrub_running || $scrub_status_code === 1;
    $app_balance_has_data = $app_balance_has_data || $balance_status_code !== 2;
    $app_balance_has_error = $app_balance_has_error || $balance_status_code === 3;
    $app_balance_running = $app_balance_running || $balance_status_code === 1;

    $upsert_state_sensor(
        $device,
        $fs_rrd_id . '.io',
        'btrfsIoStatusState',
        $fs_display_name . ' IO',
        $io_status_code,
        $io_state_index_id,
        'btrfs filesystems'
    );
    $expected_sensor_indexes['btrfsIoStatusState'][(string) $fs_rrd_id . '.io'] = true;
    $upsert_state_sensor(
        $device,
        $fs_rrd_id . '.scrub',
        'btrfsScrubStatusState',
        $fs_display_name . ' Scrub',
        $scrub_status_code,
        $scrub_state_index_id,
        'btrfs filesystems'
    );
    $expected_sensor_indexes['btrfsScrubStatusState'][(string) $fs_rrd_id . '.scrub'] = true;
    if ($publish_balance_state) {
        $upsert_state_sensor(
            $device,
            $fs_rrd_id . '.balance',
            'btrfsBalanceStatusState',
            $fs_display_name . ' Balance',
            $balance_status_code,
            $balance_state_index_id,
            'btrfs filesystems'
        );
        $expected_sensor_indexes['btrfsBalanceStatusState'][(string) $fs_rrd_id . '.balance'] = true;
    } else {
        $delete_state_sensor($device, $fs_rrd_id . '.balance', 'btrfsBalanceStatusState');
    }

    $filesystem_tables[$fs_name] = $fields;
    $tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $fs_rrd_def, 'rrd_name' => $rrd_name];
    app('Datastore')->put($device, 'app', $tags, $fields);

    foreach ($fields as $field => $value) {
        $metrics[$fs_metric_prefix . $field] = $value;
    }

    $all_dev_paths = array_unique(array_merge(
        array_keys($devices),
        array_keys($scrub_devices),
        array_keys($usage_devices),
        array_keys($show_devices_by_path)
    ));

    foreach ($all_dev_paths as $dev_path) {
        $dev_stats = $devices[$dev_path] ?? [];
        $scrub_stats = $scrub_devices[$dev_path] ?? [];
        $usage_stats = $usage_devices[$dev_path] ?? [];
        $device_numeric_id = $dev_stats['devid'] ?? $usage_stats['id'] ?? $scrub_stats['id'] ?? $show_devices_by_path[$dev_path] ?? null;
        if (! is_scalar($device_numeric_id) || (string) $device_numeric_id === '') {
            continue;
        }
        $dev_id = (string) $device_numeric_id;

        if (! isset($dev_stats['missing']) && str_contains(strtolower((string) $dev_path), '<missing disk>')) {
            $dev_stats['missing'] = true;
        }

        $device_map[$fs_name][$dev_id] = $dev_path;
        $dev_rrd_key[$fs_name][$dev_id] = $dev_id;

        $rrd_name = ['app', $name, $app->app_id, $fs_rrd_id, 'device_' . $dev_id];
        $dev_fields = [
            'io_d_corruption' => $dev_stats['corruption_errs'] ?? null,
            'io_d_flush' => $dev_stats['flush_io_errs'] ?? null,
            'io_d_generation' => $dev_stats['generation_errs'] ?? null,
            'io_d_read' => $dev_stats['read_io_errs'] ?? null,
            'io_d_write' => $dev_stats['write_io_errs'] ?? null,
            'io_c_corruption' => $dev_stats['corruption_errs'] ?? null,
            'io_c_flush' => $dev_stats['flush_io_errs'] ?? null,
            'io_c_generation' => $dev_stats['generation_errs'] ?? null,
            'io_c_read' => $dev_stats['read_io_errs'] ?? null,
            'io_c_write' => $dev_stats['write_io_errs'] ?? null,
            'io_t_corruption' => $dev_stats['corruption_errs'] ?? null,
            'io_t_flush' => $dev_stats['flush_io_errs'] ?? null,
            'io_t_generation' => $dev_stats['generation_errs'] ?? null,
            'io_t_read' => $dev_stats['read_io_errs'] ?? null,
            'io_t_write' => $dev_stats['write_io_errs'] ?? null,
            'scrub_c_read' => $scrub_stats['read_errors'] ?? null,
            'scrub_c_csum' => $scrub_stats['csum_errors'] ?? null,
            'scrub_c_verify' => $scrub_stats['verify_errors'] ?? null,
            'scrub_c_uncorrectable' => $scrub_stats['uncorrectable_errors'] ?? null,
            'scrub_c_unverified' => $scrub_stats['unverified_errors'] ?? null,
            'scrub_c_corrected' => $scrub_stats['corrected_errors'] ?? null,
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
        $dev_tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $device_rrd_def, 'rrd_name' => $rrd_name];
        app('Datastore')->put($device, 'app', $dev_tags, $dev_fields);

        $dev_type_values = $usage_stats['type_values'] ?? [];
        if (is_array($dev_type_values)) {
            // Write per-device dynamic type series to isolated one-DS RRDs.
            foreach ($dev_type_values as $type_key => $type_value) {
                if (! is_numeric($type_value)) {
                    continue;
                }

                $type_id = $normalize_id((string) $type_key);
                $dev_type_rrd_name = ['app', $name, $app->app_id, $fs_rrd_id, 'device_' . $dev_id, 'type_' . $type_id];
                $dev_type_tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $dynamic_type_rrd_def, 'rrd_name' => $dev_type_rrd_name];
                app('Datastore')->put($device, 'app', $dev_type_tags, ['value' => $type_value]);
            }
        }

        $device_tables[$fs_name][$dev_id] = [
            'path' => $dev_path,
            'devid' => $device_numeric_id,
            'write_io_errs' => $dev_stats['write_io_errs'] ?? null,
            'read_io_errs' => $dev_stats['read_io_errs'] ?? null,
            'flush_io_errs' => $dev_stats['flush_io_errs'] ?? null,
            'corruption_errs' => $dev_stats['corruption_errs'] ?? null,
            'generation_errs' => $dev_stats['generation_errs'] ?? null,
            'usage_size' => $usage_stats['device_size'] ?? null,
            'usage_slack' => $usage_stats['device_slack'] ?? null,
            'usage_unallocated' => $usage_stats['unallocated'] ?? null,
            'usage_data' => $usage_stats['data_bytes'] ?? null,
            'usage_metadata' => $usage_stats['metadata_bytes'] ?? null,
            'usage_system' => $usage_stats['system_bytes'] ?? null,
        ];
        $io_errs = $dev_stats['corruption_errs'] ?? 0;
        $io_errs += $dev_stats['flush_io_errs'] ?? 0;
        $io_errs += $dev_stats['generation_errs'] ?? 0;
        $io_errs += $dev_stats['read_io_errs'] ?? 0;
        $io_errs += $dev_stats['write_io_errs'] ?? 0;

        if ($io_errs > 0 && empty($device_error_seen[$fs_name][$dev_id])) {
            // Log only first observed device-error transition to limit noise.
            Eventlog::log("BTRFS device errors detected on $fs_name ($dev_path)", $device['device_id'], 'application', Severity::Error);
            $device_error_seen[$fs_name][$dev_id] = 1;
        }

        $dev_metric_prefix = $fs_metric_prefix . 'device_' . $dev_id . '_';
        foreach ($dev_fields as $field => $value) {
            $metrics[$dev_metric_prefix . $field] = $value;
        }

        $dev_io_has_error = false;
        foreach ($io_error_keys as $error_key) {
            if (isset($dev_stats[$error_key]) && is_numeric($dev_stats[$error_key]) && (float) $dev_stats[$error_key] > 0) {
                $dev_io_has_error = true;
                break;
            }
        }
        $dev_io_status_code = count($dev_stats) > 0 ? ($dev_io_has_error ? 3 : 0) : 2;

        $dev_scrub_has_error = false;
        foreach ($scrub_error_keys as $error_key) {
            if (isset($scrub_stats[$error_key]) && is_numeric($scrub_stats[$error_key]) && (float) $scrub_stats[$error_key] > 0) {
                $dev_scrub_has_error = true;
                break;
            }
        }
        $dev_scrub_is_running = strtolower((string) ($scrub_stats['status'] ?? '')) === 'running';
        $dev_scrub_status_code = count($scrub_stats) > 0 ? ($dev_scrub_has_error ? 3 : ($dev_scrub_is_running ? 1 : 0)) : 2;
        if (($dev_stats['missing'] ?? false) || str_contains(strtolower((string) $dev_path), '<missing disk>')) {
            $dev_io_status_code = 4;
        }

        $app_io_errors_total += (float) ($dev_stats['corruption_errs'] ?? 0)
            + (float) ($dev_stats['flush_io_errs'] ?? 0)
            + (float) ($dev_stats['generation_errs'] ?? 0)
            + (float) ($dev_stats['read_io_errs'] ?? 0)
            + (float) ($dev_stats['write_io_errs'] ?? 0);
        $app_scrub_errors_total += (float) ($scrub_stats['read_errors'] ?? 0)
            + (float) ($scrub_stats['csum_errors'] ?? 0)
            + (float) ($scrub_stats['verify_errors'] ?? 0)
            + (float) ($scrub_stats['uncorrectable_errors'] ?? 0)
            + (float) ($scrub_stats['unverified_errors'] ?? 0)
            + (float) ($scrub_stats['missing'] ?? 0)
            + (float) ($scrub_stats['device_missing'] ?? 0);

        $upsert_state_sensor(
            $device,
            $fs_rrd_id . '.dev.' . $dev_id . '.io',
            'btrfsIoStatusState',
            $fs_display_name . ' ' . $dev_path . ' IO',
            $dev_io_status_code,
            $io_state_index_id,
            'btrfs devices'
        );
        $expected_sensor_indexes['btrfsIoStatusState'][(string) $fs_rrd_id . '.dev.' . $dev_id . '.io'] = true;
        $upsert_state_sensor(
            $device,
            $fs_rrd_id . '.dev.' . $dev_id . '.scrub',
            'btrfsScrubStatusState',
            $fs_display_name . ' ' . $dev_path . ' Scrub',
            $dev_scrub_status_code,
            $scrub_state_index_id,
            'btrfs devices'
        );
        $expected_sensor_indexes['btrfsScrubStatusState'][(string) $fs_rrd_id . '.dev.' . $dev_id . '.scrub'] = true;
    }
}

$cleanup_obsolete_btrfs_state_sensors($device, $expected_sensor_indexes);

// check for added or removed filesystems
$old_filesystems = $app->data['filesystems'] ?? [];
$added_filesystems = array_diff($fs_names, $old_filesystems);
$removed_filesystems = array_diff($old_filesystems, $fs_names);
if (count($added_filesystems) > 0 || count($removed_filesystems) > 0) {
    $log_message = 'BTRFS Filesystem Change:';
    $log_message .= count($added_filesystems) > 0 ? ' Added ' . implode(',', $added_filesystems) : '';
    $log_message .= count($removed_filesystems) > 0 ? ' Removed ' . implode(',', $removed_filesystems) : '';
    Eventlog::log($log_message, $device['device_id'], 'application');
}

$app_status_code = $app_has_missing ? 4 : ($app_has_error ? 3 : ($app_has_running ? 1 : ($app_has_data ? 0 : 2)));
$metrics['status_code'] = $app_status_code;
$app_io_status_code = $app_io_missing ? 4 : ($app_io_has_error ? 3 : ($app_io_has_data ? 0 : 2));
$app_scrub_status_code = $app_scrub_has_error ? 3 : ($app_scrub_running ? 1 : ($app_scrub_has_data ? 0 : 2));
$app_balance_status_code = $app_balance_has_error ? 3 : ($app_balance_running ? 1 : ($app_balance_has_data ? 0 : 2));
$app_status_text = match ($app_status_code) {
    1 => 'Running',
    2 => 'N/A',
    3 => 'Error',
    4 => 'Missing',
    default => 'OK',
};

$overall_fields = [
    // App-level aggregate timeseries for /apps/app=btrfs/ overview graphs.
    'used' => $overview_totals['used'] ?? 0,
    'free_estimated' => $overview_totals['free_estimated'] ?? 0,
    'device_size' => $overview_totals['device_size'] ?? 0,
    'io_errors_total' => $app_io_errors_total,
    'scrub_errors_total' => $app_scrub_errors_total,
    'status_code' => $app_status_code,
    'io_status_code' => $app_io_status_code,
    'scrub_status_code' => $app_scrub_status_code,
    'balance_status_code' => $app_balance_status_code,
];
$overall_tags = [
    'name' => $name,
    'app_id' => $app->app_id,
    'rrd_def' => $overall_rrd_def,
    'rrd_name' => ['app', $name, $app->app_id, 'overall'],
];
app('Datastore')->put($device, 'app', $overall_tags, $overall_fields);
foreach ($overall_fields as $field => $value) {
    $metrics['overall_' . $field] = $value;
}

// Persist only structures needed for UI rendering/navigation; avoid heavy debug payloads.
// This payload is consumed by both device and global btrfs pages.
$app->data = [
    'schema_version' => 2,
    'filesystems' => $fs_names,
    'filesystem_meta' => $filesystem_meta,
    'device_map' => $device_map,
    'filesystem_tables' => $filesystem_tables,
    'filesystem_data_types' => $filesystem_data_types,
    'fs_rrd_key' => $fs_rrd_key,
    'dev_rrd_key' => $dev_rrd_key,
    'device_tables' => $device_tables,
    'scrub_status_fs' => $scrub_status_fs,
    'scrub_status_devices' => $scrub_status_devices,
    'balance_status_fs' => $balance_status_fs,
    'filesystem_uuid' => $filesystem_uuid,
    'device_error_seen' => $device_error_seen,
    'btrfs_progs_version' => $btrfs['btrfs_version']['version'] ?? null,
    'btrfs_progs_features' => $btrfs['btrfs_version']['features'] ?? null,
    'status_code' => $app_status_code,
    'status_text' => $app_status_text,
    'btrfs_dev_version' => $btrfs_dev_version,
    'version' => $all_return['version'] ?? null,
];

update_application($app, $app_status_text, $metrics);
