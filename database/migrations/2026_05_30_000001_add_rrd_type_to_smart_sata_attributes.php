<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_sata_attributes', function (Blueprint $table) {
            $table->string('rrd_type', 8)->default('GAUGE')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('smart_sata_attributes', function (Blueprint $table) {
            $table->dropColumn('rrd_type');
        });
    }
};
