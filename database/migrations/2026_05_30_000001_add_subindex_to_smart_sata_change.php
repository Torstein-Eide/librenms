<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Extend change snapshot to track per-page DevStat and per-error ErrorCmd changes.
        // subindex = 0 → device-level row; subindex > 0 → page/error-entry-level row.
        Schema::table('smart_sata_change', function (Blueprint $table) {
            $table->dropPrimary();
            $table->unsignedInteger('subindex')->default(0)->after('table_id');
            $table->primary(['app_id', 'device_idx', 'table_id', 'subindex']);
        });

        // Fix columns referenced by code but absent from the initial migration.
        Schema::table('smart_devices', function (Blueprint $table) {
            $table->unsignedSmallInteger('snmp_index')->nullable()->after('disk_key');
        });

        Schema::table('smart_sata_attributes', function (Blueprint $table) {
            $table->string('rrd_type', 8)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('smart_sata_change', function (Blueprint $table) {
            $table->dropPrimary();
            $table->dropColumn('subindex');
            $table->primary(['app_id', 'device_idx', 'table_id']);
        });

        Schema::table('smart_devices', function (Blueprint $table) {
            $table->dropColumn('snmp_index');
        });

        Schema::table('smart_sata_attributes', function (Blueprint $table) {
            $table->dropColumn('rrd_type');
        });
    }
};
