<?php

namespace LibreNMS\Agent\Module;

use App\Models\Application;
use App\Models\Device;
use App\Models\Sensor;
use App\Models\StateTranslation;
use Illuminate\Support\Facades\Cache;
use LibreNMS\Enum\Severity;
use LibreNMS\Exceptions\JsonAppMissingKeysException;
use LibreNMS\OS;
use LibreNMS\Polling\Modules\NutRrdWriter;
use LibreNMS\RRD\RrdDefinition;

class NutPoller
{
    // $NewData[$ups]['Battery']['Charge'|'Runtime'|'Type'|'Charge_low']
    // $NewData[$ups]['Output']['Voltage'|'Frequency'|'Voltage_nominal'|'Voltage']
    // $NewData[$ups]['Input']['Voltage']|'Frequency'|'Frequency_nominal'|'Voltage'|Transfer_high'|'Transfer_low']
    // $NewData[$ups]['Ups']['Load'|'Realpower'|'Status'|'Beeper_status']
    // $NewData[$ups]['Device']['Mfr'|'Model'|'Serial']
    public array $NewData = [];
    // ['ups']  Current UPS being processed

    public array $working = ['ups' => null, 'status' => false]; // contrains current working state, like current UPS being processed, and if we have valid data to write or not (used to set application status at the end)

    private Application $app;
    private array $payload;
    private array $device;

    private int $tick = 0;
    private int $Discoverytick = 3; // Discovery every 3rd poll
    private array $appData = []; // Loaded from $app->data, will be modified and saved back at the end

    private array $discovery = [];

    private const VERSION = 2;
    private const SCHEMA_VERSION = 1;

    private const STATE_MAPPING = [
        'OL' => ['value' => 1, 'name' => 'Online', 'descr' => 'ONLINE - The UPS is running on mains power.'],
        'OB' => ['value' => 2, 'name' => 'On Battery', 'descr' => 'ONBATT - The UPS is running on battery power.'],
        'LB' => ['value' => 3, 'name' => 'Battery Low', 'descr' => 'LOWBATT - The battery is low (often used as a trigger for shutdown).'],
        'HB' => ['value' => 4, 'name' => 'Battery High', 'descr' => 'HIGHBATT - The battery is high.'],
        'RB' => ['value' => 5, 'name' => 'Replace Battery', 'descr' => 'REPLBATT - The battery needs to be replaced.'],
        'CHRG' => ['value' => 6, 'name' => 'Charging', 'descr' => 'CHARGING - The battery is charging.'],
        'DISCHRG' => ['value' => 7, 'name' => 'Discharging', 'descr' => 'DISCHARGING - The battery is discharging.'],
        'BYPASS' => ['value' => 8, 'name' => 'Bypass', 'descr' => 'BYPASS - The UPS is on bypass mode (load is fed from input).'],
        'OVER' => ['value' => 9, 'name' => 'Overload', 'descr' => 'OVERLOAD - The UPS is overloaded.'],
        'TRIM' => ['value' => 10, 'name' => 'Trim Voltage', 'descr' => 'TRIM - The UPS is trimming incoming voltage.'],
        'BOOST' => ['value' => 11, 'name' => 'Boost Voltage', 'descr' => 'BOOST - The UPS is boosting incoming voltage.'],
        'ALARM' => ['value' => 12, 'name' => 'ALARM', 'descr' => 'ALARM - The UPS has active alarms.'],
        'FSD' => ['value' => 13, 'name' => 'Forced Shutdown', 'descr' => 'FSD - Forced Shutdown (usually set by upsmon to indicate a shutdown is in progress)'],
    ];

    public static function poll(Application $app, array $device): void
    {
        $poller = new self($app, $device);
        $poller->run();
    }

    // phpcs:ignore PHPStorm.Constructor.unusedParameter
    public function __construct(Application $app, array $device)
    {
        $this->app = $app;
        $this->device = $device;
    }

