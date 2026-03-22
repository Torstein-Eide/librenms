<?php

// Format value for display based on metric type
function format_metric_value($value, $metric): string
{
    if ($value === null) {
        return '';
    }

    if (str_contains((string) $metric, 'size') || str_contains((string) $metric, 'csize')) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $v = (float) $value;
        $i = 0;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return round($v, 2) . ' ' . $units[$i];
    }

    if (str_contains((string) $metric, 'chunks')) {
        return number_format((int) $value);
    }

    return (string) $value;
}

// Format time duration to human readable string
function format_duration(int $seconds): string
{
    if ($seconds < 3600) {
        return floor($seconds / 60) . ' minutes';
    }
    if ($seconds < 86400) {
        return floor($seconds / 3600) . ' hours';
    }

    return floor($seconds / 86400) . ' days';
}

// Generate overview table row for a repository
function borgbackup_overview_table_row($repoName, $repoData, $device, $app): void
{
    // Repo link
    $repo_link = \LibreNMS\Util\Url::generate([
        'page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps',
        'app' => 'borgbackup', 'borgrepo' => $repoName,
    ]);

    // Status badge
    if (($repoData['errored'] ?? 0) === 1) {
        $badge = 'Error';
        $badge_class = 'label-danger';
    } elseif (($repoData['locked'] ?? 0) === 1) {
        $badge = 'Locked';
        $badge_class = 'label-warning';
    } else {
        $badge = 'OK';
        $badge_class = 'label-default';
    }

    // Format size
    $size = $repoData['unique_csize'] ?? 0;
    $size_str = format_metric_value($size, 'unique_csize');

    // Format duration
    $diff = (int) ($repoData['time_since_last_modified'] ?? 0);
    $time_str = format_duration($diff);

    // Output table row
    echo '<tr>';
    echo '<td><a href="' . $repo_link . '">' . htmlspecialchars((string) $repoName) . '</a></td>';
    echo '<td><span class="label ' . $badge_class . '">' . $badge . '</span></td>';
    echo '<td>' . $size_str . '</td>';
    echo '<td>' . $time_str . '</td>';
    echo '<td>';

    // Graph for this repository
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

// Generate per-graph view panel for a repository
function borgbackup_graph_panel($repoName, $repoData, $subformat, $device, $app): void
{
    // Repo link
    $repo_link = \LibreNMS\Util\Url::generate([
        'page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps',
        'app' => 'borgbackup', 'borgrepo' => $repoName,
    ]);

    // Check if RRD exists for this repo and metric
    $repo_key = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $repoName);
    $rrd_filename = Rrd::name($device['hostname'], ['app', 'borgbackup', $app->app_id, 'repos___' . $repo_key . '___' . $subformat]);
    $has_rrd = Rrd::checkRrdExists($rrd_filename);

    // Get and format the value for this graph type
    $value = $repoData[$subformat] ?? 0;
    $value_str = format_metric_value($value, $subformat);

    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">';
    echo '<a href="' . $repo_link . '" style="color: #0088cc;"><strong>' . htmlspecialchars((string) $repoName) . '</strong></a>';
    if ($has_rrd) {
        echo ' - <span class="text-muted">' . $value_str . '</span>';
    }
    echo '</h3></div>';
    echo '<div class="panel-body"><div class="row">';

    if ($has_rrd) {
        // Graph for this repository and graph type
        $graph_array = [
            'height' => '100',
            'width' => '215',
            'to' => \App\Facades\LibrenmsConfig::get('time.now'),
            'id' => $app['app_id'],
            'legend' => 'no',
            'nototal' => 1,
            'type' => 'application_borgbackup_' . $subformat,
            'borgrepo' => $repoName,
        ];

        include 'includes/html/print-graphrow.inc.php';
    } else {
        // No data message
        echo '<div style="text-align: center; padding: 40px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; margin: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">';
        echo '<div style="font-size: 14px; color: #fff; margin-bottom: 8px;">No RRD data available yet</div>';
        echo '<div style="font-size: 18px; color: #ffd700; font-weight: bold;">Be patient, my young Padawan</div>';
        echo '<div style="font-size: 11px; color: rgba(255,255,255,0.7); margin-top: 8px;">The data shall flow once the Force gathers enough measurements</div>';
        echo '</div>';
    }

    echo '</div></div></div>';
}

// ================================================================
// Repository navigation and filtering
print_optionbar_start();

$baseLink = [
    'page' => 'device',
    'device' => $device['device_id'],
    'tab' => 'apps',
    'app' => 'borgbackup',
];

$minscale = isset($vars['minscale']) && $vars['minscale'] == 1;

// Parse format to determine view type
if (! isset($vars['format'])) {
    $vars['format'] = 'list_overview';
}
[$format, $subformat] = explode('_', basename($vars['format']), 2);

$repos = $app->data['repos'] ?? [];
if (! empty($repos)) {
    ksort($repos);
}

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
$overviewLabel = ($format == 'list') ? '<span class="pagemenu-selected">Overview</span>' : 'Overview';
echo '<a href="' . \LibreNMS\Util\Url::generate($baseLink, ['format' => 'list_overview', 'borgrepo' => null]) . '">' . $overviewLabel . '</a>';
echo ' | ';

