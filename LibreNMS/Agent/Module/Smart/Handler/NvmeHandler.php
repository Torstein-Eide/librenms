<?php

namespace LibreNMS\Agent\Module\Smart\Handler;

use App\Models\StateTranslation;
use Illuminate\Support\Facades\DB;
use LibreNMS\Agent\Module\Smart\Context;
use LibreNMS\Agent\Module\Smart\DeviceTable;
use LibreNMS\Agent\Module\Smart\Helpers\DiskIdentity;
use LibreNMS\Agent\Module\Smart\Support\DbSync;
use LibreNMS\Agent\Module\Smart\Support\SelftestAge;
use LibreNMS\Agent\Module\Smart\Support\SnmpDecode as SmartSnmpDecode;
use LibreNMS\Data\Store\Rrd;
use LibreNMS\Enum\Severity;
use LibreNMS\RRD\RrdDefinition;
use LibreNMS\Util\SnmpDecode;
use SnmpQuery;

/**
 * NVMe device-type pipeline: discovery, polling, and DB/RRD sync for every
 * smartmonDeviceType=nvme(5) device. NVMe has no change table (unlike SATA),
 * so every table is walked and synced unconditionally each cycle.
 */
final class NvmeHandler implements DiskTypeHandler
{
    public const TYPES = [5];

    private const NVME_MIBS = ['SMARTMON-TC-MIB', 'SMARTMON-COMMON-MIB', 'SMARTMON-NVME-MIB'];

    // Per-disk RRD DS heartbeat: see SataHandler::RRD_HEARTBEAT for the rationale.
    private const RRD_HEARTBEAT = 86400;

    // NVMe SMART/Health columns written to the per-disk smart_nvme RRD: MIB column => [DS name, type].
    // DS names + types MUST match the V1 nvmeDsMap (smart.php) and includes/html/graphs/smart/nvme.inc.php graph,
    // since V1 and V2 share the same smart_nvme RRD file. Rate-style figures are DERIVE.
    private const NVME_HEALTH_RRD = [
        'smartmonNvmeDataUnitsRead'                  => ['du_rd',        'DERIVE'],
        'smartmonNvmeDataUnitsWritten'               => ['du_wr',        'DERIVE'],
        'smartmonNvmeHostReadCommands'               => ['host_rd',      'DERIVE'],
        'smartmonNvmeHostWriteCommands'              => ['host_wr',      'DERIVE'],
        'smartmonNvmeControllerBusyTimeMinutes'      => ['ctrl_busy',    'DERIVE'],
        'smartmonNvmeWarningTemperatureTimeMinutes'  => ['warn_tmp_t',   'DERIVE'],
        'smartmonNvmeCriticalTemperatureTimeMinutes' => ['crit_cmp_t',   'DERIVE'],
        'smartmonNvmeMediaDataIntegrityErrors'       => ['media_errors', 'GAUGE'],
        'smartmonNvmeErrorInformationLogEntries'     => ['err_log_cnt',  'GAUGE'],
        'smartmonNvmePowerCycles'                    => ['pwr_cycles',   'GAUGE'],
        'smartmonNvmePowerOnHours'                   => ['pwr_hours',    'GAUGE'],
        'smartmonNvmeUnsafeShutdowns'                => ['unsafe_shut',  'GAUGE'],
        'smartmonNvmeCriticalWarning'                => ['crit_warn',    'GAUGE'],
    ];

    public function __construct(private readonly Context $ctx, private readonly DeviceTable $deviceTable)
    {
    }

    public static function types(): array
    {
        return self::TYPES;
    }

