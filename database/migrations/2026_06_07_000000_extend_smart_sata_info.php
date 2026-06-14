<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_sata_info', function (Blueprint $table) {
            // ATA version details
            $table->unsignedSmallInteger('ata_version_major')->nullable(); // 16-bit bitmask (bit N = ATA version N)
            $table->unsignedSmallInteger('ata_version_minor')->nullable();
            // Capacity
            $table->unsignedBigInteger('user_capacity_blocks')->nullable();
            // Drive identity / capabilities
            $table->boolean('in_smartctl_database')->nullable();
            $table->boolean('smart_available')->nullable();
            $table->boolean('smart_enabled')->nullable();
            $table->boolean('trim_supported')->nullable();
            $table->boolean('write_cache_enabled')->nullable();
            $table->boolean('read_lookahead_enabled')->nullable();
            // APM
            $table->boolean('apm_enabled')->nullable();
            $table->unsignedTinyInteger('apm_level')->nullable();
            // Security
            $table->unsignedInteger('security_state')->nullable();
            $table->boolean('security_enabled')->nullable();
            $table->boolean('security_frozen')->nullable();
            // Interface speed (Mb/s)
            $table->unsignedInteger('if_speed_current_value')->nullable();
            $table->unsignedInteger('if_speed_max_value')->nullable();
            // Self-test polling intervals
            $table->unsignedInteger('selftest_polling_short_minutes')->nullable();
            $table->unsignedInteger('selftest_polling_extended_minutes')->nullable();
            $table->unsignedInteger('selftest_polling_conveyance_minutes')->nullable();
            // Offline collection
            $table->unsignedInteger('offline_collection_completion_secs')->nullable();
            // Log revisions / sectors
            $table->unsignedInteger('attr_revision')->nullable();
            $table->unsignedInteger('error_log_revision')->nullable();
            $table->unsignedInteger('error_log_sectors')->nullable();
            $table->unsignedInteger('selftest_log_revision')->nullable();
            $table->unsignedInteger('selftest_log_sectors')->nullable();
            $table->unsignedInteger('pending_defects_size')->nullable();
            // Capability flags
            $table->boolean('capability_selftests_supported')->nullable();
            $table->boolean('capability_conveyance_supported')->nullable();
            $table->boolean('capability_selective_supported')->nullable();
            $table->boolean('capability_error_logging_supported')->nullable();
            $table->boolean('capability_gp_logging_supported')->nullable();
            $table->boolean('capability_exec_offline_immediate')->nullable();
            $table->boolean('capability_offline_aborted_on_cmd')->nullable();
            $table->boolean('capability_offline_surface_scan')->nullable();
            $table->boolean('capability_attr_autosave')->nullable();
            // SCT capability flags
            $table->boolean('sct_error_recovery_supported')->nullable();
            $table->boolean('sct_feature_control_supported')->nullable();
            $table->boolean('sct_data_table_supported')->nullable();
        });

        Schema::table('smart_sata_health', function (Blueprint $table) {
            $table->dateTime('selftest_estimated_completion_time')->nullable();
            $table->unsignedBigInteger('selftest_estimated_bytes_sec')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('smart_sata_info', function (Blueprint $table) {
            $table->dropColumn([
                'ata_version_major', 'ata_version_minor', 'user_capacity_blocks',
                'in_smartctl_database', 'smart_available', 'smart_enabled', 'trim_supported',
                'write_cache_enabled', 'read_lookahead_enabled',
                'apm_enabled', 'apm_level',
                'security_state', 'security_enabled', 'security_frozen',
                'if_speed_current_value', 'if_speed_max_value',
                'selftest_polling_short_minutes', 'selftest_polling_extended_minutes',
                'selftest_polling_conveyance_minutes', 'offline_collection_completion_secs',
                'attr_revision', 'error_log_revision', 'error_log_sectors',
                'selftest_log_revision', 'selftest_log_sectors', 'pending_defects_size',
                'capability_selftests_supported', 'capability_conveyance_supported',
                'capability_selective_supported', 'capability_error_logging_supported',
                'capability_gp_logging_supported', 'capability_exec_offline_immediate',
                'capability_offline_aborted_on_cmd', 'capability_offline_surface_scan',
                'capability_attr_autosave',
                'sct_error_recovery_supported', 'sct_feature_control_supported',
                'sct_data_table_supported',
            ]);
        });

        Schema::table('smart_sata_health', function (Blueprint $table) {
            $table->dropColumn(['selftest_estimated_completion_time', 'selftest_estimated_bytes_sec']);
        });
    }
};
