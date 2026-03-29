<?php

/**
 * Btrfs Device App Page
 *
 * Renders the device/app page for Btrfs monitoring.
 * Provides three views based on URL parameters:
 *   - Overview: All filesystems summarized in a table with mini-graphs.
 *   - Per-filesystem: Detailed view of a single filesystem with scrub/balance panels.
 *   - Per-device: Device-specific information and graphs.
 *
 * Data source: Consumes normalized app->data emitted by poller btrfs.inc.php.
 * Status rendering: Prefers live state sensors, falls back to stored poller status codes.
 */

use Illuminate\Support\Facades\Log;

// =============================================================================
// Namespace Imports
// Import shared helper functions from the common btrfs module.
// =============================================================================

require_once base_path('includes/html/pages/btrfs-common.inc.php');

use function LibreNMS\Plugins\Btrfs\combine_state_code;
use function LibreNMS\Plugins\Btrfs\find_diskio;
use function LibreNMS\Plugins\Btrfs\flatten_assoc_rows;
use function LibreNMS\Plugins\Btrfs\format_display_name;
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

// =============================================================================
// CSS Styling
// Responsive panel layout styles for btrfs display sections.
// =============================================================================

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

// =============================================================================
// Data Contract Comments
// Documents the expected structure of app->data['filesystems'][FS][...].
// Used by both device page and apps overview page.
// =============================================================================

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

// =============================================================================
// Navigation Rendering
// Renders the breadcrumb-style navigation for filesystem/device selection.
// =============================================================================

/**
 * Renders the filesystem and device navigation menu.
 *
 * Displays a hierarchical menu with overview link, filesystem submenu,
 * and device submenu. Current selection is highlighted.
 *
 * @param  array   $link_array  Base URL parameters for links.
 * @param  array   $data         Initialized data array with selection state.
 * @return void
 */
