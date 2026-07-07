<?php

namespace LibreNMS\Tests\Feature\Smart;

use LibreNMS\Agent\Module\Smart\DeviceTable;
use LibreNMS\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

class DeviceTablePollSkipReasonTest extends TestCase
{
    private function deviceTable(): DeviceTable
    {
        // pollSkipReason() is a pure function of its $dev argument -- it never
        // touches the Context this class is normally constructed with, so skip
        // building the full Context/Common/Device dependency chain.
        return (new ReflectionClass(DeviceTable::class))->newInstanceWithoutConstructor();
    }

    public function testNormalDeviceIsNotSkipped(): void
    {
        $this->assertNull($this->deviceTable()->pollSkipReason(['power_state' => 1, 'missing_since' => null]));
        $this->assertNull($this->deviceTable()->pollSkipReason(['power_state' => 0, 'missing_since' => null]));
        $this->assertNull($this->deviceTable()->pollSkipReason(['missing_since' => null]));
    }

    public function testMissingDiskIsSkippedAsMissing(): void
    {
        $this->assertSame('missing', $this->deviceTable()->pollSkipReason([
            'power_state'   => 1,
            'missing_since' => '2026-07-04 09:12:00',
        ]));
    }

    public function testMissingTakesPriorityOverPowerState(): void
    {
        $this->assertSame('missing', $this->deviceTable()->pollSkipReason([
            'power_state'   => 7,
            'missing_since' => '2026-07-04 09:12:00',
        ]));
    }

    /** @return array<string, array{int}> */
    public static function idlePowerStates(): array
    {
        return [
            'idleA'    => [2],
            'idleB'    => [3],
            'idleC'    => [4],
            'standbyY' => [5],
            'standbyZ' => [6],
            'sleeping' => [7],
            'standby'  => [8],
        ];
    }

    #[DataProvider('idlePowerStates')]
    public function testIdleAndSleepingTiersAreSkippedAsIdle(int $powerState): void
    {
        $this->assertSame('idle', $this->deviceTable()->pollSkipReason([
            'power_state'   => $powerState,
            'missing_since' => null,
        ]));
    }

    public function testActiveAndUnknownPowerStatesAreNotSkipped(): void
    {
        $this->assertNull($this->deviceTable()->pollSkipReason(['power_state' => 0, 'missing_since' => null]));
        $this->assertNull($this->deviceTable()->pollSkipReason(['power_state' => 1, 'missing_since' => null]));
        $this->assertNull($this->deviceTable()->pollSkipReason(['power_state' => null, 'missing_since' => null]));
    }
}
