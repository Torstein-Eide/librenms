<?php

use LibreNMS\Util\Number;
use LibreNMS\Util\Time;
use LibreNMS\Util\Url;

// DEBUG: Print full app->data
if (isset($_GET['debug_borgbackup'])) {
    echo '<pre style="background:#f5f5f5;border:1px solid #ccc;padding:10px;margin:10px;overflow:auto;">';
    echo '<h3>app->data (full)</h3>';
    echo htmlspecialchars(print_r($app->data, true));
    echo '</pre>';
}

$logscale = isset($vars['logscale']) && $vars['logscale'] == 1;
$chunks_logscale = isset($vars['chunks_logscale']) && $vars['chunks_logscale'] == 1;

if (! isset($vars['format'])) {
    $vars['format'] = 'list_overview';
}
[$format, $subformat] = explode('_', basename($vars['format']), 2);

$repos = $app->data['repos'] ?? [];
if (! empty($repos)) {
    ksort($repos);
}

$link_array = [
    'page'   => 'device',
    'device' => $device['device_id'],
    'tab'    => 'apps',
    'app'    => 'borgbackup',
];

echo '<style>
.borg-panels {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    width: 100%;
}
.borg-panels > div > .panel,
.borg-panels > .panel {
    flex: 1 1 33.333%;
    min-width: 0;
}
.borg-panels > .panel-wide {
    flex: 1 1 100%;
}
.borg-keyval td:first-child { text-align: right; padding-right: 15px; white-space: nowrap; }
</style>';

function borgbackup_status_badge(int $errored, int $locked): string
{
    if ($errored === 1) {
        return '<span class="label label-danger">Error</span>';
    } elseif ($locked === 1) {
        return '<span class="label label-muted">Locked</span>';
    }

    return '<span class="label label-muted">OK</span>';
}

function borgbackup_renderNavigation(array $link_array, array $repos, ?string $selectedRepo, ?string $subformat): void
{
    print_optionbar_start();

    $overview_label = ! isset($selectedRepo)
        ? '<span class="pagemenu-selected">Overview</span>'
        : 'Overview';
    echo generate_link($overview_label, $link_array, ['format' => 'list_overview']);

    if (count($repos) > 0) {
        echo ' | Repositories: ';
        $repoNames = array_keys($repos);
        foreach ($repoNames as $index => $repoName) {
            $label = htmlspecialchars($repoName);
            $isSelected = $selectedRepo === $repoName;
            $label = $isSelected ? '<span class="pagemenu-selected">' . $label . '</span>' : $label;

            $status = '';
            if (($repos[$repoName]['errored'] ?? 0) === 1) {
                $status = ' (ERRORED)';
            }

            echo generate_link($label, $link_array, ['borgrepo' => $repoName, 'format' => null]) . $status;
            if ($index < (count($repos) - 1)) {
                echo ', ';
            }
        }
    }

    echo '&nbsp;&nbsp;&nbsp;&nbsp;| Graph Types: ';
    $graphTypes = [
        'unique_csize' => 'Deduplicated Size',
        'total_csize' => 'Compressed Size',
        'total_size' => 'Original Size',
        'total_chunks' => 'Total Chunks',
        'total_unique_chunks' => 'Unique Chunks',
        'unique_size' => 'Unique Chunk Size',
    ];

    foreach ($graphTypes as $graphKey => $graphLabel) {
        $label = $subformat === $graphKey
            ? '<span class="pagemenu-selected">' . $graphLabel . '</span>'
            : $graphLabel;
        echo '<a href="' . \LibreNMS\Util\Url::generate($link_array, ['format' => 'graph_' . $graphKey, 'borgrepo' => null]) . '">' . $label . '</a>';
        if ($graphKey !== array_key_last($graphTypes)) {
            echo ' | ';
        }
    }

    print_optionbar_end();
}

