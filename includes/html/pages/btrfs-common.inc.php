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

    // Boolean passthrough for true/false states.
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    // Ratio metrics get two decimal places.
    if (str_contains($metric, 'ratio')) {
        return number_format((float) $value, 2);
    }

    // Error metrics are always displayed as whole integers.
    if (is_error_metric($metric) && is_numeric($value)) {
        return number_format((int) round((float) $value));
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

    // Duration strings in HH:MM:SS format are passed through unchanged.
    if ($metric === 'duration' && is_string($value) && str_contains($value, ':')) {
        return $value;
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
        ->whereIn('sensor_type', ['btrfsIoStatusState', 'btrfsScrubStatusState', 'btrfsBalanceStatusState'])
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
    $selected_dev_metadata = $device_metadata[$selected_dev] ?? [];
    if (is_array($selected_dev_metadata)) {
        $primary_meta = $selected_dev_metadata['primary'] ?? [];
        $backing_meta = $selected_dev_metadata['backing'] ?? [];

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
function extract_filesystem_data(array $filesystem_entries): array
{
    Log::debug('Btrfs extract_filesystem_data: start', [
        'entries_count' => count($filesystem_entries),
    ]);

    // Initialize all output arrays.
    $filesystems = [];
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
    $balance_is_running_fs = [];
    $filesystem_uuid = [];
    $fs_rrd_key = [];

    // Extract data from each filesystem entry.
    foreach ($filesystem_entries as $fs_name => $entry) {
        if (! is_array($entry)) {
            Log::debug('Btrfs extract_filesystem_data: skipping non-array entry', [
                'fs_name' => $fs_name,
                'entry_type' => gettype($entry),
            ]);
            continue;
        }

        // Register filesystem and copy indexed data arrays.
        $filesystems[] = $fs_name;
        $filesystem_meta[$fs_name] = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        $device_map[$fs_name] = is_array($entry['device_map'] ?? null) ? $entry['device_map'] : [];
        $filesystem_tables[$fs_name] = is_array($entry['table'] ?? null) ? $entry['table'] : [];
        $device_tables[$fs_name] = is_array($entry['device_tables'] ?? null) ? $entry['device_tables'] : [];
        $device_metadata[$fs_name] = is_array($entry['device_metadata'] ?? null) ? $entry['device_metadata'] : [];
        $filesystem_profiles[$fs_name] = is_array($entry['profiles'] ?? null) ? $entry['profiles'] : [];

        // Extract scrub status block.
        $scrub_block = is_array($entry['scrub'] ?? null) ? $entry['scrub'] : [];
        $balance_block = is_array($entry['balance'] ?? null) ? $entry['balance'] : [];
        $scrub_status_fs[$fs_name] = is_array($scrub_block['status'] ?? null) ? $scrub_block['status'] : [];
        $scrub_status_devices[$fs_name] = is_array($scrub_block['devices'] ?? null) ? $scrub_block['devices'] : [];
        $scrub_is_running_fs[$fs_name] = (bool) ($scrub_block['is_running'] ?? false);

        // Extract balance status block.
        $balance_status_fs[$fs_name] = is_array($balance_block['status'] ?? null) ? $balance_block['status'] : [];
        $balance_is_running_fs[$fs_name] = (bool) ($balance_block['is_running'] ?? false);

        // Extract UUID and RRD key for filesystem.
        $filesystem_uuid[$fs_name] = (string) ($entry['uuid'] ?? '');
        $fs_rrd_key[$fs_name] = (string) ($entry['rrd_key'] ?? $fs_name);

        Log::debug('Btrfs extract_filesystem_data: processed entry', [
            'fs_name' => $fs_name,
            'device_map_count' => count($device_map[$fs_name] ?? []),
            'device_tables_count' => count($device_tables[$fs_name] ?? []),
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

    // Sort filesystems alphabetically for consistent display.
    sort($filesystems);

    return [
        'filesystems' => $filesystems,
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

    // Check if filesystem data is in structured (normalized) format.
    $filesystem_entries = $app->data['filesystems'] ?? [];
    $has_structured_filesystems = is_array($filesystem_entries) && count($filesystem_entries) > 0 && is_array(reset($filesystem_entries));

    Log::debug('Btrfs initialize_data: filesystem check', [
        'has_structured' => $has_structured_filesystems,
        'entries_count' => count($filesystem_entries),
        'first_key' => is_array($filesystem_entries) ? array_key_first($filesystem_entries) : null,
        'first_val_type' => is_array($filesystem_entries) ? gettype(reset($filesystem_entries)) : null,
    ]);

    // Extract filesystem data if structured format is available.
    if ($has_structured_filesystems) {
        Log::debug('Btrfs initialize_data: using structured format');
        $extracted = extract_filesystem_data($filesystem_entries);
        $filesystems = $extracted['filesystems'];
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
        $balance_is_running_fs = [];
        $filesystem_uuid = [];
        $fs_rrd_key = [];
    }

    // Validate selected filesystem is in the filesystems list.
    if (! is_string($selected_fs) || ! in_array($selected_fs, $filesystems, true)) {
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
        // Balance status per filesystem.
        'balance_status_fs' => $balance_status_fs,
        'balance_is_running_fs' => $balance_is_running_fs,
        // Identifiers.
        'filesystem_uuid' => $filesystem_uuid,
        'fs_rrd_key' => $fs_rrd_key,
    ];
}
