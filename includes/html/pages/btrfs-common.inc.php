<?php

namespace LibreNMS\Plugins\Btrfs;

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

function state_code_from_sensor(string $sensor_type, string $sensor_index, $fallback = null): int
{
    global $data;
    $state_sensor_values = $data['state_sensor_values'] ?? [];
    if (isset($state_sensor_values[$sensor_type][$sensor_index]) && is_numeric($state_sensor_values[$sensor_type][$sensor_index])) {
        return (int) $state_sensor_values[$sensor_type][$sensor_index];
    }

    return is_numeric($fallback) ? (int) $fallback : 2;
}

function state_code_from_running_flag($running_flag, $fallback = null): int
{
    if (is_bool($running_flag)) {
        return $running_flag ? 1 : 0;
    }

    return is_numeric($fallback) ? (int) $fallback : 2;
}

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

function scrub_progress_text_from_status(array $scrub_status): string
{
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

    if ($scrub_progress === null) {
        return 'N/A';
    }

    return rtrim(rtrim(number_format($scrub_progress, 2, '.', ''), '0'), '.') . '%';
}

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

function used_percent_text($used_value, $size_value): string
{
    $used = (float) ($used_value ?? 0);
    $size = (float) ($size_value ?? 0);

    if ($size <= 0) {
        return 'N/A';
    }

    return rtrim(rtrim(number_format(($used / $size) * 100, 2, '.', ''), '0'), '.') . '%';
}

function format_metric($value, string $metric, string $null_text = 'N/A'): string
{
    if ($value === null || $value === '') {
        return $null_text;
    }

    if (str_contains($metric, 'ratio')) {
        return number_format((float) $value, 2);
    }

    if (str_contains($metric, 'size')
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
        || str_starts_with($metric, 'system_')
    ) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $v = (float) $value;
        $i = 0;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return round($v, 2) . ' ' . $units[$i];
    }

    return is_numeric($value) ? number_format((float) $value) : (string) $value;
}

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

function is_error_metric(string $metric): bool
{
    return str_contains($metric, 'errs')
        || str_contains($metric, 'errors')
        || str_contains($metric, 'devid')
        || $metric === 'id';
}

function format_metric_value($value, string $metric): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (str_contains($metric, 'ratio')) {
        return number_format((float) $value, 2);
    }

    if (is_error_metric($metric) && is_numeric($value)) {
        return number_format((int) round((float) $value));
    }

    $si_count_metrics = [
        'data_extents_scrubbed',
        'tree_extents_scrubbed',
        'no_csum',
    ];
    if (in_array($metric, $si_count_metrics, true) && is_numeric($value)) {
        return \LibreNMS\Util\Number::formatSi((float) $value, 2, 0, '');
    }

    if ($metric === 'duration' && is_string($value) && str_contains($value, ':')) {
        return $value;
    }

    if (is_byte_metric($metric)) {
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
}

function format_display_name(string $key): string
{
    $name = preg_replace('/\[([0-9]+)\]/', ' $1', $key);
    $name = str_replace(['.', '_', '-'], ' ', (string) $name);

    return ucwords((string) $name);
}

function flatten_assoc_rows(array $data, string $prefix = ''): array
{
    $rows = [];
    foreach ($data as $key => $value) {
        $segment = is_int($key) ? '[' . $key . ']' : (string) $key;
        $path = $prefix === '' ? $segment : $prefix . '.' . $segment;

        if (is_array($value)) {
            $rows = array_merge($rows, flatten_assoc_rows($value, $path));
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
}

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

function find_diskio(
    int $device_id,
    array $device_tables,
    ?string $selected_fs,
    ?string $selected_dev,
    array $device_metadata
): ?array {
    if (! isset($selected_fs, $selected_dev)) {
        return null;
    }

    $selected_dev_path = trim((string) ($device_tables[$selected_fs][$selected_dev]['path'] ?? ''));
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

    $diskio_rows = \dbFetchRows('SELECT `diskio_id`, `diskio_descr` FROM `ucd_diskio` WHERE `device_id` = ?', [$device_id]);
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

function render_diskio_graphs(array $selected_diskio): void
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

function render_fs_diskio_graphs(\App\Models\Application $app, string $selected_fs): void
{
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

function initialize_data(\App\Models\Application $app, array $device, array $vars): array
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
