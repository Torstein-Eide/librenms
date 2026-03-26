<?php

namespace LibreNMS\Polling\Modules;

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
        $state_index_id = dbFetchCell('SELECT `state_index_id` FROM `state_indexes` WHERE `state_name` = ?', [$state_name]);
        $created_index = false;

        if (! $state_index_id) {
            $state_index_id = dbInsert(['state_name' => $state_name], 'state_indexes');
            $created_index = (bool) $state_index_id;
        }

        if (! $state_index_id) {
            return null;
        }

        $existing_translations = dbFetchRows(
            'SELECT `state_translation_id`, `state_value`, `state_descr`, `state_draw_graph`, `state_generic_value` FROM `state_translations` WHERE `state_index_id` = ?',
            [$state_index_id]
        );
        $translations_by_value = [];
        foreach ($existing_translations as $row) {
            $translations_by_value[(int) $row['state_value']] = $row;
        }

        $created = 0;
        $updated = 0;

        foreach ($states as $state) {
            $state_value = (int) $state['value'];
            $existing = $translations_by_value[$state_value] ?? null;
            $translation = [
                'state_index_id' => $state_index_id,
                'state_descr' => $state['descr'],
                'state_draw_graph' => $state['graph'],
                'state_value' => $state_value,
                'state_generic_value' => $state['generic'],
            ];

            if ($existing) {
                $needs_update = (string) $existing['state_descr'] !== (string) $translation['state_descr']
                    || (int) $existing['state_draw_graph'] !== (int) $translation['state_draw_graph']
                    || (int) $existing['state_generic_value'] !== (int) $translation['state_generic_value'];

                if ($needs_update) {
                    dbUpdate($translation, 'state_translations', '`state_translation_id` = ?', [(int) $existing['state_translation_id']]);
                    $updated++;
                }
            } else {
                dbInsert($translation, 'state_translations');
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
        $sensor_id = dbFetchCell(
            'SELECT `sensor_id` FROM `sensors` WHERE `device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` = ? AND `sensor_index` = ?',
            [$device['device_id'], 'state', 'agent', $sensor_type, $sensor_index]
        );

        $data = [
            'poller_type' => 'agent',
            'sensor_class' => 'state',
            'device_id' => $device['device_id'],
            'sensor_oid' => 'app:btrfs:' . $sensor_index,
            'sensor_index' => $sensor_index,
            'sensor_type' => $sensor_type,
            'sensor_descr' => $sensor_descr,
            'sensor_divisor' => 1,
            'sensor_multiplier' => 1,
            'sensor_current' => $sensor_current,
            'group' => $sensor_group,
            'rrd_type' => 'GAUGE',
        ];

        if ($sensor_id) {
            dbUpdate($data, 'sensors', '`sensor_id` = ?', [$sensor_id]);
        } else {
            $sensor_id = dbInsert($data, 'sensors');
        }

        $sensor_rrd_name = get_sensor_rrd_name($device, $data);
        $sensor_rrd_def = \LibreNMS\RRD\RrdDefinition::make()->addDataset('sensor', 'GAUGE');
        app('Datastore')->put($device, 'sensor', [
            'sensor_class' => 'state',
            'sensor_type' => $sensor_type,
            'sensor_descr' => $sensor_descr,
            'rrd_def' => $sensor_rrd_def,
            'rrd_name' => $sensor_rrd_name,
        ], ['sensor' => $sensor_current]);

        if (! $sensor_id || ! $state_index_id) {
            return;
        }

        $link_id = dbFetchCell('SELECT `sensors_to_state_translations_id` FROM `sensors_to_state_indexes` WHERE `sensor_id` = ?', [$sensor_id]);
        if ($link_id) {
            dbUpdate(['state_index_id' => $state_index_id], 'sensors_to_state_indexes', '`sensors_to_state_translations_id` = ?', [$link_id]);
        } else {
            dbInsert(['sensor_id' => $sensor_id, 'state_index_id' => $state_index_id], 'sensors_to_state_indexes');
        }
    }

    public function deleteStateSensor(array $device, string $sensor_index, string $sensor_type): void
    {
        dbDelete(
            'sensors_to_state_indexes',
            '`sensor_id` IN (SELECT `sensor_id` FROM `sensors` WHERE `device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` = ? AND `sensor_index` = ?)',
            [$device['device_id'], 'state', 'agent', $sensor_type, $sensor_index]
        );
        dbDelete(
            'sensors',
            '`device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` = ? AND `sensor_index` = ?',
            [$device['device_id'], 'state', 'agent', $sensor_type, $sensor_index]
        );
    }

    public function upsertCountSensor(
        array $device,
        string $sensor_index,
        string $sensor_type,
        string $sensor_descr,
        float $sensor_current,
        string $sensor_group
    ): void {
        $sensor_id = dbFetchCell(
            'SELECT `sensor_id` FROM `sensors` WHERE `device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` = ? AND `sensor_index` = ?',
            [$device['device_id'], 'count', 'agent', $sensor_type, $sensor_index]
        );

        $data = [
            'poller_type' => 'agent',
            'sensor_class' => 'count',
            'device_id' => $device['device_id'],
            'sensor_oid' => 'app:btrfs:' . $sensor_index,
            'sensor_index' => $sensor_index,
            'sensor_type' => $sensor_type,
            'sensor_descr' => $sensor_descr,
            'sensor_divisor' => 1,
            'sensor_multiplier' => 1,
            'sensor_current' => $sensor_current,
            'sensor_limit_warn' => self::COUNT_SENSOR_WARN_LEVEL,
            'sensor_limit' => self::COUNT_SENSOR_ERROR_LEVEL,
            'group' => $sensor_group,
            'rrd_type' => 'GAUGE',
        ];

        if ($sensor_id) {
            dbUpdate($data, 'sensors', '`sensor_id` = ?', [$sensor_id]);
        } else {
            $sensor_id = dbInsert($data, 'sensors');
        }

        $sensor_rrd_name = get_sensor_rrd_name($device, $data);
        $sensor_rrd_def = \LibreNMS\RRD\RrdDefinition::make()->addDataset('sensor', 'GAUGE');
        app('Datastore')->put($device, 'sensor', [
            'sensor_class' => 'count',
            'sensor_type' => $sensor_type,
            'sensor_descr' => $sensor_descr,
            'rrd_def' => $sensor_rrd_def,
            'rrd_name' => $sensor_rrd_name,
        ], ['sensor' => $sensor_current]);
    }

    public function cleanupObsoleteCountSensors(array $device, array $expected_sensor_indexes): void
    {
        $rows = dbFetchRows(
            'SELECT `sensor_id`, `sensor_type`, `sensor_index` FROM `sensors` WHERE `device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` IN (?,?)',
            [$device['device_id'], 'count', 'agent', $this->countSensorTypes[0], $this->countSensorTypes[1]]
        );

        foreach ($rows as $row) {
            $sensor_id = $row['sensor_id'] ?? null;
            $sensor_type = (string) ($row['sensor_type'] ?? '');
            $sensor_index = (string) ($row['sensor_index'] ?? '');
            if (! is_numeric($sensor_id) || $sensor_type === '' || $sensor_index === '') {
                continue;
            }

            if (isset($expected_sensor_indexes[$sensor_type][$sensor_index])) {
                continue;
            }

            dbDelete('sensors', '`sensor_id` = ?', [$sensor_id]);
        }
    }

    public function cleanupObsoleteStateSensors(array $device, array $expected_sensor_indexes): void
    {
        $placeholders = implode(',', array_fill(0, count($this->stateSensorTypes), '?'));
        $rows = dbFetchRows(
            'SELECT `sensor_id`, `sensor_type`, `sensor_index` FROM `sensors` WHERE `device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` IN (' . $placeholders . ')',
            array_merge([$device['device_id'], 'state', 'agent'], $this->stateSensorTypes)
        );

        foreach ($rows as $row) {
            $sensor_id = $row['sensor_id'] ?? null;
            $sensor_type = (string) ($row['sensor_type'] ?? '');
            $sensor_index = (string) ($row['sensor_index'] ?? '');
            if (! is_numeric($sensor_id) || $sensor_type === '' || $sensor_index === '') {
                continue;
            }

            if (isset($expected_sensor_indexes[$sensor_type][$sensor_index])) {
                continue;
            }

            dbDelete('sensors_to_state_indexes', '`sensor_id` = ?', [$sensor_id]);
            dbDelete('sensors', '`sensor_id` = ?', [$sensor_id]);
        }
    }

    public function deleteAllStateAndCountSensors(array $device): void
    {
        $placeholders = implode(',', array_fill(0, count($this->stateSensorTypes), '?'));
        dbDelete(
            'sensors_to_state_indexes',
            '`sensor_id` IN (SELECT `sensor_id` FROM `sensors` WHERE `device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` IN (' . $placeholders . '))',
            array_merge([$device['device_id'], 'state', 'agent'], $this->stateSensorTypes)
        );
        dbDelete(
            'sensors',
            '`device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` IN (' . $placeholders . '))',
            array_merge([$device['device_id'], 'state', 'agent'], $this->stateSensorTypes)
        );

        $count_placeholders = implode(',', array_fill(0, count($this->countSensorTypes), '?'));
        dbDelete(
            'sensors',
            '`device_id` = ? AND `sensor_class` = ? AND `poller_type` = ? AND `sensor_type` IN (' . $count_placeholders . '))',
            array_merge([$device['device_id'], 'count', 'agent'], $this->countSensorTypes)
        );
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
