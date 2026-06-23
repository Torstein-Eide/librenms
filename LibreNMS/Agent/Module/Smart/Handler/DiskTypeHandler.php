<?php

namespace LibreNMS\Agent\Module\Smart\Handler;

/**
 * Contract for one SMART device-type pipeline (SATA, NVMe, future SAS).
 *
 * SATA and NVMe's discover/poll bodies are fully disjoint -- there's no
 * shared *implementation*, only a shared *shape* -- so this is an interface,
 * not an abstract base class. An abstract base would invite a future
 * maintainer to stuff shared logic into it and grow a second Common.php.
 */
interface DiskTypeHandler
{
    /** Device-table protocol-type codes (smartmonDeviceType) this handler owns. */
    public static function types(): array;

    /**
     * Discover this type's tables/sensors for the given devices (already
     * filtered to this handler's types()). $sensorRows is the already-walked,
     * generic SENSOR-MIB table, keyed by snmp_index, shared across all handlers.
     */
    public function discover(array $devices, array $sensorRows): void;

    /** Poll this type's tables/sensors for the given devices (loaded fresh from DB). */
    public function poll(array $devices): void;

    /**
     * OIDs this handler expects to exist for a device at index $idx, for
     * cleanupStaleMibSensors()'s generic sweep over every handler.
     */
    public function expectedSensorOids(string $idx): array;
}
