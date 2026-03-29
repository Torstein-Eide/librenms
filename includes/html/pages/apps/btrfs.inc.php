<?php

/**
 * Btrfs Global Apps Page
 *
 * Cross-device filesystem overview for the global Apps page.
 * Renders a summary table of all btrfs filesystems across devices with
 * filtering by filesystem name, device hostname, and status.
 *
 * Data source: Poller-persisted app->data for each device's btrfs application.
 * Uses shared btrfs helpers for formatting and status semantics to ensure
 * consistent display with the device page.
 */

use App\Models\Application;
use App\Models\User;
use LibreNMS\Util\Url;

require_once __DIR__ . '/../btrfs-common.inc.php';

use function LibreNMS\Plugins\Btrfs\combine_state_code;
use function LibreNMS\Plugins\Btrfs\extract_filesystem_data;
use function LibreNMS\Plugins\Btrfs\format_metric_value;
use function LibreNMS\Plugins\Btrfs\scrub_progress_text_from_status;
use function LibreNMS\Plugins\Btrfs\status_badge;
use function LibreNMS\Plugins\Btrfs\status_from_code;
use function LibreNMS\Plugins\Btrfs\total_io_errors;
use function LibreNMS\Plugins\Btrfs\used_percent_text;

// =============================================================================
// Authentication and Access Control
// Verify user is authenticated before rendering.
// =============================================================================

$user = Auth::user();
if (! $user instanceof User) {
    return;
}

// =============================================================================
// Data Loading
// Load all btrfs applications accessible to the current user.
// =============================================================================

// Query btrfs applications with device relationship, sorted by hostname.
$apps = Application::query()
    ->hasAccess($user)
    ->where('app_type', 'btrfs')
    ->with('device')
    ->get()
    ->sortBy(fn ($app) => $app->device?->hostname ?? '');

// =============================================================================
// Autocomplete Suggestions
// Build lists of unique filesystem names and device hostnames for datalist.
// =============================================================================

// Initialize suggestion arrays.
$filesystem_suggestions = [];
$device_suggestions = [];

// Iterate through all apps to collect unique suggestions.
foreach ($apps as $app_entry) {
    // Collect device hostname/display name suggestions.
    $hostname = trim((string) ($app_entry->device?->hostname ?? ''));
    $display_name = trim((string) ($app_entry->device?->displayName() ?? ''));
    if ($hostname !== '') {
        $device_suggestions[$hostname] = $hostname;
    }
    if ($display_name !== '') {
        $device_suggestions[$display_name] = $display_name;
    }
    if ($hostname !== '' && $display_name !== '' && $display_name !== $hostname) {
        $device_suggestions[$display_name . ' (' . $hostname . ')'] = $display_name . ' (' . $hostname . ')';
    }

    // Collect filesystem name and label suggestions.
    $app_filesystems = $app_entry->data['filesystems'] ?? [];
    $app_filesystem_meta = $app_entry->data['filesystem_meta'] ?? [];
    foreach ($app_filesystems as $fs_name => $fs_entry) {
        $fs_value = trim((string) $fs_name);
        if ($fs_value === '') {
            continue;
        }

        // Add filesystem mountpoint/value itself.
        $filesystem_suggestions[$fs_value] = $fs_value;

        // Add filesystem label and labeled variant.
        $fs_label = trim((string) ($app_filesystem_meta[$fs_name]['label'] ?? ''));
        if ($fs_label !== '') {
            $filesystem_suggestions[$fs_label] = $fs_label;
            $filesystem_suggestions[$fs_label . ' (' . $fs_value . ')'] = $fs_label . ' (' . $fs_value . ')';
        }
    }

    // Add device identifiers to filesystem suggestions for filter matching.
    if ($hostname !== '') {
        $filesystem_suggestions[$hostname] = $hostname;
    }
    if ($display_name !== '') {
        $filesystem_suggestions[$display_name] = $display_name;
    }
}

// Sort suggestions alphabetically for easier lookup.
ksort($filesystem_suggestions);
ksort($device_suggestions);

// =============================================================================
// Filter Processing
// Parse URL filter parameters and validate against allowed values.
// =============================================================================

// Get filter values from URL parameters.
$filter_text = strtolower(trim((string) ($vars['filter'] ?? '')));
$filter_device = strtolower(trim((string) ($vars['device'] ?? '')));
$filter_status = strtolower(trim((string) ($vars['status'] ?? 'all')));

// Define allowed status filter values.
$allowed_status_filters = ['all', 'ok', 'running', 'warning', 'error', 'na'];
if (! in_array($filter_status, $allowed_status_filters, true)) {
    $filter_status = 'all';
}

