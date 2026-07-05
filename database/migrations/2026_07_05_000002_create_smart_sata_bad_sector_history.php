<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('smart_sata_bad_sector_history', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedBigInteger('lba');
            $table->dateTime('first_seen');
            $table->dateTime('last_seen');
            $table->dateTime('cleared_at')->nullable();
            $table->unique(['app_id', 'disk_key', 'lba']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smart_sata_bad_sector_history');
    }
};
