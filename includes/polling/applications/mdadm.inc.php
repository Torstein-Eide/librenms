<?php

use LibreNMS\Exceptions\JsonAppException;
use LibreNMS\Exceptions\JsonAppMissingKeysException;
use LibreNMS\RRD\RrdDefinition;

$name = 'mdadm';
$output = 'OK';

// Try SNMP extend first, fall back to unix agent data
try {
    $payload = json_app_get($device, $name, 1);
} catch (JsonAppMissingKeysException $e) {
    $payload = $e->getParsedJson();
} catch (JsonAppException $e) {
    if (! empty($agent_data['mdadm'])) {
        $payload = json_decode($agent_data['mdadm'], true);
        if (! is_array($payload)) {
            echo PHP_EOL . $name . ':Invalid JSON from agent data' . PHP_EOL;
            update_application($app, 'ERROR: Invalid JSON from agent data', []);

            return;
        }
    } else {
        echo PHP_EOL . $name . ':' . $e->getCode() . ':' . $e->getMessage() . PHP_EOL;
        update_application($app, $e->getCode() . ':' . $e->getMessage(), []);

        return;
    }
}


if (($payload['version'] ?? 0) >= 3) {
    echo 'version: ' . $payload['version'] . ' running new ';
    $module = new LibreNMS\Agent\Module\mdadm($device, $app);
    $module->run($payload);

    return;
}
echo 'version: ' . $payload['version'] . ' running legacy ';
$mdadm_data = $payload['data'] ?? [];

$rrd_name = ['app', $name, $app->app_id];
$rrd_def = RrdDefinition::make()
    ->addDataset('level', 'GAUGE', 0)
    ->addDataset('size', 'GAUGE', 0)
    ->addDataset('disc_count', 'GAUGE', 0)
    ->addDataset('hotspare_count', 'GAUGE', 0)
    ->addDataset('degraded', 'GAUGE', 0)
    ->addDataset('sync_speed', 'GAUGE', 0)
    ->addDataset('sync_completed', 'GAUGE', 0);

$metrics = [];
foreach ($mdadm_data as $data) {
    $array_name = $data['name'];
    $level = $data['level'];
    $size = $data['size'];
    $disc_count = $data['disc_count'];
    $hotspare_count = $data['hotspare_count'];
    $degraded = $data['degraded'];
    $sync_speed = $data['sync_speed'];
    $sync_completed = $data['sync_completed'];

    $rrd_name = ['app', $name, $app->app_id, $array_name];

    $array_level = str_replace('raid', '', $level);

    $fields = [
        'level' => $array_level,
        'size' => $size,
        'disc_count' => $disc_count,
        'hotspare_count' => $hotspare_count,
        'degraded' => $degraded,
        'sync_speed' => $sync_speed,
        'sync_completed' => $sync_completed,
    ];

    $metrics[$array_name] = $fields;
    $tags = ['name' => $array_name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
    app('Datastore')->put($device, 'app', $tags, $fields);
}
update_application($app, $output, $metrics);
