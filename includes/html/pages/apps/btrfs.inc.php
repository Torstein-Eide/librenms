<?php

use App\Models\Application;
use App\Models\User;
use LibreNMS\Util\Url;

require_once __DIR__ . '/../btrfs-common.inc.php';

// Global btrfs apps page.
// Renders cross-device filesystem summaries using poller-persisted app->data.
// Uses shared btrfs helpers for formatting and status semantics to match device page behavior.

$user = Auth::user();
if (! $user instanceof User) {
    return;
}

$apps = Application::query()
    ->hasAccess($user)
    ->where('app_type', 'btrfs')
    ->with('device')
    ->get()
    ->sortBy(fn ($app) => $app->device?->hostname ?? '');

$filesystem_suggestions = [];
$device_suggestions = [];
foreach ($apps as $app_entry) {
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

    $app_filesystems = $app_entry->data['filesystems'] ?? [];
    $app_filesystem_meta = $app_entry->data['filesystem_meta'] ?? [];
    foreach ($app_filesystems as $fs_name) {
        $fs_value = trim((string) $fs_name);
        if ($fs_value === '') {
            continue;
        }

        $filesystem_suggestions[$fs_value] = $fs_value;

        $fs_label = trim((string) ($app_filesystem_meta[$fs_name]['label'] ?? ''));
        if ($fs_label !== '') {
            $filesystem_suggestions[$fs_label] = $fs_label;
            $filesystem_suggestions[$fs_label . ' (' . $fs_value . ')'] = $fs_label . ' (' . $fs_value . ')';
        }
    }

    if ($hostname !== '') {
        $filesystem_suggestions[$hostname] = $hostname;
    }
    if ($display_name !== '') {
        $filesystem_suggestions[$display_name] = $display_name;
    }
}
ksort($filesystem_suggestions);
ksort($device_suggestions);

$filter_text = strtolower(trim((string) ($vars['filter'] ?? '')));
$filter_device = strtolower(trim((string) ($vars['device'] ?? '')));
$filter_status = strtolower(trim((string) ($vars['status'] ?? 'all')));
$allowed_status_filters = ['all', 'ok', 'running', 'warning', 'error', 'na'];
if (! in_array($filter_status, $allowed_status_filters, true)) {
    $filter_status = 'all';
}

$btrfs_print_sticky_first_css();

echo '<div class="panel panel-default">';
echo '<div class="panel-heading"><h3 class="panel-title">Filters</h3></div>';
echo '<div class="panel-body">';
echo '<form method="get" class="form-inline" action="">';
echo '<input type="hidden" name="page" value="apps">';
echo '<input type="hidden" name="app" value="btrfs">';
echo '<div class="form-group" style="margin-right: 8px;">';
echo '<label for="btrfs-filter" style="margin-right: 4px;">Filesystem</label>';
echo '<input id="btrfs-filter" name="filter" type="text" class="form-control input-sm" list="btrfs-filesystem-list" value="' . htmlspecialchars((string) ($vars['filter'] ?? '')) . '" placeholder="label, mountpoint">';
echo '</div>';
echo '<div class="form-group" style="margin-right: 8px;">';
echo '<label for="btrfs-device-filter" style="margin-right: 4px;">Device</label>';
echo '<input id="btrfs-device-filter" name="device" type="text" class="form-control input-sm" list="btrfs-device-list" value="' . htmlspecialchars((string) ($vars['device'] ?? '')) . '" placeholder="hostname">';
echo '</div>';
echo '<div class="form-group" style="margin-right: 8px;">';
echo '<label for="btrfs-status-filter" style="margin-right: 4px;">Status</label>';
echo '<select id="btrfs-status-filter" name="status" class="form-control input-sm">';
foreach ($allowed_status_filters as $status_option) {
    $selected = $filter_status === $status_option ? ' selected' : '';
    echo '<option value="' . htmlspecialchars($status_option) . '"' . $selected . '>' . htmlspecialchars(ucfirst($status_option)) . '</option>';
}
echo '</select>';
echo '</div>';
echo '<button type="submit" class="btn btn-primary btn-sm" style="margin-right: 8px;">Apply</button>';
echo '<a href="' . LibreNMS\Util\Url::generate(['page' => 'apps', 'app' => 'btrfs']) . '" class="btn btn-default btn-sm">Reset</a>';
echo '<datalist id="btrfs-filesystem-list">';
foreach ($filesystem_suggestions as $suggestion) {
    echo '<option value="' . htmlspecialchars($suggestion) . '"></option>';
}
echo '</datalist>';
echo '<datalist id="btrfs-device-list">';
foreach ($device_suggestions as $suggestion) {
    echo '<option value="' . htmlspecialchars($suggestion) . '"></option>';
}
echo '</datalist>';
echo '</form>';
echo '</div>';
echo '</div>';



echo '<div class="panel panel-default">';
echo '<div class="panel-heading"><h3 class="panel-title">Filesystems Overview</h3></div>';
echo '<div class="panel-body">';

if ($apps->isEmpty()) {
    echo '<em>No btrfs applications found.</em>';
    echo '</div></div>';

    return;
}

