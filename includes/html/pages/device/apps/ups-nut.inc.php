<?php

use LibreNMS\Util\Number;
use LibreNMS\Util\Time;
use LibreNMS\Util\Url;

// DEBUG: Print full app->data
if (isset($_GET['debug_nut'])) {
    echo '<pre style="background:#f5f5f5;border:1px solid #ccc;padding:10px;margin:10px;overflow:auto;">';
    echo '<h3>app->data (full)</h3>';
    echo htmlspecialchars(print_r($app->data, true));
    echo '</pre>';
}

$link_array = [
    'page'   => 'device',
    'device' => $device['device_id'],
    'tab'    => 'apps',
    'app'    => 'ups-nut',
];

$appData = $app->data ?? [];
$version = $appData['version'] ?? 1;

echo "<!-- NUT UI: version=$version -->\n";

// Version check - show warning if v1
if ($version < 2) {
    echo '<div class="alert alert-warning">';
    echo '<strong>Please upgrade NUT agent to version 2</strong> for full features.';
    echo '</div>';
}

$upsList = array_keys($appData['Discovery']['ups_list'] ?? []);
$upsData = $appData['data'] ?? [];

echo '<style>
.nut-panels {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    width: 100%;
}
.nut-panels > div > .panel,
.nut-panels > .panel {
    flex: 1 1 33.333%;
    min-width: 0;
}
.nut-panels > .panel-wide {
    flex: 1 1 100%;
}
.nut-keyval td:first-child { text-align: right; padding-right: 15px; white-space: nowrap; }
</style>';

// Keys are numeric sensor_current values from NutPoller::STATE_MAPPING
$stateMapping = [
    1  => 'Online',
    2  => 'On Battery',
    3  => 'Battery Low',
    4  => 'Battery High',
    5  => 'Replace Battery',
    6  => 'Charging',
    7  => 'Discharging',
    8  => 'Bypass',
    9  => 'Overload',
    10 => 'Trim Voltage',
    11 => 'Boost Voltage',
    12 => 'Alarm',
    13 => 'Forced Shutdown',
];

$beeperMapping = [
    'enabled' => 'Enabled',
    'disabled' => 'Disabled',
    'muted' => 'Muted',
];

function nut_getSensor(int $deviceId, string $sensorIndex): ?App\Models\Sensor
{
    return App\Models\Sensor::where('sensor_index', $sensorIndex)
        ->where('device_id', $deviceId)
        ->first();
}

function nut_getSensorValue(int $deviceId, string $sensorIndex): ?float
{
    return nut_getSensor($deviceId, $sensorIndex)?->sensor_current;
}

function nut_getSensorDescr(int $deviceId, string $sensorIndex): ?string
{
    return nut_getSensor($deviceId, $sensorIndex)?->sensor_descr;
}

function nut_getStateValue(int $deviceId, string $sensorIndex): ?int
{
    return nut_getSensor($deviceId, $sensorIndex)?->sensor_current;
}

function nut_renderNavigation(array $link_array, array $upsList, ?string $selectedUps, ?string $graphType): void
{
    print_optionbar_start();

    $overviewLabel = ! isset($selectedUps)
        ? '<span class="pagemenu-selected">Overview</span>'
        : 'Overview';
    echo generate_link($overviewLabel, $link_array, ['nutups' => null]);

    if (count($upsList) > 0) {
        echo ' | UPS: ';
        foreach ($upsList as $index => $upsName) {
            $label = htmlspecialchars($upsName);
            $isSelected = $selectedUps === $upsName;
            $label = $isSelected ? '<span class="pagemenu-selected">' . $label . '</span>' : $label;

            echo generate_link($label, $link_array, ['nutups' => $upsName]);
            if ($index < (count($upsList) - 1)) {
                echo ', ';
            }
        }
    }

    echo ' | Graph Types: ';
    $graphTypes = [
        'charge' => 'Charge',
        'load' => 'Load',
        'runtime' => 'Runtime',
        'power' => 'Power',
        'voltage' => 'Voltage',
        'frequency' => 'Frequency',
    ];

    foreach ($graphTypes as $key => $label) {
        $labelHtml = $graphType === $key
            ? '<span class="pagemenu-selected">' . $label . '</span>'
            : $label;
        echo '<a href="' . Url::generate($link_array, ['graphtype' => $key, 'nutups' => $selectedUps]) . '">' . $labelHtml . '</a>';
        if ($key !== array_key_last($graphTypes)) {
            echo ' | ';
        }
    }

    print_optionbar_end();
}

