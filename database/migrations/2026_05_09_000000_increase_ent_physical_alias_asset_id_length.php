<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('entPhysical', function (Blueprint $table) {
            $table->string('entPhysicalAlias', 255)->nullable()->change();
            $table->string('entPhysicalAssetID', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entPhysical', function (Blueprint $table) {
            $table->string('entPhysicalAlias', 32)->nullable()->change();
            $table->string('entPhysicalAssetID', 32)->nullable()->change();
        });
    }
};
