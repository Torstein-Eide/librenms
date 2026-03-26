<?php

namespace LibreNMS\Polling\Modules;

use App\Models\Sensor;
use App\Models\SensorToStateIndex;
use App\Models\StateIndex;
use App\Models\StateTranslation;
use Illuminate\Support\Collection;

/**
 * BtrfsSensorSync handles CRUD operations for btrfs synthetic sensors.
 *
 * Sensor types:
 * - state: IO/Scrub/Balance status sensors (linked to state_indexes)
 * - count: IO error count sensors with warn/error thresholds
 *
 * All operations are idempotent and keyed by (device_id, sensor_class,
 * poller_type, sensor_type, sensor_index).
 */
class BtrfsSensorSync
{
    public const STATE_SENSOR_IO = 'btrfsIoStatusState';
    public const STATE_SENSOR_SCRUB = 'btrfsScrubStatusState';
    public const STATE_SENSOR_BALANCE = 'btrfsBalanceStatusState';

    public const COUNT_SENSOR_IO_ERRORS = 'btrfsIoErrors';
    public const LEGACY_COUNT_SENSOR_IO_ERRORS = 'btrfsIoErrorsSum';

    public const COUNT_SENSOR_WARN_LEVEL = 5;
    public const COUNT_SENSOR_ERROR_LEVEL = 10;

    private BtrfsStatusMapper $statusMapper;
    private array $stateSensorTypes;
    private array $countSensorTypes;

    public function __construct(BtrfsStatusMapper $statusMapper)
    {
        $this->statusMapper = $statusMapper;
        $this->stateSensorTypes = [
            self::STATE_SENSOR_IO,
            self::STATE_SENSOR_SCRUB,
            self::STATE_SENSOR_BALANCE,
        ];
        $this->countSensorTypes = [
            self::COUNT_SENSOR_IO_ERRORS,
            self::LEGACY_COUNT_SENSOR_IO_ERRORS,
        ];
    }

    public function ensureStateIndexes(): array
    {
        $states = $this->statusMapper->getStatusStates();
        $indexes = [];

        foreach ($this->stateSensorTypes as $sensor_type) {
            $indexes[$sensor_type] = $this->ensureStateIndex($sensor_type, $states);
        }

        return $indexes;
    }

    private function ensureStateIndex(string $state_name, array $states): ?int
    {
        /** @var ?StateIndex $stateIndex */
        $stateIndex = StateIndex::firstOrCreate(['state_name' => $state_name]);
        $state_index_id = $stateIndex->state_index_id;
        $created_index = $stateIndex->wasRecentlyCreated;

        if (! $state_index_id) {
            return null;
        }

        $existing_translations = $stateIndex->translations()->get()->keyBy('state_value');

        $created = 0;
        $updated = 0;

        foreach ($states as $state) {
            $state_value = (int) $state['value'];
            /** @var ?StateTranslation $existing */
            $existing = $existing_translations->get($state_value);
            $translation_data = [
                'state_index_id' => $state_index_id,
                'state_descr' => $state['descr'],
                'state_draw_graph' => $state['graph'],
                'state_value' => $state_value,
                'state_generic_value' => $state['generic'],
            ];

            if ($existing) {
                $needs_update = $existing->state_descr !== $translation_data['state_descr']
                    || $existing->state_draw_graph !== $translation_data['state_draw_graph']
                    || $existing->state_generic_value !== $translation_data['state_generic_value'];

                if ($needs_update) {
                    $existing->update($translation_data);
                    $updated++;
                }
            } else {
                StateTranslation::create($translation_data);
                $created++;
            }
        }

        if ($created_index || $created > 0 || $updated > 0) {
            echo ' btrfs-state: ' . $state_name
                . ' index=' . (int) $state_index_id
                . ' created_index=' . ($created_index ? 'yes' : 'no')
                . ' created=' . $created
                . ' updated=' . $updated . PHP_EOL;
        }

        return (int) $state_index_id;
    }

    public function upsertStateSensor(
        array $device,
        string $sensor_index,
        string $sensor_type,
        string $sensor_descr,
        int $sensor_current,
        ?int $state_index_id,
        string $sensor_group
    ): void {
        $sensor = $this->findOrCreateStateSensor($device, $sensor_index, $sensor_type, $sensor_descr, $sensor_current, $sensor_group);

        $this->writeSensorRrd($device, 'state', $sensor_type, $sensor_descr, $sensor_current);

        if (! $sensor || ! $state_index_id) {
            return;
        }

        $this->syncSensorStateIndex($sensor, $state_index_id);
    }

    private function findOrCreateStateSensor(
        array $device,
        string $sensor_index,
        string $sensor_type,
        string $sensor_descr,
        int $sensor_current,
        string $sensor_group
    ): ?Sensor {
        return Sensor::withoutGlobalScopes()->updateOrCreate(
            [
                'device_id' => $device['device_id'],
                'sensor_class' => 'state',
                'poller_type' => 'agent',
                'sensor_type' => $sensor_type,
                'sensor_index' => $sensor_index,
            ],
            [
                'sensor_oid' => 'app:btrfs:' . $sensor_index,
                'sensor_descr' => $sensor_descr,
                'sensor_divisor' => 1,
                'sensor_multiplier' => 1,
                'sensor_current' => $sensor_current,
                'group' => $sensor_group,
                'rrd_type' => 'GAUGE',
            ]
        );
    }

    private function syncSensorStateIndex(Sensor $sensor, int $state_index_id): void
    {
        /** @var ?SensorToStateIndex $link */
        $link = SensorToStateIndex::where('sensor_id', $sensor->sensor_id)->first();

        if ($link) {
            $link->update(['state_index_id' => $state_index_id]);
        } else {
            SensorToStateIndex::create([
                'sensor_id' => $sensor->sensor_id,
                'state_index_id' => $state_index_id,
            ]);
        }
    }

