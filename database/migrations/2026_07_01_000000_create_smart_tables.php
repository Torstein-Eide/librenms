<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Full SMART app schema, squashed from the original create migration plus all
 * follow-up migrations that extended it (2026_06_07 through 2026_06_22). Every
 * step is guarded with hasTable()/hasColumn() so this migration is safe to run
 * against a database that already has some or all of these tables/columns
 * from the pre-squash migration set.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('smart_app_state')) {
            Schema::create('smart_app_state', function (Blueprint $table) {
                $table->unsignedInteger('app_id')->primary();
                $table->string('handler', 8);                          // 'mib', 'v2', 'v1'
                $table->string('device_table_last_change', 32)->nullable();
            });
        }

        if (! Schema::hasTable('smart_sata_change')) {
            Schema::create('smart_sata_change', function (Blueprint $table) {
                $table->unsignedInteger('app_id');
                $table->unsignedInteger('device_idx');                 // smartmonDeviceIndex
                $table->unsignedTinyInteger('table_id');               // SATA_TID_* constant
                $table->unsignedInteger('subindex')->default(0);       // 0 = device-level; >0 = page/error-entry-level
                $table->string('last_change', 32)->nullable();         // DateAndTime from MIB
                $table->primary(['app_id', 'device_idx', 'table_id', 'subindex']);
            });
        }

        if (! Schema::hasTable('smart_devices')) {
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
                $table->unsignedTinyInteger('power_state')->nullable();
                $table->unsignedInteger('physical_index')->default(0);
                $table->text('uris')->nullable();
                $table->boolean('v1_rrd_migrated')->default(false);
                $table->unique(['app_id', 'disk_key']);
            });
        } elseif (! Schema::hasColumn('smart_devices', 'power_state')) {
            Schema::table('smart_devices', function (Blueprint $table) {
                $table->unsignedTinyInteger('power_state')->nullable()->after('last_poll_exit');
            });
        }

        if (! Schema::hasTable('smart_sata_info')) {
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
                $table->unique(['app_id', 'disk_key']);
            });
        } else {
            if (! Schema::hasColumn('smart_sata_info', 'ata_version_major')) {
                Schema::table('smart_sata_info', function (Blueprint $table) {
                    $table->unsignedSmallInteger('ata_version_major')->nullable();
                    $table->unsignedSmallInteger('ata_version_minor')->nullable();
                    $table->unsignedBigInteger('user_capacity_blocks')->nullable();
                    $table->boolean('in_smartctl_database')->nullable();
                    $table->boolean('smart_available')->nullable();
                    $table->boolean('smart_enabled')->nullable();
                    $table->boolean('trim_supported')->nullable();
                    $table->boolean('write_cache_enabled')->nullable();
                    $table->boolean('read_lookahead_enabled')->nullable();
                    $table->boolean('apm_enabled')->nullable();
                    $table->unsignedTinyInteger('apm_level')->nullable();
                    $table->unsignedInteger('security_state')->nullable();
                    $table->boolean('security_enabled')->nullable();
                    $table->boolean('security_frozen')->nullable();
                    $table->unsignedInteger('if_speed_current_value')->nullable();
                    $table->unsignedInteger('if_speed_max_value')->nullable();
                    $table->unsignedInteger('selftest_polling_short_minutes')->nullable();
                    $table->unsignedInteger('selftest_polling_extended_minutes')->nullable();
                    $table->unsignedInteger('selftest_polling_conveyance_minutes')->nullable();
                    $table->unsignedInteger('offline_collection_completion_secs')->nullable();
                    $table->unsignedInteger('attr_revision')->nullable();
                    $table->unsignedInteger('error_log_revision')->nullable();
                    $table->unsignedInteger('error_log_sectors')->nullable();
                    $table->unsignedInteger('selftest_log_revision')->nullable();
                    $table->unsignedInteger('selftest_log_sectors')->nullable();
                    $table->unsignedInteger('pending_defects_size')->nullable();
                    $table->boolean('capability_selftests_supported')->nullable();
                    $table->boolean('capability_conveyance_supported')->nullable();
                    $table->boolean('capability_selective_supported')->nullable();
                    $table->boolean('capability_error_logging_supported')->nullable();
                    $table->boolean('capability_gp_logging_supported')->nullable();
                    $table->boolean('capability_exec_offline_immediate')->nullable();
                    $table->boolean('capability_offline_aborted_on_cmd')->nullable();
                    $table->boolean('capability_offline_surface_scan')->nullable();
                    $table->boolean('capability_attr_autosave')->nullable();
                    $table->boolean('sct_error_recovery_supported')->nullable();
                    $table->boolean('sct_feature_control_supported')->nullable();
                    $table->boolean('sct_data_table_supported')->nullable();
                });
            } else {
                // Older databases may have picked up ata_version_major as TINYINT before it was
                // widened to a 16-bit bitmask column; make sure the type is correct either way.
                Schema::table('smart_sata_info', function (Blueprint $table) {
                    $table->unsignedSmallInteger('ata_version_major')->nullable()->change();
                });
            }
        }

        if (! Schema::hasTable('smart_sata_health')) {
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
                $table->dateTime('selftest_estimated_completion_time')->nullable();
                $table->unsignedBigInteger('selftest_estimated_bytes_sec')->nullable();
                $table->unique(['app_id', 'disk_key']);
            });
        } elseif (! Schema::hasColumn('smart_sata_health', 'selftest_estimated_completion_time')) {
            Schema::table('smart_sata_health', function (Blueprint $table) {
                $table->dateTime('selftest_estimated_completion_time')->nullable();
                $table->unsignedBigInteger('selftest_estimated_bytes_sec')->nullable();
            });
        }

        if (! Schema::hasTable('smart_sata_attributes')) {
            Schema::create('smart_sata_attributes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('app_id');
                $table->unsignedInteger('device_id');
                $table->string('disk_key', 160);
                $table->unsignedTinyInteger('attribute_id');
                $table->string('name', 64)->nullable();
                // smartmonSataAttrFlags BITS bitmask: bit0 prefailure, bit1 onlineCollection,
                // bit2 performance, bit3 errorRate, bit4 eventCount, bit5 autoKeep.
                $table->unsignedSmallInteger('flags')->nullable();
                $table->unsignedTinyInteger('value_norm')->nullable();
                $table->unsignedTinyInteger('value_worst')->nullable();
                $table->unsignedTinyInteger('value_threshold')->nullable();
                $table->unsignedBigInteger('value_raw')->nullable();
                $table->string('value_raw_string', 32)->nullable();
                $table->tinyInteger('status')->nullable();
                // -1 = no rate-of-change threshold enabled for this disk/attribute
                //  1 = threshold enabled, no window currently exceeds it
                //  2 = threshold enabled, at least one window exceeds it
                $table->tinyInteger('rate_status')->nullable()->default(-1);
                // device-reported smartmonSataAttrFormat enum (SmartmonAtaSmartAttrFormat,
                // SMARTMON-TC-MIB) indicating how value_raw_string is encoded.
                $table->unsignedTinyInteger('format')->nullable();
                $table->string('rrd_type', 8)->nullable()->default('GAUGE');
                // discovery-computed average raw-value change per hour over the last
                // 8h / 24h / 168h (1wk) / 672h (1mo).
                $table->float('rate_8h')->nullable();
                $table->float('rate_24h')->nullable();
                $table->float('rate_168h')->nullable();
                $table->float('rate_672h')->nullable();
                $table->unique(['app_id', 'disk_key', 'attribute_id']);
            });
        } else {
            Schema::table('smart_sata_attributes', function (Blueprint $table) {
                if (! Schema::hasColumn('smart_sata_attributes', 'rate_8h')) {
                    $table->float('rate_8h')->nullable()->after('rrd_type');
                    $table->float('rate_24h')->nullable()->after('rate_8h');
                    $table->float('rate_168h')->nullable()->after('rate_24h');
                    $table->float('rate_672h')->nullable()->after('rate_168h');
                }
                if (! Schema::hasColumn('smart_sata_attributes', 'rate_status')) {
                    $table->tinyInteger('rate_status')->nullable()->default(-1)->after('status');
                }
                if (! Schema::hasColumn('smart_sata_attributes', 'format')) {
                    $table->unsignedTinyInteger('format')->nullable()->after('status');
                }
            });
        }

        if (! Schema::hasTable('smart_sata_selftest_log')) {
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
        }

        if (! Schema::hasTable('smart_sata_error_log')) {
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
        }

        if (! Schema::hasTable('smart_sata_error_cmd')) {
            Schema::create('smart_sata_error_cmd', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('app_id');
                $table->unsignedInteger('device_id');
                $table->string('disk_key', 160);
                $table->unsignedSmallInteger('error_entry_num');
                $table->unsignedTinyInteger('cmd_slot');
                $table->unsignedTinyInteger('reg_command')->nullable();
                // 16-bit: in 48-bit ATA commands smartmontools reports the combined hi/lo
                // register bytes, so an 8-bit column overflows on values >255 (e.g. reg_feature 1344).
                $table->unsignedSmallInteger('reg_count')->nullable();
                $table->unsignedTinyInteger('reg_device')->nullable();
                $table->unsignedTinyInteger('reg_error')->nullable();
                $table->unsignedSmallInteger('reg_feature')->nullable();
                $table->unsignedBigInteger('reg_lba')->nullable();
                $table->unsignedInteger('powerup_ms')->nullable();
                $table->string('description', 128)->nullable();
                $table->unique(['app_id', 'disk_key', 'error_entry_num', 'cmd_slot'], 'smart_sata_error_cmd_unique');
            });
        } elseif (Schema::hasColumn('smart_sata_error_cmd', 'reg_count')) {
            Schema::table('smart_sata_error_cmd', function (Blueprint $table) {
                $table->unsignedSmallInteger('reg_count')->nullable()->change();
                $table->unsignedSmallInteger('reg_feature')->nullable()->change();
            });
        }

        if (! Schema::hasTable('smart_sata_erc')) {
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
        }

        if (! Schema::hasTable('smart_sata_phy_events')) {
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
        }

        if (! Schema::hasTable('smart_sata_selective_test')) {
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
        }

        if (! Schema::hasTable('smart_sata_log_dir')) {
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
        }

        if (! Schema::hasTable('smart_sata_dev_stats')) {
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
        }

        if (! Schema::hasTable('smart_sata_pending_defects')) {
            Schema::create('smart_sata_pending_defects', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('app_id');
                $table->unsignedInteger('device_id');
                $table->string('disk_key', 160);
                $table->unsignedSmallInteger('entry_num');
                $table->unsignedBigInteger('lba')->nullable();
                $table->unique(['app_id', 'disk_key', 'entry_num']);
            });
        }

        if (! Schema::hasTable('smart_nvme_info')) {
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
                $table->unsignedTinyInteger('link_power_state')->nullable();
                $table->unsignedInteger('max_link_speed')->nullable();
                $table->unsignedTinyInteger('max_link_width')->nullable();
                $table->unsignedInteger('current_link_speed')->nullable();
                $table->unsignedTinyInteger('current_link_width')->nullable();
                $table->unique(['app_id', 'disk_key']);
            });
        } elseif (! Schema::hasColumn('smart_nvme_info', 'link_power_state')) {
            Schema::table('smart_nvme_info', function (Blueprint $table) {
                $table->unsignedTinyInteger('link_power_state')->nullable()->after('max_data_transfer_pages');
                $table->unsignedInteger('max_link_speed')->nullable()->after('link_power_state');
                $table->unsignedTinyInteger('max_link_width')->nullable()->after('max_link_speed');
                $table->unsignedInteger('current_link_speed')->nullable()->after('max_link_width');
                $table->unsignedTinyInteger('current_link_width')->nullable()->after('current_link_speed');
            });
        }

        if (! Schema::hasTable('smart_nvme_health')) {
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
                // NVMe "current self-test operation" (in-progress test): its operation value,
                // human-readable string, and completion percentage
                // (smartmonNvmeCurrentSelfTestOperation* / CompletionPercent).
                $table->unsignedInteger('current_selftest_op')->nullable();
                $table->string('current_selftest_str', 96)->nullable();
                $table->unsignedTinyInteger('current_selftest_pct')->nullable();
                $table->unique(['app_id', 'disk_key']);
            });
        } elseif (! Schema::hasColumn('smart_nvme_health', 'current_selftest_op')) {
            Schema::table('smart_nvme_health', function (Blueprint $table) {
                $table->unsignedInteger('current_selftest_op')->nullable()->after('critical_comp_time');
                $table->string('current_selftest_str', 96)->nullable()->after('current_selftest_op');
                $table->unsignedTinyInteger('current_selftest_pct')->nullable()->after('current_selftest_str');
            });
        }

        if (! Schema::hasTable('smart_nvme_namespaces')) {
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
        }

        if (! Schema::hasTable('smart_nvme_selftest_log')) {
            Schema::create('smart_nvme_selftest_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('app_id');
                $table->unsignedInteger('device_id');
                $table->string('disk_key', 160);
                $table->unsignedTinyInteger('entry_num');
                $table->unsignedTinyInteger('test_type')->nullable();
                $table->unsignedTinyInteger('result')->nullable();
                $table->string('result_text', 96)->nullable();
                $table->unsignedBigInteger('power_on_hours')->nullable();
                $table->unsignedBigInteger('failing_lba')->nullable();
                $table->unsignedInteger('nsid')->nullable();
                $table->dateTime('estimated_completion')->nullable();
                $table->unique(['app_id', 'disk_key', 'entry_num']);
            });
        } else {
            Schema::table('smart_nvme_selftest_log', function (Blueprint $table) {
                if (! Schema::hasColumn('smart_nvme_selftest_log', 'result_text')) {
                    $table->string('result_text', 96)->nullable()->after('result');
                }
                if (! Schema::hasColumn('smart_nvme_selftest_log', 'estimated_completion')) {
                    $table->dateTime('estimated_completion')->nullable()->after('nsid');
                }
            });
        }

        if (! Schema::hasTable('smart_nvme_power_states')) {
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
        }

        if (! Schema::hasTable('smart_nvme_lba_formats')) {
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
        }

        if (! Schema::hasTable('smart_nvme_error_log')) {
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
        }

        if (! Schema::hasTable('smart_nvme_capability')) {
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

        if (! Schema::hasTable('smart_sas_info')) {
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
        }

        if (! Schema::hasTable('smart_sas_health')) {
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
        }

        if (! Schema::hasTable('smart_sas_error_counters')) {
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
        }

        if (! Schema::hasTable('smart_sas_selftest_log')) {
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

        if (! Schema::hasTable('smart_attribute_thresholds')) {
            /**
             * Warning-rate thresholds for SMART SATA attributes (changes/hour, compared
             * against smart_sata_attributes.rate_8h/24h/168h/672h).
             *
             * A row with app_id=0 / disk_key='' is the global default for an
             * attribute_id; a row with app_id+disk_key set overrides it for one disk.
             * (0/'' sentinels, not NULL, so the (app_id, disk_key, attribute_id) unique
             * index actually enforces "one global row per attribute_id". MySQL unique
             * indexes do not treat two NULLs as equal, so NULL sentinels would silently
             * allow duplicate global rows.)
             */
            Schema::create('smart_attribute_thresholds', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('app_id')->default(0);
                $table->string('disk_key', 160)->default('');
                $table->unsignedTinyInteger('attribute_id');
                $table->float('warn_rate_8h')->nullable();
                $table->float('warn_rate_24h')->nullable();
                $table->float('warn_rate_168h')->nullable();
                $table->float('warn_rate_672h')->nullable();
                // Explicit on/off switch for rate-of-change alerting on a given (scope,
                // attribute_id) row, independent of whether any warn_rate_* limit is
                // configured. Lets a user keep limits configured but temporarily mute
                // alerting, same as sensors.sensor_alert does for the device health page.
                $table->boolean('alert_enabled')->default(true);
                $table->timestamps();
                $table->unique(['app_id', 'disk_key', 'attribute_id']);
            });
        } elseif (! Schema::hasColumn('smart_attribute_thresholds', 'alert_enabled')) {
            Schema::table('smart_attribute_thresholds', function (Blueprint $table) {
                $table->boolean('alert_enabled')->default(true)->after('warn_rate_672h');
            });
        }

        if (! Schema::hasTable('smart_app_settings')) {
            /**
             * One row per app_id, plus a sentinel app_id=0 row for the global naming
             * template (same app_id=0 convention as smart_attribute_thresholds' global
             * default row). naming_template on the app_id=0 row is the installation-wide
             * default disk-naming template, shared by every device; disk_naming_templates
             * is a per-device JSON map of disk_key => template for per-disk overrides (a
             * disk only appears here when it has an explicit override. Absent means it
             * inherits the global template, or the "$device" fallback if neither is
             * set). default_view_mode is the initial per-disk view mode
             * (Basic/Metadata/Self-test/Statistics/Graphs) used on the overview page
             * before the user picks one (which is then remembered client-side via
             * cookie, same as the label-mode selector). This one stays per-device.
             */
            Schema::create('smart_app_settings', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('app_id')->unique();
                $table->string('naming_template', 120)->nullable();
                $table->json('disk_naming_templates')->nullable();
                $table->string('default_view_mode', 32)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_app_settings');
        Schema::dropIfExists('smart_attribute_thresholds');
        Schema::dropIfExists('smart_sata_change');
        Schema::dropIfExists('smart_app_state');
        Schema::dropIfExists('smart_sas_selftest_log');
        Schema::dropIfExists('smart_sas_error_counters');
        Schema::dropIfExists('smart_sas_health');
        Schema::dropIfExists('smart_sas_info');
        Schema::dropIfExists('smart_nvme_capability');
        Schema::dropIfExists('smart_nvme_error_log');
        Schema::dropIfExists('smart_nvme_lba_formats');
        Schema::dropIfExists('smart_nvme_power_states');
        Schema::dropIfExists('smart_nvme_selftest_log');
        Schema::dropIfExists('smart_nvme_namespaces');
        Schema::dropIfExists('smart_nvme_health');
        Schema::dropIfExists('smart_nvme_info');
        Schema::dropIfExists('smart_sata_pending_defects');
        Schema::dropIfExists('smart_sata_dev_stats');
        Schema::dropIfExists('smart_sata_log_dir');
        Schema::dropIfExists('smart_sata_selective_test');
        Schema::dropIfExists('smart_sata_phy_events');
        Schema::dropIfExists('smart_sata_erc');
        Schema::dropIfExists('smart_sata_error_cmd');
        Schema::dropIfExists('smart_sata_error_log');
        Schema::dropIfExists('smart_sata_selftest_log');
        Schema::dropIfExists('smart_sata_attributes');
        Schema::dropIfExists('smart_sata_health');
        Schema::dropIfExists('smart_sata_info');
        Schema::dropIfExists('smart_devices');
    }
};
