<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Store the NVMe "current self-test operation" (in-progress test) on the
 * health row: its operation value, human-readable string, and completion
 * percentage (smartmonNvmeCurrentSelfTestOperation* / CompletionPercent).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('smart_nvme_health')) {
            return;
        }
        Schema::table('smart_nvme_health', function (Blueprint $table) {
            if (! Schema::hasColumn('smart_nvme_health', 'current_selftest_op')) {
                $table->unsignedInteger('current_selftest_op')->nullable()->after('critical_comp_time');
            }
            if (! Schema::hasColumn('smart_nvme_health', 'current_selftest_str')) {
                $table->string('current_selftest_str', 96)->nullable()->after('current_selftest_op');
            }
            if (! Schema::hasColumn('smart_nvme_health', 'current_selftest_pct')) {
                $table->unsignedTinyInteger('current_selftest_pct')->nullable()->after('current_selftest_str');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('smart_nvme_health')) {
            return;
        }
        Schema::table('smart_nvme_health', function (Blueprint $table) {
            foreach (['current_selftest_op', 'current_selftest_str', 'current_selftest_pct'] as $col) {
                if (Schema::hasColumn('smart_nvme_health', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
