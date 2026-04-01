<?php

/**
 * Btrfs Plugin Common Functions
 *
 * Shared helpers for the Btrfs monitoring plugin. Provides status rendering,
 * metric formatting, sensor loading, and data extraction utilities used by both
 * the device app page and the global apps overview page.
 */

namespace LibreNMS\Plugins\Btrfs;

use Illuminate\Support\Facades\Log;

/**
 * Get filesystem discovery data by UUID.
 * Graphs pass the UUID directly in $vars['fs'].
 *
 * @param  \App\Models\Application  $app
 * @param  string|null             $uuid  Filesystem UUID
 * @return array|null              Discovery entry or null if not found
 */
function btrfs_get_discovery_by_uuid(\App\Models\Application $app, ?string $uuid): ?array
{
    if ($uuid === null) {
        return null;
    }

    return $app->data['discovery']['filesystems'][$uuid] ?? null;
}

/**
 * @deprecated Use btrfs_get_discovery_by_uuid() — $vars['fs'] is now a UUID.
 */
function btrfs_get_discovery_by_mountpoint(\App\Models\Application $app, ?string $uuid): ?array
{
    return btrfs_get_discovery_by_uuid($app, $uuid);
}

// =============================================================================
// Status Badge Rendering
// Renders Bootstrap label badges for known status states.
// =============================================================================

/**
 * Renders an HTML status badge for a given state string.
 *
 * @param  string  $state  Human-readable state (e.g., 'ok', 'error', 'running').
 * @return string          HTML span element with appropriate label class.
 */
function status_badge(string $state): string
{
    $state_lc = strtolower($state);
    if ($state_lc === 'error') {
        return '<span class="label label-danger">Error</span>';
    }
    if ($state_lc === 'missing') {
        return '<span class="label label-danger">Missing</span>';
    }
    if ($state_lc === 'running') {
        return '<span class="label label-default">Running</span>';
    }
    if ($state_lc === 'warning') {
        return '<span class="label label-warning">Warning</span>';
    }
    if ($state_lc === 'na') {
        return '<span class="label label-default">N/A</span>';
    }
    if ($state_lc === 'idle') {
        return '<span class="label label-default">Idle</span>';
    }

    return '<span class="label label-default">OK</span>';
}

// =============================================================================
// Status Code Conversion
// Maps numeric status codes to human-readable state strings.
// =============================================================================

/**
 * Converts a numeric status code to a normalized state string.
 *
 * Code semantics:
 *   0 = ok, 1 = running, -1 = na, 3 = error, 4 = missing, else = na
 *
 * @param  mixed  $value  Numeric status code (or any value).
 * @return string          Normalized state string.
 */
function status_from_code($value): string
{
    $code = is_numeric($value) ? (int) $value : 2;

    return match ($code) {
        0 => 'ok',
        1 => 'running',
        -1 => 'na',
        3 => 'error',
        4 => 'missing',
        default => 'na',
    };
}

/**
 * Converts a normalized state string to a status code.
 *
 * @param  string  $state  Normalized state string.
 * @return int             Numeric status code (0=ok, 1=running, 2=warning, 3=error, 4=missing).
 */
function state_to_code(string $state): int
{
    return match ($state) {
        'ok' => 0,
        'running' => 1,
        'warning' => 2,
        'error' => 3,
        'missing' => 4,
        default => 2,
    };
}

// =============================================================================
// State Sensor Helpers
// Extract and combine state codes from live sensors and stored poller data.
// =============================================================================

/**
 * Retrieves a state sensor value for a given sensor type and index.
 *
 * Looks up the value from the pre-loaded state sensor array. Falls back to the
 * provided fallback value if the sensor is not found or the value is non-numeric.
 *
 * @param  array        $state_sensor_values  Pre-loaded sensor values keyed by type and index.
 * @param  string       $sensor_type         Sensor type (e.g., 'btrfsIoStatusState').
 * @param  string       $sensor_index        Sensor index within that type.
 * @param  mixed        $fallback            Default code to return if sensor not found.
 * @return int                               Numeric status code (0-4).
 */
function state_code_from_sensor(array $state_sensor_values, string $sensor_type, string $sensor_index, $fallback = null): int
{
    if (isset($state_sensor_values[$sensor_type][$sensor_index]) && is_numeric($state_sensor_values[$sensor_type][$sensor_index])) {
        return (int) $state_sensor_values[$sensor_type][$sensor_index];
    }

    Log::debug('Btrfs state_code_from_sensor: using fallback (normal when no sensor data)', [
        'sensor_type' => $sensor_type,
        'sensor_index' => $sensor_index,
        'fallback' => $fallback,
    ]);

    return is_numeric($fallback) ? (int) $fallback : 2;
}

/**
 * Converts a running flag (boolean) to a state code.
 *
 * Converts a boolean running indicator into a status code where true=1 (running)
 * and false=0 (not running/idle). Falls back to provided default if value is not boolean.
 *
 * @param  mixed  $running_flag  Boolean running indicator.
 * @param  mixed  $fallback      Default code to return if not boolean.
 * @return int                    Numeric status code (0 or 1, or fallback).
 */
function state_code_from_running_flag($running_flag, $fallback = null): int
{
    if (is_bool($running_flag)) {
        return $running_flag ? 1 : 0;
    }

    Log::debug('Btrfs state_code_from_running_flag: using fallback (normal when no running flag)', [
        'running_flag' => $running_flag,
        'fallback' => $fallback,
    ]);

    return is_numeric($fallback) ? (int) $fallback : 2;
}

