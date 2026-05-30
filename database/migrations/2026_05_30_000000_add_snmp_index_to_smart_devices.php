<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_devices', function (Blueprint $table) {
            $table->unsignedSmallInteger('snmp_index')->nullable()->after('disk_key');
        });
    }

    public function down(): void
    {
        Schema::table('smart_devices', function (Blueprint $table) {
            $table->dropColumn('snmp_index');
        });
    }
};
