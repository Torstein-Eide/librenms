<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Widen the ATA Count and Feature registers in smart_sata_error_cmd to 16-bit.
 *
 * In 48-bit ATA commands smartmontools reports the combined hi/lo register
 * bytes, so unsignedTinyInteger overflows on values >255 (e.g. reg_feature
 * 1344 raised SQLSTATE[22003]). Split out of the create migration so it
 * applies to databases that already ran 2026_06_06_000000_create_smart_tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_sata_error_cmd', function (Blueprint $table) {
            $table->unsignedSmallInteger('reg_count')->nullable()->change();
            $table->unsignedSmallInteger('reg_feature')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('smart_sata_error_cmd', function (Blueprint $table) {
            $table->unsignedTinyInteger('reg_count')->nullable()->change();
            $table->unsignedTinyInteger('reg_feature')->nullable()->change();
        });
    }
};
