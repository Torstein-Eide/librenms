<?php

/**
 * NUT UPS Global Apps Page
 *
 * Cross-device UPS overview for the global Apps page.
 * Renders a summary table of all NUT UPS devices across devices with
 * filtering by UPS name, device hostname, and status.
 *
 * Data source: Poller-persisted app->data for each device's ups-nut application.
 */

use App\Models\Application;
use App\Models\Sensor;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use LibreNMS\Util\Url;

$user = Auth::user();
if (! $user instanceof User) {
    return;
}

$apps = Application::query()
    ->hasAccess($user)
    ->where('app_type', 'ups-nut')
    ->with('device')
    ->get()
    ->sortBy(fn ($app) => $app->device?->hostname ?? '');

$stateMapping = [
    1 => 'Online',
    2 => 'On Battery',
    3 => 'Low Battery',
    4 => 'High Battery',
    5 => 'Replace Battery',
    6 => 'Charging',
    7 => 'Discharging',
    8 => 'Bypass',
    9 => 'Overload',
    10 => 'Trim',
    11 => 'Boost',
    12 => 'Alarm',
    13 => 'Forced Shutdown',
];

$allowed_status_filters = ['all', 'online', 'on_battery', 'low_battery', 'warning', 'error'];
$status_filter_map = [
    'all' => [],
    'online' => [1],
    'on_battery' => [2],
    'low_battery' => [3, 5],
    'warning' => [4, 6, 7, 10, 11],
    'error' => [8, 9, 12, 13],
];

$ups_suggestions = [];
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

    $upsList = $app_entry->data['data'] ?? [];
    foreach ($upsList as $upsName => $upsData) {
        $ups_suggestions[$upsName] = $upsName;
    }
}

ksort($ups_suggestions);
ksort($device_suggestions);

$filter_text = strtolower(trim((string) ($vars['filter'] ?? '')));
$filter_device = strtolower(trim((string) ($vars['device'] ?? '')));
$filter_status = strtolower(trim((string) ($vars['status'] ?? 'all')));

if (! in_array($filter_status, $allowed_status_filters, true)) {
    $filter_status = 'all';
}

$ups_datalist_options = '';
foreach ($ups_suggestions as $suggestion) {
    $ups_datalist_options .= '<option value="' . htmlspecialchars($suggestion) . '"></option>';
}

$device_datalist_options = '';
foreach ($device_suggestions as $suggestion) {
    $device_datalist_options .= '<option value="' . htmlspecialchars($suggestion) . '"></option>';
}

$status_options = '';
$status_labels = [
    'all' => 'All',
    'online' => 'Online',
    'on_battery' => 'On Battery',
    'low_battery' => 'Low Battery',
    'warning' => 'Warning',
    'error' => 'Error',
];
foreach ($allowed_status_filters as $status_option) {
    $selected = $filter_status === $status_option ? ' selected' : '';
    $status_options .= '<option value="' . htmlspecialchars($status_option) . '"' . $selected . '>' . htmlspecialchars($status_labels[$status_option] ?? ucfirst($status_option)) . '</option>';
}

$filter_value = htmlspecialchars((string) ($vars['filter'] ?? ''));
$device_value = htmlspecialchars((string) ($vars['device'] ?? ''));
$reset_url = Url::generate(['page' => 'apps', 'app' => 'ups-nut']);

echo <<<HTML
<div class="panel panel-default">
<div class="panel-heading"><h3 class="panel-title">Filters</h3></div>
<div class="panel-body">
<form method="get" class="form-inline" action="">
<input type="hidden" name="page" value="apps">
<input type="hidden" name="app" value="ups-nut">
<div class="form-group" style="margin-right: 8px;">
<label for="upsnut-filter" style="margin-right: 4px;">UPS Name</label>
<input id="upsnut-filter" name="filter" type="text" class="form-control input-sm" list="upsnut-ups-list" value="$filter_value" placeholder="ups name">
</div>
<div class="form-group" style="margin-right: 8px;">
<label for="upsnut-device-filter" style="margin-right: 4px;">Device</label>
<input id="upsnut-device-filter" name="device" type="text" class="form-control input-sm" list="upsnut-device-list" value="$device_value" placeholder="hostname">
</div>
<div class="form-group" style="margin-right: 8px;">
<label for="upsnut-status-filter" style="margin-right: 4px;">Status</label>
<select id="upsnut-status-filter" name="status" class="form-control input-sm">
$status_options
</select>
</div>
<button type="submit" class="btn btn-primary btn-sm" style="margin-right: 8px;">Apply</button>
<a href="$reset_url" class="btn btn-default btn-sm">Reset</a>
<datalist id="upsnut-ups-list">
$ups_datalist_options
</datalist>
<datalist id="upsnut-device-list">
$device_datalist_options
</datalist>
</form>
</div>
</div>
HTML;

echo '<div class="panel panel-default">';
echo '<div class="panel-heading"><h3 class="panel-title">UPS Devices</h3></div>';
echo '<div class="panel-body">';

if ($apps->isEmpty()) {
    echo '<em>No ups-nut applications found.</em>';
    echo '</div></div>';

    return;
}

