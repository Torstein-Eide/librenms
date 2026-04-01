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

// DEBUG: Print full app->data
if (isset($_GET['debug_btrfs'])) {
    echo '<pre style="background:#f5f5f5;border:1px solid #ccc;padding:10px;margin:10px;overflow:auto;">';
    echo '<h3>app->data (full)</h3>';
    echo htmlspecialchars(print_r($app->data, true));
    echo '</pre>';
}

// DEBUG: Print discovery data specifically
if (isset($_GET['debug_btrfs_discovery'])) {
    echo '<pre style="background:#e8f5e9;border:1px solid #4caf50;padding:10px;margin:10px;overflow:auto;">';
    echo '<h3>app->data[\'discovery\']</h3>';
    echo htmlspecialchars(print_r($app->data['discovery'] ?? [], true));
    echo '</pre>';
}

// DEBUG: Print filesystem entries specifically
if (isset($_GET['debug_btrfs_fs'])) {
    echo '<pre style="background:#e3f2fd;border:1px solid #2196f3;padding:10px;margin:10px;overflow:auto;">';
    echo '<h3>app->data[\'filesystems\']</h3>';
    echo htmlspecialchars(print_r($app->data['filesystems'] ?? [], true));
    echo '</pre>';
}

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
use function LibreNMS\Plugins\Btrfs\scrub_health_badge;
use function LibreNMS\Plugins\Btrfs\scrub_health_state;
use function LibreNMS\Plugins\Btrfs\scrub_operation_state;
use function LibreNMS\Plugins\Btrfs\scrub_ops_badge;
use function LibreNMS\Plugins\Btrfs\scrub_progress_text_from_status;
use function LibreNMS\Plugins\Btrfs\scrub_state_from_operation_health;
use function LibreNMS\Plugins\Btrfs\scrub_status_to_state;
use function LibreNMS\Plugins\Btrfs\state_code_from_running_flag;
use function LibreNMS\Plugins\Btrfs\state_code_from_sensor;
use function LibreNMS\Plugins\Btrfs\state_to_code;
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
.btrfs-keyval td:first-child {
    text-align: right;
    padding-right: 15px;
    white-space: nowrap;
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
    $fs_mountpoint = $data['fs_mountpoint'] ?? [];
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
        foreach ($filesystems as $index => $fs_uuid) {
            Log::debug('Btrfs navigation: rendering filesystem', [
                'fs_uuid' => $fs_uuid,
                'index' => $index,
            ]);
            // Get mountpoint for display
            $mountpoint = $fs_mountpoint[$fs_uuid] ?? $fs_uuid;
            // Determine display label: prefer label, then 'root', then mountpoint.
            $filesystem_label = $filesystem_meta[$fs_uuid]['label'] ?? null;
            if (! empty($filesystem_label)) {
                $display_fs = (string) $filesystem_label;
            } elseif ($mountpoint === '/') {
                $display_fs = 'root';
            } else {
                $display_fs = (string) $mountpoint;
            }

            // Highlight currently selected filesystem.
            $fs_label = htmlspecialchars($display_fs);
            $label = ($selected_fs === $fs_uuid)
                ? '<span class="pagemenu-selected">' . $fs_label . '</span>'
                : $fs_label;

            // Use UUID for links
            echo generate_link($label, $link_array, ['fs' => $fs_uuid]);
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
 * @param  App\Models\Application  $app     Btrfs application model.
 * @param  array                    $device  Device array.
 * @param  array                    $data    Initialized data array.
 * @return void
 */
function btrfs_renderOverviewPage(App\Models\Application $app, array $device, array $data): void
{
    // Extract required data arrays.
    $filesystems = $data['filesystems'] ?? [];
    $fs_mountpoint = $data['fs_mountpoint'] ?? [];
    $filesystem_meta = $data['filesystem_meta'] ?? [];
    $filesystem_tables = $data['filesystem_tables'] ?? [];
    $device_map = $data['device_map'] ?? [];
    $device_tables = $data['device_tables'] ?? [];
    $scrub_status_fs = $data['scrub_status_fs'] ?? [];
    $scrub_is_running_fs = $data['scrub_is_running_fs'] ?? [];
    $scrub_operation_fs = $data['scrub_operation_fs'] ?? [];
    $scrub_health_fs = $data['scrub_health_fs'] ?? [];
    $balance_is_running_fs = $data['balance_is_running_fs'] ?? [];
    $fs_rrd_key = $data['fs_rrd_key'] ?? [];
    $state_sensor_values = $data['state_sensor_values'] ?? [];

    // Begin overview table panel with heredoc
    echo <<<'HTML'
<div class="panel panel-default">
<div class="panel-heading"><h3 class="panel-title">Filesystems Overview</h3></div>
<div class="panel-body">
<div class="table-responsive">
<table class="table table-condensed table-striped table-hover btrfs-sticky-first">
<thead>
<tr>
<th>Filesystem</th>
<th>Status</th>
<th>Scrub Ops</th>
<th>Scrub Health</th>
<th>Balance</th>
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
    foreach ($filesystems as $fs_uuid) {
        Log::debug('Btrfs overview: rendering filesystem row', [
            'fs_uuid' => $fs_uuid,
        ]);
        // Get mountpoint for display
        $mountpoint = $fs_mountpoint[$fs_uuid] ?? $fs_uuid;
        // Extract filesystem data for this entry.
        $fs_data = $filesystem_tables[$fs_uuid] ?? [];
        $fs_devices = $device_map[$fs_uuid] ?? [];
        $fs_label = trim((string) ($filesystem_meta[$fs_uuid]['label'] ?? ''));
        $display_name = $fs_label !== '' ? $fs_label . ' (' . $mountpoint . ')' : (string) $mountpoint;

        // Calculate total I/O errors across all devices.
        $total_errors = total_io_errors($device_tables[$fs_uuid] ?? []);

        // Calculate used percentage.
        $used_pct_text = used_percent_text($fs_data['used'] ?? null, $fs_data['device_size'] ?? null);

        // Resolve state sensor codes with poller fallback.
        $fs_rrd_id = $fs_rrd_key[$fs_uuid] ?? $fs_uuid;
        $io_code = state_code_from_sensor($state_sensor_values, 'btrfsIoStatusState', (string) $fs_rrd_id . '.io', $fs_data['io_status_code'] ?? null);
        $scrub_operation = $scrub_operation_fs[$fs_uuid] ?? 0;
        $scrub_health = $scrub_health_fs[$fs_uuid] ?? 0;
        $scrub_state = scrub_state_from_operation_health($scrub_operation, $scrub_health);
        $balance_fallback_code = state_code_from_running_flag($balance_is_running_fs[$fs_uuid] ?? null, $fs_data['balance_status_code'] ?? null);
        $balance_code = state_code_from_sensor($state_sensor_values, 'btrfsBalanceStatusState', (string) $fs_rrd_id . '.balance', $balance_fallback_code);
        $io_state = status_from_code($io_code);
        $balance_state = status_from_code($balance_code);

        // Build combined status graph parameters (use UUID for fs parameter).
        $graph_array = [
            'height' => 30,
            'width' => 120,
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'id' => $app['app_id'],
            'type' => 'application_btrfs_fs_status',
            'fs' => $fs_uuid,
            'legend' => 'no',
            'from' => App\Facades\LibrenmsConfig::get('time.day'),
        ];

        // Generate status graph link and image for overlib.
        $graph_link_array = $graph_array;
        $graph_link_array['page'] = 'graphs';
        unset($graph_link_array['height'], $graph_link_array['width']);
        $graph_link = LibreNMS\Util\Url::generate($graph_link_array);
        $graph_img = LibreNMS\Util\Url::lazyGraphTag($graph_array);

        // Build Ops/sec and Bps mini-graph parameters (use UUID for fs parameter).
        $ops_graph = [
            'height' => 30,
            'width' => 120,
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'from' => App\Facades\LibrenmsConfig::get('time.day'),
            'id' => $app['app_id'],
            'type' => 'application_btrfs_fs_diskio_ops',
            'fs' => $fs_uuid,
            'legend' => 'no',
        ];
        $bps_graph = [
            'height' => 30,
            'width' => 120,
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'from' => App\Facades\LibrenmsConfig::get('time.day'),
            'id' => $app['app_id'],
            'type' => 'application_btrfs_fs_diskio_bits',
            'fs' => $fs_uuid,
            'legend' => 'no',
        ];

        // Define table cells for this row
        $row_cells = [
            generate_link(htmlspecialchars((string) $display_name), ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'btrfs'], ['fs' => $fs_uuid]),
            status_badge($io_state),
            scrub_ops_badge($scrub_operation),
            scrub_health_badge($scrub_health),
            status_badge($balance_state),
            htmlspecialchars(number_format($total_errors)),
            htmlspecialchars($used_pct_text),
            htmlspecialchars(format_metric_value($fs_data['used'] ?? null, 'used')),
            htmlspecialchars(format_metric_value($fs_data['free_estimated'] ?? null, 'free_estimated')),
            htmlspecialchars(format_metric_value($fs_data['device_size'] ?? null, 'device_size')),
            number_format(count($fs_devices)),
            generate_link(LibreNMS\Util\Url::lazyGraphTag($ops_graph), ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'btrfs'], ['fs' => $fs_uuid]),
            generate_link(LibreNMS\Util\Url::lazyGraphTag($bps_graph), ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'btrfs'], ['fs' => $fs_uuid]),
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

    echo <<<'HTML'
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
 * @param  App\Models\Application  $app     Btrfs application model.
 * @param  array                    $device  Device array.
 * @param  array                    $data    Initialized data array.
 * @return void
 */
function btrfs_renderOverviewPageGraphs(App\Models\Application $app, array $device, array $data): void
{
    // Extract required data arrays.
    $filesystems = $data['filesystems'] ?? [];
    $fs_mountpoint = $data['fs_mountpoint'] ?? [];
    $filesystem_meta = $data['filesystem_meta'] ?? [];
    $filesystem_tables = $data['filesystem_tables'] ?? [];
    $fs_rrd_key = $data['fs_rrd_key'] ?? [];
    $scrub_is_running_fs = $data['scrub_is_running_fs'] ?? [];
    $scrub_operation_fs = $data['scrub_operation_fs'] ?? [];
    $scrub_health_fs = $data['scrub_health_fs'] ?? [];
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
    foreach ($filesystems as $fs_uuid) {
        Log::debug('Btrfs overview graphs: rendering filesystem panel', [
            'fs_uuid' => $fs_uuid,
        ]);
        // Get mountpoint for display
        $mountpoint = $fs_mountpoint[$fs_uuid] ?? $fs_uuid;
        // Extract filesystem data and build display name.
        $fs_data = $filesystem_tables[$fs_uuid] ?? [];
        $fs_label = trim((string) ($filesystem_meta[$fs_uuid]['label'] ?? ''));
        $display_name = $fs_label !== '' ? $fs_label . ' (' . $mountpoint . ')' : (string) $mountpoint;

        // Calculate used space percentage for header.
        $used_value = (float) ($fs_data['used'] ?? 0);
        $size_value = (float) ($fs_data['device_size'] ?? 0);
        $used_text = format_metric_value($fs_data['used'] ?? null, 'used');
        $total_text = format_metric_value($fs_data['device_size'] ?? null, 'device_size');
        $used_percent_text = $size_value > 0
            ? rtrim(rtrim(number_format(($used_value / $size_value) * 100, 2, '.', ''), '0'), '.') . '%'
            : 'N/A';

        // Calculate combined status for badge.
        $fs_rrd_id = $fs_rrd_key[$fs_uuid] ?? $fs_uuid;
        $io_code = state_code_from_sensor($state_sensor_values, 'btrfsIoStatusState', (string) $fs_rrd_id . '.io', $fs_data['io_status_code'] ?? null);
        $scrub_operation = $scrub_operation_fs[$fs_uuid] ?? 0;
        $scrub_health = $scrub_health_fs[$fs_uuid] ?? 0;
        $scrub_state = scrub_state_from_operation_health($scrub_operation, $scrub_health);
        $scrub_code = state_to_code($scrub_state);
        $balance_fallback_code = state_code_from_running_flag($balance_is_running_fs[$fs_uuid] ?? null, $fs_data['balance_status_code'] ?? null);
        $balance_code = state_code_from_sensor($state_sensor_values, 'btrfsBalanceStatusState', (string) $fs_rrd_id . '.balance', $balance_fallback_code);
        $overall_code = combine_state_code([$io_code, $scrub_code, $balance_code]);
        $overall_state = status_from_code($overall_code);

        // Build link to filesystem detail view (use UUID for fs parameter).
        $fs_link = LibreNMS\Util\Url::generate([
            'page' => 'device',
            'device' => $device['device_id'],
            'tab' => 'apps',
            'app' => 'btrfs',
            'fs' => $fs_uuid,
        ]);

        // Render panel header with name, usage, and status badge.
        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><h3 class="panel-title"><a href="' . $fs_link . '" style="color:#337ab7;">' . htmlspecialchars($display_name) . '</a><div class="pull-right"><small class="text-muted">' . htmlspecialchars($used_text . '/' . $total_text . ' ' . $used_percent_text) . '</small> ' . status_badge($overall_state) . '</div></h3></div>';
        echo '<div class="panel-body"><div class="row">';

        // Render each mini-graph with title and link (use UUID for fs parameter).
        foreach ($overview_graph_types as $graph_type => $graph_title) {
            $graph_array = [
                'height' => '80',
                'width' => '180',
                'to' => App\Facades\LibrenmsConfig::get('time.now'),
                'from' => App\Facades\LibrenmsConfig::get('time.day'),
                'id' => $app['app_id'],
                'type' => 'application_' . $graph_type,
                'fs' => $fs_uuid,
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
 * @param  App\Models\Application  $app           Btrfs application model.
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
    $device_tables = $data['device_tables'] ?? [];
    $scrub_status_devices = $data['scrub_status_devices'] ?? [];
    $scrub_operation_fs = $data['scrub_operation_fs'] ?? [];
    $scrub_health_fs = $data['scrub_health_fs'] ?? [];
    $filesystem_uuid = $data['filesystem_uuid'] ?? [];
    $fs_rrd_key = $data['fs_rrd_key'] ?? [];
    $fs_mountpoint = $data['fs_mountpoint'] ?? [];
    $state_sensor_values = $data['state_sensor_values'] ?? [];
    $tables = $app->data['tables'] ?? [];

    // Resolve RRD key and build base link.
    $selected_fs_rrd_id = $fs_rrd_key[$selected_fs] ?? $selected_fs;

    // Extract device tables from raw app data.
    $dev_tables = $tables['filesystem_devices'] ?? [];
    $dev_backing = $tables['backing_devices'] ?? [];
    $dev_info = $tables['devices'] ?? [];

    // Resolve filesystem UUID and device data.
    $fs_uuid = $filesystem_uuid[$selected_fs] ?? '';
    $dev_data = $dev_tables[$fs_uuid][$selected_dev] ?? [];
    $dev_path = $dev_data['device_path'] ?? '';
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
    $overall_state_badge = status_badge($overall_state);

    // Build device info for key-value display.
    $device_info = $dev_info[$dev_path] ?? [];
    $id_model = $device_info['id_model'] ?? $device_info['model'] ?? '-';
    $id_serial = $device_info['id_serial_short'] ?? '-';

    // Device display (with backing device if present).
    $dev_display = htmlspecialchars($dev_path);
    if ($backing_path !== null) {
        $dev_display .= ' (' . htmlspecialchars($backing_path) . ')';
    }

    // Model display (with backing model if present).
    $model_display = htmlspecialchars($id_model);
    if ($backing_path !== null) {
        $backing_info = $dev_backing[$backing_path] ?? [];
        $backing_model = $backing_info['id_model'] ?? null;
        if ($backing_model !== null) {
            $model_display .= ' (' . htmlspecialchars($backing_model) . ')';
        }
    }
    // Serial display (with backing serial if present).
    $serial_display = htmlspecialchars($id_serial);
    if ($backing_path !== null) {
        $backing_info = $dev_backing[$backing_path] ?? [];
        $backing_serial = $backing_info['id_serial_short'] ?? null;
        if ($backing_serial !== null) {
            $serial_display .= ' (' . htmlspecialchars($backing_serial) . ')';
        }
    }

    // I/O error counters.
    $dev_errors = $device_tables[$selected_fs][$selected_dev]['errors'] ?? [];
    $corruption_errs = format_metric_value($dev_errors['corruption_errs'] ?? 0, 'corruption_errs');
    $flush_io_errs = format_metric_value($dev_errors['flush_io_errs'] ?? 0, 'flush_io_errs');
    $generation_errs = format_metric_value($dev_errors['generation_errs'] ?? 0, 'generation_errs');
    $read_io_errs = format_metric_value($dev_errors['read_io_errs'] ?? 0, 'read_io_errs');
    $write_io_errs = format_metric_value($dev_errors['write_io_errs'] ?? 0, 'write_io_errs');

    // Usage data.
    $usage = $device_tables[$selected_fs][$selected_dev]['usage'] ?? [];
    $slack = format_metric_value($usage['slack'] ?? null, 'device_slack');
    $unallocated = format_metric_value($usage['unallocated'] ?? null, 'unallocated');
    $size = format_metric_value($usage['size'] ?? null, 'device_size');

    // RAID profile allocations.
    $dev_profiles = $device_tables[$selected_fs][$selected_dev]['raid_profiles'] ?? [];

    // Build key-value table rows.
    $info_rows = '';
    $info_rows .= '<tr><td>Model</td><td>' . $model_display . '</td></tr>';
    $info_rows .= '<tr><td>Serial</td><td>' . $serial_display . '</td></tr>';
    $info_rows .= '<tr><td>Corruption Errors</td><td>' . $corruption_errs . '</td></tr>';
    $info_rows .= '<tr><td>Flush Io Errors</td><td>' . $flush_io_errs . '</td></tr>';
    $info_rows .= '<tr><td>Generation Errors</td><td>' . $generation_errs . '</td></tr>';
    $info_rows .= '<tr><td>Read Io Errors</td><td>' . $read_io_errs . '</td></tr>';
    $info_rows .= '<tr><td>Write Io Errors</td><td>' . $write_io_errs . '</td></tr>';
    $info_rows .= '<tr><td>Slack</td><td>' . $slack . '</td></tr>';
    foreach ($dev_profiles as $profile_key => $profile_bytes) {
        $info_rows .= '<tr><td>' . htmlspecialchars(format_display_name($profile_key)) . '</td><td>' . htmlspecialchars(format_metric_value($profile_bytes, 'bytes')) . '</td></tr>';
    }
    $info_rows .= '<tr><td>Unallocated</td><td>' . $unallocated . '</td></tr>';
    $info_rows .= '<tr><td>Size</td><td>' . $size . '</td></tr>';

    // Render device info table with ID and Device in header, Status on right.

    echo <<<HTML
<div class="btrfs-panels">
<div class="panel panel-default panel-wide">
<div class="panel-heading"><h3 class="panel-title">Device: {$selected_dev} - {$dev_display}<div class="pull-right">{$overall_state_badge}</div></h3></div>
<div class="panel-body">
<div class="table-responsive">
<table class="table table-condensed table-striped table-hover btrfs-sticky-first btrfs-keyval">
<tbody>
{$info_rows}
</tbody>
</table>
<p class="text-muted">To reset IO stats errors, run: <code>btrfs device stats -z {$fs_mountpoint[$selected_fs]}</code> on  {$device['hostname']}</p>


</div>
</div>
</div>
HTML;

    // Build scrub status badge.
    $dev_scrub = $scrub_status_devices[$selected_fs][$selected_dev] ?? [];
    $dev_scrub_code = $device_tables[$selected_fs][$selected_dev]['scrub_status_code'] ?? null;
    $scrub_status_code = state_code_from_sensor(
        $state_sensor_values,
        'btrfsScrubStatusState',
        $selected_fs_rrd_id . '.dev.' . $selected_dev . '.scrub',
        $dev_scrub_code
    );
    $scrub_badge = status_badge(status_from_code($scrub_status_code));

    // Build scrub ops and health badges for device scrub panel.
    $scrub_ops_code = state_code_from_sensor(
        $state_sensor_values,
        'btrfsScrubOpsState',
        $selected_fs_rrd_id . '.scrub_ops',
        $scrub_operation_fs[$selected_fs] ?? 0
    );
    $scrub_ops_badge = scrub_ops_badge($scrub_ops_code);
    $scrub_health_badge = scrub_health_badge($scrub_health_fs[$selected_fs] ?? 0);

    // Render scrub status panel.
    $scrub_hidden = ['path', 'is_running'];
    $scrub_keys = array_keys($dev_scrub);
    $scrub_display_keys = array_diff($scrub_keys, $scrub_hidden);

    $scrub_table_rows = '';
    foreach ($scrub_display_keys as $key) {
        $value = $dev_scrub[$key];
        $display_value = format_metric_value($value, (string) $key);
        $key_display = htmlspecialchars(format_display_name((string) $key));
        $value_display = htmlspecialchars($display_value);
        $scrub_table_rows .= <<<HTML
<tr><td style="min-width:150px;">{$key_display}</td><td style="min-width:100px;">{$value_display}</td></tr>

HTML;
    }

    echo <<<HTML
<div class="panel panel-default panel-wide">
<div class="panel-heading"><h3 class="panel-title">Scrub<div class="pull-right">{$scrub_ops_badge} {$scrub_health_badge}</div></h3></div>
<div class="panel-body">
HTML;

    if (count($scrub_display_keys) === 0) {
        echo '<em>No scrub data available</em>';
    } else {
        echo <<<HTML
<div class="table-responsive">
<table class="table table-condensed table-striped table-hover btrfs-sticky-first btrfs-keyval">
<tbody>
{$scrub_table_rows}</tbody>
</table>
</div>
HTML;
    }

    echo <<<'HTML'
</div>
</div>
</div>
HTML;
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
 * @param  App\Models\Application  $app           Btrfs application model.
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
    $scrub_operation_fs = $data['scrub_operation_fs'] ?? [];
    $scrub_health_fs = $data['scrub_health_fs'] ?? [];
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

    // Build scrub ops badge for filesystem-level scrub operation status.
    $scrub_ops_code = state_code_from_sensor(
        $state_sensor_values,
        'btrfsScrubOpsState',
        $selected_fs_rrd_id . '.scrub_ops',
        $scrub_operation_fs[$selected_fs] ?? 0
    );
    $scrub_ops_badge = scrub_ops_badge($scrub_ops_code);
    $scrub_health_badge_html = scrub_health_badge($scrub_health_fs[$selected_fs] ?? 0);

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
        $scrub_health_badge_html,
        $scrub_ops_badge,
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
    Log::debug('Btrfs FsView: Scrub Per Device', [
        'selected_fs' => $selected_fs,
        'scrub_operation_fs_keys' => array_keys($scrub_operation_fs),
        'scrub_operation_fs_value' => $scrub_operation_fs[$selected_fs] ?? 'NOT_FOUND',
    ]);
    btrfs_renderScrubPerDevice(
        $scrub_split,
        $path_to_dev_id,
        $device['device_id'],
        $selected_fs,
        $data,
        $scrub_operation_fs[$selected_fs] ?? 0,
        $scrub_health_fs[$selected_fs] ?? 0
    );
}

/**
 * Renders a compact two-column metrics table.
 *
 * Displays metric pairs in a grid layout with specified number of columns.
 *
 * @param  array   $pairs   Array of ['metric' => ..., 'value' => ...] pairs.
 * @param  int     $columns Number of columns in the grid.
 * @return void
 */
function btrfs_renderOverviewTable(array $pairs, int $columns): void
{
    $rows = (int) ceil(count($pairs) / $columns);

    $table_rows = '';
    for ($r = 0; $r < $rows; $r++) {
        $cells = '';
        for ($c = 0; $c < $columns; $c++) {
            $index = ($r * $columns) + $c;
            if (! isset($pairs[$index])) {
                $cells .= '<td></td><td></td>';
            } else {
                $metric = htmlspecialchars(format_display_name((string) $pairs[$index]['metric']));
                $value = htmlspecialchars(format_metric_value($pairs[$index]['value'], (string) $pairs[$index]['metric']));
                $cells .= "<td>{$metric}</td><td>{$value}</td>";
            }
        }
        $table_rows .= "<tr>{$cells}</tr>";
    }

    echo <<<HTML
<table class="table table-condensed table-striped table-hover btrfs-sticky-first btrfs-keyval">
<tbody>
{$table_rows}</tbody>
</table>
HTML;
}

/**
 * Renders the balance status panel.
 *
 * Shows balance operation status, progress metrics, and optional RAID profiles.
 *
 * @param  array     $split                  Balance data split with overview/devices.
 * @param  array     $rows                   Overview rows for display.
 * @param  string|null $panel_col_class      Optional CSS class for wrapper div.
 * @param  string|null $selected_dev         Selected device ID (unused, reserved).
 * @param  int       $balance_status_code    Balance status code (0=ok, 1=running, 2=na, 3=error, 4=missing).
 * @return void
 */
function btrfs_renderBalancePanel(array $split, array $rows, ?string $panel_col_class, ?string $selected_dev, int $balance_status_code): void
{
    Log::debug('Btrfs renderBalancePanel: rendering', [
        'balance_status_code' => $balance_status_code,
        'overview_count' => count($split['overview'] ?? []),
        'devices_count' => count($split['devices'] ?? []),
    ]);

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

    $table_rows = '';
    foreach ($overview_rows as $row) {
        $key_display = htmlspecialchars(format_metric_value($row['key'], 'metric'));
        $value_display = htmlspecialchars(format_metric_value($row['value'], $row['key']));
        $table_rows .= "<tr><td>{$key_display}</td><td>{$value_display}</td></tr>";
    }

    $idle_message = ($is_idle && ! $has_profiles) ? '<p class="text-muted">No balance operation running.</p>' : '';
    $table_section = ($show_overview && count($overview_rows) > 0) ? <<<HTML
<div class="table-responsive">
<table class="table table-condensed table-striped table-hover btrfs-sticky-first btrfs-keyval">
<tbody>
{$table_rows}</tbody>
</table>
</div>

HTML : '';

    $wrapper_open = $panel_col_class !== null ? "<div class=\"{$panel_col_class}\">" : '';
    $wrapper_close = $panel_col_class !== null ? '</div>' : '';

    echo <<<HTML
{$wrapper_open}
<div class="panel panel-default panel-wide">
<div class="panel-heading"><h3 class="panel-title">Balance<div class="pull-right">{$badge}</div></h3></div>
<div class="panel-body">
{$idle_message}{$table_section}</div>
</div>
{$wrapper_close}
HTML;
}

/**
 * Renders the scrub overview panel.
 *
 * Shows scrub status and progress metrics for the filesystem.
 *
 * @param  array  $split         Scrub data split with overview/devices.
 * @param  array  $rows          Overview rows for display.
 * @param  string $badge         Pre-rendered scrub health badge.
 * @param  string $scrub_ops_badge  Pre-rendered scrub ops badge.
 * @return void
 */
function btrfs_renderScrubOverviewPanel(array $split, array $rows, string $badge, string $scrub_ops_badge): void
{
    if (count($rows) === 0) {
        echo <<<HTML
<div class="panel panel-default panel-wide">
<div class="panel-heading"><h3 class="panel-title">Scrub<div class="pull-right">{$scrub_ops_badge} {$badge}</div></h3></div>
<div class="panel-body">
<em>No data returned</em>
</div>
</div>
HTML;

        return;
    }

    $overview_map = [];
    foreach ($split['overview'] as $overview_row) {
        $overview_map[$overview_row['key']] = $overview_row['value'];
    }

    $table_rows = '';
    foreach ($split['overview'] as $overview_row) {
        $key = $overview_row['key'];
        $value = $overview_row['value'];

        if ($key === 'status' || $key === 'uuid' || $key === 'bytes_scrubbed.progress' || $key === 'is_running') {
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
        } elseif ($key === 'progress_percent') {
            $display_value = format_metric_value($value, $key) . '%';
        }

        $key_display = htmlspecialchars(format_display_name($display_key));
        $value_display = htmlspecialchars($display_value);
        $table_rows .= "<tr><td>{$key_display}</td><td>{$value_display}</td></tr>";
    }

    echo <<<HTML
<div class="panel panel-default panel-wide">
<div class="panel-heading"><h3 class="panel-title">Scrub<div class="pull-right">{$scrub_ops_badge} {$badge}</div></h3></div>
<div class="panel-body">
<div class="table-responsive">
<table class="table table-condensed table-striped table-hover btrfs-sticky-first btrfs-keyval">
<tbody>
{$table_rows}</tbody>
</table>
</div>
</div>
</div>
HTML;
}

/**
 * Renders row 1 of filesystem panels: Overview, Scrub, and Balance.
 *
 * @param  App\Models\Application  $app                 Btrfs application model.
 * @param  array                    $device              Device array.
 * @param  string                    $selected_fs         Selected filesystem name.
 * @param  string                    $selected_fs_rrd_id   RRD key for filesystem.
 * @param  array                     $scrub_split         Scrub data split by device.
 * @param  string                    $scrub_badge         Pre-rendered scrub health badge.
 * @param  string                    $scrub_ops_badge     Pre-rendered scrub ops badge.
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
    string $scrub_ops_badge,
    array $data,
    array $balance_status_fs,
    array $scrub_is_running_fs
): void {
    $filesystem_meta = $data['filesystem_meta'] ?? [];
    $filesystem_tables = $data['filesystem_tables'] ?? [];
    $filesystem_uuid = $data['filesystem_uuid'] ?? [];

    Log::debug('Btrfs renderFsPanelsRow1: rendering', [
        'selected_fs' => $selected_fs,
        'selected_fs_rrd_id' => $selected_fs_rrd_id,
    ]);

    // Build overview metric pairs for the overview table.
    $overview_metric_keys = ['device_size',
        'device_unallocated',
        'free_estimated',
        'free_statfs_df',
        'device_allocated', 'used', 'free_estimated_min', 'global_reserve'];

    $overview_pairs = [];
    foreach ($overview_metric_keys as $metric_key) {
        $overview_pairs[] = ['metric' => $metric_key, 'value' => $filesystem_tables[$selected_fs][$metric_key] ?? null];
    }
    $fs_label = $filesystem_meta[$selected_fs]['label'] ?? null;
    $mountpoint = $data['fs_mountpoint'][$selected_fs] ?? $selected_fs;
    $fs_title = ! empty($fs_label) ? $fs_label . ' (' . $mountpoint . ')' : $mountpoint;
    $fs_uuid = $filesystem_uuid[$selected_fs] ?? '';

    echo '<div class="btrfs-panels">';

    // Render Overview panel.
    $fs_uuid_section = $fs_uuid !== '' ? '<p><strong>UUID:</strong> ' . htmlspecialchars($fs_uuid) . '</p>' : '';
    echo <<<HTML
<div class="panel panel-default panel-wide">
<div class="panel-heading"><h3 class="panel-title">Overview <strong>{$fs_title}</strong> </h3></div>
<div class="panel-body">
{$fs_uuid_section}
<div class="table-responsive">
HTML;

    btrfs_renderOverviewTable($overview_pairs, 2);

    echo <<<'HTML'
</div>
</div>
</div>
HTML;

    // Render Scrub overview panel.
    btrfs_renderScrubOverviewPanel($scrub_split, $scrub_split['overview'], $scrub_badge, $scrub_ops_badge);

    // Prepare and render balance panel.
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
    btrfs_renderBalancePanel($balance_split, $balance_split['overview'], null, null, $filesystem_tables[$selected_fs]['balance_status_code'] ?? 2);

    echo '</div>';
}

/**
 * Renders row 2 of filesystem panels: Device Usage and Device Stats.
 *
 * Device Usage shows per-device size/slack/RAID profile allocations.
 * Device Stats shows I/O error counters per device.
 *
 * @param  App\Models\Application  $app                 Btrfs application model.
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
    $filesystem_profiles = $data['filesystem_profiles'] ?? [];
    $filesystem_uuid = $data['filesystem_uuid'] ?? [];
    $state_sensor_values = $data['state_sensor_values'] ?? [];

    $link_array = ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'btrfs'];

    Log::debug('Btrfs renderFsPanelsRow2: rendering', [
        'selected_fs' => $selected_fs,
        'selected_fs_rrd_id' => $selected_fs_rrd_id,
    ]);

    // Get device info and backing devices from raw app data.
    $tables = $app->data['tables'] ?? [];
    $dev_info = $tables['devices'] ?? [];
    $dev_backing = $tables['backing_devices'] ?? [];
    $fs_devices = $tables['filesystem_devices'] ?? [];
    $fs_uuid = $filesystem_uuid[$selected_fs] ?? '';

    // Collect all devices and aggregate RAID profile columns.
    $all_devices = [];
    $all_raid_profiles = [];
    $error_columns = ['corruption_errs', 'flush_io_errs', 'generation_errs', 'read_io_errs', 'write_io_errs'];

    foreach ($device_map[$selected_fs] ?? [] as $dev_id => $dev_path) {
        $dev_stats = $device_tables[$selected_fs][$dev_id] ?? [];
        if (! is_array($dev_stats) || count($dev_stats) === 0) {
            continue;
        }

        // Get backing device path and unallocated from filesystem device data.
        $backing_path = null;
        $unallocated = null;
        if ($fs_uuid !== '') {
            $fs_dev_data = $fs_devices[$fs_uuid][$dev_id] ?? [];
            $backing_path = $fs_dev_data['backing_device_path'] ?? null;
            $unallocated = $fs_dev_data['unallocated'] ?? null;
        }

        $all_devices[$dev_id] = [
            'dev_id' => $dev_id,
            'dev_path' => $dev_path,
            'backing_path' => $backing_path,
            'unallocated' => $unallocated,
            'stats' => $dev_stats,
        ];
        $raid_profiles = $dev_stats['raid_profiles'] ?? [];
        if (is_array($raid_profiles)) {
            $all_raid_profiles = array_unique(array_merge($all_raid_profiles, array_keys($raid_profiles)));
        }
    }
    sort($all_raid_profiles);

    if (count($all_devices) === 0) {
        Log::debug('Btrfs renderFsPanelsRow2: no devices found');

        return;
    }

    // Calculate overall filesystem status badge.
    $fs_io_code = state_code_from_sensor($state_sensor_values, 'btrfsIoStatusState', $selected_fs_rrd_id . '.io', $filesystem_tables[$selected_fs]['io_status_code'] ?? null);
    $fs_scrub_code = state_code_from_sensor($state_sensor_values, 'btrfsScrubStatusState', $selected_fs_rrd_id . '.scrub', null);
    $fs_balance_code = state_code_from_sensor($state_sensor_values, 'btrfsBalanceStatusState', $selected_fs_rrd_id . '.balance', null);
    $fs_overall_code = combine_state_code([$fs_io_code, $fs_scrub_code, $fs_balance_code]);
    $fs_overall_state = status_from_code($fs_overall_code);
    $fs_overall_badge = status_badge($fs_overall_state);

    // Build dynamic column headers.
    $profile_header_cols = '';
    foreach ($all_raid_profiles as $profile) {
        $profile_display = htmlspecialchars(format_display_name($profile));
        $profile_header_cols .= "<th>{$profile_display}</th>";
    }

    $error_header_titles = [
        'corruption_errs' => 'Corruption Errors',
        'flush_io_errs' => 'Flush I/O Errors',
        'generation_errs' => 'Generation Errors',
        'read_io_errs' => 'Read I/O Errors',
        'write_io_errs' => 'Write I/O Errors',
    ];
    $error_header_cols = '';
    foreach ($error_columns as $col) {
        $col_display = htmlspecialchars(format_display_name($error_header_titles[$col] ?? $col));
        $error_header_cols .= "<th>{$col_display}</th>";
    }

    // Build table rows.
    $table_rows = '';
    foreach ($all_devices as $dev_data) {
        $dev_id = $dev_data['dev_id'];
        $dev_path = $dev_data['dev_path'];
        $backing_dev_path = $dev_data['backing_path'];
        $stats = $dev_data['stats'];

        // Get ID link and device link with backing path.
        $id_link = generate_link(htmlspecialchars((string) $dev_id), $link_array, ['fs' => $selected_fs, 'dev' => $dev_id]);
        if ($backing_dev_path !== null) {
            $dev_link_text = htmlspecialchars((string) $dev_path) . ' (' . htmlspecialchars((string) $backing_dev_path) . ')';
        } else {
            $dev_link_text = htmlspecialchars((string) $dev_path);
        }
        $dev_link = generate_link($dev_link_text, $link_array, ['fs' => $selected_fs, 'dev' => $dev_id]);

        // Get device status.
        $dev_io_code = $stats['io_status_code'] ?? null;
        $dev_scrub_code = $stats['scrub_status_code'] ?? null;
        $dev_overall_code = combine_state_code([$dev_io_code ?? 2, $dev_scrub_code ?? 2]);
        $dev_overall_state = status_from_code($dev_overall_code);
        $status_badge_html = status_badge($dev_overall_state);

        // Get device model (handle bcache backing devices).
        $device_info = $dev_info[$dev_path] ?? [];
        $model = $device_info['id_model'] ?? $device_info['model'] ?? null;
        $backing_path = $dev_data['backing_path'];

        // Build device info for key-value display.
        $device_info = $dev_info[$dev_path] ?? [];
        $id_model = $device_info['id_model'] ?? $device_info['model'] ?? '-';
        $id_serial = $device_info['id_serial_short'] ?? '-';

        // Device display (with backing device if present).
        $dev_display = htmlspecialchars($dev_path);
        if ($backing_path !== null) {
            $dev_display .= ' (' . htmlspecialchars($backing_path) . ')';
        }

        // Model display (with backing model if present).
        $model_display = htmlspecialchars($id_model);
        if ($backing_path !== null) {
            $backing_info = $dev_backing[$backing_path] ?? [];
            $backing_model = $backing_info['id_model'] ?? null;
            if ($backing_model !== null) {
                $model_display .= ' (' . htmlspecialchars($backing_model) . ')';
            }
        }
        // Serial display (with backing serial if present).
        $serial_display = htmlspecialchars($id_serial);
        if ($backing_path !== null) {
            $backing_info = $dev_backing[$backing_path] ?? [];
            $backing_serial = $backing_info['id_serial_short'] ?? null;
            if ($backing_serial !== null) {
                $serial_display .= ' (' . htmlspecialchars($backing_serial) . ')';
            }
        }

        // Get errors.
        $errors = $stats['errors'] ?? [];
        $error_cells = '';
        foreach ($error_columns as $col) {
            $error_cells .= '<td>' . htmlspecialchars(format_metric_value($errors[$col] ?? null, $col)) . '</td>';
        }

        // Get usage.
        $usage = $stats['usage'] ?? [];
        $slack_value = htmlspecialchars(format_metric_value($usage['slack'] ?? null, 'device_slack'));
        $size_value = htmlspecialchars(format_metric_value($usage['size'] ?? null, 'device_size'));

        // Get unallocated from device data.
        $unallocated_value = htmlspecialchars(format_metric_value($dev_data['unallocated'] ?? null, 'device_unallocated'));

        // Get RAID profile cells.
        $raid_profiles = $stats['raid_profiles'] ?? [];
        $raid_cells = '';
        foreach ($all_raid_profiles as $profile) {
            $raid_cells .= '<td>' . htmlspecialchars(format_metric_value($raid_profiles[$profile] ?? null, 'bytes')) . '</td>';
        }
        $table_header = <<<HTML
    <tr>
        <th>ID</th>
        <th>Device</th>
        <th>Status</th>
        <th>Model</th>
        <th>Serial</th>
        {$error_header_cols}
        <th>Slack</th>
        {$profile_header_cols}
        <th>Unallocated</th>
        <th>Size</th>
    </tr>
    HTML;
        $table_rows .= <<<HTML
<tr>
    <td>{$id_link}</td>
    <td>{$dev_link}</td>
    <td>{$status_badge_html}</td>
    <td>{$model_display}</td>
    <td>{$serial_display}</td>
    {$error_cells}
    <td>{$slack_value}</td>
    {$raid_cells}
    <td>{$unallocated_value}</td>
    <td>{$size_value}</td>
</tr>

HTML;
    }

    echo <<<HTML
<div class="btrfs-panels">
<div class="panel panel-default panel-wide">
<div class="panel-heading"><h3 class="panel-title">Devices<div class="pull-right">{$fs_overall_badge}</div></h3></div>
<div class="panel-body">
<div class="table-responsive">
<table class="table table-condensed table-striped table-hover btrfs-sticky-first">
<thead>{$table_header}</thead>
<tbody>
{$table_rows}</tbody>
</table>
</div>
</div>
</div>
</div>
HTML;
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
    array $data,
    int $fs_scrub_operation = 0,
    int $fs_scrub_health = 0
): void {
    $link_array = ['page' => 'device', 'device' => $device_id, 'tab' => 'apps', 'app' => 'btrfs'];

    Log::debug('Btrfs renderScrubPerDevice: rendering', [
        'device_count' => count($scrub_split['devices'] ?? []),
        'selected_fs' => $selected_fs,
    ]);

    if (count($scrub_split['devices']) > 0) {
        $hidden_columns = ['status', 'path', 'id', 'section', 'has_status_suffix', 'has_stats', 'no_stats_available', 'last_physical', 'is_running', 'ops_status'];

        $column_display_names = [
            'status' => 'Status',
            'duration' => 'Duration',
            'time_left' => 'Time Left',
            'total_to_scrub' => 'Total to Scrub',
            'bytes_scrubbed' => 'Bytes Scrubbed',
            'rate' => 'Rate',
            'error_summary' => 'Error Summary',
            'super_errors' => 'Super Errors',
            'malloc_errors' => 'Malloc Errors',
            'corrected_errors' => 'Corrected Errors',
            'uncorrectable_errors' => 'Uncorrectable Errors',
            'verified_errors' => 'Verified Errors',
            'unverified_errors' => 'Unverified Errors',
            'csum_errors' => 'Checksum Errors',
            'read_errors' => 'Read Errors',
            'verify_errors' => 'Verify Errors',
        ];

        $header_cols = '';
        foreach ($scrub_split['device_columns'] as $column) {
            if (! in_array($column, $hidden_columns, true)) {
                $col_display = $column_display_names[$column] ?? format_display_name($column);
                $header_cols .= '<th>' . htmlspecialchars($col_display) . '</th>';
            }
        }

        $table_rows = '';
        foreach ($scrub_split['devices'] as $device_name => $metrics) {
            $dev_id = $path_to_dev_id[(string) $device_name] ?? null;
            $link = $dev_id !== null
                ? generate_link(htmlspecialchars((string) $device_name), $link_array, ['fs' => $selected_fs, 'dev' => $dev_id])
                : htmlspecialchars((string) $device_name);
            $dev_scrub_ops = $metrics['ops_status'] ?? $fs_scrub_operation;
            $scrub_ops_badge_html = scrub_ops_badge($dev_scrub_ops);
            $scrub_health_badge_html = scrub_health_badge($fs_scrub_health);
            $cells = '';
            foreach ($scrub_split['device_columns'] as $column) {
                if (! in_array($column, $hidden_columns, true)) {
                    $value = $metrics[$column] ?? '';
                    $cells .= '<td>' . htmlspecialchars(format_metric_value($value, $column)) . '</td>';
                }
            }
            $table_rows .= "<tr><td>{$link}</td><td>{$scrub_ops_badge_html}</td><td>{$scrub_health_badge_html}</td>{$cells}</tr>";
        }

        echo <<<HTML
<div class="btrfs-panels">
<div class="panel panel-default">
<div class="panel-heading"><h3 class="panel-title">Scrub Per Device</h3></div>
<div class="panel-body">
<div class="table-responsive">
<table class="table table-condensed table-striped table-hover btrfs-sticky-first">
<thead><tr><th>Device</th><th>Scrub Ops</th><th>Scrub Health</th>{$header_cols}</tr></thead>
<tbody>
{$table_rows}</tbody>
</table>
</div>
</div>
</div>
</div>
HTML;
    } else {
        echo <<<'HTML'
<div class="btrfs-panels">
<div class="panel panel-default">
<div class="panel-heading"><h3 class="panel-title">Scrub Per Device</h3></div>
<div class="panel-body"><em>No per-device scrub details were reported.</em></div>
</div>
</div>
HTML;
    }
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
 * @param  App\Models\Application  $app   Btrfs application model.
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
 * @param  App\Models\Application  $app   Btrfs application model.
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
 * @param  App\Models\Application  $app           Btrfs application model.
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
    $current_graph = $vars['graph'] ?? null;
    if ($current_graph !== null && $current_graph !== '' && isset($graphs[$current_graph])) {
        $graphs = [$current_graph => $graphs[$current_graph]];
    }

    Log::debug('Btrfs renderAppGraphs: rendering graphs', [
        'count' => count($graphs),
        'selected_fs' => $selected_fs,
        'selected_dev' => $selected_dev,
    ]);

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

        $title_html = htmlspecialchars($text);
        echo <<<HTML
<div class="panel panel-default">
<div class="panel-heading"><h3 class="panel-title">{$title_html}</h3></div>
<div class="panel-body"><div class="row">
HTML;

        include 'includes/html/print-graphrow.inc.php';

        echo <<<'HTML'
</div></div>
</div>
HTML;
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

if (empty($app->data)) {
    Log::warning('Btrfs device page: no data in database for this device', [
        'device_id' => $device['device_id'] ?? null,
        'app_id' => $app['app_id'] ?? null,
    ]);
    echo '<div class="alert alert-info">';
    echo '<strong>No btrfs data available.</strong> ';
    echo 'The database is empty for this device. ';
    echo 'Please wait for the next poll or run discovery/polling to collect btrfs data.';
    echo '</div>';

    return;
}

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
