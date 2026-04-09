<?php

/**
 * Multi-sensor graph authentication
 *
 * Allows graphing multiple sensors (of the same type) from different devices
 * on a single graph. Sensors are specified as comma-separated IDs.
 *
 * Example URL:
 * graph.php?type=multisensor_graph&id=123,456,789
 */

use App\Models\Sensor;

$multisensor_sensors = [];
$auth = false;

foreach (explode(',', (string) $vars['id']) as $sensor_id) {
    $sensor_id = trim($sensor_id);
    if (! is_numeric($sensor_id)) {
        $multisensor_sensors = [];
        break;
    }

    $sensor = Sensor::find($sensor_id);
    if (! $sensor || ! device_permitted($sensor->device_id)) {
        $multisensor_sensors = [];
        break;
    }

    $multisensor_sensors[] = $sensor;
}

if (! empty($multisensor_sensors)) {
    $auth = true;
}

$title = 'Multi Sensor :: ';
