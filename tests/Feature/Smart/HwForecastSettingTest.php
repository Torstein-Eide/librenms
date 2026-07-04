<?php

namespace LibreNMS\Tests\Feature\Smart;

use Illuminate\Support\Facades\DB;
use LibreNMS\Agent\Module\Smart\Support\HwForecastSetting;
use LibreNMS\Tests\TestCase;

class HwForecastSettingTest extends TestCase
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

    public function testDefaultsToTrueWhenNoRowsExist(): void
    {
        $this->assertTrue(HwForecastSetting::resolve(1));
        $this->assertTrue(HwForecastSetting::resolve(HwForecastSetting::GLOBAL_APP_ID));
    }

    public function testGlobalCanBeExplicitlyDisabled(): void
    {
        DB::table('smart_app_settings')->updateOrInsert(
            ['app_id' => HwForecastSetting::GLOBAL_APP_ID],
            ['enable_hw_forecast' => false]
        );

        $this->assertFalse(HwForecastSetting::resolve(1));
        $this->assertFalse(HwForecastSetting::resolve(HwForecastSetting::GLOBAL_APP_ID));
    }

    public function testAppInheritsGlobalDefaultWhenNoOverride(): void
    {
        DB::table('smart_app_settings')->updateOrInsert(
            ['app_id' => HwForecastSetting::GLOBAL_APP_ID],
            ['enable_hw_forecast' => true]
        );

        $this->assertTrue(HwForecastSetting::resolve(1));
    }

    public function testAppOverrideWinsOverGlobalDefault(): void
    {
        DB::table('smart_app_settings')->updateOrInsert(
            ['app_id' => HwForecastSetting::GLOBAL_APP_ID],
            ['enable_hw_forecast' => true]
        );
        DB::table('smart_app_settings')->updateOrInsert(
            ['app_id' => 1],
            ['enable_hw_forecast' => false]
        );

        $this->assertFalse(HwForecastSetting::resolve(1));
        $this->assertTrue(HwForecastSetting::resolve(HwForecastSetting::GLOBAL_APP_ID));
    }
}