    private function run(): void
    {
        $this->loadAppData(); // from Database previous polls, used for discovery and to determine if discovery is needed on this poll
        $this->loadAgentData(); // from Agent JSON, used for processing and discovery on this poll

        // Determine if discovery is needed on this poll based on previous discovery data and current payload
        if ($this->Checkiftimefordiscovery()) {
            $this->discovery['tick'] = $this->discovery['tick'] + 1;
        } else {
            echo '<!-- NutPoller: discovery will run on this poll -->' . "\n";

            // store new discovery data for this UPS
            $this->discovery['counter'] = $this->payload['count'] ?? 0;
            $this->discovery['ups_list'] = [];
            foreach ($this->payload['data'] ?? [] as $upsName => $upsData) {
                $this->discovery['ups_list'][$upsName] = [
                    'Device' => $upsData['Device'] ?? [],
                ];
            }
            $this->Discovery();
            $this->discovery['tick'] = 0;
        }

        // Process each UPS
        foreach ($this->discovery['ups_list'] as $upsName => $upsInfo) {
            $this->working['ups'] = $upsName;
            $this->pollerUpdate($upsName);
        }

        $this->saveAppData();
        $this->updateApplication();
    }

    private function loadAppData(): void
    {
        $data = $this->app->data;
        if (is_array($data)) {
            $this->appData = $data;
        }

        $this->discovery = $this->appData['Discovery'] ?? [];
    }

    private function loadAgentData(): void
    {
        try {
            $this->payload = json_app_get($this->device, 'ups-nut', self::VERSION);
        } catch (JsonAppMissingKeysException $e) {
            update_application($this->app, $e->getCode() . ':' . $e->getMessage(), []);

            return;
        }

        if (empty($this->payload['data'])) {
            update_application($this->app, 'ERROR: No data', []);

            return;
        }
    }

    private function saveAppData(): void
    {
        $this->appData['Discovery'] = $this->discovery;

        if (isset($this->payload['data'])) {
            $this->appData['data'] = $this->payload['data'];

        }
        $this->appData['version'] = self::VERSION;
        $this->appData['schema_version'] = self::SCHEMA_VERSION;

        $this->app->data = $this->appData;
        $this->app->save();
    }

    private function updateApplication(): void
    {
        $metrics = [];

        foreach ($this->NewData as $upsName => $data) {
            $metrics["{$upsName}_load"] = $data['ups']['load'] ?? 0;
            $metrics["{$upsName}_charge"] = $data['battery']['charge'] ?? 0;
            $metrics["{$upsName}_runtime"] = $data['battery']['runtime'] ?? 0;
        }

        $status = $this->working['status'] ? 'OK' : 'ERROR';
        update_application($this->app, $status, $metrics);
    }

    private function getSeverityForState(int $value): Severity
    {
        return match ($value) {
            1 => Severity::Ok,
            2, 3, 5, 8, 9, 12, 13 => Severity::Error,
            4, 6, 7, 10, 11 => Severity::Warning,
            default => Severity::Unknown,
        };
    }

    ////////////////////////////////////////////////////
    // Discovery
    //
    // Discovery is responsible for creating/updating sensors based on the UPS devices we have in the payload. It runs on the first poll and then every 72rd poll, or immediately if we detect new UPS devices in the payload.
    //
    // It stops loss if status if bad payload
    // it reduse some cycle time by skipping if no new devices and interval not reached
    // It creates/updates sensors for all UPS devices in the payload, and removes sensors for UPS devices that are no longer in the payload. It also removes old legacy sensors created by SNMP poller.

    private function Discovery(): void
    {
        $this->discovery['ups'] = [];

        foreach (array_keys($this->discovery['ups_list']) as $upsName) {
            $this->working['ups'] = $upsName;
            $this->discoverSensors($upsName);
            $this->cleanupRemovedSensors();
            $this->cleanupLegacySensors();
        }
    }

