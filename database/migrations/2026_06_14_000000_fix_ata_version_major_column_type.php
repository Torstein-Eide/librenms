<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_sata_info', function (Blueprint $table) {
            // ata_version_major is a 16-bit bitmask (bit N = ATA version N supported);
            // was incorrectly declared as TINYINT (max 255), values up to 65535 are valid.
            $table->unsignedSmallInteger('ata_version_major')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('smart_sata_info', function (Blueprint $table) {
            $table->unsignedTinyInteger('ata_version_major')->nullable()->change();
        });
    }
};
