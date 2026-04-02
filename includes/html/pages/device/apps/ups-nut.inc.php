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

$upsList = $appData['Discovery']['ups_list'] ?? [];
$upsData = $appData['UPS'] ?? [];

echo '<!-- NUT UI: upsList=' . count($upsList) . ' upsData=' . count($upsData) . " -->\n";

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

$stateMapping = [
    'OL' => 'On Line',
    'OB' => 'On Battery',
    'LB' => 'Low Battery',
    'HB' => 'High Battery',
    'RB' => 'Replace Battery',
    'CHRG' => 'Charging',
    'DISCHRG' => 'Discharging',
    'BYPASS' => 'Bypass',
    'OVER' => 'Overload',
    'TRIM' => 'Trim',
    'BOOST' => 'Boost',
    'ALARM' => 'Alarm',
    'FSD' => 'Forced Shutdown',
];

$beeperMapping = [
    'enabled' => 'Enabled',
    'disabled' => 'Disabled',
    'muted' => 'Muted',
];

function nut_getSensorValue(string $sensorIndex): ?float
{
    $sensor = App\Models\Sensor::where('sensor_index', $sensorIndex)
        ->where('device_id', $GLOBALS['device']['device_id'])
        ->first();

    $value = $sensor?->sensor_current;
    //echo "<!-- nut_getSensorValue($sensorIndex) = " . var_export($value, true) . " -->\n";

    return $value;
}

function nut_getSensorDescr(string $sensorIndex): ?string
{
    $sensor = App\Models\Sensor::where('sensor_index', $sensorIndex)
        ->where('device_id', $GLOBALS['device']['device_id'])
        ->first();

    return $sensor?->sensor_descr;
}

function nut_getStateValue(string $sensorIndex): ?int
{
    $sensor = App\Models\Sensor::where('sensor_index', $sensorIndex)
        ->where('device_id', $GLOBALS['device']['device_id'])
        ->first();

    return $sensor?->sensor_current;
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
    echo '<!-- nut_renderOverviewTable: upsList count=' . count($upsList) . " -->\n";

    echo '<div class="panel panel-default">
<div class="panel-heading"><h3 class="panel-title">UPS Devices</h3></div>
<div class="panel-body">
<div class="table-responsive">
<table class="table table-condensed table-striped table-hover">
<thead>
<tr>
<th>ConfigName</th>
<th>Model</th>
<th>Status</th>
<th>Load %</th>
<th>Load W</th>
</tr>
</thead>
<tbody>';

    $link_array = ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'ups-nut'];

    $stateMapping = [
        'OL' => 'On Line',
        'OB' => 'On Battery',
        'LB' => 'Low Battery',
        'HB' => 'High Battery',
        'RB' => 'Replace Battery',
        'CHRG' => 'Charging',
        'DISCHRG' => 'Discharging',
        'BYPASS' => 'Bypass',
        'OVER' => 'Overload',
        'TRIM' => 'Trim',
        'BOOST' => 'Boost',
        'ALARM' => 'Alarm',
        'FSD' => 'Forced Shutdown',
    ];

    foreach ($upsList as $upsName) {
        $upsInfo = $upsData[$upsName] ?? [];
        $model = $upsInfo['model'] ?? 'Unknown';

        // Get sensor values
        $statusValue = nut_getStateValue("{$upsName}_ups_status") ?? 1;
        $loadValue = nut_getSensorValue("{$upsName}_load") ?? 0;
        $realpowerValue = nut_getSensorValue("{$upsName}_realpower") ?? 0;

        // Map status to human-readable using local mapping
        $statusDescr = $stateMapping[$statusValue] ?? 'Unknown';

        $ups_link = Url::generate(array_merge($link_array, ['nutups' => $upsName]));

        echo '<tr>';
        echo '<td><a href="' . $ups_link . '">' . htmlspecialchars($upsName) . '</a></td>';
        echo '<td>' . htmlspecialchars($model) . '</td>';
        echo '<td>' . htmlspecialchars($statusDescr) . '</td>';
        echo '<td>' . $loadValue . '%</td>';
        echo '<td>' . $realpowerValue . 'W</td>';
        echo '</tr>';
    }

    echo '</tbody>
</table>
</div>
</div>
</div>';
}