    /**
     * Discover all NVMe tables. NVMe has no change table, so every table is
     * walked once and synced for each NVMe device. Temperature / spare / used
     * sensors come from the SENSOR-MIB and are registered by Common.
     */
    public function discover(array $devices, array $sensorRows): void
    {
        if ($devices === []) {
            return;
        }

        $controllers = $this->walkNvmeTable('smartmonNvmeControllerTable', 2);
        $namespaces = $this->walkNvmeTable('smartmonNvmeNamespaceTable', 2);
        $powerStates = $this->walkNvmeTable('smartmonNvmePowerStateTable', 2);
        $lbaFormats = $this->walkNvmeTable('smartmonNvmeLbaFormatTable', 3);
        $capabilities = $this->walkNvmeTable('smartmonNvmeCapabilityTable', 2);
        $errors = $this->walkNvmeTable('smartmonNvmeErrorLogTable', 2);
        $selftests = $this->walkNvmeTable('smartmonNvmeSelfTestTable', 2);
        $health = $this->walkNvmeTable('smartmonNvmeHealthTable', 2);

        foreach ($devices as $devIdx => $dev) {
            $key = (string) $devIdx;
            $this->ctx->vlog("NvmeHandler::discover: device idx={$key} disk_key={$dev['disk_key']}");

            // Missing (grace-period) devices carry no fresh SNMP data to discover
            // against -- just keep their Health sensor registered as Unavailable.
            if (! empty($dev['missing_since'])) {
                $this->deviceTable->markMissingHealthDiscovered($dev, 'smart_nvme_health', 5, self::healthStateTranslations());

                continue;
            }

            // Retrofit power_state onto a pre-existing RRD file that predates it;
            // no-op tune if already present, skipped entirely if the file doesn't
            // exist yet (a new device gets it at create time from pollNvmeDeviceRrd()).
            $idx = DiskIdentity::index($dev['disk_key']);
            $rrdFile = app(Rrd::class)->name($this->ctx->device->hostname, ['app', 'smart_nvme', $this->ctx->appId, $idx]);
            app(Rrd::class)->addDatasetsFromConfig($rrdFile, [
                'power_state' => ['type' => 'GAUGE', 'heartbeat' => self::RRD_HEARTBEAT, 'min' => 0, 'max' => 8],
            ]);

            if ($ctrl = $this->firstSubRow($controllers[$key] ?? null)) {
                $this->syncNvmeInfoRow($dev, $ctrl);
            }
            $this->syncNvmeNamespaceRows($dev, $this->subRows($namespaces[$key] ?? null));
            $this->syncNvmePowerStateRows($dev, $this->subRows($powerStates[$key] ?? null));
            $this->syncNvmeLbaFormatRows($dev, is_array($lbaFormats[$key] ?? null) ? $lbaFormats[$key] : []);
            $this->syncNvmeSelfTestRows($dev, $this->subRows($selftests[$key] ?? null));
            $this->syncNvmeErrorLogRows($dev, $this->subRows($errors[$key] ?? null));
            if ($cap = $this->firstSubRow($capabilities[$key] ?? null)) {
                $this->syncNvmeCapabilityRow($dev, $cap);
            }

            if ($healthRow = $this->firstSubRow($health[$key] ?? null)) {
                $this->syncNvmeHealthRow($dev, $healthRow);
                $this->discoverNvmeDeviceSensors($dev, $healthRow);
                $this->discoverNvmeSelftestStatusSensor($dev, $healthRow, $this->subRows($selftests[$key] ?? null));
            }
        }

        SelftestAge::discoverSensors($this->ctx, $devices, 'smart_nvme_selftest_', 'smart_nvme_health', 'smart_nvme_selftest_log');
        $this->syncNvmeSensorTypes();
    }

    /**
     * Poll NVMe health (DB + RRD) and refresh the self-test log for each device.
     * State sensors are updated from the DB in this method's per-device pass.
     */
    public function poll(array $devices): void
    {
        if ($devices === []) {
            return;
        }

        $health = $this->walkNvmeTable('smartmonNvmeHealthTable', 2);
        $selftests = $this->walkNvmeTable('smartmonNvmeSelfTestTable', 2);
        $errors = $this->walkNvmeTable('smartmonNvmeErrorLogTable', 2);

        foreach ($devices as $devIdx => $dev) {
            $skip = $this->deviceTable->pollSkipReason($dev);
            if ($skip === 'missing') {
                $this->deviceTable->markMissingHealthPolled($dev, 5);
                $this->markDeviceMissingRrd($dev);
                continue;
            }
            if ($skip === 'idle') {
                continue;
            }

            $key = (string) $devIdx;
            if ($healthRow = $this->firstSubRow($health[$key] ?? null)) {
                $this->syncNvmeHealthRow($dev, $healthRow);
                $this->pollNvmeDeviceRrd($dev, $healthRow);
            }
            $this->syncNvmeSelfTestRows($dev, $this->subRows($selftests[$key] ?? null));
            $this->syncNvmeErrorLogRows($dev, $this->subRows($errors[$key] ?? null));

            // Health, self-test status, and self-test age sensors, computed from the
            // tables just synced above and batched through a single updateSensorValues()
            // call so stored multipliers (selftest age -> minutes), threshold alerts, and
            // state-change events are all applied.
            $this->pollNvmeDeviceSensors($dev);
        }
    }