// =============================================================================
// Filter Form Panel
// Render filter form with filesystem, device, and status inputs.
// =============================================================================

$filesystem_datalist_options = '';
foreach ($filesystem_suggestions as $suggestion) {
    $filesystem_datalist_options .= '<option value="' . htmlspecialchars($suggestion) . '"></option>';
}

$device_datalist_options = '';
foreach ($device_suggestions as $suggestion) {
    $device_datalist_options .= '<option value="' . htmlspecialchars($suggestion) . '"></option>';
}

$status_options = '';
foreach ($allowed_status_filters as $status_option) {
    $selected = $filter_status === $status_option ? ' selected' : '';
    $status_options .= '<option value="' . htmlspecialchars($status_option) . '"' . $selected . '>' . htmlspecialchars(ucfirst($status_option)) . '</option>';
}

$filter_value = htmlspecialchars((string) ($vars['filter'] ?? ''));
$device_value = htmlspecialchars((string) ($vars['device'] ?? ''));
$reset_url = Url::generate(['page' => 'apps', 'app' => 'btrfs']);

echo <<<HTML
<div class="panel panel-default">
<div class="panel-heading"><h3 class="panel-title">Filters</h3></div>
<div class="panel-body">
<form method="get" class="form-inline" action="">
<input type="hidden" name="page" value="apps">
<input type="hidden" name="app" value="btrfs">
<div class="form-group" style="margin-right: 8px;">
<label for="btrfs-filter" style="margin-right: 4px;">Filesystem</label>
<input id="btrfs-filter" name="filter" type="text" class="form-control input-sm" list="btrfs-filesystem-list" value="$filter_value" placeholder="label, mountpoint">
</div>
<div class="form-group" style="margin-right: 8px;">
<label for="btrfs-device-filter" style="margin-right: 4px;">Device</label>
<input id="btrfs-device-filter" name="device" type="text" class="form-control input-sm" list="btrfs-device-list" value="$device_value" placeholder="hostname">
</div>
<div class="form-group" style="margin-right: 8px;">
<label for="btrfs-status-filter" style="margin-right: 4px;">Status</label>
<select id="btrfs-status-filter" name="status" class="form-control input-sm">
$status_options
</select>
</div>
<button type="submit" class="btn btn-primary btn-sm" style="margin-right: 8px;">Apply</button>
<a href="$reset_url" class="btn btn-default btn-sm">Reset</a>
<datalist id="btrfs-filesystem-list">
$filesystem_datalist_options
</datalist>
<datalist id="btrfs-device-list">
$device_datalist_options
</datalist>
</form>
</div>
</div>
HTML;

// =============================================================================
// Filesystems Overview Panel
// Main table showing one row per (device, filesystem) combination.
// =============================================================================

echo '<div class="panel panel-default">';
echo '<div class="panel-heading"><h3 class="panel-title">Filesystems Overview</h3></div>';
echo '<div class="panel-body">';

// Show message if no btrfs applications found.
if ($apps->isEmpty()) {
    echo '<em>No btrfs applications found.</em>';
    echo '</div></div>';

    return;
}

// Build overview table header with heredoc.
$table_header = <<<'HTML'
<thead><tr><th>Device</th><th>Filesystem</th><th>Status</th><th>Scrub</th><th>Balance</th><th>Scrub Progress</th><th>IO Errors</th><th>% Used</th><th>Used</th><th>Free (Estimated)</th><th>Device Size</th><th>Missing</th><th>Devices</th><th>Ops</th><th>Bps</th><th>Combined Status</th></tr></thead>
HTML;

echo <<<HTML
<div class="table-responsive">
<table class="table table-condensed table-striped table-hover btrfs-sticky-first">
$table_header
<tbody>
HTML;

