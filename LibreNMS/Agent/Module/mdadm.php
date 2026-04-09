<?php

namespace LibreNMS\Agent\Module;

use App\Models\Application;
use App\Models\Sensor;
use App\Models\StateTranslation;
use LibreNMS\Enum\Severity;
use LibreNMS\RRD\RrdDefinition;

class mdadm
{
    private array $payload = [];

    private array $discovery = [
        'sensors'     => [], // sensor_index => ['uuid', 'devId', 'type'] path info for poll
        'sync'        => [],
        'array_count' => 0,
        'arrays'      => [],
    ];

    private array $plarray = [];

    public function __construct(private array $device, private Application $app)
    {
    }

    public function run(array $payload): void
    {
        $this->payload = $payload;
        $this->plarray = $payload['data']['tables']['arrays'] ?? [];

        echo 'Mdadm: ';
        $this->discovery();
        $this->poll();
        echo PHP_EOL;
    }

    public function discovery(): void
    {
        $this->discovery['sensors'] = [];
        $this->discovery['arrays'] = [];
        $this->discovery['array_count'] = count($this->plarray);

        app()->forgetInstance('sensor-discovery');

        foreach (array_keys($this->plarray) as $uuid) {
            $this->discovery['arrays'][(string) $uuid] = [
                'devices_count' => 0,
                'devices'       => [],
            ];

            $this->discoveryArray((string) $uuid);
        }

        echo $this->discovery['array_count'] . ' array(s) discovered ';

        $this->discovery['sync'] = [
            ['sensor_type' => 'mdadm_device_errors'],
            ['sensor_type' => 'mdadm_array_health_status'],
            ['sensor_type' => 'mdadm_array_operation_status'],
            ['sensor_type' => 'mdadm_device_health_status'],
        ];

        foreach ($this->discovery['sync'] as $syncSpec) {
            app('sensor-discovery')->sync(...$syncSpec);
        }

        // Persist sensor path map so poll() can resolve values without re-running discovery structure
        $data = (array) ($this->app->data ?? []);
        $data['sensor_paths'] = $this->discovery['sensors'];
        $this->app->data = $data;
    }

    public function poll(): void
    {
        $sensorPaths = $this->app->data['sensor_paths'] ?? $this->discovery['sensors'];

        $sensors = Sensor::where('device_id', $this->device['device_id'])
            ->where('sensor_oid', 'like', 'app:mdadm:%')
            ->get()
            ->keyBy('sensor_index');

        echo count($sensors) . ' sensor(s) polled ';

        foreach ($sensorPaths as $index => $pathInfo) {
            $sensor = $sensors[$index] ?? null;
            if ($sensor === null) {
                continue;
            }

            $value = $this->resolveValue($pathInfo);
            $sensor->sensor_current = $value;
            $sensor->save();

            app('Datastore')->put($this->device, 'sensor', [
                'sensor_class' => $sensor->sensor_class,
                'sensor_type'  => $sensor->sensor_type,
                'sensor_descr' => $sensor->sensor_descr,
                'sensor_index' => $sensor->sensor_index,
                'rrd_name'     => get_sensor_rrd_name($this->device, $sensor->toArray()),
                'rrd_def'      => RrdDefinition::make()->addDataset('sensor', 'GAUGE'),
            ], ['sensor' => $value]);
        }
    }

    private function resolveValue(array $pathInfo): int
    {
        $uuid = $pathInfo['uuid'] ?? null;
        $devId = $pathInfo['devId'] ?? null;
        $array = $this->plarray[$uuid]['array'] ?? [];
        $devices = $this->plarray[$uuid]['devices'] ?? [];

        return match ($pathInfo['type']) {
            'array_health'    => $this->mapArrayHealth($array, $devices),
            'array_operation' => $this->mapArrayOperation($array),
            'device_health'   => $this->mapDeviceHealth($devices[$devId] ?? []),
            'device_errors'   => (int) ($devices[$devId]['errors'] ?? 0),
            default           => -1,
        };
    }