    /**
     * Write an explicit 'U' (unknown) to a missing disk's RRD file this poll
     * cycle. See SataHandler::markDeviceMissingRrd() for why.
     */
    private function markDeviceMissingRrd(array $dev): void
    {
        $idx = DiskIdentity::index($dev['disk_key']);
        $rrdFile = app(Rrd::class)->name($this->ctx->device->hostname, ['app', 'smart_nvme', $this->ctx->appId, $idx]);
        app(Rrd::class)->writeUnknown($rrdFile);
    }

    public function expectedSensorOids(string $idx): array
    {
        return [
            "{$idx}_health",
            "{$idx}_selftest_status",
            "{$idx}_selftest_short",
            "{$idx}_selftest_long",
        ];
    }

    /** Register the merged NVMe health-state sensor (overall status + critical warning) for one device. */
    private function discoverNvmeDeviceSensors(array $dev, array $health): void
    {
        if (! isset($health['smartmonNvmeHealthOverallStatus'])) {
            return;
        }

        $idx = DiskIdentity::index($dev['disk_key']);
        $devName = DiskIdentity::label($dev, $dev['snmp_index']);
        $group = 'SMART';

        $state = $this->nvmeHealthLevel(
            $health['smartmonNvmeHealthOverallStatus'],
            $health['smartmonNvmeCriticalWarning'] ?? null
        );

        $this->ctx->discoverSensor(
            class: 'state',
            type: 'smart_nvme_health',
            index: "{$idx}_health",
            oid: "app:smart_mib:{$idx}_health",
            descr: "{$group} {$devName} Health",
            current: $state,
            group: $group,
        )->withStateTranslations('smart_nvme_health', self::healthStateTranslations());
    }

    /** @return array<int, StateTranslation> */
    private static function healthStateTranslations(): array
    {
        return [
            StateTranslation::define('OK', 1, Severity::Ok),
            StateTranslation::define('Warning', 2, Severity::Warning),
            StateTranslation::define('Failed', 3, Severity::Error),
            StateTranslation::define('Critical Warning', 4, Severity::Error),
            StateTranslation::define('Unavailable', 5, Severity::Error),
        ];
    }

    /** Register the NVMe self-test status sensor (current op, else most recent log result) for one device. */
    private function discoverNvmeSelftestStatusSensor(array $dev, array $health, array $selftestRows): void
    {
        $currentOp = (int) ($health['smartmonNvmeCurrentSelfTestOperationValue'] ?? 0);
        $entries = array_map(static fn ($row) => [
            'result'         => $row['smartmonNvmeSelfTestResult'] ?? null,
            'power_on_hours' => $row['smartmonNvmeSelfTestPowerOnHours'] ?? null,
        ], $selftestRows);

        $value = $this->nvmeSelftestStatusValue($currentOp, $entries);
        if ($value === null) {
            return;
        }

        $idx = DiskIdentity::index($dev['disk_key']);
        $devName = DiskIdentity::label($dev, $dev['snmp_index']);
        $group = 'SMART';

        $this->ctx->discoverSensor(
            class: 'state',
            type: 'smart_nvme_selftest_status',
            index: "{$idx}_selftest_status",
            oid: "app:smart_mib:{$idx}_selftest_status",
            descr: "{$group} {$devName} Self-test Status",
            current: $value,
            group: $group,
        )
            ->withStateTranslations('smart_nvme_selftest_status', [
                StateTranslation::define('Completed without error', 0, Severity::Ok),
                StateTranslation::define('Aborted by self-test command', 1, Severity::Ok),
                StateTranslation::define('Aborted by controller level reset', 2, Severity::Ok),
                StateTranslation::define('Aborted due to removal of a namespace', 3, Severity::Ok),
                StateTranslation::define('Aborted due to processing of a Format NVM command', 4, Severity::Ok),
                StateTranslation::define('Completed: segment failed', 5, Severity::Warning),
                StateTranslation::define('Failed for unknown reason', 6, Severity::Warning),
                StateTranslation::define('Completed: failed segment unknown', 7, Severity::Warning),
                StateTranslation::define('Completed: one or more segments failed', 8, Severity::Warning),
                StateTranslation::define('Self-test in progress', 15, Severity::Ok),
            ]);
    }

