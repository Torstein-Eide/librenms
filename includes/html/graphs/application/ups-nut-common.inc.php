<?php

use Illuminate\Support\Collection;
use LibreNMS\Util\Url;

function upsNutGetAggregateGraphDefinitions(): array
{
    return [
        'power' => 'Power',
        'voltage' => 'Voltage',
        'current' => 'Current',
        'frequency' => 'Frequency',
        'temperature' => 'Temperature',
    ];
}

function upsNutGetSingleGraphDefinitions(): array
{
    return [
        'charge' => ['index' => 'charge', 'class' => 'charge', 'unit' => '%', 'title' => 'Charge'],
        'load' => ['index' => 'load', 'class' => 'load', 'unit' => '%', 'title' => 'Load'],
        'runtime' => ['index' => 'runtime', 'class' => 'runtime', 'unit' => 'min', 'title' => 'Runtime'],
        'powerfactor' => ['index' => null, 'class' => 'power_factor', 'unit' => '', 'title' => 'Powerfactor'],
    ];
}

function upsNutGetMultiviewGraphSeries(?string $view = null): array
{
    $series = [
        'voltage' => [
            'unit_text' => 'Volts',
            'series' => [
                'out_voltage' => ['descr' => 'Output', 'colour' => '006699'],
                'in_voltage' => ['descr' => 'Input', 'colour' => '630606'],
                'battery_voltage' => ['descr' => 'Battery', 'colour' => '009933'],
            ],
        ],
        'frequency' => [
            'unit_text' => 'Hertz',
            'series' => [
                'out_frequency' => ['descr' => 'Output', 'colour' => 'cc6600'],
            ],
        ],
        'power' => [
            'unit_text' => 'Watts',
            'series' => [
                'realpower' => ['descr' => 'Real Power', 'colour' => 'cc0000'],
            ],
        ],
        'load' => [
            'unit_text' => 'Percent',
            'series' => [
                'load' => ['descr' => 'Load', 'colour' => '663399'],
            ],
        ],
        'charge' => [
            'unit_text' => 'Percent',
            'series' => [
                'charge' => ['descr' => 'Charge', 'colour' => '339999'],
            ],
        ],
    ];

    return $series[$view ?? 'voltage'] ?? $series['voltage'];
}

function upsNutGetUpsSensorsByClass(Collection $deviceSensors, string $upsName): array
{
    $prefix = $upsName . '_';

    return $deviceSensors
        ->filter(fn ($sensor) => str_starts_with((string) $sensor->sensor_index, $prefix))
        ->groupBy('sensor_class')
        ->map(fn ($sensors) => $sensors->sortBy('sensor_descr')->values())
        ->all();
}

function upsNutGetSensorGroupKey(string $upsName, string $sensorIndex): string
{
    $suffix = preg_replace('/^' . preg_quote($upsName . '_', '/') . '/', '', $sensorIndex);
    if (! is_string($suffix) || $suffix === '') {
        return 'overall';
    }

    if (str_contains($suffix, '_ll_') || preg_match('/_L\d-L\d$/', $suffix)) {
        return 'phase_phase';
    }

    if (preg_match('/_L\d(?:_real)?$/', $suffix)) {
        return 'phase';
    }

    foreach (['input_', 'output_', 'bypass_'] as $prefix) {
        if (str_starts_with($suffix, $prefix)) {
            return 'section';
        }
    }

    return 'overall';
}

function upsNutGetSensorGroups(Collection $deviceSensors, string $upsName, string $sensorClass): array
{
    $prefix = $upsName . '_';

    $groups = $deviceSensors
        ->filter(
            fn ($sensor) => $sensor->sensor_class === $sensorClass
                && str_starts_with((string) $sensor->sensor_index, $prefix)
        )
        ->groupBy(fn ($sensor) => upsNutGetSensorGroupKey($upsName, (string) $sensor->sensor_index))
        ->map(fn ($sensors) => $sensors->sortBy('sensor_descr')->values())
        ->all();

    return array_filter($groups, fn ($sensors) => $sensors->isNotEmpty());
}