function nut_renderOverviewTable(App\Models\Application $app, array $device, array $upsList, array $upsData): void
{
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

    $link_array = ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'ups-nut'];

    echo '<div class="panel panel-default">
<div class="panel-heading"><h3 class="panel-title">UPS Devices</h3></div>
<div class="panel-body">
<div class="table-responsive">
<table class="table table-condensed table-striped table-hover">
<thead>
<tr>
<th>Model</th>
<th>Serial</th>
<th>Status</th>
<th>Load</th>
<th>Power</th>
<th>Charge</th>
<th>Runtime</th>
</tr>
</thead>
<tbody>';

    foreach ($upsList as $upsName) {
        $upsInfo = $upsData[$upsName] ?? [];

        $model = $upsInfo['device']['model'] ?? $upsInfo['ups']['model'] ?? 'Unknown';
        $mfr = $upsInfo['device']['mfr'] ?? $upsInfo['ups']['mfr'] ?? '';
        $modelDisplay = $mfr ? "$mfr $model" : $model;

        $configName = $upsInfo['configname'] ?? '-';
        if ($configName !== '-') {
            $modelDisplay = "$modelDisplay ($configName)";
        }

        $serial = $upsInfo['device']['serial'] ?? $upsInfo['ups']['serial'] ?? '';
        if ($serial === '' || preg_match('/^0+$/', $serial)) {
            $serial = '-';
        }

        $statusValue = nut_getStateValue($device['device_id'], "{$upsName}_ups_status") ?? 1;
        $statusDescr = $stateMapping[$statusValue] ?? 'Unknown';

        $loadValue = nut_getSensorValue($device['device_id'], "{$upsName}_load") ?? '-';
        $powerValue = nut_getSensorValue($device['device_id'], "{$upsName}_realpower") ?? '-';
        $chargeValue = nut_getSensorValue($device['device_id'], "{$upsName}_charge") ?? '-';
        $runtimeValue = nut_getSensorValue($device['device_id'], "{$upsName}_runtime") ?? '-';
        if (is_numeric($runtimeValue)) {
            $runtimeValue = (int) $runtimeValue . ' min';
        }

        $status_class = match (true) {
            in_array($statusValue, [1]) => 'online',
            in_array($statusValue, [2]) => 'on_battery',
            in_array($statusValue, [3, 5]) => 'low_battery',
            in_array($statusValue, [4, 6, 7, 10, 11]) => 'warning',
            default => 'error',
        };

        $status_badge = match ($status_class) {
            'online' => '<span class="label label-success">' . htmlspecialchars($statusDescr) . '</span>',
            'on_battery' => '<span class="label label-warning">' . htmlspecialchars($statusDescr) . '</span>',
            'low_battery' => '<span class="label label-danger">' . htmlspecialchars($statusDescr) . '</span>',
            'warning' => '<span class="label label-warning">' . htmlspecialchars($statusDescr) . '</span>',
            'error' => '<span class="label label-danger">' . htmlspecialchars($statusDescr) . '</span>',
            default => htmlspecialchars($statusDescr),
        };

        $ups_link = Url::generate(array_merge($link_array, ['nutups' => $upsName]));

        echo '<tr>';
        echo '<td><a href="' . $ups_link . '">' . htmlspecialchars($modelDisplay) . '</a></td>';
        echo '<td>' . htmlspecialchars($serial) . '</td>';
        echo '<td>' . $status_badge . '</td>';
        echo '<td>' . $loadValue . '%</td>';
        echo '<td>' . $powerValue . 'W</td>';
        echo '<td>' . $chargeValue . '%</td>';
        echo '<td>' . $runtimeValue . '</td>';
        echo '</tr>';
    }

    echo '</tbody>
