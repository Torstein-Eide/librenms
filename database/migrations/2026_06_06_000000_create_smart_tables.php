<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_app_state', function (Blueprint $table) {
            $table->unsignedInteger('app_id')->primary();
            $table->string('handler', 8);                          // 'mib', 'v2', 'v1'
            $table->string('device_table_last_change', 32)->nullable();
        });

        Schema::create('smart_sata_change', function (Blueprint $table) {
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_idx');                 // smartmonDeviceIndex
            $table->unsignedTinyInteger('table_id');               // SATA_TID_* constant
            $table->unsignedInteger('subindex')->default(0);       // 0 = device-level; >0 = page/error-entry-level
            $table->string('last_change', 32)->nullable();         // DateAndTime from MIB
            $table->primary(['app_id', 'device_idx', 'table_id', 'subindex']);
        });

        Schema::create('smart_devices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedInteger('snmp_index')->nullable();
            $table->string('device_name', 64)->nullable();
            $table->string('device_path', 256)->nullable();
            $table->unsignedTinyInteger('protocol_type')->nullable();
            $table->string('model_family', 128)->nullable();
            $table->string('model_name', 128)->nullable();
            $table->string('serial_number', 64)->nullable();
            $table->string('firmware_version', 32)->nullable();
            $table->string('wwn', 32)->nullable();
            $table->dateTime('last_poll_time')->nullable();
            $table->unsignedTinyInteger('last_poll_result')->nullable();
            $table->unsignedTinyInteger('last_poll_exit')->nullable();
            $table->unsignedInteger('physical_index')->default(0);
            $table->text('uris')->nullable();
            $table->boolean('v1_rrd_migrated')->default(false);
            $table->unique(['app_id', 'disk_key']);
        });

        Schema::create('smart_sata_info', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedTinyInteger('ata_version')->nullable();
            $table->unsignedTinyInteger('sata_version')->nullable();
            $table->unsignedSmallInteger('rotation_rate')->nullable();
            $table->unsignedTinyInteger('form_factor')->nullable();
            $table->unsignedInteger('logical_block_size')->nullable();
            $table->unsignedInteger('physical_block_size')->nullable();
            $table->unsignedBigInteger('user_capacity_bytes')->nullable();
            $table->tinyInteger('sct_hist_op_limit_min')->nullable();  // ata_sct_temperature_history.temperature.op_limit_min
            $table->tinyInteger('sct_hist_op_limit_max')->nullable();  // ata_sct_temperature_history.temperature.op_limit_max
            $table->tinyInteger('sct_hist_limit_min')->nullable();     // ata_sct_temperature_history.temperature.limit_min
            $table->tinyInteger('sct_hist_limit_max')->nullable();     // ata_sct_temperature_history.temperature.limit_max
            $table->unique(['app_id', 'disk_key']);
        });

        Schema::create('smart_sata_health', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedTinyInteger('overall_status')->nullable();
            $table->unsignedInteger('offline_collection_status')->nullable();
            $table->unsignedTinyInteger('selftest_exec_status_raw')->nullable(); // SmartmonAtaSelfTestExecStatus nibble (0-15)
            $table->unsignedBigInteger('power_cycles')->nullable();
            $table->unsignedBigInteger('power_on_hours')->nullable();
            $table->unsignedInteger('error_log_count')->nullable();
            $table->unsignedInteger('pending_defects_count')->nullable();
            $table->unsignedInteger('selftest_log_count')->nullable();
            $table->unsignedInteger('selftest_log_err_total')->nullable();
            $table->unsignedInteger('selftest_log_err_outdated')->nullable();
            $table->unsignedTinyInteger('selftest_remaining_pct')->nullable();
            $table->unsignedInteger('sct_format_version')->nullable();
            $table->unsignedInteger('sct_version')->nullable();
            $table->unsignedTinyInteger('sct_device_state')->nullable();
            $table->tinyInteger('sct_temp_power_cycle_min')->nullable();
            $table->tinyInteger('sct_temp_power_cycle_max')->nullable();
            $table->tinyInteger('sct_temp_lifetime_min')->nullable();
            $table->tinyInteger('sct_temp_lifetime_max')->nullable();
            $table->unsignedInteger('sct_temp_under_limit_count')->nullable();
            $table->unsignedInteger('sct_temp_over_limit_count')->nullable();
            $table->boolean('sct_smart_status_passed')->nullable();
            $table->unique(['app_id', 'disk_key']);
        });

        Schema::create('smart_sata_attributes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedTinyInteger('attribute_id');
            $table->string('name', 64)->nullable();
            $table->unsignedTinyInteger('attr_type')->nullable();
            $table->unsignedTinyInteger('updated_when')->nullable();
            $table->unsignedTinyInteger('value_norm')->nullable();
            $table->unsignedTinyInteger('value_worst')->nullable();
            $table->unsignedTinyInteger('value_threshold')->nullable();
            $table->unsignedBigInteger('value_raw')->nullable();
            $table->string('value_raw_string', 32)->nullable();
            $table->tinyInteger('status')->nullable();
            $table->string('rrd_type', 8)->nullable()->default('GAUGE');
            $table->unique(['app_id', 'disk_key', 'attribute_id']);
        });

        Schema::create('smart_sata_selftest_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedSmallInteger('entry_num');
            $table->unsignedTinyInteger('test_type')->nullable();
            $table->unsignedTinyInteger('result')->nullable();
            $table->boolean('result_passed')->nullable();
            $table->unsignedTinyInteger('remaining_pct')->nullable();
            $table->unsignedBigInteger('power_on_hours')->nullable();
            $table->unsignedBigInteger('lba_first_error')->nullable();
            $table->unique(['app_id', 'disk_key', 'entry_num']);
        });

        Schema::create('smart_sata_error_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedSmallInteger('entry_num');
            $table->unsignedInteger('error_count')->nullable();
            $table->unsignedInteger('lifetime_hours')->nullable();
            $table->string('error_type', 64)->nullable();
            $table->unsignedTinyInteger('device_state')->nullable();
            $table->unsignedTinyInteger('status_register')->nullable();
            $table->unsignedTinyInteger('error_register')->nullable();
            $table->unique(['app_id', 'disk_key', 'entry_num']);
        });

        Schema::create('smart_sata_error_cmd', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedSmallInteger('error_entry_num');
            $table->unsignedTinyInteger('cmd_slot');
            $table->unsignedTinyInteger('reg_command')->nullable();
            $table->unsignedTinyInteger('reg_count')->nullable();
            $table->unsignedTinyInteger('reg_device')->nullable();
            $table->unsignedTinyInteger('reg_error')->nullable();
            $table->unsignedTinyInteger('reg_feature')->nullable();
            $table->unsignedBigInteger('reg_lba')->nullable();
            $table->unsignedInteger('powerup_ms')->nullable();
            $table->string('description', 128)->nullable();
            $table->unique(['app_id', 'disk_key', 'error_entry_num', 'cmd_slot'], 'smart_sata_error_cmd_unique');
        });

        Schema::create('smart_sata_erc', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedTinyInteger('direction');
            $table->boolean('enabled')->nullable();
            $table->unsignedSmallInteger('deciseconds')->nullable();
            $table->unique(['app_id', 'disk_key', 'direction']);
        });

        Schema::create('smart_sata_phy_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedSmallInteger('event_id');
            $table->string('name', 128)->nullable();
            $table->unsignedTinyInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('value')->nullable();
            $table->boolean('overflow')->nullable();
            $table->unique(['app_id', 'disk_key', 'event_id']);
        });

        Schema::create('smart_sata_selective_test', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedTinyInteger('slot');
            $table->unsignedBigInteger('lba_min')->nullable();
            $table->unsignedBigInteger('lba_max')->nullable();
            $table->unsignedTinyInteger('status_value')->nullable();
            $table->unique(['app_id', 'disk_key', 'slot']);
        });

        Schema::create('smart_sata_log_dir', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedTinyInteger('log_address');
            $table->string('name', 128)->nullable();
            $table->boolean('readable')->nullable();
            $table->boolean('writable')->nullable();
            $table->unsignedSmallInteger('gp_sectors')->nullable();
            $table->unsignedSmallInteger('smart_sectors')->nullable();
            $table->unique(['app_id', 'disk_key', 'log_address']);
        });

        Schema::create('smart_sata_dev_stats', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedTinyInteger('page_num');
            $table->unsignedTinyInteger('stat_offset');
            $table->string('page_name', 64)->nullable();
            $table->string('stat_name', 128)->nullable();
            $table->bigInteger('value')->nullable();
            $table->unsignedTinyInteger('flags_value')->nullable();
            $table->boolean('valid')->nullable();
            $table->boolean('normalized')->nullable();
            $table->unique(['app_id', 'disk_key', 'page_num', 'stat_offset']);
        });

        Schema::create('smart_sata_pending_defects', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedSmallInteger('entry_num');
            $table->unsignedBigInteger('lba')->nullable();
            $table->unique(['app_id', 'disk_key', 'entry_num']);
        });

        Schema::create('smart_nvme_info', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedSmallInteger('pci_vendor_id')->nullable();
            $table->unsignedSmallInteger('pci_device_id')->nullable();
            $table->unsignedInteger('ieee_oui')->nullable();
            $table->unsignedBigInteger('total_nvm_capacity_bytes')->nullable();
            $table->unsignedBigInteger('unallocated_nvm_capacity_bytes')->nullable();
            $table->unsignedSmallInteger('controller_id')->nullable();
            $table->string('nvme_version', 32)->nullable();
            $table->unsignedInteger('namespace_count')->nullable();
            $table->unsignedInteger('max_data_transfer_pages')->nullable();
            $table->unique(['app_id', 'disk_key']);
        });

        Schema::create('smart_nvme_health', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedTinyInteger('overall_status')->nullable();
            $table->unsignedTinyInteger('critical_warning')->nullable();
            $table->unsignedBigInteger('data_units_read')->nullable();
            $table->unsignedBigInteger('data_units_written')->nullable();
            $table->unsignedBigInteger('data_bytes_read')->nullable();
            $table->unsignedBigInteger('data_bytes_written')->nullable();
            $table->unsignedBigInteger('host_read_commands')->nullable();
            $table->unsignedBigInteger('host_write_commands')->nullable();
            $table->unsignedBigInteger('controller_busy_time')->nullable();
            $table->unsignedBigInteger('power_cycles')->nullable();
            $table->unsignedBigInteger('power_on_hours')->nullable();
            $table->unsignedBigInteger('unsafe_shutdowns')->nullable();
            $table->unsignedBigInteger('media_errors')->nullable();
            $table->unsignedBigInteger('num_err_log_entries')->nullable();
            $table->unsignedInteger('warning_temp_time')->nullable();
            $table->unsignedInteger('critical_comp_time')->nullable();
            $table->unique(['app_id', 'disk_key']);
        });

        Schema::create('smart_nvme_namespaces', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedInteger('ns_id');
            $table->unsignedBigInteger('nsze')->nullable();
            $table->unsignedBigInteger('ncap')->nullable();
            $table->unsignedBigInteger('nuse')->nullable();
            $table->unsignedInteger('lba_data_size')->nullable();
            $table->unique(['app_id', 'disk_key', 'ns_id']);
        });

        Schema::create('smart_nvme_selftest_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedTinyInteger('entry_num');
            $table->unsignedTinyInteger('test_type')->nullable();
            $table->unsignedTinyInteger('result')->nullable();
            $table->unsignedBigInteger('power_on_hours')->nullable();
            $table->unsignedBigInteger('failing_lba')->nullable();
            $table->unsignedInteger('nsid')->nullable();
            $table->unique(['app_id', 'disk_key', 'entry_num']);
        });

        Schema::create('smart_sas_info', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->string('vendor', 32)->nullable();
            $table->string('product', 64)->nullable();
            $table->string('revision', 16)->nullable();
            $table->string('compliance', 32)->nullable();
            $table->unsignedSmallInteger('rotation_rate')->nullable();
            $table->string('form_factor', 32)->nullable();
            $table->unsignedInteger('logical_block_size')->nullable();
            $table->unsignedInteger('physical_block_size')->nullable();
            $table->unsignedBigInteger('user_capacity_bytes')->nullable();
            $table->unsignedBigInteger('power_cycles')->nullable();
            $table->unsignedBigInteger('power_on_hours')->nullable();
            $table->unique(['app_id', 'disk_key']);
        });

        Schema::create('smart_sas_health', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedTinyInteger('health_status')->nullable();
            $table->unsignedInteger('grown_defect_count')->nullable();
            $table->unsignedBigInteger('non_medium_error_count')->nullable();
            $table->boolean('informational_exceptions')->nullable();
            $table->unsignedInteger('pending_defect_count')->nullable();
            $table->unique(['app_id', 'disk_key']);
        });

        Schema::create('smart_sas_error_counters', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedTinyInteger('direction');
            $table->unsignedBigInteger('ecc_delayed')->nullable();
            $table->unsignedBigInteger('ecc_fast')->nullable();
            $table->unsignedBigInteger('rereads_rewrites')->nullable();
            $table->unsignedBigInteger('total_corrected')->nullable();
            $table->unsignedBigInteger('algorithm_invocations')->nullable();
            $table->unsignedBigInteger('bytes_processed')->nullable();
            $table->unsignedBigInteger('uncorrected_errors')->nullable();
            $table->unique(['app_id', 'disk_key', 'direction']);
        });

        Schema::create('smart_sas_selftest_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('device_id');
            $table->string('disk_key', 160);
            $table->unsignedSmallInteger('entry_num');
            $table->unsignedTinyInteger('test_type')->nullable();
            $table->unsignedTinyInteger('result')->nullable();
            $table->string('result_string', 64)->nullable();
            $table->boolean('result_passed')->nullable();
            $table->unsignedBigInteger('power_on_hours')->nullable();
            $table->unsignedBigInteger('lba_first_error')->nullable();
            $table->unique(['app_id', 'disk_key', 'entry_num']);
        });
    }

    public function down(): void
    {
        Schema::drop('smart_sata_change');
        Schema::drop('smart_app_state');
        Schema::drop('smart_sas_selftest_log');
        Schema::drop('smart_sas_error_counters');
        Schema::drop('smart_sas_health');
        Schema::drop('smart_sas_info');
        Schema::drop('smart_nvme_selftest_log');
        Schema::drop('smart_nvme_namespaces');
        Schema::drop('smart_nvme_health');
        Schema::drop('smart_nvme_info');
        Schema::drop('smart_sata_pending_defects');
        Schema::drop('smart_sata_dev_stats');
        Schema::drop('smart_sata_log_dir');
        Schema::drop('smart_sata_selective_test');
        Schema::drop('smart_sata_phy_events');
        Schema::drop('smart_sata_erc');
        Schema::drop('smart_sata_error_cmd');
        Schema::drop('smart_sata_error_log');
        Schema::drop('smart_sata_selftest_log');
        Schema::drop('smart_sata_attributes');
        Schema::drop('smart_sata_health');
        Schema::drop('smart_sata_info');
        Schema::drop('smart_devices');
    }
};