    private function Checkiftimefordiscovery(): bool
    {
        // If we have no previous discovery data, we should run discovery to initialize it
        if (empty($this->appData['Discovery'])) {
            return false;  // force: first poll
        }
        // If we have more devices than last discovery, run discovery immediately to catch new devices faster
        if ($this->discovery['counter'] >= $this->payload['count']) {
            return false; // force: new devices
        }
        // poll_count is reset to 0 each time discovery runs, so it means
        // "polls since last discovery". Skip until we reach the interval.
        if ($this->discovery['tick'] < $this->Discoverytick) {
            return true;   // timer not reached
        }

        return false;  // run discovery
    }

    private function discoverSensors(string $upsName): void
    {
        //$upsName = $this->working['ups'];
        $data = $this->payload['data'][$upsName] ?? [];

        // discoverNumericSensor(
        // string $upsName,
        // string $modelPrefix,
        // string $type,
        // string $class,
        // string $descr,
        // ?float $min = 0,
        // ?float $max = null

        // Get model name for sensor descriptions
        $modelName = $data['device']['model'] ?? '';

        // State sensors (keep these)
        $this->discoverStateSensor($upsName, 'ups_status', $data['ups']['status'] ?? 'OL', self::STATE_MAPPING);
        $this->discovery['ups'][$upsName]['sensors'][] = 'ups_status';
        $this->discovery['ups'][$upsName]['sensor_oid']['ups_status'] = 'app:nut:' . $upsName . '_ups_status';

        // Numeric sensors - only keep relevant ones

        // - sensor_limit - High alert (critical)
        // - sensor_limit_warn - High warning
        // - sensor_limit_low - Low alert (critical)
        // - sensor_limit_low_warn - Low warning

        if (! empty($data['ups']['load'])) {
            $this->discoverLoad($upsName, $modelName, $data);
            $this->discovery['ups'][$upsName]['sensors'][] = 'load';
            $this->discovery['ups'][$upsName]['sensor_oid']['load'] = 'app:nut:' . $upsName . '_load';
        }

        if (! empty($data['battery']['charge'])) {
            $this->discoverCharge($upsName, $modelName, $data);
            $this->discovery['ups'][$upsName]['sensors'][] = 'charge';
            $this->discovery['ups'][$upsName]['sensor_oid']['charge'] = 'app:nut:' . $upsName . '_charge';
        }

        if (! empty($data['battery']['runtime'])) {
            $this->discoverRuntime($upsName, $modelName, $data);
            $this->discovery['ups'][$upsName]['sensors'][] = 'runtime';
            $this->discovery['ups'][$upsName]['sensor_oid']['runtime'] = 'app:nut:' . $upsName . '_runtime';
        }

        if (! empty($data['ups']['realpower'])) {
            $this->discoverRealpower($upsName, $modelName, $data);
            $this->discovery['ups'][$upsName]['sensors'][] = 'realpower';
            $this->discovery['ups'][$upsName]['sensor_oid']['realpower'] = 'app:nut:' . $upsName . '_realpower';
        }

        if (! empty($data['output']['frequency'])) {
            $this->discoverOutputFrequency($upsName, $modelName, $data);
            $this->discovery['ups'][$upsName]['sensors'][] = 'output_frequency';
            $this->discovery['ups'][$upsName]['sensor_oid']['output_frequency'] = 'app:nut:' . $upsName . '_output_frequency';
        }

        if (! empty($data['output']['voltage'])) {
            $this->discoverOutputVoltage($upsName, $modelName, $data);
            $this->discovery['ups'][$upsName]['sensors'][] = 'output_voltage';
            $this->discovery['ups'][$upsName]['sensor_oid']['output_voltage'] = 'app:nut:' . $upsName . '_output_voltage';
        }

        if (! empty($data['input']['voltage'])) {
            $this->discoverInputVoltage($upsName, $modelName, $data);
            $this->discovery['ups'][$upsName]['sensors'][] = 'input_voltage';
            $this->discovery['ups'][$upsName]['sensor_oid']['input_voltage'] = 'app:nut:' . $upsName . '_input_voltage';
        }

        if (! empty($data['battery']['voltage'])) {
            $this->discoverBatteryVoltage($upsName, $modelName, $data);
            $this->discovery['ups'][$upsName]['sensors'][] = 'battery_voltage';
            $this->discovery['ups'][$upsName]['sensor_oid']['battery_voltage'] = 'app:nut:' . $upsName . '_battery_voltage';
        }
    }

