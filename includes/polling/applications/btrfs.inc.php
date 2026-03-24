<?php

use App\Models\Eventlog;
use LibreNMS\Enum\Severity;
use LibreNMS\Exceptions\JsonAppException;
use LibreNMS\RRD\RrdDefinition;

$name = 'btrfs';

$safe_id = static function (string $value): string {
    $base = preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
    $base = trim((string) $base, '_');
    if ($base === '') {
        $base = 'id';
    }

    $base = substr($base, 0, 32);
    $hash = substr(hash('crc32b', $value), 0, 8);

    return $base . '_' . $hash;
};

function btrfs_command_table_split(string $command_name, array $rows): array
{
    $overview_rows = [];
    $device_rows = [];
    $profile_class = [];
    $profile_name = [];

    // First pass: collect profile class/name metadata for filesystem_usage
    foreach ($rows as $row) {
        $key = (string) ($row['key'] ?? '');
        $value = (string) ($row['value'] ?? '');

        if (preg_match('/^profiles\.\[([0-9]+)\]\.class$/', $key, $match)) {
            $profile_class[$match[1]] = $value;
        }
        if (preg_match('/^profiles\.\[([0-9]+)\]\.profile$/', $key, $match)) {
            $profile_name[$match[1]] = $value;
        }
    }

    // Second pass: route each row into overview or device buckets
    foreach ($rows as $row) {
        $key = (string) ($row['key'] ?? '');
        $value = (string) ($row['value'] ?? '');

        if ($command_name === 'filesystem_show') {
            if (preg_match('/^devices\.\[([0-9]+)\]\.(.+)$/', $key, $match)) {
                $device_rows[$match[1]][$match[2]] = $value;
                continue;
            }
            continue;
        }

        if ($command_name === 'filesystem_usage') {
            if (preg_match('/^overall\.(.+)$/', $key, $match)) {
                continue;
            }

            if (preg_match('/^unallocated\.(.+)$/', $key, $match)) {
                $device_rows[$match[1]]['unallocated'] = $value;
                continue;
            }

            if (preg_match('/^profiles\.\[([0-9]+)\]\.devices\.(.+)$/', $key, $match)) {
                $index = $match[1];
                $device = $match[2];
                $class = strtolower((string) ($profile_class[$index] ?? 'profile'));
                $profile = strtolower((string) ($profile_name[$index] ?? $index));
                $metric = preg_replace('/[^a-z0-9_]+/', '_', $class . '_' . $profile);
                $device_rows[$device]['profile_' . $metric] = $value;

                continue;
            }

            if (! str_starts_with($key, 'profiles.')) {
                $overview_rows[] = ['key' => $key, 'value' => $value];
            }

            continue;
        }

        if ($command_name === 'device_stats') {
            if (preg_match('/^device-stats\.\[([0-9]+)\]\.(.+)$/', $key, $match)) {
                if (in_array($match[2], ['devid', 'id'], true)) {
                    continue;
                }
                $device_rows[$match[1]][$match[2]] = $value;
                continue;
            }

            continue;
        }

        if ($command_name === 'scrub_status' || $command_name === 'device_usage') {
            if (preg_match('/^devices\.\[([0-9]+)\]\.(.+)$/', $key, $match)) {
                $device_rows[$match[1]][$match[2]] = $value;
                continue;
            }

            if (str_starts_with($key, 'devices.')) {
                $last_dot = strrpos($key, '.');
                if ($last_dot !== false && $last_dot > 8) {
                    $device_key = substr($key, 8, $last_dot - 8);
                    $field_key = substr($key, $last_dot + 1);
                    if ($device_key !== '' && $field_key !== '') {
                        $device_rows[$device_key][$field_key] = $value;
                        continue;
                    }
                }
            }

            if ($command_name === 'scrub_status' && $key === 'uuid') {
                continue;
            }

            $overview_rows[] = ['key' => $key, 'value' => $value];
            continue;
        }

        if ($command_name === 'balance_status') {
            if (preg_match('/^profiles\.\[([0-9]+)\]\.(.+)$/', $key, $match)) {
                $device_rows[$match[1]][$match[2]] = $value;
                continue;
            }

            if (! str_starts_with($key, 'lines.')) {
                $overview_rows[] = ['key' => $key, 'value' => $value];
            }
            continue;
        }

        $overview_rows[] = ['key' => $key, 'value' => $value];
    }

    // Normalize device keys
    $normalized_devices = [];
    foreach ($device_rows as $index => $metrics) {
        if (isset($metrics['path']) && $metrics['path'] !== '') {
            $device_key = $metrics['path'];
        } elseif (isset($metrics['device']) && $metrics['device'] !== '') {
            $device_key = $metrics['device'];
        } elseif (is_string($index) && str_starts_with($index, '/')) {
            $device_key = $index;
        } else {
            $device_key = 'Device ' . ((int) $index + 1);
        }

        $normalized_devices[$device_key] = $metrics;
    }

    // Build ordered column list
    $device_columns = [];
    foreach ($normalized_devices as $metrics) {
        foreach ($metrics as $metric => $unused) {
            if ($metric === 'path' || $metric === 'device') {
                continue;
            }
            if (! in_array($metric, $device_columns, true)) {
                $device_columns[] = $metric;
            }
        }
    }

    // Order columns by semantic priority
    if ($command_name === 'device_usage') {
        $preferred = ['id', 'device_size', 'device_slack'];
        $ordered_columns = [];

        foreach ($preferred as $column) {
            if (in_array($column, $device_columns, true)) {
                $ordered_columns[] = $column;
            }
        }

        $data_columns = array_values(array_filter($device_columns, static function ($column): bool {
            return str_starts_with((string) $column, 'data_')
                || str_starts_with((string) $column, 'metadata_')
                || str_starts_with((string) $column, 'system_')
                || str_starts_with((string) $column, 'profile_');
        }));
        sort($data_columns);

        $tail_columns = [];
        if (in_array('unallocated', $device_columns, true)) {
            $tail_columns[] = 'unallocated';
        }

        $other_columns = array_values(array_filter($device_columns, static function ($column): bool {
            return ! in_array($column, ['id', 'device_size', 'device_slack', 'unallocated'], true)
                && ! str_starts_with((string) $column, 'profile_')
                && ! str_starts_with((string) $column, 'data_')
                && ! str_starts_with((string) $column, 'metadata_')
                && ! str_starts_with((string) $column, 'system_');
        }));
        sort($other_columns);

        $device_columns = array_merge($ordered_columns, $data_columns, $other_columns, $tail_columns);
    } elseif ($command_name === 'scrub_status') {
        $device_columns = array_values(array_filter($device_columns, static function ($column): bool {
            return ! in_array($column, ['has_status_suffix', 'has_stats', 'no_stats_available', 'id', 'section', 'last_physical'], true);
        }));

        $preferred = [
            'scrub_started', 'status', 'duration',
            'data_extents_scrubbed', 'tree_extents_scrubbed',
            'data_bytes_scrubbed', 'tree_bytes_scrubbed',
            'read_errors', 'csum_errors', 'verify_errors',
            'corrected_errors', 'uncorrectable_errors', 'unverified_errors',
            'no_csum', 'csum_discards', 'super_errors', 'malloc_errors',
        ];

        $ordered_columns = [];
        foreach ($preferred as $column) {
            if (in_array($column, $device_columns, true)) {
                $ordered_columns[] = $column;
            }
        }

        $other_columns = array_values(array_filter($device_columns, static function ($column) use ($preferred): bool {
            return ! in_array($column, $preferred, true);
        }));
        sort($other_columns);

        $device_columns = array_merge($ordered_columns, $other_columns);
    } elseif ($command_name === 'balance_status') {
        $preferred = ['class', 'flags', 'status'];
        $ordered_columns = [];
        foreach ($preferred as $column) {
            if (in_array($column, $device_columns, true)) {
                $ordered_columns[] = $column;
            }
        }
        $other_columns = array_values(array_filter($device_columns, static function ($column) use ($preferred): bool {
            return ! in_array($column, $preferred, true);
        }));
        sort($other_columns);
        $device_columns = array_merge($ordered_columns, $other_columns);
    } else {
        sort($device_columns);
    }

    return [
        'overview' => $overview_rows,
        'devices' => $normalized_devices,
        'device_columns' => $device_columns,
    ];
}

