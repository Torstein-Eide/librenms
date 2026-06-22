<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('smart_nvme_info', function (Blueprint $table) {
            $table->unsignedTinyInteger('link_power_state')->nullable()->after('max_data_transfer_pages');
            $table->unsignedInteger('max_link_speed')->nullable()->after('link_power_state');
            $table->unsignedTinyInteger('max_link_width')->nullable()->after('max_link_speed');
            $table->unsignedInteger('current_link_speed')->nullable()->after('max_link_width');
            $table->unsignedTinyInteger('current_link_width')->nullable()->after('current_link_speed');
        });
    }

    public function down(): void
    {
        Schema::table('smart_nvme_info', function (Blueprint $table) {
            $table->dropColumn(['link_power_state', 'max_link_speed', 'max_link_width', 'current_link_speed', 'current_link_width']);
        });
    }
};