/**
 * Combines multiple status codes into a single overall status code.
 *
 * Priority: missing(4) > error(3) > running(1) > ok(0) > na(2)
 * Returns the highest-priority code present in the input array.
 *
 * @param  array  $codes  Array of numeric status codes.
 * @return int             Combined status code.
 */
function combine_state_code(array $codes): int
{
    $normalized = [];
    foreach ($codes as $code) {
        $normalized[] = is_numeric($code) ? (int) $code : 2;
    }

    if (in_array(4, $normalized, true)) {
        return 4;
    }
    if (in_array(3, $normalized, true)) {
        return 3;
    }
    if (in_array(1, $normalized, true)) {
        return 1;
    }
    if (in_array(0, $normalized, true)) {
        return 0;
    }

    return 2;
}

// =============================================================================
// Scrub Status Helpers
// Parse and format scrub operation status information.
// =============================================================================

/**
 * Extracts a human-readable scrub progress percentage from scrub status data.
 *
 * Attempts to extract progress from the 'bytes_scrubbed.progress' field first,
 * then falls back to calculating from bytes_scrubbed/total_to_scrub ratio.
 *
 * @param  array   $scrub_status  Scrub status array from poller data.
 * @return string                  Progress as percentage string or 'N/A'.
 */
function scrub_progress_text_from_status(array $scrub_status): string
{
    $scrub_progress = null;

    // First try: use the reported progress percentage directly.
    if (is_array($scrub_status['bytes_scrubbed'] ?? null)) {
        $progress = $scrub_status['bytes_scrubbed']['progress'] ?? null;
        if (is_numeric($progress)) {
            $scrub_progress = (float) $progress;
        }
    }

    // Second try: calculate progress from byte counts if percentage not available.
    if ($scrub_progress === null) {
        $bytes_scrubbed = $scrub_status['bytes_scrubbed'] ?? null;
        if (is_array($bytes_scrubbed)) {
            $bytes_scrubbed = $bytes_scrubbed['bytes'] ?? null;
        }
        $total_to_scrub = $scrub_status['total_to_scrub'] ?? null;
        if (is_numeric($bytes_scrubbed) && is_numeric($total_to_scrub) && (float) $total_to_scrub > 0) {
            $scrub_progress = ((float) $bytes_scrubbed / (float) $total_to_scrub) * 100;
        }
    }

    // Return formatted percentage or N/A indicator.
    if ($scrub_progress === null) {
        return 'N/A';
    }

    return rtrim(rtrim(number_format($scrub_progress, 2, '.', ''), '0'), '.') . '%';
}

/**
 * Converts a scrub status string to a normalized state string.
 *
 * Maps various btrfs scrub status values to the plugin's standard states.
 *
 * @param  string  $status  Raw scrub status string from poller.
 * @return string            Normalized state: 'running', 'ok', 'error', or 'na'.
 */
function scrub_status_to_state(string $status): string
{
    $status_lc = strtolower(trim((string) $status));

    return match ($status_lc) {
        'running', 'in_progress', 'in-progress' => 'running',
        'finished', 'done', 'idle', 'stopped', 'completed' => 'ok',
        'error', 'failed', 'aborted' => 'error',
        default => 'na',
    };
}

/**
 * Computes scrub display state from operation and health codes.
 *
 * Combines operation (0=idle, 1=running) and health (0=ok, 1=warning, 2=error)
 * into a normalized status state for display.
 *
 * @param  int  $operation  Scrub operation code: 0=idle, 1=running
 * @param  int  $health    Scrub health code: 0=ok, 1=warning, 2=error
 * @return string          Normalized state: 'running', 'ok', 'warning', 'error', or 'na'
 */
function scrub_state_from_operation_health(int $operation, int $health): string
{
    if ($operation === 1 && $health === 2) {
        return 'running';
    }
    if ($operation === 1) {
        return 'running';
    }
    if ($health === 2) {
        return 'error';
    }
    if ($health === 1) {
        return 'warning';
    }

    return 'ok';
}

function scrub_operation_state(int $operation): string
{
    return $operation === 1 ? 'running' : 'idle';
}

function scrub_health_state(int $health): string
{
    return match ($health) {
        0 => 'ok',
        1 => 'warning',
        2 => 'error',
        default => 'ok',
    };
}

function scrub_ops_badge(int $operation): string
{
    if ($operation === 1) {
        return '<span class="label label-default">Running</span>';
    }

    return '<span class="label label-default">Idle</span>';
}

function scrub_health_badge(int $health): string
{
    return match ($health) {
        0 => '<span class="label label-default">OK</span>',
        1 => '<span class="label label-warning">Warning</span>',
        2 => '<span class="label label-danger">Error</span>',
        default => '<span class="label label-default">OK</span>',
    };
}

// =============================================================================
// Metric Formatting
// Format raw metric values for display with appropriate units.
// =============================================================================

/**
 * Determines if a metric name represents a byte-based value.
 *
 * Used to decide whether to apply binary unit formatting (KiB, MiB, GiB, etc.).
 *
 * @param  string  $metric  Metric name/key from RRD data.
 * @return bool             True if metric represents byte storage.
 */
