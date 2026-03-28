<?php

namespace LibreNMS\Tests\Unit;

use LibreNMS\Util\DiskTypeFilter;
use PHPUnit\Framework\TestCase;

class DiskTypeFilterTest extends TestCase
{
    public function testClassifyLinuxDisks()
    {
        // Physical drives
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'sd_family'],
            DiskTypeFilter::classify('sda')
        );
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'nvme'],
            DiskTypeFilter::classify('nvme0n1')
        );
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'mmcblk'],
            DiskTypeFilter::classify('mmcblk0')
        );

        // Logical partitions
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'partitions'],
            DiskTypeFilter::classify('sda1')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'partitions'],
            DiskTypeFilter::classify('nvme0n1p1')
        );

        // Logical devices
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'dm'],
            DiskTypeFilter::classify('dm-0')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'md'],
            DiskTypeFilter::classify('md0')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'loop'],
            DiskTypeFilter::classify('loop0')
        );
    }

    public function testClassifyUnixBsdDisks()
    {
        // Physical drives (other category)
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'sd_family'],
            DiskTypeFilter::classify('da0')
        );
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'other'],
            DiskTypeFilter::classify('wd0')
        );
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'sd_family'],
            DiskTypeFilter::classify('ad0')
        );

        // Partitions (detected as logical)
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'partitions'],
            DiskTypeFilter::classify('da0p1')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'partitions'],
            DiskTypeFilter::classify('da0s1a')
        );
        $this->assertEquals(
            ['view' => 'logical', 'subtype' => 'partitions'],
            DiskTypeFilter::classify('ad0s1c')
        );
    }

    public function testClassifyUnknownDisks()
    {
        $this->assertEquals(
            ['view' => 'physical', 'subtype' => 'other'],
            DiskTypeFilter::classify('unknown0')
        );
    }

    public function testMatchesFilter()
    {
        $physicalDisk = ['view' => 'physical', 'subtype' => 'sd_family'];
        $logicalDisk = ['view' => 'logical', 'subtype' => 'partitions'];
        $otherDisk = ['view' => 'physical', 'subtype' => 'other'];

        // Test all view
        $this->assertTrue(DiskTypeFilter::matches($physicalDisk, 'all', 'all'));
        $this->assertTrue(DiskTypeFilter::matches($logicalDisk, 'all', 'all'));
        $this->assertTrue(DiskTypeFilter::matches($otherDisk, 'all', 'all'));

        // Test physical view
        $this->assertTrue(DiskTypeFilter::matches($physicalDisk, 'physical', 'all'));
        $this->assertTrue(DiskTypeFilter::matches($physicalDisk, 'physical', 'sd_family'));
        $this->assertFalse(DiskTypeFilter::matches($physicalDisk, 'physical', 'nvme'));
        $this->assertTrue(DiskTypeFilter::matches($otherDisk, 'physical', 'all'));
        $this->assertTrue(DiskTypeFilter::matches($otherDisk, 'physical', 'other'));
        $this->assertFalse(DiskTypeFilter::matches($logicalDisk, 'physical', 'all'));

        // Test logical view
        $this->assertTrue(DiskTypeFilter::matches($logicalDisk, 'logical', 'all'));
        $this->assertTrue(DiskTypeFilter::matches($logicalDisk, 'logical', 'partitions'));
        $this->assertFalse(DiskTypeFilter::matches($logicalDisk, 'logical', 'dm'));
        $this->assertFalse(DiskTypeFilter::matches($physicalDisk, 'logical', 'all'));
    }

    public function testNormalizeSelection()
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

    public function testSubtypesFor()
    {
        $this->assertEquals(
            ['all', 'sd_family', 'nvme', 'mmcblk', 'other'],
            DiskTypeFilter::subtypesFor('physical')
        );
        $this->assertEquals(
            ['all', 'partitions', 'dm', 'md', 'loop', 'other'],
            DiskTypeFilter::subtypesFor('logical')
        );
        $this->assertEquals(
            ['all'],
            DiskTypeFilter::subtypesFor('unknown')
        );
    }
}