    /**
     * NVMe self-test status code, mirroring SATA's exec-status convention: the most
     * recent completed self-test's NVMe-spec result code (0-8), or 15 while a
     * self-test operation is currently running. Null when there's no data at all.
     *
     * @param  array<int, array{result: mixed, power_on_hours: mixed}>  $entries
     */
    private function nvmeSelftestStatusValue(int $currentOp, array $entries): ?int
    {
        if ($currentOp !== 0) {
            return 15;
        }
        if ($entries === []) {
            return null;
        }
        $latest = null;
        foreach ($entries as $e) {
            if ($latest === null || (int) ($e['power_on_hours'] ?? 0) >= (int) ($latest['power_on_hours'] ?? 0)) {
                $latest = $e;
            }
        }
        $result = $latest['result'] ?? null;

        return is_numeric($result) ? (int) $result : null;
    }

    /**
     * Merge SmartmonHealthStatus and the Critical Warning bitmask into a single
     * 1–5 health level. A set critical-warning bit always escalates to Critical(4),
     * since those conditions (spare low, temp critical, read-only, …) are urgent.
     */
    private function nvmeHealthLevel(mixed $overallRaw, mixed $critRaw): int
    {
        if (SnmpDecode::parseBitsValue($critRaw)) {
            return 4; // Critical Warning
        }

        return match ($this->healthStatusValue($overallRaw)) {
            1 => 1,       // passed       -> OK
            2 => 3,       // failed       -> Failed
            4 => 5,       // unavailable  -> Unavailable
            3 => 2,       // warning      -> Warning
            default => 2, // unknown/null -> Warning
        };
    }

    /** Register NVMe-specific sensor types with the discovery system. */
    private function syncNvmeSensorTypes(): void
    {
        foreach (['smart_nvme_health', 'smart_nvme_selftest_status', 'smart_nvme_selftest_short', 'smart_nvme_selftest_long'] as $type) {
            app('sensor-discovery')->sync(sensor_type: $type);
        }
    }

    /** Update the NVMe Health, Self-test Status, and Self-test age sensors for one device. */
    private function pollNvmeDeviceSensors(array $dev): void
    {
        $diskKey = $dev['disk_key'];
        $idx = DiskIdentity::index($diskKey);
        $values = [];

        // Merged health state: overall status plus critical warning, stored at poll time.
        $row = DB::table('smart_nvme_health')
            ->where('app_id', $this->ctx->appId)
            ->where('disk_key', $diskKey)
            ->first(['overall_status', 'critical_warning', 'current_selftest_op']);
        $currentSelftestOp = 0;
        if ($row !== null) {
            $values["{$idx}_health"] = (float) $this->nvmeHealthLevel($row->overall_status, $row->critical_warning);
            $currentSelftestOp = (int) ($row->current_selftest_op ?? 0);
        }

        // Self-test status from DB (current op, else most recent log result).
        $entries = DB::table('smart_nvme_selftest_log')
            ->where('app_id', $this->ctx->appId)
            ->where('disk_key', $diskKey)
            ->get(['result', 'power_on_hours'])
            ->map(static fn ($r) => (array) $r)
            ->all();
        $statusValue = $this->nvmeSelftestStatusValue($currentSelftestOp, $entries);
        if ($statusValue !== null) {
            $values["{$idx}_selftest_status"] = (float) $statusValue;
        }

        // Self-test age (recomputed each poll: grows over time, resets when a test runs).
        // Raw value is hours; updateSensorValues() applies the sensor's stored
        // multiplier (60) to convert to minutes, matching the 'runtime' sensor unit.
        $values += SelftestAge::values($this->ctx, $idx, $diskKey, 'smart_nvme_health', 'smart_nvme_selftest_log');

        if ($values !== []) {
            $this->ctx->updateSensorValues($values, "app:smart_mib:{$idx}_");
        }
    }

