<?php

namespace LibreNMS\Tests\Unit\Smart;

use LibreNMS\Agent\Module\Smart\Common;
use LibreNMS\Tests\TestCase;
use ReflectionClass;

/**
 * Fixtures are the worked examples documented in mibs/SMARTMON-TC-MIB's
 * SmartmonAtaSmartAttrFormat textual convention.
 *
 * - attrFormatSubValues() decodes multi-value formats (raw8, raw16,
 *   raw16raw16, raw24raw8, raw24div24, raw24div32) into sub-DS that replace
 *   the base id{N} DS in pollSataDeviceRrd().
 * - attrFormatSingleValue() decodes formats that reduce to a single more
 *   meaningful number than RawValue (min2hour, msec24hour32 -> total hours
 *   as a float), which overwrites id{N}'s value instead of RawValue.
 */
final class SataAttrFormatTest extends TestCase
{
    private function callPrivate(string $method, ?int $format, ?string $rawString): mixed
    {
        $instance = (new ReflectionClass(Common::class))->newInstanceWithoutConstructor();
        $reflMethod = (new ReflectionClass(Common::class))->getMethod($method);
        $reflMethod->setAccessible(true);

        return $reflMethod->invoke($instance, $format, $rawString);
    }

    private function callAttrFormatSubValues(?int $format, ?string $rawString): array
    {
        return $this->callPrivate('attrFormatSubValues', $format, $rawString);
    }

    private function callAttrFormatSingleValue(?int $format, ?string $rawString): ?float
    {
        return $this->callPrivate('attrFormatSingleValue', $format, $rawString);
    }

    public function testRaw8SplitsSixByteCounters(): void
    {
        $this->assertSame(
            ['P5' => 0.0, 'P4' => 0.0, 'P3' => 0.0, 'P2' => 0.0, 'P1' => 0.0, 'P0' => 23.0],
            $this->callAttrFormatSubValues(1, '0 0 0 0 0 23')
        );
    }

    public function testRaw16SplitsThreeWordCounters(): void
    {
        $this->assertSame(
            ['P2' => 0.0, 'P1' => 0.0, 'P0' => 12345.0],
            $this->callAttrFormatSubValues(2, '0 0 12345')
        );
    }

    public function testRaw16Raw16OnlyWhenParenGroupPresent(): void
    {
        $this->assertSame(['P2' => 12.0, 'P1' => 5.0], $this->callAttrFormatSubValues(9, '0 (12 5)'));
        $this->assertSame([], $this->callAttrFormatSubValues(9, '0'));
    }

    public function testRaw24Raw8OnlyWhenParenGroupPresent(): void
    {
        $this->assertSame(['P5' => 1.0, 'P4' => 0.0, 'P3' => 0.0], $this->callAttrFormatSubValues(11, '16972 (1 0 0)'));
        $this->assertSame([], $this->callAttrFormatSubValues(11, '16972'));
    }

    public function testRaw24Div24SplitsSumHiLo(): void
    {
        $this->assertSame(
            ['Sum' => 117361392.0, 'Hi' => 0.0, 'Lo' => 117361392.0],
            $this->callAttrFormatSubValues(12, '0/117361392')
        );
    }

    public function testRaw24Div32SplitsSumHiLo(): void
    {
        $this->assertSame(
            ['Sum' => 4294967295.0, 'Hi' => 0.0, 'Lo' => 4294967295.0],
            $this->callAttrFormatSubValues(13, '0/4294967295')
        );
    }

    public function testAttrFormatSubValuesEmptyForSingleValueUnknownOrUnparseable(): void
    {
        foreach ([0, 3, 4, 5, 6, 7, 8, 10, 14, 15, 16, 17, 18, 19, 20] as $format) {
            $this->assertSame([], $this->callAttrFormatSubValues($format, '0 (12 5)'));
        }
        $this->assertSame([], $this->callAttrFormatSubValues(null, '0/117361392'));
        $this->assertSame([], $this->callAttrFormatSubValues(12, null));
        $this->assertSame([], $this->callAttrFormatSubValues(12, 'not a number'));
    }

    public function testMin2HourConvertsToFloatHoursAndIgnoresParenExtra(): void
    {
        $this->assertSame(16460.55, $this->callAttrFormatSingleValue(15, '16460h+33m'));
        $this->assertSame(16460.55, $this->callAttrFormatSingleValue(15, '16460h+33m (7)'));
    }

    public function testMsec24Hour32ConvertsToFloatHours(): void
    {
        $hours = 16460.0 + 33 / 60 + 9 / 3600 + 900 / 3600000;
        $this->assertSame($hours, $this->callAttrFormatSingleValue(17, '16460h+33m+09.900s'));
    }

    public function testAttrFormatSingleValueNullForOtherFormatsOrUnparseable(): void
    {
        foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 16, 18, 19, 20] as $format) {
            $this->assertNull($this->callAttrFormatSingleValue($format, '0 (12 5)'));
        }
        $this->assertNull($this->callAttrFormatSingleValue(null, '16460h+33m'));
        $this->assertNull($this->callAttrFormatSingleValue(15, null));
        $this->assertNull($this->callAttrFormatSingleValue(15, 'not a duration'));
    }
}
