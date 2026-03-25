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

$btrfs_print_sticky_first_css();



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
echo '<thead><tr><th>Device</th><th>Filesystem</th><th>Status</th><th>Scrub</th><th>Balance</th><th>Scrub Progress</th><th>IO Errors</th><th>% Used</th><th>Used</th><th>Free (Estimated)</th><th>Device Size</th><th>Data Ratio</th><th>Metadata Ratio</th><th>Devices</th><th>Combined Status</th></tr></thead>';
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
        echo '<td>' . htmlspecialchars($btrfs_format_metric($fsData['data_ratio'] ?? null, 'data_ratio')) . '</td>';
        echo '<td>' . htmlspecialchars($btrfs_format_metric($fsData['metadata_ratio'] ?? null, 'metadata_ratio')) . '</td>';
        echo '<td>' . number_format(count($fsDevices)) . '</td>';
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

        echo '<div class="panel panel-default" style="margin-bottom: 10px;">';
        echo '<div class="panel-heading"><h3 class="panel-title"><a href="' . $headerLink . '" style="color:#337ab7;">' . htmlspecialchars($displayFs) . '</a><div class="pull-right"><small class="text-muted">' . htmlspecialchars($usedText . '/' . $totalText . ' ' . $usedPercentText) . '</small> ' . $btrfs_status_badge($overallState) . '</div></h3></div>';
        echo '<div class="panel-body"><div class="row">';

        foreach ($graphTypes as $graphType => $graphTitle) {
            $graph_array = [
                'height' => '100',
                'width' => '220',
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
