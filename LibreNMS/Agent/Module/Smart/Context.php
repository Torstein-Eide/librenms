<?php

namespace LibreNMS\Agent\Module\Smart;

use App\Models\Device;

/**
 * Stable per-run identity for one discover()/poll() cycle, plus a delegate
 * back to the owning {@see Common} (an {@see \LibreNMS\Agent\Application}
 * subclass) for the few sensor/RRD capabilities only an Application
 * instance can reach. Extracted handler classes (SataHandler, NvmeHandler,
 * ...) are plain objects, not Application subclasses, so they talk to
 * Context instead of touching Common or Application directly.
 */
final class Context
{
    public function __construct(
        public readonly int $appId,
        public readonly int $deviceId,
        public readonly Device $device,
        private readonly Common $app,
    ) {
    }

    /** Register a sensor for discovery. Mirrors Application::discoverSensor(); fluent. */
    public function discoverSensor(
        string $class,
        string $type,
        string $index,
        string $oid,
        string $descr,
        int|float $current = 0,
        string $poller_type = 'agent',
        ?string $group = null,
        ?string $navigation = null,
        int|float $divisor = 1,
        int|float $multiplier = 1,
        int|float|null $lowLimit = null,
        int|float|null $lowWarnLimit = null,
        int|float|null $warnLimit = null,
        int|float|null $highLimit = null,
        string $rrd_type = 'GAUGE',
    ): static {
        $this->app->discoverSensorPublic(
            $class, $type, $index, $oid, $descr, $current, $poller_type, $group,
            $navigation, $divisor, $multiplier, $lowLimit, $lowWarnLimit, $warnLimit, $highLimit, $rrd_type,
        );

        return $this;
    }

    /** Register state translations for the last discovered sensor. Fluent. */
    public function withStateTranslations(string $stateName, array $translations): static
    {
        $this->app->withStateTranslationsPublic($stateName, $translations);

        return $this;
    }

    /** Update sensor_current for the given sensor_index => value pairs, oid-prefix matched. */
    public function updateSensorValues(array $values, string $oidPrefix): void
    {
        $this->app->updateSensorValuesPublic($values, $oidPrefix);
    }

    /** Print a debug line when -vv is active. */
    public function vlog(string $msg): void
    {
        $this->app->vlogPublic($msg);
    }
}