function upsNutGetMultiviewGroups(Collection $deviceSensors, string $upsName, string $sensorClass): array
{
    $prefix = $upsName . '_';
    $sensors = $deviceSensors->filter(
        fn ($sensor) => $sensor->sensor_class === $sensorClass
            && str_starts_with((string) $sensor->sensor_index, $prefix)
    );

    $groups = [];

    foreach ($sensors as $sensor) {
        $suffix = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', (string) $sensor->sensor_index);
        if (! is_string($suffix) || $suffix === '') {
            continue;
        }

        $groupKey = null;
        $groupLabel = null;

        if (in_array($sensorClass, ['voltage', 'current'], true)) {
            if (str_starts_with($suffix, 'battery_')) {
                $groupKey = 'battery';
                $groupLabel = 'Battery';
            } elseif (str_starts_with($suffix, 'input_') && (str_contains($suffix, '_ll_') || preg_match('/_L\d-L\d$/', $suffix))) {
                $groupKey = 'input_phase_phase';
                $groupLabel = 'Input Phase-Phase';
            } elseif (str_starts_with($suffix, 'output_') && (str_contains($suffix, '_ll_') || preg_match('/_L\d-L\d$/', $suffix))) {
                $groupKey = 'output_phase_phase';
                $groupLabel = 'Output Phase-Phase';
            } elseif (str_starts_with($suffix, 'input_') && (preg_match('/_L\d(?:_real)?$/', $suffix) || ! preg_match('/_L\d(?:-L\d)?(?:_real)?$/', $suffix))) {
                $groupKey = 'input_phase';
                $groupLabel = 'Input Phase';
            } elseif (str_starts_with($suffix, 'output_') && (preg_match('/_L\d(?:_real)?$/', $suffix) || ! preg_match('/_L\d(?:-L\d)?(?:_real)?$/', $suffix))) {
                $groupKey = 'output_phase';
                $groupLabel = 'Output Phase';
            }
        } elseif ($sensorClass === 'frequency') {
            if (str_starts_with($suffix, 'input_')) {
                $groupKey = 'input';
                $groupLabel = 'Input';
            } elseif (str_starts_with($suffix, 'output_')) {
                $groupKey = 'output';
                $groupLabel = 'Output';
            }
        }

        if ($groupKey === null) {
            continue;
        }

        if (! isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'label' => $groupLabel,
                'sensors' => collect(),
            ];
        }

        $groups[$groupKey]['sensors']->push($sensor);
    }

    foreach ($groups as $groupKey => $group) {
        $groups[$groupKey]['sensors'] = $group['sensors']->sortBy([
            fn ($sensor) => $sensor->hasThresholds() ? 0 : 1,
            'sensor_descr',
        ])->values();
    }

    return $groups;
}

function upsNutBuildGroupLinks(array $sensorGroups, int $appId, string $upsName, string $view): string
{
    if (count($sensorGroups) <= 1) {
        return '';
    }

    $groupLinks = [];
    foreach ($sensorGroups as $group) {
        /** @var \Illuminate\Support\Collection $groupSensors */
        $groupSensors = $group['sensors'] ?? collect();
        if ($groupSensors->isEmpty()) {
            continue;
        }

        $groupLinks[] = '<a href="' . Url::generate([
            'page' => 'graphs',
            'id' => $groupSensors->pluck('sensor_id')->implode(','),
            'type' => 'multisensor_graph',
            'from' => App\Facades\LibrenmsConfig::get('time.day'),
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
        ]) . '">' . htmlspecialchars((string) ($group['label'] ?? $view)) . '</a>';
    }

    return implode(' | ', $groupLinks);
}

function upsNutBuildAggregateMiniGraphsHtml(array $upsSensorsByClass, string $headerLink): string
{
    $graphsHtml = '';

    foreach (upsNutGetAggregateGraphDefinitions() as $sensorClass => $graphTitle) {
        /** @var \Illuminate\Support\Collection $classSensors */
        $classSensors = $upsSensorsByClass[$sensorClass] ?? collect();
        if ($classSensors->isEmpty()) {
            continue;
        }
        $graphArray = [
            'height' => '80',
            'width' => '180',
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'from' => App\Facades\LibrenmsConfig::get('time.day'),
            'id' => $classSensors->pluck('sensor_id')->implode(','),
            'type' => 'multisensor_graph',
            'legend' => 'no',
        ];
        $graphLink = Url::generate([
            'page' => 'graphs',
            'type' => $graphArray['type'],
            'id' => $graphArray['id'],
            'from' => $graphArray['from'],
            'to' => $graphArray['to'],
        ]);

        $graphsHtml .= '<div class="pull-left" style="margin-right: 8px;">';
        $graphsHtml .= '<div class="text-muted" style="font-size: 11px; margin-bottom: 4px;">' . htmlspecialchars($graphTitle) . '</div>';
        $graphsHtml .= '<a href="' . $graphLink . '">' . Url::lazyGraphTag($graphArray) . '</a>';
        $graphsHtml .= '</div>';
    }

    return $graphsHtml;
}