    /** Write the per-disk NVMe SMART/Health RRD (['app','smart_nvme',app_id,idx]). */
    private function pollNvmeDeviceRrd(array $dev, array $health): void
    {
        $idx = DiskIdentity::index($dev['disk_key']);

        $rrd_def = RrdDefinition::make();
        $fields = [];
        foreach (self::NVME_HEALTH_RRD as $col => [$ds, $type]) {
            $rrd_def->addDataset($ds, $type, 0, null, self::RRD_HEARTBEAT);
            $value = $col === 'smartmonNvmeCriticalWarning'
                ? SnmpDecode::parseBitsValue($health[$col] ?? null)
                : (int) ($health[$col] ?? null);
            $fields[$ds] = $value;
        }

        $rrd_def->addDataset('power_state', 'GAUGE', 0, 8, self::RRD_HEARTBEAT);
        $fields['power_state'] = (int) ($dev['power_state'] ?? null);

        $rrdName = ['app', 'smart_nvme', $this->ctx->appId, $idx];

        // DS reconciliation (retrofitting power_state onto older files) is a
        // discovery concern, handled by discover()'s inline addDatasetsFromConfig()
        // call; new files get every DS at create time from $rrd_def below. No tune at poll time.
        //
        // NVME_HEALTH_RRD is a fixed set, plus power_state, so $fields always carries every DS.
        app('Datastore')->put($this->ctx->deviceArray, 'app', [
            'name'                => 'smart_nvme',
            'app_id'              => $this->ctx->appId,
            'rrd_def'             => $rrd_def,
            'rrd_name'            => $rrdName,
            'rrd_update_template' => true,
        ], $fields);
    }

    /** Human label for the NVMe current self-test operation enum (0 = none → null). */
    private function nvmeSelfTestOpLabel(?int $op): ?string
    {
        return match ($op) {
            1 => 'Short device self-test in progress',
            2 => 'Extended device self-test in progress',
            14 => 'Vendor-specific self-test in progress',
            null, 0 => null,
            default => 'Self-test in progress',
        };
    }

    /** Resolve a SmartmonHealthStatus value (enum int or "passed(1)") to 0-4. */
    private function healthStatusValue(mixed $raw): ?int
    {
        return $raw === null || $raw === '' ? null : (int) $raw;
    }

    private function syncNvmeInfoRow(array $dev, array $row): void
    {
        DbSync::upsert('smart_nvme_info', [
            'app_id'                         => $this->ctx->appId,
            'device_id'                      => $this->ctx->deviceId,
            'disk_key'                       => $dev['disk_key'],
            'pci_vendor_id'                  => (int) ($row['smartmonNvmePciVendorId'] ?? null),
            'pci_device_id'                  => (int) ($row['smartmonNvmePciVendorSubsystemId'] ?? null),
            'ieee_oui'                       => (int) ($row['smartmonNvmeIeeeOuiIdentifier'] ?? null),
            'total_nvm_capacity_bytes'       => (int) ($row['smartmonNvmeTotalNvmCapacityBytes'] ?? null),
            'unallocated_nvm_capacity_bytes' => (int) ($row['smartmonNvmeUnallocatedNvmCapacityBytes'] ?? null),
            'controller_id'                  => (int) ($row['smartmonNvmeControllerId'] ?? null),
            'nvme_version'                   => $row['smartmonNvmeVersion'] ?? null,
            'namespace_count'                => (int) ($row['smartmonNvmeNamespaceCount'] ?? null),
            'max_data_transfer_pages'        => (int) ($row['smartmonNvmeMaximumDataTransferPages'] ?? null),
            'link_power_state'               => (int) ($row['smartmonNvmeLinkPowerState'] ?? null),
            'max_link_speed'                 => (int) ($row['smartmonNvmeMaxLinkSpeed'] ?? null),
            'max_link_width'                 => (int) ($row['smartmonNvmeMaxLinkWidth'] ?? null),
            'current_link_speed'             => (int) ($row['smartmonNvmeCurrentLinkSpeed'] ?? null),
            'current_link_width'             => (int) ($row['smartmonNvmeCurrentLinkWidth'] ?? null),
        ], ['app_id', 'disk_key']);
    }

