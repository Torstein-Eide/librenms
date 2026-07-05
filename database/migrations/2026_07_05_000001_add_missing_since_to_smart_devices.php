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
        Schema::table('smart_devices', function (Blueprint $table): void {
            $table->dateTime('missing_since')->nullable()->after('v1_rrd_migrated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('smart_devices', function (Blueprint $table): void {
            $table->dropColumn('missing_since');
        });
    }
};
