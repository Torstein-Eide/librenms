<?php

namespace LibreNMS\Agent\Module;

use App\Models\Application;
use App\Models\Sensor;
use App\Models\StateTranslation;
use LibreNMS\Enum\Severity;
use LibreNMS\RRD\RrdDefinition;

/**
 * SMART agent module (payload version 2+).
 *
 * Payload structure (data.tables.disks keyed by "<model>+<serial>"):
 * {
 *   "version": 2,
 *   "data": {
 *     "counters": { "devices_total": 8, "over_temp": 0, "unhealthy": 0, ... },
 *     "errors": [],
 *     "tables": {
 *       "disks": {
 *         "<model>+<serial>": {
 *           "attributes": { "ata": [ { "id": 5, "name": "...", "raw": { "value": 0 }, ... } ] },
 *           "health":      { "smart_passed": true, "command_exit": 0, ... },
 *           "identity":    { "dev_name": "sda", "device_path": "/dev/sda", ... },
 *           "power":       { "power_on_time": { "hours": 1234 }, "power_cycle_count": 5 },
 *           "selftest":    { ... },
 *           "stats":       { ... },
 *           "temperature": { "current_c": 35, "lifetime_max_c": 50, "over_limit": false, ... }
 *         }
 *       }
 *     }
 *   }
 * }
 */
class smart
{
    private int $DISCOVERY_INTERVAL = 3;

    private array $payload = [];
    private array $appData = [];
    private array $discovery = [];

    public function __construct(private array $device, private Application $app)
    {
    }

    public function run(array $payload): void
    {
        $this->payload = $payload;

        $this->loadAppData();

        if ($this->checkIfTimeForDiscovery()) {
            $this->discovery['tick'] = ($this->discovery['tick'] ?? 0) + 1;
        } else {
            $this->discovery();
            $this->discovery['tick'] = 0;
        }

        $this->poll();
        $this->saveAppData();

        $counters = $payload['data']['counters'] ?? [];
        update_application($this->app, 'ok', [
            'devices_total'    => (int) ($counters['devices_total'] ?? 0),
            'over_temp'        => (int) ($counters['over_temp'] ?? 0),
            'unhealthy'        => (int) ($counters['unhealthy'] ?? 0),
            'command_failures' => (int) ($counters['command_failures'] ?? 0),
            'parse_failures'   => (int) ($counters['parse_failures'] ?? 0),
        ]);
    }

    // ── App data persistence ─────────────────────────────────────────────────

    private function loadAppData(): void
    {
        $data = $this->app->data;
        if (is_array($data)) {
            $this->appData = $data;
        }
        $this->discovery = $this->appData['Discovery'] ?? [];
    }

    private function saveAppData(): void
    {
        $data = $this->payload['data'] ?? [];
        $this->appData = array_merge($data, ['Discovery' => $this->discovery]);
        $this->app->data = $this->appData;
        $this->app->save();
    }

    // ── Discovery ────────────────────────────────────────────────────────────

    private function checkIfTimeForDiscovery(): bool
    {
        $disks = $this->payload['data']['tables']['disks'] ?? [];

        if (empty($this->discovery)) {
            return false; // first poll, run discovery
        }

        $currentCount = count($disks);
        if ($currentCount > ($this->discovery['disk_count'] ?? 0)) {
            return false; // new disk added, run immediately
        }

        if (($this->discovery['tick'] ?? 0) < $this->DISCOVERY_INTERVAL) {
            return true; // interval not reached, skip
        }

        return false; // interval reached, run
    }

    private function discovery(): void
    {
        echo '*';

        $disks = $this->payload['data']['tables']['disks'] ?? [];

        $this->discovery['disk_count'] = count($disks);
        $this->discovery['disk_list'] = [];

        app()->forgetInstance('sensor-discovery');

        foreach ($disks as $diskKey => $disk) {
            $this->discovery['disk_list'][(string) $diskKey] = ['sensors' => []];
            $this->discoverDisk((string) $diskKey, is_array($disk) ? $disk : []);
        }

        foreach (['smart_temperature', 'smart_health', 'smart_wear', 'smart_selftest_short', 'smart_selftest_long'] as $type) {
            app('sensor-discovery')->sync(sensor_type: $type);
        }

        $this->cleanupStaleSensors();
    }