    private function syncNvmeHealthRow(array $dev, array $row): void
    {
        // Current self-test: OperationValue is the operation enum (0=none, 1=short,
        // 2=extended, 14=vendor); OperationProgress is the completion percentage.
        $selftestOp = (int) ($row['smartmonNvmeCurrentSelfTestOperationValue'] ?? null);

        DbSync::upsert('smart_nvme_health', [
            'app_id'               => $this->ctx->appId,
            'device_id'            => $this->ctx->deviceId,
            'disk_key'             => $dev['disk_key'],
            'overall_status'       => $this->healthStatusValue($row['smartmonNvmeHealthOverallStatus'] ?? null),
            'critical_warning'     => SnmpDecode::parseBitsValue($row['smartmonNvmeCriticalWarning'] ?? null),
            'data_units_read'      => (int) ($row['smartmonNvmeDataUnitsRead'] ?? null),
            'data_units_written'   => (int) ($row['smartmonNvmeDataUnitsWritten'] ?? null),
            'data_bytes_read'      => (int) ($row['smartmonNvmeDataBytesRead'] ?? null),
            'data_bytes_written'   => (int) ($row['smartmonNvmeDataBytesWritten'] ?? null),
            'host_read_commands'   => (int) ($row['smartmonNvmeHostReadCommands'] ?? null),
            'host_write_commands'  => (int) ($row['smartmonNvmeHostWriteCommands'] ?? null),
            'controller_busy_time' => (int) ($row['smartmonNvmeControllerBusyTimeMinutes'] ?? null),
            'power_cycles'         => (int) ($row['smartmonNvmePowerCycles'] ?? null),
            'power_on_hours'       => (int) ($row['smartmonNvmePowerOnHours'] ?? null),
            'unsafe_shutdowns'     => (int) ($row['smartmonNvmeUnsafeShutdowns'] ?? null),
            'media_errors'         => (int) ($row['smartmonNvmeMediaDataIntegrityErrors'] ?? null),
            'num_err_log_entries'  => (int) ($row['smartmonNvmeErrorInformationLogEntries'] ?? null),
            'warning_temp_time'    => (int) ($row['smartmonNvmeWarningTemperatureTimeMinutes'] ?? null),
            'critical_comp_time'   => (int) ($row['smartmonNvmeCriticalTemperatureTimeMinutes'] ?? null),
            'current_selftest_op'  => $selftestOp,
            'current_selftest_str' => $this->nvmeSelfTestOpLabel($selftestOp),
            'current_selftest_pct' => (int) ($row['smartmonNvmeCurrentSelfTestOperationProgress'] ?? null),
        ], ['app_id', 'disk_key']);
    }

    private function syncNvmeNamespaceRows(array $dev, array $rows): void
    {
        foreach ($rows as $nsId => $row) {
            DbSync::upsert('smart_nvme_namespaces', [
                'app_id'        => $this->ctx->appId,
                'device_id'     => $this->ctx->deviceId,
                'disk_key'      => $dev['disk_key'],
                'ns_id'         => (int) $nsId,
                'nsze'          => (int) ($row['smartmonNvmeNamespaceSizeBlocks'] ?? null),
                'ncap'          => (int) ($row['smartmonNvmeNamespaceCapacityBlocks'] ?? null),
                'nuse'          => (int) ($row['smartmonNvmeNamespaceUtilizationBlocks'] ?? null),
                'lba_data_size' => (int) ($row['smartmonNvmeNamespaceFormattedLbaSizeBytes'] ?? null),
            ], ['app_id', 'disk_key', 'ns_id']);
        }
        DbSync::pruneStaleRows('smart_nvme_namespaces', $this->ctx->appId, $dev['disk_key'], 'ns_id', array_keys($rows));
    }

    private function syncNvmeSelfTestRows(array $dev, array $rows): void
    {
        foreach ($rows as $entryIndex => $row) {
            DbSync::upsert('smart_nvme_selftest_log', [
                'app_id'               => $this->ctx->appId,
                'device_id'            => $this->ctx->deviceId,
                'disk_key'             => $dev['disk_key'],
                'entry_num'            => (int) $entryIndex,
                'test_type'            => (int) ($row['smartmonNvmeSelfTestType'] ?? null),
                'result'               => (int) ($row['smartmonNvmeSelfTestResult'] ?? null),
                'result_text'          => isset($row['smartmonNvmeSelfTestResultText'])
                    ? substr((string) $row['smartmonNvmeSelfTestResultText'], 0, 96) : null,
                'power_on_hours'       => (int) ($row['smartmonNvmeSelfTestPowerOnHours'] ?? null),
                'failing_lba'          => (int) ($row['smartmonNvmeSelfTestFailingLba'] ?? null),
                'nsid'                 => (int) ($row['smartmonNvmeSelfTestNamespaceId'] ?? null),
                'estimated_completion' => SnmpDecode::parseDateAndTime($row['smartmonNvmeSelfTestEstimatedCompletionTime'] ?? null),
            ], ['app_id', 'disk_key', 'entry_num']);
        }
        DbSync::pruneStaleRows('smart_nvme_selftest_log', $this->ctx->appId, $dev['disk_key'], 'entry_num', array_keys($rows));
    }

