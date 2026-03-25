<?php

// =============================================================================

require_once __DIR__ . '/../../btrfs-common.inc.php';
// Btrfs Application Page
// Renders the device/app page for Btrfs monitoring.
// Three views: Overview (all filesystems), Per-filesystem, Per-device.
// Data source contract: consumes normalized app->data emitted by poller btrfs.inc.php.
// Status rendering rule: prefer live state sensors, then fall back to stored poller status codes.
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

$btrfs_print_sticky_first_css();

// Poller-provided datasets used throughout this page.
// Load all data from app->data that was populated by the poller.
// Uses canonical structured keys (filesystem/device/scrub/balance maps).
$filesystems = $app->data['filesystems'] ?? [];
$filesystem_meta = $app->data['filesystem_meta'] ?? [];
$device_map = $app->data['device_map'] ?? [];
$filesystem_tables = $app->data['filesystem_tables'] ?? [];
$device_tables = $app->data['device_tables'] ?? [];
$device_metadata = $app->data['device_metadata'] ?? [];
$scrub_status_fs = $app->data['scrub_status_fs'] ?? [];
$scrub_status_devices = $app->data['scrub_status_devices'] ?? [];
$balance_status_fs = $app->data['balance_status_fs'] ?? [];
$scrub_is_running_fs = $app->data['scrub_is_running_fs'] ?? [];
$balance_is_running_fs = $app->data['balance_is_running_fs'] ?? [];
$filesystem_uuid = $app->data['filesystem_uuid'] ?? [];
$fs_rrd_key = $app->data['fs_rrd_key'] ?? [];
sort($filesystems);

$state_sensor_values = [];
// Load live sensor_current values so UI can show freshest state even if app->data
// was produced in a previous poll cycle.
$btrfs_state_sensors = App\Models\Sensor::where('device_id', $device['device_id'])
    ->where('sensor_class', 'state')
    ->where('poller_type', 'agent')
    ->whereIn('sensor_type', ['btrfsIoStatusState', 'btrfsScrubStatusState', 'btrfsBalanceStatusState'])
    ->get(['sensor_type', 'sensor_index', 'sensor_current']);
foreach ($btrfs_state_sensors as $state_sensor) {
    $state_sensor_values[$state_sensor->sensor_type][$state_sensor->sensor_index] = (int) $state_sensor->sensor_current;
}

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
    || str_starts_with($metric, 'usage_')
    || str_starts_with($metric, 'usage.')
    || str_starts_with($metric, 'raid_profiles.')
    || str_contains($metric, 'bytes')
    || str_starts_with($metric, 'data_')
    || str_starts_with($metric, 'metadata_')
    || str_starts_with($metric, 'system_');

$is_error_metric = static fn (string $metric): bool => str_contains($metric, 'errs')
    || str_contains($metric, 'errors')
    || str_contains($metric, 'devid')
    || $metric === 'id';

$si_count_metrics = [
    'data_extents_scrubbed',
    'tree_extents_scrubbed',
    'no_csum',
];

