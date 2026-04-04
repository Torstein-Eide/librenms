<?php

namespace LibreNMS\Polling\Modules;

use App\Models\Sensor;
use App\Models\SensorToStateIndex;
use App\Models\StateTranslation;
use LibreNMS\Enum\Severity;
use LibreNMS\RRD\RrdDefinition;

/**
 * BtrfsSensorSync handles sensor creation, update, and deletion for btrfs sensors.
 *
 * Sensor types:
 * - state: IO/Scrub/Balance status sensors — poller_type='agent', sensor_type = unique btrfs state name
 * - count: IO error count sensors — poller_type='agent', sensor_type = COUNT_SENSOR_IO_ERRORS
 *
 * Discovery uses the app('sensor-discovery') pipeline:
 *   discoverStateSensor() / discoverCountSensor() → buffer sensors
 *   syncDiscoveredSensors()                       → creates new, updates existing, deletes removed
 *
 * Polling updates values directly:
 *   writeStateSensorRrd()    — updates sensor_current in DB + writes RRD (every poll)
 *   updateCountSensorValue() — updates sensor_current in DB + writes RRD (every poll)
 */
class BtrfsSensorSync
{
    public const STATE_SENSOR_IO = 'btrfsIoStatusState';
    public const STATE_SENSOR_SCRUB = 'btrfsScrubStatusState';
    public const STATE_SENSOR_SCRUB_OPS = 'btrfsScrubOpsState';
    public const STATE_SENSOR_BALANCE = 'btrfsBalanceStatusState';

    public const COUNT_SENSOR_IO_ERRORS = 'btrfsIoErrors';
    public const LEGACY_COUNT_SENSOR_IO_ERRORS = 'btrfsIoErrorsSum';

    public const COUNT_SENSOR_WARN_LEVEL = 5;
    public const COUNT_SENSOR_ERROR_LEVEL = 10;

    private const STATE_SENSOR_TYPES = [
        self::STATE_SENSOR_IO,
        self::STATE_SENSOR_SCRUB,
        self::STATE_SENSOR_SCRUB_OPS,
        self::STATE_SENSOR_BALANCE,
    ];

    private const COUNT_SENSOR_TYPES = [
        self::COUNT_SENSOR_IO_ERRORS,
        self::LEGACY_COUNT_SENSOR_IO_ERRORS,
    ];

    // ==========================================================================
    // Discovery — buffer sensors and sync to DB
    // ==========================================================================

    /**
     * Reset the sensor-discovery singleton so sensors from other modules
     * do not bleed into this app's sync() calls.
     * Call once before any discoverStateSensor() / discoverCountSensor() calls.
     */
    public function resetDiscoveryBuffer(): void
    {
        app()->forgetInstance('sensor-discovery');
    }

    /**
     * Buffer a state sensor in the discovery singleton.
     * Call syncDiscoveredSensors() after all sensors are buffered.
     */
    public function discoverStateSensor(
        array $device,
        string $sensorIndex,
        string $sensorType,
        string $sensorDescr,
        int $sensorCurrent,
        string $sensorGroup
    ): void {
        $translations = $sensorType === self::STATE_SENSOR_SCRUB_OPS
            ? $this->getScrubOpsTranslations()
            : $this->getStatusTranslations();

        app('sensor-discovery')
            ->discover(new Sensor([
                'device_id'          => $device['device_id'],
                'sensor_class'       => 'state',
                'poller_type'        => 'agent',
                'sensor_type'        => $sensorType,
                'sensor_index'       => $sensorIndex,
                'sensor_oid'         => 'app:btrfs:' . $sensorIndex,
                'sensor_descr'       => $sensorDescr,
                'sensor_current'     => $sensorCurrent,
                'sensor_divisor'     => 1,
                'sensor_multiplier'  => 1,
                'group'              => $sensorGroup,
            ]))
            ->withStateTranslations($sensorType, $translations);
    }

    /**
     * Buffer a count sensor in the discovery singleton.
     * Call syncDiscoveredSensors() after all sensors are buffered.
     */
    public function discoverCountSensor(
        array $device,
        string $sensorIndex,
        string $sensorDescr,
        float $sensorCurrent,
        string $sensorGroup
    ): void {
        app('sensor-discovery')->discover(new Sensor([
            'device_id'          => $device['device_id'],
            'sensor_class'       => 'count',
            'poller_type'        => 'agent',
            'sensor_type'        => self::COUNT_SENSOR_IO_ERRORS,
            'sensor_index'       => $sensorIndex,
            'sensor_oid'         => 'app:btrfs:' . $sensorIndex,
            'sensor_descr'       => $sensorDescr,
            'sensor_current'     => $sensorCurrent,
            'sensor_limit_warn'  => self::COUNT_SENSOR_WARN_LEVEL,
            'sensor_limit'       => self::COUNT_SENSOR_ERROR_LEVEL,
            'sensor_divisor'     => 1,
            'sensor_multiplier'  => 1,
            'group'              => $sensorGroup,
        ]));
    }