function nut_renderGraphs(App\Models\Application $app, array $device, string $upsName, ?string $graphType = null): void
{
    $graphTypes = [
        'charge' => 'Charge',
        'load' => 'Load',
        'runtime' => 'Runtime',
        'power' => 'Power',
        'voltage' => 'Voltage',
        'frequency' => 'Frequency',
    ];

    $graphs = $graphType ? [$graphType => $graphTypes[$graphType]] : $graphTypes;

    foreach ($graphs as $graphKey => $graphTitle) {
        $graph_array = [
            'height' => '100',
            'width' => '215',
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'id' => $app['app_id'],
            'type' => 'application_nut_' . $graphKey,
            'nutups' => $upsName,
            'legend' => 'no',
        ];

        // Get current value for display
        $value = nut_getSensorValue("{$upsName}_{$graphKey}") ?? 0;
        $unit = match ($graphKey) {
            'charge', 'load' => '%',
            'runtime' => 's',
            'power' => 'W',
            'voltage' => 'V',
            'frequency' => 'Hz',
            default => '',
        };

        $valueStr = is_numeric($value) ? $value . $unit : $value;

        echo '<div class="panel panel-default">
<div class="panel-heading">
    <h3 class="panel-title">
        ' . $graphTitle . '
        <div class="pull-right"><span class="text-muted">' . $valueStr . '</span></div>
    </h3>
</div>
<div class="panel-body">
<div class="row">';

        include 'includes/html/print-graphrow.inc.php';

        echo '</div>
</div>
</div>';
    }
}

