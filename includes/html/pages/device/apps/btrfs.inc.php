<?php

// =============================================================================
// Btrfs Application Page
// Renders the device/app page for Btrfs monitoring.
// Three views: Overview (all filesystems), Per-filesystem, Per-device.
// Data source contract: consumes normalized app->data emitted by poller btrfs.inc.php.
// Status rendering rule: prefer live state sensors, then fall back to stored poller status codes.
// =============================================================================

require_once __DIR__ . '/../../btrfs-common.inc.php';

echo '<style>
.btrfs-panels {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
}
.btrfs-panels > .panel {
    flex: 1 1 45%;
    min-width: 300px;
}
.btrfs-panels > .panel-wide {
    flex: 1 1 30%;
}
.btrfs-panels > .col-xs-12 {
    flex: 0 0 100%;
}
@media (min-width: 1400px) {
    .btrfs-panels > .panel { flex: 1 1 30%; }
    .btrfs-panels > .panel-wide { flex: 1 1 30%; }
}
@media (min-width: 1800px) {
    .btrfs-panels > .panel { flex: 1 1 23%; }
    .btrfs-panels > .panel-wide { flex: 1 1 23%; }
}
</style>';

// -----------------------------------------------------------------------------
// Helper Functions
// These functions encapsulate rendering logic for each page section.
// -----------------------------------------------------------------------------