    private function discoverStateSensor(string $upsName, string $type, string $value, array $mapping): void
    {
        $sensorIndex = "{$upsName}_{$type}";

        // Check if mapping uses nested array format or simple key=>value format
        $firstValue = array_values($mapping)[0];
        $isNestedFormat = is_array($firstValue);

        $sensorCurrent = $isNestedFormat ? ($mapping[$value]['value'] ?? 1) : ($mapping[$value] ?? 1);

        // Create state translations first (like btrfs)
        foreach ($mapping as $state => $info) {
            if ($isNestedFormat) {
                $stateValue = $info['value'] ?? 1;
                $stateDescr = $info['descr'] ?? $state;
            } else {
                $stateValue = is_array($info) ? ($info['value'] ?? 1) : $info;
                $stateDescr = $state;
            }

            StateTranslation::firstOrCreate(
                [
                    'state_value' => $stateValue,
                ],
                [
                    'state_descr' => $stateDescr,
                    'state_draw_graph' => true,
                    'state_generic_value' => match ($this->getSeverityForState($stateValue)) {
                        Severity::Ok => 0,
                        Severity::Warning => 1,
                        Severity::Error => 2,
                        default => 3,
                    },
                ]
            );
        }

        // Use withoutGlobalScopes and proper sensor_oid like btrfs does
        $sensor = $this->upsertSensor(
            [
                'device_id' => $this->device['device_id'],
                'sensor_class' => 'state',
                'sensor_type' => "{$type}",
                'sensor_index' => $sensorIndex,
                'poller_type' => 'app',
            ],
            [
                'sensor_oid' => 'app:nut:' . $sensorIndex,
                'sensor_descr' => "NUT {$upsName} status",
                'sensor_divisor' => 1,
                'sensor_multiplier' => 1,
                'sensor_current' => $sensorCurrent,
            ]
        );
    }

    private function cleanupRemovedSensors(): void
    {
        // Cleanup old legacy sensors from SNMP polling (first discovery run only)

        $removedUps = array_diff(array_keys($this->discovery['ups_list']), array_keys($this->payload['data'] ?? []));

        foreach ($removedUps as $removedUpsName) {
            Sensor::where('device_id', $this->device['device_id'])
                ->where('sensor_index', 'like', "{$removedUpsName}_%")
                ->delete();
        }

        // Collect all sensor_oids known from current discovery
        $knownOids = [];
        foreach ($this->discovery['ups'] ?? [] as $upsInfo) {
            foreach ($upsInfo['sensor_oid'] ?? [] as $oid) {
                $knownOids[] = $oid;
            }
        }

        // Find sensors in DB that are no longer in discovery and delete them
        $allSensors = Sensor::where('device_id', $this->device['device_id'])
            ->where('sensor_oid', 'like', 'app:nut:%')
            ->get(['sensor_id', 'sensor_oid', 'sensor_class', 'poller_type']);

        $staleIds = $allSensors
            ->filter(fn ($s) => ! in_array($s->sensor_oid, $knownOids))
            ->pluck('sensor_id');

        if ($staleIds->isNotEmpty()) {
            Sensor::whereIn('sensor_id', $staleIds)->delete();
        }
    }

    private function cleanupLegacySensors(): void
    {
        // Remove old legacy sensors created by SNMP poller
        // These have sensor_class in ['charge', 'load', 'runtime'] etc and poller_type != 'app'
        $legacySensorClasses = ['charge', 'load', 'runtime', 'voltage', 'power', 'frequency', 'state'];

        foreach ($legacySensorClasses as $class) {
            $deleted = Sensor::where('device_id', $this->device['device_id'])
                ->where('sensor_class', $class)
                ->where('poller_type', '!=', 'app')
                ->where('sensor_descr', 'like', 'NUT%')
                ->delete();

            if ($deleted > 0) {
            }
        }
    }