    private function discoveryArray(string $uuid): void
    {
        $arrayData = is_array($this->plarray[$uuid] ?? null) ? $this->plarray[$uuid] : [];
        $array = $arrayData['array'] ?? [];
        $devices = $arrayData['devices'] ?? [];
        $arrayName = (string) ($array['name'] ?? $uuid);
        $arrayGroup = "Mdadm $arrayName";
        $arrayHealthIndex = $uuid . '_health';
        $arrayOperationIndex = $uuid . '_operation';

        app('sensor-discovery')
            ->discover(new Sensor([
                'device_id'      => $this->device['device_id'],
                'poller_type'    => 'agent',
                'sensor_class'   => 'state',
                'sensor_type'    => 'mdadm_array_health_status',
                'sensor_index'   => $arrayHealthIndex,
                'sensor_oid'     => "app:mdadm:$arrayHealthIndex",
                'group'          => $arrayGroup,
                'sensor_descr'   => "$arrayGroup Health",
                'sensor_current' => $this->mapArrayHealth($array, $devices),
            ]))
            ->withStateTranslations('mdadm_array_health_status', $this->arrayHealthTranslations());

        $this->discovery['sensors'][$arrayHealthIndex] = ['uuid' => $uuid, 'type' => 'array_health'];

        app('sensor-discovery')
            ->discover(new Sensor([
                'device_id'      => $this->device['device_id'],
                'poller_type'    => 'agent',
                'sensor_class'   => 'state',
                'sensor_type'    => 'mdadm_array_operation_status',
                'sensor_index'   => $arrayOperationIndex,
                'sensor_oid'     => "app:mdadm:$arrayOperationIndex",
                'group'          => $arrayGroup,
                'sensor_descr'   => "$arrayGroup Operation",
                'sensor_current' => $this->mapArrayOperation($array),
            ]))
            ->withStateTranslations('mdadm_array_operation_status', $this->arrayOperationTranslations());

        $this->discovery['sensors'][$arrayOperationIndex] = ['uuid' => $uuid, 'type' => 'array_operation'];

        foreach ($devices as $deviceKey => $deviceData) {
            $this->discovery['arrays'][$uuid]['devices'][] = (string) $deviceKey;
            $arrayGroup = "Mdadm $arrayName";
            $this->discoveryDevice($uuid, (string) $deviceKey, is_array($deviceData) ? $deviceData : [], $arrayGroup);
        }

        $this->discovery['arrays'][$uuid]['devices_count'] = count($this->discovery['arrays'][$uuid]['devices']);
    }

    private function discoveryDevice(
            string $uuid,
            string $devId,
            array $deviceData,
            string $arrayGroup): void
    {
        $deviceName = (string) ($deviceData['device_name'] ?? $devId);
        $deviceHealthIndex = $uuid . '_' . $devId . '_health';
        $deviceErrorsIndex = $uuid . '_' . $devId . '_errors';

        app('sensor-discovery')
            ->discover(new Sensor([
                'device_id'      => $this->device['device_id'],
                'poller_type'    => 'agent',
                'sensor_class'   => 'state',
                'sensor_type'    => 'mdadm_device_health_status',
                'sensor_index'   => $deviceHealthIndex,
                'group'          => "$arrayGroup::devices",
                'sensor_oid'     => "app:mdadm:$deviceHealthIndex",
                'sensor_descr'   => "$arrayGroup $deviceName Health",
                'sensor_current' => $this->mapDeviceHealth($deviceData),
            ]))
            ->withStateTranslations('mdadm_device_health_status', $this->deviceHealthTranslations());

        $this->discovery['sensors'][$deviceHealthIndex] = ['uuid' => $uuid, 'devId' => $devId, 'type' => 'device_health'];

        app('sensor-discovery')
            ->discover(new Sensor([
                'device_id'      => $this->device['device_id'],
                'poller_type'    => 'agent',
                'sensor_class'   => 'count',
                'sensor_type'    => 'mdadm_device_errors',
                'sensor_index'   => $deviceErrorsIndex,
                'group'          => "$arrayGroup::devices",
                'sensor_oid'     => "app:mdadm:$deviceErrorsIndex",
                'sensor_descr'   => "$arrayGroup $deviceName errors",
                'sensor_current' => (int) ($deviceData['errors'] ?? 0),
            ]));

        $this->discovery['sensors'][$deviceErrorsIndex] = ['uuid' => $uuid, 'devId' => $devId, 'type' => 'device_errors'];
    }

    private static function state(string $descr, int $value, Severity $severity): StateTranslation
    {
        return StateTranslation::define($descr, $value, $severity);
    }

    private function arrayHealthTranslations(): array
    {
        return [
            self::state('Healthy', 0, Severity::Ok),
            self::state('Degraded', 1, Severity::Warning),
            self::state('Failed Devices', 2, Severity::Error),
            self::state('Missing Device', 3, Severity::Error),
            self::state('Unknown', -1, Severity::Unknown),
        ];
    }

