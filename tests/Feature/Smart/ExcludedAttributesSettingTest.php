<?php

namespace LibreNMS\Tests\Feature\Smart;

use Illuminate\Support\Facades\DB;
use LibreNMS\Agent\Module\Smart\Support\ExcludedAttributesSetting;
use LibreNMS\Tests\TestCase;

class ExcludedAttributesSettingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->dbSetUp();
    }

    protected function tearDown(): void
    {
        $this->dbTearDown();
        parent::tearDown();
    }

    public function testResolveFallsBackToBuiltInDefaultsWhenNoRowsExist(): void
    {
        $entries = ExcludedAttributesSetting::resolve(1, 'sda');

        $this->assertNotEmpty($entries);
        $this->assertTrue(ExcludedAttributesSetting::isExcluded('Temperature_Celsius', 194, $entries));
        $this->assertTrue(ExcludedAttributesSetting::isExcluded('Head_Flying_Hours', 240, $entries));

        // Ids 5 and 17 are excluded by id regardless of reported name, e.g. a
        // vendor reporting id 17 as "Current_TRIM_Percent".
        $this->assertTrue(ExcludedAttributesSetting::isExcluded('Reallocated_Sector_Ct', 5, $entries));
        $this->assertTrue(ExcludedAttributesSetting::isExcluded('Current_TRIM_Percent', 17, $entries));
    }

    public function testResolveHonorsExplicitEmptyGlobalList(): void
    {
        DB::table('smart_app_settings')->updateOrInsert(
            ['app_id' => ExcludedAttributesSetting::GLOBAL_APP_ID],
            ['wear_excluded_attributes' => json_encode([])]
        );

        $entries = ExcludedAttributesSetting::resolve(1, 'sda');

        $this->assertSame([], $entries);
    }

    public function testDiskOverrideReplacesGlobalListEntirely(): void
    {
        DB::table('smart_app_settings')->updateOrInsert(
            ['app_id' => ExcludedAttributesSetting::GLOBAL_APP_ID],
            ['wear_excluded_attributes' => json_encode([['type' => 'name', 'pattern' => 'Global_Only']])]
        );
        DB::table('smart_app_settings')->updateOrInsert(
            ['app_id' => 1],
            ['disk_wear_excluded_attributes' => json_encode([
                'sda' => [['type' => 'name', 'pattern' => 'Disk_Only']],
            ])]
        );

        $sdaEntries = ExcludedAttributesSetting::resolve(1, 'sda');
        $this->assertTrue(ExcludedAttributesSetting::isExcluded('Disk_Only', 1, $sdaEntries));
        $this->assertFalse(ExcludedAttributesSetting::isExcluded('Global_Only', 1, $sdaEntries));

        // A disk with no override row inherits the global list.
        $sdbEntries = ExcludedAttributesSetting::resolve(1, 'sdb');
        $this->assertTrue(ExcludedAttributesSetting::isExcluded('Global_Only', 1, $sdbEntries));
    }

    public function testIsExcludedMatchesNameCaseAndUnderscoreInsensitively(): void
    {
        $entries = [['type' => 'name', 'pattern' => 'Total LBAs Written']];

        $this->assertTrue(ExcludedAttributesSetting::isExcluded('Total_LBAs_Written', 241, $entries));
        $this->assertTrue(ExcludedAttributesSetting::isExcluded('total_lbas_written', 241, $entries));
        $this->assertFalse(ExcludedAttributesSetting::isExcluded('Total_LBAs_Read', 242, $entries));
    }

    public function testIdGatingMirrorsAgentxSpareBlocksCheck(): void
    {
        $entries = [['type' => 'regex', 'pattern' => '/^Spare_Blocks/i', 'ids' => [5, 17], 'min_id' => 100]];

        // Ids 5, 17, and 100 (>=100) must match; an unrelated id (42) must not,
        // even though the name matches -- mirrors agentx's aid in (5,17) or aid>=100.
        $this->assertTrue(ExcludedAttributesSetting::isExcluded('Spare_Blocks_Available', 5, $entries));
        $this->assertTrue(ExcludedAttributesSetting::isExcluded('Spare_Blocks_Available', 17, $entries));
        $this->assertTrue(ExcludedAttributesSetting::isExcluded('Spare_Blocks_Available', 100, $entries));
        $this->assertFalse(ExcludedAttributesSetting::isExcluded('Spare_Blocks_Available', 42, $entries));
    }

    public function testIdTypeMatchesByNumberRegardlessOfName(): void
    {
        $entries = [
            ['type' => 'id', 'pattern' => '5'],
            ['type' => 'id', 'pattern' => '17'],
        ];

        // A vendor reporting id 17 under an unrelated name (e.g. Current_TRIM_Percent)
        // is still caught, since "id" type entries never look at the name at all.
        $this->assertTrue(ExcludedAttributesSetting::isExcluded('Current_TRIM_Percent', 17, $entries));
        $this->assertTrue(ExcludedAttributesSetting::isExcluded('Reallocated_Sector_Ct', 5, $entries));
        $this->assertFalse(ExcludedAttributesSetting::isExcluded('Some_Other_Attr', 18, $entries));

        // A null/absent name doesn't stop an "id" entry from matching -- it never
        // looks at the name in the first place.
        $this->assertTrue(ExcludedAttributesSetting::isExcluded(null, 17, [['type' => 'id', 'pattern' => '17']]));
    }
}
