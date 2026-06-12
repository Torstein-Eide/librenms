<?php

use App\Models\Sensor;
use LibreNMS\Data\Source\Ipmitool;

// IPMI - We can discover this on poll!
$sensorDiscovery = app('sensor-discovery');
if ($ipmi = Ipmitool::init()) {
    echo 'IPMI : ';

    // TODO: IPMI sensors are not linked to an entPhysical entity (entPhysicalIndex
    // stays NULL) because ipmitool has no ENTITY-MIB mapping. The inventory page
    // excludes poller_type='ipmi' from its sensor_index fallback so they don't
    // mismatch onto unrelated entities. The remaining work is to attach them to the
    // real hardware entity they belong to. See LibreNMS\OS\Linux::discoverEntityPhysical.
    foreach ($ipmi->discoverySensors() as $index => $sensor) {
        $sensorDiscovery->discover(new Sensor([
            'poller_type' => 'ipmi',
            'sensor_class' => $sensor['class'],
            'sensor_oid' => $sensor['descr'],
            'sensor_index' => $index,
            'sensor_type' => 'ipmi',
            'sensor_descr' => $sensor['descr'],
            'sensor_limit' => $sensor['limit_high'],
            'sensor_limit_warn' => $sensor['limit_high_warn'],
            'sensor_limit_low' => $sensor['limit_low'],
            'sensor_limit_low_warn' => $sensor['limit_low_warn'],
            'sensor_current' => $sensor['current'],
            'rrd_type' => 'GAUGE',
        ]));
    }

    echo "\n";
}

$sensorDiscovery->sync(sensor_class: 'voltage', poller_type: 'ipmi');
$sensorDiscovery->sync(sensor_class: 'temperature', poller_type: 'ipmi');
$sensorDiscovery->sync(sensor_class: 'fanspeed', poller_type: 'ipmi');
$sensorDiscovery->sync(sensor_class: 'power', poller_type: 'ipmi');
$sensorDiscovery->sync(sensor_class: 'current', poller_type: 'ipmi');
$sensorDiscovery->sync(sensor_class: 'load', poller_type: 'ipmi');