    private function discoverDisk(string $diskKey, array $disk): void
    {
        $idx = $this->diskIndex($diskKey);
        $devName = $disk['identity']['dev_name'] ?? $idx;
        $group = 'SMART' ;
        $nav = $this->diskNavigation($diskKey);

        // ── Temperature ──────────────────────────────────────────────────────
        [$tempPath, $tempValue] = $this->temperaturePathAndValue($disk);
        if ($tempPath !== null && $tempValue !== null) {
            $index = "{$idx}_temp";
            $this->registerSensorPath($diskKey, $index, $tempPath);
            app('sensor-discovery')->discover(new Sensor([
                'device_id'         => $this->device['device_id'],
                'poller_type'       => 'agent',
                'sensor_class'      => 'temperature',
                'sensor_type'       => 'smart_temperature',
                'sensor_index'      => $index,
                'sensor_oid'        => "app:smart:{$index}",
                'group'             => $group,
                'sensor_navigation' => $nav,
                'sensor_descr'      => "{$group} {$devName} Temperature",
                'sensor_current'    => $tempValue,
                'sensor_limit_low_warn' => 5,
                'sensor_limit_warn' => 65,
                'sensor_limit'      => 75,
            ]));
        }

        // ── Health (state) ───────────────────────────────────────────────────
        $healthIndex = "{$idx}_health";
        $healthValue = isset($disk['health']['smart_passed'])
            ? ($disk['health']['smart_passed'] ? 0 : 1)
            : 0;
        app('sensor-discovery')
            ->discover(new Sensor([
                'device_id'         => $this->device['device_id'],
                'poller_type'       => 'agent',
                'sensor_class'      => 'state',
                'sensor_type'       => 'smart_health',
                'sensor_index'      => $healthIndex,
                'sensor_oid'        => "app:smart:{$healthIndex}",
                'group'             => $group,
                'sensor_navigation' => $nav,
                'sensor_descr'      => "{$group} {$devName} Health",
                'sensor_current'    => $healthValue,
            ]))
            ->withStateTranslations('smart_health', [
                StateTranslation::define('Passed', 0, Severity::Ok),
                StateTranslation::define('Failed', 1, Severity::Error),
            ]);

        // ── Wear level ───────────────────────────────────────────────────────
        $wear = $this->extractWear($disk);
        if ($wear !== null) {
            $wearIndex = "{$idx}_wear";
            // NVMe wear can be read via a simple payload path; ATA is computed
            $nvmeUsed = $disk['health']['nvme_smart_health_information_log']['percentage_used'] ?? null;
            if ($nvmeUsed !== null) {
                $this->registerSensorPath($diskKey, $wearIndex, 'health.nvme_smart_health_information_log.percentage_used');
            }
            app('sensor-discovery')->discover(new Sensor([
                'device_id'         => $this->device['device_id'],
                'poller_type'       => 'agent',
                'sensor_class'      => 'percent',
                'sensor_type'       => 'smart_wear',
                'sensor_index'      => $wearIndex,
                'sensor_oid'        => "app:smart:{$wearIndex}",
                'group'             => $group,
                'sensor_navigation' => $nav,
                'sensor_descr'      => "{$group} {$devName} Wear Remaining",
                'sensor_current'    => $wear,
                'sensor_limit_low_warn' => 20,
                'sensor_limit_low'  => 10,
            ]));
        }

        // ── Self-test age ────────────────────────────────────────────────────
        $shortAge = $this->hoursSinceTest($disk, 'short');
        if ($shortAge !== null) {
            $shortIndex = "{$idx}_selftest_short";
            app('sensor-discovery')->discover(new Sensor([
                'device_id'         => $this->device['device_id'],
                'poller_type'       => 'agent',
                'sensor_class'      => 'runtime',
                'sensor_type'       => 'smart_selftest_short',
                'sensor_index'      => $shortIndex,
                'sensor_oid'        => "app:smart:{$shortIndex}",
                'group'             => $group,
                'sensor_navigation' => $nav,
                'sensor_descr'      => "{$group} {$devName} Last Short Test",
                'sensor_current'    => (float) $shortAge,
                'sensor_multiplier' => 60,
                'sensor_limit_warn'  => 1440,
                'sensor_max'        => 1600,
                'sensor_min'        => 0,
            ]));
        }

        $longAge = $this->hoursSinceTest($disk, 'extended');
        if ($longAge !== null) {
            $longIndex = "{$idx}_selftest_long";
            app('sensor-discovery')->discover(new Sensor([
                'device_id'         => $this->device['device_id'],
                'poller_type'       => 'agent',
                'sensor_class'      => 'runtime',
                'sensor_type'       => 'smart_selftest_long',
                'sensor_index'      => $longIndex,
                'sensor_oid'        => "app:smart:{$longIndex}",
                'group'             => $group,
                'sensor_navigation' => $nav,
                'sensor_descr'      => "{$devName} Last Long Test",
                'sensor_current'    => (float) $longAge,
                'sensor_multiplier' => 60,
                'sensor_limit_warn'  => 57600,
                'sensor_max'        => 60000,
                'sensor_min'        => 0,
            ]));
        }
    }

