<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Add smart_sata_attributes.format: the device-reported
 * smartmonSataAttrFormat enum (SmartmonAtaSmartAttrFormat, SMARTMON-TC-MIB)
 * indicating how value_raw_string is encoded for this attribute.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('smart_sata_attributes')) {
            return;
        }
        Schema::table('smart_sata_attributes', function (Blueprint $table) {
            $table->unsignedTinyInteger('format')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('smart_sata_attributes')) {
            return;
        }
        Schema::table('smart_sata_attributes', function (Blueprint $table) {
            $table->dropColumn('format');
        });
    }
};