$table_header = <<<'HTML'
<thead><tr><th>Device</th><th>Model</th><th>Serial</th><th>Status</th><th>Load</th><th>Power</th><th>Charge</th><th>Runtime</th><th>Graph</th></tr></thead>
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

    $device_hostname = strtolower((string) ($device->hostname ?? ''));
    $device_display = strtolower(trim((string) ($device->displayName() ?? '')));
    $device_search_text = trim($device_hostname . ' ' . $device_display);

    $appData = $app->data ?? [];
    $upsList = $appData['data'] ?? [];

    if (empty($upsList)) {
        continue;
    }

    foreach ($upsList as $upsName => $upsData) {
        $ups_name_lower = strtolower((string) $upsName);

        $model = $upsData['device']['model'] ?? $upsData['ups']['model'] ?? 'Unknown';
        $mfr = $upsData['device']['mfr'] ?? $upsData['ups']['mfr'] ?? '';
        $deviceName = $mfr ? "$mfr $model" : $model;

        $configName = $upsData['configname'] ?? '-';

        $serial = $upsData['device']['serial'] ?? $upsData['ups']['serial'] ?? '';
        if ($serial === '' || preg_match('/^0+$/', $serial)) {
            $serial = '-';
        }

        $statusValue = (int) ($upsData['ups']['status'] ?? 1);
        $statusDescr = $stateMapping[$statusValue] ?? 'Unknown';

        $loadValue = $upsData['ups']['load'] ?? '-';
        $powerValue = $upsData['ups']['realpower'] ?? '-';
        $chargeValue = $upsData['battery']['charge'] ?? '-';
        $runtimeValue = $upsData['battery']['runtime'] ?? '-';
        if (is_numeric($runtimeValue)) {
            $runtimeValue = (int) ($runtimeValue / 60) . ' min';
        }

        $status_class = match (true) {
            in_array($statusValue, [1]) => 'online',
            in_array($statusValue, [2]) => 'on_battery',
            in_array($statusValue, [3, 5]) => 'low_battery',
            in_array($statusValue, [4, 6, 7, 10, 11]) => 'warning',
            default => 'error',
        };

        if ($filter_text !== '' && ! str_contains($ups_name_lower, $filter_text) && ! str_contains($device_search_text, $filter_text)) {
            continue;
        }
        if ($filter_device !== '' && ! str_contains($device_search_text, $filter_device)) {
            continue;
        }
        if ($filter_status !== 'all') {
            $allowed_statuses = $status_filter_map[$filter_status] ?? [];
            if (! in_array($statusValue, $allowed_statuses)) {
                continue;
            }
        }

        $device_link = Url::deviceLink($device);
        $ups_link = Url::generate([
            'page' => 'device',
            'device' => $device->device_id,
            'tab' => 'apps',
            'app' => 'ups-nut',
            'nutups' => $upsName,
        ]);

        $graph_array = [
            'height' => 30,
            'width' => 120,
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'from' => App\Facades\LibrenmsConfig::get('time.day'),
            'id' => $app->app_id,
            'type' => 'application_nut_load',
            'legend' => 'no',
        ];
        $graph_img = Url::lazyGraphTag($graph_array);

        $status_badge = match ($status_class) {
            'online' => '<span class="label label-success">' . htmlspecialchars($statusDescr) . '</span>',
            'on_battery' => '<span class="label label-warning">' . htmlspecialchars($statusDescr) . '</span>',
            'low_battery' => '<span class="label label-danger">' . htmlspecialchars($statusDescr) . '</span>',
            'warning' => '<span class="label label-warning">' . htmlspecialchars($statusDescr) . '</span>',
            'error' => '<span class="label label-danger">' . htmlspecialchars($statusDescr) . '</span>',
            default => htmlspecialchars($statusDescr),
        };

        $modelDisplay = $configName !== '-' ? "$deviceName ($configName)" : $deviceName;

        echo <<<HTML
<tr>
<td>{$device_link}</td>
<td><a href="{$ups_link}">{$modelDisplay}</a></td>
<td>{$serial}</td>
<td>{$status_badge}</td>
<td>{$loadValue}%</td>
<td>{$powerValue}W</td>
<td>{$chargeValue}%</td>
<td>{$runtimeValue}</td>
<td>{$graph_img}</td>
</tr>
HTML;
        $row_count++;
    }
}