function is_byte_metric(string $metric): bool
{
    return str_contains($metric, 'size')
        || str_contains($metric, 'used')
        || str_contains($metric, 'free')
        || str_contains($metric, 'reserve')
        || str_contains($metric, 'slack')
        || str_contains($metric, 'allocated')
        || str_contains($metric, 'unallocated')
        || str_starts_with($metric, 'usage.')
        || str_contains($metric, 'bytes')
        || str_starts_with($metric, 'data_')
        || str_starts_with($metric, 'metadata_')
        || str_starts_with($metric, 'system_');
}

/**
 * Determines if a metric name represents an error counter.
 *
 * Error metrics receive integer formatting without decimal places.
 *
 * @param  string  $metric  Metric name/key.
 * @return bool              True if metric is an error counter.
 */
function is_error_metric(string $metric): bool
{
    return str_contains($metric, 'errs')
        || str_contains($metric, 'errors')
        || str_contains($metric, 'devid')
        || $metric === 'id';
}

/**
 * Formats a metric value for display with appropriate formatting rules.
 *
 * Applies different formatting based on metric type:
 * - Byte metrics: binary units (B, KiB, MiB, GiB, TiB)
 * - Error metrics: integer with thousand separators
 * - Count metrics with SI suffixes: standard SI formatting
 * - Ratios: two decimal places
 * - Durations with colons: passthrough (HH:MM:SS format)
 * - Integers/floats: locale-aware number formatting
 *
 * @param  mixed   $value   Raw metric value.
 * @param  string  $metric  Metric name/key for formatting context.
 * @return string            Formatted value for display.
 */
