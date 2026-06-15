<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * NVMe detail tables (power states, LBA formats, error log, capability) and the
 * extra self-test columns, split out of the create migration so they apply to
 * databases that already ran 2026_06_06_000000_create_smart_tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('smart_nvme_selftest_log')) {
            Schema::table('smart_nvme_selftest_log', function (Blueprint $table) {
                if (! Schema::hasColumn('smart_nvme_selftest_log', 'result_text')) {
                    $table->string('result_text', 96)->nullable()->after('result');
                }
                if (! Schema::hasColumn('smart_nvme_selftest_log', 'estimated_completion')) {
                    $table->dateTime('estimated_completion')->nullable()->after('nsid');
                }
            });
        }

        if (Schema::hasTable('smart_nvme_power_states')) {
            return;
        }

        Schema::create('smart_nvme_power_states', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedTinyInteger('state_id');
            $table->boolean('operational')->nullable();
            $table->unsignedInteger('max_power_mw')->nullable();
            $table->unsignedInteger('active_power_mw')->nullable();
            $table->unsignedInteger('idle_power_mw')->nullable();
            $table->unsignedTinyInteger('read_latency_rank')->nullable();
            $table->unsignedTinyInteger('read_throughput_rank')->nullable();
            $table->unsignedTinyInteger('write_latency_rank')->nullable();
            $table->unsignedTinyInteger('write_throughput_rank')->nullable();
            $table->unsignedInteger('entry_latency_us')->nullable();
            $table->unsignedInteger('exit_latency_us')->nullable();
            $table->unique(['app_id', 'disk_key', 'state_id'], 'smart_nvme_power_states_unique');
        });

        Schema::create('smart_nvme_lba_formats', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedInteger('ns_id');
            $table->unsignedTinyInteger('format_id');
            $table->boolean('current')->nullable();
            $table->unsignedInteger('data_size_bytes')->nullable();
            $table->unsignedInteger('metadata_size_bytes')->nullable();
            $table->unsignedTinyInteger('relative_performance')->nullable();
            $table->unique(['app_id', 'disk_key', 'ns_id', 'format_id'], 'smart_nvme_lba_formats_unique');
        });

        Schema::create('smart_nvme_error_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedInteger('entry_num');
            $table->unsignedBigInteger('error_count')->nullable();
            $table->unsignedInteger('sq_id')->nullable();
            $table->unsignedInteger('command_id')->nullable();
            $table->unsignedInteger('status_field')->nullable();
            $table->unsignedInteger('param_error_location')->nullable();
            $table->unsignedBigInteger('lba')->nullable();
            $table->unsignedInteger('ns_id')->nullable();
            $table->unsignedInteger('vendor_info')->nullable();
            $table->unsignedInteger('status_code')->nullable();
            $table->unsignedInteger('status_code_type')->nullable();
            $table->boolean('do_not_retry')->nullable();
            $table->string('status_string', 128)->nullable();
            $table->dateTime('error_time')->nullable();
            $table->unique(['app_id', 'disk_key', 'entry_num'], 'smart_nvme_error_log_unique');
        });

        Schema::create('smart_nvme_capability', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedInteger('firmware_update_raw')->nullable();
            $table->unsignedTinyInteger('firmware_slot_count')->nullable();
            $table->boolean('firmware_reset_required')->nullable();
            $table->unsignedInteger('optional_admin_cmd_raw')->nullable();
            $table->unsignedInteger('optional_nvm_cmd_raw')->nullable();
            $table->unsignedInteger('log_page_attrs_raw')->nullable();
            $table->string('optional_admin_cmd_text', 255)->nullable();
            $table->string('optional_nvm_cmd_text', 255)->nullable();
            $table->string('log_page_attrs_text', 255)->nullable();
            $table->unique(['app_id', 'disk_key'], 'smart_nvme_capability_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_nvme_capability');
        Schema::dropIfExists('smart_nvme_error_log');
        Schema::dropIfExists('smart_nvme_lba_formats');
        Schema::dropIfExists('smart_nvme_power_states');

        if (Schema::hasTable('smart_nvme_selftest_log')) {
            Schema::table('smart_nvme_selftest_log', function (Blueprint $table) {
                foreach (['result_text', 'estimated_completion'] as $col) {
                    if (Schema::hasColumn('smart_nvme_selftest_log', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