if ($row_count == 0) {
    echo '<tr><td colspan="9"><em>No UPS devices match the selected filters.</em></td></tr>';
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
// Per-device panels with mini-graphs for each UPS.
// =============================================================================

$graph_types = [
    'load' => 'Load',
    'charge' => 'Charge',
    'runtime' => 'Runtime',
    'power' => 'Power',
    'output_voltage' => 'Output Voltage',
    'battery_voltage' => 'Battery Voltage',
];

foreach ($apps as $app) {
    $device = $app->device;
    if (! $device) {
        continue;
    }

    $device_hostname = strtolower((string) ($device->hostname ?? ''));
    $device_display = strtolower(trim((string) ($device->displayName() ?? '')));
    $device_search_text = trim($device_hostname . ' ' . $device_display);

    $appData = $app->data ?? [];
    $upsList = $appData['data'] ?? [];

    if (empty($upsList)) {
        continue;
    }

    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">' . Url::deviceLink($device) . '</h3></div>';
    echo '<div class="panel-body">';

    foreach ($upsList as $upsName => $upsData) {
        $ups_name_lower = strtolower((string) $upsName);

        $model = $upsData['device']['model'] ?? $upsData['ups']['model'] ?? 'Unknown';
        $mfr = $upsData['device']['mfr'] ?? $upsData['ups']['mfr'] ?? '';
        $deviceName = $mfr ? "$mfr $model" : $model;
        $configName = $upsData['configname'] ?? '-';

        $statusValue = (int) ($upsData['ups']['status'] ?? 1);
        $statusDescr = $stateMapping[$statusValue] ?? 'Unknown';

        $loadValue = $upsData['ups']['load'] ?? '-';
        $powerValue = $upsData['ups']['realpower'] ?? '-';
        $chargeValue = $upsData['battery']['charge'] ?? '-';
        $runtimeValue = $upsData['battery']['runtime'] ?? '-';
        if (is_numeric($runtimeValue)) {
            $runtimeValue = (int) ($runtimeValue / 60) . ' min';
        }

        $status_class = match (true) {
            in_array($statusValue, [1]) => 'online',
            in_array($statusValue, [2]) => 'on_battery',
            in_array($statusValue, [3, 5]) => 'low_battery',
            in_array($statusValue, [4, 6, 7, 10, 11]) => 'warning',
            default => 'error',
        };

        if ($filter_text !== '' && ! str_contains($ups_name_lower, $filter_text) && ! str_contains($device_search_text, $filter_text)) {
            continue;
        }
        if ($filter_device !== '' && ! str_contains($device_search_text, $filter_device)) {
            continue;
        }
        if ($filter_status !== 'all') {
            $allowed_statuses = $status_filter_map[$filter_status] ?? [];
            if (! in_array($statusValue, $allowed_statuses)) {
                continue;
            }
        }

        $status_badge = match ($status_class) {
            'online' => '<span class="label label-success">' . htmlspecialchars($statusDescr) . '</span>',
            'on_battery' => '<span class="label label-warning">' . htmlspecialchars($statusDescr) . '</span>',
            'low_battery' => '<span class="label label-danger">' . htmlspecialchars($statusDescr) . '</span>',
            'warning' => '<span class="label label-warning">' . htmlspecialchars($statusDescr) . '</span>',
            'error' => '<span class="label label-danger">' . htmlspecialchars($statusDescr) . '</span>',
            default => htmlspecialchars($statusDescr),
        };

        $header_link = Url::generate([
            'page' => 'device',
            'device' => $device->device_id,
            'tab' => 'apps',
            'app' => 'ups-nut',
            'nutups' => $upsName,
        ]);

        $graphs_html = '';
        foreach ($graph_types as $sensor_class => $graph_title) {
            $sensorIndex = match ($sensor_class) {
                'load' => "{$upsName}_load",
                'charge' => "{$upsName}_charge",
                'runtime' => "{$upsName}_runtime",
                'power' => "{$upsName}_realpower",
                'output_voltage' => "{$upsName}_output_voltage",
                'battery_voltage' => "{$upsName}_battery_voltage",
                default => null,
            };

            $actual_class = $sensor_class === 'output_voltage' || $sensor_class === 'battery_voltage' ? 'voltage' : $sensor_class;

            if ($sensorIndex === null) {
                continue;
            }

            $sensor = Sensor::where('device_id', $device->device_id)
                ->where('sensor_index', $sensorIndex)
                ->where('sensor_class', $actual_class)
                ->first();

            if (! $sensor) {
                continue;
            }

            $graph_array = [
                'height' => '80',
                'width' => '180',
                'to' => App\Facades\LibrenmsConfig::get('time.now'),
                'from' => App\Facades\LibrenmsConfig::get('time.day'),
                'id' => $sensor->sensor_id,
                'type' => 'sensor_' . $sensor_class,
                'legend' => 'no',
            ];

            $graphs_html .= '<div class="pull-left" style="margin-right: 8px;">';
            $graphs_html .= '<div class="text-muted" style="font-size: 11px; margin-bottom: 4px;">' . htmlspecialchars($graph_title) . '</div>';
            $graphs_html .= '<a href="' . $header_link . '">' . Url::lazyGraphTag($graph_array) . '</a>';
            $graphs_html .= '</div>';
        }

        echo <<<HTML
<div class="panel panel-default" style="margin-bottom: 10px;">
<div class="panel-heading"><h3 class="panel-title"><a href="{$header_link}" style="color:#337ab7;">{$configName}</a><div class="pull-right"><small class="text-muted">Load: {$loadValue}% | Power: {$powerValue}W | Charge: {$chargeValue}%</small> {$status_badge}</div></h3></div>
<div class="panel-body"><div class="row">
{$graphs_html}
</div></div>
</div>
HTML;
    }

    echo '</div>';
    echo '</div>';
}