    private function upsertSensor(array $criteria, array $values): Sensor
    {
        return Sensor::withoutGlobalScopes()->updateOrCreate($criteria, $values);
    }

    private function discoverLoad(string $upsName, string $modelName, array $data): void
    {
        $sensorIndex = "{$upsName}_load";
        $this->upsertSensor(
            ['device_id' => $this->device['device_id'],
                'sensor_class' => 'load',
                'sensor_index' => $sensorIndex,
                'poller_type' => 'app'],

            ['sensor_oid' => 'app:nut:' . $sensorIndex,
                'sensor_type' => '',
                'sensor_descr' => "UPS {$modelName}",
                'sensor_divisor' => 1,
                'sensor_multiplier' => 1,
                'sensor_current' => $data['ups']['load'],
                'sensor_min' => 0,
                'sensor_max' => 500,
                'sensor_limit_warn' => 80,
                'sensor_limit' => 90]
        );
    }

    private function discoverCharge(string $upsName, string $modelName, array $data): void
    {
        $sensorIndex = "{$upsName}_charge";
        $this->upsertSensor(
            ['device_id' => $this->device['device_id'],
                'sensor_class' => 'charge',
                'sensor_index' => $sensorIndex,
                'poller_type' => 'app'],

            ['sensor_oid' => 'app:nut:' . $sensorIndex,
                'sensor_type' => '',
                'sensor_descr' => "UPS {$modelName} battery",
                'sensor_divisor' => 1,
                'sensor_multiplier' => 1,
                'sensor_current' => $data['battery']['charge'],
                'sensor_min' => 0,
                'sensor_max' => 1000,
                'sensor_limit_low_warn' => $data['battery']['charge_low'] ?? 20]
        );
    }

    private function discoverRuntime(string $upsName, string $modelName, array $data): void
    {
        $sensorIndex = "{$upsName}_runtime";
        $this->upsertSensor(
            ['device_id' => $this->device['device_id'],
                'sensor_class' => 'runtime',
                'sensor_index' => $sensorIndex,
                'poller_type' => 'app'],

            ['sensor_oid' => 'app:nut:' . $sensorIndex,
                'sensor_type' => '',
                'sensor_descr' => "UPS {$modelName}",
                'sensor_divisor' => 60,
                'sensor_multiplier' => 1,
                'sensor_current' => $data['battery']['runtime'] / 60,
                'sensor_min' => 0]
        );
    }

    private function discoverRealpower(string $upsName, string $modelName, array $data): void
    {
        $sensorIndex = "{$upsName}_realpower";
        $this->upsertSensor(
            ['device_id' => $this->device['device_id'],
                'sensor_class' => 'power',
                'sensor_index' => $sensorIndex,
                'poller_type' => 'app'],

            ['sensor_oid' => 'app:nut:' . $sensorIndex,
                'sensor_type' => '',
                'sensor_descr' => "UPS {$modelName} output",
                'sensor_divisor' => 1,
                'sensor_multiplier' => 1,
                'sensor_current' => $data['ups']['realpower'],
                'sensor_min' => 0,
                'sensor_max' => $data['ups']['power_nominal'] ?? null]
        );
    }

    private function discoverOutputFrequency(string $upsName, string $modelName, array $data): void
    {
        $sensorIndex = "{$upsName}_output_frequency";
        $this->upsertSensor(
            ['device_id' => $this->device['device_id'],
                'sensor_class' => 'frequency',
                'sensor_index' => $sensorIndex,
                'poller_type' => 'app'],

            ['sensor_oid' => 'app:nut:' . $sensorIndex,
                'sensor_type' => '',
                'sensor_descr' => "UPS {$modelName} output",
                'sensor_divisor' => 1,
                'sensor_multiplier' => 1,
                'sensor_current' => $data['output']['frequency'],
                'sensor_min' => 0,
                'sensor_limit' => ($data['output']['frequency_nominal'] ?? 0) + 2.5,
                'sensor_limit_warn' => ($data['output']['frequency_nominal'] ?? 0) + 0.2,
                'sensor_limit_low_warn' => ($data['output']['frequency_nominal'] ?? 0) - 0.2]
        );
    }