try {
    $all_return = json_app_get($device, $name, 1);
    $btrfs = $all_return['data'];
} catch (JsonAppException $e) {
    echo PHP_EOL . $name . ':' . $e->getCode() . ':' . $e->getMessage() . PHP_EOL;
    update_application($app, $e->getCode() . ':' . $e->getMessage(), []); // Set empty metrics and error message

    return;
}

$filesystems = $btrfs['filesystems'] ?? [];

$collect_keys = static function ($value, string $prefix = '') use (&$collect_keys): array {
    if (! is_array($value)) {
        return $prefix === '' ? [] : [$prefix];
    }

    $keys = [];
    foreach ($value as $key => $child) {
        $segment = is_int($key) ? '[]' : (string) $key;
        $path = $prefix === '' ? $segment : $prefix . '.' . $segment;

        $keys[] = $path;
        if (is_array($child)) {
            $keys = array_merge($keys, $collect_keys($child, $path));
        }
    }

    return $keys;
};

$flatten_values = static function ($value, string $prefix = '') use (&$flatten_values): array {
    if (! is_array($value)) {
        return $prefix === '' ? [] : [$prefix => $value];
    }

    $rows = [];
    foreach ($value as $key => $child) {
        $segment = is_int($key) ? '[' . $key . ']' : (string) $key;
        $path = $prefix === '' ? $segment : $prefix . '.' . $segment;

        if (is_array($child)) {
            $rows += $flatten_values($child, $path);
        } else {
            $rows[$path] = $child;
        }
    }

    return $rows;
};

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
    $command = $fs['commands']['balance_status'] ?? null;
    if (! is_array($command)) {
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

$extract_device_stats = static function (array $fs): array {
    $entries = $fs['commands']['device_stats']['data']['device-stats'] ?? [];
    if (! is_array($entries)) {
        return [];
    }

    $devices = [];
    foreach ($entries as $entry) {
        if (! is_array($entry)) {
            continue;
        }

        $path = $entry['device'] ?? null;
        if (! is_string($path) || $path === '') {
            continue;
        }

        $devices[$path] = [
            'devid' => $entry['devid'] ?? null,
            'corruption_errs' => $entry['corruption_errs'] ?? null,
            'flush_io_errs' => $entry['flush_io_errs'] ?? null,
            'generation_errs' => $entry['generation_errs'] ?? null,
            'read_io_errs' => $entry['read_io_errs'] ?? null,
            'write_io_errs' => $entry['write_io_errs'] ?? null,
        ];
    }

    return $devices;
};

$extract_scrub_device_stats = static function (array $fs): array {
    $devices = [];
    $raw_devices = $fs['commands']['scrub_status_devices']['data']['devices'] ?? [];

    if (! is_array($raw_devices)) {
        return $devices;
    }

    foreach ($raw_devices as $key => $entry) {
        if (! is_array($entry)) {
            continue;
        }

        $path = $entry['path'] ?? null;
        if (! is_string($path) || $path === '') {
            $path = is_string($key) ? $key : null;
        }
        if (! is_string($path) || $path === '') {
            continue;
        }

        $devices[$path] = [
            'path' => $entry['path'] ?? $path,
            'id' => $entry['id'] ?? null,
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

$extract_device_usage = static function (array $fs): array {
    $entries = $fs['commands']['device_usage']['data']['devices'] ?? [];
    if (! is_array($entries)) {
        return [];
    }

    $devices = [];
    foreach ($entries as $entry) {
        if (! is_array($entry)) {
            continue;
        }

        $path = $entry['path'] ?? null;
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
    ->addDataset('scrub_c_read', 'COUNTER', 0)
    ->addDataset('scrub_c_csum', 'COUNTER', 0)
    ->addDataset('scrub_c_verify', 'COUNTER', 0)
    ->addDataset('scrub_c_uncorrectable', 'COUNTER', 0)
    ->addDataset('scrub_c_unverified', 'COUNTER', 0)
    ->addDataset('scrub_c_corrected', 'COUNTER', 0)
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
    $state_index_id = dbFetchCell('SELECT `state_index_id` FROM `state_indexes` WHERE `state_name` = ?', [$state_name]);

    if (! $state_index_id) {
        $state_index_id = dbInsert(['state_name' => $state_name], 'state_indexes');
    }

    if (! $state_index_id) {
        return null;
    }

    foreach ($states as $state) {
        $existing = dbFetchCell(
            'SELECT `state_translation_id` FROM `state_translations` WHERE `state_index_id` = ? AND `state_value` = ?',
            [$state_index_id, $state['value']]
        );

        $translation = [
            'state_index_id' => $state_index_id,
            'state_descr' => $state['descr'],
            'state_draw_graph' => $state['graph'],
            'state_value' => $state['value'],
            'state_generic_value' => $state['generic'],
        ];

        if ($existing) {
            dbUpdate($translation, 'state_translations', '`state_translation_id` = ?', [$existing]);
        } else {
            dbInsert($translation, 'state_translations');
        }
    }

    return (int) $state_index_id;
};

$status_states = [
    ['value' => 0, 'generic' => 0, 'graph' => 0, 'descr' => 'OK'],
    ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'Running'],
    ['value' => 2, 'generic' => 3, 'graph' => 0, 'descr' => 'N/A'],
    ['value' => 3, 'generic' => 2, 'graph' => 0, 'descr' => 'Error'],
];

$io_state_index_id = $ensure_state_index('btrfsIoStatusState', $status_states);
$scrub_state_index_id = $ensure_state_index('btrfsScrubStatusState', $status_states);
$balance_state_index_id = $ensure_state_index('btrfsBalanceStatusState', $status_states);

$upsert_state_sensor = static function (array $device, string $sensor_index, string $sensor_type, string $sensor_descr, int $sensor_current, ?int $state_index_id, string $sensor_group): void {
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

$cleanup_legacy_btrfs_state_sensors($device);

$metrics = [];
$device_map = [];
$command_tables = [];
$filesystem_tables = [];
$filesystem_data_types = [];
$device_tables = [];
$scrub_tables = [];
$fs_rrd_key = [];
$dev_rrd_key = [];
$device_error_seen = $app->data['device_error_seen'] ?? [];

// Overview (sum across all filesystems)
$overview_totals = array_fill_keys(array_keys($fs_space_datasets), 0);
unset($overview_totals['data_ratio'], $overview_totals['metadata_ratio']); // ratios don't sum meaningfully

foreach ($filesystems as $fs) {
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
$app_has_running = false;
$app_has_error = false;
$app_io_has_data = false;
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
    $fs_names[] = $fs_name;
    $overall = $normalize_overall($fs);
    $fs_label = trim((string) ($fs['commands']['filesystem_show']['data']['label'] ?? ''));
    if ($fs_label !== '') {
        $fs_display_name = $fs_label;
    } elseif ($fs_name === '/') {
        $fs_display_name = 'root';
    } else {
        $fs_display_name = $fs_name;
    }
    $fs_uuid = (string) ($fs['commands']['filesystem_show']['data']['uuid'] ?? '');
    $fs_uuid_compact = preg_replace('/[^A-Fa-f0-9]/', '', $fs_uuid);
    $fs_rrd_id = strlen((string) $fs_uuid_compact) >= 6
        ? strtolower(substr((string) $fs_uuid_compact, 0, 6))
        : $safe_id((string) $fs_name);
    $fs_rrd_key[$fs_name] = $fs_rrd_id;

    $commands = $fs['commands'] ?? [];
    foreach ($commands as $command_name => $command) {
        $command_data = $command['data'] ?? null;
        $flattened = $flatten_values($command_data);

        $command_rows = [];
        foreach ($flattened as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $value = 'null';
            } elseif (! is_scalar($value)) {
                $value = json_encode($value);
            } else {
                $value = (string) $value;
            }

            $command_rows[] = ['key' => (string) $key, 'value' => $value];
        }

        $command_tables[$fs_name][$command_name] = $command_rows;
        $command_splits[$fs_name][$command_name] = btrfs_command_table_split($command_name, $command_rows);
    }

    $rrd_name = ['app', $name, $app->app_id, $fs_rrd_id];
    $fields = [];
    foreach ($fs_space_datasets as $ds => $key) {
        $fields[$ds] = $overall[$key] ?? null;
    }

    $fs_metric_prefix = 'fs_' . $safe_id((string) $fs_name) . '_';

    $devices = $extract_device_stats($fs);
    $scrub_devices = $extract_scrub_device_stats($fs);
    $usage_devices = $extract_device_usage($fs);
    $usage_type_totals = $extract_usage_type_totals($fs);
    $filesystem_data_types[$fs_name] = $usage_type_totals;

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
        $type_id = $safe_id((string) $type_key);
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
    $app_has_running = $app_has_running || $scrub_status_code === 1 || $balance_status_code === 1;
    $app_has_error = $app_has_error || $io_status_code === 3 || $scrub_status_code === 3 || $balance_status_code === 3;
    $app_io_has_data = $app_io_has_data || $io_status_code !== 2;
    $app_io_has_error = $app_io_has_error || $io_status_code === 3;
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
    $upsert_state_sensor(
        $device,
        $fs_rrd_id . '.scrub',
        'btrfsScrubStatusState',
        $fs_display_name . ' Scrub',
        $scrub_status_code,
        $scrub_state_index_id,
        'btrfs filesystems'
    );
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
    } else {
        $delete_state_sensor($device, $fs_rrd_id . '.balance', 'btrfsBalanceStatusState');
    }

    $filesystem_tables[$fs_name] = $fields;
    $tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $fs_rrd_def, 'rrd_name' => $rrd_name];
    app('Datastore')->put($device, 'app', $tags, $fields);

    foreach ($fields as $field => $value) {
        $metrics[$fs_metric_prefix . $field] = $value;
    }

    $all_dev_paths = array_unique(array_merge(array_keys($devices), array_keys($scrub_devices), array_keys($usage_devices)));

    foreach ($all_dev_paths as $dev_path) {
        $dev_stats = $devices[$dev_path] ?? [];
        $scrub_stats = $scrub_devices[$dev_path] ?? [];
        $usage_stats = $usage_devices[$dev_path] ?? [];
        $device_numeric_id = $dev_stats['devid'] ?? null;
        $dev_id = (is_scalar($device_numeric_id) && (string) $device_numeric_id !== '')
            ? (string) $device_numeric_id
            : $safe_id((string) $dev_path);
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
            'scrub_c_read' => $scrub_stats['read_errors'] ?? null,
            'scrub_c_csum' => $scrub_stats['csum_errors'] ?? null,
            'scrub_c_verify' => $scrub_stats['verify_errors'] ?? null,
            'scrub_c_uncorrectable' => $scrub_stats['uncorrectable_errors'] ?? null,
            'scrub_c_unverified' => $scrub_stats['unverified_errors'] ?? null,
            'scrub_c_corrected' => $scrub_stats['corrected_errors'] ?? null,
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
            foreach ($dev_type_values as $type_key => $type_value) {
                if (! is_numeric($type_value)) {
                    continue;
                }

                $type_id = $safe_id((string) $type_key);
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
        $scrub_tables[$fs_name][$dev_id] = array_merge(
            $scrub_stats,
            [
                // Backward-compatible aliases used by status checks
                'scrub_read_errors' => $scrub_stats['read_errors'] ?? null,
                'scrub_csum_errors' => $scrub_stats['csum_errors'] ?? null,
                'scrub_verify_errors' => $scrub_stats['verify_errors'] ?? null,
                'scrub_uncorrectable_errors' => $scrub_stats['uncorrectable_errors'] ?? null,
                'scrub_unverified_errors' => $scrub_stats['unverified_errors'] ?? null,
                'scrub_corrected_errors' => $scrub_stats['corrected_errors'] ?? null,
                'scrub_missing' => $scrub_stats['missing'] ?? null,
                'scrub_device_missing' => $scrub_stats['device_missing'] ?? null,
            ]
        );

        $io_errs = $dev_stats['corruption_errs'] ?? 0;
        $io_errs += $dev_stats['flush_io_errs'] ?? 0;
        $io_errs += $dev_stats['generation_errs'] ?? 0;
        $io_errs += $dev_stats['read_io_errs'] ?? 0;
        $io_errs += $dev_stats['write_io_errs'] ?? 0;

        if ($io_errs > 0 && empty($device_error_seen[$fs_name][$dev_id])) {
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
        $upsert_state_sensor(
            $device,
            $fs_rrd_id . '.dev.' . $dev_id . '.scrub',
            'btrfsScrubStatusState',
            $fs_display_name . ' ' . $dev_path . ' Scrub',
            $dev_scrub_status_code,
            $scrub_state_index_id,
            'btrfs devices'
        );
    }
}

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

$json_keys = array_values(array_unique($collect_keys($all_return)));
sort($json_keys);

dbUpdate(['app_instance' => ''], 'applications', '`app_id` = ?', [$app->app_id]);

$app_status_code = $app_has_error ? 3 : ($app_has_running ? 1 : ($app_has_data ? 0 : 2));
$metrics['status_code'] = $app_status_code;
$app_io_status_code = $app_io_has_error ? 3 : ($app_io_has_data ? 0 : 2);
$app_scrub_status_code = $app_scrub_has_error ? 3 : ($app_scrub_running ? 1 : ($app_scrub_has_data ? 0 : 2));
$app_balance_status_code = $app_balance_has_error ? 3 : ($app_balance_running ? 1 : ($app_balance_has_data ? 0 : 2));
$app_status_text = match ($app_status_code) {
    1 => 'Running',
    2 => 'N/A',
    3 => 'Error',
    default => 'OK',
};

$overall_fields = [
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

$app->data = [
    'filesystems' => $fs_names,
    'device_map' => $device_map,
    'command_tables' => $command_tables,
    'command_splits' => $command_splits,
    'filesystem_tables' => $filesystem_tables,
    'filesystem_data_types' => $filesystem_data_types,
    'fs_rrd_key' => $fs_rrd_key,
    'dev_rrd_key' => $dev_rrd_key,
    'device_tables' => $device_tables,
    'scrub_tables' => $scrub_tables,
    'device_error_seen' => $device_error_seen,
    'btrfs_progs_version' => $btrfs['btrfs_version']['version'] ?? null,
    'btrfs_progs_features' => $btrfs['btrfs_version']['features'] ?? null,
    'json_keys' => $json_keys,
    'status_code' => $app_status_code,
    'status_text' => $app_status_text,
    'version' => $all_return['version'] ?? null,
];

update_application($app, $app_status_text, $metrics);
