<?php

/*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program.  If not, see <https://www.gnu.org/licenses/>.
*
* @package    LibreNMS
* @link       https://www.librenms.org
* @copyright  2016 crcro
* @author     Cercel Valentin <crc@nuamchefazi.ro>
*
*/

use LibreNMS\RRD\RrdDefinition;

function nut_clean_db(int $device_id, App\Models\Application $app): void
{
    echo "Cleaning {$app} database for device_id=$device_id, app_id={$app->app_id}\n";

    // Delete NUT sensors (sensor_class in relevant classes)
    $sensor_classes = ['charge', 'load', 'runtime', 'voltage', 'power', 'frequency', 'state'];
    $sensors = App\Models\Sensor::where('device_id', $device_id)
        ->whereIn('sensor_class', $sensor_classes)
        ->where('poller_type', '!=', 'app')
        ->delete();
    echo "Deleted $sensors legacy NUT sensors\n";

    // Reset app data using direct DB update to bypass Eloquent casts
    Illuminate\Support\Facades\DB::table('applications')
        ->where('app_id', $app->app_id)
        ->update(['data' => null]);
    // Reload the model to get fresh state
    $app->refresh();
    echo "Cleared app data\n";

    echo "Done cleaning {$app->app_name} database\n";
}

//nut_clean_db($device['device_id'], $app);

echo "<!-- ups-nut poller: start -->\n";

// Check for NUT v2 agent data
// First check if agent_data has the JSON, otherwise rely on json_app_get

// Debug: Show full agent_data structure
$agentDataStr = is_array($agent_data) ? json_encode($agent_data) : var_export($agent_data, true);
echo '<!-- ups-nut: full agent_data: ' . substr($agentDataStr, 0, 500) . " -->\n";

$agentJson = $agent_data['app']['ups-nut'] ?? $agent_data['ups-nut'] ?? null;
echo '<!-- ups-nut: agentJson from agent_data: ' . ($agentJson ? 'exists' : 'NULL') . " -->\n";

// If we have JSON from agent, check version
if (! empty($agentJson)) {
    $parsed = json_decode(stripslashes($agentJson), true);
    echo '<!-- ups-nut: json_error=' . json_last_error_msg() . ' --->' . "\n";
    $agentVersion = $parsed['version'] ?? 1;
    echo "<!-- ups-nut: parsed version=$agentVersion -->\n";

    if (isset($parsed['version']) && $parsed['version'] >= 2) {
        echo "<!-- ups-nut: using NutPoller v2 (from agent_data) -->\n";
        LibreNMS\Agent\Module\NutPoller::poll($app, $device);

        return;
    }
}

// Try to get data via json_app_get (SNMP)
echo "<!-- ups-nut: trying json_app_get for version check -->\n";
try {
    $test_payload = json_app_get($device, 'ups-nut', 1);
    echo '<!-- ups-nut: json_app_get returned, version=' . ($test_payload['version'] ?? 'none') . " -->\n";

    if (isset($test_payload['version']) && $test_payload['version'] >= 2) {
        echo "<!-- ups-nut: using NutPoller v2 (via json_app_get) -->\n";
        LibreNMS\Agent\Module\NutPoller::poll($app, $device);

        return;
    }
} catch (Exception $e) {
    echo '<!-- ups-nut: json_app_get failed: ' . $e->getMessage() . " -->\n";
}

echo "<!-- ups-nut: using legacy SNMP polling -->\n";

// (2016-11-25, R.Morris) ups-nut, try "extend" -> if not, fall back to "exec" support.
// -> Similar to approach used by Distro, but skip "legacy UCD-MIB shell support"
//
//NET-SNMP-EXTEND-MIB::nsExtendOutputFull."ups-nut"
$name = 'ups-nut';
$oid = '.1.3.6.1.4.1.8072.1.3.2.3.1.2.7.117.112.115.45.110.117.116';
$ups_nut = snmp_get($device, $oid, '-Oqv');

