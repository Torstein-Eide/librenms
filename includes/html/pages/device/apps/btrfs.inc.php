<?php

// =============================================================================
// Btrfs Application Page
// Renders the device/app page for Btrfs monitoring.
// Three views: Overview (all filesystems), Per-filesystem, Per-device.
// Data source contract: consumes normalized app->data emitted by poller btrfs.inc.php.
// Status rendering rule: prefer live state sensors, then fall back to stored poller status codes.
// =============================================================================

require_once base_path('includes/html/pages/btrfs-common.inc.php');

use function LibreNMS\Plugins\Btrfs\combine_state_code;
use function LibreNMS\Plugins\Btrfs\find_diskio;
use function LibreNMS\Plugins\Btrfs\flatten_assoc_rows;
use function LibreNMS\Plugins\Btrfs\format_display_name;
use function LibreNMS\Plugins\Btrfs\format_metric;
use function LibreNMS\Plugins\Btrfs\format_metric_value;
use function LibreNMS\Plugins\Btrfs\initialize_data;
use function LibreNMS\Plugins\Btrfs\load_state_sensors;
use function LibreNMS\Plugins\Btrfs\render_diskio_graphs;
use function LibreNMS\Plugins\Btrfs\render_fs_diskio_graphs;
use function LibreNMS\Plugins\Btrfs\scrub_progress_text_from_status;
use function LibreNMS\Plugins\Btrfs\scrub_status_to_state;
use function LibreNMS\Plugins\Btrfs\state_code_from_running_flag;
use function LibreNMS\Plugins\Btrfs\state_code_from_sensor;
use function LibreNMS\Plugins\Btrfs\status_badge;
use function LibreNMS\Plugins\Btrfs\status_from_code;
use function LibreNMS\Plugins\Btrfs\total_io_errors;
use function LibreNMS\Plugins\Btrfs\used_percent_text;

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

// $data['filesystems'][FS]['uuid' | 'rrd_key']
// $data['filesystems'][FS]['meta']['mountpoint' | 'label' | 'total_devices' | 'fs_bytes_used']
// $data['filesystems'][FS]['device_map'][DEVID]
// $data['filesystems'][FS]['table keys'][]
// $data['filesystems'][FS]['device_tables count']

// $data['filesystems'][FS]['device_tables'][DEVID]['path' | 'devid' | 'missing' | 'io_status_code' | 'scrub_status_code']
// $data['filesystems'][FS]['device_tables'][DEVID]['errors']['write_io_errs' | 'read_io_errs' | 'flush_io_errs' | 'corruption_errs' | 'generation_errs']
// $data['filesystems'][FS]['device_tables'][DEVID]['usage']['size' | 'slack' | 'unallocated' | 'data' | 'metadata' | 'system']
// $data['filesystems'][FS]['device_tables'][DEVID]['raid_profiles'][PROFILE]

// $data['filesystems'][FS]['device_metadata'][DEVID]['backing' | 'sys_block']
// $data['filesystems'][FS]['profiles'][PROFILE]

// $data['filesystems'][FS]['scrub']['is_running']
// $data['filesystems'][FS]['scrub']['status']['status' | 'scrub_started' | 'duration' | 'time_left' | 'eta' | 'total_to_scrub' | 'bytes_scrubbed' | 'progress_percent' | 'rate' | 'error_summary' | 'is_running']
// $data['filesystems'][FS]['scrub']['devices'][DEVID][...]
// $data['filesystems'][FS]['balance']['is_running']
// $data['filesystems'][FS]['balance']['status']['is_running' | 'message']

$data = initialize_data($app, $device, $vars);

