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
            $table->boolean('log_extra_dev_stats')->nullable()->after('default_view_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('smart_app_settings', function (Blueprint $table): void {
            $table->dropColumn('log_extra_dev_stats');
        });
    }
};
