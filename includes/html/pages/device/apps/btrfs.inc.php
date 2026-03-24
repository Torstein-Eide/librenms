<?php

// =============================================================================
// Btrfs Application Page
// Renders the device/app page for Btrfs monitoring.
// Three views: Overview (all filesystems), Per-filesystem, Per-device.
// =============================================================================

// -----------------------------------------------------------------------------
// Section 1: Setup and Variable Initialization
// -----------------------------------------------------------------------------

$link_array = [
    'page' => 'device',
    'device' => $device['device_id'],
    'tab' => 'apps',
    'app' => 'btrfs',
];

$selected_fs = $vars['fs'] ?? null;
$selected_dev = $vars['dev'] ?? null;
$is_overview = ! isset($selected_fs);

// Load all data from app->data that was populated by the poller.
// Each dataset is a flat key-value table stored as an array of rows
// with 'key' and 'value' fields.
$filesystems = $app->data['filesystems'] ?? [];
$device_map = $app->data['device_map'] ?? [];
$command_tables = $app->data['command_tables'] ?? [];
$command_splits = $app->data['command_splits'] ?? [];
$filesystem_tables = $app->data['filesystem_tables'] ?? [];
$device_tables = $app->data['device_tables'] ?? [];
$scrub_tables = $app->data['scrub_tables'] ?? [];
sort($filesystems);

// -----------------------------------------------------------------------------
// Section 2: Formatting Helper Closures
// These closures handle conversion of raw poller values into human-readable
// display strings.
// -----------------------------------------------------------------------------

// Format a raw numeric value based on its metric name.
// Handles byte sizes, ratios, error counts, durations, and generic numbers.
$is_byte_metric = static fn (string $metric): bool => str_contains($metric, 'size')
    || str_contains($metric, 'used')
    || str_contains($metric, 'free')
    || str_contains($metric, 'reserve')
    || str_contains($metric, 'missing')
    || str_contains($metric, 'slack')
    || str_contains($metric, 'allocated')
    || str_contains($metric, 'unallocated')
    || str_starts_with($metric, 'profile_')
    || str_contains($metric, 'bytes')
    || str_starts_with($metric, 'data_')
    || str_starts_with($metric, 'metadata_')
    || str_starts_with($metric, 'system_');

$is_error_metric = static fn (string $metric): bool => str_contains($metric, 'errs')
    || str_contains($metric, 'errors')
    || str_contains($metric, 'devid')
    || $metric === 'id';