</table>
</div>
</div>
</div>';

    // Mini graphs for each UPS
    $graph_types = [
        'load' => 'Load',
        'charge' => 'Charge',
        'runtime' => 'Runtime',
        'power' => 'Power',
        'output_voltage' => 'Output Voltage',
        'battery_voltage' => 'Battery Voltage',
    ];

    foreach ($upsList as $upsName) {
        $upsInfo = $upsData[$upsName] ?? [];
        $model = $upsInfo['device']['model'] ?? $upsInfo['ups']['model'] ?? 'Unknown';
        $mfr = $upsInfo['device']['mfr'] ?? $upsInfo['ups']['mfr'] ?? '';
        $modelDisplay = $mfr ? "$mfr $model" : $model;
        $configName = $upsInfo['configname'] ?? '-';
        if ($configName !== '-') {
            $modelDisplay = "$modelDisplay ($configName)";
        }

        $statusValue = nut_getStateValue($device['device_id'], "{$upsName}_ups_status") ?? 1;
        $statusDescr = $stateMapping[$statusValue] ?? 'Unknown';
        $loadValue = nut_getSensorValue($device['device_id'], "{$upsName}_load") ?? '-';
        $powerValue = nut_getSensorValue($device['device_id'], "{$upsName}_realpower") ?? '-';
        $chargeValue = nut_getSensorValue($device['device_id'], "{$upsName}_charge") ?? '-';
        $runtimeValue = nut_getSensorValue($device['device_id'], "{$upsName}_runtime") ?? '-';
        if (is_numeric($runtimeValue)) {
            $runtimeValue = (int) $runtimeValue . ' min';
        }

        $status_class = match (true) {
            in_array($statusValue, [1]) => 'online',
            in_array($statusValue, [2]) => 'on_battery',
            in_array($statusValue, [3, 5]) => 'low_battery',
            in_array($statusValue, [4, 6, 7, 10, 11]) => 'warning',
            default => 'error',
        };

        $status_badge = match ($status_class) {
            'online' => '<span class="label label-success">' . htmlspecialchars($statusDescr) . '</span>',
            'on_battery' => '<span class="label label-warning">' . htmlspecialchars($statusDescr) . '</span>',
            'low_battery' => '<span class="label label-danger">' . htmlspecialchars($statusDescr) . '</span>',
            'warning' => '<span class="label label-warning">' . htmlspecialchars($statusDescr) . '</span>',
            'error' => '<span class="label label-danger">' . htmlspecialchars($statusDescr) . '</span>',
            default => htmlspecialchars($statusDescr),
        };

        $header_link = Url::generate(array_merge($link_array, ['nutups' => $upsName]));

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

            $sensor = nut_getSensor($device['device_id'], $sensorIndex);
            if (! $sensor) {
                continue;
            }

            $graph_array = [
                'height' => '80',
                'width' => '180',
                'to' => App\Facades\LibrenmsConfig::get('time.now'),
                'from' => App\Facades\LibrenmsConfig::get('time.day'),
                'id' => $sensor->sensor_id,
                'type' => 'sensor_' . $actual_class,
                'legend' => 'no',
            ];

            $graphs_html .= '<div class="pull-left" style="margin-right: 8px;">';
            $graphs_html .= '<div class="text-muted" style="font-size: 11px; margin-bottom: 4px;">' . htmlspecialchars($graph_title) . '</div>';
            $graphs_html .= '<a href="' . $header_link . '">' . Url::lazyGraphTag($graph_array) . '</a>';
            $graphs_html .= '</div>';
        }

        if ($graphs_html !== '') {
            echo <<<HTML
<div class="panel panel-default" style="margin-bottom: 10px;">
<div class="panel-heading"><h3 class="panel-title"><a href="{$header_link}" style="color:#337ab7;">{$modelDisplay}</a><div class="pull-right"><small class="text-muted">Load: {$loadValue}% | Power: {$powerValue}W | Charge: {$chargeValue}%</small> {$status_badge}</div></h3></div>
<div class="panel-body"><div class="row">
{$graphs_html}
</div></div>
</div>
HTML;
        }
    }
}