    private function discoverOutputVoltage(string $upsName, string $modelName, array $data): void
    {
        $sensorIndex = "{$upsName}_output_voltage";
        $this->upsertSensor(
            ['device_id' => $this->device['device_id'],
                'sensor_class' => 'voltage',
                'sensor_index' => $sensorIndex,
                'poller_type' => 'app'],

            ['sensor_oid' => 'app:nut:' . $sensorIndex,
                'sensor_type' => '',
                'sensor_descr' => "UPS {$modelName} output",
                'sensor_divisor' => 1,
                'sensor_multiplier' => 1,
                'sensor_current' => $data['output']['voltage'],
                'sensor_min' => 0,
                'sensor_limit_warn' => ($data['output']['voltage_nominal'] ?? 0) * 1.1,
                'sensor_limit_low_warn' => ($data['output']['voltage_nominal'] ?? 0) * 0.9]
        );
    }

    private function discoverInputVoltage(string $upsName, string $modelName, array $data): void
    {
        $sensorIndex = "{$upsName}_input_voltage";
        $this->upsertSensor(
            ['device_id' => $this->device['device_id'],
                'sensor_class' => 'voltage',
                'sensor_index' => $sensorIndex,
                'poller_type' => 'app'],

            ['sensor_oid' => 'app:nut:' . $sensorIndex,
                'sensor_type' => '',
                'sensor_descr' => "UPS {$modelName} input",
                'sensor_divisor' => 1,
                'sensor_multiplier' => 1,
                'sensor_current' => $data['input']['voltage'],
                'sensor_min' => 0,
                'sensor_limit_low_warn' => ($data['input']['voltage_transfer_low'] ?? $data['input']['voltage_nominal'] ?? $data['input']['voltage'] ?? 0) * 0.9,
                'sensor_limit_warn' => ($data['input']['voltage_transfer_high'] ?? $data['input']['voltage_nominal'] ?? $data['input']['voltage'] ?? 0) * 1.1]
        );
    }

    private function discoverBatteryVoltage(string $upsName, string $modelName, array $data): void
    {
        $sensorIndex = "{$upsName}_battery_voltage";
        $this->upsertSensor(
            ['device_id' => $this->device['device_id'],
                'sensor_class' => 'voltage',
                'sensor_index' => $sensorIndex,
                'poller_type' => 'app'],

            ['sensor_oid' => 'app:nut:' . $sensorIndex,
                'sensor_type' => '',
                'sensor_descr' => "UPS {$modelName} battery",
                'sensor_divisor' => 1,
                'sensor_multiplier' => 1,
                'sensor_current' => $data['battery']['voltage'],
                'sensor_min' => 0,
                'sensor_max' => $data['battery']['voltage'] * 10,
            ]
        );
    }

    /////////////////////////////////////////////////
    // Poller update
    //
    // Poller update is responsible for updating sensor values based on the current payload. It runs on every poll for each UPS device in the payload, after discovery has run.
    /////////////////////////////////////////////////
    private function pollerUpdate(string $upsName): void
    {
        $this->updateSensors($upsName);
        $this->RrdWriteStats($upsName, $this->payload['data'][$upsName] ?? []);
    }

