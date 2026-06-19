<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Add discovery-computed rate-of-change columns to smart_sata_attributes:
 * average raw-value change per hour over the last 8h / 24h / 168h (1wk) / 672h (1mo).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('smart_sata_attributes')) {
            return;
        }
        Schema::table('smart_sata_attributes', function (Blueprint $table) {
            $table->float('rate_8h')->nullable()->after('rrd_type');
            $table->float('rate_24h')->nullable()->after('rate_8h');
            $table->float('rate_168h')->nullable()->after('rate_24h');
            $table->float('rate_672h')->nullable()->after('rate_168h');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('smart_sata_attributes')) {
            return;
        }
        Schema::table('smart_sata_attributes', function (Blueprint $table) {
            $table->dropColumn(['rate_8h', 'rate_24h', 'rate_168h', 'rate_672h']);
        });
    }
};
