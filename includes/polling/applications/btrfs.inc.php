<?php

use App\Models\Eventlog;
use LibreNMS\Enum\Severity;
use LibreNMS\Exceptions\JsonAppException;
use LibreNMS\Polling\Modules\BtrfsPayloadParser;
use LibreNMS\Polling\Modules\BtrfsRrdWriter;
use LibreNMS\Polling\Modules\BtrfsSensorSync;
use LibreNMS\Polling\Modules\BtrfsStatusMapper;

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
//   instantiate helper classes (parser, mapper, sensors, rrd)
//            |
//            v
//   ensure state indexes/translations (idempotent)
//            |
//            v
//   for each filesystem in tables:
//     - extract capacity/profiles/devices/scrub/balance
//     - compute IO/Scrub/Balance status codes
//     - write filesystem RRD
//     - upsert filesystem state sensors
//            |
//            v
//   for each device in filesystem:
//     - extract io/usage stats
//     - write device + dynamic type RRDs
//     - compute device status codes
//     - upsert device state sensors
//            |
//            v
//   aggregate app-level totals
//            |
//            v
//   persist compact app->data + update_application()

$name = 'btrfs';

try {
    $all_return = json_app_get($device, $name, 1);
    $btrfs = $all_return['data'];
} catch (JsonAppException $e) {
    echo PHP_EOL . $name . ':' . $e->getCode() . ':' . $e->getMessage() . PHP_EOL;
    update_application($app, $e->getCode() . ':' . $e->getMessage(), []);

    return;
}

// -----------------------------------------------------------------------------
// Instantiate helper classes
// -----------------------------------------------------------------------------

$parser = new BtrfsPayloadParser();
$mapper = new BtrfsStatusMapper();
$sensorSync = new BtrfsSensorSync($mapper);
$rrdWriter = new BtrfsRrdWriter();

// -----------------------------------------------------------------------------
// Payload normalization - work directly with flat tables
// -----------------------------------------------------------------------------

$tables = $btrfs['tables'] ?? [];
$fs_list = $tables['filesystems'] ?? [];
$btrfs_dev_version = (int) ($btrfs['version'] ?? $all_return['version'] ?? 0);

if ($btrfs_dev_version < 1 && count($fs_list) > 0) {
    $btrfs_dev_version = 1;
}
if ($btrfs_dev_version < 1) {
    $sensorSync->deleteAllStateAndCountSensors($device);
    $app->data = [];
    update_application($app, 'Unsupported btrfs agent payload version', ['status_code' => BtrfsStatusMapper::STATUS_NA]);

    return;
}

// Build mountpoint -> fs_uuid mapping for iteration
$fs_by_mountpoint = $parser->getFilesystemsByMountpoint($tables);

// -----------------------------------------------------------------------------
// Ensure state indexes and translations
// -----------------------------------------------------------------------------

$state_indexes = $sensorSync->ensureStateIndexes();
$io_state_index_id = $state_indexes[BtrfsSensorSync::STATE_SENSOR_IO] ?? null;
$scrub_state_index_id = $state_indexes[BtrfsSensorSync::STATE_SENSOR_SCRUB] ?? null;
$balance_state_index_id = $state_indexes[BtrfsSensorSync::STATE_SENSOR_BALANCE] ?? null;

// -----------------------------------------------------------------------------
// Runtime accumulators and per-poll state
// -----------------------------------------------------------------------------

$metrics = [];
$filesystem_entries = [];

// Preserve last-seen UUIDs when payload omits them (not needed in flat tables, but kept for compatibility)
$old_filesystem_uuid = [];
if (is_array($app->data['filesystems'] ?? null)) {
    foreach ($app->data['filesystems'] as $old_fs_name => $old_fs_entry) {
        if (! is_array($old_fs_entry)) {
            continue;
        }
        $old_uuid = trim((string) ($old_fs_entry['uuid'] ?? ''));
        if ($old_uuid !== '') {
            $old_filesystem_uuid[(string) $old_fs_name] = $old_uuid;
        }
    }
}

// Preserve scrub counters for partial RAID5/6 output scenarios
$old_scrub_status_devices = [];
if (is_array($app->data['filesystems'] ?? null)) {
    foreach ($app->data['filesystems'] as $old_fs_name => $old_fs_entry) {
        if (! is_array($old_fs_entry)) {
            continue;
        }
        $old_scrub = is_array($old_fs_entry['scrub']['devices'] ?? null) ? $old_fs_entry['scrub']['devices'] : [];
        if (count($old_scrub) > 0) {
            $old_scrub_status_devices[(string) $old_fs_name] = $old_scrub;
        }
    }
}

