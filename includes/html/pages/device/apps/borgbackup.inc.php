<?php

// Repository navigation and filtering
print_optionbar_start();

$baseLink = [
    'page'   => 'device',
    'device' => $device['device_id'],
    'tab'    => 'apps',
    'app'    => 'borgbackup',
];

$logscale = isset($vars['logscale']) && $vars['logscale'] == 1;
$chunks_logscale = isset($vars['chunks_logscale']) && $vars['chunks_logscale'] == 1;

// Parse format to determine view type
if (! isset($vars['format'])) {
    $vars['format'] = 'list_overview';
}
[$format, $subformat] = explode('_', basename($vars['format']), 2);

$repos = $app->data['repos'] ?? [];
if (! empty($repos)) {
    ksort($repos);
}

// Format helper functions for display
$format_bytes = function ($bytes) {
    if (! is_numeric($bytes)) {
        return htmlspecialchars((string) $bytes);
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return round($bytes, 2) . ' ' . $units[$i];
};

$format_time = function ($ts) {
    if (! is_numeric($ts)) {
        return htmlspecialchars((string) $ts);
    }

    // if timestamp appears to be milliseconds, reduce to seconds
    if ($ts > 1000000000000) {
        $ts = (int) ($ts / 1000);
    }

    return date('Y-m-d H:i:s', (int) $ts);
};

// Generate repository links for navigation
$repoLinks = [];
foreach ($repos as $repoName => $repoData) {
    $label = $repoName;

    if (isset($vars['borgrepo']) && $vars['borgrepo'] === $repoName) {
        $label = "<span class=\"pagemenu-selected\">{$label}</span>";
    }

    $status = '';
    if (($repoData['errored'] ?? 0) === 1) {
        $status = ' (ERRORED)';
    }

    $repoLinks[] = generate_link($label, $baseLink, ['borgrepo' => $repoName, 'format' => null]) . $status;
}

// Menu navigation
echo '<a href="' . \LibreNMS\Util\Url::generate($baseLink, ['format' => 'list_overview', 'borgrepo' => null]) . '">Overview</a>';
echo ' | ';

// Graph types for per-graph views (excluding locked, locked_for, errored, time_since_last_modified)
$graphTypes = [
    'unique_csize' => 'Deduplicated Size',
    'total_csize' => 'Compressed Size',
    'total_size' => 'Original Size',
    'total_chunks' => 'Total Chunks',
    'total_unique_chunks' => 'Unique Chunks',
    'unique_size' => 'Unique Chunk Size',
];

$graphLinks = [];
foreach ($graphTypes as $graphKey => $graphLabel) {
    $label = $graphKey;
    if (isset($subformat) && $subformat === $graphKey) {
        $label = "<span class=\"pagemenu-selected\">{$graphLabel}</span>";
    } else {
        $label = $graphLabel;
    }
    $graphLinks[] = '<a href="' . \LibreNMS\Util\Url::generate($baseLink, ['format' => 'graph_' . $graphKey, 'borgrepo' => null]) . '">' . $label . '</a>';
}
echo 'Graph Types: ' . implode(' | ', $graphLinks);

print_optionbar_end();

// Display selected repository details (per-repo view)
if (isset($vars['borgrepo'])) {
    $currentRepo = $repos[$vars['borgrepo']] ?? [];

    // Repo header - above metadata
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">';
    echo '<strong>' . htmlspecialchars($vars['borgrepo']) . '</strong>';
    echo '</h3></div></div>';

    if (! empty($currentRepo)) {
        // Repository metadata header
        print_optionbar_start();

        $repoFields = [
            'path' => 'Repo Path',
            'last_modified' => 'Last Modified',
            'locked' => 'Locked',
            'locked_for' => 'Locked For',
            'unique_csize' => 'Deduplicated Size',
            'total_csize' => 'Compressed Size',
            'total_size' => 'Original Size',
        ];

        foreach ($repoFields as $field => $label) {
            if (! isset($currentRepo[$field])) {
                continue;
            }

            $value = $currentRepo[$field];

            // format sizes
            if (str_contains($field, 'size') || str_contains($field, 'csize') || str_contains($field, 'chunks')) {
                $out = $format_bytes($value);
            // format timestamps
            } elseif (str_contains($field, 'time') || str_contains($field, 'modified') || str_contains($field, 'last')) {
                $out = $format_time($value);
            // booleans/locked
            } elseif (in_array($field, ['locked'])) {
                $out = ($value) ? 'Yes' : 'No';
            } else {
                $out = htmlspecialchars((string) $value);
            }

            echo "{$label}: {$out}<br>\n";
        }

        print_optionbar_end();
    }

    // Graphs for selected repository
    $graphs = [
        'borgbackup_unique_csize' => 'Deduplicated Size',
        'borgbackup_total_csize' => 'Compressed Size',
        'borgbackup_total_size' => 'Original Size',
        'borgbackup_unique_size' => 'Size',
        'borgbackup_time_since_last_modified' => 'Seconds since last repo update',
        'borgbackup_errored' => 'Errored Repos',
        'borgbackup_locked' => 'Locked',
        'borgbackup_locked_for' => 'Locked For',
    ];

    // Render all graphs
    foreach ($graphs as $graphKey => $graphTitle) {
        $graph_array = [
            'height' => '100',
            'width'  => '215',
            'to'     => \App\Facades\LibrenmsConfig::get('time.now'),
            'id'     => $app['app_id'],
            'legend' => 'no',
            'type'   => "application_{$graphKey}",
        ];

        if (isset($vars['borgrepo'])) {
            $graph_array['borgrepo'] = $vars['borgrepo'];
        }

        if ($graphKey == 'borgbackup_size') {
            $graph_array['logscale'] = $logscale ? '1' : '0';
        } elseif ($graphKey == 'borgbackup_chunks') {
            $graph_array['chunks_logscale'] = $chunks_logscale ? '1' : '0';
        }

        echo <<<HTML
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">{$graphTitle}</h3>
            </div>
            <div class="panel-body">
                <div class="row">
        HTML;

        include 'includes/html/print-graphrow.inc.php';

        echo <<<'HTML'
                </div>
            </div>
        </div>
        HTML;
    }
} elseif ($format == 'list') {
    // Overview table for all repositories
    echo '<table class="table table-condensed table-hover">';
    echo '<thead><tr>';
    echo '<th>Repository</th><th>Status</th><th>Deduplicated size</th><th>Time since Last Backup</th><th>Deduplicated size Graph</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    foreach ($repos as $repoName => $repoData) {
        // Repo link - links to individual repo view
        $repo_link = \LibreNMS\Util\Url::generate([
            'page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps',
            'app' => 'borgbackup', 'borgrepo' => $repoName,
        ]);

        // Status badge - shows Error/Locked/OK based on repo state
        // errored takes priority over locked
        if (($repoData['errored'] ?? 0) === 1) {
            $badge = 'Error';
            $badge_class = 'label-danger';
        } elseif (($repoData['locked'] ?? 0) === 1) {
            $badge = 'Locked';
            $badge_class = 'label-warning';
        } else {
            $badge = 'OK';
            $badge_class = 'label-success';
        }

        // Format bytes - convert unique_csize to human readable format (B, KB, MB, GB, TB, PB)
        $size = $repoData['unique_csize'] ?? 0;
        $i = 0;
        while ($size >= 1024 && $i < 5) { $size /= 1024; $i++; }
        $size_str = round($size, 2) . ' ' . $units[$i];

        // Format duration - convert seconds to minutes/hours/days
        $diff = (int) ($repoData['time_since_last_modified'] ?? 0);
        if ($diff < 3600) {
            $time_str = floor($diff / 60) . ' minutes';
        } elseif ($diff < 86400) {
            $time_str = floor($diff / 3600) . ' hours';
        } else {
            $time_str = floor($diff / 86400) . ' days';
        }

        // Output table row with repository info and graph
        echo '<tr>';
        echo '<td><a href="' . $repo_link . '">' . htmlspecialchars($repoName) . '</a></td>';
        echo '<td><span class="label ' . $badge_class . '">' . $badge . '</span></td>';
        echo '<td>' . $size_str . '</td>';
        echo '<td>' . $time_str . '</td>';
        echo '<td>';

        // Graph for this repository (deduplicated size over time) - 24h only
        $graph_array = [
            'height' => 100,
            'width' => 415,
            'to' => \App\Facades\LibrenmsConfig::get('time.now'),
            'id' => $app['app_id'],
            'type' => 'application_borgbackup_unique_csize',
            'borgrepo' => $repoName,
            'legend' => 'no',
            'from' => \App\Facades\LibrenmsConfig::get('time.week'),
        ];

        $link_array = $graph_array;
        $link_array['page'] = 'graphs';
        unset($link_array['height'], $link_array['width']);
        $link = \LibreNMS\Util\Url::generate($link_array);
        $graph_img = \LibreNMS\Util\Url::lazyGraphTag($graph_array);
        echo \LibreNMS\Util\Url::overlibLink($link, $graph_img, "$repoName - Deduplicated Size");

        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
} elseif ($format == 'graph') {
    // Per-graph-type view - all repos with one graph type
    $graphTitle = $graphTypes[$subformat] ?? $subformat;

    foreach ($repos as $repoName => $repoData) {
        // Repo link - links to individual repo view
        $repo_link = \LibreNMS\Util\Url::generate([
            'page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps',
            'app' => 'borgbackup', 'borgrepo' => $repoName,
        ]);

        // Check if RRD exists for this repo and metric
        $repo_key = preg_replace('/[^A-Za-z0-9_\-]/', '_', $repoName);
        $rrd_filename = Rrd::name($device['hostname'], ['app', 'borgbackup', $app->app_id, 'repos___' . $repo_key . '___' . $subformat]);
        $has_rrd = Rrd::checkRrdExists($rrd_filename);

        // Get the value for this graph type
        $value = $repoData[$subformat] ?? 0;
        if (str_contains($subformat, 'size') || str_contains($subformat, 'csize')) {
            $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
            $i = 0;
            while ($value >= 1024 && $i < count($units) - 1) {
                $value /= 1024;
                $i++;
            }
            $value_str = round($value, 2) . ' ' . $units[$i];
        } elseif (str_contains($subformat, 'chunks')) {
            $value_str = number_format($repoData[$subformat] ?? 0);
        } else {
            $value_str = $value;
        }

        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><h3 class="panel-title">';
        echo '<a href="' . $repo_link . '" style="color: #0088cc;"><strong>' . htmlspecialchars($repoName) . '</strong></a>';
        if ($has_rrd) {
            echo ' - <span class="text-muted">' . $value_str . '</span>';
        }
        echo '</h3></div>';
        echo '<div class="panel-body"><div class="row">';

        if ($has_rrd) {
            // Graph for this repository and graph type (hide Y scale)
            $graph_array = [
                'height' => '100',
                'width'  => '215',
                'to'     => \App\Facades\LibrenmsConfig::get('time.now'),
                'id'     => $app['app_id'],
                'legend' => 'no',
                'nototal' => 1,
                'type'   => 'application_borgbackup_' . $subformat,
                'borgrepo' => $repoName,
            ];

            include 'includes/html/print-graphrow.inc.php';
        } else {
            // Fun "no data" message
            echo '<div style="text-align: center; padding: 40px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; margin: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">';
            echo '<div style="font-size: 14px; color: #fff; margin-bottom: 8px;">No RRD data available yet</div>';
            echo '<div style="font-size: 18px; color: #ffd700; font-weight: bold;">Be patient, my young Padawan</div>';
            echo '<div style="font-size: 11px; color: rgba(255,255,255,0.7); margin-top: 8px;">The data shall flow once the Force gathers enough measurements</div>';
            echo '</div>';
        }

        echo '</div></div></div>';
    }
}
