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
        Schema::table('smart_sata_attributes', function (Blueprint $table): void {
            // discovery-computed "days until Normalized crosses Thresh" estimate,
            // from a 1-month and a separate 6-month straight-line trend fit --
            // see NormalizedTrendTracker::sync().
            $table->float('trend_days_1mo')->nullable()->after('rate_672h');
            $table->float('trend_days_6mo')->nullable()->after('trend_days_1mo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('smart_sata_attributes', function (Blueprint $table): void {
            $table->dropColumn(['trend_days_1mo', 'trend_days_6mo']);
        });
    }
};