function upsNutRenderAggregateGraphPanels(
    array $upsSensorsByClass,
    ?string $graphType = null,
    ?Collection $deviceSensors = null,
    ?string $upsName = null,
    ?int $appId = null
): void
{
    foreach (upsNutGetAggregateGraphDefinitions() as $sensorClass => $graphTitle) {
        if ($graphType && $graphType !== $sensorClass) {
            continue;
        }

        /** @var \Illuminate\Support\Collection $classSensors */
        $classSensors = $upsSensorsByClass[$sensorClass] ?? collect();
        if ($classSensors->isEmpty()) {
            continue;
        }

        echo '<div class="panel panel-default">
<div class="panel-heading">
    <h3 class="panel-title">' . htmlspecialchars($graphTitle) . '</h3>
</div>
<div class="panel-body">
<div class="row">';

        $graph_array = [
            'height' => '100',
            'width' => '215',
            'to' => App\Facades\LibrenmsConfig::get('time.now'),
            'id' => $classSensors->pluck('sensor_id')->implode(','),
            'type' => 'multisensor_graph',
            'legend' => 'no',
        ];

        include 'includes/html/print-graphrow.inc.php';

        if ($deviceSensors !== null && $upsName !== null && $appId !== null) {
            $sensorGroups = upsNutGetSensorGroups($deviceSensors, $upsName, $sensorClass);
            if (count($sensorGroups) > 1) {
                $groupLabels = [
                    'overall' => 'Overall',
                    'section' => 'Section',
                    'phase' => 'Phase',
                    'phase_phase' => 'Phase-Phase',
                ];
                $groupLinks = [];
                foreach ($groupLabels as $groupKey => $groupLabel) {
                    if (! isset($sensorGroups[$groupKey])) {
                        continue;
                    }

                    $groupLinks[] = '<a href="' . Url::generate([
                        'page' => 'graphs',
                        'id' => $appId,
                        'type' => 'application_ups-nut_multiview',
                        'view' => $sensorClass,
                        'nutups' => $upsName,
                        'group' => $groupKey,
                    ]) . '">' . htmlspecialchars($groupLabel) . '</a>';
                }

                if (! empty($groupLinks)) {
                    echo '<div class="col-md-12 text-center" style="margin-top: 8px;"><small>' . implode(' | ', $groupLinks) . '</small></div>';
                }
            }
        }

        echo '</div>
</div>
</div>';
    }
}

function upsNutRenderSingleGraphPanels(Collection $deviceSensors, string $upsName, ?string $graphType = null): void
{
    foreach (upsNutGetSingleGraphDefinitions() as $graphKey => $graphInfo) {
        if ($graphType && $graphType !== $graphKey) {
            continue;
        }

        if ($graphInfo['class'] === 'power_factor') {
            $classSensors = $deviceSensors
                ->filter(
                    fn ($item) => $item->sensor_class === 'power_factor'
                        && str_starts_with((string) $item->sensor_index, "{$upsName}_")
                )
                ->sortBy('sensor_descr')
                ->values();

            if ($classSensors->isEmpty()) {
                continue;
            }

            $currentValues = $classSensors
                ->pluck('sensor_current')
                ->filter(fn ($value) => is_numeric($value))
                ->map(fn ($value) => (float) $value)
                ->values();

            if ($currentValues->isNotEmpty()) {
                $min = $currentValues->min();
                $max = $currentValues->max();
                $valueStr = abs($max - $min) < 0.00001
                    ? number_format($min, 2)
                    : number_format($min, 2) . '-' . number_format($max, 2);
            } else {
                $valueStr = '-';
            }

            echo '<div class="panel panel-default">
<div class="panel-heading">
    <h3 class="panel-title">
        ' . $graphInfo['title'] . '
        <div class="pull-right"><span class="text-muted">' . $valueStr . '</span></div>
    </h3>
</div>
<div class="panel-body">
<div class="row">';

            $graph_array = [
                'height' => '100',
                'width' => '215',
                'to' => App\Facades\LibrenmsConfig::get('time.now'),
                'id' => $classSensors->pluck('sensor_id')->implode(','),
                'type' => 'multisensor_graph',
                'legend' => 'no',
            ];

            include 'includes/html/print-graphrow.inc.php';

            echo '</div>
</div>
</div>';

            continue;
        }

        $sensorIndex = "{$upsName}_{$graphInfo['index']}";
        $sensorClass = $graphInfo['class'];
        $sensor = $deviceSensors->first(
            fn ($item) => $item->sensor_index === $sensorIndex && $item->sensor_class === $sensorClass
        );

        if (! $sensor) {
            continue;
        }

        $value = $sensor->sensor_current;
        $valueStr = is_numeric($value) ? $value . $graphInfo['unit'] : $value;

        echo '<div class="panel panel-default">
<div class="panel-heading">
    <h3 class="panel-title">
        ' . $graphInfo['title'] . '
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
            'type' => 'sensor_' . $sensorClass,
            'legend' => 'no',
        ];

        include 'includes/html/print-graphrow.inc.php';

        echo '</div>
</div>
</div>';
    }
}