    private function updateSensors(string $upsName): void
    {
        $data = $this->payload['data'][$upsName] ?? [];
        $sensorTypes = $this->discovery['ups'][$upsName]['sensors'] ?? [];

        // Build the value map for all sensors from the current payload
        $values = [
            'ups_status'       => $data['ups']['status'] ?? 'OL',
            'load'             => $data['ups']['load'] ?? null,
            'charge'           => $data['battery']['charge'] ?? null,
            'runtime'          => isset($data['battery']['runtime']) ? (int) ($data['battery']['runtime'] / 60) : null,
            'realpower'        => $data['ups']['realpower'] ?? null,
            'output_voltage'   => $data['output']['voltage'] ?? null,
            'output_frequency' => $data['output']['frequency'] ?? null,
            'input_voltage'    => $data['input']['voltage'] ?? null,
            'battery_voltage'  => $data['battery']['voltage'] ?? null,
        ];

        // Load all sensor models for this UPS in one query, keyed by sensor_index
        $indices = array_map(fn ($type) => "{$upsName}_{$type}", $sensorTypes);
        $sensorModels = Sensor::where('device_id', $this->device['device_id'])
            ->whereIn('sensor_index', $indices)
            ->get()
            ->keyBy('sensor_index');

        foreach ($sensorTypes as $type) {
            $sensor = $sensorModels["{$upsName}_{$type}"] ?? null;
            if (! $sensor) {
                continue;
            }

            if ($type === 'ups_status') {
                $this->updateStateSensor($sensor, $values['ups_status'], self::STATE_MAPPING);
            } else {
                $this->updateNumericSensor($sensor, $values[$type] ?? null);
            }
        }
    }

    // Update a numeric sensor value in the DB and write its individual sensor RRD.
    // The sensor poller skips poller_type='app' sensors, so we must call app('Datastore')->put()
    // ourselves to create/update the per-sensor RRD (sensor/{class}/{type}/{index}.rrd).
    // Null means the UPS does not report this value; RRD stores U (unknown) for that poll.
    private function updateNumericSensor(Sensor $sensor, ?float $value): void
    {
        $sensor->sensor_current = $value;
        $sensor->save();

        $tags = [
            'sensor_class' => $sensor->sensor_class,
            'sensor_type'  => $sensor->sensor_type,
            'sensor_descr' => $sensor->sensor_descr,
            'sensor_index' => $sensor->sensor_index,
            'rrd_name'     => ['sensor', $sensor->sensor_class, $sensor->sensor_type, $sensor->sensor_index],
            'rrd_def'      => RrdDefinition::make()->addDataset('sensor', 'GAUGE'),
        ];
        app('Datastore')->put($this->device, 'sensor', $tags, ['sensor' => $value]);
    }

    // Update a state sensor value in the DB and write its individual sensor RRD.
    // $value is the raw NUT status string (e.g. 'OL', 'OB'); $mapping translates it
    // to a numeric value for storage. sensor_prev is also updated so state-change
    // event logging works correctly on the next poll.
    private function updateStateSensor(Sensor $sensor, string $value, ?array $mapping): void
    {
        // Map NUT status string to numeric state value (e.g. 'OL' -> 1)
        $currentValue = $mapping[$value]['value'] ?? 1;

        $sensor->sensor_current = $currentValue;
        $sensor->sensor_prev = $currentValue;
        $sensor->save();

        $tags = [
            'sensor_class' => $sensor->sensor_class,
            'sensor_type'  => $sensor->sensor_type,
            'sensor_descr' => $sensor->sensor_descr,
            'sensor_index' => $sensor->sensor_index,
            'rrd_name'     => ['sensor', $sensor->sensor_class, $sensor->sensor_type, $sensor->sensor_index],
            'rrd_def'      => RrdDefinition::make()->addDataset('sensor', 'GAUGE'),
        ];
        app('Datastore')->put($this->device, 'sensor', $tags, ['sensor' => $currentValue]);
    }

    private function RrdWriteStats(string $upsName, array $data): void
    {
        $writer = new NutRrdWriter();
        $fields = $writer->buildFields($data);
        $tags = ['rrd_name' => ['app', 'ups-nut', $this->app->app_id, $upsName]];

        $writer->write($this->device, 'ups-nut', $this->app->app_id, $upsName, $fields, $tags);
    }
}