    private function cleanupStaleSensors(): void
    {
        $disks = $this->payload['data']['tables']['disks'] ?? [];
        $expected = [];
        foreach (array_keys($disks) as $diskKey) {
            $idx = $this->diskIndex((string) $diskKey);
            foreach (['temp', 'health', 'wear', 'selftest_short', 'selftest_long'] as $suffix) {
                $expected[] = "app:smart:{$idx}_{$suffix}";
            }
        }

        $deleted = Sensor::where('device_id', $this->device['device_id'])
            ->where('sensor_oid', 'like', 'app:smart:%')
            ->whereNotIn('sensor_oid', $expected)
            ->delete();

        if ($deleted > 0) {
            echo PHP_EOL . "smart: removed {$deleted} stale sensor(s)" . PHP_EOL;
        }
    }

    // ── Polling ──────────────────────────────────────────────────────────────

    private function poll(): void
    {
        $disks = $this->payload['data']['tables']['disks'] ?? [];
        foreach ($disks as $diskKey => $disk) {
            $disk = is_array($disk) ? $disk : [];
            $this->updateSensors((string) $diskKey, $disk);
            $this->pollDiskRrd((string) $diskKey, $disk);
        }
    }

    /**
     * Write per-disk RRD data via the app Datastore.
     *
     * ATA disks: one RRD per disk (`smart`) with DS names `id{N}` built
     * dynamically from whatever attribute IDs the disk reports. DS names are
     * stable — new IDs added in a later poll won't appear (RRD is fixed at
     * creation time), but missing IDs are stored as U (unknown).
     *
     * NVMe disks: one RRD per disk (`smart_nvme`) with fixed DS names for
     * the NVMe Smart Health Log fields.
     *
     * Both types also get a shared `smart_power` RRD for temperature,
     * power-on hours, and power cycle count (available on all disk types).
     */
    private function pollDiskRrd(string $diskKey, array $disk): void
    {
        $idx = $this->diskIndex($diskKey);

        // ── ATA attributes ────────────────────────────────────────────────────
        $ataAttrs = $disk['attributes']['ata'] ?? [];
        if (! empty($ataAttrs)) {
            $rrd_def = RrdDefinition::make();
            $fields = [];
            $seenIds = [];
            foreach ($ataAttrs as $attr) {
                $id = $attr['id'] ?? null;
                if ($id === null) {
                    continue;
                }
                $id = (int) $id;
                if (isset($seenIds[$id])) {
                    continue;
                }
                $seenIds[$id] = true;

                $dsName = 'id' . $id;
                $dsNormalized = $dsName . 'Normalized';
                $rawValue = $attr['raw']['string'] ?? $attr['raw']['value'] ?? null;
                $normalizedValue = $attr['value'] ?? null;
                $rrd_def->addDataset($dsName, 'GAUGE', 0);
                $rrd_def->addDataset($dsNormalized, 'GAUGE', 0);
                $fields[$dsName] = $this->extractNumericValue($rawValue);
                $fields[$dsNormalized] = $this->extractNumericValue($normalizedValue);
            }
            if (! empty($fields)) {
                $rrd_name = ['app', 'smart', $this->app->app_id, $idx];
                $tags = [
                    'name'    => 'smart',
                    'app_id'  => $this->app->app_id,
                    'rrd_def' => $rrd_def,
                    'rrd_name' => $rrd_name,
                ];
                app('Datastore')->put($this->device, 'app', $tags, $fields);
            }
        }

        // ── NVMe health log ───────────────────────────────────────────────────
        $nvmeLog = $disk['health']['nvme_smart_health_information_log'] ?? null;
        if ($nvmeLog !== null) {
            $rrd_def_nvme = RrdDefinition::make()
                ->addDataset('pct_used', 'GAUGE', 0, 100)
                ->addDataset('avail_spare', 'GAUGE', 0, 100)
                ->addDataset('media_errors', 'GAUGE', 0)
                ->addDataset('pwr_hours', 'GAUGE', 0)
                ->addDataset('unsafe_shut', 'GAUGE', 0);
            $fields_nvme = [
                'pct_used'    => isset($nvmeLog['percentage_used']) ? (int) $nvmeLog['percentage_used'] : null,
                'avail_spare' => isset($nvmeLog['available_spare']) ? (int) $nvmeLog['available_spare'] : null,
                'media_errors' => isset($nvmeLog['media_errors']) ? (int) $nvmeLog['media_errors'] : null,
                'pwr_hours'   => isset($nvmeLog['power_on_hours']) ? (int) $nvmeLog['power_on_hours'] : null,
                'unsafe_shut' => isset($nvmeLog['unsafe_shutdowns']) ? (int) $nvmeLog['unsafe_shutdowns'] : null,
            ];
            $rrd_name_nvme = ['app', 'smart_nvme', $this->app->app_id, $idx];
            $tags_nvme = [
                'name'     => 'smart',
                'app_id'   => $this->app->app_id,
                'rrd_def'  => $rrd_def_nvme,
                'rrd_name' => $rrd_name_nvme,
            ];
            app('Datastore')->put($this->device, 'app', $tags_nvme, $fields_nvme);
        }

        // ── Power / temperature (all disk types) ──────────────────────────────
        [, $tempC] = $this->temperaturePathAndValue($disk);
        $hours = $disk['power']['power_on_time']['hours'] ?? null;
        $cycles = $disk['power']['power_cycle_count'] ?? null;
        if ($tempC !== null || $hours !== null || $cycles !== null) {
            $rrd_def_pwr = RrdDefinition::make()
                ->addDataset('temp', 'GAUGE', 0, 200)
                ->addDataset('hours', 'GAUGE', 0)
                ->addDataset('cycles', 'GAUGE', 0);
            $fields_pwr = [
                'temp'   => $tempC !== null ? (float) $tempC : null,
                'hours'  => $hours !== null ? (int) $hours : null,
                'cycles' => $cycles !== null ? (int) $cycles : null,
            ];
            $rrd_name_pwr = ['app', 'smart_power', $this->app->app_id, $idx];
            $tags_pwr = [
                'name'     => 'smart',
                'app_id'   => $this->app->app_id,
                'rrd_def'  => $rrd_def_pwr,
                'rrd_name' => $rrd_name_pwr,
            ];
            app('Datastore')->put($this->device, 'app', $tags_pwr, $fields_pwr);
        }
    }