// If "extend" (used above) fails, try "exec" support.
// Note, exec always splits outputs on newline, so need to use snmp_walk (not a single SNMP entry!)
if (! $ups_nut) {
    // Data is in an array, due to how "exec" works with ups-nut.sh output, so snmp_walk to retrieve it
    $oid = '.1.3.6.1.4.1.2021.7890.2.101';
    $ups_nut = snmp_walk($device, $oid, '-Oqv');
}
//print_r(array_values(explode("\n", $ups_nut)));

// (2020-05-13, Jon.W) Added ups status data and updated ups-nut.sh script.
[
    $charge,
    $battery_low,
    $remaining,
    $bat_volt,
    $bat_nom,
    $line_nom,
    $input_volt,
    $load,
    $UPSOnLine,
    $UPSOnBattery,
    $UPSLowBattery,
    $UPSHighBattery,
    $UPSBatteryReplace,
    $UPSBatteryCharging,
    $UPSBatteryDischarging,
    $UPSUPSBypass,
    $UPSRuntimeCalibration,
    $UPSOffline,
    $UPSUPSOverloaded,
    $UPSUPSBuck,
    $UPSUPSBoost,
    $UPSForcedShutdown,
    $UPSAlarm
] = array_pad(explode("\n", (string) $ups_nut), 23, 0);

$rrd_def = RrdDefinition::make()
    ->addDataset('charge', 'GAUGE', 0, 100)
    ->addDataset('battery_low', 'GAUGE', 0, 100)
    ->addDataset('time_remaining', 'GAUGE', 0)
    ->addDataset('battery_voltage', 'GAUGE', 0)
    ->addDataset('battery_nominal', 'GAUGE', 0)
    ->addDataset('line_nominal', 'GAUGE', 0)
    ->addDataset('input_voltage', 'GAUGE', 0)
    ->addDataset('load', 'GAUGE', 0, 100);

$fields = [
    'charge' => $charge,
    'battery_low' => $battery_low,
    'time_remaining' => (int) $remaining / 60,
    'battery_voltage' => $bat_volt,
    'battery_nominal' => $bat_nom,
    'line_nominal' => $line_nom,
    'input_voltage' => $input_volt,
    'load' => $load,
];

$sensors = [
    ['state_name' => 'UPSOnLine', 'value' => $UPSOnLine],
    ['state_name' => 'UPSOnBattery', 'value' => $UPSOnBattery],
    ['state_name' => 'UPSLowBattery', 'value' => $UPSLowBattery],
    ['state_name' => 'UPSHighBattery', 'value' => $UPSHighBattery],
    ['state_name' => 'UPSBatteryReplace', 'value' => $UPSBatteryReplace],
    ['state_name' => 'UPSBatteryCharging', 'value' => $UPSBatteryCharging],
    ['state_name' => 'UPSBatteryDischarging', 'value' => $UPSBatteryDischarging],
    ['state_name' => 'UPSUPSBypass', 'value' => $UPSUPSBypass],
    ['state_name' => 'UPSRuntimeCalibration', 'value' => $UPSRuntimeCalibration],
    ['state_name' => 'UPSOffline', 'value' => $UPSOffline],
    ['state_name' => 'UPSUPSOverloaded', 'value' => $UPSUPSOverloaded],
    ['state_name' => 'UPSUPSBuck', 'value' => $UPSUPSBuck],
    ['state_name' => 'UPSUPSBoost', 'value' => $UPSUPSBoost],
    ['state_name' => 'UPSForcedShutdown', 'value' => $UPSForcedShutdown],
    ['state_name' => 'UPSAlarm', 'value' => $UPSAlarm],
];

foreach ($sensors as $sensor) {
    $rrd_def->addDataset($sensor['state_name'], 'GAUGE', 0);
    $fields[$sensor['state_name']] = $sensor['value'];
}

$tags = [
    'name' => $name,
    'app_id' => $app->app_id,
    'rrd_name' => ['app', $name, $app->app_id],
    'rrd_def' => $rrd_def,
];
app('Datastore')->put($device, 'app', $tags, $fields);
update_application($app, $ups_nut, $fields);