function btrfs_renderNavigation(
    array $link_array,
    array $filesystems,
    ?string $selected_fs,
    ?string $selected_dev,
    array $filesystem_meta,
    array $device_map,
    array $device_tables
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
            if ($is_missing_device) {
                $dev_label = $dev_id !== null ? 'devid ' . $dev_id : '<missing>';
            } else {
                $dev_label = (string) ($dev_path ?: ($dev_id ?? 'unknown'));
            }
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

// Overview Page table
//
// Renders the overview page with a table of all filesystems and their aggregate status metrics.
function btrfs_renderOverviewPage(App\Models\Application $app, array $device, array $data): void
{
    $filesystems = $data['filesystems'] ?? [];
    $filesystem_meta = $data['filesystem_meta'] ?? [];
    $filesystem_tables = $data['filesystem_tables'] ?? [];
    $device_map = $data['device_map'] ?? [];
    $device_tables = $data['device_tables'] ?? [];
    $scrub_status_fs = $data['scrub_status_fs'] ?? [];
    $scrub_is_running_fs = $data['scrub_is_running_fs'] ?? [];
    $balance_is_running_fs = $data['balance_is_running_fs'] ?? [];
    $fs_rrd_key = $data['fs_rrd_key'] ?? [];

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
            ? scrub_progress_text_from_status($scrub_status)
            : 'N/A';

        $total_errors = total_io_errors($device_tables[$fs] ?? []);
        $used_pct_text = used_percent_text($fs_data['used'] ?? null, $fs_data['device_size'] ?? null);

        $fs_rrd_id = $fs_rrd_key[$fs] ?? $fs;
        $io_code = state_code_from_sensor('btrfsIoStatusState', (string) $fs_rrd_id . '.io', $fs_data['io_status_code'] ?? null);
        $scrub_fallback_code = state_code_from_running_flag($scrub_is_running_fs[$fs] ?? null, $fs_data['scrub_status_code'] ?? null);
        $balance_fallback_code = state_code_from_running_flag($balance_is_running_fs[$fs] ?? null, $fs_data['balance_status_code'] ?? null);
        $scrub_code = state_code_from_sensor('btrfsScrubStatusState', (string) $fs_rrd_id . '.scrub', $scrub_fallback_code);
        $balance_code = state_code_from_sensor('btrfsBalanceStatusState', (string) $fs_rrd_id . '.balance', $balance_fallback_code);
        $io_state = status_from_code($io_code);
        $scrub_state = status_from_code($scrub_code);
        $balance_state = status_from_code($balance_code);

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
        echo '<td>' . status_badge($io_state) . '</td>';
        echo '<td>' . status_badge($scrub_state) . '</td>';
        echo '<td>' . status_badge($balance_state) . '</td>';
        echo '<td>' . htmlspecialchars($scrub_progress_text) . '</td>';
        echo '<td>' . htmlspecialchars(number_format($total_errors)) . '</td>';
        echo '<td>' . htmlspecialchars($used_pct_text) . '</td>';
        echo '<td>' . htmlspecialchars(format_metric($fs_data['used'] ?? null, 'used')) . '</td>';
        echo '<td>' . htmlspecialchars(format_metric($fs_data['free_estimated'] ?? null, 'free_estimated')) . '</td>';
        echo '<td>' . htmlspecialchars(format_metric($fs_data['device_size'] ?? null, 'device_size')) . '</td>';
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

    btrfs_renderOverviewPageGraphs($app, $device, $data);
}

//
// Renders the graphs for the overview page, showing key metrics for each filesystem in a compact format below the main table.
function btrfs_renderOverviewPageGraphs(App\Models\Application $app, array $device, array $data): void
{
    $filesystems = $data['filesystems'] ?? [];
    $filesystem_meta = $data['filesystem_meta'] ?? [];
    $filesystem_tables = $data['filesystem_tables'] ?? [];
    $fs_rrd_key = $data['fs_rrd_key'] ?? [];
    $scrub_is_running_fs = $data['scrub_is_running_fs'] ?? [];
    $balance_is_running_fs = $data['balance_is_running_fs'] ?? [];

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
        $used_text = format_metric($fs_data['used'] ?? null, 'used');
        $total_text = format_metric($fs_data['device_size'] ?? null, 'device_size');
        $used_percent_text = $size_value > 0
            ? rtrim(rtrim(number_format(($used_value / $size_value) * 100, 2, '.', ''), '0'), '.') . '%'
            : 'N/A';

        $fs_rrd_id = $fs_rrd_key[$fs] ?? $fs;
        $io_code = state_code_from_sensor('btrfsIoStatusState', (string) $fs_rrd_id . '.io', $fs_data['io_status_code'] ?? null);
        $scrub_fallback_code = state_code_from_running_flag($scrub_is_running_fs[$fs] ?? null, $fs_data['scrub_status_code'] ?? null);
        $balance_fallback_code = state_code_from_running_flag($balance_is_running_fs[$fs] ?? null, $fs_data['balance_status_code'] ?? null);
        $scrub_code = state_code_from_sensor('btrfsScrubStatusState', (string) $fs_rrd_id . '.scrub', $scrub_fallback_code);
        $balance_code = state_code_from_sensor('btrfsBalanceStatusState', (string) $fs_rrd_id . '.balance', $balance_fallback_code);
        $overall_code = combine_state_code([$io_code, $scrub_code, $balance_code]);
        $overall_state = status_from_code($overall_code);

        $fs_link = LibreNMS\Util\Url::generate([
            'page' => 'device',
            'device' => $device['device_id'],
            'tab' => 'apps',
            'app' => 'btrfs',
            'fs' => $fs,
        ]);

        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><h3 class="panel-title"><a href="' . $fs_link . '" style="color:#337ab7;">' . htmlspecialchars($display_name) . '</a><div class="pull-right"><small class="text-muted">' . htmlspecialchars($used_text . '/' . $total_text . ' ' . $used_percent_text) . '</small> ' . status_badge($overall_state) . '</div></h3></div>';
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
            echo '<a href="' . $fs_link . '">' . LibreNMS\Util\Url::lazyGraphTag($graph_array) . '</a>';
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
    array $data
): void {
    $filesystem_meta = $data['filesystem_meta'] ?? [];
    $filesystem_tables = $data['filesystem_tables'] ?? [];
    $device_map = $data['device_map'] ?? [];
    $device_tables = $data['device_tables'] ?? [];
    $filesystem_profiles = $data['filesystem_profiles'] ?? [];
    $scrub_status_fs = $data['scrub_status_fs'] ?? [];
    $scrub_status_devices = $data['scrub_status_devices'] ?? [];
    $scrub_is_running_fs = $data['scrub_is_running_fs'] ?? [];
    $filesystem_uuid = $data['filesystem_uuid'] ?? [];
    $fs_rrd_key = $data['fs_rrd_key'] ?? [];
    $tables = $app->data['tables'] ?? [];

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

    $io_code = state_code_from_sensor('btrfsIoStatusState', $selected_fs_rrd_id . '.dev.' . $selected_dev . '.io', $dev_io_code);
    $scrub_code = state_code_from_sensor('btrfsScrubStatusState', $selected_fs_rrd_id . '.dev.' . $selected_dev . '.scrub', $dev_scrub_code);
    $overall_code = combine_state_code([$io_code, $scrub_code]);
    $overall_state = status_from_code($overall_code);

    echo '<div class="btrfs-panels">';

    echo '<div class="panel panel-default panel-wide">';
    echo '<div class="panel-heading"><h3 class="panel-title">Device Info<div class="pull-right">' . status_badge($overall_state) . '</div></h3></div>';
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
            $info_rows[] = ['key' => 'backing_size', 'value' => format_metric_value($backing_size, 'bytes')];
        }
    }

    $size = $dev_data['size'] ?? null;
    if ($size !== null) {
        $info_rows[] = ['key' => 'size', 'value' => format_metric_value($size, 'bytes')];
    }

    $dev_errors = $device_tables[$selected_fs][$selected_dev]['errors'] ?? [];
    $error_keys = ['write_io_errs', 'read_io_errs', 'flush_io_errs', 'corruption_errs', 'generation_errs'];
    foreach ($error_keys as $err_key) {
        if (isset($dev_errors[$err_key])) {
            $info_rows[] = ['key' => $err_key, 'value' => format_metric_value($dev_errors[$err_key], $err_key)];
        }
    }

    $dev_profiles = $device_tables[$selected_fs][$selected_dev]['raid_profiles'] ?? [];
    foreach ($dev_profiles as $profile_key => $profile_bytes) {
        $info_rows[] = ['key' => $profile_key, 'value' => format_metric_value($profile_bytes, 'bytes')];
    }

    echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
    echo '<thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
    foreach ($info_rows as $row) {
        echo '<tr><td>' . htmlspecialchars(format_display_name((string) $row['key'])) . '</td>';
        echo '<td>' . htmlspecialchars($row['value']) . '</td></tr>';
    }
    echo '</tbody></table></div></div></div>';

    $dev_scrub = $scrub_status_devices[$selected_fs][$selected_dev] ?? [];
    $scrub_status_code = state_code_from_sensor(
        'btrfsScrubStatusState',
        $selected_fs_rrd_id . '.scrub',
        state_code_from_running_flag($scrub_is_running_fs[$selected_fs] ?? false, $filesystem_tables[$selected_fs]['scrub_status_code'] ?? null)
    );
    $scrub_badge = status_badge(status_from_code($scrub_status_code));

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
            $display_value = format_metric_value($value, (string) $key);
            echo '<tr><td>' . htmlspecialchars(format_display_name((string) $key)) . '</td>';
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
    array $data
): void {
    $filesystem_meta = $data['filesystem_meta'] ?? [];
    $filesystem_tables = $data['filesystem_tables'] ?? [];
    $device_map = $data['device_map'] ?? [];
    $device_tables = $data['device_tables'] ?? [];
    $device_metadata = $data['device_metadata'] ?? [];
    $filesystem_profiles = $data['filesystem_profiles'] ?? [];
    $scrub_status_fs = $data['scrub_status_fs'] ?? [];
    $scrub_status_devices = $data['scrub_status_devices'] ?? [];
    $balance_status_fs = $data['balance_status_fs'] ?? [];
    $scrub_is_running_fs = $data['scrub_is_running_fs'] ?? [];
    $balance_is_running_fs = $data['balance_is_running_fs'] ?? [];
    $fs_rrd_key = $data['fs_rrd_key'] ?? [];

    $selected_fs_rrd_id = $fs_rrd_key[$selected_fs] ?? $selected_fs;
    $path_to_dev_id = [];
    foreach ($device_map[$selected_fs] ?? [] as $dev_id => $dev_path) {
        $path_to_dev_id[(string) $dev_path] = (string) $dev_id;
    }

    $scrub_status_code = state_code_from_sensor(
        'btrfsScrubStatusState',
        $selected_fs_rrd_id . '.scrub',
        state_code_from_running_flag($scrub_is_running_fs[$selected_fs] ?? false, $filesystem_tables[$selected_fs]['scrub_status_code'] ?? null)
    );
    $scrub_badge = status_badge(status_from_code($scrub_status_code));

    $scrub_split = [
        'overview' => is_array($scrub_status_fs[$selected_fs] ?? null) ? flatten_assoc_rows($scrub_status_fs[$selected_fs]) : [],
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

    btrfs_renderFsPanelsRow1(
        $app,
        $device,
        $selected_fs,
        $selected_fs_rrd_id,
        $scrub_split,
        $scrub_badge,
        $data,
        $balance_status_fs,
        $scrub_is_running_fs
    );

    btrfs_renderFsPanelsRow2(
        $app,
        $device,
        $selected_fs,
        $selected_fs_rrd_id,
        $path_to_dev_id,
        $data
    );

    btrfs_renderScrubPerDevice(
        $scrub_split,
        $path_to_dev_id,
        $device['device_id'],
        $selected_fs,
        $data
    );
}

function btrfs_renderFsPanelsRow1(
    App\Models\Application $app,
    array $device,
    string $selected_fs,
    string $selected_fs_rrd_id,
    array $scrub_split,
    string $scrub_badge,
    array $data,
    array $balance_status_fs,
    array $scrub_is_running_fs
): void {
    $filesystem_meta = $data['filesystem_meta'] ?? [];
    $filesystem_tables = $data['filesystem_tables'] ?? [];
    $device_map = $data['device_map'] ?? [];
    $render_balance_panel = static function (array $split, array $rows, ?string $panel_col_class, ?string $selected_dev, int $balance_status_code): void {
        $state = match ($balance_status_code) {
            0 => 'ok', 1 => 'running', 2 => 'na', 3 => 'error', 4 => 'missing', default => 'na',
        };
        $badge = status_badge($state);
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
                echo '<tr><td>' . htmlspecialchars(format_metric($row['key'], 'metric')) . '</td>';
                echo '<td>' . htmlspecialchars(format_metric_value($row['value'], $row['key'])) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div>';
        if ($panel_col_class !== null) {
            echo '</div>';
        }
    };

    echo '<div class="btrfs-panels">';

    $renderOverviewTable = static function (array $pairs, int $columns): void {
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
                echo '<td>' . htmlspecialchars(format_display_name((string) $pairs[$index]['metric'])) . '</td>';
                echo '<td>' . htmlspecialchars(format_metric_value($pairs[$index]['value'], (string) $pairs[$index]['metric'])) . '</td>';
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

    $render_scrub_overview_panel = static function (array $split, array $rows, string $badge): void {
        if (true) {
            echo '<div class="panel panel-default panel-wide">';
        }
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
                $display_value = format_metric_value($value, (string) $key);
                if ($key === 'bytes_scrubbed.bytes') {
                    $progress = $overview_map['bytes_scrubbed.progress'] ?? null;
                    $formatted = format_metric_value($value, 'bytes');
                    $display_value = is_numeric($progress)
                        ? $formatted . ' (' . rtrim(rtrim(number_format((float) $progress, 2, '.', ''), '0'), '.') . '%)'
                        : $formatted;
                    $display_key = 'total_bytes_done';
                } elseif ($key === 'total_to_scrub') {
                    $display_value = format_metric_value($value, 'bytes');
                }
                echo '<tr><td>' . htmlspecialchars(format_display_name($display_key)) . '</td>';
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
                $balance_split['overview'] = array_merge($balance_split['overview'], flatten_assoc_rows([$key => $value]));
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
    array $path_to_dev_id,
    array $data
): void {
    $filesystem_tables = $data['filesystem_tables'] ?? [];
    $device_map = $data['device_map'] ?? [];
    $device_tables = $data['device_tables'] ?? [];
    $device_metadata = $data['device_metadata'] ?? [];
    $filesystem_profiles = $data['filesystem_profiles'] ?? [];

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
            echo '<th>' . htmlspecialchars(format_display_name($profile)) . '</th>';
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
            echo '<td>' . htmlspecialchars(format_metric($usage['size'] ?? null, 'device_size')) . '</td>';
            echo '<td>' . htmlspecialchars(format_metric($usage['slack'] ?? null, 'device_slack')) . '</td>';
            foreach ($all_raid_profiles as $profile) {
                echo '<td>' . htmlspecialchars(format_metric($raid_profiles[$profile] ?? null, 'bytes')) . '</td>';
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
        $fs_io_code = state_code_from_sensor('btrfsIoStatusState', $selected_fs_rrd_id . '.io', $filesystem_tables[$selected_fs]['io_status_code'] ?? null);
        $fs_scrub_code = state_code_from_sensor('btrfsScrubStatusState', $selected_fs_rrd_id . '.scrub', null);
        $fs_balance_code = state_code_from_sensor('btrfsBalanceStatusState', $selected_fs_rrd_id . '.balance', null);
        $fs_overall_code = combine_state_code([$fs_io_code, $fs_scrub_code, $fs_balance_code]);
        $fs_overall_state = status_from_code($fs_overall_code);

        echo '<div class="panel panel-default panel-wide">';
        echo '<div class="panel-heading"><h3 class="panel-title">Device Stats<div class="pull-right">' . status_badge($fs_overall_state) . '</div></h3></div>';
        echo '<div class="panel-body">';
        echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
        echo '<thead><tr><th>Device</th><th>Status</th>';
        foreach ($stats_split['device_columns'] as $col) {
            echo '<th>' . htmlspecialchars(format_display_name($col)) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($stats_split['devices'] as $dev_path => $metrics) {
            $dev_id = $path_to_dev_id[(string) $dev_path] ?? null;
            $link = $dev_id !== null
                ? generate_link(htmlspecialchars((string) $dev_path), $link_array, ['fs' => $selected_fs, 'dev' => $dev_id])
                : htmlspecialchars((string) $dev_path);
            $dev_io_code = $metrics['io_status_code'] ?? null;
            $dev_scrub_code = $metrics['scrub_status_code'] ?? null;
            $dev_overall_code = combine_state_code([$dev_io_code ?? 2, $dev_scrub_code ?? 2]);
            $dev_overall_state = status_from_code($dev_overall_code);
            $errors = $metrics['errors'] ?? [];
            echo '<tr><td>' . $link . '</td><td>' . status_badge($dev_overall_state) . '</td>';
            foreach ($stats_split['device_columns'] as $col) {
                echo '<td>' . htmlspecialchars(format_metric($errors[$col] ?? null, $col)) . '</td>';
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
    array $data
): void {
    $link_array = ['page' => 'device', 'device' => $device_id, 'tab' => 'apps', 'app' => 'btrfs'];

    echo '<div class="btrfs-panels">';

    if (count($scrub_split['devices']) > 0) {
        $hidden_columns = ['path', 'id', 'section', 'has_status_suffix', 'has_stats', 'no_stats_available', 'last_physical'];
        echo '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title">Scrub Per Device</h3></div>';
        echo '<div class="panel-body">';
        echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
        echo '<thead><tr><th>Device</th><th>Status</th>';
        foreach ($scrub_split['device_columns'] as $column) {
            if (! in_array($column, $hidden_columns, true)) {
                echo '<th>' . htmlspecialchars(format_display_name($column)) . '</th>';
            }
        }
        echo '</tr></thead><tbody>';
        foreach ($scrub_split['devices'] as $device_name => $metrics) {
            $dev_id = $path_to_dev_id[(string) $device_name] ?? null;
            $link = $dev_id !== null
                ? generate_link(htmlspecialchars((string) $device_name), $link_array, ['fs' => $selected_fs, 'dev' => $dev_id])
                : htmlspecialchars((string) $device_name);
            $scrub_state = scrub_status_to_state($metrics['status'] ?? '');
            echo '<tr><td>' . $link . '</td><td>' . status_badge($scrub_state) . '</td>';
            foreach ($scrub_split['device_columns'] as $column) {
                if (! in_array($column, $hidden_columns, true)) {
                    $value = $metrics[$column] ?? '';
                    echo '<td>' . htmlspecialchars(format_metric($value, $column)) . '</td>';
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
        render_diskio_graphs($selected_diskio);
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
    render_fs_diskio_graphs($app, $selected_fs);
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

// -----------------------------------------------------------------------------
// Main Execution
// -----------------------------------------------------------------------------

$state_sensor_values = load_state_sensors($device['device_id']);
$data['state_sensor_values'] = $state_sensor_values;

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
    $data['device_tables']
);

if ($is_per_disk) {
    btrfs_renderDevView(
        $app,
        $device,
        $selected_fs,
        $selected_dev,
        $data
    );

    btrfs_renderDevGraphs($app, $selected_fs, $selected_dev, $device['device_id'], $data['device_tables'], $data['device_metadata'], $vars);
} elseif (isset($selected_fs)) {
    btrfs_renderFsView(
        $app,
        $device,
        $selected_fs,
        $data
    );

    btrfs_renderFsGraphs($app, $selected_fs, $data);
} else {
    btrfs_renderOverviewPage($app, $device, $data);
}