// Global overview table: one row per (device, filesystem).
echo '<div class="table-responsive">';
echo '<table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
echo '<thead><tr><th>Device</th><th>Filesystem</th><th>Status</th><th>Scrub</th><th>Balance</th><th>Scrub Progress</th><th>IO Errors</th><th>% Used</th><th>Used</th><th>Free (Estimated)</th><th>Device Size</th><th>Missing</th><th>Devices</th><th>Ops</th><th>Bps</th><th>Combined Status</th></tr></thead>';
echo '<tbody>';

foreach ($apps as $app) {
    $device = $app->device;
    if (! $device) {
        continue;
    }

    $filesystems = $app->data['filesystems'] ?? [];
    $filesystemMeta = $app->data['filesystem_meta'] ?? [];
    $filesystemTables = $app->data['filesystem_tables'] ?? [];
    $deviceMap = $app->data['device_map'] ?? [];
    $scrubStatusFs = $app->data['scrub_status_fs'] ?? [];
    $scrubIsRunningFs = $app->data['scrub_is_running_fs'] ?? [];
    $balanceIsRunningFs = $app->data['balance_is_running_fs'] ?? [];

    foreach ($filesystems as $fs) {
        // Build one summary row for this filesystem on this device.
        $fsData = $filesystemTables[$fs] ?? [];
        $fsDevices = $deviceMap[$fs] ?? [];
        $fsLabel = trim((string) ($filesystemMeta[$fs]['label'] ?? ''));
        $displayName = $fsLabel !== '' ? $fsLabel . ' (' . $fs . ')' : (string) $fs;

        $ioState = $btrfs_status_from_code($fsData['io_status_code'] ?? null);
        $scrubCode = is_bool($scrubIsRunningFs[$fs] ?? null)
            ? (($scrubIsRunningFs[$fs] ?? false) ? 1 : 0)
            : ($fsData['scrub_status_code'] ?? null);
        $balanceCode = is_bool($balanceIsRunningFs[$fs] ?? null)
            ? (($balanceIsRunningFs[$fs] ?? false) ? 1 : 0)
            : ($fsData['balance_status_code'] ?? null);
        $scrubState = $btrfs_status_from_code($scrubCode);
        $balanceState = $btrfs_status_from_code($balanceCode);
        $overallCode = $btrfs_combine_state_code([$fsData['io_status_code'] ?? 2, $scrubCode, $balanceCode]);
        $overallState = $btrfs_status_from_code($overallCode);

        $deviceHostname = strtolower((string) ($device->hostname ?? ''));
        $deviceDisplay = strtolower(trim((string) ($device->displayName() ?? '')));
        $deviceSearchText = trim($deviceHostname . ' ' . $deviceDisplay);
        $displayNameLower = strtolower((string) $displayName);
        if ($filter_text !== '' && ! str_contains($displayNameLower, $filter_text) && ! str_contains($deviceSearchText, $filter_text)) {
            continue;
        }
        if ($filter_device !== '' && ! str_contains($deviceSearchText, $filter_device)) {
            continue;
        }
        if ($filter_status !== 'all' && $overallState !== $filter_status) {
            continue;
        }

        $scrubStatus = $scrubStatusFs[$fs] ?? [];
        $scrubProgressText = is_array($scrubStatus) && count($scrubStatus) > 0
            ? $btrfs_scrub_progress_text_from_status($scrubStatus)
            : 'N/A';

        $deviceTables = $app->data['device_tables'][$fs] ?? [];
        $totalErrors = $btrfs_total_io_errors($deviceTables);

        $usedPercentText = $btrfs_used_percent_text($fsData['used'] ?? null, $fsData['device_size'] ?? null);

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
        $graphLinkArray = $graphArray;
        $graphLinkArray['page'] = 'device';
        $graphLinkArray['device'] = $device->device_id;
        $graphLinkArray['tab'] = 'apps';
        $graphLinkArray['app'] = 'btrfs';
        unset($graphLinkArray['height'], $graphLinkArray['width'], $graphLinkArray['type'], $graphLinkArray['id'], $graphLinkArray['legend'], $graphLinkArray['from'], $graphLinkArray['to']);
        $graphLink = Url::generate($graphLinkArray);
        $graphImg = Url::lazyGraphTag($graphArray);

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
        $fsDetailLink = ['page' => 'device', 'device' => $device->device_id, 'tab' => 'apps', 'app' => 'btrfs', 'fs' => $fs];

        echo '<tr>';
        echo '<td>' . Url::deviceLink($device) . '</td>';
        echo '<td>' . generate_link(htmlspecialchars((string) $displayName), ['page' => 'device', 'device' => $device->device_id, 'tab' => 'apps', 'app' => 'btrfs', 'fs' => $fs]) . '</td>';
        echo '<td>' . $btrfs_status_badge($ioState) . '</td>';
        echo '<td>' . $btrfs_status_badge($scrubState) . '</td>';
        echo '<td>' . $btrfs_status_badge($balanceState) . '</td>';
        echo '<td>' . htmlspecialchars($scrubProgressText) . '</td>';
        echo '<td>' . htmlspecialchars(number_format($totalErrors)) . '</td>';
        echo '<td>' . htmlspecialchars($usedPercentText) . '</td>';
        echo '<td>' . htmlspecialchars($btrfs_format_metric($fsData['used'] ?? null, 'used')) . '</td>';
        echo '<td>' . htmlspecialchars($btrfs_format_metric($fsData['free_estimated'] ?? null, 'free_estimated')) . '</td>';
        echo '<td>' . htmlspecialchars($btrfs_format_metric($fsData['device_size'] ?? null, 'device_size')) . '</td>';
        echo '<td>' . (($fsData['has_missing'] ?? false) ? '<span class="label label-danger">Yes</span>' : '<span class="label label-default">No</span>') . '</td>';
        echo '<td>' . number_format(count($fsDevices)) . '</td>';
        echo '<td>' . generate_link(Url::lazyGraphTag($opsGraph), $fsDetailLink) . '</td>';
        echo '<td>' . generate_link(Url::lazyGraphTag($bpsGraph), $fsDetailLink) . '</td>';
        echo '<td>' . Url::overlibLink($graphLink, $graphImg, $displayName . ' - Combined Status') . '</td>';
        echo '</tr>';
    }
}

