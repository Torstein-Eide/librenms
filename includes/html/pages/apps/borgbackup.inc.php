<?php

/**
 * BorgBackup Global Apps Page
 *
 * Cross-device backup overview for the global Apps page.
 * Renders a summary table of all borgbackup repositories across devices with
 * filtering by repository name, device hostname, and status.
 *
 * Data source: Poller-persisted app->data for each device's borgbackup application.
 */

use App\Models\Application;
use App\Models\User;
use Auth;
use LibreNMS\Util\Number;
use LibreNMS\Util\Time;
use LibreNMS\Util\Url;

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
// Load all borgbackup applications accessible to the current user.
// =============================================================================

$apps = Application::query()
    ->hasAccess($user)
    ->where('app_type', 'borgbackup')
    ->with('device')
    ->get()
    ->sortBy(fn ($app) => $app->device?->hostname ?? '');

// =============================================================================
// Autocomplete Suggestions
// Build lists of unique repository names and device hostnames for datalist.
// =============================================================================

$repo_suggestions = [];
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

    $repos = $app_entry->data['repos'] ?? [];
    foreach ($repos as $repo_name => $repo_data) {
        $repo_suggestions[$repo_name] = $repo_name;
    }
}

ksort($repo_suggestions);
ksort($device_suggestions);

// =============================================================================
// Filter Processing
// Parse URL filter parameters.
// =============================================================================

$filter_text = strtolower(trim((string) ($vars['filter'] ?? '')));
$filter_device = strtolower(trim((string) ($vars['device'] ?? '')));
$filter_status = strtolower(trim((string) ($vars['status'] ?? 'all')));

$allowed_status_filters = ['all', 'ok', 'locked', 'error'];
if (! in_array($filter_status, $allowed_status_filters, true)) {
    $filter_status = 'all';
}

// =============================================================================
// Filter Form Panel
// =============================================================================