// Persisted scrub counter/session marker for reset detection
$old_scrub_counter_state = $app->data['scrub_counter_state'] ?? [];
$device_error_seen = $app->data['device_error_seen'] ?? [];
$scrub_counter_state = [];

$expected_sensor_indexes = [
    BtrfsSensorSync::STATE_SENSOR_IO => [],
    BtrfsSensorSync::STATE_SENSOR_SCRUB => [],
    BtrfsSensorSync::STATE_SENSOR_BALANCE => [],
];
$expected_count_sensor_indexes = [
    BtrfsSensorSync::COUNT_SENSOR_IO_ERRORS => [],
];

// Overview totals across all filesystems
$overview_totals = array_fill_keys(array_keys($rrdWriter->fsSpaceDatasets), 0);
unset($overview_totals['data_ratio'], $overview_totals['metadata_ratio']);

// Per-filesystem app-level status rollups
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

// -----------------------------------------------------------------------------
// First pass: compute app-level overview totals
// -----------------------------------------------------------------------------

foreach ($fs_list as $fs_uuid => $fs_row) {
    if (! is_array($fs_row)) {
        continue;
    }
    $overall = $parser->normalizeOverall($tables, $fs_uuid);
    foreach ($overview_totals as $ds => $unused) {
        $key = $rrdWriter->fsSpaceDatasets[$ds];
        if (isset($overall[$key]) && is_numeric($overall[$key])) {
            $overview_totals[$ds] += $overall[$key];
        }
    }
}

// -----------------------------------------------------------------------------
// Main filesystem/device processing loop
// -----------------------------------------------------------------------------

