<?php

namespace LibreNMS\Tests\Unit;

use LibreNMS\Util\DiskTypeFilter;
use PHPUnit\Framework\TestCase;

class DiskTypeFilterTest extends TestCase
{
    /**
     * @return array{view: 'physical'|'logical', subtype: string}
     */
    private function classifyUnix(string $diskName, ?string $osOrSysDescr = null): array
    {
        $classified = DiskTypeFilter::classify(
            [$diskName],
            'unix',
            ['os_or_sys_descr' => $osOrSysDescr]
        );

        return $classified[0];
    }

    public function testClassifyLinuxDisks(): void
    {
        // Physical drives
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'sd_family'],
            $this->classifyUnix('sda')
        );
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'nvme'],
            $this->classifyUnix('nvme0n1')
        );
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'mmcblk'],
            $this->classifyUnix('mmcblk0')
        );

        // Logical partitions
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'partitions'],
            $this->classifyUnix('sda1')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'partitions'],
            $this->classifyUnix('nvme0n1p1')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'partitions'],
            $this->classifyUnix('bcache0p1')
        );

        // Logical devices
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'dm'],
            $this->classifyUnix('dm-0')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'caching'],
            $this->classifyUnix('bcache0')
        );

        // Memory-backed devices
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'memory'],
            $this->classifyUnix('ram0')
        );
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'memory'],
            $this->classifyUnix('zram0')
        );

        // Software RAID
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'sw_raid'],
            $this->classifyUnix('md0')
        );

        // Image/Mem devices
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'loop'],
            $this->classifyUnix('loop0')
        );
    }

    public function testClassifyUnixBsdDisks(): void
    {
        // Physical drives
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'sd_family'],
            $this->classifyUnix('da0')
        );
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'other'],
            $this->classifyUnix('wd0')
        );
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'sd_family'],
            $this->classifyUnix('ad0')
        );

        // Partitions (detected as logical)
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'partitions'],
            $this->classifyUnix('da0p1')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'partitions'],
            $this->classifyUnix('da0s1a')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'partitions'],
            $this->classifyUnix('ad0s1c')
        );
    }

    public function testClassifyBsdSoftwareRaid(): void
    {
        // FreeBSD software RAID (ccd)
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'sw_raid'],
            $this->classifyUnix('ccd0', 'freebsd')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'sw_raid'],
            $this->classifyUnix('ccd1', 'freebsd')
        );

        // BSD VND (vnode disk)
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'sw_raid'],
            $this->classifyUnix('vnd0', 'freebsd')
        );

        // OpenBSD/NetBSD software RAID
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'sw_raid'],
            $this->classifyUnix('ccd0', 'openbsd')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'sw_raid'],
            $this->classifyUnix('ccd0', 'netbsd')
        );

        // BSD memory disks (md*)
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'memory'],
            $this->classifyUnix('md0', 'freebsd')
        );
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'memory'],
            $this->classifyUnix('md0', 'openbsd')
        );
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'memory'],
            $this->classifyUnix('md0', 'netbsd')
        );
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'memory'],
            $this->classifyUnix('md0', 'pfSense pfSense.eideen.no 2.8.1-RELEASE FreeBSD 15.0-CURRENT amd64')
        );
    }

    public function testClassifyLinuxMd(): void
    {
        // Linux md devices should be software RAID
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'sw_raid'],
            $this->classifyUnix('md0', 'linux')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'sw_raid'],
            $this->classifyUnix('md1', 'linux')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'sw_raid'],
            $this->classifyUnix('md127', 'linux')
        );

        // Unix group without OS descriptor should still classify md as software RAID
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'sw_raid'],
            $this->classifyUnix('md0')
        );
    }

    public function testClassifyUnknownDisks(): void
    {
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'other'],
            $this->classifyUnix('unknown0')
        );

        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'other'],
            DiskTypeFilter::classify(['sda'])[0]
        );

        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'other'],
            DiskTypeFilter::classify(['sda'], 'windows')[0]
        );
    }

    public function testMatchesFilter(): void
    {
        $physicalDisk = ['view' => 'physical', 'subtype' => 'sd_family'];
        $logicalDisk = ['view' => 'logical', 'subtype' => 'partitions'];
        $swRaidDisk = ['view' => 'logical', 'subtype' => 'sw_raid'];
        $loopDisk = ['view' => 'logical', 'subtype' => 'loop'];
        $otherDisk = ['view' => 'physical', 'subtype' => 'other'];

        // Test all view
        $this->assertTrue(DiskTypeFilter::matches($physicalDisk, 'all', 'all'));
        $this->assertTrue(DiskTypeFilter::matches($logicalDisk, 'all', 'all'));
        $this->assertTrue(DiskTypeFilter::matches($swRaidDisk, 'all', 'all'));
        $this->assertTrue(DiskTypeFilter::matches($loopDisk, 'all', 'all'));
        $this->assertTrue(DiskTypeFilter::matches($otherDisk, 'all', 'all'));

        // Test physical view
        $this->assertTrue(DiskTypeFilter::matches($physicalDisk, 'physical', 'all'));
        $this->assertTrue(DiskTypeFilter::matches($physicalDisk, 'physical', 'sd_family'));
        $this->assertFalse(DiskTypeFilter::matches($physicalDisk, 'physical', 'nvme'));
        $this->assertTrue(DiskTypeFilter::matches($otherDisk, 'physical', 'all'));
        $this->assertTrue(DiskTypeFilter::matches($otherDisk, 'physical', 'other'));
        $this->assertFalse(DiskTypeFilter::matches($logicalDisk, 'physical', 'all'));
        $this->assertFalse(DiskTypeFilter::matches($swRaidDisk, 'physical', 'all'));
        $this->assertFalse(DiskTypeFilter::matches($loopDisk, 'physical', 'all'));

        // Test logical view
        $this->assertTrue(DiskTypeFilter::matches($logicalDisk, 'logical', 'all'));
        $this->assertTrue(DiskTypeFilter::matches($logicalDisk, 'logical', 'partitions'));
        $this->assertFalse(DiskTypeFilter::matches($logicalDisk, 'logical', 'dm'));
        $this->assertTrue(DiskTypeFilter::matches($swRaidDisk, 'logical', 'all'));
        $this->assertTrue(DiskTypeFilter::matches($swRaidDisk, 'logical', 'sw_raid'));
        $this->assertFalse(DiskTypeFilter::matches($swRaidDisk, 'logical', 'loop'));
        $this->assertTrue(DiskTypeFilter::matches($loopDisk, 'logical', 'all'));
        $this->assertTrue(DiskTypeFilter::matches($loopDisk, 'logical', 'loop'));
        $this->assertFalse(DiskTypeFilter::matches($physicalDisk, 'logical', 'all'));
    }

    public function testNormalizeSelection(): void
    {
        // Test valid selections
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'all'],
            DiskTypeFilter::normalizeSelection('physical', null)
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'partitions'],
            DiskTypeFilter::normalizeSelection('logical', 'partitions')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'sw_raid'],
            DiskTypeFilter::normalizeSelection('logical', 'sw_raid')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'loop'],
            DiskTypeFilter::normalizeSelection('logical', 'loop')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'caching'],
            DiskTypeFilter::normalizeSelection('logical', 'caching')
        );
        $this->assertEquals(
            ['view' => 'all', 'subtype' => 'all'],
            DiskTypeFilter::normalizeSelection('all', 'something')
        );

        // Test invalid selections
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'all'],
            DiskTypeFilter::normalizeSelection('invalid', null)
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'all'],
            DiskTypeFilter::normalizeSelection('logical', 'invalid')
        );
    }

    public function testSubtypesFor(): void
    {
        $this->assertEquals(
            ['all', 'sd_family', 'nvme', 'mmcblk', 'memory', 'other'],
            DiskTypeFilter::subtypesFor('physical')
        );
        $this->assertEquals(
            ['all', 'partitions', 'dm', 'sw_raid', 'loop', 'caching', 'other'],
            DiskTypeFilter::subtypesFor('logical')
        );
        $this->assertEquals(
            ['all'],
            DiskTypeFilter::subtypesFor('unknown')
        );
    }
}