    /**
     * Sync all buffered sensors to the DB: creates new sensors, updates existing
     * ones, and deletes sensors whose indexes are no longer in the buffer.
     * One sync() call per sensor_type scopes the delete to that type only.
     * Must be called after all discoverStateSensor() / discoverCountSensor() calls.
     */
    public function syncDiscoveredSensors(): void
    {
        foreach (self::STATE_SENSOR_TYPES as $sensorType) {
            app('sensor-discovery')->sync(sensor_type: $sensorType);
        }
        app('sensor-discovery')->sync(sensor_type: self::COUNT_SENSOR_IO_ERRORS);
    }

    // ==========================================================================
    // Polling — update values every poll
    // ==========================================================================

    /**
     * Update sensor_current in the DB and write the RRD for a state sensor.
     * Called every poll so graphs and alert evaluations stay current.
     * sensor_current is stored directly; the RRD writer receives the same value.
     */
    public function writeStateSensorRrd(array $device, string $sensorIndex, string $sensorType, string $sensorDescr, int $sensorCurrent): void
    {
        Sensor::withoutGlobalScopes()
            ->where('device_id', $device['device_id'])
            ->where('sensor_class', 'state')
            ->where('poller_type', 'agent')
            ->where('sensor_type', $sensorType)
            ->where('sensor_index', $sensorIndex)
            ->update(['sensor_current' => $sensorCurrent]);

        $this->writeSensorRrd($device, 'state', $sensorType, $sensorIndex, $sensorDescr, (float) $sensorCurrent);
    }

    /**
     * Update sensor_current in the DB and write the RRD for a count sensor.
     * Called every poll to keep the error count current.
     */
    public function updateCountSensorValue(
        array $device,
        string $sensorIndex,
        string $sensorDescr,
        float $sensorCurrent
    ): void {
        Sensor::withoutGlobalScopes()
            ->where('device_id', $device['device_id'])
            ->where('sensor_class', 'count')
            ->where('poller_type', 'agent')
            ->where('sensor_type', self::COUNT_SENSOR_IO_ERRORS)
            ->where('sensor_index', $sensorIndex)
            ->update(['sensor_current' => $sensorCurrent]);

        $this->writeSensorRrd($device, 'count', self::COUNT_SENSOR_IO_ERRORS, $sensorIndex, $sensorDescr, $sensorCurrent);
    }

    // ==========================================================================
    // Cleanup
    // ==========================================================================

    /**
     * Delete all btrfs state and count sensors for a device.
     * Called when the agent payload version is unsupported or invalid.
     */
    public function deleteAllStateAndCountSensors(array $device): void
    {
        $stateSensors = Sensor::withoutGlobalScopes()
            ->where('device_id', $device['device_id'])
            ->where('sensor_class', 'state')
            ->where('poller_type', 'agent')
            ->whereIn('sensor_type', self::STATE_SENSOR_TYPES)
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
            ->whereIn('sensor_type', self::COUNT_SENSOR_TYPES)
            ->delete();
    }

    // ==========================================================================
    // Internals
    // ==========================================================================

    private function writeSensorRrd(array $device, string $sensorClass, string $sensorType, string $sensorIndex, string $sensorDescr, float $sensorCurrent): void
    {
        app('Datastore')->put($device, 'sensor', [
            'sensor_class' => $sensorClass,
            'sensor_type'  => $sensorType,
            'sensor_index' => $sensorIndex,
            'sensor_descr' => $sensorDescr,
            'rrd_def'      => RrdDefinition::make()->addDataset('sensor', 'GAUGE'),
            'rrd_name'     => ['sensor', $sensorClass, $sensorType, $sensorIndex],
        ], ['sensor' => $sensorCurrent]);
    }

    /**
     * State translations for IO, Scrub, and Balance status sensors.
     *
     * Maps BtrfsStatusMapper status codes to LibreNMS severity levels:
     *   STATUS_UNKNOWN (-1) / STATUS_NA (2) → Unknown
     *   STATUS_OK      (0)                  → Ok
     *   STATUS_RUNNING (1)                  → Warning
     *   STATUS_ERROR   (3)                  → Error
     *   STATUS_MISSING (4)                  → Error
     *
     * @return StateTranslation[]
     */
    private function getStatusTranslations(): array
    {
        return [
            StateTranslation::define('N/A', BtrfsStatusMapper::STATUS_UNKNOWN, Severity::Unknown),
            StateTranslation::define('OK', BtrfsStatusMapper::STATUS_OK, Severity::Ok),
            StateTranslation::define('Running', BtrfsStatusMapper::STATUS_RUNNING, Severity::Warning),
            StateTranslation::define('N/A', BtrfsStatusMapper::STATUS_NA, Severity::Unknown),
            StateTranslation::define('Error', BtrfsStatusMapper::STATUS_ERROR, Severity::Error),
            StateTranslation::define('Missing', BtrfsStatusMapper::STATUS_MISSING, Severity::Error),
        ];
    }

    /**
     * State translations for the Scrub Ops sensor.
     *
     * @return StateTranslation[]
     */
    private function getScrubOpsTranslations(): array
    {
        return [
            StateTranslation::define('Idle', 0, Severity::Ok),
            StateTranslation::define('Running', 1, Severity::Warning),
        ];
    }
}