function btrfs_renderNavigation(array $link_array, array $data): void
{
    $filesystems = $data['filesystems'] ?? [];
    $selected_fs = $data['selected_fs'];
    $selected_dev = $data['selected_dev'];
    $filesystem_meta = $data['filesystem_meta'] ?? [];
    $device_map = $data['device_map'] ?? [];
    $device_tables = $data['device_tables'] ?? [];
    print_optionbar_start();

    // Render overview link with selection highlighting.
    $overview_label = ! isset($selected_fs)
        ? '<span class="pagemenu-selected">Overview</span>'
        : 'Overview';
    echo generate_link($overview_label, $link_array);

    // Render filesystem submenu if filesystems exist.
    if (count($filesystems) > 0) {
        echo ' | Filesystems: ';
        foreach ($filesystems as $index => $fs) {
            Log::debug('Btrfs navigation: rendering filesystem', [
                'fs' => $fs,
                'index' => $index,
            ]);
            // Determine display label: prefer label, then 'root', then mountpoint.
            $filesystem_label = $filesystem_meta[$fs]['label'] ?? null;
            if (! empty($filesystem_label)) {
                $display_fs = (string) $filesystem_label;
            } elseif ($fs === '/') {
                $display_fs = 'root';
            } else {
                $display_fs = (string) $fs;
            }

            // Highlight currently selected filesystem.
            $fs_label = htmlspecialchars($display_fs);
            $label = ($selected_fs === $fs)
                ? '<span class="pagemenu-selected">' . $fs_label . '</span>'
                : $fs_label;

            echo generate_link($label, $link_array, ['fs' => $fs]);
            if ($index < (count($filesystems) - 1)) {
                echo ', ';
            }
        }
    } else {
        Log::error('Btrfs navigation: no filesystems to render');
    }

    // Render device submenu if filesystem selected and devices exist.
    if (isset($selected_fs) && isset($device_map[$selected_fs]) && count($device_map[$selected_fs]) > 0) {
        echo '<br />&nbsp;&nbsp;&nbsp;Devices: ';
        $devices = $device_map[$selected_fs];
        asort($devices);
        $i = 0;
        foreach ($devices as $dev_id => $dev_path) {
            Log::debug('Btrfs navigation: rendering device', [
                'dev_id' => $dev_id,
                'dev_path' => $dev_path,
            ]);
            // Handle missing devices with special label.
            $is_missing_device = (bool) ($device_tables[$selected_fs][$dev_id]['missing'] ?? false);
            if ($is_missing_device) {
                $dev_label = $dev_id !== null ? 'devid ' . $dev_id : '<missing>';
            } else {
                $dev_label = (string) ($dev_path ?: ($dev_id ?? 'unknown'));
            }
            // Highlight currently selected device.
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
    } else {
        Log::debug('Btrfs navigation: no devices to render', [
            'selected_fs' => $selected_fs,
            'device_map_keys' => isset($device_map[$selected_fs]) ? array_keys($device_map[$selected_fs]) : null,
        ]);
    }

    print_optionbar_end();
}

// =============================================================================
// Overview Page Rendering
// Displays all filesystems in a summary table with aggregate metrics.
// =============================================================================

/**
 * Renders the overview page with a table of all filesystems.
 *
 * Shows per-filesystem status badges, scrub progress, I/O errors, usage,
 * and links to per-filesystem and per-device views. Includes mini-graphs.
 *
 * @param  \App\Models\Application  $app     Btrfs application model.
 * @param  array                    $device  Device array.
 * @param  array                    $data    Initialized data array.
 * @return void
 */
function btrfs_renderOverviewPage(App\Models\Application $app, array $device, array $data): void
{
    // Extract required data arrays.
    $filesystems = $data['filesystems'] ?? [];
    $filesystem_meta = $data['filesystem_meta'] ?? [];
    $filesystem_tables = $data['filesystem_tables'] ?? [];
    $device_map = $data['device_map'] ?? [];
    $device_tables = $data['device_tables'] ?? [];
    $scrub_status_fs = $data['scrub_status_fs'] ?? [];
    $scrub_is_running_fs = $data['scrub_is_running_fs'] ?? [];
    $balance_is_running_fs = $data['balance_is_running_fs'] ?? [];
    $fs_rrd_key = $data['fs_rrd_key'] ?? [];
    $state_sensor_values = $data['state_sensor_values'] ?? [];

    // Begin overview table panel with heredoc
    echo <<<HTML
<div class="panel panel-default">
<div class="panel-heading"><h3 class="panel-title">Filesystems Overview</h3></div>
<div class="panel-body">
<div class="table-responsive">
<table class="table table-condensed table-striped table-hover btrfs-sticky-first">
<thead>
<tr>
<th>Filesystem</th>
<th>Status</th>
<th>Scrub</th>
<th>Balance</th>
<th>Scrub Progress</th>
<th>IO Errors</th>
<th>% Used</th>
<th>Used</th>
<th>Free (Estimated)</th>
<th>Device Size</th>
<th>Devices</th>
<th>Ops</th>
<th>Bps</th>
<th>Combined Status</th>
</tr>
</thead>
<tbody>
HTML;

    // Render one row per filesystem.
    if (empty($filesystems)) {
        Log::error('Btrfs overview: no filesystems to render in table');
    }
    foreach ($filesystems as $fs) {
        Log::debug('Btrfs overview: rendering filesystem row', [
            'fs' => $fs,
        ]);
        // Extract filesystem data for this entry.
        $fs_data = $filesystem_tables[$fs] ?? [];
        $fs_devices = $device_map[$fs] ?? [];
        $fs_label = trim((string) ($filesystem_meta[$fs]['label'] ?? ''));
        $display_name = $fs_label !== '' ? $fs_label . ' (' . $fs . ')' : (string) $fs;
        $scrub_status = $scrub_status_fs[$fs] ?? [];

        // Calculate scrub progress percentage.
        $scrub_progress_text = is_array($scrub_status) && count($scrub_status) > 0
            ? scrub_progress_text_from_status($scrub_status)
            : 'N/A';

        // Calculate total I/O errors across all devices.
        $total_errors = total_io_errors($device_tables[$fs] ?? []);

        // Calculate used percentage.
        $used_pct_text = used_percent_text($fs_data['used'] ?? null, $fs_data['device_size'] ?? null);

        // Resolve state sensor codes with poller fallback.
        $fs_rrd_id = $fs_rrd_key[$fs] ?? $fs;
        $io_code = state_code_from_sensor($state_sensor_values, 'btrfsIoStatusState', (string) $fs_rrd_id . '.io', $fs_data['io_status_code'] ?? null);
        $scrub_fallback_code = state_code_from_running_flag($scrub_is_running_fs[$fs] ?? null, $fs_data['scrub_status_code'] ?? null);
        $balance_fallback_code = state_code_from_running_flag($balance_is_running_fs[$fs] ?? null, $fs_data['balance_status_code'] ?? null);
        $scrub_code = state_code_from_sensor($state_sensor_values, 'btrfsScrubStatusState', (string) $fs_rrd_id . '.scrub', $scrub_fallback_code);
        $balance_code = state_code_from_sensor($state_sensor_values, 'btrfsBalanceStatusState', (string) $fs_rrd_id . '.balance', $balance_fallback_code);
        $io_state = status_from_code($io_code);
        $scrub_state = status_from_code($scrub_code);
        $balance_state = status_from_code($balance_code);

        // Build combined status graph parameters.
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

        // Generate status graph link and image for overlib.
        $graph_link_array = $graph_array;
        $graph_link_array['page'] = 'graphs';
        unset($graph_link_array['height'], $graph_link_array['width']);
        $graph_link = LibreNMS\Util\Url::generate($graph_link_array);
        $graph_img = LibreNMS\Util\Url::lazyGraphTag($graph_array);

        // Build Ops/sec and Bps mini-graph parameters.
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

        // Define table cells for this row
        $row_cells = [
            generate_link(htmlspecialchars((string) $display_name), ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'btrfs'], ['fs' => $fs]),
            status_badge($io_state),
            status_badge($scrub_state),
            status_badge($balance_state),
            htmlspecialchars($scrub_progress_text),
            htmlspecialchars(number_format($total_errors)),
            htmlspecialchars($used_pct_text),
            htmlspecialchars(format_metric_value($fs_data['used'] ?? null, 'used')),
            htmlspecialchars(format_metric_value($fs_data['free_estimated'] ?? null, 'free_estimated')),
            htmlspecialchars(format_metric_value($fs_data['device_size'] ?? null, 'device_size')),
            number_format(count($fs_devices)),
            generate_link(LibreNMS\Util\Url::lazyGraphTag($ops_graph), ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'btrfs'], ['fs' => $fs]),
            generate_link(LibreNMS\Util\Url::lazyGraphTag($bps_graph), ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'btrfs'], ['fs' => $fs]),
            LibreNMS\Util\Url::overlibLink($graph_link, $graph_img, $display_name . ' - Combined Status'),
        ];

        // Render table row with heredoc
        echo <<<HTML
