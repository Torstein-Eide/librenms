<?php

namespace LibreNMS\Agent\Unix\Mdadm;

use App\Models\StateTranslation;
use LibreNMS\Agent\Application;
use LibreNMS\Enum\Severity;
use LibreNMS\RRD\RrdDefinition;

class Common extends Application
{
    private array $payload = [];
    private array $appdata = [];
    private array $plarray = [];
    private array $Working = [];
    private array $discovery = [
        'sync'        => [],
        'array_count' => 0,
        'arrays'      => [],
    ];


    // -------------------------------------------------------------------------
    // State translation tables
    // -------------------------------------------------------------------------

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
            self::state('Clear', 4, Severity::Error),
            self::state('Inactive', 5, Severity::Error),
            self::state('Suspended', 6, Severity::Error),
            self::state('Readonly', 7, Severity::Warning),
            self::state('Read Auto', 8, Severity::Warning),
            self::state('Write Pending', 9, Severity::Warning),
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
            self::state('Inactive', 7, Severity::Ok),
            self::state('Readonly', 8, Severity::Error),
            self::state('Clear', 9, Severity::Ok),
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
            self::state('Active', 1, Severity::Ok),
            self::state('Write Mostly', 2, Severity::Ok),
            self::state('Spare', 3, Severity::Ok),
            self::state('Rebuilding', 4, Severity::Warning),
            self::state('Want Replacement', 5, Severity::Warning),
            self::state('Replacement', 6, Severity::Warning),
            self::state('Write Error', 7, Severity::Error),
            self::state('Blocked', 8, Severity::Error),
            self::state('Faulty', 9, Severity::Error),
            self::state('Missing', 10, Severity::Error),
            self::state('Unknown', -1, Severity::Unknown),
        ];
    }

    // -------------------------------------------------------------------------
    // Health / operation mappers
    // -------------------------------------------------------------------------

    private function mapArrayHealth(array $array, int $maxDeviceHealth): int
    {
        if (! isset($array['state'], $array['failed_devices'], $array['degraded'])) {
            return -1;
        }

        $arrayState = str_replace('_', '-', strtolower(trim((string) ($array['state'] ?? ''))));

        if ($arrayState === 'clear') {
            return 4;
        }
        if ($arrayState === 'inactive') {
            return 5;
        }
        if ($arrayState === 'suspended') {
            return 6;
        }
        if (in_array($arrayState, ['readonly', 'read-only'], true)) {
            return 7;
        }
        if ($arrayState === 'read-auto') {
            return 8;
        }
        if ($arrayState === 'write-pending') {
            return 9;
        }
        if ($maxDeviceHealth === 10) {
            return 3;
        }
        if ($maxDeviceHealth >= 9) {
            return 2;
        }

        $failedDevices = (int) $array['failed_devices'];
        $degraded = (int) $array['degraded'];
        $activeDevices = (int) ($array['active_devices'] ?? 0);
        $workingDevices = (int) ($array['working_devices'] ?? 0);

        if ($degraded > 0 || $failedDevices > 0) {
            return ($activeDevices === 0 || $workingDevices === 0) ? 2 : 1;
        }

        return 0;
    }

    private function maxKnownDeviceHealth(string $uuid): int
    {
        $values = array_filter(
            $this->Working[$uuid]['devHealth'] ?? [],
            static fn ($v) => is_int($v) && $v >= 0
        );

        return $values === [] ? -1 : max($values);
    }

    private function mapArrayOperation(array $array): int
    {
        $operation = str_replace('_', '-', strtolower(trim((string) ($array['sync']['action'] ?? ''))));
        $operationMap = [
            'idle'        => 0,
            'clean'       => 1,
            'active'      => 2,
            'check'       => 3,
            'resync'      => 4,
            'recover'     => 5,
            'recovery'    => 5,
            'repair'      => 6,
            'active-idle' => 12,
        ];

        return $operationMap[$operation] ?? -1;
    }

    private function mapDeviceHealth(array $device): int
    {
        if (($device['is_missing'] ?? null) === true) {
            return 7;
        }

        $flags = array_map('strtolower', $device['state_flags'] ?? []);
        $state = strtolower(trim((string) ($device['state'] ?? '')));

        foreach (['faulty' => 8, 'blocked' => 9, 'write_error' => 10, 'want_replacement' => 5, 'replacement' => 6] as $flag => $val) {
            if (in_array($flag, $flags, true)) {
                return $val;
            }
        }

        foreach (['rebuild' => 4, 'recover' => 4, 'spare' => 3, 'active sync' => 0] as $fragment => $val) {
            if (str_contains($state, $fragment)) {
                return $val;
            }
        }

        foreach (['spare' => 3, 'in_sync' => 0, 'clean' => 0, 'active' => 1, 'writemostly' => 2, 'write_mostly' => 2] as $flag => $val) {
            if (in_array($flag, $flags, true)) {
                return $val;
            }
        }

        return ['clean' => 0, 'active' => 1][$state] ?? -1;
    }

    public function discover(): void
    {
        $payload = $this->fetchPayload('mdadm', 1);
        if ($payload === null || ($payload['version'] ?? 0) < 3) {
            return;
        }
        $this->initState($payload);
        $this->runDiscovery();
        $data = $this->appdata;
        $data['discovery'] = $this->discovery;
        $this->saveAppData($data);
    }

    public function poll(): void
    {
        $payload = $this->fetchPayload('mdadm', 1);
        if ($payload === null) {
            return;
        }
        $version = $payload['version'] ?? 0;
        if ($version < 2) {
            (new V1($this->os, $this->app, $this->agent_data))->pollLegacy($payload);

            return;
        }
        if ($version < 3) {
            (new V2($this->os, $this->app, $this->agent_data))->pollLegacy($payload);

            return;
        }
        $this->initState($payload);
        $this->runPoll();
        $this->runPollRrd();
        \update_application($this->app, 'ok', $this->collectMetrics());
    }

    private function initState(array $payload): void
    {
        $this->payload = $payload;
        $this->plarray = $payload['data']['tables']['arrays'] ?? [];
        $this->appdata = $this->getAppData();
        $this->discovery = $this->appdata['discovery'] ?? $this->discovery;
    }

    private function runDiscovery(): void
    {
        echo '*';

        $this->discovery = [];
        $this->discovery['array_count'] = $this->payload['data']['counters']['arrays'];
        $this->discovery['device_count'] = $this->payload['data']['counters']['devices_total'] ?? 0;

        app()->forgetInstance('sensor-discovery');

        foreach (array_keys($this->plarray) as $uuid) {
            $this->discovery['arrays'][(string) $uuid] = [
                'devices_count' => count($this->plarray[$uuid]['devices']),
                'devices'       => [],
            ];
            $this->discoveryArray((string) $uuid);
        }

        $this->syncSensors(
            'mdadm_array_health_status',
            'mdadm_array_operation_status',
            'mdadm_array_mismatch',
            'mdadm_device_health_status',
            'mdadm_device_error',
        );

        $this->deleteStaleAgentSensors(
            oidPrefix: 'app:mdadm:',
            knownTypes: ['mdadm_array_health_status', 'mdadm_array_operation_status', 'mdadm_array_mismatch', 'mdadm_device_health_status', 'mdadm_device_error'],
            expectedOids: app('sensor-discovery')->getModels()->pluck('sensor_oid')->all(),
        );
    }

    private function discoveryArray(string $uuid): void
    {
        $array = $this->plarray[$uuid]['array'] ?? [];
        $devices = $this->plarray[$uuid]['devices'] ?? [];
        $this->Working[$uuid]['devHealth'] = [];

        $arrayName = (string) ($array['name'] ?? $uuid);
        $arrayGroup = "Mdadm $arrayName";
        $arrayNav = 'tab=apps/app=mdadm/array=' . rawurlencode($arrayName) . '/';

        $this->discovery['arrays'][$uuid]['name'] = $arrayName;
        $this->discovery['arrays'][$uuid]['devices']['rrdkey'] = substr($uuid, 0, 8);
        $this->Working[$uuid]['arrayNavigation'] = $arrayNav;

        foreach ($devices as $deviceKey => $deviceData) {
            $deviceHealth = $this->mapDeviceHealth(is_array($deviceData) ? $deviceData : []);
            $this->Working[$uuid]['devHealth'][] = $deviceHealth;
            $this->discovery['arrays'][$uuid]['devices'][] = (string) $deviceKey;

            $this->discoveryDevice(
                $uuid,
                (string) $deviceKey,
                is_array($deviceData) ? $deviceData : [],
                $arrayGroup,
                $arrayNav,
                $deviceHealth
            );
        }

        $maxDeviceHealth = $this->maxKnownDeviceHealth($uuid);
        $arrayHealthIndex = $uuid . '_health';
        $arrayOperationIndex = $uuid . '_operation';
        $arrayMismatchIndex = $uuid . '_mismatch';

        $this->discoverSensor(
            class: 'count',
            type: 'mdadm_array_mismatch',
            index: $arrayMismatchIndex,
            oid: "app:mdadm:$arrayMismatchIndex",
            descr: "$arrayGroup Mismatch",
            current: (int) ($array['mismatch_cnt'] ?? 0),
            group: $arrayGroup,
            navigation: $arrayNav,
            highLimit: 1,
        );

        $this->discoverSensor(
            class: 'state',
            type: 'mdadm_array_operation_status',
            index: $arrayOperationIndex,
            oid: "app:mdadm:$arrayOperationIndex",
            descr: "$arrayGroup Operation",
            current: $this->mapArrayOperation($array),
            group: $arrayGroup,
            navigation: $arrayNav,
        )->withStateTranslations('mdadm_array_operation_status', $this->arrayOperationTranslations());

        $this->discoverSensor(
            class: 'state',
            type: 'mdadm_array_health_status',
            index: $arrayHealthIndex,
            oid: "app:mdadm:$arrayHealthIndex",
            descr: "$arrayGroup Health",
            current: $this->mapArrayHealth($array, $maxDeviceHealth),
            group: $arrayGroup,
            navigation: $arrayNav,
        )->withStateTranslations('mdadm_array_health_status', $this->arrayHealthTranslations());
    }

    private function discoveryDevice(
        string $uuid,
        string $devId,
        array $deviceData,
        string $arrayGroup,
        string $arrayNav,
        int $deviceHealth
    ): void {
        $deviceHealthIndex = $uuid . '_' . $devId . '_health';
        $deviceErrorsIndex = $uuid . '_' . $devId . '_errors';

        $this->discoverSensor(
            class: 'state',
            type: 'mdadm_device_health_status',
            index: $deviceHealthIndex,
            oid: "app:mdadm:$deviceHealthIndex",
            descr: "$arrayGroup $devId Health",
            current: $deviceHealth,
            group: "$arrayGroup::devices",
            navigation: $arrayNav,
        )->withStateTranslations('mdadm_device_health_status', $this->deviceHealthTranslations());

        $this->discoverSensor(
            class: 'count',
            type: 'mdadm_device_error',
            index: $deviceErrorsIndex,
            oid: "app:mdadm:$deviceErrorsIndex",
            descr: "$arrayGroup $devId errors",
            current: (int) ($deviceData['errors'] ?? 0),
            group: "$arrayGroup::devices",
            navigation: $arrayNav,
        );
    }

    private function runPoll(): void
    {
        $sensorValues = [];

        foreach (($this->discovery['arrays'] ?? []) as $uuid => $discoveryArray) {
            $array = $this->plarray[$uuid]['array'] ?? [];
            $devices = $this->plarray[$uuid]['devices'] ?? [];
            $this->Working[$uuid]['devHealth'] = [];

            foreach (($discoveryArray['devices'] ?? []) as $devId) {
                $dev = $devices[$devId] ?? [];
                $deviceHealth = $this->mapDeviceHealth($dev);
                $this->Working[$uuid]['devHealth'][] = $deviceHealth;
                $sensorValues[$uuid . '_' . $devId . '_health'] = $deviceHealth;
                $sensorValues[$uuid . '_' . $devId . '_errors'] = (int) ($dev['errors'] ?? 0);
            }

            $maxDeviceHealth = $this->maxKnownDeviceHealth($uuid);
            $sensorValues[$uuid . '_health'] = $this->mapArrayHealth($array, $maxDeviceHealth);
            $sensorValues[$uuid . '_operation'] = $this->mapArrayOperation($array);
            $sensorValues[$uuid . '_mismatch'] = (int) ($array['mismatch_cnt'] ?? 0);
        }

        $this->updateSensorValues($sensorValues, 'app:mdadm:');

        $rawArrays = [];
        foreach ($this->plarray as $uuid => $entry) {
            $rawArrays[$uuid] = [
                'array'   => $entry['array'] ?? [],
                'devices' => is_array($entry['devices'] ?? null) ? $entry['devices'] : [],
            ];
        }

        $data = $this->appdata;
        $data['discovery'] = $this->discovery;
        $data['arrays'] = $rawArrays;
        $this->saveAppData($data);
    }

    private function runPollRrd(): void
    {
        foreach (($this->discovery['arrays'] ?? []) as $uuid => $_) {
            $array = $this->plarray[$uuid]['array'] ?? [];
            $arrayName = (string) ($array['name'] ?? '');
            if ($arrayName === '') {
                continue;
            }

            $sync = $array['sync'] ?? [];
            $syncAction = strtolower(trim((string) ($sync['action'] ?? 'idle')));
            $isSyncing = $syncAction !== '' && $syncAction !== 'idle';
            $appId = $this->app->app_id;

            $prevSync = ($this->appdata['arrays'][$uuid]['array']['sync'] ?? []) ?? null;
            $this->logIfChanged($arrayName, 'sync action', $prevSync['action'] ?? null, $syncAction);
            $this->logIfChanged($arrayName, 'speed limit min', $prevSync['speed_min_bps'] ?? null, (int) ($sync['speed_min_bps'] ?? 0));
            $this->logIfChanged($arrayName, 'speed limit max', $prevSync['speed_max_bps'] ?? null, (int) ($sync['speed_max_bps'] ?? 0));

            $this->putRrd('app', [
                'name'     => 'mdadm',
                'app_id'   => $appId,
                'rrd_def'  => RrdDefinition::make()
                    ->addDataset('active', 'GAUGE', 0)
                    ->addDataset('spare', 'GAUGE', 0)
                    ->addDataset('failed', 'GAUGE', 0)
                    ->addDataset('degraded', 'GAUGE', 0)
                    ->addDataset('mismatch', 'GAUGE', 0)
                    ->addDataset('done_sectors', 'DERIVE', 0)
                    ->addDataset('completed_pct', 'GAUGE', 0, 100)
                    ->addDataset('speed_bps', 'GAUGE', 0),
                'rrd_name' => ['app', 'mdadm', $appId, $arrayName],
            ], [
                'active'        => (int) ($array['active_devices'] ?? null),
                'spare'         => (int) ($array['spare_devices'] ?? null),
                'failed'        => (int) ($array['failed_devices'] ?? null),
                'degraded'      => (int) ($array['degraded'] ?? null),
                'mismatch'      => (int) ($array['mismatch_cnt'] ?? null),
                'done_sectors'  => $isSyncing ? (int) ($sync['done_bytes'] ?? null): null,
                'completed_pct' => (float) ($sync['completed_pct'] ?? null),
                'speed_bps'     => (int) ($sync['speed_bps'] ?? null),
            ]);
        }
    }

    private function collectMetrics(): array
    {
        $counters = $this->payload['data']['counters'] ?? [];
        $metrics = [
            'arrays'          => (int) ($counters['arrays'] ?? 0),
            'arrays_syncing'  => (int) ($counters['arrays_syncing'] ?? 0),
            'degraded_arrays' => (int) ($counters['degraded_arrays'] ?? 0),
            'devices_total'   => (int) ($counters['devices_total'] ?? 0),
        ];

        foreach ($this->plarray as $uuid => $entry) {
            $array = $entry['array'] ?? [];
            $arrayName = (string) ($array['name'] ?? $uuid);
            if ($arrayName === '') {
                continue;
            }
            $sync = $array['sync'] ?? [];
            $metrics[$arrayName] = [
                'active_devices'     => (int) ($array['active_devices'] ?? 0),
                'spare_devices'      => (int) ($array['spare_devices'] ?? 0),
                'failed_devices'     => (int) ($array['failed_devices'] ?? 0),
                'working_devices'    => (int) ($array['working_devices'] ?? 0),
                'sync_completed_pct' => (float) ($sync['completed_pct'] ?? 0),
            ];
        }

        return $metrics;
    }

    private function logIfChanged(string $arrayName, string $label, mixed $prev, mixed $curr): void
    {
        if ($prev === null || $prev === $curr) {
            return;
        }
        $this->logEvent('notice', "mdadm $arrayName: $label changed ($prev -> $curr)");
    }

} 