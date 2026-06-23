<?php

/**
 * RrdtoolTest.php
 *
 * Tests functionality of our rrdtool wrapper
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2016 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace LibreNMS\Tests;

use App\Facades\LibrenmsConfig;
use LibreNMS\Data\Store\Rrd;

final class RrdtoolTest extends TestCase
{
    public function testBuildCommandLocal(): void
    {
        LibrenmsConfig::set('rrdcached', '');
        LibrenmsConfig::set('rrdtool_version', '1.4');
        LibrenmsConfig::set('rrd_dir', '/opt/librenms/rrd');

        $cmd = $this->buildCommandProxy('create', '/opt/librenms/rrd/f', ['o']);
        $this->assertEquals(['create', '/opt/librenms/rrd/f', 'o'], $cmd);

        $cmd = $this->buildCommandProxy('tune', '/opt/librenms/rrd/f', ['o']);
        $this->assertEquals(['tune', '/opt/librenms/rrd/f', 'o'], $cmd);

        $cmd = $this->buildCommandProxy('update', '/opt/librenms/rrd/f', ['o']);
        $this->assertEquals(['update', '/opt/librenms/rrd/f', 'o'], $cmd);

        LibrenmsConfig::set('rrdtool_version', '1.6');

        $cmd = $this->buildCommandProxy('create', '/opt/librenms/rrd/f', ['o']);
        $this->assertEquals(['create', '/opt/librenms/rrd/f', 'o', '-O'], $cmd);

        $cmd = $this->buildCommandProxy('tune', '/opt/librenms/rrd/f', ['o']);
        $this->assertEquals(['tune', '/opt/librenms/rrd/f', 'o'], $cmd);

        $cmd = $this->buildCommandProxy('update', '/opt/librenms/rrd/f', ['options']);
        $this->assertEquals(['update', '/opt/librenms/rrd/f', 'options'], $cmd);
    }

    public function testBuildCommandException(): void
    {
        LibrenmsConfig::set('rrdcached', '');
        LibrenmsConfig::set('rrdtool_version', '1.4');

        $this->expectException(\LibreNMS\Exceptions\RrdFileExistsException::class);
        // use this file, since it is guaranteed to exist
        $this->buildCommandProxy('create', __FILE__, ['o']);
    }

    private function buildCommandProxy(string $command, string $filename, array $options): array
    {
        $mock = $this->mock(Rrd::class)->makePartial(); // avoid constructor
        // @phpstan-ignore method.protected
        $mock->loadConfig(); // load config every time to clear cached settings

        return $mock->buildCommand($command, $filename, $options);
    }

    /**
     * rrdtool aligns a requested [start, end] xport window to step boundaries and can
     * return more rows than requested. When that alignment pushes the window past the
     * most recent sample, the trailing row is an unwritten future bucket (NaN) while the
     * real averaged value sits in an earlier row -- reproduces a real xport response seen
     * in production where this caused every rate-of-change value to come back null.
     */
    public function testParseXportRatesSkipsTrailingNanRow(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="ISO-8859-1"?>
            <xport>
              <meta>
                <start>1782236400</start>
                <end>1782237000</end>
                <step>600</step>
                <rows>2</rows>
                <columns>1</columns>
                <legend>
                  <entry>id5</entry>
                </legend>
              </meta>
              <data>
                <row><v>0.0000000000e+00</v></row>
                <row><v>NaN</v></row>
              </data>
            </xport>
            XML;

        $this->assertSame(['id5' => 0.0], $this->parseXportRatesProxy($xml, ['id5']));
    }

    /** Per dataset, not per row: one column's last row can be NaN while another's is valid. */
    public function testParseXportRatesBackfillsPerDataset(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="ISO-8859-1"?>
            <xport>
              <meta>
                <start>1782236400</start>
                <end>1782237000</end>
                <step>600</step>
                <rows>2</rows>
                <columns>2</columns>
                <legend>
                  <entry>id5</entry>
                  <entry>id197</entry>
                </legend>
              </meta>
              <data>
                <row><v>1.2300000000e+02</v><v>4.0000000000e+00</v></row>
                <row><v>NaN</v><v>5.0000000000e+00</v></row>
              </data>
            </xport>
            XML;

        $rates = $this->parseXportRatesProxy($xml, ['id5', 'id197']);
        ksort($rates);
        $this->assertSame(['id197' => 5.0, 'id5' => 123.0], $rates);
    }

    /** All rows NaN for a dataset (no data in range yet) legitimately yields no rate. */
    public function testParseXportRatesAllNanYieldsEmpty(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="ISO-8859-1"?>
            <xport>
              <meta>
                <start>1782236400</start>
                <end>1782237000</end>
                <step>600</step>
                <rows>2</rows>
                <columns>1</columns>
                <legend>
                  <entry>id5</entry>
                </legend>
              </meta>
              <data>
                <row><v>NaN</v></row>
                <row><v>NaN</v></row>
              </data>
            </xport>
            XML;

        $this->assertSame([], $this->parseXportRatesProxy($xml, ['id5']));
    }

    private function parseXportRatesProxy(string $xportOutput, array $datasets): array
    {
        // parseXportRates() touches no instance state, so a bare, uninitialized
        // instance (skipping the constructor/loadConfig) is fine here; a Mockery
        // partial mock can't call through to a private method on the real class.
        $rrd = (new \ReflectionClass(Rrd::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($rrd, 'parseXportRates');

        return $method->invoke($rrd, $xportOutput, $datasets);
    }
}