<tr>
<td>{$row_cells[0]}</td>
<td>{$row_cells[1]}</td>
<td>{$row_cells[2]}</td>
<td>{$row_cells[3]}</td>
<td>{$row_cells[4]}</td>
<td>{$row_cells[5]}</td>
<td>{$row_cells[6]}</td>
<td>{$row_cells[7]}</td>
<td>{$row_cells[8]}</td>
<td>{$row_cells[9]}</td>
<td>{$row_cells[10]}</td>
<td>{$row_cells[11]}</td>
<td>{$row_cells[12]}</td>
<td>{$row_cells[13]}</td>
</tr>
HTML;
    }

    echo <<<HTML
</tbody>
</table>
</div>
</div>
</div>
HTML;


    // Render per-filesystem mini-graph panels below the table.
    btrfs_renderOverviewPageGraphs($app, $device, $data);
}

/**
 * Renders mini-graph panels for each filesystem below the overview table.
 *
 * Each filesystem gets a panel with seven mini-graphs showing errors,
 * space usage, scrub rate, data types, and I/O statistics.
 *
 * @param  \App\Models\Application  $app     Btrfs application model.
 * @param  array                    $device  Device array.
 * @param  array                    $data    Initialized data array.
 * @return void
 */
function btrfs_renderOverviewPageGraphs(App\Models\Application $app, array $device, array $data): void
{
    // Extract required data arrays.
    $filesystems = $data['filesystems'] ?? [];
    $filesystem_meta = $data['filesystem_meta'] ?? [];
    $filesystem_tables = $data['filesystem_tables'] ?? [];
    $fs_rrd_key = $data['fs_rrd_key'] ?? [];
    $scrub_is_running_fs = $data['scrub_is_running_fs'] ?? [];
    $balance_is_running_fs = $data['balance_is_running_fs'] ?? [];
    $state_sensor_values = $data['state_sensor_values'] ?? [];

    // Define graph types to render for each filesystem.
    $overview_graph_types = [
        'btrfs_fs_errors_by_type' => 'Aggregate Errors by Type',
        'btrfs_fs_errors_by_device' => 'Aggregate Errors by Device',
        'btrfs_fs_space' => 'Filesystem Space',
        'btrfs_fs_scrub_bytes' => 'Scrub Rate',
        'btrfs_fs_data_types' => 'Per Data Type',
        'btrfs_fs_diskio_ops' => 'Aggregate Ops/sec',
        'btrfs_fs_diskio_bits' => 'Aggregate Bps',
    ];

    // Render one graph panel per filesystem.
    if (empty($filesystems)) {
        Log::error('Btrfs overview graphs: no filesystems to render');
    }
    foreach ($filesystems as $fs) {
        Log::debug('Btrfs overview graphs: rendering filesystem panel', [
            'fs' => $fs,
        ]);
        // Extract filesystem data and build display name.
        $fs_data = $filesystem_tables[$fs] ?? [];
        $fs_label = trim((string) ($filesystem_meta[$fs]['label'] ?? ''));
        $display_name = $fs_label !== '' ? $fs_label . ' (' . $fs . ')' : (string) $fs;

        // Calculate used space percentage for header.
        $used_value = (float) ($fs_data['used'] ?? 0);
        $size_value = (float) ($fs_data['device_size'] ?? 0);
        $used_text = format_metric_value($fs_data['used'] ?? null, 'used');
        $total_text = format_metric_value($fs_data['device_size'] ?? null, 'device_size');
        $used_percent_text = $size_value > 0
            ? rtrim(rtrim(number_format(($used_value / $size_value) * 100, 2, '.', ''), '0'), '.') . '%'
            : 'N/A';

        // Calculate combined status for badge.
        $fs_rrd_id = $fs_rrd_key[$fs] ?? $fs;
        $io_code = state_code_from_sensor($state_sensor_values, 'btrfsIoStatusState', (string) $fs_rrd_id . '.io', $fs_data['io_status_code'] ?? null);
        $scrub_fallback_code = state_code_from_running_flag($scrub_is_running_fs[$fs] ?? null, $fs_data['scrub_status_code'] ?? null);
        $balance_fallback_code = state_code_from_running_flag($balance_is_running_fs[$fs] ?? null, $fs_data['balance_status_code'] ?? null);
        $scrub_code = state_code_from_sensor($state_sensor_values, 'btrfsScrubStatusState', (string) $fs_rrd_id . '.scrub', $scrub_fallback_code);
        $balance_code = state_code_from_sensor($state_sensor_values, 'btrfsBalanceStatusState', (string) $fs_rrd_id . '.balance', $balance_fallback_code);
        $overall_code = combine_state_code([$io_code, $scrub_code, $balance_code]);
        $overall_state = status_from_code($overall_code);

        // Build link to filesystem detail view.
        $fs_link = LibreNMS\Util\Url::generate([
            'page' => 'device',
            'device' => $device['device_id'],
            'tab' => 'apps',
            'app' => 'btrfs',
            'fs' => $fs,
        ]);

        // Render panel header with name, usage, and status badge.
        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><h3 class="panel-title"><a href="' . $fs_link . '" style="color:#337ab7;">' . htmlspecialchars($display_name) . '</a><div class="pull-right"><small class="text-muted">' . htmlspecialchars($used_text . '/' . $total_text . ' ' . $used_percent_text) . '</small> ' . status_badge($overall_state) . '</div></h3></div>';
        echo '<div class="panel-body"><div class="row">';

        // Render each mini-graph with title and link.
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

// =============================================================================
// Per-Device View Rendering
// Displays detailed information for a single device.
// =============================================================================

/**
 * Renders the per-device detail view.
 *
 * Shows device info (path, UUID, model, serial), scrub status, and errors
 * in a panel layout. Includes links to per-device graphs.
 *
 * @param  \App\Models\Application  $app           Btrfs application model.
 * @param  array                    $device        Device array.
 * @param  string                   $selected_fs   Selected filesystem name.
 * @param  string                   $selected_dev  Selected device ID.
 * @param  array                    $data          Initialized data array.
 * @return void
 */
function btrfs_renderDevView(
    App\Models\Application $app,
    array $device,
    string $selected_fs,
    string $selected_dev,
    array $data
): void {
    // Extract required data arrays.
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
    $state_sensor_values = $data['state_sensor_values'] ?? [];
    $tables = $app->data['tables'] ?? [];

    // Resolve RRD key and build base link.
    $selected_fs_rrd_id = $fs_rrd_key[$selected_fs] ?? $selected_fs;
    $link_array = ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'btrfs'];

    // Extract device tables from raw app data.
    $dev_tables = $tables['filesystem_devices'] ?? [];
    $dev_backing = $tables['backing_devices'] ?? [];
    $dev_info = $tables['devices'] ?? [];

    // Resolve filesystem UUID and device data.
    $fs_uuid = $filesystem_uuid[$selected_fs] ?? '';
    $dev_data = $dev_tables[$fs_uuid][$selected_dev] ?? [];
    $dev_path = $dev_data['device_path'] ?? '';
    $dev_missing = $dev_data['missing'] ?? false;
    $backing_path = $dev_data['backing_device_path'] ?? null;

    // Get device status codes from stored poller data.
    $dev_status = $device_tables[$selected_fs][$selected_dev] ?? [];
    $dev_io_code = $dev_status['io_status_code'] ?? null;
    $dev_scrub_code = $dev_status['scrub_status_code'] ?? null;

    // Resolve state sensor codes with fallback to stored values.
    $io_code = state_code_from_sensor($state_sensor_values, 'btrfsIoStatusState', $selected_fs_rrd_id . '.dev.' . $selected_dev . '.io', $dev_io_code);
    $scrub_code = state_code_from_sensor($state_sensor_values, 'btrfsScrubStatusState', $selected_fs_rrd_id . '.dev.' . $selected_dev . '.scrub', $dev_scrub_code);
    $overall_code = combine_state_code([$io_code, $scrub_code]);
    $overall_state = status_from_code($overall_code);

    // Begin device info panel.
    echo '<div class="btrfs-panels">';

    echo '<div class="panel panel-default panel-wide">';
    echo '<div class="panel-heading"><h3 class="panel-title">Device Info<div class="pull-right">' . status_badge($overall_state) . '</div></h3></div>';
    echo '<div class="panel-body">';

    // Build info rows array with key-value pairs.
    $info_rows = [];
    $info_rows[] = ['key' => 'name', 'value' => $dev_path];
    $info_rows[] = ['key' => 'dev_id', 'value' => $selected_dev];
    if ($fs_uuid !== '') {
        $info_rows[] = ['key' => 'uuid', 'value' => $fs_uuid];
    }

    // Add device identity info from lsblk/sysfs.
    $device_info = $dev_info[$dev_path] ?? [];
    $id_model = $device_info['id_model'] ?? $device_info['model'] ?? null;
    $id_serial = $device_info['id_serial_short'] ?? null;
    if ($id_model !== null) {
        $info_rows[] = ['key' => 'model', 'value' => $id_model];
    }
    if ($id_serial !== null) {
        $info_rows[] = ['key' => 'serial', 'value' => $id_serial];
    }

    // Add backing device info if available (for loopback/qemu).
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

    // Add device size.
    $size = $dev_data['size'] ?? null;
    if ($size !== null) {
        $info_rows[] = ['key' => 'size', 'value' => format_metric_value($size, 'bytes')];
    }

    // Add I/O error counters.
    $dev_errors = $device_tables[$selected_fs][$selected_dev]['errors'] ?? [];
    $error_keys = ['write_io_errs', 'read_io_errs', 'flush_io_errs', 'corruption_errs', 'generation_errs'];
    foreach ($error_keys as $err_key) {
        if (isset($dev_errors[$err_key])) {
            $info_rows[] = ['key' => $err_key, 'value' => format_metric_value($dev_errors[$err_key], $err_key)];
        }
    }

    // Add RAID profile allocations.
    $dev_profiles = $device_tables[$selected_fs][$selected_dev]['raid_profiles'] ?? [];
    foreach ($dev_profiles as $profile_key => $profile_bytes) {
        $info_rows[] = ['key' => $profile_key, 'value' => format_metric_value($profile_bytes, 'bytes')];
    }

    // Render info table.
    echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
    echo '<thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
    foreach ($info_rows as $row) {
        echo '<tr><td>' . htmlspecialchars(format_display_name((string) $row['key'])) . '</td>';
        echo '<td>' . htmlspecialchars($row['value']) . '</td></tr>';
    }
    echo '</tbody></table></div></div></div>';

    // Build scrub status badge.
    $dev_scrub = $scrub_status_devices[$selected_fs][$selected_dev] ?? [];
    $scrub_status_code = state_code_from_sensor(
        $state_sensor_values,
        'btrfsScrubStatusState',
        $selected_fs_rrd_id . '.scrub',
        state_code_from_running_flag($scrub_is_running_fs[$selected_fs] ?? false, $filesystem_tables[$selected_fs]['scrub_status_code'] ?? null)
    );
    $scrub_badge = status_badge(status_from_code($scrub_status_code));

    // Render scrub status panel.
    echo '<div class="panel panel-default panel-wide">';
    echo '<div class="panel-heading"><h3 class="panel-title">Scrub<div class="pull-right">' . $scrub_badge . '</div></h3></div>';
    echo '<div class="panel-body">';

    // Render scrub data table, excluding path.
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

// =============================================================================
// Per-Filesystem View Rendering
// Displays detailed information for a single filesystem.
// =============================================================================

/**
 * Renders the per-filesystem detail view.
 *
 * Orchestrates rendering of overview, scrub, balance, device usage,
 * device stats, and per-device scrub panels. Also includes graphs.
 *
 * @param  \App\Models\Application  $app           Btrfs application model.
 * @param  array                    $device        Device array.
 * @param  string                   $selected_fs   Selected filesystem name.
 * @param  array                    $data          Initialized data array.
 * @return void
 */
function btrfs_renderFsView(
    App\Models\Application $app,
    array $device,
    string $selected_fs,
    array $data
): void {
    // Extract required data arrays.
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
    $state_sensor_values = $data['state_sensor_values'] ?? [];

    // Resolve RRD key for this filesystem.
    $selected_fs_rrd_id = $fs_rrd_key[$selected_fs] ?? $selected_fs;

    // Build path-to-device-id mapping for link generation.
    $path_to_dev_id = [];
    foreach ($device_map[$selected_fs] ?? [] as $dev_id => $dev_path) {
        $path_to_dev_id[(string) $dev_path] = (string) $dev_id;
    }

    // Build scrub status badge for filesystem-level scrub.
    $scrub_status_code = state_code_from_sensor(
        $state_sensor_values,
        'btrfsScrubStatusState',
        $selected_fs_rrd_id . '.scrub',
        state_code_from_running_flag($scrub_is_running_fs[$selected_fs] ?? false, $filesystem_tables[$selected_fs]['scrub_status_code'] ?? null)
    );
    $scrub_badge = status_badge(status_from_code($scrub_status_code));

    // Prepare scrub status data split by device for per-device table.
    $scrub_split = [
        'overview' => is_array($scrub_status_fs[$selected_fs] ?? null) ? flatten_assoc_rows($scrub_status_fs[$selected_fs]) : [],
        'devices' => [],
        'device_columns' => [],
    ];

    // Collect per-device scrub data and determine columns.
    if (is_array($scrub_status_devices[$selected_fs] ?? null) && count($scrub_status_devices[$selected_fs] ?? []) > 0) {
        foreach ($scrub_status_devices[$selected_fs] as $dev_id => $scrub_metrics) {
            if (! is_array($scrub_metrics)) {
                continue;
            }
            $dev_id_str = (string) $dev_id;
            $dev_path = (string) ($device_map[$selected_fs][$dev_id_str] ?? ($scrub_metrics['path'] ?? $dev_id_str));
            $scrub_split['devices'][$dev_path] = $scrub_metrics;
            // Collect unique column keys across all devices.
            foreach ($scrub_metrics as $metric_key => $unused) {
                if (! in_array($metric_key, $scrub_split['device_columns'], true)) {
                    $scrub_split['device_columns'][] = $metric_key;
                }
            }
        }
    }

    // Render row 1: Overview, Scrub, Balance panels.
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

    // Render row 2: Device Usage and Device Stats panels.
    btrfs_renderFsPanelsRow2(
        $app,
        $device,
        $selected_fs,
        $selected_fs_rrd_id,
        $path_to_dev_id,
        $data
    );

    // Render Scrub Per Device table.
    btrfs_renderScrubPerDevice(
        $scrub_split,
        $path_to_dev_id,
        $device['device_id'],
        $selected_fs,
        $data
    );
}

/**
 * Renders row 1 of filesystem panels: Overview, Scrub, and Balance.
 *
 * Contains inline closures for rendering balance and scrub overview panels
 * since they have complex conditional rendering logic.
 *
 * @param  \App\Models\Application  $app                 Btrfs application model.
 * @param  array                    $device              Device array.
 * @param  string                    $selected_fs         Selected filesystem name.
 * @param  string                    $selected_fs_rrd_id   RRD key for filesystem.
 * @param  array                     $scrub_split         Scrub data split by device.
 * @param  string                    $scrub_badge         Pre-rendered scrub status badge.
 * @param  array                    $data                Initialized data array.
 * @param  array                    $balance_status_fs   Balance status per filesystem.
 * @param  array                    $scrub_is_running_fs Scrub running state per filesystem.
 * @return void
 */
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

    // Inline closure: renders the balance status panel.
    $render_balance_panel = static function (array $split, array $rows, ?string $panel_col_class, ?string $selected_dev, int $balance_status_code): void {
        // Convert status code to state and badge.
        $state = match ($balance_status_code) {
            0 => 'ok', 1 => 'running', 2 => 'na', 3 => 'error', 4 => 'missing', default => 'na',
        };
        $badge = status_badge($state);

        // Filter overview rows to exclude 'path' and 'status' keys.
        $overview_rows = [];
        foreach ($split['overview'] as $row) {
            if ($row['key'] !== 'path' && $row['key'] !== 'status') {
                $overview_rows[] = $row;
            }
        }

        // Determine visibility conditions.
        $profiles = $split['devices'];
        $has_profiles = count($profiles) > 0;
        $has_overview = count($overview_rows) > 0;
        $is_idle = $balance_status_code !== 1;
        $show_overview = ($has_profiles || (! $is_idle && $has_overview));

        // Render panel with optional column wrapper.
        if ($panel_col_class !== null) {
            echo '<div class="' . $panel_col_class . '">';
        }
        echo '<div class="panel panel-default panel-wide">';
        echo '<div class="panel-heading"><h3 class="panel-title">Balance<div class="pull-right">' . $badge . '</div></h3></div>';
        echo '<div class="panel-body">';

        // Show idle message if no balance running and no profiles.
        if ($is_idle && ! $has_profiles) {
            echo '<p class="text-muted">No balance operation running.</p>';
        }

        // Render overview table if conditions met.
        if ($show_overview && count($overview_rows) > 0) {
            echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
            echo '<thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
            foreach ($overview_rows as $row) {
                echo '<tr><td>' . htmlspecialchars(format_metric_value($row['key'], 'metric')) . '</td>';
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

    // Inline closure: renders a compact two-column metrics table.
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

    // Build overview metric pairs for the overview table.
    $overview_metric_keys = ['device_size', 'device_unallocated', 'free_estimated', 'free_statfs_df', 'device_allocated', 'used', 'free_estimated_min', 'global_reserve'];
    $overview_pairs = [];
    foreach ($overview_metric_keys as $metric_key) {
        $overview_pairs[] = ['metric' => $metric_key, 'value' => $filesystem_tables[$selected_fs][$metric_key] ?? null];
    }
    $fs_label = $filesystem_meta[$selected_fs]['label'] ?? null;
    $fs_title = ! empty($fs_label) ? $fs_label . ' (' . $selected_fs . ')' : $selected_fs;
    $fs_uuid = $filesystem_meta[$selected_fs]['uuid'] ?? '';

    // Render Overview panel.
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

    // Inline closure: renders scrub overview panel.
    $render_scrub_overview_panel = static function (array $split, array $rows, string $badge): void {
        echo '<div class="panel panel-default panel-wide">';
        echo '<div class="panel-heading"><h3 class="panel-title">Scrub<div class="pull-right">' . $badge . '</div></h3></div>';
        echo '<div class="panel-body">';

        // Show message if no scrub data.
        if (count($rows) === 0) {
            echo '<em>No data returned</em>';
        } else {
            // Build lookup map for progress percentage.
            $overview_map = [];
            foreach ($split['overview'] as $overview_row) {
                $overview_map[$overview_row['key']] = $overview_row['value'];
            }

            echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
            echo '<thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
            foreach ($split['overview'] as $overview_row) {
                $key = $overview_row['key'];
                $value = $overview_row['value'];

                // Skip certain keys that are display-only or redundant.
                if ($key === 'status' || $key === 'uuid' || $key === 'bytes_scrubbed.progress') {
                    continue;
                }

                $display_key = $key;
                $display_value = format_metric_value($value, (string) $key);

                // Special handling: show bytes_scrubbed.bytes with progress percentage.
                if ($key === 'bytes_scrubbed.bytes') {
                    $progress = $overview_map['bytes_scrubbed.progress'] ?? null;
                    $formatted = format_metric_value($value, 'bytes');
                    $display_value = is_numeric($progress)
                        ? $formatted . ' (' . rtrim(rtrim(number_format((float) $progress, 2, '.', ''), '0'), '.') . '%)'
                        : $formatted;
                    $display_key = 'total_bytes_done';
                } elseif ($key === 'total_to_scrub') {
                    // Apply byte formatting to total_to_scrub.
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

    // Prepare and render balance panel.
    $balance_split = ['overview' => [], 'devices' => [], 'device_columns' => []];
    $balance_data = $balance_status_fs[$selected_fs] ?? [];
    if (is_array($balance_data)) {
        foreach ($balance_data as $key => $value) {
            // Skip complex nested structures.
            if ($key === 'profiles' || $key === 'lines') {
                continue;
            }
            // Flatten array values into overview rows.
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

/**
 * Renders row 2 of filesystem panels: Device Usage and Device Stats.
 *
 * Device Usage shows per-device size/slack/RAID profile allocations.
 * Device Stats shows I/O error counters per device.
 *
 * @param  \App\Models\Application  $app                 Btrfs application model.
 * @param  array                    $device              Device array.
 * @param  string                    $selected_fs         Selected filesystem name.
 * @param  string                    $selected_fs_rrd_id   RRD key for filesystem.
 * @param  array                    $path_to_dev_id      Path to device ID mapping.
 * @param  array                    $data                Initialized data array.
 * @return void
 */
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
    $state_sensor_values = $data['state_sensor_values'] ?? [];

    // Build base link array for device detail links.
    $link_array = ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'btrfs'];

    echo '<div class="btrfs-panels">';

    // Collect device usage data and aggregate RAID profile columns.
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

    // Render Device Usage panel if devices exist.
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
            // Link device path to per-device view if device ID known.
            $link = $dev_id !== null
                ? generate_link(htmlspecialchars((string) $dev_path), $link_array, ['fs' => $selected_fs, 'dev' => $dev_id])
                : htmlspecialchars((string) $dev_path);
            echo '<tr><td>' . $link . '</td>';
            echo '<td>' . htmlspecialchars(format_metric_value($usage['size'] ?? null, 'device_size')) . '</td>';
            echo '<td>' . htmlspecialchars(format_metric_value($usage['slack'] ?? null, 'device_slack')) . '</td>';
            foreach ($all_raid_profiles as $profile) {
                echo '<td>' . htmlspecialchars(format_metric_value($raid_profiles[$profile] ?? null, 'bytes')) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></div></div>';
    }

    // Build device stats data for I/O error display.
    $stats_split = ['overview' => [], 'devices' => [], 'device_columns' => ['corruption_errs', 'flush_io_errs', 'generation_errs', 'read_io_errs', 'write_io_errs']];
    foreach ($device_map[$selected_fs] ?? [] as $dev_id => $dev_path) {
        $dev_stats = $device_tables[$selected_fs][$dev_id] ?? [];
        if (! is_array($dev_stats)) {
            continue;
        }
        $stats_split['devices'][$dev_path] = $dev_stats;
    }

    // Render Device Stats panel if devices have stats.
    if (count($stats_split['devices']) > 0) {
        // Calculate filesystem-level combined status.
        $fs_io_code = state_code_from_sensor($state_sensor_values, 'btrfsIoStatusState', $selected_fs_rrd_id . '.io', $filesystem_tables[$selected_fs]['io_status_code'] ?? null);
        $fs_scrub_code = state_code_from_sensor($state_sensor_values, 'btrfsScrubStatusState', $selected_fs_rrd_id . '.scrub', null);
        $fs_balance_code = state_code_from_sensor($state_sensor_values, 'btrfsBalanceStatusState', $selected_fs_rrd_id . '.balance', null);
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
            // Calculate per-device status badge.
            $dev_io_code = $metrics['io_status_code'] ?? null;
            $dev_scrub_code = $metrics['scrub_status_code'] ?? null;
            $dev_overall_code = combine_state_code([$dev_io_code ?? 2, $dev_scrub_code ?? 2]);
            $dev_overall_state = status_from_code($dev_overall_code);
            $errors = $metrics['errors'] ?? [];
            echo '<tr><td>' . $link . '</td><td>' . status_badge($dev_overall_state) . '</td>';
            foreach ($stats_split['device_columns'] as $col) {
                echo '<td>' . htmlspecialchars(format_metric_value($errors[$col] ?? null, $col)) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></div></div>';
    }

    echo '</div>';
}

/**
 * Renders the per-device scrub status table.
 *
 * Shows scrub status and metrics for each device in a wide table
 * with columns dynamically determined from available metrics.
 *
 * @param  array   $scrub_split     Scrub data split by device with columns array.
 * @param  array   $path_to_dev_id  Path to device ID mapping for links.
 * @param  int     $device_id       Device ID for link generation.
 * @param  string  $selected_fs     Selected filesystem name.
 * @param  array   $data            Initialized data array.
 * @return void
 */
function btrfs_renderScrubPerDevice(
    array $scrub_split,
    array $path_to_dev_id,
    int $device_id,
    string $selected_fs,
    array $data
): void {
    $link_array = ['page' => 'device', 'device' => $device_id, 'tab' => 'apps', 'app' => 'btrfs'];

    echo '<div class="btrfs-panels">';

    // Render table if per-device scrub data exists.
    if (count($scrub_split['devices']) > 0) {
        // Exclude columns that are not useful in per-device view.
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
            // Convert scrub status string to state for badge.
            $scrub_state = scrub_status_to_state($metrics['status'] ?? '');
            echo '<tr><td>' . $link . '</td><td>' . status_badge($scrub_state) . '</td>';
            foreach ($scrub_split['device_columns'] as $column) {
                if (! in_array($column, $hidden_columns, true)) {
                    $value = $metrics[$column] ?? '';
                    echo '<td>' . htmlspecialchars(format_metric_value($value, $column)) . '</td>';
                }
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></div></div>';
    } else {
        // Show placeholder when no per-device scrub data available.
        echo '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title">Scrub Per Device</h3></div>';
        echo '<div class="panel-body"><em>No per-device scrub details were reported.</em></div></div>';
    }

    echo '</div>';
}

// =============================================================================
// Graph Rendering
// Renders performance and status graphs for each view level.
// =============================================================================

/**
 * Renders per-device graphs and matched disk I/O graphs.
 *
 * Shows usage, I/O errors, and scrub error graphs for the selected device.
 * Also attempts to match the device to system disk I/O and renders those graphs.
 *
 * @param  \App\Models\Application  $app   Btrfs application model.
 * @param  array                    $data  Initialized data array.
 * @param  array                    $vars  URL parameters including graph selection.
 * @return void
 */
function btrfs_renderDevGraphs(App\Models\Application $app, array $data, array $vars): void
{
    $device_tables = $data['device_tables'] ?? [];
    $selected_fs = $data['selected_fs'];
    $selected_dev = $data['selected_dev'];
    $device_metadata = $data['device_metadata'] ?? [];

    // Define per-device graph types.
    $dev_graphs = [
        'btrfs_dev_usage' => 'Usage',
        'btrfs_dev_errors' => 'IO Errors (Rate)',
        'btrfs_dev_errors_counter' => 'IO Errors (Total)',
        'btrfs_dev_scrub_errors' => 'Scrub Errors (Total)',
        'btrfs_dev_scrub_errors_derive' => 'Scrub Errors (Rate)',
    ];
    btrfs_renderAppGraphs($app, $dev_graphs, $selected_fs, $selected_dev, $vars);

    // Attempt to match device to disk I/O and render if found.
    $selected_diskio = find_diskio($app['device_id'], $device_tables, $selected_fs, $selected_dev, $device_metadata);
    if ($selected_diskio !== null) {
        render_diskio_graphs($selected_diskio);
    }
}

/**
 * Renders per-filesystem graphs and filesystem aggregate I/O graphs.
 *
 * Shows space usage, scrub rate, data type ratios, errors, and I/O graphs
 * for the selected filesystem.
 *
 * @param  \App\Models\Application  $app   Btrfs application model.
 * @param  array                    $data  Initialized data array.
 * @param  array                    $vars  URL parameters including graph selection.
 * @return void
 */
function btrfs_renderFsGraphs(App\Models\Application $app, array $data, array $vars): void
{
    $selected_fs = $data['selected_fs'];

    // Define per-filesystem graph types.
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

/**
 * Renders a set of graphs with optional filtering by graph parameter.
 *
 * Common renderer used by both per-device and per-filesystem graph sections.
 * If a 'graph' parameter is provided in vars, only that graph type is shown.
 *
 * @param  \App\Models\Application  $app           Btrfs application model.
 * @param  array                    $graphs        Graph type to title mapping.
 * @param  string|null              $selected_fs   Selected filesystem name.
 * @param  string|null              $selected_dev  Selected device ID.
 * @param  array                    $vars          URL parameters.
 * @return void
 */
function btrfs_renderAppGraphs(
    App\Models\Application $app,
    array $graphs,
    ?string $selected_fs,
    ?string $selected_dev,
    array $vars
): void {
    // Filter to single graph if graph parameter specified.
    $current_graph = $vars['graph'] ?? null;
    if ($current_graph !== null && $current_graph !== '' && isset($graphs[$current_graph])) {
        $graphs = [$current_graph => $graphs[$current_graph]];
    }

    // Render each graph in a panel.
    foreach ($graphs as $key => $text) {
        $graph_array = [
            'height' => '100',
            'width' => '215',
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'id' => $app['app_id'],
            'type' => 'application_' . $key,
        ];

        // Add filesystem/device parameters if available.
        if (isset($selected_fs)) {
            $graph_array['fs'] = $selected_fs;
        }
        if (isset($selected_dev)) {
            $graph_array['dev'] = $selected_dev;
        }

        // Render graph panel.
        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><h3 class="panel-title">' . $text . '</h3></div>';
        echo '<div class="panel-body"><div class="row">';
        include 'includes/html/print-graphrow.inc.php';
        echo '</div></div>';
        echo '</div>';
    }
}

// =============================================================================
// Main Execution
// Initializes data and routes to appropriate view renderer.
// =============================================================================

Log::debug('Btrfs device page: start', [
    'device_id' => $device['device_id'] ?? null,
    'app_id' => $app['app_id'] ?? null,
    'vars' => $vars,
]);

$data = initialize_data($app, $device, $vars);

// Build base link array for navigation.
$link_array = [
    'page' => 'device',
    'device' => $device['device_id'],
    'tab' => 'apps',
    'app' => 'btrfs',
];

// Load live state sensor values for status rendering.
$state_sensor_values = load_state_sensors($device['device_id']);
$data['state_sensor_values'] = $state_sensor_values;

// Extract selection state from initialized data.
$selected_fs = $data['selected_fs'];
$selected_dev = $data['selected_dev'];
$is_overview = ! isset($selected_fs);
$is_per_disk = isset($selected_fs) && isset($selected_dev);

// Resolve selected device path for disk I/O matching.
$selected_dev_path = $is_per_disk && isset($data['device_map'][$selected_fs][$selected_dev])
    ? (string) $data['device_map'][$selected_fs][$selected_dev]
    : null;

// Render navigation menu.
btrfs_renderNavigation($link_array, $data);

// Route to appropriate view based on selection state.
if ($is_per_disk) {
    Log::debug('Btrfs device page: routing to per-device view', [
        'selected_fs' => $selected_fs,
        'selected_dev' => $selected_dev,
    ]);
    // Per-device view: show device info and device graphs.
    btrfs_renderDevView(
        $app,
        $device,
        $selected_fs,
        $selected_dev,
        $data
    );

    btrfs_renderDevGraphs($app, $data, $vars);
} elseif (isset($selected_fs)) {
    Log::debug('Btrfs device page: routing to per-filesystem view', [
        'selected_fs' => $selected_fs,
    ]);
    // Per-filesystem view: show filesystem panels and filesystem graphs.
    btrfs_renderFsView(
        $app,
        $device,
        $selected_fs,
        $data
    );

    btrfs_renderFsGraphs($app, $data, $vars);
} else {
    Log::debug('Btrfs device page: routing to overview view', [
        'filesystems_count' => count($data['filesystems'] ?? []),
    ]);
    // Overview view: show all filesystems summary table and mini-graphs.
    btrfs_renderOverviewPage($app, $device, $data);
}

Log::debug('Btrfs device page: done');
