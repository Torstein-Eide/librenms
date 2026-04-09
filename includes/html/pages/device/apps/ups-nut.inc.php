<?php

use LibreNMS\Util\Number;
use LibreNMS\Util\Time;
use LibreNMS\Util\Url;

require_once 'includes/html/graphs/application/ups-nut-common.inc.php';

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

echo '<div class="alert alert-info">';
echo '<strong>Discovery Debug</strong>';
echo '<pre style="margin-top: 10px; max-height: 280px; overflow: auto;">';
echo htmlspecialchars(print_r($appData['Discovery'] ?? [], true));
echo '</pre>';
echo '</div>';

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
// Keyed by NUT status string from ups.status (e.g. 'OL', 'OB')
$stateMapping = [
    'OL'     => 'Online',
    'OB'     => 'On Battery',
    'LB'     => 'Battery Low',
    'HB'     => 'Battery High',
    'RB'     => 'Replace Battery',
    'CHRG'   => 'Charging',
    'DISCHRG' => 'Discharging',
    'BYPASS' => 'Bypass',
    'OVER'   => 'Overload',
    'TRIM'   => 'Trim',
    'BOOST'  => 'Boost',
    'ALARM'  => 'Alarm',
    'FSD'    => 'Forced Shutdown',
];

$beeperMapping = [
    'enabled' => 'Enabled',
    'disabled' => 'Disabled',
    'muted' => 'Muted',
];

function nut_getSensor(int $deviceId, string $sensorIndex): ?App\Models\Sensor
{
    static $sensorCache = [];
    static $loaded = [];

    if (! isset($loaded[$deviceId])) {
        $sensorCache[$deviceId] = App\Models\Sensor::where('device_id', $deviceId)
            ->where('sensor_oid', 'like', 'app:nut:%')
            ->get()
            ->keyBy('sensor_index');
        $loaded[$deviceId] = true;
    }

    return $sensorCache[$deviceId]->get($sensorIndex);
}

function nut_extractValue(mixed $data): ?float
{
    if ($data === null) {
        return null;
    }

    if (is_numeric($data)) {
        return (float) $data;
    }

    if (is_array($data) && isset($data['value'])) {
        return is_numeric($data['value']) ? (float) $data['value'] : null;
    }

    return null;
}

