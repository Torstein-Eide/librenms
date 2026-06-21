<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Add smart_attribute_thresholds.alert_enabled: an explicit on/off switch
 * for rate-of-change alerting on a given (scope, attribute_id) row,
 * independent of whether any warn_rate_* limit is configured there. Lets a
 * user keep limits configured but temporarily mute alerting, same as
 * sensors.sensor_alert does for the device health page.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('smart_attribute_thresholds')) {
            return;
        }
        Schema::table('smart_attribute_thresholds', function (Blueprint $table) {
            $table->boolean('alert_enabled')->default(true)->after('warn_rate_672h');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('smart_attribute_thresholds')) {
            return;
        }
        Schema::table('smart_attribute_thresholds', function (Blueprint $table) {
            $table->dropColumn('alert_enabled');
        });
    }
};