echo '</tbody>';
echo '</table>';
echo '</div>';
echo '</div>';
echo '</div>';

$graphTypes = [
    'btrfs_fs_errors_by_type' => 'Aggregate Errors by Type',
    'btrfs_fs_errors_by_device' => 'Aggregate Errors by Device',
    'btrfs_fs_space' => 'Filesystem Space',
    'btrfs_fs_scrub_bytes' => 'Scrub Rate',
    'btrfs_fs_data_types' => 'Per Data Type',
    'btrfs_fs_diskio_ops' => 'Aggregate Ops/sec',
    'btrfs_fs_diskio_bits' => 'Aggregate Bps',
];

// Device-grouped filesystem panels with quick mini-graphs.
foreach ($apps as $app) {
    $device = $app->device;
    if (! $device) {
        continue;
    }

    $filesystems = $app->data['filesystems'] ?? [];
    $filesystemMeta = $app->data['filesystem_meta'] ?? [];
    $filesystemTables = $app->data['filesystem_tables'] ?? [];
    $scrubIsRunningFs = $app->data['scrub_is_running_fs'] ?? [];
    $balanceIsRunningFs = $app->data['balance_is_running_fs'] ?? [];
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">' . Url::deviceLink($device) . '</h3></div>';
    echo '<div class="panel-body">';

    foreach ($filesystems as $fs) {
        // Render filesystem header + status + four mini-graphs.
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
        $usedText = $btrfs_format_metric($fsData['used'] ?? null, 'used');
        $totalText = $btrfs_format_metric($fsData['device_size'] ?? null, 'device_size');
        $usedPercentText = $btrfs_used_percent_text($fsData['used'] ?? null, $fsData['device_size'] ?? null);

        $overallCode = $btrfs_combine_state_code([
            $fsData['io_status_code'] ?? 2,
            is_bool($scrubIsRunningFs[$fs] ?? null) ? (($scrubIsRunningFs[$fs] ?? false) ? 1 : 0) : ($fsData['scrub_status_code'] ?? 2),
            is_bool($balanceIsRunningFs[$fs] ?? null) ? (($balanceIsRunningFs[$fs] ?? false) ? 1 : 0) : ($fsData['balance_status_code'] ?? 2),
        ]);
        $overallState = $btrfs_status_from_code($overallCode);

        $deviceHostname = strtolower((string) ($device->hostname ?? ''));
        $deviceDisplay = strtolower(trim((string) ($device->displayName() ?? '')));
        $deviceSearchText = trim($deviceHostname . ' ' . $deviceDisplay);
        $displayFsLower = strtolower((string) $displayFs);
        if ($filter_text !== '' && ! str_contains($displayFsLower, $filter_text) && ! str_contains($deviceSearchText, $filter_text)) {
            continue;
        }
        if ($filter_device !== '' && ! str_contains($deviceSearchText, $filter_device)) {
            continue;
        }
        if ($filter_status !== 'all' && $overallState !== $filter_status) {
            continue;
        }

        echo '<div class="panel panel-default" style="margin-bottom: 10px;">';
        echo '<div class="panel-heading"><h3 class="panel-title"><a href="' . $headerLink . '" style="color:#337ab7;">' . htmlspecialchars($displayFs) . '</a><div class="pull-right"><small class="text-muted">' . htmlspecialchars($usedText . '/' . $totalText . ' ' . $usedPercentText) . '</small> ' . $btrfs_status_badge($overallState) . '</div></h3></div>';
        echo '<div class="panel-body"><div class="row">';

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

            echo '<div class="pull-left" style="margin-right: 8px;">';
            echo '<div class="text-muted" style="font-size: 11px; margin-bottom: 4px;">' . htmlspecialchars($graphTitle) . '</div>';
            echo '<a href="' . $headerLink . '">' . Url::lazyGraphTag($graph_array) . '</a>';
            echo '</div>';
        }

        echo '</div></div>';
        echo '</div>';
    }

    echo '</div>';
    echo '</div>';
}