    private function updateSensors(string $diskKey, array $disk): void
    {
        $idx = $this->diskIndex($diskKey);

        $sensors = Sensor::where('device_id', $this->device['device_id'])
            ->where('sensor_oid', 'like', "app:smart:{$idx}_%")
            ->get()
            ->keyBy('sensor_index');

        // Numeric sensors via stored dot-paths (temperature; NVMe wear via percentage_used)
        foreach ($this->discovery['disk_list'][$diskKey]['sensors'] ?? [] as $index => $path) {
            $sensor = $sensors->get($index);
            if (! $sensor) {
                continue;
            }
            $rawValue = $this->getValueFromPayloadPath($disk, $path, $diskKey);
            $value = $rawValue !== null ? (float) $rawValue : null;

            // NVMe wear is stored as percentage_used; convert to remaining
            if ($sensor->sensor_type === 'smart_wear' && $value !== null) {
                $value = 100.0 - $value;
            }

            $this->updateNumericSensor($sensor, $value);
        }

        // Health (state — boolean cast)
        if ($sensor = $sensors->get("{$idx}_health")) {
            $value = isset($disk['health']['smart_passed'])
                ? ($disk['health']['smart_passed'] ? 0 : 1)
                : null;
            $this->updateStateSensor($sensor, $value);
        }

        // ATA wear (computed from attributes, no stored path)
        if (($sensor = $sensors->get("{$idx}_wear")) && ! isset($this->discovery['disk_list'][$diskKey]['sensors']["{$idx}_wear"])) {
            $this->updateNumericSensor($sensor, $this->extractWear($disk));
        }

        // Self-test ages (computed)
        if ($sensor = $sensors->get("{$idx}_selftest_short")) {
            $age = $this->hoursSinceTest($disk, 'short');
            $this->updateNumericSensor($sensor, $age !== null ? (float) $age : null);
        }

        if ($sensor = $sensors->get("{$idx}_selftest_long")) {
            $age = $this->hoursSinceTest($disk, 'extended');
            $this->updateNumericSensor($sensor, $age !== null ? (float) $age : null);
        }
    }

