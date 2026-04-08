<?php

use LibreNMS\Enum\Severity;
use LibreNMS\Util\Html;

$row = 0;
$unit ??= $class->unit();
$graph_type ??= 'sensor_' . $class->value;

// Sort by group path first so sensors in the same hierarchy are adjacent, then by
// sensor_descr within each group.  The original sort was sensor_descr only.
$sensors = App\Models\Sensor::where('sensor_class', $class)->where('device_id', $device['device_id'])->orderBy('group')->orderBy('sensor_descr')->get();

include_once 'includes/html/pages/device/sensor-group-helpers.inc.php';
[$groupCounts, $groupHasChildren] = buildSensorGroupData($sensors);

// Tracks which heading levels have already been rendered for the current position
// in the sorted list.  Reset at each depth where the path changes.
$currentPath = [];

foreach ($sensors as $sensor) {
    $groupStr = $sensor->group ?? '';
    // Split '::'-separated group path into level names.
    $parts = $groupStr !== '' ? explode('::', $groupStr) : [];

    // Emit section heading divs for every path level that changed since the last
    // sensor.  Depth 0 uses <h4>, deeper levels use <h5>; each is indented by
    // 16 px per level so nested groups are visually distinct.
    for ($depth = 0; $depth < count($parts); $depth++) {
        if (($currentPath[$depth] ?? null) !== $parts[$depth]) {
            $currentPath = array_slice($currentPath, 0, $depth);
            for ($d = $depth; $d < count($parts); $d++) {
                $pathToHere = implode('::', array_slice($parts, 0, $d + 1));
                if (! isSensorGroupSuppressed($pathToHere, $groupCounts, $groupHasChildren)) {
                    $marginLeft = $d * 16;
                    $headingSize = $d === 0 ? 'h4' : 'h5';
                    echo "<div style='margin-left:{$marginLeft}px; margin-top: 8px; margin-bottom: 4px;'>"
                        . "<{$headingSize} class='section-heading'>{$parts[$d]}</{$headingSize}>"
                        . '</div>';
                }
                $currentPath[$d] = $parts[$d];
            }
            break;
        }
    }

    // Strip the root group segment from sensor_descr when its heading is visible.
    // The heading already provides that context so repeating it in every panel title
    // is redundant.  The full sensor_descr is preserved in alerts and eventlog.
    $displayDescr = stripSensorDescrGroupPrefix($sensor['sensor_descr'], $groupStr, $parts, $groupCounts, $groupHasChildren);

    if (! is_int($row++ / 2)) {
        $row_colour = App\Facades\LibrenmsConfig::get('list_colour.even');
    } else {
        $row_colour = App\Facades\LibrenmsConfig::get('list_colour.odd');
    }

    if ($sensor['poller_type'] == 'ipmi') {
        $sensor_descr = ipmiSensorName($device['hardware'], $displayDescr);
    } else {
        $sensor_descr = $displayDescr;
    }

    $sensor_current = Html::severityToLabel($sensor->currentStatus(), $sensor->formatValue());

    echo "<div class='panel panel-default'>
        <div class='panel-heading'>
        <h3 class='panel-title'>$sensor_descr <div class='pull-right'>$sensor_current";

    //Display low and high limit if they are not null (format_si() is changing null to '0')
    if (! is_null($sensor->sensor_limit_low)) {
        echo ' ' . Html::severityToLabel(Severity::Unknown, 'low: ' . $sensor->formatValue('sensor_limit_low'));
    }
    if (! is_null($sensor->sensor_limit_low_warn)) {
        echo ' ' . Html::severityToLabel(Severity::Unknown, 'low_warn: ' . $sensor->formatValue('sensor_limit_low_warn'));
    }
    if (! is_null($sensor->sensor_limit_warn)) {
        echo ' ' . Html::severityToLabel(Severity::Unknown, 'high_warn: ' . $sensor->formatValue('sensor_limit_warn'));
    }
    if (! is_null($sensor->sensor_limit)) {
        echo ' ' . Html::severityToLabel(Severity::Unknown, 'high: ' . $sensor->formatValue('sensor_limit'));
    }

    echo '</div></h3>
        </div>';
    echo "<div class='panel-body'>";

    $graph_array['id'] = $sensor['sensor_id'];
    $graph_array['type'] = $graph_type;

    include 'includes/html/print-graphrow.inc.php';

    echo '</div></div>';
}
