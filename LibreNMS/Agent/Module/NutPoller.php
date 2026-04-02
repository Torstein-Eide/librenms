<?php

namespace LibreNMS\Agent\Module;

use App\Models\Application;
use App\Models\Sensor;
use App\Models\StateTranslation;
use LibreNMS\Enum\Severity;
use LibreNMS\Exceptions\JsonAppMissingKeysException;
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
            foreach ($this->payload['data'] ?? [] as $upsID => $upsData) {
                $this->discovery['ups_list'][$upsID] = [
                    'device' => $upsData['device'] ?? [],
                ];
            }
            $this->Discovery();
            $this->discovery['tick'] = 0;
        }

        // Process each UPS
        foreach ($this->discovery['ups_list'] as $upsID => $upsInfo) {
            $this->working['ups'] = $upsID;
            $this->pollerUpdate($upsID);
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

        foreach ($this->NewData as $upsID => $data) {
            $metrics["{$upsID}_load"] = $data['ups']['load'] ?? 0;
            $metrics["{$upsID}_charge"] = $data['battery']['charge'] ?? 0;
            $metrics["{$upsID}_runtime"] = $data['battery']['runtime'] ?? 0;
        }

        update_application($this->app, "oKS", $metrics);
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
        foreach (array_keys($this->discovery['ups_list']) as $upsID) {
            $this->working['ups'] = $upsID;
            $this->discoverSensors($upsID);
            $this->cleanupLegacySensors();
        }

        // Sync all discovered app sensors: creates new, updates existing, deletes sensors
        // for UPS devices no longer in the payload — replaces manual cleanupRemovedSensors().
        app('sensor-discovery')->sync(poller_type: 'app');
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

    private function discoverSensors(string $upsID): void
    {
        $data = $this->payload['data'][$upsID] ?? [];
        $modelName = $data['device']['model'] ?? '';

        // State sensors
        $this->discoverStatusOnline($upsID, $data);
        $this->discoverOutputVoltageRegulation($upsID, $data);
        $this->discoverBatteryCharging($upsID, $data);
        $this->discoverBatteryHealth($upsID, $data);

        // Boolean status sensors — pass the actual data key since casing differs from sensor type name
        $this->discoverStatusBool('status_bypass', 'Bypass', $upsID, $data, 'status_Bypass');
        $this->discoverStatusBool('status_overload', 'Overload', $upsID, $data, 'status_Overload');
        $this->discoverStatusBool('status_alarm', 'Alarm', $upsID, $data, 'status_Alarm');
        $this->discoverStatusBool('status_forced_shutdown', 'Forced Shutdown', $upsID, $data, 'status_Forced_Shutdown');

        // Numeric sensors
        // - sensor_limit - High alert (critical)
        // - sensor_limit_warn - High warning
        // - sensor_limit_low - Low alert (critical)
        // - sensor_limit_low_warn - Low warning

        if (! empty($data['ups']['load'])) {
            $this->discoverLoad($upsID, $modelName, $data);
        }
        if (! empty($data['battery']['charge'])) {
            $this->discoverCharge($upsID, $modelName, $data);
        }
        if (! empty($data['battery']['runtime'])) {
            $this->discoverRuntime($upsID, $modelName, $data);
        }
        if (! empty($data['ups']['realpower'])) {
            $this->discoverRealpower($upsID, $modelName, $data);
        }
        if (! empty($data['output']['frequency'])) {
            $this->discoverOutputFrequency($upsID, $modelName, $data);
        }
        if (! empty($data['output']['voltage'])) {
            $this->discoverOutputVoltage($upsID, $modelName, $data);
        }
        if (! empty($data['input']['voltage'])) {
            $this->discoverInputVoltage($upsID, $modelName, $data);
        }
        if (! empty($data['battery']['voltage'])) {
            $this->discoverBatteryVoltage($upsID, $modelName, $data);
        }
    }

    private function cleanupLegacySensors(): void
    {
        // Remove old legacy sensors created by SNMP poller
        // These have sensor_class in numeric classes and poller_type != 'app'
        $legacySensorClasses = [
            'charge', 'load', 'runtime', 'voltage', 'power', 'frequency', 'state',
            'output_voltage_regulation', 'battery_charging', 'battery_health',
            'status_online', 'status_bypass', 'status_overload', 'status_alarm', 'status_forced_shutdown',
        ];

        foreach ($legacySensorClasses as $class) {
            $sensors = Sensor::where('device_id', $this->device['device_id'])
                ->where('sensor_class', $class)
                ->where('poller_type', '!=', 'app')
                ->where('sensor_descr', 'like', 'NUT%')
                ->get(['sensor_id', 'sensor_index', 'sensor_descr']);

            foreach ($sensors as $sensor) {
                echo "<!-- Deleting legacy sensor: {$sensor->sensor_index} ({$sensor->sensor_descr}) -->\n";
            }

            $deleted = $sensors->count();
            if ($deleted > 0) {
                Sensor::whereIn('sensor_id', $sensors->pluck('sensor_id'))->delete();
            }
        }
    }

    private function discoverLoad(string $upsID, string $modelName, array $data): void
    {
        app('sensor-discovery')->discover(new Sensor([
            'poller_type' => 'app',
            'sensor_class' => 'load',
            'sensor_index' => "{$upsID}_load",
            'sensor_oid' => "app:nut:{$upsID}_load",
            'sensor_descr' => "UPS {$modelName}",
            'sensor_divisor' => 1,
            'sensor_multiplier' => 1,
            'sensor_current' => $data['ups']['load'],
            'sensor_min' => 0,
            'sensor_max' => 500,
            'sensor_limit_warn' => 80,
            'sensor_limit' => 90,
        ]));
    }

    private function discoverCharge(string $upsID, string $modelName, array $data): void
    {
        app('sensor-discovery')->discover(new Sensor([
            'poller_type' => 'app',
            'sensor_class' => 'charge',
            'sensor_index' => "{$upsID}_charge",
            'sensor_oid' => "app:nut:{$upsID}_charge",
            'sensor_descr' => "UPS {$modelName} battery",
            'sensor_divisor' => 1,
            'sensor_multiplier' => 1,
            'sensor_current' => $data['battery']['charge'],
            'sensor_min' => 0,
            'sensor_max' => 100,
            'sensor_limit_low_warn' => $data['battery']['charge_low'] ?? 20,
        ]));
    }

    private function discoverRuntime(string $upsID, string $modelName, array $data): void
    {
        app('sensor-discovery')->discover(new Sensor([
            'poller_type' => 'app',
            'sensor_class' => 'runtime',
            'sensor_index' => "{$upsID}_runtime",
            'sensor_oid' => "app:nut:{$upsID}_runtime",
            'sensor_descr' => "UPS {$modelName}",
            'sensor_divisor' => 60,
            'sensor_multiplier' => 1,
            'sensor_current' => $data['battery']['runtime'],
            'sensor_min' => 0,
        ]));
    }

    private function discoverRealpower(string $upsID, string $modelName, array $data): void
    {
        app('sensor-discovery')->discover(new Sensor([
            'poller_type' => 'app',
            'sensor_class' => 'power',
            'sensor_index' => "{$upsID}_realpower",
            'sensor_oid' => "app:nut:{$upsID}_realpower",
            'sensor_descr' => "UPS {$modelName} output",
            'sensor_divisor' => 1,
            'sensor_multiplier' => 1,
            'sensor_current' => $data['ups']['realpower'],
            'sensor_min' => 0,
            'sensor_max' => $data['ups']['power_nominal'] ?? null,
        ]));
    }

    private function discoverOutputFrequency(string $upsID, string $modelName, array $data): void
    {
        $nom = $data['output']['frequency_nominal'] ?? 0;
        app('sensor-discovery')->discover(new Sensor([
            'poller_type' => 'app',
            'sensor_class' => 'frequency',
            'sensor_index' => "{$upsID}_output_frequency",
            'sensor_oid' => "app:nut:{$upsID}_output_frequency",
            'sensor_descr' => "UPS {$modelName} output",
            'sensor_divisor' => 1,
            'sensor_multiplier' => 1,
            'sensor_current' => $data['output']['frequency'],
            'sensor_min' => 0,
            'sensor_limit' => $nom + 2.5,
            'sensor_limit_warn' => $nom + 0.2,
            'sensor_limit_low_warn' => $nom - 0.2,
        ]));
    }

    private function discoverOutputVoltage(string $upsID, string $modelName, array $data): void
    {
        $nom = $data['output']['voltage_nominal'] ?? 0;
        app('sensor-discovery')->discover(new Sensor([
            'poller_type' => 'app',
            'sensor_class' => 'voltage',
            'sensor_index' => "{$upsID}_output_voltage",
            'sensor_oid' => "app:nut:{$upsID}_output_voltage",
            'sensor_descr' => "UPS {$modelName} output",
            'sensor_divisor' => 1,
            'sensor_multiplier' => 1,
            'sensor_current' => $data['output']['voltage'],
            'sensor_min' => 0,
            'sensor_limit_warn' => $nom * 1.1,
            'sensor_limit_low_warn' => $nom * 0.9,
        ]));
    }

    private function discoverInputVoltage(string $upsID, string $modelName, array $data): void
    {
        $nomLow = $data['input']['voltage_transfer_low'] ?? $data['input']['voltage_nominal'] ?? $data['input']['voltage'] ?? 0;
        $nomHigh = $data['input']['voltage_transfer_high'] ?? $data['input']['voltage_nominal'] ?? $data['input']['voltage'] ?? 0;
        app('sensor-discovery')->discover(new Sensor([
            'poller_type' => 'app',
            'sensor_class' => 'voltage',
            'sensor_index' => "{$upsID}_input_voltage",
            'sensor_oid' => "app:nut:{$upsID}_input_voltage",
            'sensor_descr' => "UPS {$modelName} input",
            'sensor_divisor' => 1,
            'sensor_multiplier' => 1,
            'sensor_current' => $data['input']['voltage'],
            'sensor_min' => 0,
            'sensor_limit_low_warn' => $nomLow * 0.9,
            'sensor_limit_warn' => $nomHigh * 1.1,
        ]));
    }

    private function discoverBatteryVoltage(string $upsID, string $modelName, array $data): void
    {
        app('sensor-discovery')->discover(new Sensor([
            'poller_type' => 'app',
            'sensor_class' => 'voltage',
            'sensor_index' => "{$upsID}_battery_voltage",
            'sensor_oid' => "app:nut:{$upsID}_battery_voltage",
            'sensor_descr' => "UPS {$modelName} battery",
            'sensor_divisor' => 1,
            'sensor_multiplier' => 1,
            'sensor_current' => $data['battery']['voltage'],
            'sensor_min' => 0,
            'sensor_max' => $data['battery']['voltage'] * 10,
        ]));
    }

    private function discoverBatteryHealth(string $upsID, array $data): void
    {
        $modelName = $data['device']['model'] ?? '';
        app('sensor-discovery')
            ->discover(new Sensor([
                'poller_type' => 'app',
                'sensor_class' => 'state',
                'sensor_type' => 'nut_battery_health',
                'sensor_index' => "{$upsID}_battery_health",
                'sensor_oid' => "app:nut:{$upsID}_battery_health",
                'sensor_descr' => "UPS {$modelName} battery health",
                'sensor_divisor' => 1,
                'sensor_multiplier' => 1,
                'sensor_current' => (int) ($data['battery_health'] ?? -1),
            ]))
            ->withStateTranslations('nut_battery_health', [
                StateTranslation::define('OK', 0, Severity::Ok),
                StateTranslation::define('Low Battery', 1, Severity::Warning),
                StateTranslation::define('High Battery', 2, Severity::Warning),
                StateTranslation::define('Replace Battery', 4, Severity::Error),
                StateTranslation::define('Low + Replace', 5, Severity::Error),
                StateTranslation::define('High + Replace', 6, Severity::Error),
                StateTranslation::define('Multiple Issues', 7, Severity::Error),
            ]);
    }

    private function discoverStatusOnline(string $upsID, array $data): void
    {
        $modelName = $data['device']['model'] ?? '';
        app('sensor-discovery')
            ->discover(new Sensor([
                'poller_type' => 'app',
                'sensor_class' => 'state',
                'sensor_type' => 'nut_status_online',
                'sensor_index' => "{$upsID}_status_online",
                'sensor_oid' => "app:nut:{$upsID}_status_online",
                'sensor_descr' => "UPS {$modelName} online status",
                'sensor_divisor' => 1,
                'sensor_multiplier' => 1,
                'sensor_current' => (int) ($data['status_online'] ?? -1),
            ]))
            ->withStateTranslations('nut_status_online', [
                StateTranslation::define('Online', 0, Severity::Ok),
                StateTranslation::define('On Battery', 1, Severity::Warning),
            ]);
    }

    private function discoverStatusBool(string $type, string $descr, string $upsID, array $data, string $dataKey = ''): void
    {
        $key = $dataKey ?: $type;
        $modelName = $data['device']['model'] ?? '';
        $stateType = 'nut_' . $type;
        app('sensor-discovery')
            ->discover(new Sensor([
                'poller_type' => 'app',
                'sensor_class' => 'state',
                'sensor_type' => $stateType,
                'sensor_index' => "{$upsID}_{$type}",
                'sensor_oid' => "app:nut:{$upsID}_{$type}",
                'sensor_descr' => "UPS {$modelName} {$descr}",
                'sensor_divisor' => 1,
                'sensor_multiplier' => 1,
                'sensor_current' => (int) ($data[$key] ?? -1),
            ]))
            ->withStateTranslations($stateType, [
                StateTranslation::define('OK', 0, Severity::Ok),
                StateTranslation::define($descr, 1, Severity::Error),
            ]);
    }

    private function discoverOutputVoltageRegulation(string $upsID, array $data): void
    {
        $modelName = $this->payload['data'][$upsID]['device']['model'] ?? '';
        app('sensor-discovery')
            ->discover(new Sensor([
                'poller_type' => 'app',
                'sensor_class' => 'state',
                'sensor_type' => 'nut_output_voltage_regulation',
                'sensor_index' => "{$upsID}_output_voltage_regulation",
                'sensor_oid' => "app:nut:{$upsID}_output_voltage_regulation",
                'sensor_descr' => "UPS {$modelName} voltage regulation",
                'sensor_divisor' => 1,
                'sensor_multiplier' => 1,
                'sensor_current' => (int) ($data['output_voltage_regulation'] ?? 0),
            ]))
            ->withStateTranslations('nut_output_voltage_regulation', [
                StateTranslation::define('Normal', 0, Severity::Ok),
                StateTranslation::define('Trim', 1, Severity::Warning),
                StateTranslation::define('Boost', 2, Severity::Warning),
            ]);
    }

    private function discoverBatteryCharging(string $upsID, array $data): void
    {
        $modelName = $data['device']['model'] ?? '';
        app('sensor-discovery')
            ->discover(new Sensor([
                'poller_type' => 'app',
                'sensor_class' => 'state',
                'sensor_type' => 'nut_battery_charging',
                'sensor_index' => "{$upsID}_battery_charging",
                'sensor_oid' => "app:nut:{$upsID}_battery_charging",
                'sensor_descr' => "UPS {$modelName} battery charging",
                'sensor_divisor' => 1,
                'sensor_multiplier' => 1,
                'sensor_current' => (int) ($data['battery_charging'] ?? -1),
            ]))
            ->withStateTranslations('nut_battery_charging', [
                StateTranslation::define('Idle', 0, Severity::Ok),
                StateTranslation::define('Charging', 1, Severity::Ok),
                StateTranslation::define('Discharging', 2, Severity::Warning),
            ]);
    }

    /////////////////////////////////////////////////
    // Poller update
    //
    // Poller update is responsible for updating sensor values based on the current payload. It runs on every poll for each UPS device in the payload, after discovery has run.
    /////////////////////////////////////////////////
    private function pollerUpdate(string $upsID): void
    {
        $this->updateSensors($upsID);
        $this->RrdWriteStats($upsID, $this->payload['data'][$upsID] ?? []);
    }

    private function updateSensors(string $upsID): void
    {
        $data = $this->payload['data'][$upsID] ?? [];

        // Build value map keyed by the sensor_index suffix (everything after "{upsID}_")
        $values = [
            'load'                       => $data['ups']['load'] ?? null,
            'charge'                     => $data['battery']['charge'] ?? null,
            'runtime'                    => $data['battery']['runtime'] ?? null,
            'realpower'                  => $data['ups']['realpower'] ?? null,
            'output_voltage'             => $data['output']['voltage'] ?? null,
            'output_frequency'           => $data['output']['frequency'] ?? null,
            'input_voltage'              => $data['input']['voltage'] ?? null,
            'battery_voltage'            => $data['battery']['voltage'] ?? null,
            'output_voltage_regulation'  => $data['output_voltage_regulation'] ?? null,
            'battery_charging'           => $data['battery_charging'] ?? null,
            'battery_health'             => $data['battery_health'] ?? null,
            'status_online'              => $data['status_online'] ?? null,
            'status_bypass'              => $data['status_Bypass'] ?? null,
            'status_overload'            => $data['status_Overload'] ?? null,
            'status_alarm'               => $data['status_Alarm'] ?? null,
            'status_forced_shutdown'     => $data['status_Forced_Shutdown'] ?? null,
        ];

        // Load all sensors for this UPS in one query
        $prefix = $upsID . '_';
        $sensors = Sensor::where('device_id', $this->device['device_id'])
            ->where('sensor_oid', 'like', "app:nut:{$upsID}_%")
            ->get();

        foreach ($sensors as $sensor) {
            $type = substr($sensor->sensor_index, strlen($prefix));
            $value = $values[$type] ?? null;

            if ($sensor->sensor_class === 'state') {
                $this->updateStateSensorValue($sensor, $value !== null ? (int) $value : null);
            } else {
                $this->updateNumericSensor($sensor, $value);
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
    // sensor_prev must be saved before overwriting sensor_current so state-change
    // event logging can detect transitions on the next poll.
    private function updateStateSensorValue(Sensor $sensor, ?int $value): void
    {
        $currentValue = $value ?? -1;
        $sensor->sensor_current = $currentValue;
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

    private function RrdWriteStats(string $upsID, array $data): void
    {
        $writer = new NutRrdWriter();
        $fields = $writer->buildFields($data);
        $tags = ['rrd_name' => ['app', 'ups-nut', $this->app->app_id, $upsID]];

        $writer->write($this->device, 'ups-nut', $this->app->app_id, $upsID, $fields, $tags);
    }
}