// Iterate through each app and filesystem to build table rows.
foreach ($apps as $app) {
    $device = $app->device;
    if (! $device) {
        continue;
    }

    // Extract filesystem data from app data.
    $filesystemEntries = $app->data['filesystems'] ?? [];
    $structured = is_array($filesystemEntries) && count($filesystemEntries) > 0 && is_array(reset($filesystemEntries));

    // Extract and normalize filesystem data if structured format available.
    if ($structured) {
        $filesystems = array_keys($filesystemEntries);
        $filesystemMeta = [];
        $filesystemTables = [];
        $deviceMap = [];
        $scrubStatusFs = [];
        $scrubIsRunningFs = [];
        $balanceIsRunningFs = [];
        foreach ($filesystemEntries as $fsName => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $filesystemMeta[$fsName] = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            $filesystemTables[$fsName] = is_array($entry['table'] ?? null) ? $entry['table'] : [];
            $deviceMap[$fsName] = is_array($entry['device_map'] ?? null) ? $entry['device_map'] : [];
            $scrubBlock = is_array($entry['scrub'] ?? null) ? $entry['scrub'] : [];
            $balanceBlock = is_array($entry['balance'] ?? null) ? $entry['balance'] : [];
            $scrubStatusFs[$fsName] = is_array($scrubBlock['status'] ?? null) ? $scrubBlock['status'] : [];
            $scrubIsRunningFs[$fsName] = (bool) ($scrubBlock['is_running'] ?? false);
            $balanceIsRunningFs[$fsName] = (bool) ($balanceBlock['is_running'] ?? false);
        }
    } else {
        // Initialize empty arrays for legacy/unstructured data.
        $filesystems = [];
        $filesystemMeta = [];
        $filesystemTables = [];
        $deviceMap = [];
        $scrubStatusFs = [];
        $scrubIsRunningFs = [];
        $balanceIsRunningFs = [];
    }

    // Process each filesystem for this device.
    foreach ($filesystems as $fs) {
        // Build one summary row for this filesystem on this device.
        $fsData = $filesystemTables[$fs] ?? [];
        $fsDevices = $deviceMap[$fs] ?? [];
        $fsLabel = trim((string) ($filesystemMeta[$fs]['label'] ?? ''));
        $displayName = $fsLabel !== '' ? $fsLabel . ' (' . $fs . ')' : (string) $fs;

        // Calculate status badges from stored poller status codes.
        $ioState = status_from_code($fsData['io_status_code'] ?? null);
        $scrubCode = is_bool($scrubIsRunningFs[$fs] ?? null)
            ? (($scrubIsRunningFs[$fs] ?? false) ? 1 : 0)
            : ($fsData['scrub_status_code'] ?? null);
        $balanceCode = is_bool($balanceIsRunningFs[$fs] ?? null)
            ? (($balanceIsRunningFs[$fs] ?? false) ? 1 : 0)
            : ($fsData['balance_status_code'] ?? null);
        $scrubState = status_from_code($scrubCode);
        $balanceState = status_from_code($balanceCode);
        $overallCode = combine_state_code([$fsData['io_status_code'] ?? 2, $scrubCode, $balanceCode]);
        $overallState = status_from_code($overallCode);

        // Build search text for filter matching.
        $deviceHostname = strtolower((string) ($device->hostname ?? ''));
        $deviceDisplay = strtolower(trim((string) ($device->displayName() ?? '')));
        $deviceSearchText = trim($deviceHostname . ' ' . $deviceDisplay);
        $displayNameLower = strtolower((string) $displayName);

        // Apply filters: skip rows that don't match criteria.
        if ($filter_text !== '' && ! str_contains($displayNameLower, $filter_text) && ! str_contains($deviceSearchText, $filter_text)) {
            continue;
        }
        if ($filter_device !== '' && ! str_contains($deviceSearchText, $filter_device)) {
            continue;
        }
        if ($filter_status !== 'all' && $overallState !== $filter_status) {
            continue;
        }

        // Calculate scrub progress text.
        $scrubStatus = $scrubStatusFs[$fs] ?? [];
        $scrubProgressText = is_array($scrubStatus) && count($scrubStatus) > 0
            ? scrub_progress_text_from_status($scrubStatus)
            : 'N/A';

        // Calculate total I/O errors across all devices.
        $deviceTables = is_array($filesystemEntries[$fs]['device_tables'] ?? null) ? $filesystemEntries[$fs]['device_tables'] : [];
        $totalErrors = total_io_errors($deviceTables);

        // Calculate used percentage.
        $usedPercentText = used_percent_text($fsData['used'] ?? null, $fsData['device_size'] ?? null);

        // Build combined status graph parameters.
        $graphArray = [
            'height' => 40,
            'width' => 180,
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'id' => $app->app_id,
            'type' => 'application_btrfs_fs_status',
            'fs' => $fs,
            'legend' => 'no',
            'from' => App\Facades\LibrenmsConfig::get('time.week'),
        ];
        // Build graph link for overlib popup.
        $graphLinkArray = $graphArray;
        $graphLinkArray['page'] = 'device';
        $graphLinkArray['device'] = $device->device_id;
        $graphLinkArray['tab'] = 'apps';
        $graphLinkArray['app'] = 'btrfs';
        unset($graphLinkArray['height'], $graphLinkArray['width'], $graphLinkArray['type'], $graphLinkArray['id'], $graphLinkArray['legend'], $graphLinkArray['from'], $graphLinkArray['to']);
        $graphLink = Url::generate($graphLinkArray);
        $graphImg = Url::lazyGraphTag($graphArray);

        // Build Ops/sec mini-graph parameters.
        $opsGraph = [
            'height' => 30,
            'width' => 120,
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'from' => App\Facades\LibrenmsConfig::get('time.day'),
            'id' => $app->app_id,
            'type' => 'application_btrfs_fs_diskio_ops',
            'fs' => $fs,
            'legend' => 'no',
        ];

        // Build Bps mini-graph parameters.
        $bpsGraph = [
            'height' => 30,
            'width' => 120,
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'from' => App\Facades\LibrenmsConfig::get('time.day'),
            'id' => $app->app_id,
            'type' => 'application_btrfs_fs_diskio_bits',
            'fs' => $fs,
            'legend' => 'no',
        ];

        // Build link to filesystem detail view on device page.
        $fsDetailLink = ['page' => 'device', 'device' => $device->device_id, 'tab' => 'apps', 'app' => 'btrfs', 'fs' => $fs];

        // Build table row cells array for rendering.
        $has_missing = $fsData['has_missing'] ?? false;
        $missing_badge = $has_missing
            ? '<span class="label label-danger">Yes</span>'
            : '<span class="label label-default">No</span>';

        $row_cells = [
            Url::deviceLink($device),
            generate_link(htmlspecialchars((string) $displayName), ['page' => 'device', 'device' => $device->device_id, 'tab' => 'apps', 'app' => 'btrfs', 'fs' => $fs]),
            status_badge($ioState),
            status_badge($scrubState),
            status_badge($balanceState),
            htmlspecialchars($scrubProgressText),
            htmlspecialchars(number_format($totalErrors)),
            htmlspecialchars($usedPercentText),
            htmlspecialchars(format_metric_value($fsData['used'] ?? null, 'used')),
            htmlspecialchars(format_metric_value($fsData['free_estimated'] ?? null, 'free_estimated')),
            htmlspecialchars(format_metric_value($fsData['device_size'] ?? null, 'device_size')),
            $missing_badge,
            number_format(count($fsDevices)),
            generate_link(Url::lazyGraphTag($opsGraph), $fsDetailLink),
            generate_link(Url::lazyGraphTag($bpsGraph), $fsDetailLink),
            Url::overlibLink($graphLink, $graphImg, $displayName . ' - Combined Status'),
        ];

        $row_html = '<tr>';
        for ($i = 0; $i < count($row_cells); $i++) {
            $row_html .= '<td>' . $row_cells[$i] . '</td>';
        }
        $row_html .= '</tr>';

        echo $row_html;
    }
}