    private function syncNvmePowerStateRows(array $dev, array $rows): void
    {
        foreach ($rows as $stateId => $row) {
            DbSync::upsert('smart_nvme_power_states', [
                'app_id'                => $this->ctx->appId,
                'device_id'             => $this->ctx->deviceId,
                'disk_key'              => $dev['disk_key'],
                'state_id'              => (int) $stateId,
                'operational'           => SmartSnmpDecode::snmpTruthValue($row['smartmonNvmePowerStateOperational'] ?? null),
                'max_power_mw'          => (int) ($row['smartmonNvmePowerStateMaxPowerMilliWatts'] ?? null),
                'active_power_mw'       => (int) ($row['smartmonNvmePowerStateActivePowerMilliWatts'] ?? null),
                'idle_power_mw'         => (int) ($row['smartmonNvmePowerStateIdlePowerMilliWatts'] ?? null),
                'read_latency_rank'     => (int) ($row['smartmonNvmePowerStateReadLatencyRank'] ?? null),
                'read_throughput_rank'  => (int) ($row['smartmonNvmePowerStateReadThroughputRank'] ?? null),
                'write_latency_rank'    => (int) ($row['smartmonNvmePowerStateWriteLatencyRank'] ?? null),
                'write_throughput_rank' => (int) ($row['smartmonNvmePowerStateWriteThroughputRank'] ?? null),
                'entry_latency_us'      => (int) ($row['smartmonNvmePowerStateEntryLatencyUsec'] ?? null),
                'exit_latency_us'       => (int) ($row['smartmonNvmePowerStateExitLatencyUsec'] ?? null),
            ], ['app_id', 'disk_key', 'state_id']);
        }
        DbSync::pruneStaleRows('smart_nvme_power_states', $this->ctx->appId, $dev['disk_key'], 'state_id', array_keys($rows));
    }

    /** LBA formats are indexed per namespace: $nsFormats = [nsId => [formatId => row]]. */
    private function syncNvmeLbaFormatRows(array $dev, array $nsFormats): void
    {
        foreach ($nsFormats as $nsId => $formats) {
            if (! is_array($formats)) {
                continue;
            }
            foreach ($formats as $formatId => $row) {
                DbSync::upsert('smart_nvme_lba_formats', [
                    'app_id'               => $this->ctx->appId,
                    'device_id'            => $this->ctx->deviceId,
                    'disk_key'             => $dev['disk_key'],
                    'ns_id'                => (int) $nsId,
                    'format_id'            => (int) $formatId,
                    'current'              => SmartSnmpDecode::snmpTruthValue($row['smartmonNvmeLbaFormatCurrent'] ?? null),
                    'data_size_bytes'      => (int) ($row['smartmonNvmeLbaFormatDataSizeBytes'] ?? null),
                    'metadata_size_bytes'  => (int) ($row['smartmonNvmeLbaFormatMetadataSizeBytes'] ?? null),
                    'relative_performance' => (int) ($row['smartmonNvmeLbaFormatRelativePerformance'] ?? null),
                ], ['app_id', 'disk_key', 'ns_id', 'format_id']);
            }
            DbSync::pruneStaleRows('smart_nvme_lba_formats', $this->ctx->appId, $dev['disk_key'], 'format_id', array_keys($formats), ['ns_id' => (int) $nsId]);
        }
        DbSync::pruneStaleRows('smart_nvme_lba_formats', $this->ctx->appId, $dev['disk_key'], 'ns_id', array_keys($nsFormats));
    }