function nut_renderGraphs(App\Models\Application $app, array $device, string $upsName, ?string $graphType = null): void
{
    $deviceId = $device['device_id'];

    // Map graph types to sensor info: key => [index, class, unit, title]
    $graphTypes = [
        'charge' => ['index' => 'charge', 'class' => 'charge', 'unit' => '%', 'title' => 'Charge'],
        'load' => ['index' => 'load', 'class' => 'load', 'unit' => '%', 'title' => 'Load'],
        'runtime' => ['index' => 'runtime', 'class' => 'runtime', 'unit' => 'min', 'title' => 'Runtime'],
        'power' => ['index' => 'realpower', 'class' => 'power', 'unit' => 'W', 'title' => 'Power'],
        'frequency' => ['index' => 'output_frequency', 'class' => 'frequency', 'unit' => 'Hz', 'title' => 'Frequency'],
    ];

    // Handle voltage separately (show input and output side by side)
    if ($graphType === null || $graphType === 'voltage') {
        $outVoltage = App\Models\Sensor::where('device_id', $deviceId)
            ->where('sensor_index', "{$upsName}_output_voltage")
            ->where('sensor_class', 'voltage')
            ->first();
        $inVoltage = App\Models\Sensor::where('device_id', $deviceId)
            ->where('sensor_index', "{$upsName}_input_voltage")
            ->where('sensor_class', 'voltage')
            ->first();

        foreach ([['sensor' => $outVoltage, 'title' => 'Output Voltage'], ['sensor' => $inVoltage, 'title' => 'Input Voltage']] as $voltageConfig) {
            $sensor = $voltageConfig['sensor'];
            if (! $sensor) {
                continue;
            }
            $title = $voltageConfig['title'];
            $value = $sensor->sensor_current;
            $valueStr = is_numeric($value) ? $value . 'V' : '-';

            echo '<div class="panel panel-default">
<div class="panel-heading">
    <h3 class="panel-title">
        ' . $title . '
        <div class="pull-right"><span class="text-muted">' . $valueStr . '</span></div>
    </h3>
</div>
<div class="panel-body">
<div class="row">';

            $graph_array = [
                'height' => '100',
                'width' => '215',
                'to' => App\Facades\LibrenmsConfig::get('time.now'),
                'id' => $sensor->sensor_id,
                'type' => 'sensor_voltage',
                'legend' => 'no',
            ];

            include 'includes/html/print-graphrow.inc.php';

            echo '</div>
</div>
</div>';
        }
    }

    // If specific graph type requested, limit to just that
    if ($graphType && $graphType !== 'voltage') {
        $graphTypes = array_filter($graphTypes, fn ($key) => $key === $graphType, ARRAY_FILTER_USE_KEY);
    }

    foreach ($graphTypes as $graphKey => $graphInfo) {
        $sensorIndex = "{$upsName}_{$graphInfo['index']}";
        $sensorClass = $graphInfo['class'];
        $unit = $graphInfo['unit'];
        $graphTitle = $graphInfo['title'];

        // Get sensor for this graph
        $sensor = App\Models\Sensor::where('device_id', $deviceId)
            ->where('sensor_index', $sensorIndex)
            ->where('sensor_class', $sensorClass)
            ->first();

        if (! $sensor) {
            continue;
        }

        $value = $sensor->sensor_current;
        $valueStr = is_numeric($value) ? $value . $unit : $value;
        $sensorId = $sensor->sensor_id;

        echo '<div class="panel panel-default">
<div class="panel-heading">
    <h3 class="panel-title">
        ' . $graphTitle . '
        <div class="pull-right"><span class="text-muted">' . $valueStr . '</span></div>
    </h3>
</div>
<div class="panel-body">
<div class="row">';

        // Use sensor graph
        $graph_array = [
            'height' => '100',
            'width' => '215',
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'id' => $sensorId,
            'type' => 'sensor_' . $sensorClass,
            'legend' => 'no',
        ];

        include 'includes/html/print-graphrow.inc.php';

        echo '</div>
</div>
</div>';
    }
}