echo <<<'HTML'
</tbody>
</table>
</div>
</div>
</div>
HTML;

// =============================================================================
// Device-Grouped Graph Panels
// Per-device panels with mini-graphs for each filesystem.
// =============================================================================

// Define mini-graph types for filesystem panels.
$graphTypes = [
    'btrfs_fs_errors_by_type' => 'Aggregate Errors by Type',
    'btrfs_fs_errors_by_device' => 'Aggregate Errors by Device',
    'btrfs_fs_space' => 'Filesystem Space',
    'btrfs_fs_scrub_bytes' => 'Scrub Rate',
    'btrfs_fs_data_types' => 'Per Data Type',
    'btrfs_fs_diskio_ops' => 'Aggregate Ops/sec',
    'btrfs_fs_diskio_bits' => 'Aggregate Bps',
];

// Iterate through apps to render device-grouped panels.
foreach ($apps as $app) {
    $device = $app->device;
    if (! $device) {
        continue;
    }

    // Extract filesystem data from app data.
    $filesystemEntries = $app->data['filesystems'] ?? [];
    $structured = is_array($filesystemEntries) && count($filesystemEntries) > 0 && is_array(reset($filesystemEntries));

    // Extract and normalize filesystem data if structured format available.
    if ($structured) {
        $filesystems = array_keys($filesystemEntries);
        $filesystemMeta = [];
        $filesystemTables = [];
        $scrubIsRunningFs = [];
        $balanceIsRunningFs = [];
        foreach ($filesystemEntries as $fsName => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $filesystemMeta[$fsName] = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            $filesystemTables[$fsName] = is_array($entry['table'] ?? null) ? $entry['table'] : [];
            $scrubBlock = is_array($entry['scrub'] ?? null) ? $entry['scrub'] : [];
            $balanceBlock = is_array($entry['balance'] ?? null) ? $entry['balance'] : [];
            $scrubIsRunningFs[$fsName] = (bool) ($scrubBlock['is_running'] ?? false);
            $balanceIsRunningFs[$fsName] = (bool) ($balanceBlock['is_running'] ?? false);
        }
    } else {
        // Initialize empty arrays for legacy/unstructured data.
        $filesystems = [];
        $filesystemMeta = [];
        $filesystemTables = [];
        $scrubIsRunningFs = [];
        $balanceIsRunningFs = [];
    }

    // Render device panel with header.
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">' . Url::deviceLink($device) . '</h3></div>';
    echo '<div class="panel-body">';

    // Render filesystem blocks within device panel.
    foreach ($filesystems as $fs) {
        // Build filesystem display name and link.
        $fsData = $filesystemTables[$fs] ?? [];
        $fsLabel = trim((string) ($filesystemMeta[$fs]['label'] ?? ''));
        $displayFs = $fsLabel !== '' ? $fsLabel . ' (' . $fs . ')' : (string) $fs;
        $headerLink = Url::generate([
            'page' => 'device',
            'device' => $device->device_id,
            'tab' => 'apps',
            'app' => 'btrfs',
            'fs' => $fs,
        ]);

        // Calculate usage text for panel header.
        $usedText = format_metric_value($fsData['used'] ?? null, 'used');
        $totalText = format_metric_value($fsData['device_size'] ?? null, 'device_size');
        $usedPercentText = used_percent_text($fsData['used'] ?? null, $fsData['device_size'] ?? null);

        // Calculate combined status for badge.
        $overallCode = combine_state_code([
            $fsData['io_status_code'] ?? 2,
            is_bool($scrubIsRunningFs[$fs] ?? null) ? (($scrubIsRunningFs[$fs] ?? false) ? 1 : 0) : ($fsData['scrub_status_code'] ?? 2),
            is_bool($balanceIsRunningFs[$fs] ?? null) ? (($balanceIsRunningFs[$fs] ?? false) ? 1 : 0) : ($fsData['balance_status_code'] ?? 2),
        ]);
        $overallState = status_from_code($overallCode);

        // Build search text for filter matching.
        $deviceHostname = strtolower((string) ($device->hostname ?? ''));
        $deviceDisplay = strtolower(trim((string) ($device->displayName() ?? '')));
        $deviceSearchText = trim($deviceHostname . ' ' . $deviceDisplay);
        $displayFsLower = strtolower((string) $displayFs);

        // Apply filters: skip filesystems that don't match criteria.
        if ($filter_text !== '' && ! str_contains($displayFsLower, $filter_text) && ! str_contains($deviceSearchText, $filter_text)) {
            continue;
        }
        if ($filter_device !== '' && ! str_contains($deviceSearchText, $filter_device)) {
            continue;
        }
        if ($filter_status !== 'all' && $overallState !== $filter_status) {
            continue;
        }

        // Build mini-graph HTML for this filesystem.
        $graphs_html = '';
        foreach ($graphTypes as $graphType => $graphTitle) {
            $graph_array = [
                'height' => '80',
                'width' => '180',
                'to' => App\Facades\LibrenmsConfig::get('time.now'),
                'from' => App\Facades\LibrenmsConfig::get('time.day'),
                'id' => $app->app_id,
                'type' => 'application_' . $graphType,
                'fs' => $fs,
                'legend' => 'no',
            ];

            $graphs_html .= '<div class="pull-left" style="margin-right: 8px;">';
            $graphs_html .= '<div class="text-muted" style="font-size: 11px; margin-bottom: 4px;">' . htmlspecialchars($graphTitle) . '</div>';
            $graphs_html .= '<a href="' . $headerLink . '">' . Url::lazyGraphTag($graph_array) . '</a>';
            $graphs_html .= '</div>';
        }

        $usage_text = htmlspecialchars($usedText . '/' . $totalText . ' ' . $usedPercentText);

        echo <<<HTML
<div class="panel panel-default" style="margin-bottom: 10px;">
<div class="panel-heading"><h3 class="panel-title"><a href="$headerLink" style="color:#337ab7;">{$displayFs}</a><div class="pull-right"><small class="text-muted">{$usage_text}</small> {$overallState}</div></h3></div>
<div class="panel-body"><div class="row">
{$graphs_html}
</div></div>
</div>
HTML;
    }

    echo '</div>';
    echo '</div>';
}