$format_metric_value = static function ($value, string $metric) use ($is_byte_metric, $is_error_metric, $si_count_metrics): string {
    if ($value === null) {
        return '';
    }

    if (str_contains($metric, 'ratio')) {
        return number_format((float) $value, 2);
    }

    if ($is_error_metric($metric) && is_numeric($value)) {
        return number_format((int) round((float) $value));
    }

    if (in_array($metric, $si_count_metrics, true) && is_numeric($value)) {
        return \LibreNMS\Util\Number::formatSi((float) $value, 2, 0, '');
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

$hms_to_seconds = static function (string $value): ?int {
    $parts = explode(':', trim($value));
    if (count($parts) !== 3) {
        return null;
    }

    foreach ($parts as $part) {
        if (! preg_match('/^\d+$/', $part)) {
            return null;
        }
    }

    return ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (int) $parts[2];
};

// Format a value for table display: handles null/empty, booleans, and numeric formatting.
$format_display_value = static function ($value, string $key) use ($format_metric_value, $hms_to_seconds): string {
    if ($value === null || $value === '') {
        return '-';
    }

    if ($value === 'true' || $value === true) {
        return 'Yes';
    }
    if ($value === 'false' || $value === false) {
        return 'No';
    }

    if (($key === 'duration' || $key === 'time_left') && is_numeric($value)) {
        $formatted = \LibreNMS\Util\Time::formatInterval((int) round((float) $value));

        return $formatted !== '' ? $formatted : (string) $value;
    }

    if (($key === 'duration' || $key === 'time_left') && is_string($value)) {
        $seconds = $hms_to_seconds($value);
        if ($seconds !== null) {
            $formatted = \LibreNMS\Util\Time::formatInterval($seconds);

            return $formatted !== '' ? $formatted : $value;
        }
    }

    if (($key === 'scrub_started' || $key === 'eta') && is_string($value) && strtotime($value) !== false) {
        try {
            return \LibreNMS\Util\Time::format($value, 'long');
        } catch (\Exception) {
            // Fall back to raw value when parsing fails.
        }
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

$format_device_display_name = static function (?string $path, ?string $devId = null, bool $isMissing = false): string {
    $path_text = trim((string) ($path ?? ''));
    if ($isMissing) {
        return $devId !== null && $devId !== ''
            ? 'devid ' . $devId . ' (Missing)'
            : 'Missing';
    }

    if ($path_text === '') {
        return (string) ($devId ?? 'unknown');
    }

    return $path_text;
};

// Generate a Bootstrap label badge HTML string for a given state.
// States: ok (gray), running (gray), warning (orange), error (red).
// NOTE: Keep normal states neutral (`label-default`) instead of green success.
// This follows ISA-101 HMI guidance: reserve high-salience colors for abnormal conditions.
$status_badge = $btrfs_status_badge;
$status_from_code = $btrfs_status_from_code;

$state_code_from_sensor = static function (string $sensor_type, string $sensor_index, $fallback = null) use ($state_sensor_values): int {
    // Prefer live sensor value when present, otherwise use poller snapshot code.
    if (isset($state_sensor_values[$sensor_type][$sensor_index]) && is_numeric($state_sensor_values[$sensor_type][$sensor_index])) {
        return (int) $state_sensor_values[$sensor_type][$sensor_index];
    }

    return is_numeric($fallback) ? (int) $fallback : 2;
};

$state_code_from_running_flag = static function ($running_flag, $fallback = null): int {
    if (is_bool($running_flag)) {
        return $running_flag ? 1 : 0;
    }

    return is_numeric($fallback) ? (int) $fallback : 2;
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

// Preferred metric ordering for the per-device view table.
$device_metric_order = [
    'path',
    'devid',
    'missing',
    'device.vendor',
    'device.model',
    'device.serial',
    'backing.devnode',
    'backing.model',
    'backing.serial',
    'errors.write_io_errs',
    'errors.read_io_errs',
    'errors.flush_io_errs',
    'errors.corruption_errs',
    'errors.generation_errs',
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

require_once __DIR__ . '/btrfs-panels.inc.php';

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
        $filesystem_label = $filesystem_meta[$fs]['label'] ?? null;
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
    // Device submenu is scoped to the currently selected filesystem.
    echo '<br />Devices: ';
    $devices = $device_map[$selected_fs];
    asort($devices);
    $i = 0;
    foreach ($devices as $dev_id => $dev_path) {
        $is_missing_device = (bool) (($device_tables[$selected_fs][$dev_id]['missing'] ?? false));
        $dev_label = $format_device_display_name((string) $dev_path, (string) $dev_id, $is_missing_device);
        $dev_path_label = htmlspecialchars($dev_label);
        $label = ($selected_dev === $dev_id)
            ? '<span class="pagemenu-selected">' . $dev_path_label . '</span>'
            : $dev_path_label;

        echo generate_link($label, $link_array, ['fs' => $selected_fs, 'dev' => $dev_id]);
        if (++$i < count($devices)) {
            echo ', ';
        }
    }
}

print_optionbar_end();

// -----------------------------------------------------------------------------
// Section 4: Overview Page (when no filesystem is selected)
// Shows filesystem summary table.
// -----------------------------------------------------------------------------

// Filesystem summary table
if ($is_overview && count($filesystems) > 0) {
    // Top-level filesystem table (one row per filesystem on this device).
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Filesystems Overview</h3></div>';
    echo '<div class="panel-body">';
    echo '<div class="table-responsive">';
    echo '<table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
    echo '<thead><tr><th>Filesystem</th><th>Status</th><th>Scrub</th><th>Balance</th><th>Scrub Progress</th><th>IO Errors</th><th>% Used</th><th>Used</th><th>Free (Estimated)</th><th>Device Size</th><th>Data Ratio</th><th>Metadata Ratio</th><th>Devices</th><th>Combined Status</th></tr></thead>';
    echo '<tbody>';

    foreach ($filesystems as $fs) {
        // Resolve display name + status + summary metrics for one filesystem row.
        $fs_data = $filesystem_tables[$fs] ?? [];
        $fs_devices = $device_map[$fs] ?? [];
        $fs_label = trim((string) ($filesystem_meta[$fs]['label'] ?? ''));
        $display_name = $fs_label !== '' ? $fs_label . ' (' . $fs . ')' : (string) $fs;
        $scrub_status = $scrub_status_fs[$fs] ?? [];
        $scrub_progress_text = is_array($scrub_status) && count($scrub_status) > 0
            ? $btrfs_scrub_progress_text_from_status($scrub_status)
            : 'N/A';

        $total_errors = $btrfs_total_io_errors($device_tables[$fs] ?? []);

        $used_percent_text = $btrfs_used_percent_text($fs_data['used'] ?? null, $fs_data['device_size'] ?? null);

        $fs_rrd_id = $fs_rrd_key[$fs] ?? $fs;
        $io_code = $state_code_from_sensor('btrfsIoStatusState', (string) $fs_rrd_id . '.io', $fs_data['io_status_code'] ?? null);
        $scrub_code = $state_code_from_sensor('btrfsScrubStatusState', (string) $fs_rrd_id . '.scrub', $fs_data['scrub_status_code'] ?? null);
        $scrub_fallback_code = $state_code_from_running_flag($scrub_is_running_fs[$fs] ?? null, $fs_data['scrub_status_code'] ?? null);
        $balance_fallback_code = $state_code_from_running_flag($balance_is_running_fs[$fs] ?? null, $fs_data['balance_status_code'] ?? null);
        $scrub_code = $state_code_from_sensor('btrfsScrubStatusState', (string) $fs_rrd_id . '.scrub', $scrub_fallback_code);
        $balance_code = $state_code_from_sensor('btrfsBalanceStatusState', (string) $fs_rrd_id . '.balance', $balance_fallback_code);
        $io_state = $status_from_code($io_code);
        $scrub_state = $status_from_code($scrub_code);
        $balance_state = $status_from_code($balance_code);

        $graph_array = [
            'height' => 40,
            'width' => 180,
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'id' => $app['app_id'],
            'type' => 'application_btrfs_fs_status',
            'fs' => $fs,
            'legend' => 'no',
            'from' => App\Facades\LibrenmsConfig::get('time.week'),
        ];

        $graph_link_array = $graph_array;
        $graph_link_array['page'] = 'graphs';
        unset($graph_link_array['height'], $graph_link_array['width']);
        $graph_link = LibreNMS\Util\Url::generate($graph_link_array);
        $graph_img = LibreNMS\Util\Url::lazyGraphTag($graph_array);

        echo '<tr>';
        echo '<td>' . generate_link(htmlspecialchars((string) $display_name), $link_array, ['fs' => $fs]) . '</td>';
        echo '<td>' . $status_badge($io_state) . '</td>';
        echo '<td>' . $status_badge($scrub_state) . '</td>';
        echo '<td>' . $status_badge($balance_state) . '</td>';
        echo '<td>' . htmlspecialchars($scrub_progress_text) . '</td>';
        echo '<td>' . htmlspecialchars(number_format($total_errors)) . '</td>';
        echo '<td>' . htmlspecialchars($used_percent_text) . '</td>';
        echo '<td>' . htmlspecialchars($format_metric_value($fs_data['used'] ?? null, 'used')) . '</td>';
        echo '<td>' . htmlspecialchars($format_metric_value($fs_data['free_estimated'] ?? null, 'free_estimated')) . '</td>';
        echo '<td>' . htmlspecialchars($format_metric_value($fs_data['device_size'] ?? null, 'device_size')) . '</td>';
        echo '<td>' . htmlspecialchars($format_metric_value($fs_data['data_ratio'] ?? null, 'data_ratio')) . '</td>';
        echo '<td>' . htmlspecialchars($format_metric_value($fs_data['metadata_ratio'] ?? null, 'metadata_ratio')) . '</td>';
        echo '<td>' . number_format(count($fs_devices)) . '</td>';
        echo '<td>' . LibreNMS\Util\Url::overlibLink($graph_link, $graph_img, $display_name . ' - Combined Status') . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    $overview_graph_types = [
        'btrfs_fs_errors_by_type' => 'Aggregate Errors by Type',
        'btrfs_fs_errors_by_device' => 'Aggregate Errors by Device',
        'btrfs_fs_space' => 'Filesystem Space',
        'btrfs_fs_scrub_bytes' => 'Scrub Rate',
        'btrfs_fs_data_types' => 'Per Data Type',
    ];

    foreach ($filesystems as $fs) {
        // Per-filesystem graph panel block under overview table.
        $fs_data = $filesystem_tables[$fs] ?? [];
        $fs_label = trim((string) ($filesystem_meta[$fs]['label'] ?? ''));
        $display_name = $fs_label !== '' ? $fs_label . ' (' . $fs . ')' : (string) $fs;

        $used_value = (float) ($fs_data['used'] ?? 0);
        $size_value = (float) ($fs_data['device_size'] ?? 0);
        $used_text = $format_metric_value($fs_data['used'] ?? null, 'used');
        $total_text = $format_metric_value($fs_data['device_size'] ?? null, 'device_size');
        $used_percent_text = $size_value > 0
            ? rtrim(rtrim(number_format(($used_value / $size_value) * 100, 2, '.', ''), '0'), '.') . '%'
            : 'N/A';

        $fs_rrd_id = $fs_rrd_key[$fs] ?? $fs;
        $io_code = $state_code_from_sensor('btrfsIoStatusState', (string) $fs_rrd_id . '.io', $fs_data['io_status_code'] ?? null);
        $scrub_fallback_code = $state_code_from_running_flag($scrub_is_running_fs[$fs] ?? null, $fs_data['scrub_status_code'] ?? null);
        $balance_fallback_code = $state_code_from_running_flag($balance_is_running_fs[$fs] ?? null, $fs_data['balance_status_code'] ?? null);
        $scrub_code = $state_code_from_sensor('btrfsScrubStatusState', (string) $fs_rrd_id . '.scrub', $scrub_fallback_code);
        $balance_code = $state_code_from_sensor('btrfsBalanceStatusState', (string) $fs_rrd_id . '.balance', $balance_fallback_code);
        $overall_code = $btrfs_combine_state_code([$io_code, $scrub_code, $balance_code]);
        $overall_state = $status_from_code($overall_code);

        $fs_link = \LibreNMS\Util\Url::generate([
            'page' => 'device',
            'device' => $device['device_id'],
            'tab' => 'apps',
            'app' => 'btrfs',
            'fs' => $fs,
        ]);

        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><h3 class="panel-title"><a href="' . $fs_link . '" style="color:#337ab7;">' . htmlspecialchars($display_name) . '</a><div class="pull-right"><small class="text-muted">' . htmlspecialchars($used_text . '/' . $total_text . ' ' . $used_percent_text) . '</small> ' . $status_badge($overall_state) . '</div></h3></div>';
        echo '<div class="panel-body"><div class="row">';

        foreach ($overview_graph_types as $graph_type => $graph_title) {
            $graph_array = [
                'height' => '100',
                'width' => '220',
                'to' => App\Facades\LibrenmsConfig::get('time.now'),
                'from' => App\Facades\LibrenmsConfig::get('time.day'),
                'id' => $app['app_id'],
                'type' => 'application_' . $graph_type,
                'fs' => $fs,
                'legend' => 'no',
            ];

            echo '<div class="pull-left" style="margin-right: 8px;">';
            echo '<div class="text-muted" style="font-size: 11px; margin-bottom: 4px;">' . htmlspecialchars($graph_title) . '</div>';
            echo '<a href="' . $fs_link . '">' . \LibreNMS\Util\Url::lazyGraphTag($graph_array) . '</a>';
            echo '</div>';
        }

        echo '</div></div>';
        echo '</div>';
    }
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
    $filesystem_label = $filesystem_meta[$selected_fs]['label'] ?? null;
    $selected_fs_uuid = (string) ($filesystem_uuid[$selected_fs] ?? '');
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
    if ($selected_fs_uuid !== '') {
        echo '<p><strong>UUID:</strong> ' . htmlspecialchars($selected_fs_uuid) . '</p>';
    }

    // Renders the metric pairs table in a variable number of columns
    // depending on the visible breakpoint (lg=4, md=3, sm=2, xs=1).
    $renderOverviewTable = static function (array $pairs, int $columns) use ($format_display_name, $format_display_value): void {
        $rows = (int) ceil(count($pairs) / $columns);

        echo '<table class="table table-condensed table-striped btrfs-sticky-first">';
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
    $dev_metrics_flat = [];
    $selected_device_table = $device_tables[$selected_fs][$selected_dev];
    foreach ($btrfs_flatten_assoc_rows(is_array($selected_device_table) ? $selected_device_table : []) as $row) {
        $dev_metrics_flat[(string) $row['key']] = $row['value'];
    }

    $selected_device_metadata = $device_metadata[$selected_fs][$selected_dev] ?? [];
    $append_metadata_metric = static function (array &$metrics, string $key, $value): void {
        if (is_bool($value)) {
            $metrics[$key] = $value ? 'true' : 'false';

            return;
        }

        if (is_scalar($value)) {
            $text = trim((string) $value);
            if ($text !== '' && strtolower($text) !== 'null') {
                $metrics[$key] = $value;
            }
        }
    };

    if (is_array($selected_device_metadata)) {
        $primary = is_array($selected_device_metadata['primary'] ?? null) ? $selected_device_metadata['primary'] : [];
        $primary_identity = is_array($primary['device'] ?? null) ? $primary['device'] : [];
        $append_metadata_metric($dev_metrics_flat, 'device.vendor', $primary_identity['vendor'] ?? null);
        $append_metadata_metric($dev_metrics_flat, 'device.model', $primary_identity['model'] ?? null);
        $append_metadata_metric($dev_metrics_flat, 'device.serial', $primary_identity['serial'] ?? null);

        $backing = is_array($selected_device_metadata['backing'] ?? null) ? $selected_device_metadata['backing'] : [];
        $append_metadata_metric($dev_metrics_flat, 'backing.devnode', $backing['devnode'] ?? null);
        $backing_identity = is_array($backing['device'] ?? null) ? $backing['device'] : [];
        $append_metadata_metric($dev_metrics_flat, 'backing.model', $backing_identity['model'] ?? null);
        $append_metadata_metric($dev_metrics_flat, 'backing.serial', $backing_identity['serial'] ?? null);
    }

    $dev_metrics = $ordered_metric_pairs($dev_metrics_flat, $device_metric_order);
    $selected_dev_path = $device_tables[$selected_fs][$selected_dev]['path'] ?? null;
    $selected_fs_rrd_id = $fs_rrd_key[$selected_fs] ?? $selected_fs;
    $dev_io_code = $state_code_from_sensor('btrfsIoStatusState', (string) $selected_fs_rrd_id . '.dev.' . $selected_dev . '.io', null);
    $dev_scrub_code = $state_code_from_sensor('btrfsScrubStatusState', (string) $selected_fs_rrd_id . '.dev.' . $selected_dev . '.scrub', null);
    $dev_state = $status_from_code($btrfs_combine_state_code([$dev_io_code, $dev_scrub_code]));

    echo '<div class="col-md-4">';
    echo '<div class="panel panel-default">';
    $device_title = 'Device Metrics';
    if (is_string($selected_dev_path) && $selected_dev_path !== '') {
        $is_selected_dev_missing = (bool) (($device_tables[$selected_fs][$selected_dev]['missing'] ?? false));
        $device_title .= ': ' . $format_device_display_name($selected_dev_path, (string) $selected_dev, $is_selected_dev_missing);
    }
    echo '<div class="panel-heading"><h3 class="panel-title">' . htmlspecialchars($device_title) . '<div class="pull-right">' . $status_badge($dev_state) . '</div></h3></div>';
    echo '<div class="panel-body">';
    echo '<div class="table-responsive">';
    echo '<table class="table table-condensed table-striped btrfs-sticky-first">';
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
// Section 6: Detail Panels
// Renders canonical structured panels for selected filesystem:
// Balance, Device Usage, Device Stats, Scrub Overview, Scrub Per Device.
// -----------------------------------------------------------------------------

if (isset($selected_fs) && isset($filesystem_tables[$selected_fs])) {
    $selected_fs_rrd_id = $fs_rrd_key[$selected_fs] ?? $selected_fs;
    // Build a reverse map from device path to device ID for generating links.
    $path_to_dev_id = [];
    foreach ($device_map[$selected_fs] ?? [] as $dev_id => $dev_path) {
        $path_to_dev_id[(string) $dev_path] = (string) $dev_id;
    }
    $selected_dev_path = isset($selected_dev, $device_map[$selected_fs][$selected_dev]) ? (string) $device_map[$selected_fs][$selected_dev] : null;

    // Balance panel
    if (! isset($selected_dev)) {
        $balance_data = $balance_status_fs[$selected_fs] ?? [];
        $balance_split = ['overview' => [], 'devices' => [], 'device_columns' => []];
        if (is_array($balance_data)) {
            foreach ($balance_data as $key => $value) {
                if ($key === 'profiles' || $key === 'lines') {
                    continue;
                }

                if (is_array($value)) {
                    $balance_split['overview'] = array_merge($balance_split['overview'], $btrfs_flatten_assoc_rows([$key => $value]));
                } elseif (is_bool($value)) {
                    $balance_split['overview'][] = ['key' => (string) $key, 'value' => $value ? 'true' : 'false'];
                } elseif ($value === null) {
                    $balance_split['overview'][] = ['key' => (string) $key, 'value' => 'null'];
                } else {
                    $balance_split['overview'][] = ['key' => (string) $key, 'value' => (string) $value];
                }
            }

            $profiles = $balance_data['profiles'] ?? [];
            if (is_array($profiles)) {
                foreach ($profiles as $index => $profile) {
                    if (! is_array($profile)) {
                        continue;
                    }
                    $balance_split['devices'][(string) $index] = $profile;
                    foreach ($profile as $column => $unused) {
                        if (! in_array((string) $column, $balance_split['device_columns'], true)) {
                            $balance_split['device_columns'][] = (string) $column;
                        }
                    }
                }
            }
        }

        $balance_fallback_code = $state_code_from_running_flag(
            $balance_is_running_fs[$selected_fs] ?? null,
            $filesystem_tables[$selected_fs]['balance_status_code'] ?? 2
        );
        $balance_status_code = $state_code_from_sensor(
            'btrfsBalanceStatusState',
            (string) $selected_fs_rrd_id . '.balance',
            $balance_fallback_code
        );
        $render_balance_panel($balance_split, $balance_split['overview'], $panel_col_class, $selected_dev, $balance_status_code);
    }

    // Device usage panel
    $usage_split = ['overview' => [], 'devices' => [], 'device_columns' => ['id', 'slack']];
    foreach (($device_map[$selected_fs] ?? []) as $dev_id => $dev_path) {
        $dev_stats = $device_tables[$selected_fs][$dev_id] ?? [];
        if (! is_array($dev_stats) || count($dev_stats) === 0) {
            continue;
        }

        $usage_row = [
            'id' => (string) $dev_id,
            'slack' => $dev_stats['usage']['slack'] ?? null,
        ];

        $type_values = $dev_stats['raid_profiles'] ?? [];
        if (is_array($type_values)) {
            ksort($type_values);
            foreach ($type_values as $type_key => $type_value) {
                $usage_row[(string) $type_key] = $type_value;
                if (! in_array((string) $type_key, $usage_split['device_columns'], true)) {
                    $usage_split['device_columns'][] = (string) $type_key;
                }
            }
        }

        // Keep size and unallocated as trailing columns after profile/type data.
        $usage_row['size'] = $dev_stats['usage']['size'] ?? null;
        $usage_row['unallocated'] = $dev_stats['usage']['unallocated'] ?? null;
        if (! in_array('size', $usage_split['device_columns'], true)) {
            $usage_split['device_columns'][] = 'size';
        }
        if (! in_array('unallocated', $usage_split['device_columns'], true)) {
            $usage_split['device_columns'][] = 'unallocated';
        }

        $usage_split['devices'][(string) $dev_path] = $usage_row;
    }
    $render_generic_panel('device_usage', 'Device Usage', null, $panel_col_class, $usage_split, $selected_dev, $selected_dev_path, $path_to_dev_id, $link_array, $selected_fs);

    // Device IO stats panel (filesystem view only)
    if (! isset($selected_dev)) {
        $stats_split = ['overview' => [], 'devices' => [], 'device_columns' => ['corruption_errs', 'flush_io_errs', 'generation_errs', 'read_io_errs', 'write_io_errs']];
        foreach (($device_map[$selected_fs] ?? []) as $dev_id => $dev_path) {
            $dev_stats = $device_tables[$selected_fs][$dev_id] ?? [];
            if (! is_array($dev_stats) || count($dev_stats) === 0) {
                continue;
            }

            $stats_split['devices'][(string) $dev_path] = [
                'missing' => $dev_stats['missing'] ?? null,
                'corruption_errs' => $dev_stats['errors']['corruption_errs'] ?? null,
                'flush_io_errs' => $dev_stats['errors']['flush_io_errs'] ?? null,
                'generation_errs' => $dev_stats['errors']['generation_errs'] ?? null,
                'read_io_errs' => $dev_stats['errors']['read_io_errs'] ?? null,
                'write_io_errs' => $dev_stats['errors']['write_io_errs'] ?? null,
            ];
        }

        $io_status_code = $state_code_from_sensor(
            'btrfsIoStatusState',
            (string) $selected_fs_rrd_id . '.io',
            $filesystem_tables[$selected_fs]['io_status_code'] ?? null
        );
        $io_badge = $status_badge($status_from_code($io_status_code));
        $render_generic_panel('device_stats', 'Device Stats', $io_badge, $panel_col_class, $stats_split, $selected_dev, $selected_dev_path, $path_to_dev_id, $link_array, $selected_fs);
    }

    // Scrub panels
    $scrub_fallback_code = $state_code_from_running_flag(
        $scrub_is_running_fs[$selected_fs] ?? null,
        $filesystem_tables[$selected_fs]['scrub_status_code'] ?? null
    );
    $scrub_status_code = $state_code_from_sensor(
        'btrfsScrubStatusState',
        (string) $selected_fs_rrd_id . '.scrub',
        $scrub_fallback_code
    );
    $scrub_badge = $status_badge($status_from_code($scrub_status_code));

    $scrub_status_fs_data = $scrub_status_fs[$selected_fs] ?? [];
    $scrub_status_devices_data = $scrub_status_devices[$selected_fs] ?? [];

    $scrub_split = [
        'overview' => is_array($scrub_status_fs_data) ? $btrfs_flatten_assoc_rows($scrub_status_fs_data) : [],
        'devices' => [],
        'device_columns' => [],
    ];

    if (is_array($scrub_status_devices_data) && count($scrub_status_devices_data) > 0) {
        foreach ($scrub_status_devices_data as $dev_id => $scrub_metrics) {
            if (! is_array($scrub_metrics)) {
                continue;
            }

            $dev_id_str = (string) $dev_id;
            $dev_path = (string) ($device_map[$selected_fs][$dev_id_str] ?? ($scrub_metrics['path'] ?? $dev_id_str));
            $scrub_split['devices'][$dev_path] = $scrub_metrics;

            foreach ($scrub_metrics as $metric_key => $unused_metric_value) {
                if (! in_array($metric_key, $scrub_split['device_columns'], true)) {
                    $scrub_split['device_columns'][] = $metric_key;
                }
            }
        }
    }

    $scrub_rows = $scrub_split['overview'];
    $has_scrub_overview = count($scrub_split['overview']) > 0;
    $show_scrub_overview = ! isset($selected_dev) && ($has_scrub_overview || count($scrub_rows) === 0);
    if ($show_scrub_overview) {
        $render_scrub_overview_panel($scrub_split, $scrub_rows, $scrub_badge, $panel_col_class);
    }

    if (isset($selected_dev)) {
        $render_scrub_per_device_panel($scrub_split, $panel_col_class, $selected_dev_path);
    } else {
        $render_scrub_per_device_fs_panel($scrub_split, $selected_dev_path, $path_to_dev_id, $link_array, $selected_fs);
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
$selected_diskio = null;
if (! $is_overview) {
    if (isset($selected_dev)) {
        $all_graphs = [
            'btrfs_dev_usage' => 'Usage',
            'btrfs_dev_errors' => 'IO Errors (Rate)',
            'btrfs_dev_errors_counter' => 'IO Errors (Total)',
            'btrfs_dev_scrub_errors' => 'Scrub Errors (Total)',
            'btrfs_dev_scrub_errors_derive' => 'Scrub Errors (Rate)',
        ];

        $selected_dev_path = isset($selected_fs)
            ? trim((string) ($device_tables[$selected_fs][$selected_dev]['path'] ?? ''))
            : '';
        if ($selected_dev_path !== '') {
            $diskio_candidates = [];
            $preferred_diskio_candidates = [];

            $diskio_candidates[] = $selected_dev_path;
            $without_dev_prefix = preg_replace('#^/dev/#', '', $selected_dev_path);
            if (is_string($without_dev_prefix) && $without_dev_prefix !== '') {
                $diskio_candidates[] = $without_dev_prefix;
            }

            $path_basename = basename($selected_dev_path);
            if ($path_basename !== '') {
                $diskio_candidates[] = $path_basename;
            }

            $selected_dev_metadata = isset($selected_fs)
                ? ($device_metadata[$selected_fs][$selected_dev] ?? [])
                : [];
            if (is_array($selected_dev_metadata)) {
                $primary_meta = is_array($selected_dev_metadata['primary'] ?? null) ? $selected_dev_metadata['primary'] : [];
                $backing_meta = is_array($selected_dev_metadata['backing'] ?? null) ? $selected_dev_metadata['backing'] : [];

                $primary_devnode = trim((string) ($primary_meta['devnode'] ?? ''));
                if ($primary_devnode !== '') {
                    $diskio_candidates[] = $primary_devnode;
                    $diskio_candidates[] = ltrim((string) preg_replace('#^/dev/#', '', $primary_devnode), '/');
                    $diskio_candidates[] = basename($primary_devnode);
                }

                $primary_name = trim((string) ($primary_meta['name'] ?? ''));
                if ($primary_name !== '') {
                    $diskio_candidates[] = $primary_name;
                    $diskio_candidates[] = '/dev/' . $primary_name;
                }

                $backing_name = trim((string) ($backing_meta['name'] ?? ''));
                if ($backing_name !== '') {
                    $preferred_diskio_candidates[] = $backing_name;
                    $preferred_diskio_candidates[] = '/dev/' . $backing_name;
                    $diskio_candidates[] = $backing_name;
                    $diskio_candidates[] = '/dev/' . $backing_name;
                }

                $backing_devnode = trim((string) ($backing_meta['devnode'] ?? ''));
                if ($backing_devnode !== '') {
                    $preferred_diskio_candidates[] = $backing_devnode;
                    $preferred_diskio_candidates[] = ltrim((string) preg_replace('#^/dev/#', '', $backing_devnode), '/');
                    $preferred_diskio_candidates[] = basename($backing_devnode);
                    $diskio_candidates[] = $backing_devnode;
                    $diskio_candidates[] = ltrim((string) preg_replace('#^/dev/#', '', $backing_devnode), '/');
                    $diskio_candidates[] = basename($backing_devnode);
                }
            }

            $diskio_candidates = array_values(array_unique($diskio_candidates));
            $preferred_diskio_candidates = array_values(array_unique(array_merge($preferred_diskio_candidates, $diskio_candidates)));
            $diskio_rows = dbFetchRows('SELECT `diskio_id`, `diskio_descr` FROM `ucd_diskio` WHERE `device_id` = ?', [$device['device_id']]);
            $diskio_by_descr = [];
            foreach ($diskio_rows as $diskio_row) {
                $diskio_descr = trim((string) ($diskio_row['diskio_descr'] ?? ''));
                if ($diskio_descr !== '') {
                    $diskio_by_descr[$diskio_descr] = $diskio_row;
                }
            }

            foreach ($preferred_diskio_candidates as $candidate) {
                if (isset($diskio_by_descr[$candidate])) {
                    $selected_diskio = $diskio_by_descr[$candidate];

                    break;
                }
            }

            if (! is_array($selected_diskio) && count($diskio_rows) === 1) {
                $selected_diskio = $diskio_rows[0];
            }
        }
    } else {
        $all_graphs = [
            'btrfs_fs_space' => 'Filesystem Space',
            'btrfs_fs_scrub_bytes' => 'Filesystem Scrub Rate',
            'btrfs_fs_data_types' => 'Per Data Type',
            'btrfs_fs_free' => 'Filesystem Free Space',
            'btrfs_fs_ratios' => 'Data/Metadata Ratios',
            'btrfs_fs_errors_by_type' => 'Aggregate Errors by Type',
            'btrfs_fs_errors_by_device' => 'Aggregate Errors by Device',
        ];
    }

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

    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">' . $text . '</h3></div>';
    echo '<div class="panel-body"><div class="row">';
    include 'includes/html/print-graphrow.inc.php';
    echo '</div></div>';
    echo '</div>';
}

if (isset($selected_dev) && is_array($selected_diskio) && isset($selected_diskio['diskio_id'])) {
    $diskio_id = $selected_diskio['diskio_id'];
    $diskio_descr = trim((string) ($selected_diskio['diskio_descr'] ?? ''));
    $diskio_label = $diskio_descr !== '' ? $diskio_descr : (string) $diskio_id;
    $diskio_types = [
        'diskio_ops' => 'Disk I/O Ops/sec',
        'diskio_bits' => 'Disk I/O bps',
    ];

    foreach ($diskio_types as $diskio_type => $diskio_title) {
        $graph_array = [];
        $graph_array['height'] = '100';
        $graph_array['width'] = '215';
        $graph_array['to'] = App\Facades\LibrenmsConfig::get('time.now');
        $graph_array['id'] = $diskio_id;
        $graph_array['type'] = $diskio_type;

        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><h3 class="panel-title">' . htmlspecialchars($diskio_title . ': ' . $diskio_label) . '</h3></div>';
        echo '<div class="panel-body"><div class="row">';
        include 'includes/html/print-graphrow.inc.php';
        echo '</div></div>';
        echo '</div>';
    }
}

if (isset($selected_fs) && ! isset($selected_dev)) {
    $diskio_aggregate_types = [
        'btrfs_fs_diskio_ops' => 'Disk I/O Aggregate Ops/sec',
        'btrfs_fs_diskio_bits' => 'Disk I/O Aggregate bps',
    ];

    foreach ($diskio_aggregate_types as $graph_type => $graph_title) {
        $graph_array = [];
        $graph_array['height'] = '100';
        $graph_array['width'] = '215';
        $graph_array['to'] = App\Facades\LibrenmsConfig::get('time.now');
        $graph_array['id'] = $app['app_id'];
        $graph_array['fs'] = $selected_fs;
        $graph_array['type'] = 'application_' . $graph_type;

        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><h3 class="panel-title">' . htmlspecialchars($graph_title) . '</h3></div>';
        echo '<div class="panel-body"><div class="row">';
        include 'includes/html/print-graphrow.inc.php';
        echo '</div></div>';
        echo '</div>';
    }
}
