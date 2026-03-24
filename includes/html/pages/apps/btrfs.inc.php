<?php

use App\Models\Application;
use App\Models\User;
use LibreNMS\Util\Url;

$user = Auth::user();
if (! $user instanceof User) {
    return;
}

$apps = Application::query()
    ->hasAccess($user)
    ->where('app_type', 'btrfs')
    ->with('device')
    ->get()
    ->sortBy(fn ($app) => $app->device->hostname);

echo '<style>
.btrfs-sticky-first th:first-child,
.btrfs-sticky-first td:first-child {
    position: sticky;
    left: 0;
    z-index: 2;
    background: #fff;
}
.btrfs-sticky-first thead th:first-child {
    z-index: 3;
    background: #f5f5f5;
}
</style>';

$statusBadge = static function (string $state): string {
    $state_lc = strtolower($state);
    if ($state_lc === 'error') {
        $badge = 'Error';
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

$statusFromCode = static function ($value): string {
    $code = is_numeric($value) ? (int) $value : 2;

    return match ($code) {
        0 => 'ok',
        1 => 'running',
        3 => 'error',
        default => 'na',
    };
};

$formatMetric = static function ($value, string $metric): string {
    if ($value === null || $value === '') {
        return 'N/A';
    }

    if (str_contains($metric, 'ratio')) {
        return number_format((float) $value, 2);
    }

    if (str_contains($metric, 'size') || str_contains($metric, 'used') || str_contains($metric, 'free')) {
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
};

echo '<div class="panel panel-default">';
echo '<div class="panel-heading"><h3 class="panel-title">Filesystems Overview</h3></div>';
echo '<div class="panel-body">';

if ($apps->isEmpty()) {
    echo '<em>No btrfs applications found.</em>';
    echo '</div></div>';

    return;
}

echo '<div class="table-responsive">';
echo '<table class="table table-condensed table-striped table-hover btrfs-sticky-first">';
echo '<thead><tr><th>Device</th><th>Filesystem</th><th>IO</th><th>Scrub</th><th>Balance</th><th>Scrub Progress</th><th>Total Errors</th><th>% Used</th><th>Used</th><th>Free (Estimated)</th><th>Device Size</th><th>Data Ratio</th><th>Metadata Ratio</th><th>Devices</th><th>Combined Status</th></tr></thead>';
echo '<tbody>';

foreach ($apps as $app) {
    $device = $app->device;
    if (! $device) {
        continue;
    }

    $filesystems = $app->data['filesystems'] ?? [];
    $filesystemTables = $app->data['filesystem_tables'] ?? [];
    $deviceMap = $app->data['device_map'] ?? [];
    $commandTables = $app->data['command_tables'] ?? [];

    foreach ($filesystems as $fs) {
        $fsData = $filesystemTables[$fs] ?? [];
        $fsDevices = $deviceMap[$fs] ?? [];
        $showRows = $commandTables[$fs]['filesystem_show'] ?? [];

        $label = null;
        foreach ($showRows as $row) {
            if (($row['key'] ?? null) === 'label') {
                $label = $row['value'] ?? null;
                break;
            }
        }
        $displayName = ! empty($label) ? $label . ' (' . $fs . ')' : $fs;

        $ioState = $statusFromCode($fsData['io_status_code'] ?? null);
        $scrubState = $statusFromCode($fsData['scrub_status_code'] ?? null);
        $balanceState = $statusFromCode($fsData['balance_status_code'] ?? null);

        $scrubRows = $commandTables[$fs]['scrub_status'] ?? [];
        $scrubProgress = null;
        foreach ($scrubRows as $scrubRow) {
            if (($scrubRow['key'] ?? null) === 'bytes_scrubbed.progress' && is_numeric($scrubRow['value'] ?? null)) {
                $scrubProgress = (float) $scrubRow['value'];
                break;
            }
        }
        if ($scrubProgress === null) {
            $bytesScrubbed = null;
            $totalToScrub = null;
            foreach ($scrubRows as $scrubRow) {
                if (($scrubRow['key'] ?? null) === 'bytes_scrubbed.bytes') {
                    $bytesScrubbed = $scrubRow['value'] ?? null;
                } elseif (($scrubRow['key'] ?? null) === 'total_to_scrub') {
                    $totalToScrub = $scrubRow['value'] ?? null;
                }
            }
            if (is_numeric($bytesScrubbed) && is_numeric($totalToScrub) && (float) $totalToScrub > 0) {
                $scrubProgress = ((float) $bytesScrubbed / (float) $totalToScrub) * 100;
            }
        }
        $scrubProgressText = $scrubProgress === null
            ? 'N/A'
            : rtrim(rtrim(number_format($scrubProgress, 2, '.', ''), '0'), '.') . '%';

        $deviceTables = $app->data['device_tables'][$fs] ?? [];
        $scrubTables = $app->data['scrub_tables'][$fs] ?? [];
        $totalErrors = 0.0;
        foreach ($deviceTables as $devStats) {
            $totalErrors += (float) ($devStats['corruption_errs'] ?? 0)
                + (float) ($devStats['flush_io_errs'] ?? 0)
                + (float) ($devStats['generation_errs'] ?? 0)
                + (float) ($devStats['read_io_errs'] ?? 0)
                + (float) ($devStats['write_io_errs'] ?? 0);
        }
        foreach ($scrubTables as $scrubStats) {
            $totalErrors += (float) ($scrubStats['read_errors'] ?? 0)
                + (float) ($scrubStats['csum_errors'] ?? 0)
                + (float) ($scrubStats['verify_errors'] ?? 0)
                + (float) ($scrubStats['uncorrectable_errors'] ?? 0)
                + (float) ($scrubStats['unverified_errors'] ?? 0)
                + (float) ($scrubStats['missing'] ?? 0)
                + (float) ($scrubStats['device_missing'] ?? 0);
        }

        $usedValue = (float) ($fsData['used'] ?? 0);
        $sizeValue = (float) ($fsData['device_size'] ?? 0);
        $usedPercentText = $sizeValue > 0
            ? rtrim(rtrim(number_format(($usedValue / $sizeValue) * 100, 2, '.', ''), '0'), '.') . '%'
            : 'N/A';

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
        echo '<td>' . $statusBadge($ioState) . '</td>';
        echo '<td>' . $statusBadge($scrubState) . '</td>';
        echo '<td>' . $statusBadge($balanceState) . '</td>';
        echo '<td>' . htmlspecialchars($scrubProgressText) . '</td>';
        echo '<td>' . htmlspecialchars(number_format($totalErrors)) . '</td>';
        echo '<td>' . htmlspecialchars($usedPercentText) . '</td>';
        echo '<td>' . htmlspecialchars($formatMetric($fsData['used'] ?? null, 'used')) . '</td>';
        echo '<td>' . htmlspecialchars($formatMetric($fsData['free_estimated'] ?? null, 'free_estimated')) . '</td>';
        echo '<td>' . htmlspecialchars($formatMetric($fsData['device_size'] ?? null, 'device_size')) . '</td>';
        echo '<td>' . htmlspecialchars($formatMetric($fsData['data_ratio'] ?? null, 'data_ratio')) . '</td>';
        echo '<td>' . htmlspecialchars($formatMetric($fsData['metadata_ratio'] ?? null, 'metadata_ratio')) . '</td>';
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
    'btrfs_fs_data_types' => 'Per Data Type',
];

foreach ($apps as $app) {
    $device = $app->device;
    if (! $device) {
        continue;
    }

    $filesystems = $app->data['filesystems'] ?? [];
    $filesystemTables = $app->data['filesystem_tables'] ?? [];
    $commandTables = $app->data['command_tables'] ?? [];
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">' . Url::deviceLink($device) . '</h3></div>';
    echo '<div class="panel-body">';

    foreach ($filesystems as $fs) {
        $fsData = $filesystemTables[$fs] ?? [];
        $showRows = $commandTables[$fs]['filesystem_show'] ?? [];
        $label = null;
        foreach ($showRows as $row) {
            if (($row['key'] ?? null) === 'label') {
                $label = $row['value'] ?? null;
                break;
            }
        }

        $displayFs = ! empty($label) ? (string) $label . ' (' . (string) $fs . ')' : (string) $fs;
        $headerLink = Url::generate([
            'page' => 'device',
            'device' => $device->device_id,
            'tab' => 'apps',
            'app' => 'btrfs',
            'fs' => $fs,
        ]);
        $usedValue = (float) ($fsData['used'] ?? 0);
        $sizeValue = (float) ($fsData['device_size'] ?? 0);
        $usedText = $formatMetric($fsData['used'] ?? null, 'used');
        $totalText = $formatMetric($fsData['device_size'] ?? null, 'device_size');
        $usedPercentText = $sizeValue > 0
            ? rtrim(rtrim(number_format(($usedValue / $sizeValue) * 100, 2, '.', ''), '0'), '.') . '%'
            : 'N/A';

        $overallCode = $fsData['io_status_code'] ?? 2;
        if (is_numeric($fsData['scrub_status_code'] ?? null) && (int) $fsData['scrub_status_code'] === 3) {
            $overallCode = 3;
        } elseif (is_numeric($fsData['balance_status_code'] ?? null) && (int) $fsData['balance_status_code'] === 3) {
            $overallCode = 3;
        } elseif ((int) $overallCode !== 3) {
            if ((int) ($fsData['scrub_status_code'] ?? 2) === 1 || (int) ($fsData['balance_status_code'] ?? 2) === 1) {
                $overallCode = 1;
            } elseif ((int) $overallCode === 2 && ((int) ($fsData['scrub_status_code'] ?? 2) === 0 || (int) ($fsData['balance_status_code'] ?? 2) === 0)) {
                $overallCode = 0;
            }
        }
        $overallState = $statusFromCode($overallCode);

        echo '<div class="panel panel-default" style="margin-bottom: 10px;">';
        echo '<div class="panel-heading"><h3 class="panel-title"><a href="' . $headerLink . '" style="color:#337ab7;">' . htmlspecialchars($displayFs) . '</a><div class="pull-right"><small class="text-muted">' . htmlspecialchars($usedText . '/' . $totalText . ' ' . $usedPercentText) . '</small> ' . $statusBadge($overallState) . '</div></h3></div>';
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