function borgbackup_renderOverviewPage(App\Models\Application $app, array $device, array $repos): void
{
    echo <<<'HTML'
<div class="panel panel-default">
<div class="panel-heading"><h3 class="panel-title">Repositories Overview</h3></div>
<div class="panel-body">
<div class="table-responsive">
<table class="table table-condensed table-striped table-hover">
<thead>
<tr>
<th>Repository</th>
<th>Status</th>
<th>Deduplicated Size</th>
<th>Time since Last Backup</th>
<th>Deduplicated Size Graph</th>
</tr>
</thead>
<tbody>
HTML;

    $link_array = ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'borgbackup'];

    foreach ($repos as $repoName => $repoData) {
        $repo_link = \LibreNMS\Util\Url::generate(array_merge($link_array, ['borgrepo' => $repoName]));
        $badge = borgbackup_status_badge($repoData['errored'] ?? 0, $repoData['locked'] ?? 0);

        $size = $repoData['unique_csize'] ?? 0;
        $size_str = Number::formatBase($size, 1000, 2, 0, 'B');

        $diff = (int) ($repoData['time_since_last_modified'] ?? 0);
        $time_str = Time::formatInterval($diff);

        $graph_array = [
            'height' => 30,
            'width' => 120,
            'to' => \App\Facades\LibrenmsConfig::get('time.now'),
            'from' => \App\Facades\LibrenmsConfig::get('time.day'),
            'id' => $app['app_id'],
            'type' => 'application_borgbackup_unique_csize',
            'borgrepo' => $repoName,
            'legend' => 'no',
        ];

        $link_array_graph = $graph_array;
        $link_array_graph['page'] = 'graphs';
        unset($link_array_graph['height'], $link_array_graph['width']);
        $graph_link = \LibreNMS\Util\Url::generate($link_array_graph);
        $graph_img = \LibreNMS\Util\Url::lazyGraphTag($graph_array);
        $graph_html = \LibreNMS\Util\Url::overlibLink($graph_link, $graph_img, "$repoName - Deduplicated Size");

        echo <<<HTML
<tr>
<td><a href="{$repo_link}">{$repoName}</a></td>
<td>{$badge}</td>
<td>{$size_str}</td>
<td>{$time_str}</td>
<td>{$graph_html}</td>
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

    borgbackup_renderOverviewPageGraphs($app, $device, $repos);
}

function borgbackup_renderOverviewPageGraphs(App\Models\Application $app, array $device, array $repos): void
{
    $link_array = ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'borgbackup'];

    $overview_graph_types = [
        'borgbackup_unique_csize' => 'Deduplicated Size',
        'borgbackup_total_csize' => 'Compressed Size',
        'borgbackup_total_size' => 'Original Size',
        'borgbackup_unique_size' => 'Unique Size',
        'borgbackup_time_since_last_modified' => 'Seconds since last update',
        'borgbackup_errored' => 'Errored',
        'borgbackup_locked' => 'Locked',
        'borgbackup_locked_for' => 'Locked For',
    ];

    foreach ($repos as $repoName => $repoData) {
        $repo_link = \LibreNMS\Util\Url::generate(array_merge($link_array, ['borgrepo' => $repoName]));

        $size = $repoData['unique_csize'] ?? 0;
        $size_str = Number::formatBase($size, 1000, 2, 0, 'B');
        $badge = borgbackup_status_badge($repoData['errored'] ?? 0, $repoData['locked'] ?? 0);

        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><h3 class="panel-title"><a href="' . $repo_link . '" style="color:#337ab7;">' . htmlspecialchars($repoName) . '</a><div class="pull-right"><small class="text-muted">' . $size_str . '</small> ' . $badge . '</div></h3></div>';
        echo '<div class="panel-body"><div class="row">';

        foreach ($overview_graph_types as $graph_type => $graph_title) {
            $graph_array = [
                'height' => '80',
                'width' => '180',
                'to' => \App\Facades\LibrenmsConfig::get('time.now'),
                'from' => \App\Facades\LibrenmsConfig::get('time.day'),
                'id' => $app['app_id'],
                'type' => 'application_' . $graph_type,
                'borgrepo' => $repoName,
                'legend' => 'no',
            ];

            echo '<div class="pull-left" style="margin-right: 8px;">';
            echo '<div class="text-muted" style="font-size: 11px; margin-bottom: 4px;">' . htmlspecialchars($graph_title) . '</div>';
            echo '<a href="' . $repo_link . '">' . \LibreNMS\Util\Url::lazyGraphTag($graph_array) . '</a>';
            echo '</div>';
        }

        echo '</div></div>';
        echo '</div>';
    }
}

