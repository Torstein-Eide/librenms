<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Add smart_sata_attributes.rate_status: the rate-of-change threshold
 * verdict, kept separate from the device-reported `status` column so the
 * two never overwrite each other.
 *
 * -1 = no rate-of-change threshold enabled for this disk/attribute
 *  1 = threshold enabled, no window currently exceeds it
 *  2 = threshold enabled, at least one window exceeds it
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('smart_sata_attributes')) {
            return;
        }
        Schema::table('smart_sata_attributes', function (Blueprint $table) {
            $table->tinyInteger('rate_status')->nullable()->default(-1)->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('smart_sata_attributes')) {
            return;
        }
        Schema::table('smart_sata_attributes', function (Blueprint $table) {
            $table->dropColumn('rate_status');
        });
    }
};