foreach ($fs_by_mountpoint as $fs_name => $fs_uuid) {
    if (! isset($fs_list[$fs_uuid])) {
        continue;
    }

    $fs_names[] = $fs_name;

    $fs_info = $parser->getFsInfo($tables, $fs_uuid);
    $fs_label = $fs_info['label'];
    $fs_display_name = $fs_label !== '' ? $fs_label : ($fs_name === '/' ? 'root' : $fs_name);

    $filesystem_meta = [
        'mountpoint' => $fs_name,
        'label' => $fs_label,
        'total_devices' => $fs_info['total_devices'],
        'fs_bytes_used' => $fs_info['fs_bytes_used'],
    ];

    $fs_rrd_id = $parser->normalizeId($fs_name);
    $overall = $parser->normalizeOverall($tables, $fs_uuid);
    $fields = [];
    foreach ($rrdWriter->fsSpaceDatasets as $ds => $key) {
        $fields[$ds] = $overall[$key] ?? null;
    }

    $fs_metric_prefix = 'fs_' . $fs_rrd_id . '_';

    // Extract device data
    $devices = $parser->extractDeviceStats($tables, $fs_uuid);
    $usage_devices = $parser->extractDeviceUsage($tables, $fs_uuid);
    $usage_type_totals = $parser->extractUsageTypeTotals($tables, $fs_uuid);

    // Scrub status handling
    $fs_scrub_status = $parser->extractScrubStatus($tables, $fs_uuid);
    $scrub_bytes_scrubbed = null;
    $scrub_started = null;
    if (is_array($fs_scrub_status)) {
        $bytes_scrubbed = $fs_scrub_status['bytes_scrubbed'] ?? null;
        if (is_numeric($bytes_scrubbed)) {
            $scrub_bytes_scrubbed = (float) $bytes_scrubbed;
        }

        $scrub_started_raw = $fs_scrub_status['scrub_started'] ?? null;
        if (is_string($scrub_started_raw) && trim($scrub_started_raw) !== '') {
            $scrub_started = trim($scrub_started_raw);
        }
    }

    // Scrub device processing
    $raw_scrub_devices = $parser->extractScrubDevices($tables, $fs_uuid);
    $show_devices_by_path = $parser->extractShowDevices($tables, $fs_uuid);
    $scrub_devices = $parser->getScrubStatusDevicesForPath($raw_scrub_devices, $show_devices_by_path);

    // Scrub counter reset detection for COUNTER DS
    $scrub_bytes_for_rrd = $scrub_bytes_scrubbed;
    $previous_counter_state = $old_scrub_counter_state[$fs_name] ?? [];
    $previous_bytes = is_array($previous_counter_state) && is_numeric($previous_counter_state['bytes'] ?? null)
        ? (float) $previous_counter_state['bytes']
        : null;
    $previous_started = is_array($previous_counter_state) && is_string($previous_counter_state['scrub_started'] ?? null)
        ? trim((string) $previous_counter_state['scrub_started'])
        : '';
    if ($previous_started === '') {
        $previous_started = null;
    }

    if ($scrub_bytes_for_rrd !== null && $previous_bytes !== null) {
        $counter_reset = $scrub_bytes_for_rrd < $previous_bytes;
        $session_reset = $scrub_started !== null
            && $previous_started !== null
            && $scrub_started !== $previous_started
            && $scrub_bytes_for_rrd <= $previous_bytes;

        if ($counter_reset || $session_reset) {
            $scrub_bytes_for_rrd = null;
        }
    }

    $scrub_counter_state[$fs_name] = [
        'bytes' => $scrub_bytes_scrubbed,
        'scrub_started' => $scrub_started,
    ];

    // Balance status
    $fs_balance_status = $parser->extractBalanceStatus($tables, $fs_uuid);
    $balance_status_code = $mapper->getBalanceStatusCodeFromFlat($fs_balance_status);
    $publish_balance_state = ! empty($fs_balance_status['is_running']);

    $sys_block_metadata = [];
    $fs_has_missing = $parser->filesystemHasMissingDevice($tables, $fs_uuid);

    // Status computation
    $has_device_data = count($devices) > 0;
    $has_scrub_data = count($scrub_devices) > 0 || ! empty($fs_scrub_status);

    $io_has_error = $rrdWriter->hasDeviceError($devices);
    $scrub_has_error = false;
    $scrub_running_flag = $parser->extractRunningFlag($fs_scrub_status);
    $scrub_is_running = $scrub_running_flag === true;
    foreach ($scrub_devices as $scrub_device) {
        if ($parser->extractRunningFlag($scrub_device) === true) {
            $scrub_is_running = true;
        }

        if ($rrdWriter->hasScrubError($scrub_device)) {
            $scrub_has_error = true;
        }
    }

    $io_status_code = $mapper->getIoStatusCode($has_device_data, $io_has_error, $fs_has_missing);
    $scrub_status_code = $mapper->getScrubStatusCode($has_scrub_data, $scrub_has_error, $scrub_is_running);

    // Usage totals
    $usage_totals = $rrdWriter->sumUsageTotals($usage_devices);
    foreach ($usage_totals as $k => $v) {
        $fields[$k] = $v;
        $metrics[$fs_metric_prefix . $k] = $v;
    }

    $fs_io_errors_sum = 0.0;

    $fields[BtrfsRrdWriter::DS_SCRUB_BYTES] = $scrub_bytes_for_rrd;
    $metrics[$fs_metric_prefix . BtrfsRrdWriter::DS_SCRUB_BYTES] = $scrub_bytes_for_rrd;

    // Dynamic type RRDs
    foreach ($usage_type_totals as $type_key => $type_value) {
        $type_id = $parser->normalizeId((string) $type_key);
        $rrdWriter->writeTypeRrd($device, $name, $app->app_id, $fs_rrd_id, $type_id, $type_value);
        $metrics[$fs_metric_prefix . 'type_' . $type_id] = $type_value;
    }

    $fields[BtrfsRrdWriter::DS_IO_STATUS] = $io_status_code;
    $fields[BtrfsRrdWriter::DS_SCRUB_STATUS] = $scrub_status_code;
    $fields[BtrfsRrdWriter::DS_BALANCE_STATUS] = $balance_status_code;
    $metrics[$fs_metric_prefix . BtrfsRrdWriter::DS_IO_STATUS] = $io_status_code;
    $metrics[$fs_metric_prefix . BtrfsRrdWriter::DS_SCRUB_STATUS] = $scrub_status_code;
    $metrics[$fs_metric_prefix . BtrfsRrdWriter::DS_BALANCE_STATUS] = $balance_status_code;

    // App-level status aggregation
    $app_has_data = $app_has_data || $io_status_code !== BtrfsStatusMapper::STATUS_NA || $scrub_status_code !== BtrfsStatusMapper::STATUS_NA || $balance_status_code !== BtrfsStatusMapper::STATUS_NA;
    $app_has_missing = $app_has_missing || $io_status_code === BtrfsStatusMapper::STATUS_MISSING;
    $app_has_running = $app_has_running || $scrub_status_code === BtrfsStatusMapper::STATUS_RUNNING || $balance_status_code === BtrfsStatusMapper::STATUS_RUNNING;
    $app_has_error = $app_has_error || $io_status_code === BtrfsStatusMapper::STATUS_ERROR || $scrub_status_code === BtrfsStatusMapper::STATUS_ERROR || $balance_status_code === BtrfsStatusMapper::STATUS_ERROR || $io_status_code === BtrfsStatusMapper::STATUS_MISSING || $scrub_status_code === BtrfsStatusMapper::STATUS_MISSING || $balance_status_code === BtrfsStatusMapper::STATUS_MISSING;
    $app_io_has_data = $app_io_has_data || $io_status_code !== BtrfsStatusMapper::STATUS_NA;
    $app_io_missing = $app_io_missing || $io_status_code === BtrfsStatusMapper::STATUS_MISSING;
    $app_io_has_error = $app_io_has_error || $io_status_code === BtrfsStatusMapper::STATUS_ERROR || $io_status_code === BtrfsStatusMapper::STATUS_MISSING;
    $app_scrub_has_data = $app_scrub_has_data || $scrub_status_code !== BtrfsStatusMapper::STATUS_NA;
    $app_scrub_has_error = $app_scrub_has_error || $scrub_status_code === BtrfsStatusMapper::STATUS_ERROR;
    $app_scrub_running = $app_scrub_running || $scrub_status_code === BtrfsStatusMapper::STATUS_RUNNING;
    $app_balance_has_data = $app_balance_has_data || $balance_status_code !== BtrfsStatusMapper::STATUS_NA;
    $app_balance_has_error = $app_balance_has_error || $balance_status_code === BtrfsStatusMapper::STATUS_ERROR;
    $app_balance_running = $app_balance_running || $balance_status_code === BtrfsStatusMapper::STATUS_RUNNING;

    // Upsert filesystem state sensors
    $sensorSync->upsertStateSensor(
        $device,
        $fs_rrd_id . '.io',
        BtrfsSensorSync::STATE_SENSOR_IO,
        $fs_display_name . ' IO',
        $io_status_code,
        $io_state_index_id,
        'btrfs filesystems'
    );
    $expected_sensor_indexes[BtrfsSensorSync::STATE_SENSOR_IO][(string) $fs_rrd_id . '.io'] = true;

    $sensorSync->upsertStateSensor(
        $device,
        $fs_rrd_id . '.scrub',
        BtrfsSensorSync::STATE_SENSOR_SCRUB,
        $fs_display_name . ' Scrub',
        $scrub_status_code,
        $scrub_state_index_id,
        'btrfs filesystems'
    );
    $expected_sensor_indexes[BtrfsSensorSync::STATE_SENSOR_SCRUB][(string) $fs_rrd_id . '.scrub'] = true;

    if ($publish_balance_state) {
        $sensorSync->upsertStateSensor(
            $device,
            $fs_rrd_id . '.balance',
            BtrfsSensorSync::STATE_SENSOR_BALANCE,
            $fs_display_name . ' Balance',
            $balance_status_code,
            $balance_state_index_id,
            'btrfs filesystems'
        );
        $expected_sensor_indexes[BtrfsSensorSync::STATE_SENSOR_BALANCE][(string) $fs_rrd_id . '.balance'] = true;
    } else {
        $sensorSync->deleteStateSensor($device, $fs_rrd_id . '.balance', BtrfsSensorSync::STATE_SENSOR_BALANCE);
    }

    // Write filesystem RRD
    $rrdWriter->writeFsRrd($device, $name, $app->app_id, $fs_rrd_id, $fields);

    foreach ($fields as $field => $value) {
        $metrics[$fs_metric_prefix . $field] = $value;
    }

    // Per-device processing
    $device_map = [];
    $device_tables = [];
    $device_metadata = [];

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
        $device_numeric_id = $dev_stats['devid'] ?? $show_devices_by_path[$dev_path] ?? null;
        if (! is_scalar($device_numeric_id) || (string) $device_numeric_id === '') {
            continue;
        }
        $dev_id = (string) $device_numeric_id;

        $dev_stats['missing'] = (bool) ($dev_stats['missing'] ?? false);

        $device_map[$dev_id] = $dev_path;

        $dev_fields = $rrdWriter->buildDeviceFields($dev_stats, $scrub_stats, $usage_stats);
        $rrdWriter->writeDeviceRrd($device, $name, $app->app_id, $fs_rrd_id, $dev_id, $dev_fields);

        // Dynamic type RRDs per device
        $dev_type_values = $usage_stats['type_values'] ?? [];
        if (is_array($dev_type_values)) {
            foreach ($dev_type_values as $type_key => $type_value) {
                if (! is_numeric($type_value)) {
                    continue;
                }

                $type_id = $parser->normalizeId((string) $type_key);
                $rrdWriter->writeDevTypeRrd($device, $name, $app->app_id, $fs_rrd_id, $dev_id, $type_id, $type_value);
            }
        }

        $device_tables[$dev_id] = $rrdWriter->buildDeviceTableRow($dev_path, $device_numeric_id, $dev_stats, $usage_stats);
        $device_metadata[$dev_id] = $sys_block_metadata[$dev_path] ?? [];

        $io_errs = $rrdWriter->sumDeviceErrors($dev_stats);

        if ($io_errs > 0 && empty($device_error_seen[$fs_name][$dev_id])) {
            Eventlog::log("BTRFS device errors detected on $fs_name ($dev_path)", $device['device_id'], 'application', Severity::Error);
            $device_error_seen[$fs_name][$dev_id] = 1;
        }

        $fs_io_errors_sum += (float) $io_errs;

        $sensorSync->upsertCountSensor(
            $device,
            $fs_rrd_id . '.dev.' . $dev_id . '.io_errors',
            BtrfsSensorSync::COUNT_SENSOR_IO_ERRORS,
            $fs_display_name . ' ' . $dev_path . ' IO Errors',
            (float) $io_errs,
            'btrfs device errors'
        );
        $expected_count_sensor_indexes[BtrfsSensorSync::COUNT_SENSOR_IO_ERRORS][(string) $fs_rrd_id . '.dev.' . $dev_id . '.io_errors'] = true;

        $dev_metric_prefix = $fs_metric_prefix . 'device_' . $dev_id . '_';
        foreach ($dev_fields as $field => $value) {
            $metrics[$dev_metric_prefix . $field] = $value;
        }

        // Device status codes
        $dev_io_has_error = $rrdWriter->hasDeviceError($dev_stats);
        $dev_scrub_has_error = $rrdWriter->hasScrubError($scrub_stats);
        $dev_scrub_is_running = $parser->extractRunningFlag($scrub_stats) === true;

        $dev_io_status_code = $mapper->getDevIoStatusCode(count($dev_stats) > 0, $dev_io_has_error, $dev_stats['missing'] ?? false);
        $dev_scrub_status_code = $mapper->getDevScrubStatusCode(count($scrub_stats) > 0, $dev_scrub_has_error, $dev_scrub_is_running);

        $app_io_errors_total += $rrdWriter->sumDeviceErrors($dev_stats);
        $app_scrub_errors_total += $rrdWriter->sumScrubErrors($scrub_stats);

        $sensorSync->upsertStateSensor(
            $device,
            $fs_rrd_id . '.dev.' . $dev_id . '.io',
            BtrfsSensorSync::STATE_SENSOR_IO,
            $fs_display_name . ' ' . $dev_path . ' IO',
            $dev_io_status_code,
            $io_state_index_id,
            'btrfs devices'
        );
        $expected_sensor_indexes[BtrfsSensorSync::STATE_SENSOR_IO][(string) $fs_rrd_id . '.dev.' . $dev_id . '.io'] = true;

        $sensorSync->upsertStateSensor(
            $device,
            $fs_rrd_id . '.dev.' . $dev_id . '.scrub',
            BtrfsSensorSync::STATE_SENSOR_SCRUB,
            $fs_display_name . ' ' . $dev_path . ' Scrub',
            $dev_scrub_status_code,
            $scrub_state_index_id,
            'btrfs devices'
        );
        $expected_sensor_indexes[BtrfsSensorSync::STATE_SENSOR_SCRUB][(string) $fs_rrd_id . '.dev.' . $dev_id . '.scrub'] = true;
    }

    // Build filesystem entry for persistence
    $filesystem_entries[$fs_name] = [
        'meta' => $filesystem_meta,
        'uuid' => $fs_uuid,
        'rrd_key' => $fs_rrd_id,
        'device_map' => $device_map,
        'table' => $fields,
        'device_tables' => $device_tables,
        'device_metadata' => $device_metadata,
        'profiles' => $usage_type_totals,
        'scrub' => [
            'status' => $fs_scrub_status,
            'devices' => $raw_scrub_devices,
            'is_running' => $scrub_is_running,
        ],
        'balance' => [
            'status' => $fs_balance_status,
            'is_running' => $balance_status_code === BtrfsStatusMapper::STATUS_RUNNING,
        ],
    ];

    $fields['io_errors'] = $fs_io_errors_sum;
    $sensorSync->upsertCountSensor(
        $device,
        $fs_rrd_id . '.io_errors',
        BtrfsSensorSync::COUNT_SENSOR_IO_ERRORS,
        $fs_display_name . ' IO Errors',
        $fs_io_errors_sum,
        'btrfs filesystem errors'
    );
    $expected_count_sensor_indexes[BtrfsSensorSync::COUNT_SENSOR_IO_ERRORS][(string) $fs_rrd_id . '.io_errors'] = true;
}