    private function syncNvmeErrorLogRows(array $dev, array $rows): void
    {
        foreach ($rows as $entryIndex => $row) {
            DbSync::upsert('smart_nvme_error_log', [
                'app_id'               => $this->ctx->appId,
                'device_id'            => $this->ctx->deviceId,
                'disk_key'             => $dev['disk_key'],
                'entry_num'            => (int) $entryIndex,
                'error_count'          => (int) ($row['smartmonNvmeErrorCount'] ?? null),
                'sq_id'                => (int) ($row['smartmonNvmeErrorSubmissionQueueId'] ?? null),
                'command_id'           => (int) ($row['smartmonNvmeErrorCommandId'] ?? null),
                'status_field'         => (int) ($row['smartmonNvmeErrorStatusField'] ?? null),
                'param_error_location' => (int) ($row['smartmonNvmeErrorParameterErrorLocation'] ?? null),
                'lba'                  => (int) ($row['smartmonNvmeErrorLba'] ?? null),
                'ns_id'                => (int) ($row['smartmonNvmeErrorNamespaceId'] ?? null),
                'vendor_info'          => (int) ($row['smartmonNvmeErrorVendorSpecificInfo'] ?? null),
                'status_code'          => (int) ($row['smartmonNvmeErrorStatusCode'] ?? null),
                'status_code_type'     => (int) ($row['smartmonNvmeErrorStatusCodeType'] ?? null),
                'do_not_retry'         => SmartSnmpDecode::snmpTruthValue($row['smartmonNvmeErrorDoNotRetry'] ?? null),
                'status_string'        => isset($row['smartmonNvmeErrorStatusString'])
                    ? substr((string) $row['smartmonNvmeErrorStatusString'], 0, 128) : null,
                'error_time'           => SnmpDecode::parseDateAndTime($row['smartmonNvmeErrorTimestamp'] ?? null),
            ], ['app_id', 'disk_key', 'entry_num']);
        }
        DbSync::pruneStaleRows('smart_nvme_error_log', $this->ctx->appId, $dev['disk_key'], 'entry_num', array_keys($rows));
    }

    private function syncNvmeCapabilityRow(array $dev, array $row): void
    {
        DbSync::upsert('smart_nvme_capability', [
            'app_id'                  => $this->ctx->appId,
            'device_id'               => $this->ctx->deviceId,
            'disk_key'                => $dev['disk_key'],
            'firmware_update_raw'     => (int) ($row['smartmonNvmeFirmwareUpdateRaw'] ?? null),
            'firmware_slot_count'     => (int) ($row['smartmonNvmeFirmwareSlotCount'] ?? null),
            'firmware_reset_required' => SmartSnmpDecode::snmpTruthValue($row['smartmonNvmeFirmwareResetRequired'] ?? null),
            'optional_admin_cmd_raw'  => SmartSnmpDecode::bitsValue($row['smartmonNvmeOptionalAdminCommandRaw'] ?? null),
            'optional_nvm_cmd_raw'    => SmartSnmpDecode::bitsValue($row['smartmonNvmeOptionalNvmCommandRaw'] ?? null),
            'log_page_attrs_raw'      => SmartSnmpDecode::bitsValue($row['smartmonNvmeLogPageAttributesRaw'] ?? null),
            'optional_admin_cmd_text' => isset($row['smartmonNvmeOptionalAdminCommandText'])
                ? substr((string) $row['smartmonNvmeOptionalAdminCommandText'], 0, 255) : null,
            'optional_nvm_cmd_text'   => isset($row['smartmonNvmeOptionalNvmCommandText'])
                ? substr((string) $row['smartmonNvmeOptionalNvmCommandText'], 0, 255) : null,
            'log_page_attrs_text'     => isset($row['smartmonNvmeLogPageAttributesText'])
                ? substr((string) $row['smartmonNvmeLogPageAttributesText'], 0, 255) : null,
        ], ['app_id', 'disk_key']);
    }

    private function walkNvmeTable(string $table, int $group): array
    {
        return SnmpQuery::mibs(self::NVME_MIBS)->mibDir('smart')->hideMib()
            ->walk("SMARTMON-NVME-MIB::$table")->table($group);
    }

    /** First sub-row from a table(2) device entry ([subIdx => [col => val]]), or null. */
    private function firstSubRow(mixed $entry): ?array
    {
        if (! is_array($entry)) {
            return null;
        }
        foreach ($entry as $row) {
            if (is_array($row)) {
                return $row;
            }
        }

        return null;
    }

    /** Keep only the array sub-rows of a table(2) device entry, preserving sub-index keys. */
    private function subRows(mixed $entry): array
    {
        if (! is_array($entry)) {
            return [];
        }

        return array_filter($entry, 'is_array');
    }
}