$format_metric_value = static function ($value, string $metric) use ($is_byte_metric, $is_error_metric): string {
    if ($value === null) {
        return '';
    }

    if (str_contains($metric, 'ratio')) {
        return number_format((float) $value, 2);
    }

    if ($is_error_metric($metric) && is_numeric($value)) {
        return number_format((int) round((float) $value));
    }

    if ($metric === 'duration' && is_string($value) && str_contains($value, ':')) {
        return $value;
    }

    if ($is_byte_metric($metric)) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $v = (float) $value;
        $i = 0;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return round($v, 2) . ' ' . $units[$i];
    }

    if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) {
        return number_format((int) $value);
    }

    if (is_float($value) || (is_string($value) && preg_match('/^-?\d+\.\d+$/', $value))) {
        $formatted = number_format((float) $value, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    return (string) $value;
};

// Convert a raw key string into a human-readable label.
// Examples: "device_size" -> "Device Size", "bytes_scrubbed.bytes" -> "Bytes Scrubbed Bytes"
$format_display_name = static function (string $key): string {
    $name = preg_replace('/\[([0-9]+)\]/', ' $1', $key);
    $name = str_replace(['.', '_', '-'], ' ', (string) $name);
    $name = preg_replace('/\s+/', ' ', (string) $name);
    $name = trim((string) $name);
    $name = ucwords($name);
    $name = preg_replace('/\bId\b/', 'ID', (string) $name);
    $name = preg_replace('/\bIo\b/', 'IO', (string) $name);
    $name = preg_replace('/\bUuid\b/', 'UUID', (string) $name);

    return (string) $name;
};

// Convert command name (snake_case) to a display title.
// Example: "device_usage" -> "Device Usage"
$format_command_name = static function (string $command_name): string {
    return ucwords(str_replace('_', ' ', $command_name));
};

// Find a specific key's value in a flat key-value table (array of rows).
$get_command_value = static function (array $rows, string $wanted_key): ?string {
    foreach ($rows as $row) {
        if (($row['key'] ?? null) === $wanted_key) {
            return (string) ($row['value'] ?? '');
        }
    }

    return null;
};

// Format a value for table display: handles null/empty, booleans, and numeric formatting.
$format_display_value = static function ($value, string $key) use ($format_metric_value): string {
    if ($value === null || $value === '') {
        return '-';
    }

    if ($value === 'true' || $value === true) {
        return 'Yes';
    }
    if ($value === 'false' || $value === false) {
        return 'No';
    }

    if ($key === 'duration' && is_numeric($value)) {
        $percent = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        return $percent . '%';
    }

    return $format_metric_value($value, $key);
};

// Convert a value to a strict boolean, or null if unrecognised.
$to_bool = static function ($value): ?bool {
    if (is_bool($value)) {
        return $value;
    }

    if (is_string($value)) {
        $v = strtolower(trim($value));
        if (in_array($v, ['true', 'yes', '1'], true)) {
            return true;
        }
        if (in_array($v, ['false', 'no', '0'], true)) {
            return false;
        }
    }

    if (is_numeric($value)) {
        return ((int) $value) !== 0;
    }

    return null;
};

// Produce a human-readable scrub status string from scrub metadata metrics.
// Handles the "has_stats", "no_stats_available", and "has_status_suffix" flags
// that btrfs scrub emits in different versions.
$format_scrub_status = static function (array $metrics) use ($to_bool): string {
    $status = trim((string) ($metrics['status'] ?? ''));
    $has_status_suffix = $to_bool($metrics['has_status_suffix'] ?? null);
    $has_stats = $to_bool($metrics['has_stats'] ?? null);
    $no_stats_available = $to_bool($metrics['no_stats_available'] ?? null);

    if ($has_stats === false || $no_stats_available === true) {
        return 'No stats available';
    }

    if ($status === '') {
        $status = 'Unknown';
    }

    if ($has_status_suffix === true && strtolower($status) !== 'running') {
        return $status . ' (suffix)';
    }

    return $status;
};

// Generate a Bootstrap label badge HTML string for a given state.
// States: ok (gray), running (gray), warning (orange), error (red).
$status_badge = static function (string $state): string {
    $state_lc = strtolower($state);
    if ($state_lc === 'error') {
        $badge = 'Error';
        $class = 'label-danger';
    } elseif ($state_lc === 'running') {
        $badge = 'Running';
        $class = 'label-default';
    } elseif ($state_lc === 'na') {
        $badge = 'N/A';
        $class = 'label-default';
    } elseif ($state_lc === 'warning') {
        $badge = 'Warning';
        $class = 'label-warning';
    } else {
        $badge = ucfirst($state_lc === 'ok' ? 'OK' : $state);
        $class = 'label-default';
    }

    return '<span class="label ' . $class . '">' . htmlspecialchars($badge) . '</span>';
};

// Reorder an associative array so that a list of preferred keys appears first,
// in the given order, followed by all remaining keys.
$ordered_metric_pairs = static function (array $metrics, array $preferred_order): array {
    $ordered = [];

    foreach ($preferred_order as $key) {
        if (array_key_exists($key, $metrics)) {
            $ordered[$key] = $metrics[$key];
            unset($metrics[$key]);
        }
    }

    foreach ($metrics as $key => $value) {
        $ordered[$key] = $value;
    }

    return $ordered;
};

// Preferred metric ordering for the filesystem overview table.
$filesystem_metric_order = [
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

// Preferred metric ordering for the per-device view table.
$device_metric_order = [
    'path',
    'write_io_errs',
    'read_io_errs',
    'flush_io_errs',
    'corruption_errs',
    'generation_errs',
];

// Scrub counter columns that should be hidden when has_stats is false.
$scrub_counter_columns = [
    'data_extents_scrubbed',
    'tree_extents_scrubbed',
    'data_bytes_scrubbed',
    'tree_bytes_scrubbed',
    'read_errors',
    'csum_errors',
    'verify_errors',
    'corrected_errors',
    'uncorrectable_errors',
    'unverified_errors',
    'no_csum',
    'csum_discards',
    'super_errors',
    'malloc_errors',
    'last_physical',
];

$scrub_status_error_keys = [
    'read_errors',
    'csum_errors',
    'verify_errors',
    'uncorrectable_errors',
    'unverified_errors',
    'missing',
    'device_missing',
];

$scrub_device_error_keys = [
    'scrub_read_errors',
    'scrub_csum_errors',
    'scrub_verify_errors',
    'scrub_uncorrectable_errors',
    'scrub_unverified_errors',
    'scrub_missing',
    'scrub_device_missing',
];

$device_io_error_keys = [
    'corruption_errs',
    'flush_io_errs',
    'generation_errs',
    'read_io_errs',
    'write_io_errs',
];

$get_command_state = static function (string $command_name, int $row_count, array $devices) use ($device_io_error_keys, $scrub_status_error_keys): string {
    if ($row_count === 0) {
        return 'warning';
    }

    if ($command_name === 'device_stats') {
        foreach ($devices as $dev_metrics) {
            foreach ($device_io_error_keys as $ek) {
                if (isset($dev_metrics[$ek]) && is_numeric($dev_metrics[$ek]) && $dev_metrics[$ek] > 0) {
                    return 'error';
                }
            }
        }
    } elseif ($command_name === 'scrub_status') {
        foreach ($devices as $dev_metrics) {
            if (strtolower((string) ($dev_metrics['status'] ?? '')) === 'running') {
                return 'running';
            }
            foreach ($scrub_status_error_keys as $ek) {
                if (isset($dev_metrics[$ek]) && is_numeric($dev_metrics[$ek]) && $dev_metrics[$ek] > 0) {
                    return 'error';
                }
            }
        }
    }

    return 'ok';
};

// Render the Scrub Overview panel (filesystem-wide scrub totals).
// Shows the key-value table for scrub overview metrics with special formatting
// for bytes_scrubbed, total_to_scrub, and duration.
$render_scrub_overview_panel = static function (array $split, array $rows, string $badge, string $panel_col_class) use ($format_display_name, $format_display_value): void {
    echo '<div class="' . $panel_col_class . '">';
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Scrub Overview ' . $badge . '</h3></div>';
    echo '<div class="panel-body">';

    if (count($rows) === 0) {
        echo '<em>No data returned</em>';
    } else {
        $overview_map = [];
        foreach ($split['overview'] as $overview_row) {
            $overview_map[$overview_row['key']] = $overview_row['value'];
        }

        echo '<div class="table-responsive">';
        echo '<table class="table table-condensed table-striped">';
        echo '<thead><tr><th>Key</th><th>Value</th></tr></thead>';
        echo '<tbody>';
        foreach ($split['overview'] as $overview_row) {
            $key = $overview_row['key'];
            $value = $overview_row['value'];

            // Skip status and progress rows; shown in the panel header badge
            if ($key === 'status' || $key === 'bytes_scrubbed.progress') {
                continue;
            }

            // bytes_scrubbed.bytes: show value with progress percentage
            if ($key === 'bytes_scrubbed.bytes') {
                $progress = $overview_map['bytes_scrubbed.progress'] ?? null;
                $formatted = $format_display_value($value, 'bytes');
                if (is_numeric($progress)) {
                    $progress_text = rtrim(rtrim(number_format((float) $progress, 2, '.', ''), '0'), '.');
                    $value = "$formatted (" . $progress_text . '%)';
                } else {
                    $value = $formatted;
                }
                $key = 'bytes_scrubbed';
            } elseif ($key === 'total_to_scrub') {
                $value = $format_display_value($value, 'bytes');
            } elseif ($key === 'duration') {
                // Fall back to calculating progress from old-style flat keys when
                // bytes_scrubbed.bytes is not available (new-style already shows progress).
                if (! isset($overview_map['bytes_scrubbed.bytes'])) {
                    $bytes_scrubbed = $overview_map['bytes_scrubbed'] ?? null;
                    $total_to_scrub = $overview_map['total_to_scrub'] ?? null;
                    $bytes_scrubbed_num = LibreNMS\Util\Number::toBytes((string) ($bytes_scrubbed ?? ''));
                    $total_to_scrub_num = LibreNMS\Util\Number::toBytes((string) ($total_to_scrub ?? ''));
                    if (! is_nan($bytes_scrubbed_num) && ! is_nan($total_to_scrub_num) && $total_to_scrub_num > 0) {
                        $progress = ($bytes_scrubbed_num / $total_to_scrub_num) * 100;
                        $value = rtrim(rtrim(number_format($progress, 2, '.', ''), '0'), '.') . '%';
                    }
                }
            }

            echo '<tr>';
            echo '<td>' . htmlspecialchars($format_display_name($key)) . '</td>';
            echo '<td>' . htmlspecialchars($value) . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '</div>';
    echo '</div>';
    echo '</div>';
};

// Render Scrub Per Device panel for the filesystem view (all devices, full-width).
$render_scrub_per_device_fs_panel = static function (
    array $split,
    ?string $selected_dev_path,
    array $path_to_dev_id,
    array $link_array,
    string $selected_fs
) use ($to_bool, $scrub_counter_columns, $format_display_name, $format_display_value, $status_badge): void {
    $devices = $split['devices'];
    $hidden_columns = ['path', 'id', 'section', 'has_status_suffix', 'has_stats', 'no_stats_available', 'last_physical'];

    echo '<div class="col-md-12">';
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Scrub Per Device</h3></div>';
    echo '<div class="panel-body">';

    if (isset($selected_dev_path) && $selected_dev_path !== '' && isset($devices[$selected_dev_path])) {
        $devices = [$selected_dev_path => $devices[$selected_dev_path]];
    }

    if (count($devices) > 0) {
        echo '<div class="table-responsive">';
        echo '<table class="table table-condensed table-striped">';
        echo '<thead><tr><th>Device</th>';
        foreach ($split['device_columns'] as $column) {
            if (in_array($column, $hidden_columns, true)) {
                continue;
            }
            echo '<th>' . htmlspecialchars($format_display_name($column)) . '</th>';
        }
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($devices as $device_name => $metrics) {
            echo '<tr>';
            if (isset($path_to_dev_id[$device_name])) {
                echo '<td>' . generate_link(htmlspecialchars($device_name), $link_array, ['fs' => $selected_fs, 'dev' => $path_to_dev_id[$device_name]]) . '</td>';
            } else {
                echo '<td>' . htmlspecialchars($device_name) . '</td>';
            }

            $has_stats = $to_bool($metrics['has_stats'] ?? null);
            $no_stats_available = $to_bool($metrics['no_stats_available'] ?? null);
            $hide_counters = $has_stats === false || $no_stats_available === true;
            foreach ($split['device_columns'] as $column) {
                if (in_array($column, $hidden_columns, true)) {
                    continue;
                }
                $value = $metrics[$column] ?? '';
                $display_value = $format_display_value($value, $column);
                if ($hide_counters && in_array($column, $scrub_counter_columns, true)) {
                    $display_value = '';
                }
                if ($column === 'status') {
                    echo '<td>' . $status_badge((string) $value) . '</td>';
                } else {
                    echo '<td>' . htmlspecialchars($display_value) . '</td>';
                }
            }
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    } else {
        echo '<p class="text-muted">No per-device scrub details were reported.</p>';
    }

    echo '</div>';
    echo '</div>';
    echo '</div>';
};

// Render Scrub Per Device panel for the per-device view (single device).
$render_scrub_per_device_panel = static function (
    array $split,
    string $panel_col_class,
    ?string $selected_dev_path
) use ($to_bool, $scrub_counter_columns, $format_display_name, $format_display_value, $status_badge): void {
    $devices = $split['devices'];
    $hidden_columns = ['path', 'id', 'section', 'has_status_suffix', 'has_stats', 'no_stats_available', 'last_physical'];

    echo '<div class="' . $panel_col_class . '">';
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Scrub Per Device</h3></div>';
    echo '<div class="panel-body">';

    if (isset($selected_dev_path) && $selected_dev_path !== '' && isset($devices[$selected_dev_path])) {
        $devices = [$selected_dev_path => $devices[$selected_dev_path]];
    }

    if (count($devices) > 0) {
        $single_metrics = reset($devices);
        echo '<div class="table-responsive">';
        echo '<table class="table table-condensed table-striped">';
        echo '<thead><tr><th>Metric</th><th>Value</th></tr></thead>';
        echo '<tbody>';

        foreach ($split['device_columns'] as $column) {
            if (in_array($column, $hidden_columns, true)) {
                continue;
            }
            $has_stats = $to_bool($single_metrics['has_stats'] ?? null);
            $no_stats_available = $to_bool($single_metrics['no_stats_available'] ?? null);
            $hide_counters = $has_stats === false || $no_stats_available === true;
            $value = $single_metrics[$column] ?? '';
            $display_value = $format_display_value($value, $column);
            if ($hide_counters && in_array($column, $scrub_counter_columns, true)) {
                $display_value = '';
            }
            echo '<tr>';
            echo '<td>' . htmlspecialchars($format_display_name($column)) . '</td>';
            if ($column === 'status') {
                echo '<td>' . $status_badge((string) $value) . '</td>';
            } else {
                echo '<td>' . htmlspecialchars($display_value) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    } else {
        echo '<p class="text-muted">No per-device scrub details were reported.</p>';
    }

    echo '</div>';
    echo '</div>';
    echo '</div>';
};

// Render a generic command panel with optional overview and per-device tables.
// Used for device_usage, device_stats, and other commands.
$render_generic_panel = static function (
    string $command_name,
    string $panel_title,
    string $panel_col_class,
    array $split,
    ?string $selected_dev,
    ?string $selected_dev_path,
    array $path_to_dev_id,
    array $link_array,
    string $selected_fs
) use ($format_display_name, $format_display_value): void {
    echo '<div class="' . $panel_col_class . '">';
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">' . $panel_title . '</h3></div>';
    echo '<div class="panel-body">';

    if (count($split['overview']) === 0 && count($split['devices']) === 0) {
        echo '<em>No data returned</em>';
    } else {
        $has_overview = count($split['overview']) > 0;

        if ($has_overview) {
            echo '<h4>Overview</h4>';
            echo '<div class="table-responsive">';
            echo '<table class="table table-condensed table-striped">';
            echo '<thead><tr><th>Key</th><th>Value</th></tr></thead>';
            echo '<tbody>';
            foreach ($split['overview'] as $overview_row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($format_display_name($overview_row['key'])) . '</td>';
                echo '<td>' . htmlspecialchars($format_display_value($overview_row['value'], $overview_row['key'])) . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }

        if (count($split['devices']) > 0) {
            if ($has_overview) {
                echo '<h4>Per Device</h4>';
            }

            $devices = $split['devices'];
            if (isset($selected_dev_path) && $selected_dev_path !== '' && isset($devices[$selected_dev_path])) {
                $devices = [$selected_dev_path => $devices[$selected_dev_path]];
            }

            if (isset($selected_dev) && count($devices) === 1) {
                $single_metrics = reset($devices);
                echo '<div class="table-responsive">';
                echo '<table class="table table-condensed table-striped">';
                echo '<thead><tr><th>Metric</th><th>Value</th></tr></thead>';
                echo '<tbody>';

                foreach ($split['device_columns'] as $column) {
                    $value = $single_metrics[$column] ?? '';
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($format_display_name($column)) . '</td>';
                    echo '<td>' . htmlspecialchars($format_display_value($value, $column)) . '</td>';
                    echo '</tr>';
                }

                echo '</tbody>';
                echo '</table>';
                echo '</div>';
            } else {
                echo '<div class="table-responsive">';
                echo '<table class="table table-condensed table-striped">';
                echo '<thead><tr><th>Device</th>';
                foreach ($split['device_columns'] as $column) {
                    echo '<th>' . htmlspecialchars($format_display_name($column)) . '</th>';
                }
                echo '</tr></thead>';
                echo '<tbody>';

                foreach ($devices as $device_name => $metrics) {
                    echo '<tr>';
                    if (isset($path_to_dev_id[$device_name])) {
                        echo '<td>' . generate_link(htmlspecialchars($device_name), $link_array, ['fs' => $selected_fs, 'dev' => $path_to_dev_id[$device_name]]) . '</td>';
                    } else {
                        echo '<td>' . htmlspecialchars($device_name) . '</td>';
                    }

                    foreach ($split['device_columns'] as $column) {
                        $value = $metrics[$column] ?? '';
                        echo '<td>' . htmlspecialchars($format_display_value($value, $column)) . '</td>';
                    }
                    echo '</tr>';
                }

                echo '</tbody>';
                echo '</table>';
                echo '</div>';
            }
        }
    }

    echo '</div>';
    echo '</div>';
    echo '</div>';
};

// Render the Balance panel HTML.
// Takes the already-split balance data so the caller controls the split call.
// Outputs the panel div directly.
$render_balance_panel = static function (array $split, array $rows, string $panel_col_class, ?string $selected_dev, int $balance_status_code) use ($status_badge, $format_display_name, $format_display_value): void {
    $state = match ($balance_status_code) {
        1 => 'running',
        2 => 'na',
        3 => 'error',
        default => 'na',
    };
    $badge = $status_badge($state);

    $overview_rows = [];
    foreach ($split['overview'] as $row) {
        $key = $row['key'];
        if ($key !== 'path' && $key !== 'status') {
            $overview_rows[] = $row;
        }
    }

    $profiles = $split['devices'];
    $has_profiles = count($profiles) > 0;
    $has_overview = count($overview_rows) > 0 || $has_profiles;
    $show_overview = ! isset($selected_dev) && ($has_overview || count($rows) === 0);
    $is_idle = $balance_status_code !== 1;

    echo '<div class="' . $panel_col_class . '">';
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Balance ' . $badge . '</h3></div>';
    echo '<div class="panel-body">';

    if ($is_idle && ! $has_profiles) {
        echo '<p class="text-muted">No balance operation running.</p>';
    }

    if ($show_overview) {
        if (count($overview_rows) > 0) {
            echo '<div class="table-responsive">';
            echo '<table class="table table-condensed table-striped">';
            echo '<thead><tr><th>Key</th><th>Value</th></tr></thead>';
            echo '<tbody>';
            foreach ($overview_rows as $row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($format_display_name($row['key'])) . '</td>';
                echo '<td>' . htmlspecialchars($format_display_value($row['value'], $row['key'])) . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }

        if ($has_profiles) {
            echo '<h4>Profiles</h4>';
            echo '<div class="table-responsive">';
            echo '<table class="table table-condensed table-striped">';
            echo '<thead><tr>';
            if (! isset($selected_dev)) {
                echo '<th>#</th>';
            }
            foreach ($split['device_columns'] as $column) {
                echo '<th>' . htmlspecialchars($format_display_name($column)) . '</th>';
            }
            echo '</tr></thead>';
            echo '<tbody>';
            foreach ($profiles as $profile_index => $profile) {
                echo '<tr>';
                if (! isset($selected_dev)) {
                    echo '<td>' . ((int) $profile_index + 1) . '</td>';
                }
                foreach ($split['device_columns'] as $column) {
                    $value = $profile[$column] ?? '';
                    echo '<td>' . htmlspecialchars($format_display_value($value, $column)) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }
    }

    echo '</div>';
    echo '</div>';
    echo '</div>';
};

// -----------------------------------------------------------------------------
// Section 3: Navigation Bar (print_optionbar)
// Allows switching between overview, filesystem, and device views.
// -----------------------------------------------------------------------------

print_optionbar_start();

$overview_label = $is_overview
    ? '<span class="pagemenu-selected">Overview</span>'
    : 'Overview';
echo generate_link($overview_label, $link_array);

// Filesystem links
if (count($filesystems) > 0) {
    echo ' | Filesystems: ';
    foreach ($filesystems as $index => $fs) {
        $show_rows = $command_tables[$fs]['filesystem_show'] ?? [];
        $filesystem_label = $get_command_value($show_rows, 'label');
        if (! empty($filesystem_label)) {
            $display_fs = (string) $filesystem_label;
        } elseif ($fs === '/') {
            $display_fs = 'root';
        } else {
            $display_fs = (string) $fs;
        }

        $fs_label = htmlspecialchars($display_fs);
        $label = ($selected_fs === $fs)
            ? '<span class="pagemenu-selected">' . $fs_label . '</span>'
            : $fs_label;

        echo generate_link($label, $link_array, ['fs' => $fs]);
        if ($index < (count($filesystems) - 1)) {
            echo ', ';
        }
    }
}

// Device links (shown only when a filesystem is selected)
if (isset($selected_fs) && isset($device_map[$selected_fs]) && count($device_map[$selected_fs]) > 0) {
    echo ' | Devices: ';
    $devices = $device_map[$selected_fs];
    asort($devices);
    $i = 0;
    foreach ($devices as $dev_id => $dev_path) {
        $dev_path_label = htmlspecialchars((string) $dev_path);
        $label = ($selected_dev === $dev_id)
            ? '<span class="pagemenu-selected">' . $dev_path_label . '</span>'
            : $dev_path_label;

        echo generate_link($label, $link_array, ['fs' => $selected_fs, 'dev' => $dev_id]);
        if (++$i < count($devices)) {
            echo ', ';
        }
    }
}

// Graph types menu (shown when a filesystem is selected)
if (isset($selected_fs)) {
    echo ' | Graphs: ';

    // Add "All" link when a specific graph is selected
    if (isset($vars['graph'])) {
        $all_label = ($vars['graph'] === null || $vars['graph'] === '')
            ? '<span class="pagemenu-selected">All</span>'
            : 'All';
        $all_vars = ['fs' => $selected_fs];
        if (isset($selected_dev)) {
            $all_vars['dev'] = $selected_dev;
        }
        echo generate_link($all_label, $link_array, $all_vars) . ' | ';
    }

    if (isset($selected_dev)) {
        $graph_types = [
            'btrfs_dev_usage' => 'Usage',
            'btrfs_dev_errors' => 'IO Errors (Rate)',
            'btrfs_dev_errors_counter' => 'IO Errors (Total)',
            'btrfs_dev_scrub_errors' => 'Scrub Errors (Total)',
            'btrfs_dev_scrub_errors_derive' => 'Scrub Errors (Rate)',
        ];
    } else {
        $graph_types = [
            'btrfs_fs_space' => 'Space',
            'btrfs_fs_data_types' => 'Data Types',
            'btrfs_fs_free' => 'Free Space',
            'btrfs_fs_ratios' => 'Ratios',
            'btrfs_fs_errors_by_type' => 'Errors by Type',
            'btrfs_fs_errors_by_device' => 'Errors by Device',
        ];
    }

    $current_graph = $vars['graph'] ?? null;
    $graph_links = [];
    foreach ($graph_types as $graph_key => $graph_label) {
        $label = ($current_graph === $graph_key)
            ? '<span class="pagemenu-selected">' . $graph_label . '</span>'
            : $graph_label;

        $graph_vars = ['fs' => $selected_fs];
        if (isset($selected_dev)) {
            $graph_vars['dev'] = $selected_dev;
        }
        $graph_vars['graph'] = $graph_key;

        $graph_links[] = generate_link($label, $link_array, $graph_vars);
    }
    echo implode(' | ', $graph_links);
}

print_optionbar_end();

// -----------------------------------------------------------------------------
// Section 4: Overview Page (when no filesystem is selected)
// Shows btrfs-progs version, JSON key dump, and filesystem summary table.
// -----------------------------------------------------------------------------

$json_keys = $app->data['json_keys'] ?? [];
if ($is_overview && count($json_keys) > 0) {
    $keys_text = htmlspecialchars(implode(PHP_EOL, $json_keys));
    $btrfs_version = htmlspecialchars((string) ($app->data['btrfs_progs_version'] ?? 'unknown'));
    $btrfs_features = $app->data['btrfs_progs_features'] ?? [];
    $feature_text = is_array($btrfs_features) ? htmlspecialchars(implode(' ', $btrfs_features)) : '';

    // Version and feature info panel
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Btrfs Overview</h3></div>';
    echo '<div class="panel-body">';
    echo '<strong>btrfs-progs version:</strong> ' . $btrfs_version;
    if ($feature_text !== '') {
        echo '<br><strong>features:</strong> ' . $feature_text;
    }
    echo '</div>';
    echo '</div>';

    // JSON key dump panel (all keys seen in the btrfs JSON output)
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Btrfs JSON Keys</h3></div>';
    echo '<div class="panel-body">';
    echo '<textarea class="form-control" rows="16" readonly>' . $keys_text . '</textarea>';
    echo '</div>';
    echo '</div>';
}

// Filesystem summary table
if ($is_overview && count($filesystems) > 0) {
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Filesystems Overview</h3></div>';
    echo '<div class="panel-body">';
    echo '<div class="table-responsive">';
    echo '<table class="table table-condensed table-striped table-hover">';
    echo '<thead><tr><th>Filesystem</th><th>Status</th><th>Used</th><th>Free (Estimated)</th><th>Device Size</th><th>Data Ratio</th><th>Metadata Ratio</th><th>Devices</th></tr></thead>';
    echo '<tbody>';

    foreach ($filesystems as $fs) {
        $fs_data = $filesystem_tables[$fs] ?? [];
        $fs_devices = $device_map[$fs] ?? [];
        $show_rows = $command_tables[$fs]['filesystem_show'] ?? [];
        $label = $get_command_value($show_rows, 'label');
        $display_name = ! empty($label) ? $label . ' (' . $fs . ')' : $fs;

        // Determine filesystem health state from device error counters and scrub status.
        // error state takes priority over running.
        $fs_state = 'ok';
        foreach (($device_tables[$fs] ?? []) as $dev) {
            foreach ($device_io_error_keys as $k) {
                if (isset($dev[$k]) && is_numeric($dev[$k]) && $dev[$k] > 0) {
                    $fs_state = 'error';
                    break 2;
                }
            }
        }
        if ($fs_state === 'ok') {
            foreach (($scrub_tables[$fs] ?? []) as $scrub_dev) {
                foreach ($scrub_device_error_keys as $k) {
                    if (isset($scrub_dev[$k]) && is_numeric($scrub_dev[$k]) && $scrub_dev[$k] > 0) {
                        $fs_state = 'error';
                        break 2;
                    }
                }
            }
        }
        if ($fs_state === 'ok') {
            foreach (($command_tables[$fs]['scrub_status'] ?? []) as $row) {
                if (($row['key'] ?? null) === 'status' && strtolower((string) ($row['value'] ?? '')) === 'running') {
                    $fs_state = 'running';
                    break;
                }
            }
        }

        echo '<tr>';
        echo '<td>' . generate_link(htmlspecialchars((string) $display_name), $link_array, ['fs' => $fs]) . '</td>';
        echo '<td>' . $status_badge($fs_state) . '</td>';
        echo '<td>' . htmlspecialchars($format_metric_value($fs_data['used'] ?? null, 'used')) . '</td>';
        echo '<td>' . htmlspecialchars($format_metric_value($fs_data['free_estimated'] ?? null, 'free_estimated')) . '</td>';
        echo '<td>' . htmlspecialchars($format_metric_value($fs_data['device_size'] ?? null, 'device_size')) . '</td>';
        echo '<td>' . htmlspecialchars($format_metric_value($fs_data['data_ratio'] ?? null, 'data_ratio')) . '</td>';
        echo '<td>' . htmlspecialchars($format_metric_value($fs_data['metadata_ratio'] ?? null, 'metadata_ratio')) . '</td>';
        echo '<td>' . number_format(count($fs_devices)) . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

// -----------------------------------------------------------------------------
// Section 5: Per-Filesystem View
// Wraps the device view and all command panels inside a Bootstrap row.
// -----------------------------------------------------------------------------

if (isset($selected_fs)) {
    $is_device_view = isset($selected_dev);
    $panel_col_class = $is_device_view ? 'col-md-4' : 'col-md-6';
    echo '<div class="row">';
}

// Per-filesystem Overview Panel: shows filesystem label, UUID, and key metrics
// in a multi-column table that adapts to screen size.
if (isset($selected_fs) && ! isset($selected_dev) && isset($filesystem_tables[$selected_fs])) {
    $show_rows = $command_tables[$selected_fs]['filesystem_show'] ?? [];
    $filesystem_label = $get_command_value($show_rows, 'label');
    $filesystem_uuid = $get_command_value($show_rows, 'uuid');
    if ($filesystem_label === null) {
        $filesystem_label = '';
    }

    $overview_metric_keys = [
        'device_size',
        'device_unallocated',
        'free_estimated',
        'free_statfs_df',
        'device_allocated',
        'used',
        'free_estimated_min',
        'global_reserve',
    ];

    $overview_pairs = [];
    foreach ($overview_metric_keys as $metric_key) {
        $overview_pairs[] = [
            'metric' => $metric_key,
            'value' => $filesystem_tables[$selected_fs][$metric_key] ?? null,
        ];
    }

    $fs_title = ! empty($filesystem_label)
        ? $filesystem_label . ' (' . $selected_fs . ')'
        : $selected_fs;

    echo '<div class="col-md-6">';
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Overview</h3></div>';
    echo '<div class="panel-body">';
    echo '<p><strong>Filesystem:</strong> ' . htmlspecialchars((string) $fs_title) . '</p>';
    if (! empty($filesystem_uuid)) {
        echo '<p><strong>UUID:</strong> ' . htmlspecialchars((string) $filesystem_uuid) . '</p>';
    }

    // Renders the metric pairs table in a variable number of columns
    // depending on the visible breakpoint (lg=4, md=3, sm=2, xs=1).
    $renderOverviewTable = static function (array $pairs, int $columns) use ($format_display_name, $format_display_value): void {
        $rows = (int) ceil(count($pairs) / $columns);

        echo '<table class="table table-condensed table-striped">';
        echo '<thead><tr>';
        for ($c = 0; $c < $columns; $c++) {
            echo '<th>Metric</th><th>Value</th>';
        }
        echo '</tr></thead>';
        echo '<tbody>';

        for ($r = 0; $r < $rows; $r++) {
            echo '<tr>';
            for ($c = 0; $c < $columns; $c++) {
                $index = ($r * $columns) + $c;
                if (! isset($pairs[$index])) {
                    echo '<td></td><td></td>';
                    continue;
                }

                $metric = (string) $pairs[$index]['metric'];
                $value = $pairs[$index]['value'];

                echo '<td>' . htmlspecialchars($format_display_name($metric)) . '</td>';
                echo '<td>' . htmlspecialchars($format_display_value($value, $metric)) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    };

    echo '<div class="table-responsive visible-lg-block">';
    $renderOverviewTable($overview_pairs, 4);
    echo '</div>';

    echo '<div class="table-responsive visible-md-block">';
    $renderOverviewTable($overview_pairs, 3);
    echo '</div>';

    echo '<div class="table-responsive visible-sm-block">';
    $renderOverviewTable($overview_pairs, 2);
    echo '</div>';

    echo '<div class="table-responsive visible-xs-block">';
    $renderOverviewTable($overview_pairs, 1);
    echo '</div>';

    echo '</div>';
    echo '</div>';
    echo '</div>';
}

// Per-Device Metrics Panel: shown when a device is selected alongside a filesystem.
if (isset($selected_fs, $selected_dev) && isset($device_tables[$selected_fs][$selected_dev])) {
    $dev_metrics = $ordered_metric_pairs($device_tables[$selected_fs][$selected_dev], $device_metric_order);
    $selected_dev_path = $device_tables[$selected_fs][$selected_dev]['path'] ?? null;
    $dev_state = 'ok';
    foreach ($device_io_error_keys as $ek) {
        if (isset($dev_metrics[$ek]) && is_numeric($dev_metrics[$ek]) && $dev_metrics[$ek] > 0) {
            $dev_state = 'error';
            break;
        }
    }

    echo '<div class="col-md-4">';
    echo '<div class="panel panel-default">';
    $device_title = 'Device Metrics';
    if (is_string($selected_dev_path) && $selected_dev_path !== '') {
        $device_title .= ': ' . $selected_dev_path;
    }
    echo '<div class="panel-heading"><h3 class="panel-title">' . htmlspecialchars($device_title) . ' ' . $status_badge($dev_state) . '</h3></div>';
    echo '<div class="panel-body">';
    echo '<div class="table-responsive">';
    echo '<table class="table table-condensed table-striped">';
    echo '<thead><tr><th>Metric</th><th>Value</th></tr></thead>';
    echo '<tbody>';

    foreach ($dev_metrics as $metric => $value) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($format_display_name((string) $metric)) . '</td>';
        echo '<td>' . htmlspecialchars($format_display_value($value, (string) $metric)) . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

// -----------------------------------------------------------------------------
// Section 6: Command Panels
// Iterates over all command tables for the selected filesystem and renders
// a panel for each. Balance is rendered first (before scrub panels).
// scrub_status renders as two separate panels: Scrub Overview and Scrub Per Device.
// -----------------------------------------------------------------------------

if (isset($selected_fs) && isset($command_tables[$selected_fs]) && is_array($command_tables[$selected_fs])) {
    // Build a reverse map from device path to device ID for generating links.
    $path_to_dev_id = [];
    foreach ($device_map[$selected_fs] ?? [] as $dev_id => $dev_path) {
        $path_to_dev_id[(string) $dev_path] = (string) $dev_id;
    }
    $selected_dev_path = isset($selected_dev, $device_map[$selected_fs][$selected_dev]) ? (string) $device_map[$selected_fs][$selected_dev] : null;

    $fs_commands = $command_tables[$selected_fs];

    // Merge scrub_status_devices into scrub_status if present.
    // The poller may store them separately; we consolidate here for rendering.
    if (isset($fs_commands['scrub_status_devices']) && is_array($fs_commands['scrub_status_devices'])) {
        if (! isset($fs_commands['scrub_status']) || ! is_array($fs_commands['scrub_status'])) {
            $fs_commands['scrub_status'] = [];
        }

        foreach ($fs_commands['scrub_status_devices'] as $row) {
            $row_key = (string) ($row['key'] ?? '');
            if (str_starts_with($row_key, 'devices.')) {
                $fs_commands['scrub_status'][] = $row;
            }
        }

        unset($fs_commands['scrub_status_devices']);
    }

    // Reorder command tables: device_usage, device_stats, scrub_status first,
    // then any remaining commands in original order.
    $command_order = ['device_usage', 'device_stats', 'scrub_status'];
    $ordered_fs_commands = [];
    foreach ($command_order as $ordered_name) {
        if (isset($fs_commands[$ordered_name])) {
            $ordered_fs_commands[$ordered_name] = $fs_commands[$ordered_name];
        }
    }

    foreach ($fs_commands as $name => $rows) {
        if (! isset($ordered_fs_commands[$name])) {
            $ordered_fs_commands[$name] = $rows;
        }
    }

    // Render Balance panel first (before scrub panels), per-filesystem only.
    if (! isset($selected_dev) && isset($fs_commands['balance_status']) && is_array($fs_commands['balance_status'])) {
        $balance_split = $command_splits[$selected_fs]['balance_status'] ?? [];
        $balance_status_code = (int) ($filesystem_tables[$selected_fs]['balance_status_code'] ?? 2);
        $render_balance_panel($balance_split, $fs_commands['balance_status'], $panel_col_class, $selected_dev, $balance_status_code);
    }

    foreach ($ordered_fs_commands as $command_name => $rows) {
        // balance_status is rendered before the loop
        if ($command_name === 'balance_status') {
            continue;
        }
        // filesystem_show and filesystem_usage are already shown in the Overview panel
        if (in_array($command_name, ['filesystem_show', 'filesystem_usage'], true)) {
            continue;
        }

        // device_stats is only shown on the overview page, not in per-device view
        if (isset($selected_dev) && $command_name === 'device_stats') {
            continue;
        }

        // Split command data into overview and per-device sections
        $split = $command_splits[$selected_fs][$command_name] ?? ['overview' => [], 'devices' => [], 'device_columns' => []];
        if ($command_name === 'scrub_status' && isset($command_splits[$selected_fs]['scrub_status_devices'])) {
            $scrub_devices_split = $command_splits[$selected_fs]['scrub_status_devices'];
            if (! empty($scrub_devices_split['devices'])) {
                $split['devices'] = $scrub_devices_split['devices'];
                $split['device_columns'] = $scrub_devices_split['device_columns'] ?? [];
            }
        }

        $command_state = $get_command_state($command_name, count($rows), $split['devices']);
        $command_badge = $status_badge($command_state);

        // ---------------------------------------------------------------------
        // scrub_status renders as two separate panels: Scrub Overview
        // (filesystem-wide totals) and Scrub Per Device (per-device counters).
        // ---------------------------------------------------------------------
        if ($command_name === 'scrub_status') {
            $scrub_split = $split;
            $scrub_from_tables = $scrub_tables[$selected_fs] ?? [];
            if (is_array($scrub_from_tables) && count($scrub_from_tables) > 0) {
                $scrub_split['devices'] = [];
                $scrub_split['device_columns'] = [];

                foreach ($scrub_from_tables as $dev_id => $scrub_metrics) {
                    if (! is_array($scrub_metrics)) {
                        continue;
                    }

                    $dev_path = (string) ($device_map[$selected_fs][$dev_id] ?? $dev_id);
                    $scrub_split['devices'][$dev_path] = $scrub_metrics;

                    foreach ($scrub_metrics as $metric_key => $unused_metric_value) {
                        if (! in_array($metric_key, $scrub_split['device_columns'], true)) {
                            $scrub_split['device_columns'][] = $metric_key;
                        }
                    }
                }
            }

            $has_overview = count($split['overview']) > 0;
            $show_scrub_overview = ! isset($selected_dev) && ($has_overview || count($rows) === 0);

            // ---- Scrub Overview Panel ----
            if ($show_scrub_overview) {
                $render_scrub_overview_panel($split, $rows, $command_badge, $panel_col_class);
            }

            // ---- Scrub Per Device Panel ----
            if (isset($selected_dev)) {
                $render_scrub_per_device_panel($scrub_split, $panel_col_class, $selected_dev_path);
            } else {
                $render_scrub_per_device_fs_panel($scrub_split, $selected_dev_path, $path_to_dev_id, $link_array, $selected_fs);
            }

            continue;
        }

        $panel_title = $command_name === 'device_usage'
            ? $format_command_name($command_name)
            : $format_command_name($command_name) . ' ' . $command_badge;
        $render_generic_panel(
            $command_name,
            $panel_title,
            $panel_col_class,
            $split,
            $selected_dev,
            $selected_dev_path,
            $path_to_dev_id,
            $link_array,
            $selected_fs
        );
    }
}

// Close the Bootstrap row opened for the per-filesystem view
if (isset($selected_fs)) {
    echo '</div>';
}

// -----------------------------------------------------------------------------
// Section 7: Graph Panels
// Renders RRD graph panels below all data panels. Graph set depends on view:
//   - Overview page: no graphs (use overview table)
//   - Per-filesystem view: configurable via graph type menu
//   - Per-device view: configurable via graph type menu
// -----------------------------------------------------------------------------

$graphs = [];
if (! $is_overview) {
    if (isset($selected_dev)) {
        $all_graphs = [
            'btrfs_dev_usage' => 'Usage',
            'btrfs_dev_errors' => 'IO Errors (Rate)',
            'btrfs_dev_errors_counter' => 'IO Errors (Total)',
            'btrfs_dev_scrub_errors' => 'Scrub Errors (Total)',
            'btrfs_dev_scrub_errors_derive' => 'Scrub Errors (Rate)',
        ];
    } else {
        $all_graphs = [
            'btrfs_fs_space' => 'Filesystem Space',
            'btrfs_fs_data_types' => 'Per Data Type',
            'btrfs_fs_free' => 'Filesystem Free Space',
            'btrfs_fs_ratios' => 'Data/Metadata Ratios',
            'btrfs_fs_errors_by_type' => 'Aggregate Errors by Type',
            'btrfs_fs_errors_by_device' => 'Aggregate Errors by Device',
        ];
    }

    // Filter to single graph if specified, otherwise show all
    $current_graph = $vars['graph'] ?? null;
    if ($current_graph !== null && $current_graph !== '' && isset($all_graphs[$current_graph])) {
        $graphs = [$current_graph => $all_graphs[$current_graph]];
    } else {
        $graphs = $all_graphs;
    }
}

foreach ($graphs as $key => $text) {
    $graph_array = [];
    $graph_array['height'] = '100';
    $graph_array['width'] = '215';
    $graph_array['to'] = App\Facades\LibrenmsConfig::get('time.now');
    $graph_array['id'] = $app['app_id'];
    $graph_array['type'] = 'application_' . $key;

    if (isset($selected_fs)) {
        $graph_array['fs'] = $selected_fs;
    }
    if (isset($selected_dev)) {
        $graph_array['dev'] = $selected_dev;
    }

    echo '<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title">' . $text . '</h3>
    </div>
    <div class="panel-body">
    <div class="row">';
    include 'includes/html/print-graphrow.inc.php';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}