function borgbackup_renderRepoView(App\Models\Application $app, array $device, string $repoName, array $repoData): void
{
    $link_array = [
        'page'   => 'device',
        'device' => $device['device_id'],
        'tab'    => 'apps',
        'app'    => 'borgbackup',
    ];

    $repo_link = Url::generate(array_merge($link_array, ['borgrepo' => $repoName]));

    // Size summary panel (col-xs-4)
    $total_size = $repoData['total_size'] ?? 0;
    $total_csize = $repoData['total_csize'] ?? 0;
    $unique_csize = $repoData['unique_csize'] ?? 0;
    $total_unique_chunks = $repoData['total_unique_chunks'] ?? 0;
    $total_chunks = $repoData['total_chunks'] ?? 0;

    // Size and Chunks panels (wrapped in borg-panels)
    echo '<div class="row">';

    // Repository info panel
    $repoFields = [
        'path'          => 'Repo Path',
        'last_modified' => 'Last Modified',
        'locked'        => 'Locked',
        'locked_for'    => 'Locked For',
    ];

    $info_rows = '';
    foreach ($repoFields as $field => $label) {
        if (! isset($repoData[$field])) {
            continue;
        }

        $value = $repoData[$field];

        if (str_contains($field, 'time') || str_contains($field, 'modified') || str_contains($field, 'last')) {
            $ts = is_numeric($value) ? (int) $value : 0;
            $out = $ts > 0 ? date('Y-m-d H:i:s', $ts) : '-';
        } elseif (in_array($field, ['locked'])) {
            $out = $value ? 'Yes' : 'No';
        } else {
            $out = htmlspecialchars((string) $value);
        }

        $info_rows .= "<tr><td>{$label}</td><td>{$out}</td></tr>";
    }

    if (! empty($info_rows)) {
        echo '<div class="col-md-4"><div class="panel panel-default">';
        echo '<div class="panel-heading"><h3 class="panel-title">' . htmlspecialchars($repoName) . '</h3></div>';
        echo '<div class="panel-body">';
        echo '<table class="table table-condensed table-striped table-hover borg-keyval">';
        echo '<tbody>' . $info_rows . '</tbody>';
        echo '</table>';
        echo '</div></div></div>';
    }

    // Size table panel
    echo '<div class="col-md-4"><div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Size</h3></div>';
    echo '<div class="panel-body">';
    echo '<table class="table table-condensed table-striped table-hover">';
    echo '<thead><tr>';
    echo '<th></th><th>Original size</th><th>Compressed size</th><th>Deduplicated size</th>';
    echo '</tr></thead><tbody><tr>';
    echo '<th>All archives:</th>';
    echo '<td>' . Number::formatBase($total_size, 1000, 2, 0, 'B') . '</td>';
    echo '<td>' . Number::formatBase($total_csize, 1000, 2, 0, 'B') . '</td>';
    echo '<td>' . Number::formatBase($unique_csize, 1000, 2, 0, 'B') . '</td>';
    echo '</tr></tbody></table>';
    echo '</div></div></div>';

    // Chunks table panel
    echo '<div class="col-md-4"><div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Chunks</h3></div>';
    echo '<div class="panel-body">';
    echo '<table class="table table-condensed table-striped table-hover">';
    echo '<thead><tr>';
    echo '<th></th><th>Unique chunks</th><th>Total chunks</th>';
    echo '</tr></thead><tbody><tr>';
    echo '<th>Chunk index:</th>';
    echo '<td>' . number_format($total_unique_chunks) . '</td>';
    echo '<td>' . number_format($total_chunks) . '</td>';
    echo '</tr></tbody></table>';
    echo '</div></div></div>';

    echo '</div>'; // end row

    ///////////////////////////////////////////////////
    // Per Repository Graphs
    //////////////////////////////////////////////////
    $graphs = [
        'borgbackup_unique_csize'          => 'Deduplicated Size',
        'borgbackup_total_csize'           => 'Compressed Size',
        'borgbackup_total_size'            => 'Original Size',
        'borgbackup_unique_size'           => 'Unique Size',
        'borgbackup_time_since_last_modified' => 'Seconds since last repo update',
        'borgbackup_errored'               => 'Errored Repos',
        'borgbackup_locked'                => 'Locked',
        'borgbackup_locked_for'            => 'Locked For',
    ];

    foreach ($graphs as $graphKey => $graphTitle) {
        $graph_array = [
            'height' => '100',
            'width'  => '215',
            'to'     => \App\Facades\LibrenmsConfig::get('time.now'),
            'id'     => $app['app_id'],
            'type'   => 'application_' . $graphKey,
            'borgrepo' => $repoName,
        ];

        if ($graphKey == 'borgbackup_unique_csize') {
            $graph_array['logscale'] = $logscale ? '1' : '0';
        } elseif ($graphKey == 'borgbackup_total_chunks') {
            $graph_array['chunks_logscale'] = $chunks_logscale ? '1' : '0';
        }

        $metric_key = str_replace('borgbackup_', '', $graphKey);
        $value = $repoData[$metric_key] ?? 0;

        if (str_contains($metric_key, 'size') || str_contains($metric_key, 'csize')) {
            $value_str = Number::formatBase($value, 1000, 2, 0, 'B');
        } elseif (str_contains($metric_key, 'chunks')) {
            $value_str = number_format($value);
        } elseif (in_array($metric_key, ['errored', 'locked'])) {
            $value_str = $value ? 'Yes' : 'No';
        } elseif ($metric_key == 'time_since_last_modified' || $metric_key == 'locked_for') {
            $value_str = Time::formatInterval($value);
        } else {
            $value_str = $value;
        }

        $title_html = htmlspecialchars($graphTitle);
        echo <<<HTML
<div class="panel panel-default">
<div class="panel-heading">
    <h3 class="panel-title">
        {$title_html}
        <div class="pull-right"><span class="text-muted">{$value_str}</span></div>
    </h3>
</div>
<div class="panel-body"><div class="row">
HTML;

        include 'includes/html/print-graphrow.inc.php';

        echo <<<'HTML'
</div></div>
</div>
HTML;
    }
}