function nut_renderUpsDetail(App\Models\Application $app, array $device, string $upsName, array $upsInfo): void
{
    // --- Data Collection ---
    $model = $upsInfo['ups']['model'] ?? $upsInfo['device']['model'] ?? 'Unknown';
    $serial = $upsInfo['ups']['serial'] ?? $upsInfo['device']['serial'] ?? '';
    $mfr = $upsInfo['ups']['mfr'] ?? $upsInfo['device']['mfr'] ?? '';
    $configName = $upsInfo['configname'] ?? '-';
    $title = $model . ($configName !== '-' ? " ($configName)" : '');

    $statusValue = nut_getStateValue($device['device_id'], "{$upsName}_ups_status") ?? 1;
    $beeperValue = nut_getStateValue($device['device_id'], "{$upsName}_beeper_status") ?? 2;
    $chargeValue = nut_getSensorValue($device['device_id'], "{$upsName}_charge") ?? 0;
    $runtimeValue = nut_getSensorValue($device['device_id'], "{$upsName}_runtime") ?? 0;
    $loadValue = nut_getSensorValue($device['device_id'], "{$upsName}_load") ?? 0;
    $realpowerValue = nut_getSensorValue($device['device_id'], "{$upsName}_realpower") ?? 0;
    $outVoltageValue = nut_getSensorValue($device['device_id'], "{$upsName}_output_voltage") ?? 0;
    $outFreqValue = nut_getSensorValue($device['device_id'], "{$upsName}_output_frequency") ?? 0;
    $inVoltageValue = nut_getSensorValue($device['device_id'], "{$upsName}_input_voltage") ?? 0;

    $chargeLowValue = $upsInfo['battery']['charge_low'] ?? 0;
    $powerNominalValue = $upsInfo['ups']['power_nominal'] ?? 0;
    $outVoltageNomValue = $upsInfo['output']['voltage_nominal'] ?? 0;
    $inTransferHighValue = $upsInfo['input']['transfer_high'] ?? 0;
    $inTransferLowValue = $upsInfo['input']['transfer_low'] ?? 0;
    $batteryType = $upsInfo['battery']['type'] ?? '';
    $outlets = $upsInfo['outlets'] ?? [];

    $statusMapping = [
        1 => 'On Line', 2 => 'On Battery', 3 => 'Low Battery', 4 => 'High Battery',
        5 => 'Replace Battery', 6 => 'Charging', 7 => 'Discharging', 8 => 'Bypass',
        9 => 'Overload', 10 => 'Trim', 11 => 'Boost', 12 => 'Alarm', 13 => 'Forced Shutdown',
    ];
    $statusDescr = $statusMapping[$statusValue] ?? 'Unknown';

    $beeperMapping = [1 => 'Enabled', 2 => 'Disabled', 3 => 'Muted'];
    $beeperDescr = $beeperMapping[$beeperValue] ?? 'Unknown';

    // --- Build Panels Array ---
    $panels = [];

    // Device Info
    $deviceRows = [];
    if ($mfr) {
        $deviceRows[] = ['MFR', $mfr];
    }
    $deviceRows[] = ['Model', $model];
    if ($serial) {
        $deviceRows[] = ['Serial', $serial];
    }
    $deviceRows[] = ['Status', $statusDescr];
    $panels[] = ['title' => 'Device Information', 'rows' => $deviceRows];

    // Battery (skip if charge/load are 0)
    $batteryRows = [];
    if ($chargeValue !== 0) {
        $batteryRows[] = ['Charge', $chargeValue . '%'];
    }
    if ($chargeLowValue !== 0) {
        $batteryRows[] = ['Charge Low', $chargeLowValue . '%'];
    }
    if ($runtimeValue !== 0) {
        $batteryRows[] = ['Runtime', $runtimeValue . ' min'];
    }
    if ($batteryType) {
        $batteryRows[] = ['Battery Type', $batteryType];
    }
    if (! empty($batteryRows)) {
        $panels[] = ['title' => 'Battery', 'rows' => $batteryRows];
    }

    // Power (skip if load/realpower are 0)
    $powerRows = [];
    if ($loadValue !== 0) {
        $powerRows[] = ['Load', $loadValue . '%'];
    }
    if ($realpowerValue !== 0) {
        $powerRows[] = ['Real Power', $realpowerValue . 'W'];
    }
    if ($powerNominalValue !== 0) {
        $powerRows[] = ['Power Nominal', $powerNominalValue . 'W'];
    }
    if (! empty($powerRows)) {
        $panels[] = ['title' => 'Power', 'rows' => $powerRows];
    }

    // Output (skip if voltage is 0)
    $outputRows = [];
    if ($outVoltageValue !== 0) {
        $outputRows[] = ['Voltage', $outVoltageValue . 'V'];
    }
    if ($outVoltageNomValue !== 0) {
        $outputRows[] = ['Voltage Nominal', $outVoltageNomValue . 'V'];
    }
    if ($outFreqValue !== 0) {
        $outputRows[] = ['Frequency', $outFreqValue . 'Hz'];
    }
    foreach ($outlets as $outlet) {
        $id = $outlet['id'] ?? '';
        $desc = trim(str_replace('PowerShare ', '', $outlet['desc'] ?? 'Outlet ' . $id));
        $switchable = ($outlet['switchable'] ?? 'no') === 'yes';
        $status = $switchable ? ($outlet['status'] ?? 'unknown') : 'on';
        $switchLabel = $switchable ? ' (switchable)' : '';
        $outputRows[] = [$desc, ucfirst($status) . $switchLabel];
    }
    if (! empty($outputRows)) {
        $panels[] = ['title' => 'Output', 'rows' => $outputRows];
    }

    // Input (skip if transfer values are 0)
    if ($inTransferLowValue !== 0 || $inTransferHighValue !== 0) {
        $panels[] = ['title' => 'Input', 'rows' => [
            ['Transfer', $inTransferLowValue . '-' . $inTransferHighValue . 'V'],
        ]];
    }

    // Beeper
    $panels[] = ['title' => 'Beeper', 'rows' => [
        ['Status', $beeperDescr],
    ]];

    // --- HTML Output ---
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">';
    echo '<span>' . htmlspecialchars($title) . '</span>';
    echo '<div class="pull-right"><span class="label label-success">' . htmlspecialchars($statusDescr) . '</span></div>';
    echo '</h3></div>';
    echo '<div class="panel-body">';
    echo '<div class="nut-panels" style="display: flex; flex-wrap: wrap; gap: 10px;">';

    foreach ($panels as $panel) {
        echo '<div style="flex: 1 1 250px; min-width: 200px;">';
        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><h3 class="panel-title">' . htmlspecialchars($panel['title']) . '</h3></div>';
        echo '<div class="panel-body">';
        echo '<table class="table table-condensed table-striped table-hover nut-keyval"><tbody>';
        foreach ($panel['rows'] as $row) {
            echo '<tr><td>' . htmlspecialchars($row[0]) . ':</td><td>' . htmlspecialchars($row[1]) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div></div></div>';
    }

    echo '</div></div></div>';

    // Graphs
    nut_renderGraphs($app, $device, $upsName);
}

$selectedUps = $vars['nutups'] ?? null;
$graphType = $vars['graphtype'] ?? null;

nut_renderNavigation($link_array, $upsList, $selectedUps, $graphType);

if (isset($selectedUps)) {
    $currentUps = $appData['data'][$selectedUps] ?? [];
    if (! empty($currentUps)) {
        nut_renderUpsDetail($app, $device, $selectedUps, $currentUps);
    }
} else {
    nut_renderOverviewTable($app, $device, $upsList, $appData['data'] ?? []);
}

// Version footer
echo '<div class="text-muted" style="margin-top: 10px; text-align: right;">';
echo 'Agent Version: ' . ($appData['version'] ?? 'unknown');
echo '</div>';