    private function updateNumericSensor(Sensor $sensor, ?float $sensor_value): void
    {
        if ($sensor['sensor_divisor'] && $sensor_value !== 0) {
            $sensor_value /= $sensor['sensor_divisor'];
        }

        if ($sensor['sensor_multiplier']) {
            $sensor_value *= $sensor['sensor_multiplier'];
        }
        $sensor->sensor_current = $sensor_value;
        $sensor->save();

        $tags = [
            'sensor_class' => $sensor->sensor_class,
            'sensor_type'  => $sensor->sensor_type,
            'sensor_descr' => $sensor->sensor_descr,
            'sensor_index' => $sensor->sensor_index,
            'rrd_name'     => ['sensor', $sensor->sensor_class, $sensor->sensor_type, $sensor->sensor_index],
            'rrd_def'      => RrdDefinition::make()->addDataset('sensor', 'GAUGE'),
        ];
        app('Datastore')->put($this->device, 'sensor', $tags, ['sensor' => $sensor_value]);
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

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Stable sensor index from MODEL+SERIAL key — safe chars only, max 80 */
    private function diskIndex(string $key): string
    {
        return substr(preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key), 0, 80);
    }

    private function diskNavigation(string $key): string
    {
        return 'tab=apps/app=smart/disk=' . rawurlencode($key) . '/';
    }

    private function registerSensorPath(string $diskKey, string $index, string $path): void
    {
        $this->discovery['disk_list'][$diskKey]['sensors'][$index] = $path;
    }

