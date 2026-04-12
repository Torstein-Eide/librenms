<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sensors', function (Blueprint $table) {
            $table->string('sensor_navigation')->nullable()->after('group');
        });
    }

    public function down(): void
    {
        Schema::table('sensors', function (Blueprint $table) {
            $table->dropColumn('sensor_navigation');
        });
    }
};