    private function arrayOperationTranslations(): array
    {
        return [
            self::state('Idle', 0, Severity::Ok),
            self::state('Clean', 1, Severity::Ok),
            self::state('Active', 2, Severity::Ok),
            self::state('Check', 3, Severity::Warning),
            self::state('Resync', 4, Severity::Warning),
            self::state('Recover', 5, Severity::Warning),
            self::state('Repair', 6, Severity::Warning),
            self::state('Inactive', 7, Severity::Warning),
            self::state('Readonly', 8, Severity::Warning),
            self::state('Clear', 9, Severity::Warning),
            self::state('Read Auto', 10, Severity::Ok),
            self::state('Write Pending', 11, Severity::Warning),
            self::state('Active Idle', 12, Severity::Ok),
            self::state('Suspended', 13, Severity::Warning),
            self::state('Unknown', -1, Severity::Unknown),
        ];
    }

    private function deviceHealthTranslations(): array
    {
        return [
            self::state('In Sync', 0, Severity::Ok),
            self::state('Spare', 1, Severity::Warning),
            self::state('Rebuilding', 2, Severity::Warning),
            self::state('Missing', 3, Severity::Error),
            self::state('Faulty', 4, Severity::Error),
            self::state('Blocked', 5, Severity::Error),
            self::state('Write Error', 6, Severity::Error),
            self::state('Active', 7, Severity::Ok),
            self::state('Write Mostly', 8, Severity::Ok),
            self::state('Want Replacement', 9, Severity::Warning),
            self::state('Replacement', 10, Severity::Warning),
            self::state('Unknown', -1, Severity::Unknown),
        ];
    }

    private function mapArrayHealth(array $array, array $devices): int
    {
        foreach ($devices as $device) {
            if (($device['is_missing'] ?? null) === true) {
                return 3;
            }
        }

        if (! isset($array['failed_devices'], $array['degraded'])) {
            return -1;
        }

        if ((int) $array['failed_devices'] > 0) {
            return 2;
        }

        if ((int) $array['degraded'] > 0) {
            return 1;
        }

        return 0;
    }

    private function mapArrayOperation(array $array): int
    {
        $operation = str_replace('_', '-', strtolower(trim((string) ($array['sync']['action'] ?? $array['state'] ?? ''))));

        $operationMap = [
            'idle' => 0,
            'clean' => 1,
            'active' => 2,
            'check' => 3,
            'resync' => 4,
            'recover' => 5,
            'recovery' => 5,
            'repair' => 6,
            'inactive' => 7,
            'readonly' => 8,
            'read-only' => 8,
            'clear' => 9,
            'read-auto' => 10,
            'write-pending' => 11,
            'active-idle' => 12,
            'suspended' => 13,
        ];

        return $operationMap[$operation] ?? -1;
    }

    private function mapDeviceHealth(array $device): int
    {
        // md device state is represented by both flags and a free-form state string.
        // We resolve in ordered groups to preserve intent and avoid false positives:
        // missing/fault flags first, then explicit rebuild/recover text (which should
        // beat spare), then normal healthy/activity flags, then exact state fallback.
        if (($device['is_missing'] ?? null) === true) {
            return 3;
        }

        $flags = array_map('strtolower', $device['state_flags'] ?? []);
        $state = strtolower(trim((string) ($device['state'] ?? '')));

        $flagMap = [
            'faulty' => 4,
            'blocked' => 5,
            'write_error' => 6,
            'want_replacement' => 9,
            'replacement' => 10,
        ];

        foreach ($flagMap as $flag => $value) {
            if (in_array($flag, $flags, true)) {
                return $value;
            }
        }

        $containsStateMap = [
            'rebuild' => 2,
            'recover' => 2,
            'spare' => 1,
            'active sync' => 0,
        ];

        foreach ($containsStateMap as $fragment => $value) {
            if (str_contains($state, $fragment)) {
                return $value;
            }
        }

        $flagFallbackMap = [
            'spare' => 1,
            'in_sync' => 0,
            'clean' => 0,
            'active' => 7,
            'writemostly' => 8,
            'write_mostly' => 8,
        ];

        foreach ($flagFallbackMap as $flag => $value) {
            if (in_array($flag, $flags, true)) {
                return $value;
            }
        }

        $exactStateMap = [
            'clean' => 0,
            'active' => 7,
        ];

        if (isset($exactStateMap[$state])) {
            return $exactStateMap[$state];
        }

        return -1;
    }
}