    private function getValueFromPayloadPath(array $data, string $path, string $diskKey): mixed
    {
        $path = $this->normalizeDiskPath($path, $diskKey);

        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Accept both disk-relative paths and full payload paths.
     *
     * Examples:
     * - attributes.ata.0.flags.string
     * - data.tables.disks.<diskKey>.attributes.ata.0.flags.string
     */
    private function normalizeDiskPath(string $path, string $diskKey): string
    {
        $prefix = 'data.tables.disks.';
        if (! str_starts_with($path, $prefix)) {
            return $path;
        }

        $diskPrefix = $prefix . $diskKey . '.';
        if (str_starts_with($path, $diskPrefix)) {
            return substr($path, strlen($diskPrefix));
        }

        // If the full path targets a different disk key, this path is invalid for
        // the current disk context.
        return '__invalid_path__';
    }

    /**
     * Return the payload path and numeric temperature value for a disk.
     * Supports both `temperature.current_c` and flat `temperature` payload shapes.
     *
     * @return array{0: ?string, 1: ?float}
     */
    private function temperaturePathAndValue(array $disk): array
    {
        $tempCurrentC = $disk['temperature']['current_c'] ?? null;
        if (is_numeric($tempCurrentC)) {
            return ['temperature.current_c', (float) $tempCurrentC];
        }

        $tempFlat = $disk['temperature'] ?? null;
        if (is_numeric($tempFlat)) {
            return ['temperature', (float) $tempFlat];
        }

        return [null, null];
    }

    /**
     * Extract wear-remaining percentage from a disk.
     * NVMe: uses percentage_used (0=new, 100=end-of-life) → return 100-used.
     * ATA SSD: checks attribute IDs used by V1 to identify SSDs (173=Crucial/Micron,
     * 177=Samsung, 202=SanDisk/WD, 231=Intel, 233=Intel); returns normalised value
     * which represents remaining life on those drives.
     */
    private function extractWear(array $disk): ?float
    {
        // NVMe
        $nvmeUsed = $disk['health']['nvme_smart_health_information_log']['percentage_used'] ?? null;
        if ($nvmeUsed !== null) {
            return (float) (100 - $nvmeUsed);
        }

        // ATA SSD
        foreach ($disk['attributes']['ata'] ?? [] as $attr) {
            if (in_array($attr['id'] ?? -1, [173, 177, 202, 231, 233], true)) {
                return isset($attr['value']) ? (float) $attr['value'] : null;
            }
        }

        return null;
    }

    /**
     * Compute hours elapsed since the most recent self-test of a given type.
     * $typePattern: 'short' or 'extended' (matched case-insensitively against
     * the type.string field of the selftest log entries).
     */
    private function hoursSinceTest(array $disk, string $typePattern): ?int
    {
        $currentHours = $disk['power']['power_on_time']['hours'] ?? null;
        if ($currentHours === null) {
            return null;
        }

        $table = $disk['selftest']['ata_smart_self_test_log']['extended']['table'] ?? [];

        foreach ($table as $entry) {
            $typeStr = strtolower($entry['type']['string'] ?? '');
            if (str_contains($typeStr, $typePattern)) {
                $testHours = $entry['lifetime_hours'] ?? null;
                if ($testHours !== null) {
                    return max(0, (int) $currentHours - (int) $testHours);
                }
            }
        }

        return null;
    }

    /**
     * SMART raw fields can include extra text like "303 (Average 302)".
     * Keep only the first numeric token for RRD storage.
     */
    private function extractNumericValue(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*\/\s*(-?\d+(?:\.\d+)?)\s*$/', $value, $fraction) === 1) {
            $numerator = (float) $fraction[1];
            $denominator = (float) $fraction[2];
            if ($numerator == 0.0) {
                return 0.0;
            }
            if ($denominator != 0.0) {
                return $numerator / $denominator;
            }
        }

        if (preg_match('/-?\d+(?:\.\d+)?/', $value, $matches) !== 1) {
            return null;
        }

        return (float) $matches[0];
    }
}