function btrfs_initializeData(App\Models\Application $app, array $device, array $vars): array
{
    $selected_fs = $vars['fs'] ?? null;
    $selected_dev = $vars['dev'] ?? null;

    $filesystem_entries = $app->data['filesystems'] ?? [];
    $has_structured_filesystems = is_array($filesystem_entries) && count($filesystem_entries) > 0 && is_array(reset($filesystem_entries));

    if ($has_structured_filesystems) {
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

        foreach ($filesystem_entries as $fs_name => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $filesystem_meta[$fs_name] = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            $device_map[$fs_name] = is_array($entry['device_map'] ?? null) ? $entry['device_map'] : [];
            $filesystem_tables[$fs_name] = is_array($entry['table'] ?? null) ? $entry['table'] : [];
            $device_tables[$fs_name] = is_array($entry['device_tables'] ?? null) ? $entry['device_tables'] : [];
            $device_metadata[$fs_name] = is_array($entry['device_metadata'] ?? null) ? $entry['device_metadata'] : [];
            $filesystem_profiles[$fs_name] = is_array($entry['profiles'] ?? null) ? $entry['profiles'] : [];
            $scrub_block = is_array($entry['scrub'] ?? null) ? $entry['scrub'] : [];
            $balance_block = is_array($entry['balance'] ?? null) ? $entry['balance'] : [];
            $scrub_status_fs[$fs_name] = is_array($scrub_block['status'] ?? null) ? $scrub_block['status'] : [];
            $scrub_status_devices[$fs_name] = is_array($scrub_block['devices'] ?? null) ? $scrub_block['devices'] : [];
            $scrub_is_running_fs[$fs_name] = (bool) ($scrub_block['is_running'] ?? false);
            $balance_status_fs[$fs_name] = is_array($balance_block['status'] ?? null) ? $balance_block['status'] : [];
            $balance_is_running_fs[$fs_name] = (bool) ($balance_block['is_running'] ?? false);
            $filesystem_uuid[$fs_name] = (string) ($entry['uuid'] ?? '');
            $fs_rrd_key[$fs_name] = (string) ($entry['rrd_key'] ?? $fs_name);
        }
        $filesystems = array_keys($filesystem_entries);
    } else {
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

    sort($filesystems);

    if (! is_string($selected_fs) || ! in_array($selected_fs, $filesystems, true)) {
        $selected_fs = null;
    }

    if (! is_scalar($selected_dev) || (string) $selected_dev === '') {
        $selected_dev = null;
    } else {
        $selected_dev_raw = $selected_dev;
        $selected_dev = (string) $selected_dev;
        if (! isset($selected_fs, $device_map[$selected_fs])) {
            $selected_dev = null;
        } else {
            $dev_keys = array_keys((array) $device_map[$selected_fs]);
            $dev_key_found = in_array($selected_dev, $dev_keys, true)
                || (is_numeric($selected_dev) && in_array((int) $selected_dev, $dev_keys, true));
            if (! $dev_key_found) {
                $selected_dev = null;
            }
        }
    }

    $is_overview = ! isset($selected_fs);

    return [
        'selected_fs' => $selected_fs,
        'selected_dev' => $selected_dev,
        'is_overview' => $is_overview,
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

function btrfs_loadStateSensors(int $device_id): array
{
    $state_sensor_values = [];
    $btrfs_state_sensors = App\Models\Sensor::where('device_id', $device_id)
        ->where('sensor_class', 'state')
        ->where('poller_type', 'agent')
        ->whereIn('sensor_type', ['btrfsIoStatusState', 'btrfsScrubStatusState', 'btrfsBalanceStatusState'])
        ->get(['sensor_type', 'sensor_index', 'sensor_current']);
    foreach ($btrfs_state_sensors as $state_sensor) {
        $state_sensor_values[$state_sensor->sensor_type][$state_sensor->sensor_index] = (int) $state_sensor->sensor_current;
    }

    return $state_sensor_values;
}

function btrfs_createClosures(array $state_sensor_values): array
{
    $status_from_code = static function ($value): string {
        $code = is_numeric($value) ? (int) $value : 2;
        return match ($code) {
            0 => 'ok',
            1 => 'running',
            -1 => 'na',
            3 => 'error',
            4 => 'missing',
            default => 'na',
        };
    };

    $status_badge = static function (string $state): string {
        $state_lc = strtolower($state);
        if ($state_lc === 'error') {
            $badge = 'Error';
            $class = 'label-danger';
        } elseif ($state_lc === 'missing') {
            $badge = 'Missing';
            $class = 'label-danger';
        } elseif ($state_lc === 'running') {
            $badge = 'Running';
            $class = 'label-default';
        } elseif ($state_lc === 'warning') {
            $badge = 'Warning';
            $class = 'label-warning';
        } elseif ($state_lc === 'na') {
            $badge = 'N/A';
            $class = 'label-default';
        } else {
            $badge = 'OK';
            $class = 'label-default';
        }
        return '<span class="label ' . $class . '">' . htmlspecialchars($badge) . '</span>';
    };

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
            return \LibreNMS\Util\Number::formatBi((float) $value, 2, 0, 'B');
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

    $format_display_name = static function (string $key): string {
        $name = preg_replace('/\[([0-9]+)\]/', ' $1', $key);
        $name = str_replace(['.', '_', '-'], ' ', (string) $name);

        return ucwords((string) $name);
    };

    $to_bool = static fn ($value): ?bool => match (true) {
        is_bool($value) => $value,
        is_string($value) && in_array(strtolower($value), ['true', 'yes', '1'], true) => true,
        is_string($value) && in_array(strtolower($value), ['false', 'no', '0', ''], true) => false,
        is_numeric($value) => (bool) (int) $value,
        default => null,
    };

    $format_device_display_name = static function (string $path_text, ?string $devId = null, bool $is_missing = false) use ($to_bool): string {
        $is_missing = $to_bool($is_missing) ?? false;
        if ($is_missing) {
            if ($devId !== null) {
                return 'devid ' . $devId;
            }

            return '<missing>';
        }

        if ($path_text === '') {
            return (string) ($devId ?? 'unknown');
        }

        return $path_text;
    };

    $state_code_from_sensor = static function (string $sensor_type, string $sensor_index, $fallback = null) use ($state_sensor_values): int {
        if (isset($state_sensor_values[$sensor_type][$sensor_index]) && is_numeric($state_sensor_values[$sensor_type][$sensor_index])) {
            return (int) $state_sensor_values[$sensor_type][$sensor_index];
        }

        return is_numeric($fallback) ? (int) $fallback : 2;
    };

    $state_code_from_running_flag = static fn ($running_flag, $fallback = null): int => is_bool($running_flag) ? ($running_flag ? 1 : 0) : (is_numeric($fallback) ? (int) $fallback : 2);

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

    $combine_state_code = static function (array $codes): int {
        $normalized = [];
        foreach ($codes as $code) {
            $normalized[] = is_numeric($code) ? (int) $code : 2;
        }
        if (in_array(4, $normalized, true)) { return 4; }
        if (in_array(3, $normalized, true)) { return 3; }
        if (in_array(1, $normalized, true)) { return 1; }
        if (in_array(0, $normalized, true)) { return 0; }
        return 2;
    };

    $scrub_progress_text_from_status = static function (array $scrub_status): string {
        $scrub_progress = null;
        if (is_array($scrub_status['bytes_scrubbed'] ?? null)) {
            $progress = $scrub_status['bytes_scrubbed']['progress'] ?? null;
            if (is_numeric($progress)) {
                $scrub_progress = (float) $progress;
            }
        }
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
        return $scrub_progress === null
            ? 'N/A'
            : rtrim(rtrim(number_format($scrub_progress, 2, '.', ''), '0'), '.') . '%';
    };

    $total_io_errors = static function (array $device_tables): float {
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
    };

    $format_used_percent = static function ($used_value, $size_value): string {
        $used = (float) ($used_value ?? 0);
        $size = (float) ($size_value ?? 0);
        return $size > 0
            ? rtrim(rtrim(number_format(($used / $size) * 100, 2, '.', ''), '0'), '.') . '%'
            : 'N/A';
    };

    $flatten_assoc_rows = static function (array $data, string $prefix = '') use (&$flatten_assoc_rows): array {
        $rows = [];
        foreach ($data as $key => $value) {
            $segment = is_int($key) ? '[' . $key . ']' : (string) $key;
            $path = $prefix === '' ? $segment : $prefix . '.' . $segment;
            if (is_array($value)) {
                $rows = array_merge($rows, $flatten_assoc_rows($value, $path));
                continue;
            }
            if (is_bool($value)) {
                $rows[] = ['key' => $path, 'value' => $value ? 'true' : 'false'];
            } elseif ($value === null) {
                $rows[] = ['key' => $path, 'value' => 'null'];
            } else {
                $rows[] = ['key' => $path, 'value' => (string) $value];
            }
        }
        return $rows;
    };

    return [
        'format_metric_value' => $format_metric_value,
        'format_display_name' => $format_display_name,
        'to_bool' => $to_bool,
        'format_device_display_name' => $format_device_display_name,
        'state_code_from_sensor' => $state_code_from_sensor,
        'state_code_from_running_flag' => $state_code_from_running_flag,
        'ordered_metric_pairs' => $ordered_metric_pairs,
        'status_badge' => $status_badge,
        'status_from_code' => $status_from_code,
        'combine_state_code' => $combine_state_code,
        'scrub_progress_text_from_status' => $scrub_progress_text_from_status,
        'total_io_errors' => $total_io_errors,
        'used_percent_text' => $format_used_percent,
        'flatten_assoc_rows' => $flatten_assoc_rows,
    ];
}

function btrfs_renderNavigation(
    array $link_array,
    array $filesystems,
    ?string $selected_fs,
    ?string $selected_dev,
    array $filesystem_meta,
    array $device_map,
    array $device_tables,
    callable $format_device_display_name
): void {
    print_optionbar_start();

    $overview_label = ! isset($selected_fs)
        ? '<span class="pagemenu-selected">Overview</span>'
        : 'Overview';
    echo generate_link($overview_label, $link_array);

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

    if (isset($selected_fs) && isset($device_map[$selected_fs]) && count($device_map[$selected_fs]) > 0) {
        echo '<br />&nbsp;&nbsp;&nbsp;Devices: ';
        $devices = $device_map[$selected_fs];
        asort($devices);
        $i = 0;
        foreach ($devices as $dev_id => $dev_path) {
            $is_missing_device = (bool) ($device_tables[$selected_fs][$dev_id]['missing'] ?? false);
            $dev_label = $format_device_display_name((string) $dev_path, (string) $dev_id, $is_missing_device);
            $dev_path_label = htmlspecialchars($dev_label);
            $is_selected = ((string) $selected_dev === (string) $dev_id) || ($selected_dev !== null && (int) $selected_dev === $dev_id);
            $label = $is_selected
                ? '<span class="pagemenu-selected">' . $dev_path_label . '</span>'
                : $dev_path_label;

            echo generate_link($label, $link_array, ['fs' => $selected_fs, 'dev' => $dev_id]);
            if (++$i < count($devices)) {
                echo ', ';
            }
        }
    }

    print_optionbar_end();
}

function btrfs_renderOverviewPage(
    App\Models\Application $app,
    array $device,
    array $filesystems,
    array $filesystem_meta,
    array $filesystem_tables,
    array $device_map,
    array $device_tables,
    array $scrub_status_fs,
    array $scrub_is_running_fs,
    array $balance_is_running_fs,
    array $fs_rrd_key,
    callable $state_code_from_sensor,
    callable $state_code_from_running_flag,
    callable $status_from_code,
    callable $status_badge,
    callable $format_metric_value,
    callable $format_display_name,
    callable $combine_state_code,
    callable $scrub_progress_text_from_status,
    callable $total_io_errors,
    callable $used_percent_text,
    callable $flatten_assoc_rows
): void {
    $debug_user = Auth::user();
    $debug_is_admin = is_object($debug_user) && method_exists($debug_user, 'hasGlobalAdmin') && $debug_user->hasGlobalAdmin();
    $debug_enabled = (bool) App\Facades\LibrenmsConfig::get('apps.btrfs.debug', false);
    $show_debug_panel = $debug_enabled && $debug_is_admin;

    if ($show_debug_panel) {
        $overview_debug = [
            'app_data' => $app->data ?? [],
        ];
        $overview_debug_json = json_encode($overview_debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';

        $debug_panel_id = 'btrfs-debug-overview-' . (int) ($app['app_id'] ?? 0);
        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><h3 class="panel-title">Debug</h3></div>';
        echo '<div class="panel-body">';
        echo '<a class="btn btn-xs btn-default" role="button" data-toggle="collapse" href="#' . $debug_panel_id . '" aria-expanded="false" aria-controls="' . $debug_panel_id . '">Toggle Raw Payload</a>';
        echo '<div id="' . $debug_panel_id . '" class="collapse" style="margin-top:8px;">';
        echo '<pre style="max-height: 260px; overflow: auto; margin-bottom: 0;">' . htmlspecialchars($overview_debug_json) . '</pre>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Filesystems Overview</h3></div>';
    echo '<div class="panel-body">';
    echo '<div class="table-responsive">';
    echo '<table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
    echo '<thead><tr><th>Filesystem</th><th>Status</th><th>Scrub</th><th>Balance</th><th>Scrub Progress</th><th>IO Errors</th><th>% Used</th><th>Used</th><th>Free (Estimated)</th><th>Device Size</th><th>Missing</th><th>Devices</th><th>Ops</th><th>Bps</th><th>Combined Status</th></tr></thead>';
    echo '<tbody>';

    foreach ($filesystems as $fs) {
        $fs_data = $filesystem_tables[$fs] ?? [];
        $fs_devices = $device_map[$fs] ?? [];
        $fs_label = trim((string) ($filesystem_meta[$fs]['label'] ?? ''));
        $display_name = $fs_label !== '' ? $fs_label . ' (' . $fs . ')' : (string) $fs;
        $scrub_status = $scrub_status_fs[$fs] ?? [];
        $scrub_progress_text = is_array($scrub_status) && count($scrub_status) > 0
            ? $scrub_progress_text_from_status($scrub_status)
            : 'N/A';

        $total_errors = $total_io_errors($device_tables[$fs] ?? []);
        $used_pct_text = $used_percent_text($fs_data['used'] ?? null, $fs_data['device_size'] ?? null);

        $fs_rrd_id = $fs_rrd_key[$fs] ?? $fs;
        $io_code = $state_code_from_sensor('btrfsIoStatusState', (string) $fs_rrd_id . '.io', $fs_data['io_status_code'] ?? null);
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

        $ops_graph = [
            'height' => 30,
            'width' => 120,
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'from' => App\Facades\LibrenmsConfig::get('time.day'),
            'id' => $app['app_id'],
            'type' => 'application_btrfs_fs_diskio_ops',
            'fs' => $fs,
            'legend' => 'no',
        ];
        $bps_graph = [
            'height' => 30,
            'width' => 120,
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'from' => App\Facades\LibrenmsConfig::get('time.day'),
            'id' => $app['app_id'],
            'type' => 'application_btrfs_fs_diskio_bits',
            'fs' => $fs,
            'legend' => 'no',
        ];

        echo '<tr>';
        echo '<td>' . generate_link(htmlspecialchars((string) $display_name), ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'btrfs'], ['fs' => $fs]) . '</td>';
        echo '<td>' . $status_badge($io_state) . '</td>';
        echo '<td>' . $status_badge($scrub_state) . '</td>';
        echo '<td>' . $status_badge($balance_state) . '</td>';
        echo '<td>' . htmlspecialchars($scrub_progress_text) . '</td>';
        echo '<td>' . htmlspecialchars(number_format($total_errors)) . '</td>';
        echo '<td>' . htmlspecialchars($used_pct_text) . '</td>';
        echo '<td>' . htmlspecialchars($format_metric_value($fs_data['used'] ?? null, 'used')) . '</td>';
        echo '<td>' . htmlspecialchars($format_metric_value($fs_data['free_estimated'] ?? null, 'free_estimated')) . '</td>';
        echo '<td>' . htmlspecialchars($format_metric_value($fs_data['device_size'] ?? null, 'device_size')) . '</td>';
        echo '<td>' . (($fs_data['has_missing'] ?? false) ? '<span class="label label-danger">Yes</span>' : '<span class="label label-default">No</span>') . '</td>';
        echo '<td>' . number_format(count($fs_devices)) . '</td>';
        echo '<td>' . generate_link(LibreNMS\Util\Url::lazyGraphTag($ops_graph), ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'btrfs'], ['fs' => $fs]) . '</td>';
        echo '<td>' . generate_link(LibreNMS\Util\Url::lazyGraphTag($bps_graph), ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'btrfs'], ['fs' => $fs]) . '</td>';
        echo '<td>' . LibreNMS\Util\Url::overlibLink($graph_link, $graph_img, $display_name . ' - Combined Status') . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    btrfs_renderOverviewPageGraphs($app, $device, $filesystems, $filesystem_meta, $filesystem_tables, $fs_rrd_key, $scrub_is_running_fs, $balance_is_running_fs, $state_code_from_sensor, $state_code_from_running_flag, $status_from_code, $status_badge, $format_metric_value, $combine_state_code);
}

function btrfs_renderOverviewPageGraphs(
    App\Models\Application $app,
    array $device,
    array $filesystems,
    array $filesystem_meta,
    array $filesystem_tables,
    array $fs_rrd_key,
    array $scrub_is_running_fs,
    array $balance_is_running_fs,
    callable $state_code_from_sensor,
    callable $state_code_from_running_flag,
    callable $status_from_code,
    callable $status_badge,
    callable $format_metric_value,
    callable $combine_state_code
): void {
    $overview_graph_types = [
        'btrfs_fs_errors_by_type' => 'Aggregate Errors by Type',
        'btrfs_fs_errors_by_device' => 'Aggregate Errors by Device',
        'btrfs_fs_space' => 'Filesystem Space',
        'btrfs_fs_scrub_bytes' => 'Scrub Rate',
        'btrfs_fs_data_types' => 'Per Data Type',
        'btrfs_fs_diskio_ops' => 'Aggregate Ops/sec',
        'btrfs_fs_diskio_bits' => 'Aggregate Bps',
    ];

    foreach ($filesystems as $fs) {
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
        $overall_code = $combine_state_code([$io_code, $scrub_code, $balance_code]);
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
                'height' => '80',
                'width' => '180',
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

function btrfs_renderDevView(
    App\Models\Application $app,
    array $device,
    string $selected_fs,
    string $selected_dev,
    array $filesystem_meta,
    array $filesystem_tables,
    array $device_map,
    array $device_tables,
    array $device_metadata,
    array $filesystem_profiles,
    array $scrub_status_fs,
    array $scrub_status_devices,
    array $balance_status_fs,
    array $scrub_is_running_fs,
    array $balance_is_running_fs,
    array $filesystem_uuid,
    array $fs_rrd_key,
    array $tables,
    callable $state_code_from_sensor,
    callable $state_code_from_running_flag,
    callable $status_from_code,
    callable $status_badge,
    callable $format_metric_value,
    callable $format_display_name,
    callable $format_device_display_name,
    callable $ordered_metric_pairs,
    callable $to_bool,
    callable $combine_state_code,
    callable $scrub_progress_text_from_status,
    callable $flatten_assoc_rows
): void {
    $is_byte_metric = static fn (string $metric): bool =>
        str_contains($metric, 'size')
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

    $format_display_value = static function ($value, string $metric) use ($is_byte_metric): string {
        if ($value === null) {
            return '';
        }
        if ($is_byte_metric($metric)) {
            return \LibreNMS\Util\Number::formatBi((float) $value, 2, 0, 'B');
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    };

    $selected_fs_rrd_id = $fs_rrd_key[$selected_fs] ?? $selected_fs;
    $link_array = ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'btrfs'];

    $dev_tables = $tables['filesystem_devices'] ?? [];
    $dev_backing = $tables['backing_devices'] ?? [];
    $dev_info = $tables['devices'] ?? [];

    $fs_uuid = $filesystem_uuid[$selected_fs] ?? '';
    $dev_data = $dev_tables[$fs_uuid][$selected_dev] ?? [];
    $dev_path = $dev_data['device_path'] ?? '';
    $dev_missing = $dev_data['missing'] ?? false;
    $backing_path = $dev_data['backing_device_path'] ?? null;

    $dev_status = $device_tables[$selected_fs][$selected_dev] ?? [];
    $dev_io_code = $dev_status['io_status_code'] ?? null;
    $dev_scrub_code = $dev_status['scrub_status_code'] ?? null;

    $io_code = $state_code_from_sensor('btrfsIoStatusState', $selected_fs_rrd_id . '.dev.' . $selected_dev . '.io', $dev_io_code);
    $scrub_code = $state_code_from_sensor('btrfsScrubStatusState', $selected_fs_rrd_id . '.dev.' . $selected_dev . '.scrub', $dev_scrub_code);
    $overall_code = $combine_state_code([$io_code, $scrub_code]);
    $overall_state = $status_from_code($overall_code);

    echo '<div class="btrfs-panels">';

    echo '<div class="panel panel-default panel-wide">';
    echo '<div class="panel-heading"><h3 class="panel-title">Device Info<div class="pull-right">' . $status_badge($overall_state) . '</div></h3></div>';
    echo '<div class="panel-body">';

    $info_rows = [];
    $info_rows[] = ['key' => 'name', 'value' => $dev_path];
    $info_rows[] = ['key' => 'dev_id', 'value' => $selected_dev];
    if ($fs_uuid !== '') {
        $info_rows[] = ['key' => 'uuid', 'value' => $fs_uuid];
    }

    $device_info = $dev_info[$dev_path] ?? [];
    $id_model = $device_info['id_model'] ?? $device_info['model'] ?? null;
    $id_serial = $device_info['id_serial_short'] ?? null;
    if ($id_model !== null) {
        $info_rows[] = ['key' => 'model', 'value' => $id_model];
    }
    if ($id_serial !== null) {
        $info_rows[] = ['key' => 'serial', 'value' => $id_serial];
    }

    if ($backing_path !== null) {
        $backing_info = $dev_backing[$backing_path] ?? [];
        $info_rows[] = ['key' => 'backing_device', 'value' => $backing_path];
        $backing_model = $backing_info['id_model'] ?? null;
        $backing_serial = $backing_info['id_serial_short'] ?? null;
        $backing_size = $backing_info['size_bytes'] ?? null;
        if ($backing_model !== null) {
            $info_rows[] = ['key' => 'backing_model', 'value' => $backing_model];
        }
        if ($backing_serial !== null) {
            $info_rows[] = ['key' => 'backing_serial', 'value' => $backing_serial];
        }
        if ($backing_size !== null) {
            $info_rows[] = ['key' => 'backing_size', 'value' => $format_display_value($backing_size, 'bytes')];
        }
    }

    $size = $dev_data['size'] ?? null;
    if ($size !== null) {
        $info_rows[] = ['key' => 'size', 'value' => $format_display_value($size, 'bytes')];
    }

    $dev_errors = $device_tables[$selected_fs][$selected_dev]['errors'] ?? [];
    $error_keys = ['write_io_errs', 'read_io_errs', 'flush_io_errs', 'corruption_errs', 'generation_errs'];
    foreach ($error_keys as $err_key) {
        if (isset($dev_errors[$err_key])) {
            $info_rows[] = ['key' => $err_key, 'value' => $format_metric_value($dev_errors[$err_key], $err_key)];
        }
    }

    $dev_profiles = $device_tables[$selected_fs][$selected_dev]['raid_profiles'] ?? [];
    foreach ($dev_profiles as $profile_key => $profile_bytes) {
        $info_rows[] = ['key' => $profile_key, 'value' => $format_display_value($profile_bytes, 'bytes')];
    }

    echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
    echo '<thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
    foreach ($info_rows as $row) {
        echo '<tr><td>' . htmlspecialchars($format_display_name((string) $row['key'])) . '</td>';
        echo '<td>' . htmlspecialchars($row['value']) . '</td></tr>';
    }
    echo '</tbody></table></div></div></div>';

    $dev_scrub = $scrub_status_devices[$selected_fs][$selected_dev] ?? [];
    $scrub_status_code = $state_code_from_sensor(
        'btrfsScrubStatusState',
        $selected_fs_rrd_id . '.scrub',
        $state_code_from_running_flag($scrub_is_running_fs[$selected_fs] ?? false, $filesystem_tables[$selected_fs]['scrub_status_code'] ?? null)
    );
    $scrub_badge = $status_badge($status_from_code($scrub_status_code));

    echo '<div class="panel panel-default panel-wide">';
    echo '<div class="panel-heading"><h3 class="panel-title">Scrub<div class="pull-right">' . $scrub_badge . '</div></h3></div>';
    echo '<div class="panel-body">';

    $scrub_hidden = ['path'];
    $scrub_keys = array_keys($dev_scrub);
    $scrub_display_keys = array_diff($scrub_keys, $scrub_hidden);

    if (count($scrub_display_keys) === 0) {
        echo '<em>No scrub data available</em>';
    } else {
        echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
        echo '<thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
        foreach ($scrub_display_keys as $key) {
            $value = $dev_scrub[$key];
            $display_value = $format_display_value($value, (string) $key);
            echo '<tr><td>' . htmlspecialchars($format_display_name((string) $key)) . '</td>';
            echo '<td>' . htmlspecialchars($display_value) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></div>';

    echo '</div>';
}

function btrfs_renderFsView(
    App\Models\Application $app,
    array $device,
    string $selected_fs,
    array $filesystem_meta,
    array $filesystem_tables,
    array $device_map,
    array $device_tables,
    array $device_metadata,
    array $filesystem_profiles,
    array $scrub_status_fs,
    array $scrub_status_devices,
    array $balance_status_fs,
    array $scrub_is_running_fs,
    array $balance_is_running_fs,
    array $filesystem_uuid,
    array $fs_rrd_key,
    callable $state_code_from_sensor,
    callable $state_code_from_running_flag,
    callable $status_from_code,
    callable $status_badge,
    callable $format_metric_value,
    callable $format_display_name,
    callable $format_device_display_name,
    callable $ordered_metric_pairs,
    callable $to_bool,
    callable $combine_state_code,
    callable $scrub_progress_text_from_status,
    callable $flatten_assoc_rows
): void {
    $selected_fs_rrd_id = $fs_rrd_key[$selected_fs] ?? $selected_fs;
    $path_to_dev_id = [];
    foreach ($device_map[$selected_fs] ?? [] as $dev_id => $dev_path) {
        $path_to_dev_id[(string) $dev_path] = (string) $dev_id;
    }

    $scrub_status_code = $state_code_from_sensor(
        'btrfsScrubStatusState',
        $selected_fs_rrd_id . '.scrub',
        $state_code_from_running_flag($scrub_is_running_fs[$selected_fs] ?? false, $filesystem_tables[$selected_fs]['scrub_status_code'] ?? null)
    );
    $scrub_badge = $status_badge($status_from_code($scrub_status_code));

    $scrub_split = [
        'overview' => is_array($scrub_status_fs[$selected_fs] ?? null) ? $flatten_assoc_rows($scrub_status_fs[$selected_fs]) : [],
        'devices' => [],
        'device_columns' => [],
    ];

    if (is_array($scrub_status_devices[$selected_fs] ?? null) && count($scrub_status_devices[$selected_fs] ?? []) > 0) {
        foreach ($scrub_status_devices[$selected_fs] as $dev_id => $scrub_metrics) {
            if (! is_array($scrub_metrics)) {
                continue;
            }
            $dev_id_str = (string) $dev_id;
            $dev_path = (string) ($device_map[$selected_fs][$dev_id_str] ?? ($scrub_metrics['path'] ?? $dev_id_str));
            $scrub_split['devices'][$dev_path] = $scrub_metrics;
            foreach ($scrub_metrics as $metric_key => $unused) {
                if (! in_array($metric_key, $scrub_split['device_columns'], true)) {
                    $scrub_split['device_columns'][] = $metric_key;
                }
            }
        }
    }

    $is_byte_metric = static fn (string $metric): bool =>
        str_contains($metric, 'size')
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

    $format_display_value = static function ($value, string $metric) use ($is_byte_metric): string {
        if ($value === null) {
            return '';
        }
        if ($is_byte_metric($metric)) {
            return \LibreNMS\Util\Number::formatBi((float) $value, 2, 0, 'B');
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    };

    btrfs_renderFsPanelsRow1(
        $app, $device, $selected_fs, $selected_fs_rrd_id, $filesystem_meta, $filesystem_tables,
        $device_map, $balance_status_fs, $scrub_is_running_fs, $scrub_badge, $scrub_split,
        $state_code_from_sensor, $state_code_from_running_flag, $status_badge, $format_display_name, $flatten_assoc_rows,
        $format_display_value
    );

    btrfs_renderFsPanelsRow2(
        $app, $device, $selected_fs, $selected_fs_rrd_id,
        $filesystem_tables, $device_map, $device_tables, $device_metadata, $filesystem_profiles, $path_to_dev_id,
        $state_code_from_sensor, $status_from_code, $status_badge, $format_display_name, $format_metric_value,
        $format_device_display_name, $ordered_metric_pairs, $flatten_assoc_rows, $combine_state_code
    );

    btrfs_renderScrubPerDevice(
        $scrub_split, $path_to_dev_id, $device['device_id'], $selected_fs,
        $format_display_name, $format_metric_value, $status_badge
    );
}

function btrfs_renderFsPanelsRow1(
    App\Models\Application $app,
    array $device,
    string $selected_fs,
    string $selected_fs_rrd_id,
    array $filesystem_meta,
    array $filesystem_tables,
    array $device_map,
    array $balance_status_fs,
    array $scrub_is_running_fs,
    string $scrub_badge,
    array $scrub_split,
    callable $state_code_from_sensor,
    callable $state_code_from_running_flag,
    callable $status_badge,
    callable $format_display_name,
    callable $flatten_assoc_rows,
    callable $format_display_value
): void {
    $render_balance_panel = static function (array $split, array $rows, ?string $panel_col_class, ?string $selected_dev, int $balance_status_code) use ($status_badge, $format_display_name, $format_display_value): void {
        $state = match ($balance_status_code) {
            0 => 'ok', 1 => 'running', 2 => 'na', 3 => 'error', 4 => 'missing', default => 'na',
        };
        $badge = $status_badge($state);
        $overview_rows = [];
        foreach ($split['overview'] as $row) {
            if ($row['key'] !== 'path' && $row['key'] !== 'status') {
                $overview_rows[] = $row;
            }
        }
        $profiles = $split['devices'];
        $has_profiles = count($profiles) > 0;
        $has_overview = count($overview_rows) > 0;
        $is_idle = $balance_status_code !== 1;
        $show_overview = ($has_profiles || (! $is_idle && $has_overview));

        if ($panel_col_class !== null) {
            echo '<div class="' . $panel_col_class . '">';
        }
        echo '<div class="panel panel-default panel-wide">';
        echo '<div class="panel-heading"><h3 class="panel-title">Balance<div class="pull-right">' . $badge . '</div></h3></div>';
        echo '<div class="panel-body">';
        if ($is_idle && ! $has_profiles) {
            echo '<p class="text-muted">No balance operation running.</p>';
        }
        if ($show_overview && count($overview_rows) > 0) {
            echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
            echo '<thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
            foreach ($overview_rows as $row) {
                echo '<tr><td>' . htmlspecialchars($format_display_name($row['key'])) . '</td>';
                echo '<td>' . htmlspecialchars($format_display_value($row['value'], $row['key'])) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div>';
        if ($panel_col_class !== null) {
            echo '</div>';
        }
    };

    echo '<div class="btrfs-panels">';

    $renderOverviewTable = static function (array $pairs, int $columns) use ($format_display_name, $format_display_value): void {
        $rows = (int) ceil(count($pairs) / $columns);
        echo '<table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
        echo '<thead><tr>';
        for ($c = 0; $c < $columns; $c++) {
            echo '<th>Metric</th><th>Value</th>';
        }
        echo '</tr></thead><tbody>';
        for ($r = 0; $r < $rows; $r++) {
            echo '<tr>';
            for ($c = 0; $c < $columns; $c++) {
                $index = ($r * $columns) + $c;
                if (! isset($pairs[$index])) {
                    echo '<td></td><td></td>';
                    continue;
                }
                echo '<td>' . htmlspecialchars($format_display_name((string) $pairs[$index]['metric'])) . '</td>';
                echo '<td>' . htmlspecialchars($format_display_value($pairs[$index]['value'], (string) $pairs[$index]['metric'])) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    };

    $overview_metric_keys = ['device_size', 'device_unallocated', 'free_estimated', 'free_statfs_df', 'device_allocated', 'used', 'free_estimated_min', 'global_reserve'];
    $overview_pairs = [];
    foreach ($overview_metric_keys as $metric_key) {
        $overview_pairs[] = ['metric' => $metric_key, 'value' => $filesystem_tables[$selected_fs][$metric_key] ?? null];
    }
    $fs_label = $filesystem_meta[$selected_fs]['label'] ?? null;
    $fs_title = ! empty($fs_label) ? $fs_label . ' (' . $selected_fs . ')' : $selected_fs;
    $fs_uuid = $filesystem_meta[$selected_fs]['uuid'] ?? '';

    echo '<div class="panel panel-default panel-wide">';
    echo '<div class="panel-heading"><h3 class="panel-title">Overview</h3></div>';
    echo '<div class="panel-body">';
    echo '<p><strong>Filesystem:</strong> ' . htmlspecialchars($fs_title) . '</p>';
    if ($fs_uuid !== '') {
        echo '<p><strong>UUID:</strong> ' . htmlspecialchars($fs_uuid) . '</p>';
    }
    echo '<div class="table-responsive">';
    $renderOverviewTable($overview_pairs, 2);
    echo '</div></div></div>';

    $render_scrub_overview_panel = static function (array $split, array $rows, string $badge) use ($format_display_name, $format_display_value): void {
        if (true) { echo '<div class="panel panel-default panel-wide">'; }
        echo '<div class="panel-heading"><h3 class="panel-title">Scrub<div class="pull-right">' . $badge . '</div></h3></div>';
        echo '<div class="panel-body">';
        if (count($rows) === 0) {
            echo '<em>No data returned</em>';
        } else {
            $overview_map = [];
            foreach ($split['overview'] as $overview_row) {
                $overview_map[$overview_row['key']] = $overview_row['value'];
            }
            echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
            echo '<thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
            foreach ($split['overview'] as $overview_row) {
                $key = $overview_row['key'];
                $value = $overview_row['value'];
                if ($key === 'status' || $key === 'uuid' || $key === 'bytes_scrubbed.progress') {
                    continue;
                }
                $display_key = $key;
                $display_value = $format_display_value($value, (string) $key);
                if ($key === 'bytes_scrubbed.bytes') {
                    $progress = $overview_map['bytes_scrubbed.progress'] ?? null;
                    $formatted = $format_display_value($value, 'bytes');
                    $display_value = is_numeric($progress)
                        ? $formatted . ' (' . rtrim(rtrim(number_format((float) $progress, 2, '.', ''), '0'), '.') . '%)'
                        : $formatted;
                    $display_key = 'total_bytes_done';
                } elseif ($key === 'total_to_scrub') {
                    $display_value = $format_display_value($value, 'bytes');
                }
                echo '<tr><td>' . htmlspecialchars($format_display_name($display_key)) . '</td>';
                echo '<td>' . htmlspecialchars($display_value) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div>';
    };
    $render_scrub_overview_panel($scrub_split, $scrub_split['overview'], $scrub_badge);

    $balance_split = ['overview' => [], 'devices' => [], 'device_columns' => []];
    $balance_data = $balance_status_fs[$selected_fs] ?? [];
    if (is_array($balance_data)) {
        foreach ($balance_data as $key => $value) {
            if ($key === 'profiles' || $key === 'lines') {
                continue;
            }
            if (is_array($value)) {
                $balance_split['overview'] = array_merge($balance_split['overview'], $flatten_assoc_rows([$key => $value]));
            } else {
                $balance_split['overview'][] = ['key' => (string) $key, 'value' => $value];
            }
        }
    }
    $render_balance_panel($balance_split, $balance_split['overview'], null, null, $filesystem_tables[$selected_fs]['balance_status_code'] ?? 2);

    echo '</div>';
}

function btrfs_renderFsPanelsRow2(
    App\Models\Application $app,
    array $device,
    string $selected_fs,
    string $selected_fs_rrd_id,
    array $filesystem_tables,
    array $device_map,
    array $device_tables,
    array $device_metadata,
    array $filesystem_profiles,
    array $path_to_dev_id,
    callable $state_code_from_sensor,
    callable $status_from_code,
    callable $status_badge,
    callable $format_display_name,
    callable $format_metric_value,
    callable $format_device_display_name,
    callable $ordered_metric_pairs,
    callable $flatten_assoc_rows,
    callable $combine_state_code
): void {
    $link_array = ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'btrfs'];

    echo '<div class="btrfs-panels">';

    $usage_devices = [];
    $all_raid_profiles = [];
    foreach ($device_map[$selected_fs] ?? [] as $dev_id => $dev_path) {
        $dev_stats = $device_tables[$selected_fs][$dev_id] ?? [];
        if (! is_array($dev_stats) || count($dev_stats) === 0) {
            continue;
        }
        $usage_devices[$dev_path] = $dev_stats;
        $raid_profiles = $dev_stats['raid_profiles'] ?? [];
        if (is_array($raid_profiles)) {
            $all_raid_profiles = array_unique(array_merge($all_raid_profiles, array_keys($raid_profiles)));
        }
    }
    sort($all_raid_profiles);

    if (count($usage_devices) > 0) {
        echo '<div class="panel panel-default panel-wide">';
        echo '<div class="panel-heading"><h3 class="panel-title">Device Usage</h3></div>';
        echo '<div class="panel-body">';
        echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
        echo '<thead><tr><th>Device</th><th>Size</th><th>Slack</th>';
        foreach ($all_raid_profiles as $profile) {
            echo '<th>' . htmlspecialchars($format_display_name($profile)) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($usage_devices as $dev_path => $dev_stats) {
            $usage = $dev_stats['usage'] ?? [];
            $raid_profiles = $dev_stats['raid_profiles'] ?? [];
            $dev_id = $path_to_dev_id[(string) $dev_path] ?? null;
            $link = $dev_id !== null
                ? generate_link(htmlspecialchars((string) $dev_path), $link_array, ['fs' => $selected_fs, 'dev' => $dev_id])
                : htmlspecialchars((string) $dev_path);
            echo '<tr><td>' . $link . '</td>';
            echo '<td>' . htmlspecialchars($format_metric_value($usage['size'] ?? null, 'device_size')) . '</td>';
            echo '<td>' . htmlspecialchars($format_metric_value($usage['slack'] ?? null, 'device_slack')) . '</td>';
            foreach ($all_raid_profiles as $profile) {
                echo '<td>' . htmlspecialchars($format_metric_value($raid_profiles[$profile] ?? null, 'bytes')) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></div></div>';
    }

    $stats_split = ['overview' => [], 'devices' => [], 'device_columns' => ['corruption_errs', 'flush_io_errs', 'generation_errs', 'read_io_errs', 'write_io_errs']];
        foreach ($device_map[$selected_fs] ?? [] as $dev_id => $dev_path) {
            $dev_stats = $device_tables[$selected_fs][$dev_id] ?? [];
            if (! is_array($dev_stats)) {
                continue;
            }
            $stats_split['devices'][$dev_path] = $dev_stats;
        }
        if (count($stats_split['devices']) > 0) {
            $fs_io_code = $state_code_from_sensor('btrfsIoStatusState', $selected_fs_rrd_id . '.io', $filesystem_tables[$selected_fs]['io_status_code'] ?? null);
            $fs_scrub_code = $state_code_from_sensor('btrfsScrubStatusState', $selected_fs_rrd_id . '.scrub', null);
            $fs_balance_code = $state_code_from_sensor('btrfsBalanceStatusState', $selected_fs_rrd_id . '.balance', null);
            $fs_overall_code = $combine_state_code([$fs_io_code, $fs_scrub_code, $fs_balance_code]);
            $fs_overall_state = $status_from_code($fs_overall_code);

            echo '<div class="panel panel-default panel-wide">';
            echo '<div class="panel-heading"><h3 class="panel-title">Device Stats<div class="pull-right">' . $status_badge($fs_overall_state) . '</div></h3></div>';
            echo '<div class="panel-body">';
            echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
            echo '<thead><tr><th>Device</th><th>Status</th>';
            foreach ($stats_split['device_columns'] as $col) {
                echo '<th>' . htmlspecialchars($format_display_name($col)) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($stats_split['devices'] as $dev_path => $metrics) {
                $dev_id = $path_to_dev_id[(string) $dev_path] ?? null;
                $link = $dev_id !== null
                    ? generate_link(htmlspecialchars((string) $dev_path), $link_array, ['fs' => $selected_fs, 'dev' => $dev_id])
                    : htmlspecialchars((string) $dev_path);
                $dev_io_code = $metrics['io_status_code'] ?? null;
                $dev_scrub_code = $metrics['scrub_status_code'] ?? null;
                $dev_overall_code = $combine_state_code([$dev_io_code ?? 2, $dev_scrub_code ?? 2]);
                $dev_overall_state = $status_from_code($dev_overall_code);
                $errors = $metrics['errors'] ?? [];
                echo '<tr><td>' . $link . '</td><td>' . $status_badge($dev_overall_state) . '</td>';
                foreach ($stats_split['device_columns'] as $col) {
                    echo '<td>' . htmlspecialchars($format_metric_value($errors[$col] ?? null, $col)) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></div></div></div>';
        }

    echo '</div>';
}

function btrfs_renderScrubPerDevice(
    array $scrub_split,
    array $path_to_dev_id,
    int $device_id,
    string $selected_fs,
    callable $format_display_name,
    callable $format_metric_value,
    callable $status_badge
): void {
    $link_array = ['page' => 'device', 'device' => $device_id, 'tab' => 'apps', 'app' => 'btrfs'];

    $scrub_status_to_state = static function (string $status): string {
        $status_lc = strtolower(trim((string) $status));
        return match ($status_lc) {
            'running', 'in_progress', 'in-progress' => 'running',
            'finished', 'done', 'idle', 'stopped', 'completed' => 'ok',
            'error', 'failed', 'aborted' => 'error',
            default => 'na',
        };
    };

    echo '<div class="btrfs-panels">';

    if (count($scrub_split['devices']) > 0) {
        $hidden_columns = ['path', 'id', 'section', 'has_status_suffix', 'has_stats', 'no_stats_available', 'last_physical'];
        echo '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title">Scrub Per Device</h3></div>';
        echo '<div class="panel-body">';
        echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
        echo '<thead><tr><th>Device</th><th>Status</th>';
        foreach ($scrub_split['device_columns'] as $column) {
            if (! in_array($column, $hidden_columns, true)) {
                echo '<th>' . htmlspecialchars($format_display_name($column)) . '</th>';
            }
        }
        echo '</tr></thead><tbody>';
        foreach ($scrub_split['devices'] as $device_name => $metrics) {
            $dev_id = $path_to_dev_id[(string) $device_name] ?? null;
            $link = $dev_id !== null
                ? generate_link(htmlspecialchars((string) $device_name), $link_array, ['fs' => $selected_fs, 'dev' => $dev_id])
                : htmlspecialchars((string) $device_name);
            $scrub_state = $scrub_status_to_state($metrics['status'] ?? '');
            echo '<tr><td>' . $link . '</td><td>' . $status_badge($scrub_state) . '</td>';
            foreach ($scrub_split['device_columns'] as $column) {
                if (! in_array($column, $hidden_columns, true)) {
                    $value = $metrics[$column] ?? '';
                    echo '<td>' . htmlspecialchars($format_metric_value($value, $column)) . '</td>';
                }
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></div></div>';
    } else {
        echo '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title">Scrub Per Device</h3></div>';
        echo '<div class="panel-body"><em>No per-device scrub details were reported.</em></div></div>';
    }

    echo '</div>';
}

function btrfs_renderDevGraphs(
    App\Models\Application $app,
    ?string $selected_fs,
    ?string $selected_dev,
    int $device_id,
    array $device_tables,
    array $device_metadata,
    array $vars
): void {
    $dev_graphs = [
        'btrfs_dev_usage' => 'Usage',
        'btrfs_dev_errors' => 'IO Errors (Rate)',
        'btrfs_dev_errors_counter' => 'IO Errors (Total)',
        'btrfs_dev_scrub_errors' => 'Scrub Errors (Total)',
        'btrfs_dev_scrub_errors_derive' => 'Scrub Errors (Rate)',
    ];
    btrfs_renderAppGraphs($app, $dev_graphs, $selected_fs, $selected_dev, $vars);
    $selected_diskio = btrfs_findDiskio($device_id, $device_tables, $selected_fs, $selected_dev, $device_metadata);
    if ($selected_diskio !== null) {
        btrfs_renderDiskioGraphs($selected_diskio);
    }
}

function btrfs_renderFsGraphs(
    App\Models\Application $app,
    string $selected_fs,
    array $vars
): void {
    $fs_graphs = [
        'btrfs_fs_space' => 'Filesystem Space',
        'btrfs_fs_scrub_bytes' => 'Filesystem Scrub Rate',
        'btrfs_fs_data_types' => 'Per Data Type',
        'btrfs_fs_free' => 'Filesystem Free Space',
        'btrfs_fs_ratios' => 'Data/Metadata Ratios',
        'btrfs_fs_errors_by_type' => 'Aggregate Errors by Type',
        'btrfs_fs_errors_by_device' => 'Aggregate Errors by Device',
    ];
    btrfs_renderAppGraphs($app, $fs_graphs, $selected_fs, null, $vars);
    btrfs_renderFsDiskioGraphs($app, $selected_fs);
}

function btrfs_renderAppGraphs(
    App\Models\Application $app,
    array $graphs,
    ?string $selected_fs,
    ?string $selected_dev,
    array $vars
): void {
    $current_graph = $vars['graph'] ?? null;
    if ($current_graph !== null && $current_graph !== '' && isset($graphs[$current_graph])) {
        $graphs = [$current_graph => $graphs[$current_graph]];
    }

    foreach ($graphs as $key => $text) {
        $graph_array = [
            'height' => '100',
            'width' => '215',
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'id' => $app['app_id'],
            'type' => 'application_' . $key,
        ];

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
}

function btrfs_findDiskio(
    int $device_id,
    array $device_tables,
    ?string $selected_fs,
    ?string $selected_dev,
    array $device_metadata
): ?array {
    if (! isset($selected_fs, $selected_dev)) {
        return null;
    }

    $selected_dev_path = trim((string) ($device_tables[$selected_dev]['path'] ?? ''));
    if ($selected_dev_path === '') {
        return null;
    }

    $diskio_candidates = [];
    $preferred_diskio_candidates = [];

    $diskio_candidates[] = $selected_dev_path;
    $without_dev_prefix = preg_replace('#^/dev/#', '', $selected_dev_path);
    if ($without_dev_prefix !== '') {
        $diskio_candidates[] = $without_dev_prefix;
    }
    $diskio_candidates[] = basename($selected_dev_path);

    $selected_dev_metadata = $device_metadata[$selected_dev] ?? [];
    if (is_array($selected_dev_metadata)) {
        $primary_meta = $selected_dev_metadata['primary'] ?? [];
        $backing_meta = $selected_dev_metadata['backing'] ?? [];

        $primary_devnode = trim((string) ($primary_meta['devnode'] ?? ''));
        if ($primary_devnode !== '') {
            $diskio_candidates[] = $primary_devnode;
            $diskio_candidates[] = ltrim(preg_replace('#^/dev/#', '', $primary_devnode), '/');
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
            $preferred_diskio_candidates[] = ltrim(preg_replace('#^/dev/#', '', $backing_devnode), '/');
            $preferred_diskio_candidates[] = basename($backing_devnode);
            $diskio_candidates[] = $backing_devnode;
            $diskio_candidates[] = ltrim(preg_replace('#^/dev/#', '', $backing_devnode), '/');
            $diskio_candidates[] = basename($backing_devnode);
        }
    }

    $diskio_candidates = array_values(array_unique($diskio_candidates));
    $preferred_diskio_candidates = array_values(array_unique(array_merge($preferred_diskio_candidates, $diskio_candidates)));

    $diskio_rows = dbFetchRows('SELECT `diskio_id`, `diskio_descr` FROM `ucd_diskio` WHERE `device_id` = ?', [$device_id]);
    $diskio_by_descr = [];
    foreach ($diskio_rows as $diskio_row) {
        $diskio_descr = trim((string) ($diskio_row['diskio_descr'] ?? ''));
        if ($diskio_descr !== '') {
            $diskio_by_descr[$diskio_descr] = $diskio_row;
        }
    }

    foreach ($preferred_diskio_candidates as $candidate) {
        if (isset($diskio_by_descr[$candidate])) {
            return $diskio_by_descr[$candidate];
        }
    }

    if (count($diskio_rows) === 1) {
        return $diskio_rows[0];
    }

    return null;
}

function btrfs_renderDiskioGraphs(array $selected_diskio): void
{
    $diskio_id = $selected_diskio['diskio_id'];
    $diskio_descr = trim((string) ($selected_diskio['diskio_descr'] ?? ''));
    $diskio_label = $diskio_descr !== '' ? $diskio_descr : (string) $diskio_id;

    $diskio_types = [
        'diskio_ops' => 'Disk I/O Ops/sec',
        'diskio_bits' => 'Disk I/O bps',
    ];

    foreach ($diskio_types as $diskio_type => $diskio_title) {
        $graph_array = [
            'height' => '100',
            'width' => '215',
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
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

function btrfs_renderFsDiskioGraphs(App\Models\Application $app, string $selected_fs): void
{
    $diskio_types = [
        'btrfs_fs_diskio_ops' => 'Aggregate Ops/sec',
        'btrfs_fs_diskio_bits' => 'Aggregate Bps',
    ];

    foreach ($diskio_types as $graph_type => $graph_title) {
        $graph_array = [
            'height' => '100',
            'width' => '215',
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
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

// -----------------------------------------------------------------------------
// Main Execution
// -----------------------------------------------------------------------------

$data = btrfs_initializeData($app, $device, $vars);
$state_sensor_values = btrfs_loadStateSensors($device['device_id']);
$closures = btrfs_createClosures($state_sensor_values);

$link_array = [
    'page' => 'device',
    'device' => $device['device_id'],
    'tab' => 'apps',
    'app' => 'btrfs',
];

$selected_fs = $data['selected_fs'];
$selected_dev = $data['selected_dev'];
$is_overview = ! isset($selected_fs);
$is_per_disk = isset($selected_fs) && isset($selected_dev);

$selected_dev_path = $is_per_disk && isset($data['device_map'][$selected_fs][$selected_dev])
    ? (string) $data['device_map'][$selected_fs][$selected_dev]
    : null;

btrfs_renderNavigation(
    $link_array,
    $data['filesystems'],
    $selected_fs,
    $selected_dev,
    $data['filesystem_meta'],
    $data['device_map'],
    $data['device_tables'],
    $closures['format_device_display_name']
);

if ($is_per_disk) {
    btrfs_renderDevView(
        $app,
        $device,
        $selected_fs,
        $selected_dev,
        $data['filesystem_meta'],
        $data['filesystem_tables'],
        $data['device_map'],
        $data['device_tables'],
        $data['device_metadata'],
        $data['filesystem_profiles'],
        $data['scrub_status_fs'],
        $data['scrub_status_devices'],
        $data['balance_status_fs'],
        $data['scrub_is_running_fs'],
        $data['balance_is_running_fs'],
        $data['filesystem_uuid'],
        $data['fs_rrd_key'],
        $app->data['tables'] ?? [],
        $closures['state_code_from_sensor'],
        $closures['state_code_from_running_flag'],
        $closures['status_from_code'],
        $closures['status_badge'],
        $closures['format_metric_value'],
        $closures['format_display_name'],
        $closures['format_device_display_name'],
        $closures['ordered_metric_pairs'],
        $closures['to_bool'],
        $closures['combine_state_code'],
        $closures['scrub_progress_text_from_status'],
        $closures['flatten_assoc_rows']
    );

    btrfs_renderDevGraphs($app, $selected_fs, $selected_dev, $device['device_id'], $data['device_tables'], $data['device_metadata'], $vars);
} elseif (isset($selected_fs)) {
    btrfs_renderFsView(
        $app,
        $device,
        $selected_fs,
        $data['filesystem_meta'],
        $data['filesystem_tables'],
        $data['device_map'],
        $data['device_tables'],
        $data['device_metadata'],
        $data['filesystem_profiles'],
        $data['scrub_status_fs'],
        $data['scrub_status_devices'],
        $data['balance_status_fs'],
        $data['scrub_is_running_fs'],
        $data['balance_is_running_fs'],
        $data['filesystem_uuid'],
        $data['fs_rrd_key'],
        $closures['state_code_from_sensor'],
        $closures['state_code_from_running_flag'],
        $closures['status_from_code'],
        $closures['status_badge'],
        $closures['format_metric_value'],
        $closures['format_display_name'],
        $closures['format_device_display_name'],
        $closures['ordered_metric_pairs'],
        $closures['to_bool'],
        $closures['combine_state_code'],
        $closures['scrub_progress_text_from_status'],
        $closures['flatten_assoc_rows']
    );

    btrfs_renderFsGraphs($app, $selected_fs, $vars);
} else {
    btrfs_renderOverviewPage(
        $app,
        $device,
        $data['filesystems'],
        $data['filesystem_meta'],
        $data['filesystem_tables'],
        $data['device_map'],
        $data['device_tables'],
        $data['scrub_status_fs'],
        $data['scrub_is_running_fs'],
        $data['balance_is_running_fs'],
        $data['fs_rrd_key'],
        $closures['state_code_from_sensor'],
        $closures['state_code_from_running_flag'],
        $closures['status_from_code'],
        $closures['status_badge'],
        $closures['format_metric_value'],
        $closures['format_display_name'],
        $closures['combine_state_code'],
        $closures['scrub_progress_text_from_status'],
        $closures['total_io_errors'],
        $closures['used_percent_text'],
        $closures['flatten_assoc_rows']
    );
}