// -----------------------------------------------------------------------------
// Post-loop cleanup and persistence
// -----------------------------------------------------------------------------

$sensorSync->cleanupObsoleteStateSensors($device, $expected_sensor_indexes);
$sensorSync->cleanupObsoleteCountSensors($device, $expected_count_sensor_indexes);

// Filesystem change events
$old_filesystems = is_array($app->data['filesystems'] ?? null) ? array_keys($app->data['filesystems']) : [];
$added_filesystems = array_diff($fs_names, $old_filesystems);
$removed_filesystems = array_diff($old_filesystems, $fs_names);
if (count($added_filesystems) > 0 || count($removed_filesystems) > 0) {
    $log_message = 'BTRFS Filesystem Change:';
    $log_message .= count($added_filesystems) > 0 ? ' Added ' . implode(',', $added_filesystems) : '';
    $log_message .= count($removed_filesystems) > 0 ? ' Removed ' . implode(',', $removed_filesystems) : '';
    Eventlog::log($log_message, $device['device_id'], 'application');
}

// App-level status derivation
$app_status_code = $mapper->deriveAppStatusCode($app_has_missing, $app_has_error, $app_has_running, $app_has_data);
$metrics['status_code'] = $app_status_code;
$app_io_status_code = $app_io_missing ? BtrfsStatusMapper::STATUS_MISSING : ($app_io_has_error ? BtrfsStatusMapper::STATUS_ERROR : ($app_io_has_data ? BtrfsStatusMapper::STATUS_OK : BtrfsStatusMapper::STATUS_NA));
$app_scrub_status_code = $app_scrub_has_error ? BtrfsStatusMapper::STATUS_ERROR : ($app_scrub_running ? BtrfsStatusMapper::STATUS_RUNNING : ($app_scrub_has_data ? BtrfsStatusMapper::STATUS_OK : BtrfsStatusMapper::STATUS_NA));
$app_balance_status_code = $app_balance_has_error ? BtrfsStatusMapper::STATUS_ERROR : ($app_balance_running ? BtrfsStatusMapper::STATUS_RUNNING : ($app_balance_has_data ? BtrfsStatusMapper::STATUS_OK : BtrfsStatusMapper::STATUS_NA));
$app_status_text = $mapper->getStatusText($app_status_code);

// Persist app data
$app->data = [
    'schema_version' => 5,
    'tables' => $tables,
    'filesystems' => $filesystem_entries,
    'scrub_counter_state' => $scrub_counter_state,
    'device_error_seen' => $device_error_seen,
    'btrfs_progs_version' => $btrfs['btrfs_version']['version'] ?? null,
    'btrfs_progs_features' => $btrfs['btrfs_version']['features'] ?? null,
    'status_code' => $app_status_code,
    'status_text' => $app_status_text,
    'btrfs_dev_version' => $btrfs_dev_version,
    'version' => $btrfs['version'] ?? ($all_return['version'] ?? null),
];

update_application($app, $app_status_text, $metrics);