// Graph types for per-graph views (excluding locked, locked_for, errored, time_since_last_modified)
$graphTypes = [
    'unique_csize' => 'Deduplicated Size',
    'total_csize' => 'Compressed Size',
    'total_size' => 'Original Size',
    'total_chunks' => 'Total Chunks',
    'total_unique_chunks' => 'Unique Chunks',
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

// Y-axis min toggle button
echo ' | ';
$minscaleLabel = $minscale ? '<span class="pagemenu-selected">Y-axis Minimum Scale 0</span>' : 'Y-axis Minimum Scale 0';
$toggleVars = $vars;
$toggleVars['minscale'] = $minscale ? '0' : '1';
echo '<a href="' . \LibreNMS\Util\Url::generate($baseLink, $toggleVars) . '">' . $minscaleLabel . '</a>';

print_optionbar_end();


// Display selected repository details (per-repo view)
// ==============================================================================
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
            'total_chunks' => 'Total Chunks',
            'total_unique_chunks' => 'Unique Chunks',
        ];

        // Add status based on errored repos
        $errored = $app->data['errored'] ?? [];
        $isErrored = isset($errored[$vars['borgrepo']]);

        echo '<table class="table table-condensed"><tr>';
        echo '<td><strong>Status:</strong> ' . ($isErrored ? '<span class="text-danger">Error</span>' : '<span class="text-muted">OK</span>') . '</td>';
        $col = 1;
        if ($col % 2 == 0) {
            echo '</tr><tr>';
        }
        foreach ($repoFields as $field => $label) {
            if (! isset($currentRepo[$field])) {
                continue;
            }

            $value = $currentRepo[$field];

            // format sizes and chunks
            if (str_contains($field, 'size') || str_contains($field, 'csize') || str_contains($field, 'chunks')) {
                $out = format_metric_value($value, $field);
                // format time duration
            } elseif (str_contains($field, 'time') || str_contains($field, 'modified') || str_contains($field, 'locked_for')) {
                $out = format_duration((int) $value);
                // booleans/locked
            } elseif (in_array($field, ['locked'])) {
                $out = ($value) ? 'Yes' : 'No';
            } else {
                $out = htmlspecialchars((string) $value);
            }

            echo "<td><strong>{$label}:</strong> {$out}</td>";
            $col++;
            if ($col % 2 == 0) {
                echo '</tr><tr>';
            }
        }
        if ($col % 2 != 0) {
            echo '<td></td>'; // Fill empty cell if odd number of items
        }
        echo '</tr></table>';

        print_optionbar_end();
    }

    // Graphs for selected repository
    $graphs = [
        'borgbackup_unique_csize' => 'Deduplicated Size',
        'borgbackup_total_csize' => 'Compressed Size',
        'borgbackup_total_size' => 'Original Size',
        'borgbackup_total_chunks' => 'Total Chunks',
        'borgbackup_total_unique_chunks' => 'Unique Chunks',
        'borgbackup_time_since_last_modified' => 'Seconds since last repo update',
        'borgbackup_errored' => 'Errored Repos',
        'borgbackup_locked' => 'Locked',
        'borgbackup_locked_for' => 'Locked For',
    ];

    // Render all graphs
    foreach ($graphs as $graphKey => $graphTitle) {
        $graph_array = [
            'height' => '100',
            'width' => '215',
            'to' => \App\Facades\LibrenmsConfig::get('time.now'),
            'id' => $app['app_id'],
            'legend' => 'no',
            'type' => "application_{$graphKey}",
        ];

        if (isset($vars['borgrepo'])) {
            $graph_array['borgrepo'] = $vars['borgrepo'];
        }

        // Add minscale for size and chunks metrics
        $metric = substr($graphKey, strlen('borgbackup_'));
        if (str_contains($metric, 'size') || str_contains($metric, 'csize') || str_contains($metric, 'chunks')) {
            $graph_array['minscale'] = $minscale ? '1' : '0';
        }

        // Extract metric name from graph key (e.g., borgbackup_unique_csize -> unique_csize)
        $metricValue = $currentRepo[$metric] ?? null;
        $value_str = format_metric_value($metricValue, $metric);

        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><h3 class="panel-title">' . $graphTitle;
        if ($value_str !== '') {
            echo ' - <span class="text-muted">' . $value_str . '</span>';
        }
        echo '</h3></div>';
        echo '<div class="panel-body"><div class="row">';

        include 'includes/html/print-graphrow.inc.php';

        echo '</div></div></div>';
    }

    // Overview tab of all repos
    // ==============================================================================
} elseif ($format == 'list') {
    // Overview table for all repositories
    echo '<table class="table table-condensed table-hover">';
    echo '<thead><tr>';
    echo '<th>Repository</th><th>Status</th><th>Deduplicated size</th><th>Time since Last Backup</th><th>Deduplicated size Graph</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    foreach ($repos as $repoName => $repoData) {
        borgbackup_overview_table_row($repoName, $repoData, $device, $app);
    }
    echo '</tbody></table>';

    // Per Graph view
    // ==============================================================================
} elseif ($format == 'graph') {
    // Per-graph-type view - all repos with one graph type
    $graphTitle = $graphTypes[$subformat] ?? $subformat;

    foreach ($repos as $repoName => $repoData) {
        borgbackup_graph_panel($repoName, $repoData, $subformat, $device, $app);
    }
}