$repo_datalist_options = '';
foreach ($repo_suggestions as $suggestion) {
    $repo_datalist_options .= '<option value="' . htmlspecialchars($suggestion) . '"></option>';
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
$reset_url = Url::generate(['page' => 'apps', 'app' => 'borgbackup']);

echo <<<HTML
<div class="panel panel-default">
<div class="panel-heading"><h3 class="panel-title">Filters</h3></div>
<div class="panel-body">
<form method="get" class="form-inline" action="">
<input type="hidden" name="page" value="apps">
<input type="hidden" name="app" value="borgbackup">
<div class="form-group" style="margin-right: 8px;">
<label for="borgbackup-filter" style="margin-right: 4px;">Repository</label>
<input id="borgbackup-filter" name="filter" type="text" class="form-control input-sm" list="borgbackup-repo-list" value="$filter_value" placeholder="repo name">
</div>
<div class="form-group" style="margin-right: 8px;">
<label for="borgbackup-device-filter" style="margin-right: 4px;">Device</label>
<input id="borgbackup-device-filter" name="device" type="text" class="form-control input-sm" list="borgbackup-device-list" value="$device_value" placeholder="hostname">
</div>
<div class="form-group" style="margin-right: 8px;">
<label for="borgbackup-status-filter" style="margin-right: 4px;">Status</label>
<select id="borgbackup-status-filter" name="status" class="form-control input-sm">
$status_options
</select>
</div>
<button type="submit" class="btn btn-primary btn-sm" style="margin-right: 8px;">Apply</button>
<a href="$reset_url" class="btn btn-default btn-sm">Reset</a>
<datalist id="borgbackup-repo-list">
$repo_datalist_options
</datalist>
<datalist id="borgbackup-device-list">
$device_datalist_options
</datalist>
</form>
</div>
</div>
HTML;

// =============================================================================
// Repositories Overview Panel
// =============================================================================

echo '<div class="panel panel-default">';
echo '<div class="panel-heading"><h3 class="panel-title">Repositories Overview</h3></div>';
echo '<div class="panel-body">';

if ($apps->isEmpty()) {
    echo '<em>No borgbackup applications found.</em>';
    echo '</div></div>';

    return;
}

$table_header = <<<'HTML'
<thead><tr><th>Device</th><th>Repository</th><th>Status</th><th>Deduplicated Size</th><th>Time since Last Backup</th><th>Graph</th></tr></thead>
HTML;

echo <<<HTML
<div class="table-responsive">
<table class="table table-condensed table-striped table-hover">
$table_header
<tbody>
HTML;

$row_count = 0;

foreach ($apps as $app) {
    $device = $app->device;
    if (! $device) {
        continue;
    }

    $repos = $app->data['repos'] ?? [];
    if (empty($repos)) {
        continue;
    }

    foreach ($repos as $repo_name => $repo_data) {
        $device_hostname = strtolower((string) ($device->hostname ?? ''));
        $device_display = strtolower(trim((string) ($device->displayName() ?? '')));
        $device_search_text = trim($device_hostname . ' ' . $device_display);
        $repo_name_lower = strtolower((string) $repo_name);

        // Determine status
        $errored = (int) ($repo_data['errored'] ?? 0);
        $locked = (int) ($repo_data['locked'] ?? 0);

        if ($errored) {
            $status = 'error';
            $status_badge = '<span class="label label-danger">Error</span>';
        } elseif ($locked) {
            $status = 'locked';
            $status_badge = '<span class="label label-warning">Locked</span>';
        } else {
            $status = 'ok';
            $status_badge = '<span class="label label-success">OK</span>';
        }

        // Apply filters
        if ($filter_text !== '' && ! str_contains($repo_name_lower, $filter_text) && ! str_contains($device_search_text, $filter_text)) {
            continue;
        }
        if ($filter_device !== '' && ! str_contains($device_search_text, $filter_device)) {
            continue;
        }
        if ($filter_status !== 'all' && $status !== $filter_status) {
            continue;
        }

        // Format values
        $size = $repo_data['unique_csize'] ?? 0;
        $size_str = Number::formatBase($size, 1000, 2, 0, 'B');

        $time_since = (int) ($repo_data['time_since_last_modified'] ?? 0);
        $time_str = Time::formatInterval($time_since);

        // Build graph
        $graph_array = [
            'height' => 30,
            'width' => 120,
            'to' => \App\Facades\LibrenmsConfig::get('time.now'),
            'from' => \App\Facades\LibrenmsConfig::get('time.day'),
            'id' => $app->app_id,
            'type' => 'application_borgbackup_unique_csize',
            'borgrepo' => $repo_name,
            'legend' => 'no',
        ];

        $graph_link_array = $graph_array;
        $graph_link_array['page'] = 'device';
        $graph_link_array['device'] = $device->device_id;
        $graph_link_array['tab'] = 'apps';
        $graph_link_array['app'] = 'borgbackup';
        unset($graph_link_array['height'], $graph_link_array['width'], $graph_link_array['type'], $graph_link_array['id'], $graph_link_array['legend'], $graph_link_array['from'], $graph_link_array['to']);
        $graph_link = Url::generate($graph_link_array);
        $graph_img = Url::lazyGraphTag($graph_array);

        $device_link = Url::deviceLink($device);
        $repo_link = Url::generate([
            'page' => 'device',
            'device' => $device->device_id,
            'tab' => 'apps',
            'app' => 'borgbackup',
            'borgrepo' => $repo_name,
        ]);

        echo <<<HTML
<tr>
<td>{$device_link}</td>
<td><a href="{$repo_link}">{$repo_name}</a></td>
<td>{$status_badge}</td>
<td>{$size_str}</td>
<td>{$time_str}</td>
<td>{$graph_img}</td>
</tr>
HTML;
        $row_count++;
    }
}

if ($row_count == 0) {
    echo '<tr><td colspan="6"><em>No repositories match the selected filters.</em></td></tr>';
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
// Per-device panels with mini-graphs for each repository.
// =============================================================================

$graph_types = [
    'borgbackup_unique_csize' => 'Deduplicated Size',
    'borgbackup_total_csize' => 'Compressed Size',
    'borgbackup_total_size' => 'Original Size',
    'borgbackup_unique_size' => 'Unique Size',
    'borgbackup_time_since_last_modified' => 'Seconds since last update',
    'borgbackup_errored' => 'Errored',
    'borgbackup_locked' => 'Locked',
    'borgbackup_locked_for' => 'Locked For',
];

foreach ($apps as $app) {
    $device = $app->device;
    if (! $device) {
        continue;
    }

    $repos = $app->data['repos'] ?? [];
    if (empty($repos)) {
        continue;
    }

    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">' . Url::deviceLink($device) . '</h3></div>';
    echo '<div class="panel-body">';

    foreach ($repos as $repo_name => $repo_data) {
        $device_hostname = strtolower((string) ($device->hostname ?? ''));
        $device_display = strtolower(trim((string) ($device->displayName() ?? '')));
        $device_search_text = trim($device_hostname . ' ' . $device_display);
        $repo_name_lower = strtolower((string) $repo_name);

        // Determine status
        $errored = (int) ($repo_data['errored'] ?? 0);
        $locked = (int) ($repo_data['locked'] ?? 0);

        if ($errored) {
            $overall_status = 'error';
        } elseif ($locked) {
            $overall_status = 'locked';
        } else {
            $overall_status = 'ok';
        }

        // Apply filters
        if ($filter_text !== '' && ! str_contains($repo_name_lower, $filter_text) && ! str_contains($device_search_text, $filter_text)) {
            continue;
        }
        if ($filter_device !== '' && ! str_contains($device_search_text, $filter_device)) {
            continue;
        }
        if ($filter_status !== 'all' && $overall_status !== $filter_status) {
            continue;
        }

        $size = $repo_data['unique_csize'] ?? 0;
        $size_str = Number::formatBase($size, 1000, 2, 0, 'B');

        $status_badge = $errored
            ? '<span class="label label-danger">Error</span>'
            : ($locked ? '<span class="label label-warning">Locked</span>' : '<span class="label label-success">OK</span>');

        $header_link = Url::generate([
            'page' => 'device',
            'device' => $device->device_id,
            'tab' => 'apps',
            'app' => 'borgbackup',
            'borgrepo' => $repo_name,
        ]);

        $graphs_html = '';
        foreach ($graph_types as $graph_type => $graph_title) {
            $graph_array = [
                'height' => '80',
                'width' => '180',
                'to' => \App\Facades\LibrenmsConfig::get('time.now'),
                'from' => \App\Facades\LibrenmsConfig::get('time.day'),
                'id' => $app->app_id,
                'type' => 'application_' . $graph_type,
                'borgrepo' => $repo_name,
                'legend' => 'no',
            ];

            $graphs_html .= '<div class="pull-left" style="margin-right: 8px;">';
            $graphs_html .= '<div class="text-muted" style="font-size: 11px; margin-bottom: 4px;">' . htmlspecialchars($graph_title) . '</div>';
            $graphs_html .= '<a href="' . $header_link . '">' . Url::lazyGraphTag($graph_array) . '</a>';
            $graphs_html .= '</div>';
        }

        echo <<<HTML
<div class="panel panel-default" style="margin-bottom: 10px;">
<div class="panel-heading"><h3 class="panel-title"><a href="{$header_link}" style="color:#337ab7;">{$repo_name}</a><div class="pull-right"><small class="text-muted">{$size_str}</small> {$status_badge}</div></h3></div>
<div class="panel-body"><div class="row">
{$graphs_html}
</div></div>
</div>
HTML;
    }

    echo '</div>';
    echo '</div>';
}