function borgbackup_renderGraphTypeView(App\Models\Application $app, array $device, array $repos, string $subformat): void
{
    $link_array = ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'borgbackup'];

    $graphTypes = [
        'unique_csize' => 'Deduplicated Size',
        'total_csize' => 'Compressed Size',
        'total_size' => 'Original Size',
        'total_chunks' => 'Total Chunks',
        'total_unique_chunks' => 'Unique Chunks',
        'unique_size' => 'Unique Chunk Size',
    ];

    $graphTitle = $graphTypes[$subformat] ?? $subformat;

    foreach ($repos as $repoName => $repoData) {
        $repo_link = \LibreNMS\Util\Url::generate(array_merge($link_array, ['borgrepo' => $repoName]));

        $repo_key = preg_replace('/[^A-Za-z0-9_\-]/', '_', $repoName);
        $rrd_filename = Rrd::name($device['hostname'], ['app', 'borgbackup', $app->app_id, 'repos___' . $repo_key . '___' . $subformat]);
        $has_rrd = Rrd::checkRrdExists($rrd_filename);

        $value = $repoData[$subformat] ?? 0;
        if (str_contains($subformat, 'size') || str_contains($subformat, 'csize')) {
            $value_str = Number::formatBase($value, 1000, 2, 0, 'B');
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
            echo '<div style="text-align: center; padding: 40px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; margin: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">';
            echo '<div style="font-size: 14px; color: #fff; margin-bottom: 8px;">No RRD data available yet</div>';
            echo '<div style="font-size: 18px; color: #ffd700; font-weight: bold;">Be patient, my young Padawan</div>';
            echo '<div style="font-size: 11px; color: rgba(255,255,255,0.7); margin-top: 8px;">The data shall flow once the Force gathers enough measurements</div>';
            echo '</div>';
        }

        echo '</div></div></div>';
    }
}

$selectedRepo = $vars['borgrepo'] ?? null;
borgbackup_renderNavigation($link_array, $repos, $selectedRepo, $subformat ?? null);

if (isset($selectedRepo)) {
    $currentRepo = $repos[$selectedRepo] ?? [];
    if (! empty($currentRepo)) {
        borgbackup_renderRepoView($app, $device, $selectedRepo, $currentRepo);
    }
} elseif ($format == 'list') {
    borgbackup_renderOverviewPage($app, $device, $repos);
} elseif ($format == 'graph') {
    borgbackup_renderGraphTypeView($app, $device, $repos, $subformat ?? '');
}
