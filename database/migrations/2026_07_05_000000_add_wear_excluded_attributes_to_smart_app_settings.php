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
        Schema::table('smart_app_settings', function (Blueprint $table): void {
            $table->json('wear_excluded_attributes')->nullable()->after('enable_hw_forecast');
            $table->json('disk_wear_excluded_attributes')->nullable()->after('wear_excluded_attributes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('smart_app_settings', function (Blueprint $table): void {
            $table->dropColumn(['wear_excluded_attributes', 'disk_wear_excluded_attributes']);
        });
    }
};