function nut_getModel(array $appData, string $upsID): string
{
    $upsInfo = $appData[$upsID] ?? [];

    return $upsInfo['device']['model']
        ?? $upsInfo['ups']['model']
        ?? $upsInfo['configname']
        ?? $upsInfo['ups']['mfr']
        ?? $upsID;
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

function nut_sensorBelongsToUps(string $sensorIndex, string $upsName): bool
{
    $index = strtolower($sensorIndex);
    $variants = [
        strtolower($upsName),
        strtolower(str_replace('-', '_', $upsName)),
        strtolower(str_replace('_', '-', $upsName)),
        strtolower(str_replace([' ', '-'], '_', $upsName)),
    ];

    foreach (array_unique($variants) as $variant) {
        if (str_starts_with($index, $variant . '_')) {
            return true;
        }
    }

    return false;
}

function nut_getUpsPowerDisplay(int $deviceId, string $upsName, array $upsInfo): array
{
    $phasePowerValue = nut_sumPhaseSensorValues($deviceId, $upsName, 'output_power')
        ?? nut_sumPhaseSensorValues($deviceId, $upsName, 'input_power');
    if ($phasePowerValue !== null) {
        return ['value' => $phasePowerValue, 'unit' => 'VA'];
    }

    $jsonPhasePowerValue = nut_extractSectionMetric($upsInfo['output'] ?? [], 'power', 'sum')
        ?? nut_extractSectionMetric($upsInfo['input'] ?? [], 'power', 'sum');
    if ($jsonPhasePowerValue !== null) {
        return ['value' => $jsonPhasePowerValue, 'unit' => 'VA'];
    }

    $apparentPowerValue = nut_getSensorValue($deviceId, "{$upsName}_ups_power")
        ?? nut_getSensorValue($deviceId, "{$upsName}_output_power")
        ?? nut_getSensorValue($deviceId, "{$upsName}_input_power")
        ?? nut_extractValue($upsInfo['ups']['power'] ?? null);
    if ($apparentPowerValue !== null) {
        return ['value' => $apparentPowerValue, 'unit' => 'VA'];
    }

    $realPowerValue = nut_getSensorValue($deviceId, "{$upsName}_ups_realpower")
        ?? nut_getSensorValue($deviceId, "{$upsName}_output_realpower")
        ?? nut_getSensorValue($deviceId, "{$upsName}_input_realpower")
        ?? nut_extractValue($upsInfo['ups']['realpower'] ?? null);

    return ['value' => $realPowerValue, 'unit' => 'W'];
}

function nut_selectMiniGraphSensor($upsSensors, string $upsName, string $sensorClass, array $preferredSuffixes = [])
{
    $classSensors = $upsSensors->filter(
        fn ($sensor) => $sensor->sensor_class === $sensorClass
            && nut_sensorBelongsToUps((string) $sensor->sensor_index, $upsName)
    );

    if ($classSensors->isEmpty()) {
        return null;
    }

    $suffixOrder = array_flip($preferredSuffixes);

    return $classSensors
        ->sortBy([
            fn ($sensor) => $sensor->hasThresholds() ? 0 : 1,
            fn ($sensor) => $suffixOrder[preg_replace('/^' . preg_quote(str_replace('-', '_', $upsName) . '_', '/') . '/', '', (string) $sensor->sensor_index)]
                ?? $suffixOrder[preg_replace('/^' . preg_quote($upsName . '_', '/') . '/', '', (string) $sensor->sensor_index)]
                ?? 999,
            'sensor_descr',
        ])
        ->first();
}

function nut_getMiniGraphSensors($upsSensors, string $upsName, string $sensorClass)
{
    return $upsSensors->filter(
        fn ($sensor) => $sensor->sensor_class === $sensorClass
            && nut_sensorBelongsToUps((string) $sensor->sensor_index, $upsName)
    )->values();
}

function nut_getSingleGraphTypes(): array
{
    return [
        'charge' => 'Charge',
        'load' => 'Load',
        'runtime' => 'Runtime',
        'powerfactor' => 'Powerfactor',
    ];
}

function nut_getMultiviewTypes(): array
{
    return [
        'temperature' => 'Temperature',
        'power' => 'Power',
        'current' => 'Current',
        'voltage' => 'Voltage',
    ];
}

function nut_getMultiviewSensorClass(string $view): ?string
{
    return match ($view) {
        'voltage' => 'voltage',
        'power' => 'power',
        'current' => 'current',
        'temperature' => 'temperature',
        default => null,
    };
}

function nut_renderGraphTypeLinks(array $link_array, array $types, ?string $selectedUps, ?string $graphType): void
{
    foreach ($types as $key => $label) {
        $labelHtml = $graphType === $key
            ? '<span class="pagemenu-selected">' . $label . '</span>'
            : $label;
        echo '<a href="' . Url::generate($link_array, ['graphtype' => $key, 'nutups' => $selectedUps]) . '">' . $labelHtml . '</a>';
        if ($key !== array_key_last($types)) {
            echo ' | ';
        }
    }
}

function nut_renderNavigation(array $link_array, array $upsList, array $upsData, ?string $selectedUps, ?string $graphType): void
{
    print_optionbar_start();

    $overviewLabel = ! isset($selectedUps)
        ? '<span class="pagemenu-selected">Overview</span>'
        : 'Overview';
    echo generate_link($overviewLabel, $link_array, ['nutups' => null]);

    if (count($upsList) > 0) {
        echo ' | UPS: ';
        foreach ($upsList as $index => $upsName) {
            $label = nut_getModel($upsData, $upsName);
            $label = htmlspecialchars($label);
            $isSelected = $selectedUps === $upsName;
            $label = $isSelected ? '<span class="pagemenu-selected">' . $label . '</span>' : $label;

            echo generate_link($label, $link_array, ['nutups' => $upsName]);
            if ($index < (count($upsList) - 1)) {
                echo ', ';
            }
        }
    }

    print_optionbar_end();
}

function nut_formatMultiviewHeaderValues($viewSensors, string $view, string $upsName): string
{
    $unitMap = [
        'voltage' => 'V',
        'frequency' => 'Hz',
        'power' => '',
        'current' => 'A',
        'temperature' => 'C',
        'load' => '%',
        'charge' => '%',
    ];

    $defaultUnit = $unitMap[$view] ?? '';
    $groupedValues = [];
    $groupUnits = [];

    foreach ($viewSensors->sortBy('sensor_descr') as $sensor) {
        $current = $sensor->sensor_current;
        if (! is_numeric($current)) {
            continue;
        }

        $value = (float) $current;
        $suffix = preg_replace('/^' . preg_quote($upsName . '_', '/') . '/', '', (string) $sensor->sensor_index);
        if (! is_string($suffix)) {
            $suffix = '';
        }

        $group = match (true) {
            str_starts_with($suffix, 'battery_') => 'Battery',
            str_starts_with($suffix, 'input_') => 'Input',
            str_starts_with($suffix, 'output_') => 'Output',
            str_starts_with($suffix, 'bypass_') => 'Bypass',
            default => 'UPS',
        };

        if ($view === 'power') {
            $isRealPower = str_contains($suffix, '_realpower');
            $group = $group . ($isRealPower ? ' W' : ' VA');
            $groupUnits[$group] = $isRealPower ? 'W' : 'VA';
        } else {
            $groupUnits[$group] = $defaultUnit;
        }

        if (! isset($groupedValues[$group])) {
            $groupedValues[$group] = [];
        }

        $groupedValues[$group][] = $value;
    }

    $segments = [];
    $formatValue = function (float $value, string $unit): string {
        if (in_array($unit, ['W', 'VA', 'V', 'A', 'Hz'], true)) {
            return Number::formatSi($value, 1, 0, $unit);
        }

        return number_format($value, 1) . $unit;
    };

    $orderedGroups = $view === 'power'
        ? ['Battery VA', 'Battery W', 'Input VA', 'Input W', 'Output VA', 'Output W', 'Bypass VA', 'Bypass W', 'UPS VA', 'UPS W']
        : ['Battery', 'Input', 'Output', 'Bypass', 'UPS'];

    foreach ($orderedGroups as $group) {
        $values = $groupedValues[$group] ?? [];
        if (empty($values)) {
            continue;
        }

        $min = min($values);
        $max = max($values);
        $unit = $groupUnits[$group] ?? '';
        $formatted = abs($max - $min) < 0.00001
            ? $formatValue($min, $unit)
            : $formatValue($min, $unit) . '-' . $formatValue($max, $unit);

        $segments[] = htmlspecialchars($group) . ': ' . $formatted;
    }

    if (empty($segments)) {
        return 'No current data';
    }

    return implode(' | ', $segments);
}

function nut_renderMultiviewPanel(array $graphArray, string $title, string $headerValues, string $groupLinks): void
{
    $graph_array = $graphArray;

    echo '<div class="panel panel-default">
<div class="panel-heading">
    <h3 class="panel-title">' . htmlspecialchars($title) . '<div class="pull-right"><span class="text-muted">' . $headerValues . '</span></div></h3>
</div>
<div class="panel-body">
<div class="row">';

    include 'includes/html/print-graphrow.inc.php';

    if ($groupLinks !== '') {
        echo '<div class="col-md-12" style="margin-top: 8px;">';
        echo '<div class="well well-sm" style="margin-bottom: 0; text-align: center;">';
        echo '<strong>Jump to:</strong> ' . $groupLinks;
        echo '</div>';
        echo '</div>';
    }

    echo '</div>
</div>
</div>';
}

function nut_buildSingleViewLinks($viewSensors, string $sensorClass): string
{
    $links = [];

    foreach ($viewSensors->sortBy('sensor_descr') as $sensor) {
        $label = htmlspecialchars((string) $sensor->sensor_descr);
        $graphLink = Url::generate([
            'page' => 'graphs',
            'type' => 'sensor_' . $sensorClass,
            'id' => $sensor->sensor_id,
        ]);
        $links[] = '<a href="' . $graphLink . '">' . $label . '</a>';
    }

    return implode(' | ', $links);
}

// Helper function to render a state sensor with translation-based styling
function nut_renderStateSensor(int $deviceId, string $upsName, array $config): string
{
    $sensorIndex = $upsName . '_' . $config['key'];
    $sensor = nut_getSensor($deviceId, $sensorIndex);

    if (! $sensor) {
        return '<span class="label label-default">-</span>';
    }

    $translation = $sensor->translations->firstWhere('state_value', $sensor->sensor_current);
    $stateDescr = $translation ? $translation->state_descr : 'Unknown';
    $severity = $translation ? $translation->severity() : null;

    $labelClass = match ($severity) {
        LibreNMS\Enum\Severity::Ok => 'label-default',
        LibreNMS\Enum\Severity::Warning => 'label-warning',
        LibreNMS\Enum\Severity::Error => 'label-danger',
        default => 'label-default',
    };

    return '<span class="label ' . $labelClass . '" title="' . htmlspecialchars($stateDescr) . ' (' . $sensor->sensor_current . ')">' . htmlspecialchars($stateDescr) . '</span>';
}

// State sensor configurations grouped by section
function nut_getStateSensorConfig(): array
{
    return [
        'battery' => [
            'nut_battery_health' => ['label' => 'Battery Health', 'key' => 'battery_health'],
            'nut_battery_charging' => ['label' => 'Charging', 'key' => 'battery_charging'],
        ],
        'input' => [
            'nut_output_voltage_regulation' => ['label' => 'Voltage Reg', 'key' => 'output_voltage_regulation'],
            'nut_status_bypass' => ['label' => 'Bypass', 'key' => 'status_bypass'],
        ],
        'output' => [
            'nut_status_overload' => ['label' => 'Overload', 'key' => 'status_overload'],
        ],
        'alarm' => [
            'nut_status_online' => ['label' => 'Online', 'key' => 'status_online'],
            'nut_status_alarm' => ['label' => 'Alarm', 'key' => 'status_alarm'],
            'nut_status_forced_shutdown' => ['label' => 'Forced Shutdown', 'key' => 'status_forced_shutdown'],
        ],
    ];
}

function nut_renderOverviewTable(App\Models\Application $app, array $device, array $upsList, array $upsData): void
{
    $link_array = ['page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'ups-nut'];
    $stateConfig = nut_getStateSensorConfig();
    $allStateSensors = array_merge(...array_values($stateConfig));

    echo '<div class="panel panel-default">
<div class="panel-heading"><h3 class="panel-title">UPS Devices</h3></div>
<div class="panel-body">
<div class="table-responsive">
<table class="table table-condensed table-striped table-hover">
<thead>
<tr>
<th>Model</th>';
    foreach ($stateConfig as $sectionSensors) {
        foreach ($sectionSensors as $config) {
            echo '<th>' . htmlspecialchars($config['label']) . '</th>';
        }
    }
    echo '<th>Load</th>
<th>Power</th>
<th>Charge</th>
<th>Runtime</th>
<th>Battery Temp</th>
<th>UPS Temp</th>
</tr>
</thead>
<tbody>';

    // Helper function to get aggregate severity from all state sensors
    $getAggregateSeverity = function (int $deviceId, string $upsName) use ($allStateSensors): array {
        $worstSeverity = null;
        $problemStates = [];

        foreach ($allStateSensors as $config) {
            $sensorIndex = $upsName . '_' . $config['key'];
            $sensor = nut_getSensor($deviceId, $sensorIndex);
            if ($sensor) {
                $translation = $sensor->translations->firstWhere('state_value', $sensor->sensor_current);
                if ($translation) {
                    $severity = $translation->severity();
                    $stateDescr = $translation->state_descr;

                    // Track worst severity (Error > Warning > Ok)
                    if ($worstSeverity === null || $severity->value > $worstSeverity->value) {
                        $worstSeverity = $severity;
                    }

                    // Track non-ok states
                    if ($severity !== LibreNMS\Enum\Severity::Ok) {
                        $problemStates[] = $stateDescr;
                    }
                }
            }
        }

        return ['severity' => $worstSeverity, 'problems' => $problemStates];
    };

    foreach ($upsList as $upsName) {
        $upsInfo = $upsData[$upsName] ?? [];
        $modelRaw = $upsInfo['device']['model'] ?? $upsInfo['ups']['model'] ?? null;
        $model = is_string($modelRaw) ? $modelRaw : ($upsInfo['configname'] ?? '');
        $mfrRaw = $upsInfo['device']['mfr'] ?? $upsInfo['ups']['mfr'] ?? null;
        $mfr = is_string($mfrRaw) ? $mfrRaw : '';
        $configName = $upsInfo['configname'] ?? null;
        // Don't duplicate manufacturer in model name (case-insensitive contains check)
        $modelDisplay = $model;
        if ($mfr !== '' && $model !== '' && stripos($model, $mfr) === false) {
            $modelDisplay = "$mfr $model";
        }
        if ($configName && $configName !== $model && $configName !== $mfr && $configName !== $modelDisplay) {
            $modelDisplay = "$modelDisplay ($configName)";
        }

        $loadValue = nut_getSensorValue($device['device_id'], "{$upsName}_load");
        $loadValue = $loadValue ?? nut_extractValue($upsInfo['ups']['load'] ?? null) ?? '-';
        $powerInfo = nut_getUpsPowerDisplay($device['device_id'], $upsName, $upsInfo);
        $powerValue = $powerInfo['value'];
        $powerUnit = $powerInfo['unit'];
        $chargeValue = nut_getSensorValue($device['device_id'], "{$upsName}_charge");
        $chargeValue = $chargeValue ?? nut_extractValue($upsInfo['battery']['charge'] ?? null) ?? '-';
        $runtimeValue = nut_getSensorValue($device['device_id'], "{$upsName}_runtime");
        $runtimeValue = $runtimeValue ?? $upsInfo['battery']['runtime'] ?? '-';
        if (is_numeric($runtimeValue)) {
            $runtimeValue = (int) $runtimeValue . ' min';
        }
        $batteryTempValue = nut_getSensorValue($device['device_id'], "{$upsName}_battery_temperature");
        $batteryTempValue = $batteryTempValue ?? $upsInfo['battery']['temperature'] ?? '-';
        $upsTempValue = nut_getSensorValue($device['device_id'], "{$upsName}_ups_temperature");
        $upsTempValue = $upsTempValue ?? $upsInfo['ups']['temperature'] ?? '-';

        $ups_link = Url::generate(array_merge($link_array, ['nutups' => $upsName]));

        echo '<tr>';
        echo '<td><a href="' . $ups_link . '">' . htmlspecialchars($modelDisplay) . '</a></td>';
        foreach ($stateConfig as $sectionSensors) {
            foreach ($sectionSensors as $config) {
                echo '<td>' . nut_renderStateSensor($device['device_id'], $upsName, $config) . '</td>';
            }
        }
        echo '<td>' . (is_numeric($loadValue) ? $loadValue . '%' : $loadValue) . '</td>';
        echo '<td>' . (is_numeric($powerValue) ? Number::formatSi((float) $powerValue, 1, 0, $powerUnit) : '-') . '</td>';
        echo '<td>' . (is_numeric($chargeValue) ? $chargeValue . '%' : $chargeValue) . '</td>';
        echo '<td>' . $runtimeValue . '</td>';
        echo '<td>' . (is_numeric($batteryTempValue) ? $batteryTempValue . '°C' : $batteryTempValue) . '</td>';
        echo '<td>' . (is_numeric($upsTempValue) ? $upsTempValue . '°C' : $upsTempValue) . '</td>';
        echo '</tr>';
    }

    echo '</tbody>
</table>
</div>
</div>
</div>';


    // Mini graphs for each UPS (max one graph per sensor type, prefer thresholded sensors)
    $miniGraphDefinitions = [
        ['title' => 'Load', 'class' => 'load', 'suffixes' => ['load']],
        ['title' => 'Charge', 'class' => 'charge', 'suffixes' => ['charge']],
        ['title' => 'Runtime', 'class' => 'runtime', 'suffixes' => ['runtime']],
        ['title' => 'Power', 'class' => 'power', 'suffixes' => ['ups_power', 'ups_realpower', 'output_power', 'output_realpower', 'input_power', 'input_realpower']],
        ['title' => 'Powerfactor', 'class' => 'power_factor', 'suffixes' => ['output_power_factor', 'input_power_factor', 'ups_power_factor']],
        ['title' => 'Voltage', 'class' => 'voltage', 'suffixes' => ['output_voltage', 'input_voltage', 'battery_voltage']],
        ['title' => 'Current', 'class' => 'current', 'suffixes' => ['output_current', 'input_current', 'bypass_current']],
        ['title' => 'Frequency', 'class' => 'frequency', 'suffixes' => ['output_frequency', 'input_frequency', 'bypass_frequency']],
        ['title' => 'Temperature', 'class' => 'temperature', 'suffixes' => ['ups_temperature', 'battery_temperature', 'ambient_temperature']],
    ];

    // Build lookup from actual sensors in DB
    $dbSensors = App\Models\Sensor::where('device_id', $device['device_id'])
        ->where('sensor_oid', 'like', 'app:nut:%')
        ->get()
        ->keyBy(fn ($s) => $s->sensor_index);
    $upsSensors = $dbSensors->values();

    foreach ($upsList as $upsName) {
        $upsInfo = $upsData[$upsName] ?? [];
        $modelRaw = $upsInfo['device']['model'] ?? $upsInfo['ups']['model'] ?? null;
        $model = is_string($modelRaw) ? $modelRaw : ($upsInfo['configname'] ?? '');
        $mfrRaw = $upsInfo['device']['mfr'] ?? $upsInfo['ups']['mfr'] ?? null;
        $mfr = is_string($mfrRaw) ? $mfrRaw : '';
        $configName = $upsInfo['configname'] ?? null;
        // Don't duplicate manufacturer in model name (case-insensitive contains check)
        $modelDisplay = $model;
        if ($mfr !== '' && $model !== '' && stripos($model, $mfr) === false) {
            $modelDisplay = "$mfr $model";
        }
        if ($configName && $configName !== $model && $configName !== $mfr && $configName !== $modelDisplay) {
            $modelDisplay = "$modelDisplay ($configName)";
        }

        $header_link = Url::generate(array_merge($link_array, ['nutups' => $upsName]));

        $graphs_html = '';
        foreach ($miniGraphDefinitions as $definition) {
            $sensorClass = $definition['class'];
            $classSensors = nut_getMiniGraphSensors($upsSensors, $upsName, $sensorClass);
            if ($classSensors->isEmpty()) {
                continue;
            }

            $multiviewView = match ($sensorClass) {
                'temperature' => 'temperature',
                'power' => 'power',
                'current' => 'current',
                'voltage' => 'voltage',
                'frequency' => 'frequency',
                default => null,
            };

            if ($classSensors->count() > 1 && $multiviewView !== null) {
                $graph_array = [
                    'height' => '80',
                    'width' => '180',
                    'to' => App\Facades\LibrenmsConfig::get('time.now'),
                    'from' => App\Facades\LibrenmsConfig::get('time.day'),
                    'id' => $app->app_id,
                    'type' => 'application_ups-nut_multiview',
                    'legend' => 'no',
                    'view' => $multiviewView,
                    'nutups' => $upsName,
                ];
            } else {
                $sensor = nut_selectMiniGraphSensor($classSensors, $upsName, $sensorClass, $definition['suffixes']);
                if (! $sensor) {
                    continue;
                }

                $graph_array = [
                    'height' => '80',
                    'width' => '180',
                    'to' => App\Facades\LibrenmsConfig::get('time.now'),
                    'from' => App\Facades\LibrenmsConfig::get('time.day'),
                    'id' => $sensor->sensor_id,
                    'type' => 'sensor_' . $sensorClass,
                    'legend' => 'no',
                ];
            }
            $graphLinkParams = [
                'page' => 'graphs',
                'type' => $graph_array['type'],
                'id' => $graph_array['id'],
                'from' => $graph_array['from'],
                'to' => $graph_array['to'],
            ];
            if (isset($graph_array['view'])) {
                $graphLinkParams['view'] = $graph_array['view'];
            }
            if (isset($graph_array['nutups'])) {
                $graphLinkParams['nutups'] = $graph_array['nutups'];
            }
            $graph_link = Url::generate($graphLinkParams);

            $graphs_html .= '<div class="pull-left" style="margin-right: 8px;">';
            $graphs_html .= '<div class="text-muted" style="font-size: 11px; margin-bottom: 4px;">' . htmlspecialchars($definition['title']) . '</div>';
            $graphs_html .= '<a href="' . $graph_link . '">' . Url::lazyGraphTag($graph_array) . '</a>';
            $graphs_html .= '</div>';
        }

        // Keep at most one mini graph per type (do not append aggregate duplicates)

        // Get current values for header
        $loadValue = nut_getSensorValue($device['device_id'], "{$upsName}_load")
            ?? nut_extractValue($upsInfo['ups']['load'] ?? null)
            ?? '-';
        $powerInfo = nut_getUpsPowerDisplay($device['device_id'], $upsName, $upsInfo);
        $powerValue = $powerInfo['value'];
        $powerUnit = $powerInfo['unit'];
        $chargeValue = nut_getSensorValue($device['device_id'], "{$upsName}_charge")
            ?? nut_extractValue($upsInfo['battery']['charge'] ?? null)
            ?? '-';

        $loadText = is_numeric($loadValue) ? Number::formatSi((float) $loadValue, 1, 0, '%') : '-';
        $powerText = is_numeric($powerValue) ? Number::formatSi((float) $powerValue, 1, 0, $powerUnit) : '-';
        $chargeText = is_numeric($chargeValue) ? Number::formatSi((float) $chargeValue, 1, 0, '%') : '-';

        // Get aggregate status
        $aggResult = $getAggregateSeverity($device['device_id'], $upsName);
        $aggSeverity = $aggResult['severity'];
        $aggProblems = $aggResult['problems'];
        $aggLabelClass = match ($aggSeverity) {
            LibreNMS\Enum\Severity::Ok => 'label-default',
            LibreNMS\Enum\Severity::Warning => 'label-warning',
            LibreNMS\Enum\Severity::Error => 'label-danger',
            default => 'label-default',
        };
        $aggTitle = empty($aggProblems) ? 'All OK' : implode(', ', $aggProblems);
        $aggDescr = match ($aggSeverity) {
            LibreNMS\Enum\Severity::Ok, null => 'OK',
            LibreNMS\Enum\Severity::Warning => 'Warning',
            LibreNMS\Enum\Severity::Error => 'Alert',
            default => 'Unknown',
        };
        $aggStatusHtml = '<span class="label ' . $aggLabelClass . '" title="' . htmlspecialchars($aggTitle) . '">' . htmlspecialchars($aggDescr) . '</span>';

        if ($graphs_html !== '') {
            echo <<<HTML
<div class="panel panel-default" style="margin-bottom: 10px;">
<div class="panel-heading"><h3 class="panel-title"><a href="{$header_link}" style="color:#337ab7;">{$modelDisplay}</a><div class="pull-right"><small class="text-muted">Load: {$loadText} | Power: {$powerText} | Charge: {$chargeText}</small> {$aggStatusHtml}</div></h3></div>
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
    $deviceSensors = App\Models\Sensor::where('device_id', $deviceId)
        ->where('sensor_oid', 'like', 'app:nut:%')
        ->get();
    $upsSensorsByClass = upsNutGetUpsSensorsByClass($deviceSensors, $upsName);
    upsNutRenderSingleGraphPanels($deviceSensors, $upsName, $graphType);
    // upsNutRenderAggregateGraphPanels($upsSensorsByClass, $graphType, $deviceSensors, $upsName, (int) $app->app_id);

    $multiviewTypes = [];
    foreach (nut_getMultiviewTypes() as $view => $label) {
        $multiviewTypes[$view] = $label . ' Multiview';
    }

    foreach ($multiviewTypes as $view => $title) {
        if ($graphType && $graphType !== $view) {
            continue;
        }

        $sensorClass = nut_getMultiviewSensorClass($view);

        if ($sensorClass === null) {
            continue;
        }

        $viewSensors = $deviceSensors->filter(
            fn ($sensor) => $sensor->sensor_class === $sensorClass
                && nut_sensorBelongsToUps((string) $sensor->sensor_index, $upsName)
        );

        if ($viewSensors->isEmpty()) {
            continue;
        }

        if ($viewSensors->count() === 1) {
            $sensor = $viewSensors->first();
            $unit = match ($view) {
                'voltage' => 'V',
                'current' => 'A',
                'temperature' => 'C',
                'power' => str_contains((string) $sensor->sensor_index, 'realpower') ? 'W' : 'VA',
                default => '',
            };
            $currentValue = $sensor->sensor_current;
            $currentText = is_numeric($currentValue)
                ? Number::formatSi((float) $currentValue, 1, 0, $unit)
                : '-';

            $graph_array = [
                'height' => '100',
                'width' => '215',
                'to' => App\Facades\LibrenmsConfig::get('time.now'),
                'id' => $sensor->sensor_id,
                'type' => 'sensor_' . $sensorClass,
                'legend' => 'no',
            ];

            echo '<div class="panel panel-default">
<div class="panel-heading">
    <h3 class="panel-title">' . htmlspecialchars(str_replace(' Multiview', '', $title)) . '<div class="pull-right"><span class="text-muted">' . htmlspecialchars($currentText) . '</span></div></h3>
</div>
<div class="panel-body">
<div class="row">';

            include 'includes/html/print-graphrow.inc.php';

            echo '</div>
</div>
</div>';

            continue;
        }

        $headerValues = nut_formatMultiviewHeaderValues($viewSensors, $view, $upsName);
        $singleViewLinks = nut_buildSingleViewLinks($viewSensors, $sensorClass);

        $sensorGroups = upsNutGetMultiviewGroups($deviceSensors, $upsName, $sensorClass);
        $groupLinks = upsNutBuildGroupLinks($sensorGroups, (int) $app->app_id, $upsName, $view);

        $graph_array = [
            'height' => '100',
            'width' => '215',
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'id' => $app->app_id,
            'type' => 'application_ups-nut_multiview',
            'legend' => 'no',
            'view' => $view,
            'nutups' => $upsName,
        ];

        nut_renderMultiviewPanel($graph_array, $title, $headerValues, $groupLinks);

        if ($singleViewLinks !== '') {
            echo '<div class="panel panel-default" style="margin-top: -10px;">';
            echo '<div class="panel-body" style="padding: 8px 12px; text-align: center;">';
            echo '<strong>Single view:</strong> ' . $singleViewLinks;
            echo '</div>';
            echo '</div>';
        }
    }
}

function nut_buildStateHeaderSensors(int $deviceId, string $upsName, array $sensorConfigs): string
{
    $headerSensors = [];
    foreach ($sensorConfigs as $config) {
        $headerSensors[] = nut_renderStateSensor($deviceId, $upsName, $config);
    }

    return implode(' ', $headerSensors);
}

function nut_renderDetailPanel(array $panel): void
{
    $headerContent = htmlspecialchars((string) ($panel['title'] ?? ''));
    $header = $panel['header'] ?? '';
    if ($header !== '') {
        $headerContent .= ' <span class="pull-right">' . $header . '</span>';
    }

    echo '<div style="flex: 1 1 300px; min-width: 240px;">';
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">' . $headerContent . '</h3></div>';
    echo '<div class="panel-body">';

    $rows = $panel['rows'] ?? [];
    if (! empty($rows)) {
        echo '<table class="table table-condensed table-striped table-hover nut-keyval"><tbody>';
        foreach ($rows as $row) {
            $key = is_string($row[0] ?? null) ? htmlspecialchars($row[0]) : '';
            $valueRaw = $row[1] ?? '';
            $value = is_string($valueRaw) ? $valueRaw : (is_numeric($valueRaw) ? htmlspecialchars((string) $valueRaw) : json_encode($valueRaw));
            if (str_starts_with($value, '<span')) {
                echo '<tr><td>' . $key . ':</td><td>' . $value . '</td></tr>';
            } else {
                echo '<tr><td>' . $key . ':</td><td>' . htmlspecialchars($value) . '</td></tr>';
            }
        }
        echo '</tbody></table>';
    }

    echo '</div></div></div>';
}

function nut_renderPhaseLikeTable(string $title, array $columns, array $dataRows): void
{
    if (empty($columns) || empty($dataRows)) {
        return;
    }

    echo '<div style="flex: 1 1 300px; min-width: 240px;">';
    echo '<div class="panel panel-default" style="margin-top: 10px;">';
    echo '<div class="panel-heading"><h3 class="panel-title">' . htmlspecialchars($title) . '</h3></div>';
    echo '<div class="panel-body">';
    echo '<div class="table-responsive">';
    echo '<table class="table table-condensed table-striped table-hover">';
    echo '<thead><tr><th>Phase</th>';
    foreach ($columns as $col) {
        echo '<th>' . htmlspecialchars((string) ($col['label'] ?? '')) . '</th>';
    }
    echo '</tr></thead><tbody>';

    $sortedKeys = array_keys($dataRows);
    natsort($sortedKeys);
    foreach ($sortedKeys as $phase) {
        echo '<tr><td><strong>' . htmlspecialchars((string) $phase) . '</strong></td>';
        foreach ($columns as $col) {
            $key = (string) ($col['key'] ?? '');
            $unit = (string) ($col['unit'] ?? '');
            $value = $dataRows[$phase][$key] ?? null;
            if ($value === null) {
                $formatted = '-';
            } else {
                $formattedValue = in_array($unit, ['W', 'VA', 'V', 'A', 'Hz'], true)
                    ? Number::formatSi((float) $value, 1, 0, $unit)
                    : number_format((float) $value, 1) . $unit;
                $formatted = htmlspecialchars($formattedValue);
            }
            echo '<td>' . $formatted . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div></div></div></div>';
}

function nut_extractSectionMetric(array $sectionData, string $metric, string $aggregate = 'average'): ?float
{
    $direct = nut_extractValue($sectionData[$metric] ?? null);
    if ($direct !== null) {
        return $direct;
    }

    $phaseValues = [];
    foreach ($sectionData as $key => $value) {
        if (! is_array($value) || ! preg_match('/^(L\d+|\d+)$/', (string) $key)) {
            continue;
        }

        $phaseValue = nut_extractValue($value[$metric] ?? null);
        if ($phaseValue !== null) {
            $phaseValues[] = $phaseValue;
        }
    }

    if ($phaseValues === []) {
        return null;
    }

    if ($aggregate === 'sum') {
        return (float) array_sum($phaseValues);
    }

    return (float) (array_sum($phaseValues) / count($phaseValues));
}

function nut_extractSectionMetricNominal(array $sectionData, string $metric): ?float
{
    if (! isset($sectionData[$metric]) || ! is_array($sectionData[$metric])) {
        return null;
    }

    return nut_extractValue($sectionData[$metric]['nominal'] ?? null);
}

function nut_sumPhaseSensorValues(int $deviceId, string $upsName, string $metricPrefix): ?float
{
    $sensors = App\Models\Sensor::where('device_id', $deviceId)
        ->where('sensor_oid', 'like', 'app:nut:%')
        ->where('sensor_class', 'power')
        ->get();

    $sensors = $sensors->filter(
        fn ($sensor) => nut_sensorBelongsToUps((string) $sensor->sensor_index, $upsName)
            && str_contains((string) $sensor->sensor_index, "_{$metricPrefix}_L")
    );

    if ($sensors->isEmpty()) {
        return null;
    }

    $sum = 0.0;
    $found = false;
    foreach ($sensors as $sensor) {
        if (! is_numeric($sensor->sensor_current)) {
            continue;
        }

        $sum += (float) $sensor->sensor_current;
        $found = true;
    }

    return $found ? $sum : null;
}

function nut_renderUpsDetail(App\Models\Application $app, array $device, string $upsName, array $upsInfo): void
{
    $deviceId = $device['device_id'];

    // --- Data Collection ---
    $model = $upsInfo['device']['model'] ?? $upsInfo['ups']['model'] ?? 'Unknown';
    $serial = $upsInfo['ups']['serial'] ?? $upsInfo['device']['serial'] ?? '';
    if (is_string($serial) && preg_match('/^0+$/', $serial) === 1) {
        $serial = '-';
    }
    $mfr = $upsInfo['ups']['mfr'] ?? $upsInfo['device']['mfr'] ?? '';
    $configName = $upsInfo['configname'] ?? null;
    $title = $model;
    if ($configName && $configName !== $model) {
        $title .= " ($configName)";
    }

    // Get state sensor configuration
    $stateConfig = nut_getStateSensorConfig();

    $chargeValue = nut_getSensorValue($deviceId, "{$upsName}_charge");
    $chargeValue = $chargeValue ?? nut_extractValue($upsInfo['battery']['charge'] ?? null) ?? 0;
    $runtimeValue = nut_getSensorValue($deviceId, "{$upsName}_runtime");
    $runtimeValue = $runtimeValue ?? ($upsInfo['battery']['runtime'] ?? 0);
    $loadValue = nut_getSensorValue($deviceId, "{$upsName}_load");
    $loadValue = $loadValue ?? nut_extractValue($upsInfo['ups']['load'] ?? null) ?? 0;
    $phasePowerValue = nut_sumPhaseSensorValues($deviceId, $upsName, 'output_power')
        ?? nut_sumPhaseSensorValues($deviceId, $upsName, 'input_power');
    $jsonPhasePowerValue = nut_extractSectionMetric($upsInfo['output'] ?? [], 'power', 'sum')
        ?? nut_extractSectionMetric($upsInfo['input'] ?? [], 'power', 'sum');

    $powerValue = $phasePowerValue
        ?? $jsonPhasePowerValue
        ?? nut_getSensorValue($deviceId, "{$upsName}_ups_power")
        ?? nut_getSensorValue($deviceId, "{$upsName}_output_power")
        ?? nut_getSensorValue($deviceId, "{$upsName}_input_power")
        ?? 0;

    $realpowerValue = nut_getSensorValue($deviceId, "{$upsName}_ups_realpower")
        ?? nut_getSensorValue($deviceId, "{$upsName}_output_realpower")
        ?? nut_getSensorValue($deviceId, "{$upsName}_input_realpower");
    $realpowerValue = $realpowerValue ?? nut_extractValue($upsInfo['ups']['realpower'] ?? null) ?? 0;
    $outVoltageValue = nut_getSensorValue($deviceId, "{$upsName}_output_voltage");
    $outVoltageValue = $outVoltageValue ?? nut_extractSectionMetric($upsInfo['output'] ?? [], 'voltage') ?? 0;
    $outFreqValue = nut_getSensorValue($deviceId, "{$upsName}_output_frequency");
    $outFreqValue = $outFreqValue ?? nut_extractSectionMetric($upsInfo['output'] ?? [], 'frequency') ?? 0;
    $inVoltageValue = nut_getSensorValue($deviceId, "{$upsName}_input_voltage");
    $inVoltageValue = $inVoltageValue ?? nut_extractSectionMetric($upsInfo['input'] ?? [], 'voltage') ?? 0;
    $inFreqValue = nut_getSensorValue($deviceId, "{$upsName}_input_frequency");
    $inFreqValue = $inFreqValue ?? nut_extractSectionMetric($upsInfo['input'] ?? [], 'frequency') ?? 0;
    $batteryVoltageValue = nut_getSensorValue($deviceId, "{$upsName}_battery_voltage");
    $batteryVoltageValue = $batteryVoltageValue ?? nut_extractValue($upsInfo['battery']['voltage'] ?? null) ?? 0;
    $batteryTempValue = nut_getSensorValue($deviceId, "{$upsName}_battery_temperature");
    $batteryTempValue = $batteryTempValue ?? $upsInfo['battery']['temperature'] ?? 0;
    $upsTempValue = nut_getSensorValue($deviceId, "{$upsName}_ups_temperature");
    $upsTempValue = $upsTempValue ?? $upsInfo['ups']['temperature'] ?? 0;

    $chargeLowValue = 0;
    if (isset($upsInfo['battery']['charge']['low'])) {
        $chargeLowValue = (float) $upsInfo['battery']['charge']['low'];
    } elseif (isset($upsInfo['battery']['charge_low'])) {
        $chargeLowValue = (float) $upsInfo['battery']['charge_low'];
    }
    $powerNominalValue = nut_extractValue($upsInfo['ups']['power'] ?? null);
    if ($powerNominalValue === null) {
        $powerNominalValue = (float) ($upsInfo['ups']['power']['nominal'] ?? 0);
    }
    $outVoltageNomValue = nut_extractSectionMetricNominal($upsInfo['output'] ?? [], 'voltage') ?? 0;
    $inVoltageNomValue = nut_extractSectionMetricNominal($upsInfo['input'] ?? [], 'voltage') ?? 0;
    $batteryVoltageNomValue = (float) ($upsInfo['battery']['voltage']['nominal'] ?? 0);
    $inTransfer = $upsInfo['input']['transfer'] ?? [];
    $inTransferHighValue = (float) ($inTransfer['high'] ?? 0);
    $inTransferLowValue = (float) ($inTransfer['low'] ?? 0);
    $batteryType = $upsInfo['battery']['type'] ?? '';
    $outlets = $upsInfo['outlets'] ?? [];
    if (empty($outlets) && isset($upsInfo['outlet']) && is_array($upsInfo['outlet'])) {
        $outlets = array_values(array_filter(
            $upsInfo['outlet'],
            fn ($value) => is_array($value)
        ));
    }
    $ambientTempValue = $upsInfo['ambient']['temperature'] ?? 0;

    // Bypass data
    $bypass = $upsInfo['input']['bypass'] ?? [];
    $bypassVoltage = nut_extractValue($bypass['voltage'] ?? null) ?? 0;
    $bypassCurrent = nut_extractValue($bypass['current'] ?? null) ?? 0;
    $bypassFrequency = nut_extractValue($bypass['frequency'] ?? null) ?? 0;

    // Collect phase data dynamically - build unified table structure
    $phaseData = []; // [phase => [section_metric => value]]
    $hasInputVoltage = false;
    $hasOutputVoltage = false;
    $hasInputCurrent = false;
    $hasOutputCurrent = false;
    $hasInputPower = false;
    $hasOutputPower = false;
    $hasInputRealpower = false;
    $hasOutputRealpower = false;

    // Line-to-line data (separate table)
    $lineToLineData = []; // [phase => [section => value]]
    $hasInputLLVoltage = false;
    $hasOutputLLVoltage = false;

    foreach (['input', 'output'] as $section) {
        if (empty($upsInfo[$section]) || ! is_array($upsInfo[$section])) {
            continue;
        }
        $sectionData = $upsInfo[$section];

        // Handle aggregate values (non-phase specific like input.current, output.voltage)
        $aggregateVoltage = nut_extractValue($sectionData['voltage'] ?? null);
        if ($aggregateVoltage !== null) {
            $phaseData['Total']["{$section}_voltage"] = (float) $aggregateVoltage;
            if ($section === 'input') {
                $hasInputVoltage = true;
            } else {
                $hasOutputVoltage = true;
            }
        }

        $aggregateCurrent = nut_extractValue($sectionData['current'] ?? null);
        if ($aggregateCurrent !== null) {
            $phaseData['Total']["{$section}_current"] = (float) $aggregateCurrent;
            if ($section === 'input') {
                $hasInputCurrent = true;
            } else {
                $hasOutputCurrent = true;
            }
        }

        $aggregatePower = nut_extractValue($sectionData['power'] ?? null);
        if ($aggregatePower !== null) {
            $phaseData['Total']["{$section}_power"] = (float) $aggregatePower;
            if ($section === 'input') {
                $hasInputPower = true;
            } else {
                $hasOutputPower = true;
            }
        }

        $aggregateRealpower = nut_extractValue($sectionData['realpower'] ?? null);
        if ($aggregateRealpower !== null) {
            $phaseData['Total']["{$section}_realpower"] = (float) $aggregateRealpower;
            if ($section === 'input') {
                $hasInputRealpower = true;
            } else {
                $hasOutputRealpower = true;
            }
        }

        // Process phase-specific data (L1, L2, L3) and line-to-line (L1-L2, L2-L3, L3-L1)
        foreach ($sectionData as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            // Match L1, L2, L3, L4 etc. (individual phases)
            if (preg_match('/^L(\d+)$/', $key, $matches)) {
                $phase = $key;

                if (! isset($phaseData[$phase])) {
                    $phaseData[$phase] = [];
                }

                // Phase current
                $currentData = $value['current'] ?? null;
                if ($currentData !== null) {
                    $currentValue = is_numeric($currentData) ? $currentData : ($currentData['value'] ?? null);
                    if ($currentValue !== null) {
                        $phaseData[$phase]["{$section}_current"] = (float) $currentValue;
                        if ($section === 'input') {
                            $hasInputCurrent = true;
                        } else {
                            $hasOutputCurrent = true;
                        }
                    }
                }

                // Phase power
                $powerData = $value['power'] ?? null;
                if ($powerData !== null) {
                    $phasePowerMetric = is_numeric($powerData) ? $powerData : ($powerData['value'] ?? null);
                    if ($phasePowerMetric !== null) {
                        $phaseData[$phase]["{$section}_power"] = (float) $phasePowerMetric;
                        if ($section === 'input') {
                            $hasInputPower = true;
                        } else {
                            $hasOutputPower = true;
                        }
                    }
                }

                // Phase realpower
                $realpowerData = $value['realpower'] ?? null;
                if ($realpowerData !== null) {
                    $phaseRealpowerMetric = is_numeric($realpowerData) ? $realpowerData : ($realpowerData['value'] ?? null);
                    if ($phaseRealpowerMetric !== null) {
                        $phaseData[$phase]["{$section}_realpower"] = (float) $phaseRealpowerMetric;
                        if ($section === 'input') {
                            $hasInputRealpower = true;
                        } else {
                            $hasOutputRealpower = true;
                        }
                    }
                }
            }

            // Match L1-L2, L2-L3, L3-L1 (line-to-line voltages) - separate table
            if (preg_match('/^L(\d+)-L(\d+)$/', $key, $matches)) {
                $llPhase = $key; // e.g., "L1-L2"

                if (! isset($lineToLineData[$llPhase])) {
                    $lineToLineData[$llPhase] = [];
                }

                // Line-to-line voltage
                $voltageData = $value['voltage'] ?? null;
                if ($voltageData !== null) {
                    $voltageValue = is_numeric($voltageData) ? $voltageData : ($voltageData['value'] ?? null);
                    if ($voltageValue !== null) {
                        $lineToLineData[$llPhase][$section] = (float) $voltageValue;
                        if ($section === 'input') {
                            $hasInputLLVoltage = true;
                        } else {
                            $hasOutputLLVoltage = true;
                        }
                    }
                }
            }
        }
    }

    // Build column headers for phase table
    $phaseColumns = [];
    if ($hasInputVoltage) {
        $phaseColumns[] = ['key' => 'input_voltage', 'label' => 'In V', 'unit' => 'V'];
    }
    if ($hasInputCurrent) {
        $phaseColumns[] = ['key' => 'input_current', 'label' => 'In A', 'unit' => 'A'];
    }
    if ($hasInputPower) {
        $phaseColumns[] = ['key' => 'input_power', 'label' => 'In W', 'unit' => 'W'];
    }
    if ($hasInputRealpower) {
        $phaseColumns[] = ['key' => 'input_realpower', 'label' => 'In Real W', 'unit' => 'W'];
    }
    if ($hasOutputVoltage) {
        $phaseColumns[] = ['key' => 'output_voltage', 'label' => 'Out V', 'unit' => 'V'];
    }
    if ($hasOutputCurrent) {
        $phaseColumns[] = ['key' => 'output_current', 'label' => 'Out A', 'unit' => 'A'];
    }
    if ($hasOutputPower) {
        $phaseColumns[] = ['key' => 'output_power', 'label' => 'Out W', 'unit' => 'W'];
    }
    if ($hasOutputRealpower) {
        $phaseColumns[] = ['key' => 'output_realpower', 'label' => 'Out Real W', 'unit' => 'W'];
    }

    // Build column headers for line-to-line table
    $llColumns = [];
    if ($hasInputLLVoltage) {
        $llColumns[] = ['key' => 'input', 'label' => 'In V', 'unit' => 'V'];
    }
    if ($hasOutputLLVoltage) {
        $llColumns[] = ['key' => 'output', 'label' => 'Out V', 'unit' => 'V'];
    }

    $hasNamedPhases = false;
    foreach (array_keys($phaseData) as $phase) {
        if (preg_match('/^L\d+$/', (string) $phase) === 1) {
            $hasNamedPhases = true;
            break;
        }
    }
    $hasPhaseData = ! empty($phaseColumns) && $hasNamedPhases;
    $hasLineToLineData = ! empty($llColumns) && ! empty($lineToLineData);

    // --- Build Panels Array ---
    $panels = [];

    // Status sensors for device name header - Online, Alarm, Forced Shutdown
    $statusHeaderSensors = '';
    foreach ($stateConfig['alarm'] as $config) {
        $statusHeaderSensors .= '<span style="margin-left: 10px;">' . htmlspecialchars($config['label']) . ': ' . nut_renderStateSensor($deviceId, $upsName, $config) . '</span>';
    }

    // Device Info
    $deviceRows = [];
    if ($mfr) {
        $deviceRows[] = ['MFR', $mfr];
    }
    $deviceRows[] = ['Model', $model];
    if ($serial) {
        $deviceRows[] = ['Serial', $serial];
    }
    $panels[] = ['title' => 'Device Information', 'rows' => $deviceRows];

    // Battery (state sensors in header)
    $batteryHeaderSensors = nut_buildStateHeaderSensors($deviceId, $upsName, $stateConfig['battery']);
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
    if ($batteryVoltageValue !== 0) {
        $batteryRows[] = ['Voltage', $batteryVoltageValue . 'V'];
    }
    if (abs($batteryVoltageNomValue) > 0.00001) {
        $batteryRows[] = ['Voltage Nominal', $batteryVoltageNomValue . 'V'];
    }
    if ($batteryTempValue !== 0) {
        $batteryRows[] = ['Temperature', $batteryTempValue . '°C'];
    }
    if ($batteryType) {
        $batteryRows[] = ['Battery Type', $batteryType];
    }
    if (! empty($batteryRows) || $batteryHeaderSensors !== '') {
        $panels[] = ['title' => 'Battery', 'header' => $batteryHeaderSensors, 'rows' => $batteryRows];
    }

    // Power (skip if load/realpower are 0)
    $powerRows = [];
    if ($loadValue !== 0) {
        $powerRows[] = ['Load', $loadValue . '%'];
    }
    if (abs($powerValue) > 0.00001) {
        $powerRows[] = ['Power', Number::formatSi((float) $powerValue, 1, 0, 'VA')];
    }
    if (abs($realpowerValue) > 0.00001) {
        $powerRows[] = ['Real Power', Number::formatSi((float) $realpowerValue, 1, 0, 'W')];
    }
    if (abs($powerNominalValue) > 0.00001) {
        $powerRows[] = ['Power Nominal', Number::formatSi((float) $powerNominalValue, 1, 0, 'VA')];
    }
    if ($upsTempValue !== 0) {
        $powerRows[] = ['Temperature', $upsTempValue . '°C'];
    }
    if ($ambientTempValue !== 0) {
        $powerRows[] = ['Ambient Temperature', $ambientTempValue . '°C'];
    }
    if (! empty($powerRows)) {
        $panels[] = ['title' => 'Power', 'rows' => $powerRows];
    }

    // Output (state sensors in header)
    $outputHeaderSensors = nut_buildStateHeaderSensors($deviceId, $upsName, $stateConfig['output']);
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
    if (! empty($outputRows) || $outputHeaderSensors !== '') {
        $panels[] = ['title' => 'Output', 'header' => $outputHeaderSensors, 'rows' => $outputRows];
    }

    // Input (state sensors in header)
    $inputHeaderSensors = nut_buildStateHeaderSensors($deviceId, $upsName, $stateConfig['input']);
    $inputRows = [];
    if ($inVoltageValue !== 0) {
        $inputRows[] = ['Voltage', $inVoltageValue . 'V'];
    }
    if ($inFreqValue !== 0) {
        $inputRows[] = ['Frequency', $inFreqValue . 'Hz'];
    }
    if (abs($inTransferLowValue) > 0.00001 || abs($inTransferHighValue) > 0.00001) {
        $inputRows[] = ['Transfer', $inTransferLowValue . '-' . $inTransferHighValue . 'V'];
    }
    if ($inVoltageNomValue !== 0) {
        $inputRows[] = ['Voltage Nominal', $inVoltageNomValue . 'V'];
    }
    if (! empty($inputRows) || $inputHeaderSensors !== '') {
        $panels[] = ['title' => 'Input', 'header' => $inputHeaderSensors, 'rows' => $inputRows];
    }

    // Bypass
    if ($bypassVoltage !== 0 || $bypassCurrent !== 0 || $bypassFrequency !== 0) {
        $bypassRows = [];
        if ($bypassVoltage !== 0) {
            $bypassRows[] = ['Voltage', $bypassVoltage . 'V'];
        }
        if ($bypassCurrent !== 0) {
            $bypassRows[] = ['Current', $bypassCurrent . 'A'];
        }
        if ($bypassFrequency !== 0) {
            $bypassRows[] = ['Frequency', $bypassFrequency . 'Hz'];
        }
        if (! empty($bypassRows)) {
            $panels[] = ['title' => 'Bypass', 'rows' => $bypassRows];
        }
    }

    // --- HTML Output ---
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">';
    echo '<span>' . htmlspecialchars($title) . '</span>';
    echo '<span class="pull-right">' . $statusHeaderSensors . '</span>';
    echo '</h3></div>';
    echo '<div class="panel-body">';
    echo '<div class="nut-panels" style="display: flex; flex-wrap: wrap; gap: 10px;">';

    foreach ($panels as $panel) {
        nut_renderDetailPanel($panel);
    }

    // Phase Data Table (for 3-phase UPS) - L1, L2, L3 currents and power
    if ($hasPhaseData) {
        nut_renderPhaseLikeTable('Phase Data', $phaseColumns, $phaseData);
    }

    // Line-to-Line Voltage Table (L1-L2, L2-L3, L3-L1)
    if ($hasLineToLineData) {
        nut_renderPhaseLikeTable('Line-to-Line Voltage', $llColumns, $lineToLineData);
    }

    echo '</div>'; // close nut-panels

    echo '</div></div></div>';

    // Graphs
}

$selectedUps = $vars['nutups'] ?? null;
$graphType = $vars['graphtype'] ?? null;

nut_renderNavigation($link_array, $upsList, $appData['data'] ?? [], $selectedUps, $graphType);

if (isset($selectedUps)) {
    $currentUps = $appData['data'][$selectedUps] ?? [];
    if (! empty($currentUps)) {
        nut_renderUpsDetail($app, $device, $selectedUps, $currentUps);
        nut_renderGraphs($app, $device, $selectedUps);
    }
} else {
    nut_renderOverviewTable($app, $device, $upsList, $appData['data'] ?? []);
}

// Version footer
echo '<div class="text-muted" style="margin-top: 10px; text-align: right;">';
echo 'Agent Version: ' . ($appData['version'] ?? 'unknown');
echo '</div>';