function format_metric_value($value, string $metric): string
{
    if ($value === null) {
        return '';
    }

    if ($value === 'null') {
        return '-';
    }

    // Boolean passthrough for true/false states.
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    // Duration/time_left strings are passed through unchanged (before ratio check
    // because 'duration' contains 'ratio' as substring).
    $time_duration_metrics = ['duration', 'time_left'];
    if (in_array($metric, $time_duration_metrics, true) && is_string($value)) {
        return $value === '' ? 'N/A' : $value;
    }

    // Error metrics are always displayed as whole integers (before ratio check
    // because 'generation_errs' contains 'ratio' as substring in 'generation').
    if (is_error_metric($metric) && is_numeric($value)) {
        return number_format((int) round((float) $value));
    }

    // Ratio metrics get two decimal places.
    $ratio_metrics = ['data_ratio', 'metadata_ratio'];
    if (in_array($metric, $ratio_metrics, true)) {
        return number_format((float) $value, 2);
    }

    // Count metrics that benefit from SI suffixes.
    $si_count_metrics = [
        'data_extents_scrubbed',
        'tree_extents_scrubbed',
        'no_csum',
    ];
    if (in_array($metric, $si_count_metrics, true) && is_numeric($value)) {
        return \LibreNMS\Util\Number::formatSi((float) $value, 2, 0, '');
    }

    // Byte-based metrics get binary unit formatting.
    if (is_byte_metric($metric)) {
        return \LibreNMS\Util\Number::formatBi((float) $value, 2, 0, 'B');
    }

    // Pure integer values get locale-aware thousand separators.
    if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) {
        return number_format((int) $value);
    }

    // Float values get two decimal places with trailing zeros stripped.
    if (is_float($value) || (is_string($value) && preg_match('/^-?\d+\.\d+$/', $value))) {
        $formatted = number_format((float) $value, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    return (string) $value;
}

/**
 * Converts a metric key to a human-readable display name.
 *
 * Transforms snake_case/dotted keys like 'device_size' or 'bytes_scrubbed.bytes'
 * into 'Device Size' or 'Bytes Scrubbed Bytes' for table display.
 *
 * @param  string  $key  Raw metric or config key.
 * @return string        Human-readable name.
 */
function format_display_name(string $key): string
{
    $name = preg_replace('/\[([0-9]+)\]/', ' $1', $key);
    $name = str_replace(['.', '_', '-'], ' ', (string) $name);

    return ucwords((string) $name);
}

// =============================================================================
// Data Transformation
// Helpers for flattening and extracting structured data.
// =============================================================================

/**
 * Flattens a nested associative array into an array of key-value rows.
 *
 * Recursively traverses nested arrays, building dot-notation path keys.
 * Useful for converting nested config/status blocks into flat table rows.
 *
 * @param  array   $data   Nested associative array to flatten.
 * @param  string  $prefix  Dot-notation path prefix for nested keys.
 * @return array           Flat array of ['key' => path, 'value' => string_value].
 */
function flatten_assoc_rows(array $data, string $prefix = ''): array
{
    $rows = [];
    foreach ($data as $key => $value) {
        $segment = is_int($key) ? '[' . $key . ']' : (string) $key;
        $path = $prefix === '' ? $segment : $prefix . '.' . $segment;

        // Recurse into nested arrays with accumulated path.
        if (is_array($value)) {
            $rows = array_merge($rows, flatten_assoc_rows($value, $path));
            continue;
        }

        // Convert scalar values to strings with type-specific representations.
        if (is_bool($value)) {
            $rows[] = ['key' => $path, 'value' => $value ? 'true' : 'false'];
        } elseif ($value === null) {
            $rows[] = ['key' => $path, 'value' => 'null'];
        } else {
            $rows[] = ['key' => $path, 'value' => (string) $value];
        }
    }

    return $rows;
}

/**
 * Sums all I/O error counters across all devices in a filesystem.
 *
 * Aggregates corruption_errs, flush_io_errs, generation_errs, read_io_errs,
 * and write_io_errs from each device's error block.
 *
 * @param  array   $device_tables  Device tables array keyed by dev_id.
 * @return float                    Total error count across all devices.
 */
function total_io_errors(array $device_tables): float
{
    $total_errors = 0.0;
    foreach ($device_tables as $dev_stats) {
        $errors = is_array($dev_stats['errors'] ?? null) ? $dev_stats['errors'] : [];
        $total_errors += (float) ($errors['corruption_errs'] ?? 0)
            + (float) ($errors['flush_io_errs'] ?? 0)
            + (float) ($errors['generation_errs'] ?? 0)
            + (float) ($errors['read_io_errs'] ?? 0)
            + (float) ($errors['write_io_errs'] ?? 0);
    }

    return $total_errors;
}

/**
 * Calculates used space as a percentage of total size.
 *
 * @param  mixed   $used_value   Used bytes.
 * @param  mixed   $size_value   Total size in bytes.
 * @return string                 Percentage string or 'N/A' if size is invalid.
 */
function used_percent_text($used_value, $size_value): string
{
    $used = (float) ($used_value ?? 0);
    $size = (float) ($size_value ?? 0);

    if ($size <= 0) {
        return 'N/A';
    }

    return rtrim(rtrim(number_format(($used / $size) * 100, 2, '.', ''), '0'), '.') . '%';
}

function build_sum_expr(array $ids): ?string
{
    if (count($ids) === 0) {
        return null;
    }

    $expr = array_shift($ids);
    foreach ($ids as $id) {
        $expr .= ',' . $id . ',+';
    }

    return $expr;
}

// =============================================================================
// State Sensor Loading
// Retrieves live state sensor values from the database.
// =============================================================================

/**
 * Loads state sensor values for btrfs operations from the database.
 *
 * Queries the sensors table for agent-based state sensors of type
 * btrfsIoStatusState, btrfsScrubStatusState, and btrfsBalanceStatusState.
 * Returns a nested array keyed by [sensor_type][sensor_index].
 *
 * @param  int     $device_id  Device ID to load sensors for.
 * @return array               Sensor values keyed by type and index.
 */
function load_state_sensors(int $device_id): array
{
    $state_sensor_values = [];
    $btrfs_state_sensors = \App\Models\Sensor::where('device_id', $device_id)
        ->where('sensor_class', 'state')
        ->where('poller_type', 'agent')
        ->whereIn('sensor_type', ['btrfsIoStatusState', 'btrfsScrubStatusState', 'btrfsScrubOpsState', 'btrfsBalanceStatusState'])
        ->get(['sensor_type', 'sensor_index', 'sensor_current']);
    foreach ($btrfs_state_sensors as $state_sensor) {
        $state_sensor_values[$state_sensor->sensor_type][$state_sensor->sensor_index] = (int) $state_sensor->sensor_current;
    }

    return $state_sensor_values;
}

// =============================================================================
// Disk I/O Matching
// Maps btrfs devices to system disk I/O entries.
// =============================================================================

/**
 * Finds the matching ucd_diskio entry for a selected btrfs device.
 *
 * Tries multiple path variants of the btrfs device (full path, /dev/ prefix,
 * basename, backing device names) against recorded diskio descriptions.
 * Falls back to the only diskio entry if there's exactly one match.
 *
 * @param  int      $device_id       Device ID for diskio lookup.
 * @param  array    $device_tables   Device tables keyed by fs_name.
 * @param  string|null $selected_fs  Selected filesystem name.
 * @param  string|null $selected_dev Selected device ID.
 * @param  array    $device_metadata Device metadata including backing info.
 * @return array|null                Matching diskio row or null if not found.
 */
function find_diskio(
    int $device_id,
    array $device_tables,
    ?string $selected_fs,
    ?string $selected_dev,
    array $device_metadata
): ?array {
    // Require both filesystem and device selection.
    if (! isset($selected_fs, $selected_dev)) {
        return null;
    }

    // Get the device path from device_tables for this filesystem.
    $selected_dev_path = trim((string) ($device_tables[$selected_fs][$selected_dev]['path'] ?? ''));
    if ($selected_dev_path === '') {
        return null;
    }

    // Build list of candidate path strings to match against diskio descriptions.
    $diskio_candidates = [];
    $preferred_diskio_candidates = [];

    // Add various path representations for matching.
    $diskio_candidates[] = $selected_dev_path;
    $without_dev_prefix = preg_replace('#^/dev/#', '', $selected_dev_path);
    if ($without_dev_prefix !== '') {
        $diskio_candidates[] = $without_dev_prefix;
    }
    $diskio_candidates[] = basename($selected_dev_path);

    // Include backing device names with higher priority.
    $selected_dev_metadata = $device_metadata[$selected_fs][$selected_dev] ?? [];
    if (is_array($selected_dev_metadata)) {
        $primary_meta = $selected_dev_metadata['primary'] ?? [];
        $backing_meta = $selected_dev_metadata['backing'] ?? [];
        $backing_path = trim((string) ($selected_dev_metadata['backing_path'] ?? ''));

        // Add backing path candidates if available.
        if ($backing_path !== '') {
            $diskio_candidates[] = $backing_path;
            $diskio_candidates[] = preg_replace('#^/dev/#', '', $backing_path);
            $diskio_candidates[] = basename($backing_path);
            // Backing device is preferred match.
            $preferred_diskio_candidates[] = $backing_path;
            $preferred_diskio_candidates[] = basename($backing_path);
        }

        // Primary device node path variants.
        $primary_devnode = trim((string) ($primary_meta['devnode'] ?? ''));
        if ($primary_devnode !== '') {
            $diskio_candidates[] = $primary_devnode;
            $diskio_candidates[] = ltrim(preg_replace('#^/dev/#', '', $primary_devnode), '/');
            $diskio_candidates[] = basename($primary_devnode);
        }

        // Primary device name (e.g., 'sda').
        $primary_name = trim((string) ($primary_meta['name'] ?? ''));
        if ($primary_name !== '') {
            $diskio_candidates[] = $primary_name;
            $diskio_candidates[] = '/dev/' . $primary_name;
        }

        // Backing device is preferred match (for loopback/qemu setups).
        $backing_name = trim((string) ($backing_meta['name'] ?? ''));
        if ($backing_name !== '') {
            $preferred_diskio_candidates[] = $backing_name;
            $preferred_diskio_candidates[] = '/dev/' . $backing_name;
            $diskio_candidates[] = $backing_name;
            $diskio_candidates[] = '/dev/' . $backing_name;
        }

        // Backing device node path variants.
        $backing_devnode = trim((string) ($backing_meta['devnode'] ?? ''));
        if ($backing_devnode !== '') {
            $preferred_diskio_candidates[] = $backing_devnode;
            $preferred_diskio_candidates[] = ltrim(preg_replace('#^/dev/#', '', $backing_devnode), '/');
            $preferred_diskio_candidates[] = basename($backing_devnode);
            $diskio_candidates[] = $backing_devnode;
            $diskio_candidates[] = ltrim(preg_replace('#^/dev/#', '', $backing_devnode), '/');
            $diskio_candidates[] = basename($backing_devnode);
        }
    }

    // Deduplicate candidates and prioritize backing device matches.
    $diskio_candidates = array_values(array_unique($diskio_candidates));
    $preferred_diskio_candidates = array_values(array_unique(array_merge($preferred_diskio_candidates, $diskio_candidates)));

    // Load all diskio entries for this device.
    $diskio_rows = \dbFetchRows('SELECT `diskio_id`, `diskio_descr` FROM `ucd_diskio` WHERE `device_id` = ?', [$device_id]);
    $diskio_by_descr = [];
    foreach ($diskio_rows as $diskio_row) {
        $diskio_descr = trim((string) ($diskio_row['diskio_descr'] ?? ''));
        if ($diskio_descr !== '') {
            $diskio_by_descr[$diskio_descr] = $diskio_row;
        }
    }

    // Try preferred candidates first (backing devices).
    foreach ($preferred_diskio_candidates as $candidate) {
        if (isset($diskio_by_descr[$candidate])) {
            return $diskio_by_descr[$candidate];
        }
    }

    // Fallback: return only diskio if there's exactly one.
    if (count($diskio_rows) === 1) {
        return $diskio_rows[0];
    }

    return null;
}

// =============================================================================
// Disk I/O Graph Rendering
// Renders disk I/O performance graphs.
// =============================================================================

/**
 * Renders disk I/O graphs for a matched diskio entry.
 *
 * Outputs two graphs: operations per second and bytes per second.
 *
 * @param  array   $selected_diskio  Matching diskio row with diskio_id and diskio_descr.
 * @return void
 */
function render_diskio_graphs(array $selected_diskio): void
{
    $diskio_id = $selected_diskio['diskio_id'];
    $diskio_descr = trim((string) ($selected_diskio['diskio_descr'] ?? ''));
    $diskio_label = $diskio_descr !== '' ? $diskio_descr : (string) $diskio_id;

    // Define graph types to render.
    $diskio_types = [
        'diskio_ops' => 'Disk I/O Ops/sec',
        'diskio_bits' => 'Disk I/O bps',
    ];

    foreach ($diskio_types as $diskio_type => $diskio_title) {
        $graph_array = [
            'height' => '100',
            'width' => '215',
            'to' => \App\Facades\LibrenmsConfig::get('time.now'),
            'id' => $diskio_id,
            'type' => $diskio_type,
        ];

        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><h3 class="panel-title">' . htmlspecialchars($diskio_title . ': ' . $diskio_label) . '</h3></div>';
        echo '<div class="panel-body"><div class="row">';
        include 'includes/html/print-graphrow.inc.php';
        echo '</div></div>';
        echo '</div>';
    }
}

/**
 * Renders filesystem-level aggregate disk I/O graphs.
 *
 * Outputs aggregate graphs combining all devices in a filesystem.
 *
 * @param  \App\Models\Application  $app         Btrfs application model.
 * @param  string                    $selected_fs Selected filesystem name.
 * @return void
 */
function render_fs_diskio_graphs(\App\Models\Application $app, string $selected_fs): void
{
    // Define aggregate graph types.
    $diskio_types = [
        'btrfs_fs_diskio_ops' => 'Aggregate Ops/sec',
        'btrfs_fs_diskio_bits' => 'Aggregate Bps',
    ];

    foreach ($diskio_types as $graph_type => $graph_title) {
        $graph_array = [
            'height' => '100',
            'width' => '215',
            'to' => \App\Facades\LibrenmsConfig::get('time.now'),
            'id' => $app['app_id'],
            'fs' => $selected_fs,
            'type' => 'application_' . $graph_type,
        ];

        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><h3 class="panel-title">' . htmlspecialchars($graph_title) . '</h3></div>';
        echo '<div class="panel-body"><div class="row">';
        include 'includes/html/print-graphrow.inc.php';
        echo '</div></div>';
        echo '</div>';
    }
}

// =============================================================================
// Data Extraction
// Extract and normalize filesystem data from poller output.
// =============================================================================

/**
 * Extracts normalized filesystem data arrays from poller output.
 *
 * Takes the raw 'filesystems' array from app->data and produces flat, indexed
 * arrays for filesystems, metadata, device mappings, and status blocks.
 * Handles both structured (new) and unstructured (legacy) data formats.
 *
 * @param  array   $filesystem_entries  Raw filesystems array from poller.
 * @return array                          Normalized data arrays.
 */
function extract_filesystem_data(array $filesystem_entries, array $discovery_filesystems = [], array $tables_filesystems = []): array
{
    Log::debug('Btrfs extract_filesystem_data: start', [
        'entries_count' => count($filesystem_entries),
        'discovery_count' => count($discovery_filesystems),
        'tables_count' => count($tables_filesystems),
    ]);

    // Initialize all output arrays.
    $filesystems = [];
    $fs_mountpoint = [];
    $filesystem_meta = [];
    $device_map = [];
    $filesystem_tables = [];
    $device_tables = [];
    $device_metadata = [];
    $filesystem_profiles = [];
    $scrub_status_fs = [];
    $scrub_status_devices = [];
    $balance_status_fs = [];
    $scrub_is_running_fs = [];
    $scrub_operation_fs = [];
    $scrub_health_fs = [];
    $balance_is_running_fs = [];
    $filesystem_uuid = [];
    $fs_rrd_key = [];

    // Build mountpoint-to-UUID mapping from tables/filesystems
    $mountpoint_to_uuid = [];
    foreach ($tables_filesystems as $uuid => $fs_entry) {
        $mountpoint = $fs_entry['mountpoint'] ?? null;
        if ($mountpoint !== null) {
            $mountpoint_to_uuid[$mountpoint] = $uuid;
        }
    }

    // Build UUID-to-mountpoint mapping
    $uuid_to_mountpoint = array_flip($mountpoint_to_uuid);

    // If filesystem_entries is keyed by mountpoint, convert to UUID-based
    $uuid_keyed_entries = [];
    if (! empty($filesystem_entries)) {
        $first_key = array_key_first($filesystem_entries);
        // Check if first key looks like a UUID (36 chars with hyphens)
        if (is_string($first_key) && strlen($first_key) === 36 && substr_count($first_key, '-') === 4) {
            // Already UUID-keyed
            $uuid_keyed_entries = $filesystem_entries;
        } else {
            // Keyed by mountpoint, convert to UUID
            foreach ($filesystem_entries as $mountpoint => $entry) {
                $uuid = $mountpoint_to_uuid[$mountpoint] ?? null;
                if ($uuid !== null) {
                    $uuid_keyed_entries[$uuid] = $entry;
                } else {
                    Log::warning('Btrfs extract_filesystem_data: no UUID for mountpoint', [
                        'mountpoint' => $mountpoint,
                    ]);
                }
            }
        }
    }

    // Extract data from each filesystem entry keyed by UUID.
    foreach ($uuid_keyed_entries as $fs_uuid => $entry) {
        if (! is_array($entry)) {
            Log::debug('Btrfs extract_filesystem_data: skipping non-array entry', [
                'fs_uuid' => $fs_uuid,
                'entry_type' => gettype($entry),
            ]);
            continue;
        }

        // Register filesystem UUID
        $filesystems[] = $fs_uuid;
        $fs_mountpoint[$fs_uuid] = $uuid_to_mountpoint[$fs_uuid] ?? $fs_uuid;
        $filesystem_meta[$fs_uuid] = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        $device_map[$fs_uuid] = is_array($entry['device_map'] ?? null) ? $entry['device_map'] : [];
        $filesystem_tables[$fs_uuid] = is_array($entry['table'] ?? null) ? $entry['table'] : [];
        $device_tables[$fs_uuid] = is_array($entry['device_tables'] ?? null) ? $entry['device_tables'] : [];
        $device_metadata[$fs_uuid] = is_array($entry['device_metadata'] ?? null) ? $entry['device_metadata'] : [];
        $filesystem_profiles[$fs_uuid] = is_array($entry['profiles'] ?? null) ? $entry['profiles'] : [];

        // Extract scrub status block.
        $scrub_block = is_array($entry['scrub'] ?? null) ? $entry['scrub'] : [];
        $balance_block = is_array($entry['balance'] ?? null) ? $entry['balance'] : [];
        $scrub_status_fs[$fs_uuid] = is_array($scrub_block['status'] ?? null) ? $scrub_block['status'] : [];
        $scrub_status_devices[$fs_uuid] = is_array($scrub_block['devices'] ?? null) ? $scrub_block['devices'] : [];
        $scrub_is_running_fs[$fs_uuid] = (bool) ($scrub_block['is_running'] ?? false);
        $scrub_operation_fs[$fs_uuid] = (int) ($scrub_block['operation'] ?? 0);
        $scrub_health_fs[$fs_uuid] = (int) ($scrub_block['health'] ?? 0);

        // Extract balance status block.
        $balance_status_fs[$fs_uuid] = is_array($balance_block['status'] ?? null) ? $balance_block['status'] : [];
        $balance_is_running_fs[$fs_uuid] = (bool) ($balance_block['is_running'] ?? false);

        // Extract UUID and RRD key for filesystem.
        $filesystem_uuid[$fs_uuid] = (string) ($entry['uuid'] ?? $fs_uuid);
        // rrd_key comes from discovery cache
        $fs_rrd_key[$fs_uuid] = (string) ($discovery_filesystems[$fs_uuid]['rrd_key'] ?? substr($fs_uuid, 0, 8));

        Log::debug('Btrfs extract_filesystem_data: processed entry', [
            'fs_uuid' => $fs_uuid,
            'mountpoint' => $fs_mountpoint[$fs_uuid],
            'device_map_count' => count($device_map[$fs_uuid] ?? []),
            'device_tables_count' => count($device_tables[$fs_uuid] ?? []),
        ]);
    }

    if (empty($filesystems)) {
        Log::error('Btrfs extract_filesystem_data: no filesystems extracted');
    } else {
        Log::debug('Btrfs extract_filesystem_data: done', [
            'filesystems' => $filesystems,
            'total' => count($filesystems),
        ]);
    }

    // Sort filesystems by mountpoint for consistent display
    $sorted_filesystems = $filesystems;
    usort($sorted_filesystems, static function ($a, $b) use ($fs_mountpoint) {
        return strcmp($fs_mountpoint[$a] ?? $a, $fs_mountpoint[$b] ?? $b);
    });

    return [
        'filesystems' => $sorted_filesystems,
        'fs_mountpoint' => $fs_mountpoint,
        'filesystem_meta' => $filesystem_meta,
        'device_map' => $device_map,
        'filesystem_tables' => $filesystem_tables,
        'device_tables' => $device_tables,
        'device_metadata' => $device_metadata,
        'filesystem_profiles' => $filesystem_profiles,
        'scrub_status_fs' => $scrub_status_fs,
        'scrub_status_devices' => $scrub_status_devices,
        'balance_status_fs' => $balance_status_fs,
        'scrub_is_running_fs' => $scrub_is_running_fs,
        'scrub_operation_fs' => $scrub_operation_fs,
        'scrub_health_fs' => $scrub_health_fs,
        'balance_is_running_fs' => $balance_is_running_fs,
        'filesystem_uuid' => $filesystem_uuid,
        'fs_rrd_key' => $fs_rrd_key,
    ];
}

// =============================================================================
// Data Initialization
// Initialize page state from app data and URL parameters.
// =============================================================================

/**
 * Initializes the complete data array for page rendering.
 *
 * Combines filesystem extraction with URL parameter parsing to determine
 * the current view (overview, per-filesystem, or per-device) and resolve
 * which filesystem/device is selected.
 *
 * @param  \App\Models\Application  $app     Btrfs application model.
 * @param  array                    $device  Device array.
 * @param  array                    $vars    URL parameters (fs, dev).
 * @return array                          Complete data array with selection state.
 */
function initialize_data(\App\Models\Application $app, array $device, array $vars): array
{
    // Extract filesystem/device selection from URL parameters.
    $selected_fs = $vars['fs'] ?? null;
    $selected_dev = $vars['dev'] ?? null;

    Log::debug('Btrfs initialize_data: start', [
        'device_id' => $device['device_id'] ?? null,
        'selected_fs' => $selected_fs,
        'selected_dev' => $selected_dev,
    ]);

    // Get discovery and tables data
    $discovery_filesystems = $app->data['discovery']['filesystems'] ?? [];
    $tables_filesystems = $app->data['tables']['filesystems'] ?? [];
    $filesystem_entries = $app->data['filesystems'] ?? [];
    $has_structured_filesystems = is_array($filesystem_entries) && count($filesystem_entries) > 0 && is_array(reset($filesystem_entries));

    Log::debug('Btrfs initialize_data: filesystem check', [
        'has_structured' => $has_structured_filesystems,
        'entries_count' => count($filesystem_entries),
        'discovery_count' => count($discovery_filesystems),
        'first_key' => is_array($filesystem_entries) ? array_key_first($filesystem_entries) : null,
        'first_val_type' => is_array($filesystem_entries) ? gettype(reset($filesystem_entries)) : null,
    ]);

    // Build mountpoint-to-UUID mapping from tables/filesystems
    $mountpoint_to_uuid = [];
    foreach ($tables_filesystems as $uuid => $fs_entry) {
        $mountpoint = $fs_entry['mountpoint'] ?? null;
        if ($mountpoint !== null) {
            $mountpoint_to_uuid[$mountpoint] = $uuid;
        }
    }

    // Build UUID-to-mountpoint mapping
    $uuid_to_mountpoint = array_flip($mountpoint_to_uuid);

    // Extract filesystem data if structured format is available.
    if ($has_structured_filesystems) {
        Log::debug('Btrfs initialize_data: using structured format');
        $extracted = extract_filesystem_data($filesystem_entries, $discovery_filesystems, $tables_filesystems);
        $filesystems = $extracted['filesystems'];
        $fs_mountpoint = $extracted['fs_mountpoint'];
        $filesystem_meta = $extracted['filesystem_meta'];
        $device_map = $extracted['device_map'];
        $filesystem_tables = $extracted['filesystem_tables'];
        $device_tables = $extracted['device_tables'];
        $device_metadata = $extracted['device_metadata'];
        $filesystem_profiles = $extracted['filesystem_profiles'];
        $scrub_status_fs = $extracted['scrub_status_fs'];
        $scrub_status_devices = $extracted['scrub_status_devices'];
        $balance_status_fs = $extracted['balance_status_fs'];
        $scrub_is_running_fs = $extracted['scrub_is_running_fs'];
        $scrub_operation_fs = $extracted['scrub_operation_fs'];
        $scrub_health_fs = $extracted['scrub_health_fs'];
        $balance_is_running_fs = $extracted['balance_is_running_fs'];
        $filesystem_uuid = $extracted['filesystem_uuid'];
        $fs_rrd_key = $extracted['fs_rrd_key'];

        Log::debug('Btrfs initialize_data: extracted', [
            'filesystems_count' => count($filesystems),
            'device_map_count' => count($device_map),
            'filesystem_tables_count' => count($filesystem_tables),
        ]);
    } else {
        Log::error('Btrfs initialize_data: USING FALLBACK - not structured format', [
            'device_id' => $device['device_id'] ?? null,
            'entries_count' => count($filesystem_entries),
            'first_key' => is_array($filesystem_entries) ? array_key_first($filesystem_entries) : null,
            'first_val_type' => is_array($filesystem_entries) ? gettype(reset($filesystem_entries)) : null,
        ]);
        // Initialize empty arrays for legacy/unstructured data.
        $filesystems = [];
        $fs_mountpoint = [];
        $filesystem_meta = [];
        $device_map = [];
        $filesystem_tables = [];
        $device_tables = [];
        $device_metadata = [];
        $filesystem_profiles = [];
        $scrub_status_fs = [];
        $scrub_status_devices = [];
        $balance_status_fs = [];
        $scrub_is_running_fs = [];
        $scrub_operation_fs = [];
        $scrub_health_fs = [];
        $balance_is_running_fs = [];
        $filesystem_uuid = [];
        $fs_rrd_key = [];
    }

    // Resolve selected_fs: it can be UUID or mountpoint
    if (is_string($selected_fs) && $selected_fs !== '') {
        // Check if it's already a valid UUID in the filesystems list
        if (in_array($selected_fs, $filesystems, true)) {
            // Already a valid UUID
            Log::debug('Btrfs initialize_data: selected_fs is valid UUID', ['selected_fs' => $selected_fs]);
        } elseif (isset($mountpoint_to_uuid[$selected_fs])) {
            // It's a mountpoint, convert to UUID
            $selected_fs = $mountpoint_to_uuid[$selected_fs];
            Log::debug('Btrfs initialize_data: converted mountpoint to UUID', [
                'mountpoint' => $vars['fs'],
                'uuid' => $selected_fs,
            ]);
        } else {
            // Not found, clear selection
            Log::debug('Btrfs initialize_data: selected_fs not found', [
                'selected_fs' => $selected_fs,
                'filesystems' => $filesystems,
            ]);
            $selected_fs = null;
        }
    } else {
        $selected_fs = null;
    }

    // Validate selected filesystem is in the filesystems list.
    if ($selected_fs !== null && ! in_array($selected_fs, $filesystems, true)) {
        Log::debug('Btrfs initialize_data: selected_fs cleared - not in filesystems list', [
            'selected_fs' => $selected_fs,
            'filesystems' => $filesystems,
        ]);
        $selected_fs = null;
    }

    // Validate selected device exists in the filesystem's device map.
    if (! is_scalar($selected_dev) || (string) $selected_dev === '') {
        $selected_dev = null;
    } else {
        $selected_dev = (string) $selected_dev;
        if (! isset($selected_fs, $device_map[$selected_fs])) {
            Log::debug('Btrfs initialize_data: selected_dev cleared - no fs or device_map');
            $selected_dev = null;
        } else {
            // Check if device ID exists in the filesystem's device map.
            $dev_keys = array_keys((array) $device_map[$selected_fs]);
            $dev_key_found = in_array($selected_dev, $dev_keys, true)
                || (is_numeric($selected_dev) && in_array((int) $selected_dev, $dev_keys, true));
            if (! $dev_key_found) {
                Log::debug('Btrfs initialize_data: selected_dev cleared - not in dev_keys', [
                    'selected_dev' => $selected_dev,
                    'dev_keys' => $dev_keys,
                ]);
                $selected_dev = null;
            }
        }
    }

    // Determine if we're showing the overview page.
    $is_overview = ! isset($selected_fs);

    Log::debug('Btrfs initialize_data: result', [
        'selected_fs' => $selected_fs,
        'selected_dev' => $selected_dev,
        'is_overview' => $is_overview,
        'filesystems_count' => count($filesystems),
    ]);

    return [
        // Selection state.
        'selected_fs' => $selected_fs,
        'selected_dev' => $selected_dev,
        'is_overview' => $is_overview,
        // Data arrays.
        'filesystems' => $filesystems,
        'fs_mountpoint' => $fs_mountpoint,
        'filesystem_meta' => $filesystem_meta,
        'device_map' => $device_map,
        'filesystem_tables' => $filesystem_tables,
        'device_tables' => $device_tables,
        'device_metadata' => $device_metadata,
        'filesystem_profiles' => $filesystem_profiles,
        // Scrub status per filesystem.
        'scrub_status_fs' => $scrub_status_fs,
        'scrub_status_devices' => $scrub_status_devices,
        'scrub_is_running_fs' => $scrub_is_running_fs,
        'scrub_operation_fs' => $scrub_operation_fs,
        'scrub_health_fs' => $scrub_health_fs,
        // Balance status per filesystem.
        'balance_status_fs' => $balance_status_fs,
        'balance_is_running_fs' => $balance_is_running_fs,
        // Identifiers.
        'filesystem_uuid' => $filesystem_uuid,
        'fs_rrd_key' => $fs_rrd_key,
    ];
}
