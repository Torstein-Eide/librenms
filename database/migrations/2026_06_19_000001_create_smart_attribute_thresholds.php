<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Warning-rate thresholds for SMART SATA attributes (changes/hour, compared
 * against smart_sata_attributes.rate_8h/24h/168h/672h).
 *
 * A row with app_id=0 / disk_key='' is the global default for an
 * attribute_id; a row with app_id+disk_key set overrides it for one disk.
 * (0/'' sentinels, not NULL, so the (app_id, disk_key, attribute_id) unique
 * index actually enforces "one global row per attribute_id" — MySQL unique
 * indexes do not treat two NULLs as equal, so NULL sentinels would silently
 * allow duplicate global rows.)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('smart_attribute_thresholds', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id')->default(0);
            $table->string('disk_key', 160)->default('');
            $table->unsignedTinyInteger('attribute_id');
            $table->float('warn_rate_8h')->nullable();
            $table->float('warn_rate_24h')->nullable();
            $table->float('warn_rate_168h')->nullable();
            $table->float('warn_rate_672h')->nullable();
            $table->timestamps();
            $table->unique(['app_id', 'disk_key', 'attribute_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_attribute_thresholds');
    }
};
