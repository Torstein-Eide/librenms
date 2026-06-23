<?php

namespace LibreNMS\Agent\Module\Smart\Support;

use Illuminate\Support\Facades\DB;
use LibreNMS\Agent\Module\Smart\Context;
use LibreNMS\Agent\Module\Smart\Helpers\DiskIdentity;

/**
 * "Last Short/Long Test" age sensor logic, identical between SATA and NVMe
 * (each just supplies its own health/selftest-log table names). Shared here
 * instead of duplicated in both handlers.
 */
final class SelftestAge
{
    /**
     * Hours elapsed since the most recent self-test of the given type
     * (1 = short, 2 = extended/long), computed from the synced DB rows.
     * Returns null when power-on hours or a matching self-test entry is unknown.
     */
    public static function hours(Context $ctx, string $healthTable, string $logTable, string $diskKey, int $testType): ?int
    {
        $currentPoh = DB::table($healthTable)
            ->where('app_id', $ctx->appId)
            ->where('disk_key', $diskKey)
            ->value('power_on_hours');
        if ($currentPoh === null) {
            return null;
        }

        $lastTestPoh = DB::table($logTable)
            ->where('app_id', $ctx->appId)
            ->where('disk_key', $diskKey)
            ->where('test_type', $testType)
            ->max('power_on_hours');
        if ($lastTestPoh === null) {
            return null;
        }

        return max(0, (int) $currentPoh - (int) $lastTestPoh);
    }

    /**
     * Register the "Last Short/Long Test" age sensors (runtime class) for each
     * device in $deviceList. Runs after the self-test log table has been
     * synced so the age is computed from the current cycle's data. Only
     * creates a sensor when a matching log entry with power-on hours exists.
     * Not all devices (especially NVMe) implement the self-test log.
     *
     * @param  array<int|string, array{disk_key: string, snmp_index: string}>  $deviceList
     */
    public static function discoverSensors(Context $ctx, array $deviceList, string $sensorTypePrefix, string $healthTable, string $logTable): void
    {
        $group = 'SMART';
        foreach ($deviceList as $dev) {
            $diskKey = $dev['disk_key'];
            $idx = DiskIdentity::index($diskKey);
            $devName = DiskIdentity::label($dev, $dev['snmp_index']);

            foreach ([
                ['short', 1, 'Last Short SelfTest', 12000, 16000],
                ['long',  2, 'Last Long SelfTest',  57600, 60000],
            ] as [$suffix, $testType, $label, $warn, $max]) {
                $age = self::hours($ctx, $healthTable, $logTable, $diskKey, $testType);
                if ($age === null) {
                    continue;
                }
                $ctx->discoverSensor(
                    class: 'runtime',
                    type: "{$sensorTypePrefix}{$suffix}",
                    index: "{$idx}_selftest_{$suffix}",
                    oid: "app:smart_mib:{$idx}_selftest_{$suffix}",
                    descr: "{$group} {$devName} {$label}",
                    current: (float) $age * 60,
                    group: $group,
                    multiplier: 60,
                    warnLimit: $warn,
                    highLimit: $max,
                );
            }
        }
    }

    /** Build the `{idx}_selftest_short`/`_long` raw-hours values for one device, ready to batch into updateSensorValues(). */
    public static function values(Context $ctx, string $idx, string $diskKey, string $healthTable, string $logTable): array
    {
        $values = [];
        foreach (['short' => 1, 'long' => 2] as $suffix => $testType) {
            $age = self::hours($ctx, $healthTable, $logTable, $diskKey, $testType);
            if ($age !== null) {
                $values["{$idx}_selftest_{$suffix}"] = (float) $age;
            }
        }

        return $values;
    }
}
