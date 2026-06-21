<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('smart_devices', function (Blueprint $table) {
            $table->unsignedTinyInteger('power_state')->nullable()->after('last_poll_exit');
        });
    }

    public function down(): void
    {
        Schema::table('smart_devices', function (Blueprint $table) {
            $table->dropColumn('power_state');
        });
    }
};
