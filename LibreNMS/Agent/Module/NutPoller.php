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
    // $NewData[$ups]['Battery']['Charge'|'Runtime'|'Type'|'Charge_low'|'Voltage_nominal'|'Temperature']
    // $NewData[$ups]['Output']['Voltage'|'Frequency'|'Voltage_nominal'|'Voltage']
    // $NewData[$ups]['Input']['Voltage']|'Frequency'|'Frequency_nominal'|'Voltage_nominal'|'Voltage'|Transfer_high'|'Transfer_low']
    // $NewData[$ups]['Ups']['Load'|'Realpower'|'Status'|'Beeper_status'|'Temperature']
    // $NewData[$ups]['Device']['Mfr'|'Model'|'Serial']
    public array $NewData = [];
    // ['ups']  Current UPS being processed

    public array $working = ['ups' => null, 'status' => false]; // contrains current working state, like current UPS being processed, and if we have valid data to write or not (used to set application status at the end)

    private Application $app;
    private array $payload;
    private array $device;

    private int $Discoverytick = 3; // Discovery every 3rd poll
    private array $appData = []; // Loaded from $app->data, will be modified and saved back at the end

    private array $discovery = [];

    private const VERSION = 2;
    private const SCHEMA_VERSION = 1;
    private const STALE_THRESHOLD = 288; // 24h at 5-min poll intervals

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


    /// Main function to run the App
    //  1. load appData from the database
    //  2. load agentData from the agent
    //  3. Determin if Discovery is need, if yes do discovery
    private function run(): void
    {
        $this->loadAppData(); // from Database previous polls, used for discovery and to determine if discovery is needed on this poll
        $this->loadAgentData(); // from Agent JSON, used for processing and discovery on this poll

        // echo '<!-- NutPoller discovery DB: ' . json_encode($this->discovery, JSON_PRETTY_PRINT) . ' -->' . "\n";

        // Determine if discovery is needed on this poll based on previous discovery data and current payload
        if ($this->Checkiftimefordiscovery()) {
            $this->discovery['tick'] = $this->discovery['tick'] + 1;
        } else {
            // store new discovery data for this UPS
            $this->discovery['counter'] = $this->payload['count'] ?? 0;
            $previous = $this->discovery['ups_list'] ?? [];
            $previousStale = $this->discovery['sensor_stale'] ?? [];
            $this->discovery['ups_list'] = [];
            $this->discovery['sensor_stale'] = [];
            foreach ($this->payload['data'] ?? [] as $upsID => $upsData) {
                $this->discovery['ups_list'][$upsID] = [
                    'device'        => $upsData['device'] ?? [],
                    'sensors'       => [],
                    'sensor_ignore' => $previous[$upsID]['sensor_ignore'] ?? [],
                ];
                $this->discovery['sensor_stale'][$upsID] = $previousStale[$upsID] ?? [];
            }
            $this->Discovery();
            $this->discovery['tick'] = 0;
        }

        // Process each UPS
        foreach ($this->discovery['ups_list'] as $upsID => $upsInfo) {
            $this->working['ups'] = $upsID;
            $this->working['ups_model'] = $this->extractModel($upsID);
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

        // Cleanup legacy discovery keys and normalize stale storage.
        unset($this->discovery['sensor_paths']);

        $this->discovery['sensor_stale'] ??= [];
        foreach (($this->discovery['ups_list'] ?? []) as $upsID => $upsInfo) {
            if (! isset($this->discovery['sensor_stale'][$upsID]) && isset($upsInfo['sensor_stale']) && is_array($upsInfo['sensor_stale'])) {
                $this->discovery['sensor_stale'][$upsID] = $upsInfo['sensor_stale'];
            }
            unset($this->discovery['ups_list'][$upsID]['sensor_stale']);

            $sensors = $upsInfo['sensors'] ?? [];
            $normalizedSensors = [];
            if (is_array($sensors)) {
                foreach ($sensors as $index => $path) {
                    if (is_string($index) && is_string($path)) {
                        $normalizedSensors[$index] = $path;
                    } elseif (is_array($path) && isset($path['index'], $path['path']) && is_string($path['index']) && is_string($path['path'])) {
                        $normalizedSensors[$path['index']] = $path['path'];
                    }
                }
            }
            $this->discovery['ups_list'][$upsID]['sensors'] = $normalizedSensors;
        }
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
            $metrics["{$upsID}_runtime"] = ($data['battery']['runtime'] ?? 0) / 60;
        }

        update_application($this->app, 'oKS', $metrics);
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
        // Reset the singleton so previously discovered sensors from other modules
        // don't bleed into our sync() call, and so re-running discovery starts clean.
        //app()->forgetInstance('sensor-discovery');
        echo '*';

        foreach (array_keys($this->discovery['ups_list']) as $upsID) {
            $this->working['ups'] = $upsID;
            $this->working['ups_model'] = $this->extractModel($upsID);
            $this->discoverSensors($upsID);
        }

        // Sync all discovered sensors: creates new, updates existing, deletes sensors
        // for UPS devices no longer in the payload.
        // Numeric sensors use sensor_type='app'; each state type uses its own name.
        app('sensor-discovery')->sync(sensor_type: 'app');
        app('sensor-discovery')->sync(sensor_type: 'nut_status_online');
        app('sensor-discovery')->sync(sensor_type: 'nut_output_voltage_regulation');
        app('sensor-discovery')->sync(sensor_type: 'nut_battery_charging');
        app('sensor-discovery')->sync(sensor_type: 'nut_battery_health');
        app('sensor-discovery')->sync(sensor_type: 'nut_status_bypass');
        app('sensor-discovery')->sync(sensor_type: 'nut_status_overload');
        app('sensor-discovery')->sync(sensor_type: 'nut_status_alarm');
        app('sensor-discovery')->sync(sensor_type: 'nut_status_forced_shutdown');
    }

        private function getValueFromPayloadPath(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function registerSensorPath(string $upsID, string $index, string $path): void
    {
        $this->discovery['ups_list'][$upsID]['sensors'][$index] = $path;
    }

    private function buildPayloadPath(string $section, string $metric, ?string $suffix = null): string
    {
        $base = $section === 'bypass' ? 'input.bypass' : $section;
        if ($suffix !== null && $suffix !== '') {
            return "{$base}.{$suffix}.{$metric}";
        }

        return "{$base}.{$metric}";
    }

    
    private function Checkiftimefordiscovery(): bool
    {
        // If we have no previous discovery data, we should run discovery to initialize it
        if (empty($this->appData['Discovery'])) {
            return false;  // force: first poll
        }
        // If we have more devices than last discovery, run discovery immediately to catch new devices faster
        if ($this->discovery['counter'] < $this->payload['count']) {
            return false; // force: new devices
        }
        // poll_count is reset to 0 each time discovery runs, so it means
        // "polls since last discovery". Skip until we reach the interval.
        if ($this->discovery['tick'] < $this->Discoverytick) {
            return true;   // timer not reached, skip discovery
        }

        return false;  // interval reached, run discovery
    }

    private function discoverSensors(string $upsID): void
    {
        $data = $this->payload['data'][$upsID] ?? [];
        $modelName = $this->working['ups_model'];
        $ignore = $this->discovery['ups_list'][$upsID]['sensor_ignore'] ?? [];

        // State sensors
        $this->discoverStatusOnline($upsID, $data);
        $this->discoverOutputVoltageRegulation($upsID, $data);
        $this->discoverBatteryCharging($upsID, $data);
        $this->discoverBatteryHealth($upsID, $data);
        $this->discoverStatusBool('status_bypass', 'Bypass', $upsID, $data, 'status_Bypass');
        $this->discoverStatusBool('status_overload', 'Overload', $upsID, $data, 'status_Overload');
        $this->discoverStatusBool('status_alarm', 'Alarm', $upsID, $data, 'status_Alarm');
        $this->discoverStatusBool('status_forced_shutdown', 'Forced Shutdown', $upsID, $data, 'status_Forced_Shutdown');

        // Build sensor arrays per type
        $this->working['sensors'] = [
            'voltage' => [],
            'current' => [],
            'power' => [],
            'powerfactor' => [],
            'frequency' => [],
            'temperature' => [],
        ];

        // Voltages: input, output (section-level, then phase-level)
        foreach (['input', 'output'] as $section) {
            if (empty($data[$section]) || ! is_array($data[$section])) {
                continue;
            }

            // Section-level voltage (e.g., input.voltage or input.1.voltage)
            if (isset($data[$section]['voltage'])) {
                $index = "{$upsID}_{$section}_voltage";
                if (! in_array($index, $ignore)) {
                    $this->working['sensors']['voltage'][] = [
                        'section' => $section,
                        'suffix' => null,
                        'suffix_raw' => null,
                        'key' => 'voltage',
                        'data' => $data[$section]['voltage'],
                    ];
                }
            }

            // Phase voltages (L1, L2, L3 or 1, 2, 3)
            foreach ($data[$section] as $key => $value) {
                if (preg_match('/^(L?\d+)$/', $key, $matches) && is_array($value) && isset($value['voltage'])) {
                    $suffix = $matches[1]; // e.g., "L1" or "1"
                    $index = "{$upsID}_{$section}_voltage_{$suffix}";
                    if (! in_array($index, $ignore)) {
                        $this->working['sensors']['voltage'][] = [
                            'section' => $section,
                            'suffix' => $suffix,
                            'suffix_raw' => $suffix,
                            'key' => 'voltage',
                            'data' => $value['voltage'],
                        ];
                    }
                }
            }
        }

        // Line-to-line voltages (L1-L2, L2-L3, L3-L1)
        foreach (['input', 'output'] as $section) {
            if (empty($data[$section]) || ! is_array($data[$section])) {
                continue;
            }
            foreach ($data[$section] as $key => $value) {
                if (preg_match('/^L(\d+)-L(\d+)$/', $key) && ! empty($value['voltage'])) {
                    $index = "{$upsID}_{$section}_ll_voltage_{$key}";
                    if (! in_array($index, $ignore)) {
                        $this->working['sensors']['voltage'][] = [
                            'section' => $section,
                            'suffix' => $key,
                            'suffix_raw' => $key,
                            'key' => 'voltage',
                            'data' => $value['voltage'],
                        ];
                    }
                }
            }
        }

        // Battery voltage (separate from other voltages)
        $index = "{$upsID}_battery_voltage";
        if (! empty($data['battery']['voltage']) && ! in_array($index, $ignore)) {
            $this->working['sensors']['voltage'][] = [
                'section' => 'battery',
                'suffix' => null,
                'suffix_raw' => null,
                'key' => 'voltage',
                'data' => $data['battery']['voltage'],
            ];
        }

        // Currents: phases (L1, L2, L3) and section-level
        foreach (['input', 'output'] as $section) {
            if (empty($data[$section]) || ! is_array($data[$section])) {
                continue;
            }

            // Section-level current (aggregate)
            if (isset($data[$section]['current'])) {
                $index = "{$upsID}_{$section}_current";
                if (! in_array($index, $ignore)) {
                    $this->working['sensors']['current'][] = [
                        'section' => $section,
                        'suffix' => null,
                        'suffix_raw' => null,
                        'key' => 'current',
                        'data' => $data[$section]['current'],
                    ];
                }
            }

            // Phase currents (L1, L2, L3 or 1, 2, 3)
            foreach ($data[$section] as $key => $value) {
                if (preg_match('/^(L?\d+)$/', $key, $matches) && is_array($value) && isset($value['current'])) {
                    $suffix = $matches[1];
                    $index = "{$upsID}_{$section}_current_{$suffix}";
                    if (! in_array($index, $ignore)) {
                        $this->working['sensors']['current'][] = [
                            'section' => $section,
                            'suffix' => $suffix,
                            'suffix_raw' => $suffix,
                            'key' => 'current',
                            'data' => $value['current'],
                        ];
                    }
                }
            }
        }

        // Powers: section-level and phase-level
        foreach (['input', 'output'] as $section) {
            if (empty($data[$section]) || ! is_array($data[$section])) {
                continue;
            }

            // Section-level power (aggregate)
            if (isset($data[$section]['power'])) {
                $index = "{$upsID}_{$section}_power";
                if (! in_array($index, $ignore)) {
                    $this->working['sensors']['power'][] = [
                        'section' => $section,
                        'suffix' => null,
                        'suffix_raw' => null,
                        'key' => 'power',
                        'data' => $data[$section]['power'],
                    ];
                }
            }

            // Phase powers (L1, L2, L3 or 1, 2, 3)
            foreach ($data[$section] as $key => $value) {
                if (preg_match('/^(L?\d+)$/', $key, $matches) && is_array($value) && isset($value['power'])) {
                    $suffix = $matches[1];
                    $index = "{$upsID}_{$section}_power_{$suffix}";
                    if (! in_array($index, $ignore)) {
                        $this->working['sensors']['power'][] = [
                            'section' => $section,
                            'suffix' => $suffix,
                            'suffix_raw' => $suffix,
                            'key' => 'power',
                            'data' => $value['power'],
                        ];
                    }
                }
            }

            // Section-level realpower (aggregate)
            $realpowerData = $data[$section]['realpower'] ?? null;
            if ($realpowerData !== null && (is_numeric($realpowerData) || (is_array($realpowerData) && isset($realpowerData['value'])))) {
                $index = "{$upsID}_{$section}_realpower";
                if (! in_array($index, $ignore)) {
                    $this->working['sensors']['power'][] = [
                        'section' => $section,
                        'suffix' => '_real',
                        'suffix_raw' => '_real',
                        'key' => 'realpower',
                        'data' => $realpowerData,
                    ];
                }
            }

            // Phase realpowers (L1, L2, L3 or 1, 2, 3)
            foreach ($data[$section] as $key => $value) {
                if (preg_match('/^(L?\d+)$/', $key, $matches) && is_array($value) && isset($value['realpower'])) {
                    $suffix = $matches[1];
                    $index = "{$upsID}_{$section}_realpower_{$suffix}";
                    if (! in_array($index, $ignore)) {
                        $this->working['sensors']['power'][] = [
                            'section' => $section,
                            'suffix' => $suffix . '_real',
                            'suffix_raw' => $suffix . '_real',
                            'key' => 'realpower',
                            'data' => $value['realpower'],
                        ];
                    }
                }
            }
        }

        // Power/Realpower at ups level - only add if has valid value
        if (isset($data['ups']['power'])) {
            $powerData = $data['ups']['power'];
            if (is_numeric($powerData) || (is_array($powerData) && isset($powerData['value']))) {
                $index = "{$upsID}_ups_power";
                if (! in_array($index, $ignore)) {
                    $this->working['sensors']['power'][] = [
                        'section' => 'ups',
                        'suffix' => null,
                        'suffix_raw' => null,
                        'key' => 'power',
                        'data' => $powerData,
                    ];
                }
            }
        }
        if (isset($data['ups']['realpower'])) {
            $realpowerData = $data['ups']['realpower'];
            if (is_numeric($realpowerData) || (is_array($realpowerData) && isset($realpowerData['value']))) {
                $index = "{$upsID}_ups_realpower";
                if (! in_array($index, $ignore)) {
                    $this->working['sensors']['power'][] = [
                        'section' => 'ups',
                        'suffix' => '_real',
                        'suffix_raw' => '_real',
                        'key' => 'realpower',
                        'data' => $realpowerData,
                    ];
                }
            }
        }

        // Power factor
        foreach (['input', 'output'] as $section) {
            if (empty($data[$section]) || ! is_array($data[$section])) {
                continue;
            }
            foreach ($data[$section] as $key => $value) {
                // Section-level powerfactor
                if ($key === 'powerfactor' && (is_numeric($value) || (is_array($value) && isset($value['value'])))) {
                    $index = "{$upsID}_{$section}_powerfactor";
                    if (! in_array($index, $ignore)) {
                        $this->working['sensors']['powerfactor'][] = [
                            'section' => $section,
                            'suffix' => null,
                            'key' => 'powerfactor',
                            'data' => $value,
                        ];
                    }
                }
                // Phase powerfactors (L1, L2, L3 or 1, 2, 3)
                if (preg_match('/^(L?\d+)$/', $key, $matches) && is_array($value) && isset($value['powerfactor'])) {
                    $suffix = $matches[1];
                    $index = "{$upsID}_{$section}_powerfactor_{$suffix}";
                    if (! in_array($index, $ignore)) {
                        $this->working['sensors']['powerfactor'][] = [
                            'section' => $section,
                            'suffix' => $suffix,
                            'key' => 'powerfactor',
                            'data' => $value['powerfactor'],
                        ];
                    }
                }
            }
        }

        // Frequencies: section-level and phase-level
        foreach (['input', 'output'] as $section) {
            if (empty($data[$section]) || ! is_array($data[$section])) {
                continue;
            }

            // Section-level frequency (e.g., output.frequency)
            if (isset($data[$section]['frequency'])) {
                $index = "{$upsID}_{$section}_frequency";
                if (! in_array($index, $ignore)) {
                    $this->working['sensors']['frequency'][] = [
                        'section' => $section,
                        'suffix' => null,
                        'suffix_raw' => null,
                        'key' => 'frequency',
                        'data' => $data[$section]['frequency'],
                    ];
                }
            }

            // Phase frequencies (L1, L2, L3 or 1, 2, 3)
            foreach ($data[$section] as $key => $value) {
                if (preg_match('/^(L?\d+)$/', $key, $matches) && is_array($value) && isset($value['frequency'])) {
                    $suffix = $matches[1];
                    $index = "{$upsID}_{$section}_frequency_{$suffix}";
                    if (! in_array($index, $ignore)) {
                        $this->working['sensors']['frequency'][] = [
                            'section' => $section,
                            'suffix' => $suffix,
                            'suffix_raw' => $suffix,
                            'key' => 'frequency',
                            'data' => $value['frequency'],
                        ];
                    }
                }
            }
        }

        // Temperatures
        foreach (['battery', 'ups', 'ambient'] as $section) {
            $index = "{$upsID}_{$section}_temperature";
            if (! empty($data[$section]['temperature']) && ! in_array($index, $ignore)) {
                $this->working['sensors']['temperature'][] = [
                    'section' => $section,
                    'suffix' => null,
                    'suffix_raw' => null,
                    'key' => 'temperature',
                    'data' => $data[$section]['temperature'],
                ];
            }
        }

        // Bypass sensors: voltage, current, frequency
        if (! empty($data['input']['bypass'])) {
            $bypass = $data['input']['bypass'];
            $index = "{$upsID}_bypass_voltage";
            if (! empty($bypass['voltage']) && ! in_array($index, $ignore)) {
                $this->working['sensors']['voltage'][] = [
                    'section' => 'bypass',
                    'suffix' => null,
                    'suffix_raw' => null,
                    'key' => 'voltage',
                    'data' => $bypass['voltage'],
                ];
            }
            $index = "{$upsID}_bypass_current";
            if (! empty($bypass['current']) && ! in_array($index, $ignore)) {
                $this->working['sensors']['current'][] = [
                    'section' => 'bypass',
                    'suffix' => null,
                    'suffix_raw' => null,
                    'key' => 'current',
                    'data' => $bypass['current'],
                ];
            }
            $index = "{$upsID}_bypass_frequency";
            if (! empty($bypass['frequency']) && ! in_array($index, $ignore)) {
                $this->working['sensors']['frequency'][] = [
                    'section' => 'bypass',
                    'suffix' => null,
                    'suffix_raw' => null,
                    'key' => 'frequency',
                    'data' => $bypass['frequency'],
                ];
            }
        }

        // Discover sensors by type
        $this->discoverVoltages($upsID);
        $this->discoverCurrents($upsID);
        $this->discoverPowers($upsID);
        $this->discoverPowerfactors($upsID);
        $this->discoverFrequencies($upsID);
        $this->discoverTemperatures($upsID);

        // Single sensors (no grouping needed)
        if (! empty($data['ups']['load']) && ! in_array('load', $ignore)) {
            $this->discoverLoad($upsID, $data);
        }
        if (! empty($data['battery']['charge']) && ! in_array('charge', $ignore)) {
            $this->discoverCharge($upsID, $data);
        }
        if (! empty($data['battery']['runtime']) && ! in_array('runtime', $ignore)) {
            $this->discoverRuntime($upsID, $data);
        }
    }

    private function discoverVoltages(string $upsID): void
    {
        $sensors = $this->working['sensors']['voltage'] ?? [];
        if (count($sensors) === 0) {
            return;
        }

        $modelName = $this->working['ups_model'];
        $group = "UPS {$modelName}";

        foreach ($sensors as $sensor) {
            $section = $sensor['section'];
            $suffix = $sensor['suffix'];
            $sensorData = $sensor['data'];

            // Normalize phase suffix for index and description
            $indexSuffix = $suffix !== null ? '_' . self::normalizePhaseSuffix($suffix) : '';
            $descrSuffix = $suffix !== null ? self::normalizePhaseSuffix($suffix) : null;

            // Build index
            $index = "{$upsID}_{$section}_voltage{$indexSuffix}";

            // Build description
            $descr = $descrSuffix !== null ?
             "UPS {$modelName} {$section} {$descrSuffix}" :
             "UPS {$modelName} {$section}";

            $value = self::extractValue($sensorData);

            if (! $this->checkStaleOrContinue($upsID, $index, $value)) {
                continue;
            }

            $nom = self::extractNominal($sensorData);
            $suffixRaw = $sensor['suffix_raw'] ?? $suffix;
            $this->registerSensorPath($upsID, $index, $this->buildPayloadPath($section, 'voltage', $suffixRaw));

            // Calculate limits based on section
            $limits = [];
            if ($section === 'input') {
                $nomLow = $nom ?: 0;
                $nomHigh = $nom ?: 0;
                $limits = [
                    'sensor_limit_low_warn' => $nomLow * 0.9,
                    'sensor_limit_warn' => $nomHigh * 1.1,
                ];
            } elseif ($section === 'output') {
                $limits = [
                    'sensor_limit_warn' => $nom * 1.1,
                    'sensor_limit_low_warn' => $nom * 0.9,
                ];
            } elseif ($section === 'battery') {
                $limits = ['sensor_max' => $nom > 0 ? $nom * 1.5 : null];
            }

            app('sensor-discovery')->discover(new Sensor([
                'device_id' => $this->device['device_id'],
                'group' => $group,
                'poller_type' => 'agent',
                'sensor_type' => 'app',
                'sensor_class' => 'voltage',
                'sensor_index' => $index,
                'sensor_oid' => "app:nut:{$index}",
                'sensor_descr' => $descr,
                'sensor_current' => $value,
                'sensor_min' => 0,
                ...$limits,
            ]));
        }
    }

    private function discoverCurrents(string $upsID): void
    {
        $sensors = $this->working['sensors']['current'] ?? [];
        if (count($sensors) === 0) {
            return;
        }

        $modelName = $this->working['ups_model'];
        $group = "UPS {$modelName}";

        foreach ($sensors as $sensor) {
            $section = $sensor['section'];
            $suffix = $sensor['suffix'];
            $sensorData = $sensor['data'];

            // Normalize phase suffix for index and description
            $indexSuffix = $suffix !== null ? '_' . self::normalizePhaseSuffix($suffix) : '';
            $descrSuffix = $suffix !== null ? self::normalizePhaseSuffix($suffix) : null;

            // Build index
            $index = "{$upsID}_{$section}_current{$indexSuffix}";

            // Build description
                $descr = $descrSuffix !== null ? "UPS {$modelName} {$section} {$descrSuffix}" : "UPS {$modelName} {$section}";

            $value = self::extractValue($sensorData);

            if (! $this->checkStaleOrContinue($upsID, $index, $value)) {
                continue;
            }

            $suffixRaw = $sensor['suffix_raw'] ?? $suffix;
            $this->registerSensorPath($upsID, $index, $this->buildPayloadPath($section, 'current', $suffixRaw));

            // Extract limits from data if available
            $limit = null;
            $limitWarn = null;
            if (is_array($sensorData)) {
                $limit = self::extractValue($sensorData['high']['critical'] ?? null);
                $limitWarn = self::extractValue($sensorData['high']['warning'] ?? null);
            }

            app('sensor-discovery')->discover(new Sensor([
                'device_id' => $this->device['device_id'],
                'group' => $group,
                'poller_type' => 'agent',
                'sensor_type' => 'app',
                'sensor_class' => 'current',
                'sensor_index' => $index,
                'sensor_oid' => "app:nut:{$index}",
                'sensor_descr' => $descr,
                'sensor_current' => $value,
                'sensor_min' => 0,
                'sensor_limit' => $limit,
                'sensor_limit_warn' => $limitWarn,
            ]));
        }
    }

    private function discoverPowers(string $upsID): void
    {
        $sensors = $this->working['sensors']['power'] ?? [];
        if (count($sensors) === 0) {
            return;
        }

        $modelName = $this->working['ups_model'];
        $group = "UPS {$modelName}";

        foreach ($sensors as $sensor) {
            $section = $sensor['section'];
            $suffix = $sensor['suffix'];
            $sensorData = $sensor['data'];
            $isReal = str_ends_with($suffix ?? '', '_real');
            $phaseSuffix = $isReal ? str_replace('_real', '', $suffix ?? '') : $suffix;
            $normalizedPhase = $phaseSuffix !== '' && $phaseSuffix !== null ? self::normalizePhaseSuffix($phaseSuffix) : null;

            // Build index
            $typeSuffix = $isReal ? '_realpower' : '_power';
            $indexPhase = $normalizedPhase !== null ? '_' . $normalizedPhase : '';
            $index = "{$upsID}_{$section}{$typeSuffix}{$indexPhase}";

            // Build description
            $descrSuffix = $isReal ? ' real' : '';
            $descr = ($phaseSuffix !== '' && $phaseSuffix !== null ?
            "UPS {$modelName} {$section} {$phaseSuffix}{$descrSuffix}" :
            "UPS {$modelName} {$section}{$descrSuffix}");

            $value = self::extractValue($sensorData);

            if (! $this->checkStaleOrContinue($upsID, $index, $value)) {
                continue;
            }

            $suffixRaw = $sensor['suffix_raw'] ?? $suffix;
            $pathSuffix = is_string($suffixRaw) ? str_replace('_real', '', $suffixRaw) : $suffixRaw;
            $this->registerSensorPath($upsID, $index, $this->buildPayloadPath($section, $sensor['key'], $pathSuffix));

            app('sensor-discovery')->discover(new Sensor([
                'device_id' => $this->device['device_id'],
                'group' => $group,
                'poller_type' => 'agent',
                'sensor_type' => 'app',
                'sensor_class' => 'power',
                'sensor_index' => $index,
                'sensor_oid' => "app:nut:{$index}",
                'sensor_descr' => $descr,
                'sensor_current' => $value,
                'sensor_min' => 0,
            ]));
        }
    }

    private function discoverPowerfactors(string $upsID): void
    {
        $sensors = $this->working['sensors']['powerfactor'] ?? [];
        if (count($sensors) === 0) {
            return;
        }

        $modelName = $this->working['ups_model'];
        $group = "UPS {$modelName}";

        foreach ($sensors as $sensor) {
            $section = $sensor['section'];
            $suffix = $sensor['suffix'];
            $sensorData = $sensor['data'];

            // Normalize phase suffix for index and description
            $indexSuffix = $suffix !== null ? '_' . self::normalizePhaseSuffix($suffix) : '';
            $descrSuffix = $suffix !== null ? self::normalizePhaseSuffix($suffix) : null;

            // Build index
            $index = "{$upsID}_{$section}_power_factor{$indexSuffix}";

            // Build description
            $descr = $descrSuffix !== null ?
                "UPS {$modelName} {$section} {$descrSuffix}" : "UPS {$modelName} {$section}";


            $value = self::extractValue($sensorData);

            if (! $this->checkStaleOrContinue($upsID, $index, $value)) {
                continue;
            }

            $suffixRaw = $sensor['suffix_raw'] ?? $suffix;
            $this->registerSensorPath($upsID, $index, $this->buildPayloadPath($section, 'powerfactor', $suffixRaw));

            app('sensor-discovery')->discover(new Sensor([
                'device_id' => $this->device['device_id'],
                'group' => $group,
                'poller_type' => 'agent',
                'sensor_type' => 'app',
                'sensor_class' => 'power_factor',
                'sensor_index' => $index,
                'sensor_oid' => "app:nut:{$index}",
                'sensor_descr' => $descr,
                'sensor_current' => $value,
                'sensor_limit_low_warn' => 0.95,
                'sensor_limit_low' => 0.85,
            ]));
        }
    }

    private function discoverFrequencies(string $upsID): void
    {
        $sensors = $this->working['sensors']['frequency'] ?? [];
        if (count($sensors) === 0) {
            return;
        }

        $modelName = $this->working['ups_model'];
        $group = "UPS {$modelName}";

        foreach ($sensors as $sensor) {
            $section = $sensor['section'];
            $suffix = $sensor['suffix'];
            $sensorData = $sensor['data'];

            // Normalize phase suffix for index and description
            $indexSuffix = $suffix !== null ? '_' . self::normalizePhaseSuffix($suffix) : '';
            $descrSuffix = $suffix !== null ? self::normalizePhaseSuffix($suffix) : null;

            // Build index
            $index = "{$upsID}_{$section}_frequency{$indexSuffix}";

            // Build description
            $descr = $descrSuffix !== null ?
                "UPS {$modelName} {$section} {$descrSuffix}" :
                "UPS {$modelName} {$section}";

            $value = self::extractValue($sensorData);

            if (! $this->checkStaleOrContinue($upsID, $index, $value)) {
                continue;
            }

            $suffixRaw = $sensor['suffix_raw'] ?? $suffix;
            $this->registerSensorPath($upsID, $index, $this->buildPayloadPath($section, 'frequency', $suffixRaw));

            $nom = self::extractFrequencyNominal($sensorData);

            app('sensor-discovery')->discover(new Sensor([
                'device_id' => $this->device['device_id'],
                'group' => $group,
                'poller_type' => 'agent',
                'sensor_type' => 'app',
                'sensor_class' => 'frequency',
                'sensor_index' => $index,
                'sensor_oid' => "app:nut:{$index}",
                'sensor_descr' => $descr,
                'sensor_current' => $value,
                'sensor_min' => 0,
                'sensor_limit' => $nom + 2.5,
                'sensor_limit_low' => $nom - 2.5,
                'sensor_limit_warn' => $nom + 0.2,
                'sensor_limit_low_warn' => $nom - 0.2,
            ]));
        }
    }

    private function discoverTemperatures(string $upsID): void
    {
        $sensors = $this->working['sensors']['temperature'] ?? [];
        if (count($sensors) === 0) {
            return;
        }

        $modelName = $this->working['ups_model'];
        $group = "UPS {$modelName}";

        foreach ($sensors as $sensor) {
            $section = $sensor['section'];
            $suffix = $sensor['suffix'];
            $sensorData = $sensor['data'];

            // Build index
            $index = "{$upsID}_{$section}_temperature";

            // Build description
            if ($suffix !== null) {
                $descrSuffix = self::normalizePhaseSuffix($suffix);
            } else {
                $descrSuffix = null;
            }
                $descr = $descrSuffix !== null ?
                "UPS {$modelName} {$section} {$descrSuffix}" :
                "UPS {$modelName} {$section}";

            $value = self::extractValue($sensorData);

            if (! $this->checkStaleOrContinue($upsID, $index, $value)) {
                continue;
            }

            $suffixRaw = $sensor['suffix_raw'] ?? $suffix;
            $this->registerSensorPath($upsID, $index, $this->buildPayloadPath($section, 'temperature', $suffixRaw));

            // Extract limits from data if available, otherwise use section defaults
            $limits = [];
            if (is_array($sensorData)) {
                $limits['sensor_limit'] = self::extractValue($sensorData['high'] ?? null);
                $limits['sensor_limit_low'] = self::extractValue($sensorData['low'] ?? null);
            }

            // Fall back to section defaults if not in data
            if ($section === 'battery') {
                $limits += [
                    'sensor_limit_low_warn' => 20,
                    'sensor_limit_warn' => 25,
                ];
                if (! isset($limits['sensor_limit'])) {
                    $limits['sensor_limit'] = 30;
                }
            } elseif ($section === 'ups') {
                $limits += [
                    'sensor_limit_low_warn' => 5,
                    'sensor_limit_warn' => 40,
                ];
                if (! isset($limits['sensor_limit'])) {
                    $limits['sensor_limit'] = 50;
                }
                if (! isset($limits['sensor_limit_low'])) {
                    $limits['sensor_limit_low'] = 1;
                }
            }

            app('sensor-discovery')->discover(new Sensor([
                'device_id' => $this->device['device_id'],
                'group' => $group,
                'poller_type' => 'agent',
                'sensor_type' => 'app',
                'sensor_class' => 'temperature',
                'sensor_index' => $index,
                'sensor_oid' => "app:nut:{$index}",
                'sensor_descr' => $descr,
                'sensor_current' => $value,
                ...$limits,
            ]));
        }
    }

    private function discoverLoad(string $upsID, array $data): void
    {
        $modelName = $this->working['ups_model'];
        $index = "{$upsID}_load";
        $value = self::extractValue($data['ups']['load'] ?? null);
        $this->registerSensorPath($upsID, $index, 'ups.load');

        app('sensor-discovery')->discover(new Sensor([
            'device_id' => $this->device['device_id'],
            'poller_type' => 'agent',
            'sensor_type' => 'app',
            'sensor_class' => 'load',
            'sensor_index' => $index,
            'sensor_oid' => "app:nut:{$index}",
            'sensor_descr' => "UPS {$modelName}",
            'sensor_current' => $value,
            'sensor_min' => 0,
            'sensor_max' => 500,
            'sensor_limit_warn' => 80,
            'sensor_limit' => 90,
        ]));
    }

    private function discoverCharge(string $upsID, array $data): void
    {
        $modelName = $this->working['ups_model'];
        $index = "{$upsID}_charge";
        $chargeData = $data['battery']['charge'] ?? null;
        $value = self::extractValue($chargeData);
        $this->registerSensorPath($upsID, $index, 'battery.charge');

        $chargeLow = 20;
        if (is_array($chargeData) && isset($chargeData['low'])) {
            $chargeLow = (float) $chargeData['low'];
        }

        app('sensor-discovery')->discover(new Sensor([
            'device_id' => $this->device['device_id'],
            'poller_type' => 'agent',
            'sensor_type' => 'app',
            'sensor_class' => 'charge',
            'sensor_index' => $index,
            'sensor_oid' => "app:nut:{$index}",
            'sensor_descr' => "UPS {$modelName} battery",
            'sensor_current' => $value,
            'sensor_min' => 0,
            'sensor_max' => 200,
            'sensor_limit_low_warn' => $chargeLow,
        ]));
    }

    private function discoverRuntime(string $upsID, array $data): void
    {
        $modelName = $this->working['ups_model'];
        $index = "{$upsID}_runtime";
        $value = self::extractValue($data['battery']['runtime'] ?? null);
        $this->registerSensorPath($upsID, $index, 'battery.runtime');

        app('sensor-discovery')->discover(new Sensor([
            'device_id' => $this->device['device_id'],
            'poller_type' => 'agent',
            'sensor_type' => 'app',
            'sensor_class' => 'runtime',
            'sensor_index' => $index,
            'sensor_oid' => "app:nut:{$index}",
            'sensor_descr' => "UPS {$modelName}",
            'sensor_divisor' => 1,
            'sensor_current' => $value !== null ? $value / 60 : null,
            'sensor_min' => 0,
        ]));
    }

    private function discoverBatteryHealth(string $upsID, array $data): void
    {
        $modelName = $this->working['ups_model'];
        app('sensor-discovery')
            ->discover(new Sensor([
                'device_id' => $this->device['device_id'],
                'poller_type' => 'agent',
                'sensor_class' => 'state',
                'sensor_type' => 'nut_battery_health',
                'sensor_index' => "{$upsID}_battery_health",
                'sensor_oid' => "app:nut:{$upsID}_battery_health",
                'group' => "UPS {$modelName}",
                'sensor_descr' => 'Battery Health',
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
        $modelName = $this->working['ups_model'];
        app('sensor-discovery')
            ->discover(new Sensor([
                'device_id' => $this->device['device_id'],
                'poller_type' => 'agent',
                'sensor_class' => 'state',
                'group' => "UPS {$modelName}",
                'sensor_type' => 'nut_status_online',
                'sensor_index' => "{$upsID}_status_online",
                'sensor_oid' => "app:nut:{$upsID}_status_online",
                'sensor_descr' => 'online status',
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
        $modelName = $this->working['ups_model'];
        $stateType = 'nut_' . $type;
        app('sensor-discovery')
            ->discover(new Sensor([
                'device_id' => $this->device['device_id'],
                'poller_type' => 'agent',
                'sensor_class' => 'state',
                'group' => "UPS {$modelName}",
                'sensor_type' => $stateType,
                'sensor_index' => "{$upsID}_{$type}",
                'sensor_oid' => "app:nut:{$upsID}_{$type}",
                'sensor_descr' => "{$descr}",
                'sensor_current' => (int) ($data[$key] ?? -1),
            ]))
            ->withStateTranslations($stateType, [
                StateTranslation::define('OK', 0, Severity::Ok),
                StateTranslation::define($descr, 1, Severity::Error),
            ]);
    }

    private function discoverOutputVoltageRegulation(string $upsID, array $data): void
    {
        $modelName = $this->working['ups_model'];
        app('sensor-discovery')
            ->discover(new Sensor([
                'device_id' => $this->device['device_id'],
                'poller_type' => 'agent',
                'sensor_class' => 'state',
                'group' => "UPS {$modelName}",
                'sensor_type' => 'nut_output_voltage_regulation',
                'sensor_index' => "{$upsID}_output_voltage_regulation",
                'sensor_oid' => "app:nut:{$upsID}_output_voltage_regulation",
                'sensor_descr' => 'Voltage regulation',
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
        $modelName = $this->working['ups_model'];
        app('sensor-discovery')
            ->discover(new Sensor([
                'device_id' => $this->device['device_id'],
                'poller_type' => 'agent',
                'sensor_class' => 'state',
                'group' => "UPS {$modelName}",
                'sensor_type' => 'nut_battery_charging',
                'sensor_index' => "{$upsID}_battery_charging",
                'sensor_oid' => "app:nut:{$upsID}_battery_charging",
                'sensor_descr' => 'Battery charging',
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

    // Check if a sensor should be created (not stale). Returns true if not stale, false if stale.
    private function checkStale(string $upsID, string $key, mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        $stale = &$this->discovery['sensor_stale'][$upsID][$key];

        if (! isset($stale)) {
            $stale = ['value' => $value, 'count' => 0];

            return true;
        }

        if ((string) $stale['value'] === (string) $value) {
            $stale['count']++;

            if ($stale['count'] >= self::STALE_THRESHOLD) {
                return false;
            }
        } else {
            $stale['value'] = $value;
            $stale['count'] = 0;
        }

        return true;
    }

    // Check stale and continue if stale. Returns true if not stale, false if should skip.
    private function checkStaleOrContinue(string $upsID, string $index, mixed $value): bool
    {
        $staleKey = substr($index, strlen($upsID) + 1);

        // Bypass current can legitimately stay flat for long periods,
        // so exclude bypass*current metrics from stale suppression.
        if (str_starts_with($staleKey, 'bypass') && str_contains($staleKey, 'current')) {
            return true;
        }

        return $this->checkStale($upsID, $staleKey, $value);
    }

    private function extractModel(string $upsID): string
    {
        $data = $this->payload['data'][$upsID] ?? [];

        return $data['device']['model']
            ?? $data['ups']['model']
            ?? $data['configname']
            ?? '';
    }

    private static function normalizePhaseSuffix(string $suffix): string
    {
        if (preg_match('/^(\d+)$/', $suffix, $matches)) {
            return 'L' . $matches[1];
        }

        return $suffix;
    }

    private static function extractValue(mixed $data): ?float
    {
        if ($data === null) {
            return null;
        }

        if (is_numeric($data)) {
            return (float) $data;
        }

        if (is_array($data) && isset($data['value'])) {
            return is_numeric($data['value']) ? (float) $data['value'] : null;
        }

        return null;
    }

    private static function extractNominal(mixed $data): float
    {
        if ($data === null) {
            return 0;
        }

        if (is_numeric($data)) {
            return (float) $data;
        }

        if (is_array($data)) {
            if (isset($data['nominal']) && is_numeric($data['nominal'])) {
                return (float) $data['nominal'];
            }
            if (isset($data['value']) && is_numeric($data['value'])) {
                return (float) $data['value'];
            }
        }

        return 0;
    }

    private static function extractFrequencyNominal(mixed $data): float
    {
        if (is_array($data) && isset($data['nominal']) && is_numeric($data['nominal'])) {
            return (float) $data['nominal'];
        }

        $value = self::extractValue($data);
        if ($value === null) {
            return 0;
        }

        return $value < 55 ? 50.0 : 60.0;
    }

    private function updateSensors(string $upsID): void
    {
        $sensorPaths = $this->discovery['ups_list'][$upsID]['sensors'] ?? [];



        // Load all sensors for this UPS in one query
        $sensors = Sensor::where('device_id', $this->device['device_id'])
            ->where('sensor_oid', 'like', "app:nut:{$upsID}_%")
            ->get()
            ->keyBy('sensor_index');

        foreach ($sensorPaths as $index => $path) {
            $sensor = $sensors->get($index);
            if (! $sensor) {
                continue;
            }

            $value = self::extractValue($this->getValueFromPayloadPath($this->payload['data'], "{$upsID}.{$path}"));

            // Runtime is stored in seconds, convert to minutes for display
            if ($sensor->sensor_class === 'runtime' && $value !== null) {
                $value = $value / 60;
            }

            $this->updateNumericSensor($sensor, $value);
        }

        // State sensor paths (hardcoded)
        $statePaths = [
            'status_online' => 'status_online',
            'battery_health' => 'battery_health',
            'output_voltage_regulation' => 'output_voltage_regulation',
            'battery_charging' => 'battery_charging',
            'status_bypass' => 'status_Bypass',
            'status_overload' => 'status_Overload',
            'status_alarm' => 'status_Alarm',
            'status_forced_shutdown' => 'status_Forced_Shutdown',
        ];

        // Update state sensors from current payload values
        foreach ($statePaths as $stateIndex => $path) {
            $sensor = $sensors->get("{$upsID}_{$stateIndex}");
            if (! $sensor) {
                continue;
            }

            $value = $this->getValueFromPayloadPath($this->payload['data'], "{$upsID}.{$path}");
            $this->updateStateSensor($sensor, $value !== null ? (int) $value : null);
        }
    }

    // Update a numeric sensor value in the DB and write its individual sensor RRD.
    // The sensor poller skips sensor_type='app' sensors, so we must call app('Datastore')->put()
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

    private function updateStateSensor(Sensor $sensor, ?int $value): void
    {
        $sensorValue = $value ?? -1;
        $sensor->sensor_current = $sensorValue;
        $sensor->save();

        $tags = [
            'sensor_class' => $sensor->sensor_class,
            'sensor_type'  => $sensor->sensor_type,
            'sensor_descr' => $sensor->sensor_descr,
            'sensor_index' => $sensor->sensor_index,
            'rrd_name'     => ['sensor', $sensor->sensor_class, $sensor->sensor_type, $sensor->sensor_index],
            'rrd_def'      => RrdDefinition::make()->addDataset('sensor', 'GAUGE'),
        ];

        app('Datastore')->put($this->device, 'sensor', $tags, ['sensor' => $sensorValue]);
    }


    private function RrdWriteStats(string $upsID, array $data): void
    {
        $writer = new NutRrdWriter();
        $fields = $writer->buildFields($data);
        $tags = ['rrd_name' => ['app', 'ups-nut', $this->app->app_id, $upsID]];

        $writer->write($this->device, 'ups-nut', $this->app->app_id, $upsID, $fields, $tags);
    }
}