    public function deleteStateSensor(array $device, string $sensor_index, string $sensor_type): void
    {
        $this->findStateSensor($device, $sensor_index, $sensor_type)?->delete();
    }

    private function findStateSensor(array $device, string $sensor_index, string $sensor_type): ?Sensor
    {
        return Sensor::withoutGlobalScopes()
            ->where('device_id', $device['device_id'])
            ->where('sensor_class', 'state')
            ->where('poller_type', 'agent')
            ->where('sensor_type', $sensor_type)
            ->where('sensor_index', $sensor_index)
            ->first();
    }

    public function upsertCountSensor(
        array $device,
        string $sensor_index,
        string $sensor_type,
        string $sensor_descr,
        float $sensor_current,
        string $sensor_group
    ): void {
        $this->findOrCreateCountSensor($device, $sensor_index, $sensor_type, $sensor_descr, $sensor_current, $sensor_group);

        $this->writeSensorRrd($device, 'count', $sensor_type, $sensor_descr, $sensor_current);
    }

    private function findOrCreateCountSensor(
        array $device,
        string $sensor_index,
        string $sensor_type,
        string $sensor_descr,
        float $sensor_current,
        string $sensor_group
    ): ?Sensor {
        return Sensor::withoutGlobalScopes()->updateOrCreate(
            [
                'device_id' => $device['device_id'],
                'sensor_class' => 'count',
                'poller_type' => 'agent',
                'sensor_type' => $sensor_type,
                'sensor_index' => $sensor_index,
            ],
            [
                'sensor_oid' => 'app:btrfs:' . $sensor_index,
                'sensor_descr' => $sensor_descr,
                'sensor_divisor' => 1,
                'sensor_multiplier' => 1,
                'sensor_current' => $sensor_current,
                'sensor_limit_warn' => self::COUNT_SENSOR_WARN_LEVEL,
                'sensor_limit' => self::COUNT_SENSOR_ERROR_LEVEL,
                'group' => $sensor_group,
                'rrd_type' => 'GAUGE',
            ]
        );
    }

    private function writeSensorRrd(array $device, string $sensor_class, string $sensor_type, string $sensor_descr, float $sensor_current): void
    {
        $sensor_rrd_def = \LibreNMS\RRD\RrdDefinition::make()->addDataset('sensor', 'GAUGE');

        app('Datastore')->put($device, 'sensor', [
            'sensor_class' => $sensor_class,
            'sensor_type' => $sensor_type,
            'sensor_descr' => $sensor_descr,
            'rrd_def' => $sensor_rrd_def,
            'rrd_name' => null,
        ], ['sensor' => $sensor_current]);
    }

    public function cleanupObsoleteCountSensors(array $device, array $expected_sensor_indexes): void
    {
        $this->findObsoleteCountSensors($device, $expected_sensor_indexes)
            ->each(fn (Sensor $sensor) => $sensor->delete());
    }

    private function findObsoleteCountSensors(array $device, array $expected_sensor_indexes): Collection
    {
        return Sensor::withoutGlobalScopes()
            ->where('device_id', $device['device_id'])
            ->where('sensor_class', 'count')
            ->where('poller_type', 'agent')
            ->whereIn('sensor_type', $this->countSensorTypes)
            ->get()
            ->filter(function (Sensor $sensor) use ($expected_sensor_indexes) {
                return ! isset($expected_sensor_indexes[$sensor->sensor_type][$sensor->sensor_index]);
            });
    }

    public function cleanupObsoleteStateSensors(array $device, array $expected_sensor_indexes): void
    {
        $this->findObsoleteStateSensors($device, $expected_sensor_indexes)
            ->each(function (Sensor $sensor) {
                SensorToStateIndex::where('sensor_id', $sensor->sensor_id)->delete();
                $sensor->delete();
            });
    }

    private function findObsoleteStateSensors(array $device, array $expected_sensor_indexes): Collection
    {
        return Sensor::withoutGlobalScopes()
            ->where('device_id', $device['device_id'])
            ->where('sensor_class', 'state')
            ->where('poller_type', 'agent')
            ->whereIn('sensor_type', $this->stateSensorTypes)
            ->get()
            ->filter(function (Sensor $sensor) use ($expected_sensor_indexes) {
                return ! isset($expected_sensor_indexes[$sensor->sensor_type][$sensor->sensor_index]);
            });
    }

    public function deleteAllStateAndCountSensors(array $device): void
    {
        $stateSensors = Sensor::withoutGlobalScopes()
            ->where('device_id', $device['device_id'])
            ->where('sensor_class', 'state')
            ->where('poller_type', 'agent')
            ->whereIn('sensor_type', $this->stateSensorTypes)
            ->get();

        $stateSensorIds = $stateSensors->pluck('sensor_id');

        SensorToStateIndex::whereIn('sensor_id', $stateSensorIds)->delete();
        Sensor::withoutGlobalScopes()
            ->whereIn('sensor_id', $stateSensorIds)
            ->delete();

        Sensor::withoutGlobalScopes()
            ->where('device_id', $device['device_id'])
            ->where('sensor_class', 'count')
            ->where('poller_type', 'agent')
            ->whereIn('sensor_type', $this->countSensorTypes)
            ->delete();
    }

    public function getStateSensorTypes(): array
    {
        return $this->stateSensorTypes;
    }

    public function getCountSensorTypes(): array
    {
        return $this->countSensorTypes;
    }
}