function nut_renderUpsDetail(App\Models\Application $app, array $device, string $upsName, array $upsInfo): void
{
    $model = $upsInfo['model'] ?? 'Unknown';
    $serial = $upsInfo['serial'] ?? '';
    $mfr = $upsInfo['mfr'] ?? '';

    // Get sensor values
    $statusValue = nut_getStateValue("{$upsName}_ups_status") ?? 1;
    $beeperValue = nut_getStateValue("{$upsName}_beeper_status") ?? 2;
    $chargeValue = nut_getSensorValue("{$upsName}_charge") ?? 0;
    $chargeLowValue = nut_getSensorValue("{$upsName}_charge_low") ?? 0;
    $runtimeValue = nut_getSensorValue("{$upsName}_runtime") ?? 0;
    $loadValue = nut_getSensorValue("{$upsName}_load") ?? 0;
    $realpowerValue = nut_getSensorValue("{$upsName}_realpower") ?? 0;
    $powerNominalValue = nut_getSensorValue("{$upsName}_power_nominal") ?? 0;
    $outVoltageValue = nut_getSensorValue("{$upsName}_output_voltage") ?? 0;
    $outVoltageNomValue = nut_getSensorValue("{$upsName}_output_voltage_nominal") ?? 0;
    $outFreqValue = nut_getSensorValue("{$upsName}_output_frequency") ?? 0;
    $inTransferHighValue = nut_getSensorValue("{$upsName}_input_transfer_high") ?? 0;
    $inTransferLowValue = nut_getSensorValue("{$upsName}_input_transfer_low") ?? 0;

    // Get battery type from driver data if available
    $batteryType = $upsInfo['battery_type'] ?? '';

    // Map status to human-readable
    $stateMapping = [
        'OL' => 'On Line',
        'OB' => 'On Battery',
        'LB' => 'Low Battery',
        'HB' => 'High Battery',
        'RB' => 'Replace Battery',
        'CHRG' => 'Charging',
        'DISCHRG' => 'Discharging',
        'BYPASS' => 'Bypass',
        'OVER' => 'Overload',
        'TRIM' => 'Trim',
        'BOOST' => 'Boost',
        'ALARM' => 'Alarm',
        'FSD' => 'Forced Shutdown',
    ];

    $statusDescr = $stateMapping[$statusValue] ?? 'Unknown';

    $beeperDescr = match ($beeperValue) {
        1 => 'Enabled',
        2 => 'Disabled',
        3 => 'Muted',
        default => 'Unknown',
    };

    // Header with status
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">';
    echo htmlspecialchars($upsName);
    echo '<div class="pull-right"><span class="label label-success">' . htmlspecialchars($statusDescr) . '</span></div>';
    echo '</h3></div>';
    echo '<div class="panel-body"><div class="nut-panels">';

    // Device Info Panel (with Status)
    echo '<div class="col-md-4"><div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Device Information</h3></div>';
    echo '<div class="panel-body">';
    echo '<table class="table table-condensed table-striped table-hover nut-keyval">';
    echo '<tbody>';
    if ($mfr) {
        echo '<tr><td>MFR:</td><td>' . htmlspecialchars($mfr) . '</td></tr>';
    }
    echo '<tr><td>Model:</td><td>' . htmlspecialchars($model) . '</td></tr>';
    if ($serial) {
        echo '<tr><td>Serial:</td><td>' . htmlspecialchars($serial) . '</td></tr>';
    }
    echo '<tr><td>Status:</td><td>' . htmlspecialchars($statusDescr) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '</div></div></div>';

    // Battery Panel (with Battery Type)
    echo '<div class="col-md-4"><div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Battery</h3></div>';
    echo '<div class="panel-body">';
    echo '<table class="table table-condensed table-striped table-hover nut-keyval">';
    echo '<tbody>';
    echo '<tr><td>Charge:</td><td>' . $chargeValue . '%</td></tr>';
    echo '<tr><td>Charge Low:</td><td>' . $chargeLowValue . '%</td></tr>';
    echo '<tr><td>Runtime:</td><td>' . $runtimeValue . ' seconds</td></tr>';
    if ($batteryType) {
        echo '<tr><td>Battery Type:</td><td>' . htmlspecialchars($batteryType) . '</td></tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div></div></div>';

    // Power Panel
    echo '<div class="col-md-4"><div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Power</h3></div>';
    echo '<div class="panel-body">';
    echo '<table class="table table-condensed table-striped table-hover nut-keyval">';
    echo '<tbody>';
    echo '<tr><td>Load:</td><td>' . $loadValue . '%</td></tr>';
    echo '<tr><td>Real Power:</td><td>' . $realpowerValue . 'W</td></tr>';
    echo '<tr><td>Power Nominal:</td><td>' . $powerNominalValue . 'W</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '</div></div></div>';

    echo '</div></div></div>';

    // Row 2: Output + Input
    echo '<div class="panel panel-default"><div class="panel-body"><div class="nut-panels">';

    // Output Panel
    echo '<div class="col-md-4"><div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Output</h3></div>';
    echo '<div class="panel-body">';
    echo '<table class="table table-condensed table-striped table-hover nut-keyval">';
    echo '<tbody>';
    echo '<tr><td>Voltage:</td><td>' . $outVoltageValue . 'V</td></tr>';
    echo '<tr><td>Voltage Nominal:</td><td>' . $outVoltageNomValue . 'V</td></tr>';
    echo '<tr><td>Frequency:</td><td>' . $outFreqValue . 'Hz</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '</div></div></div>';

    // Input Panel
    echo '<div class="col-md-4"><div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Input</h3></div>';
    echo '<div class="panel-body">';
    echo '<table class="table table-condensed table-striped table-hover nut-keyval">';
    echo '<tbody>';
    echo '<tr><td>Transfer:</td><td>' . $inTransferLowValue . '-' . $inTransferHighValue . 'V</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '</div></div></div>';

    // Beeper Panel
    echo '<div class="col-md-4"><div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Beeper</h3></div>';
    echo '<div class="panel-body">';
    echo '<table class="table table-condensed table-striped table-hover nut-keyval">';
    echo '<tbody>';
    echo '<tr><td>Status:</td><td>' . htmlspecialchars($beeperDescr) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '</div></div></div>';

    echo '</div></div></div>';

    // Graphs
    nut_renderGraphs($app, $device, $upsName);
}

$selectedUps = $vars['nutups'] ?? null;
$graphType = $vars['graphtype'] ?? null;

nut_renderNavigation($link_array, $upsList, $selectedUps, $graphType);

if (isset($selectedUps)) {
    $currentUps = $upsData[$selectedUps] ?? [];
    if (! empty($currentUps)) {
        nut_renderUpsDetail($app, $device, $selectedUps, $currentUps);
    }
} else {
    nut_renderOverviewTable($app, $device, $upsList, $upsData);
    nut_renderGraphs($app, $device, $upsList[0] ?? 'default', $graphType);
}

// Version footer
echo '<div class="text-muted" style="margin-top: 10px; text-align: right;">';
echo 'Agent Version: ' . ($appData['version'] ?? 'unknown');
echo '</div>';
