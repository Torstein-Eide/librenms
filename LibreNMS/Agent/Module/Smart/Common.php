<?php

namespace LibreNMS\Agent\Module\Smart;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\Models\Sensor;
use App\Models\StateTranslation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LibreNMS\Agent\Application;
use LibreNMS\Agent\Module\Smart\Helpers\DiskIdentity;
use LibreNMS\Data\Store\Rrd;
use LibreNMS\Enum\Severity;
use LibreNMS\RRD\RrdDefinition;
use LibreNMS\Util\Debug;
use SnmpQuery;

/**
 * SMART application dispatcher.
 *
 * Reads the payload version and delegates to the correct handler:
 *   MIB   →  SNMP SMARTMON-*-MIB path (this class)
 *   V1    →  SmartV1 (legacy CSV or v1 JSON, raw RRD only)
 */
class Common extends Application
{
    private const COMMON_MIBS = ['SMARTMON-TC-MIB', 'SMARTMON-COMMON-MIB'];
    private const SATA_MIBS = ['SMARTMON-TC-MIB', 'SMARTMON-COMMON-MIB', 'SMARTMON-SATA-MIB'];
    private const SENSOR_MIBS = ['SMARTMON-TC-MIB', 'SMARTMON-COMMON-MIB', 'SMARTMON-SENSOR-MIB'];

    // smartSATAChangeByDeviceTable IDs used for change-detection guards (matches
    // sata_table_meta_for() in the agentx). The full ID space, including the
    // tables that aren't change-gated (info=1, attr=3, errorCmd=5), is in
    // SATA_TID_NAMES below; only the IDs referenced as change guards are named here.
    private const SATA_TID_HEALTH = 2;
    private const SATA_TID_ERROR_LOG = 4;
    private const SATA_TID_SELFTEST = 6;
    private const SATA_TID_ERC = 7;
    private const SATA_TID_PHY_EVENT = 8;
    private const SATA_TID_SELECTIVE_TEST = 9;
    private const SATA_TID_LOG_DIR = 10;
    private const SATA_TID_DEV_STAT = 11;
    private const SATA_TID_PENDING_DEFECTS = 12;

    // SNMP returns enumerated table IDs as named strings (e.g. "sataInfo") when MIBs are loaded.
    // Map them to the integer constants so change-detection lookups work correctly.
    private const SATA_TID_NAMES = [
        'sataInfo'           => 1,
        'sataHealth'         => 2,
        'sataAttr'           => 3,
        'sataErrorLog'       => 4,
        'sataErrorCmd'       => 5,
        'sataSelfTest'       => 6,
        'sataErc'            => 7,
        'sataPhyEvent'       => 8,
        'sataSelectiveTest'  => 9,
        'sataLogDir'         => 10,
        'sataDevStat'        => 11,
        'sataPendingDefects' => 12,
    ];

    /**
     * SmartmonSensorDataType → [LibreNMS sensor_class, sensor_type, index prefix]
     * Types other(1), unknown(2), vibration(8) have no useful LibreNMS mapping and are skipped.
     */
    private const SENSOR_TYPE_MAP = [
        3  => ['temperature', 'smart_mib_temperature', 'temp'],
        4  => ['power',       'smart_mib_power',       'pwr'],
        5  => ['current',     'smart_mib_current',     'amp'],
        6  => ['voltage',     'smart_mib_voltage',     'vdc'],
        7  => ['voltage',     'smart_mib_voltage',     'vac'],
        9  => ['fanspeed',    'smart_mib_fanspeed',    'rpm'],
        10 => ['percent',     'smart_mib_percent',     'pct'],
    ];

    // SmartmonSensorDataScale enum → power of 10 exponent
    private const SENSOR_SCALE_EXP = [
        1 => -24, 2 => -21, 3 => -18, 4 => -15, 5 => -12,
        6 => -9,  7 => -6,  8 => -3,  9 => 0,   10 => 3,
        11 => 6,  12 => 9,  13 => 12, 14 => 15,
    ];

    // SATA device types: ata=1, sat=2
    private const SATA_TYPES = [1, 2];

    // NVMe device type: nvme=5
    private const NVME_TYPES = [5];

    private const NVME_MIBS = ['SMARTMON-TC-MIB', 'SMARTMON-COMMON-MIB', 'SMARTMON-NVME-MIB'];

    // NVMe SMART/Health columns written to the per-disk smart_nvme RRD: MIB column => [DS name, type].
    // DS names + types MUST match the V1 nvmeDsMap (smart.php) and smart_v2_nvme.inc.php graph,
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

    // SmartmonHealthStatus enum: unknown(0), passed(1), failed(2), warning(3), unavailable(4).
    private const HEALTH_STATUS_NAMES = [
        'unknown' => 0, 'passed' => 1, 'failed' => 2, 'warning' => 3, 'unavailable' => 4,
    ];

    // ATA attributes whose raw DS is COUNTER: [id => smartmontools name].
    // Discovery checks by name (more reliable; ID 251 varies by vendor).
    // Polling falls back to the ID key when rrd_type is not yet stored in DB.
    private const ATA_COUNTER_ATTRS = [
        179 => 'Used_Rsvd_Blk_Cnt_Tot',
        180 => 'Unused_Rsvd_Blk_Cnt_Tot',
        241 => 'Total_LBAs_Written',
        242 => 'Total_LBAs_Read',
        245 => 'Timed_Workld_Media_Wear',
        246 => 'Timed_Workld_RdWr_Ratio',
        247 => 'Timed_Workld_Timer',
        251 => 'NAND_Writes',
    ];

    // Rate-of-change lookback windows (column suffix => seconds).
    private const RATE_WINDOWS = [
        '8h' => 28800,
        '24h' => 86400,
        '168h' => 604800,
        '672h' => 2419200,
    ];

    private const HANDLER_MIB = 'mib'; // SMARTMON-*-MIB
    private const HANDLER_V1 = 'v1';  // Json

    // V1 RRD datasets that have no equivalent in V2 and should be discarded on migration.
    // V1 stored these as self-test pass/fail counters; V2 handles self-test via the log table.
    private const V1_SATA_DISCARD_DS = [
        'completed', 'interrupted', 'readfailure', 'unknownfail',
        'extended', 'short', 'conveyance', 'selective',
    ];

    // Per-disk child tables keyed by (app_id, disk_key). Pruned alongside
    // smart_devices when a drive disappears. smart_sata_change is keyed by
    // device_idx (snmp_index) instead and handled separately.
    private const DEVICE_CHILD_TABLES = [
        'smart_sata_info', 'smart_sata_health', 'smart_sata_attributes',
        'smart_sata_selftest_log', 'smart_sata_error_log', 'smart_sata_error_cmd',
        'smart_sata_erc', 'smart_sata_phy_events', 'smart_sata_selective_test',
        'smart_sata_log_dir', 'smart_sata_dev_stats', 'smart_sata_pending_defects',
        'smart_nvme_info', 'smart_nvme_health', 'smart_nvme_namespaces', 'smart_nvme_selftest_log',
        'smart_sas_info', 'smart_sas_health', 'smart_sas_error_counters', 'smart_sas_selftest_log',
    ];

    private ?array $commonDevices = null;
    private ?array $sataChangeRows = null;
    private ?array $sataSubindexChangeRows = null;
    private ?array $prevSataChange = null;
    private array  $sataHealth = [];
    private array  $sataAttributes = [];
    private array  $sensorRows = [];

    // Stable per-run identity context, initialized at the top of discover()/poll().
    private int     $appId;
    private int     $deviceId;
    private Device  $device;
    // SATA / NVMe device lists for the current run, keyed by snmp_index.
    private array   $sataDeviceList = [];
    private array   $nvmeDeviceList = [];

    // ── Public interface ──────────────────────────────────────────────────────

    public function shouldDiscover(): bool
    {
        $rowCount = $this->intValue(
            SnmpQuery::mibs(self::COMMON_MIBS)->hideMib()
                ->get('SMARTMON-COMMON-MIB::smartmonDeviceTableRowCount.0')
                ->value('smartmonDeviceTableRowCount.0')
        );

        $this->vlog("shouldDiscover: MIB rowCount={$rowCount}");

        if ($rowCount > 0) {
            return true;
        }

        // The device-table OID is empty or unresponsive. Still run discovery when
        // the DB holds devices for this app so the cleanup pass can remove the now
        // stale device rows, child rows, and sensors.
        $hasRows = DB::table('smart_devices')
            ->where('app_id', $this->app->app_id)
            ->exists();
        $this->vlog('shouldDiscover: OID empty/unresponsive, DB ' . ($hasRows ? 'non-empty, running for cleanup' : 'empty, skipping'));

        return $hasRows;
    }

    public function discover(): void
    {
        $this->initContext();

        $handler = $this->detectAndPersistHandler();
        $this->vlog("discover: handler={$handler}");

        if ($handler !== self::HANDLER_MIB) {
            $this->vlog('discover: non-MIB handler, skipping MIB discovery');

            return;
        }

        $this->discoverMib();
    }

    public function shouldPoll(): bool
    {
        // Runs before initContext(); use the raw accessor.
        return DB::table('smart_devices')
            ->where('app_id', $this->app->app_id)
            ->exists();
    }

    public function poll(): void
    {
        $this->initContext();

        $this->pollCommon();
        $this->pollSata();
        $this->pollNvme();
        // $this->pollSAS();  // future

        // SENSOR-MIB values (temperature, NVMe spare/used) for every polled device.
        $this->pollSensorValues();
    }

    /**
     * Remove every DB row owned by this app when the application is deleted or
     * its handler changes. Runs before initContext(), so read app_id directly.
     * Returns the sensor count deleted by the parent (the documented contract).
     */
    public function cleanup(): int
    {
        $appId = $this->app->app_id;

        foreach ([...self::DEVICE_CHILD_TABLES, 'smart_devices', 'smart_sata_change', 'smart_app_state'] as $table) {
            DB::table($table)->where('app_id', $appId)->delete();
        }

        return parent::cleanup();
    }

    /** Cache the stable identity context used throughout discovery and polling. */
    private function initContext(): void
    {
        $this->appId = $this->app->app_id;
        $this->deviceId = $this->os->getDeviceId();
        $this->device = $this->os->getDevice();
    }

    // ── Discovery ─────────────────────────────────────────────────────────────

    private function discoverMib(): void
    {
        $this->vlog('discoverMib: starting MIB discovery');
        app()->forgetInstance('sensor-discovery');

        // One-shot V1→V2 RRD migration (no-op once all devices are marked done).
        $this->migrateV1Rrds();

        // Retrofit any DS missing from pre-existing RRD files (idempotent; no-op once present).
        $this->reconcileCommonDeviceRrds();

        // SENSOR-MIB is common to all device types; walk once before type discovery.
        $this->sensorTable();
        $this->vlog('discoverMib: sensorTable has ' . count($this->sensorRows) . ' device entry/entries');

        // Type: SATA
        $this->discoverSata();

        // Type: NVMe
        $this->discoverNvme();

        // Type: SAS. Not yet implemented.
        // $this->discoverSas();

        // SENSOR-MIB sensors: register for every discovered device.
        $device = $this->device;
        $group = 'SMART';
        // Source-defined limits per sensor_oid. LibreNMS guesses limits for some
        // classes (e.g. temperature low = current - 10) when a column is null, so
        // after sync we force these back to exactly what the source reported.
        $intendedLimits = [];
        $this->vlog('discoverMib: registering SENSOR-MIB sensors for ' . count($this->commonDevices) . ' device(s)');
        foreach ($this->commonDevices as $devIdx => $dev) {
            $diskKey = $dev['disk_key'];
            $idx = $this->mibDiskIndex($diskKey);
            $devName = $this->sensorLabel($dev, (string) $devIdx);
            foreach ($this->sensorRows[$devIdx] ?? [] as $sensorIdx => $row) {
                // smartmonSensorType is an enum returned as a name ("celsius(3)") when MIBs load.
                $type = $this->intValue($row['smartmonSensorType'] ?? null);
                $value = $this->applySensorScaleCol($row, 'smartmonSensorValue');
                if ($value === null) {
                    $this->vlog("discoverMib sensor: devIdx={$devIdx} sub-index={$sensorIdx} type=" . var_export($type, true) . ' has null value, skipped');
                    continue;
                }
                $meta = self::SENSOR_TYPE_MAP[$type] ?? null;
                if ($meta === null) {
                    $this->vlog("discoverMib sensor: devIdx={$devIdx} sub-index={$sensorIdx} type=" . var_export($type, true) . ' has no SENSOR_TYPE_MAP entry, skipped');
                    continue;
                }
                $this->vlog("discoverMib sensor: devIdx={$devIdx} sub-index={$sensorIdx} type={$type} ({$meta[0]}) value={$value}");
                [$sensorClass, $sensorType, $prefix] = $meta;
                $name = trim((string) ($row['smartmonSensorName'] ?? ''));
                $sIdx = "{$idx}_{$prefix}_{$sensorIdx}";
                $descr = $name !== '' ? "{$group} {$devName} {$name}" : "{$group} {$devName}";
                $highCrit = $this->applySensorScaleCol($row, 'smartmonSensorHighCritical');
                $highWarn = $this->applySensorScaleCol($row, 'smartmonSensorHighWarning');
                $lowWarn = $this->applySensorScaleCol($row, 'smartmonSensorLowWarning');
                $lowCrit = $this->applySensorScaleCol($row, 'smartmonSensorLowCritical');
                // A warning threshold equal to critical gives no early notice; nudge it
                // one notch less severe so "warning" fires before "critical".
                if ($highCrit !== null && $highWarn !== null && $highWarn == $highCrit) {
                    $highWarn = $highCrit - 5;
                }
                if ($lowCrit !== null && $lowWarn !== null && $lowWarn == $lowCrit) {
                    $lowWarn = $lowCrit + 5;
                }
                // Carry the scale as divisor/multiplier so the poll can rescale the raw value.
                $scale = $this->sensorScaleColumns($row);
                $intendedLimits["app:smart_mib:{$sIdx}"] = [
                    'sensor_limit'          => $highCrit,
                    'sensor_limit_warn'     => $highWarn,
                    'sensor_limit_low_warn' => $lowWarn,
                    'sensor_limit_low'      => $lowCrit,
                ];
                $this->discoverSensor(
                    class: $sensorClass,
                    type: $sensorType,
                    index: $sIdx,
                    oid: "app:smart_mib:{$sIdx}",
                    descr: $descr,
                    current: $value,
                    group: $group,
                    divisor: $scale['sensor_divisor'],
                    multiplier: $scale['sensor_multiplier'],
                    lowLimit: $lowCrit,
                    lowWarnLimit: $lowWarn,
                    warnLimit: $highWarn,
                    highLimit: $highCrit,
                );
            }
        }

        // Persist the generic SENSOR-MIB sensors. This must run after the
        // registration loop above: sync() only writes the sensors queued so far,
        // so syncing these types earlier (e.g. in discoverSata) would drop them.
        $this->syncMibSensorTypes();
        $this->correctGuessedSensorLimits($intendedLimits);

        $this->cleanupStaleMibSensors();
        $this->cleanupStaleDevices();
    }

    /**
     * Discover all SATA tables: for each table, walk once, then process per device.
     */
    private function discoverSata(): void
    {
        // Change index must be loaded first so all table-change guards below are valid.
        $this->sataChangeByDeviceTable();
        $this->sataDeviceList = $this->sataDevices();
        $this->vlog('discoverSata: ' . count($this->sataDeviceList) . ' SATA device(s) found');

        // Info table: sync unconditionally. Static identity data is not tracked in the change table.
        $this->walkAndSyncSataTable('smartmonSataInfoTable', 1, null, [$this, 'syncSataInfoRow']);

        // Tables needed for sensor discovery (always fetched).
        $this->sataAttributeTable();
        $this->sataHealthTable();

        // For each SATA device: register SATA-specific sensors and sync health + attributes to DB.
        foreach ($this->sataDeviceList as $devIdx => $dev) {
            $this->vlog("discoverSata: device idx={$devIdx} disk_key={$dev['disk_key']}");
            $this->discoverSataDeviceSensors(
                $dev,
                $this->sataHealth[$devIdx] ?? [],
                $this->sataAttributes[$devIdx] ?? []
            );
            if (isset($this->sataHealth[$devIdx])) {
                $this->syncSataHealthRow($dev, $this->sataHealth[$devIdx]);
            }
            if (isset($this->sataAttributes[$devIdx])) {
                $this->syncSataAttributeRows($dev, $this->sataAttributes[$devIdx]);
                $this->syncSataAttributeRates($dev, $this->sataAttributes[$devIdx]);
                $this->reconcileSataAttributeRrds($dev, $this->sataAttributes[$devIdx]);
            }
        }

        // Change-guarded tables (per device):
        $this->walkAndSyncSataTable('smartmonSataErcTable', 2, self::SATA_TID_ERC, [$this, 'syncSataErcRows']);
        $this->walkAndSyncSataTable('smartmonSataPhyEventTable', 2, self::SATA_TID_PHY_EVENT, [$this, 'syncSataPhyEventRows']);
        $this->walkAndSyncSataTable('smartmonSataErrorLogTable', 2, self::SATA_TID_ERROR_LOG, [$this, 'syncSataErrorLogRows']);
        $this->walkAndSyncSataTable('smartmonSataErrorCmdTable', 3, self::SATA_TID_ERROR_LOG, [$this, 'syncSataErrorCmdRows']);
        $this->walkAndSyncSataTable('smartmonSataSelfTestTable', 2, self::SATA_TID_SELFTEST, [$this, 'syncSataSelfTestRows']);
        $this->walkAndSyncSataTable('smartmonSataSelectiveTestTable', 2, self::SATA_TID_SELECTIVE_TEST, [$this, 'syncSataSelectiveTestRows']);
        $this->walkAndSyncSataTable('smartmonSataLogDirTable', 2, self::SATA_TID_LOG_DIR, [$this, 'syncSataLogDirRows']);
        $this->walkAndSyncSataTable('smartmonSataDevStatTable', 3, self::SATA_TID_DEV_STAT, [$this, 'syncSataDevStatRows'], true);

        // Self-test age sensors, computed from the freshly-synced self-test log + power-on hours.
        $this->discoverSelftestAgeSensors($this->sataDeviceList, 'smart_selftest_', 'smart_sata_health', 'smart_sata_selftest_log');

        // Register all sensor types with the discovery system.
        $this->syncSensorTypes();

        // Persist change snapshot for the next cycle's change detection.
        $this->persistSataChangeSnapshot();
    }

    /**
     * Register LibreNMS sensors for one SATA device.
     * Called once per device with pre-fetched table data.
     */
    private function discoverSataDeviceSensors(
        array $dev,
        array $health,
        array $attrRows
    ): void {
        $device = $this->device;
        $diskKey = $dev['disk_key'];
        $devName = $this->sensorLabel($dev, $dev['snmp_index']);
        $idx = $this->mibDiskIndex($diskKey);
        $group = 'SMART';

        // Health: synthesised from overall status + attribute statuses
        if (isset($health['smartmonSataHealthOverallStatus'])) {
            $synthesized = $this->synthesizeHealthStatus($health, $attrRows, $diskKey);
            $this->discoverSensor(
                class: 'state',
                type: 'smart_mib_health',
                index: "{$idx}_health",
                oid: "app:smart_mib:{$idx}_health",
                descr: "{$group} {$devName} Health",
                current: $synthesized,
                group: $group,
            )
                ->withStateTranslations('smart_mib_health', [
                    StateTranslation::define('OK', 1, Severity::Ok),
                    StateTranslation::define('Warning', 2, Severity::Warning),
                    StateTranslation::define('Warning: Attr Failed', 3, Severity::Warning),
                    StateTranslation::define('Warning: Attr Rate', 4, Severity::Warning),
                    StateTranslation::define('Error: Attr Failing', 5, Severity::Error),
                    StateTranslation::define('Unavailable', 6, Severity::Warning),
                ]);
        }

        // Self-test execution status (MIB returns the decoded nibble directly)
        $statusRaw = $health['smartmonSataSelfTestExecutionStatusValue'] ?? null;
        if ($statusRaw !== null) {
            $statusNibble = (int) $statusRaw;
            $this->discoverSensor(
                class: 'state',
                type: 'smart_selftest_status',
                index: "{$idx}_selftest_status",
                oid: "app:smart_mib:{$idx}_selftest_status",
                descr: "{$group} {$devName} Self-test Status",
                current: $statusNibble,
                group: $group,
            )
                ->withStateTranslations('smart_selftest_status', [
                    StateTranslation::define('Completed without error', 0x0, Severity::Ok),
                    StateTranslation::define('Aborted by host', 0x1, Severity::Ok),
                    StateTranslation::define('Interrupted (host reset)', 0x2, Severity::Ok),
                    StateTranslation::define('Fatal or unknown error', 0x3, Severity::Warning),
                    StateTranslation::define('Completed: unknown failure', 0x4, Severity::Warning),
                    StateTranslation::define('Completed: electrical fail', 0x5, Severity::Warning),
                    StateTranslation::define('Completed: servo failure', 0x6, Severity::Warning),
                    StateTranslation::define('Completed: read failure', 0x7, Severity::Warning),
                    StateTranslation::define('Completed: handling damage', 0x8, Severity::Warning),
                    StateTranslation::define('Self-test in progress', 0xf, Severity::Ok),
                ]);
        }
    }

    /**
     * Hours elapsed since the most recent self-test of the given type
     * (1 = short, 2 = extended/long), computed from the synced DB rows.
     * Returns null when power-on hours or a matching self-test entry is unknown.
     */
    private function selftestAgeHours(string $healthTable, string $logTable, string $diskKey, int $testType): ?int
    {
        $currentPoh = DB::table($healthTable)
            ->where('app_id', $this->appId)
            ->where('disk_key', $diskKey)
            ->value('power_on_hours');
        if ($currentPoh === null) {
            return null;
        }

        $lastTestPoh = DB::table($logTable)
            ->where('app_id', $this->appId)
            ->where('disk_key', $diskKey)
            ->where('test_type', $testType)
            ->max('power_on_hours');
        if ($lastTestPoh === null) {
            return null;
        }

        return max(0, (int) $currentPoh - (int) $lastTestPoh);
    }

    /**
     * Register the "Last Short/Long Test" age sensors (runtime class) for each
     * device in $deviceList. Runs after the self-test log table has been
     * synced so the age is computed from the current cycle's data. Only
     * creates a sensor when a matching log entry with power-on hours exists.
     * Not all devices (especially NVMe) implement the self-test log.
     *
     * @param  array<int|string, array{disk_key: string, snmp_index: string}>  $deviceList
     */
    private function discoverSelftestAgeSensors(array $deviceList, string $sensorTypePrefix, string $healthTable, string $logTable): void
    {
        $group = 'SMART';
        foreach ($deviceList as $dev) {
            $diskKey = $dev['disk_key'];
            $idx = $this->mibDiskIndex($diskKey);
            $devName = $this->sensorLabel($dev, $dev['snmp_index']);

            foreach ([
                ['short', 1, 'Last Short SelfTest', 12000, 16000],
                ['long',  2, 'Last Long SelfTest',  57600, 60000],
            ] as [$suffix, $testType, $label, $warn, $max]) {
                $age = $this->selftestAgeHours($healthTable, $logTable, $diskKey, $testType);
                if ($age === null) {
                    continue;
                }
                $this->discoverSensor(
                    class: 'runtime',
                    type: "{$sensorTypePrefix}{$suffix}",
                    index: "{$idx}_selftest_{$suffix}",
                    oid: "app:smart_mib:{$idx}_selftest_{$suffix}",
                    descr: "{$group} {$devName} {$label}",
                    current: (float) $age * 60,
                    group: $group,
                    multiplier: 60,
                    warnLimit: $warn,
                    highLimit: $max,
                );
            }
        }
    }

    /**
     * Sync the SATA state sensor types (registered in discoverSataDeviceSensors,
     * which runs before this call). The generic SENSOR-MIB types are synced
     * separately in syncMibSensorTypes() after their registration loop.
     */
    private function syncSensorTypes(): void
    {
        foreach (['smart_mib_health', 'smart_selftest_status', 'smart_selftest_short', 'smart_selftest_long'] as $type) {
            app('sensor-discovery')->sync(sensor_type: $type);
        }
    }

    /** Sync the generic SENSOR-MIB sensor types (temperature, percent, …). */
    private function syncMibSensorTypes(): void
    {
        foreach (array_unique(array_column(self::SENSOR_TYPE_MAP, 1)) as $type) {
            app('sensor-discovery')->sync(sensor_type: $type);
        }
    }

    /**
     * Reset each generic SENSOR-MIB sensor's limit columns to exactly the values
     * the source reported (null where it defined none).
     *
     * The sensor "creating" observer runs guessLimits() when sensors.guess_limits
     * is enabled (the default), which fabricates a low limit (current - 10 for
     * temperature) whenever the source left one unset. We write the source values
     * directly via the query builder so the observer does not re-guess; later
     * polls keep the value (guessing only happens on create), so this is stable.
     *
     * @param array<string, array<string, float|null>> $intendedLimits  sensor_oid => limit columns
     */
    private function correctGuessedSensorLimits(array $intendedLimits): void
    {
        foreach ($intendedLimits as $oid => $limits) {
            DB::table('sensors')
                ->where('device_id', $this->device['device_id'])
                ->where('sensor_oid', $oid)
                ->where('sensor_custom', 'No') // never override user-customized limits
                ->update($limits);
        }
    }

    /** Remove sensors belonging to drives that no longer appear in the device table. */
    private function cleanupStaleMibSensors(): void
    {
        $device = $this->device;
        $expected = [];
        foreach ($this->commonDevices ?? [] as $snmpIndex => $dev) {
            $idx = $this->mibDiskIndex($dev['disk_key']);

            // Generic SENSOR-MIB sensors (temperature, NVMe spare/used). Applies to all device types.
            foreach ($this->sensorRows[$snmpIndex] ?? [] as $sensorIdx => $row) {
                $type = $this->intValue($row['smartmonSensorType'] ?? null);
                $meta = self::SENSOR_TYPE_MAP[$type] ?? null;
                if ($meta !== null) {
                    $expected[] = "app:smart_mib:{$idx}_{$meta[2]}_{$sensorIdx}";
                }
            }

            // Per-type state sensors. SATA and NVMe register the same set of
            // health + self-test sensors, so the expected OIDs are identical.
            $deviceType = $dev['device_type'] ?? 0;
            if (in_array($deviceType, [...self::SATA_TYPES, ...self::NVME_TYPES], true)) {
                $expected[] = "app:smart_mib:{$idx}_health";
                $expected[] = "app:smart_mib:{$idx}_selftest_status";
                $expected[] = "app:smart_mib:{$idx}_selftest_short";
                $expected[] = "app:smart_mib:{$idx}_selftest_long";
            }
        }

        $deleted = Sensor::where('device_id', $device['device_id'])
            ->where('sensor_oid', 'like', 'app:smart_mib:%')
            ->whereNotIn('sensor_oid', $expected)
            ->delete();

        if ($deleted > 0) {
            echo PHP_EOL . "smart_mib: removed {$deleted} stale sensor(s)" . PHP_EOL;
        }
    }

    /**
     * Remove DB rows for drives that no longer appear in the device table.
     *
     * Covers two cases with one pass:
     *  - a single drive removed while SNMP is healthy (prune by disk_key), and
     *  - the device-table OID gone empty/unresponsive (commonDevices is empty,
     *    so every row for this app is deleted; this is the full-wipe path).
     */
    private function cleanupStaleDevices(): void
    {
        $keepKeys = array_values(array_map(
            static fn ($dev) => $dev['disk_key'],
            $this->commonDevices ?? []
        ));
        $keepIdx = array_map('intval', array_keys($this->commonDevices ?? []));

        $totalDeleted = 0;

        // Disk-keyed child tables + the device table itself.
        foreach ([...self::DEVICE_CHILD_TABLES, 'smart_devices'] as $table) {
            $query = DB::table($table)->where('app_id', $this->appId);
            if ($keepKeys !== []) {
                $query->whereNotIn('disk_key', $keepKeys);
            }
            $totalDeleted += $query->delete();
        }

        // smart_sata_change is keyed by device_idx (snmp_index), not disk_key.
        $changeQuery = DB::table('smart_sata_change')->where('app_id', $this->appId);
        if ($keepIdx !== []) {
            $changeQuery->whereNotIn('device_idx', $keepIdx);
        }
        $totalDeleted += $changeQuery->delete();

        if ($totalDeleted > 0) {
            echo PHP_EOL . "smart_mib: removed {$totalDeleted} stale device row(s)" . PHP_EOL;
        }
    }

    // ── Polling ───────────────────────────────────────────────────────────────

    /** Update common application counters. */
    private function pollCommon(): void
    {
        $rows = SnmpQuery::mibs(self::COMMON_MIBS)->hideMib()->walk([
            'SMARTMON-COMMON-MIB::smartmonDeviceLastPollResult',
            'SMARTMON-COMMON-MIB::smartmonDeviceLastPollTime',
            'SMARTMON-COMMON-MIB::smartmonDevicePowerState',
        ])->table(1);

        foreach ($rows as $snmpIndex => $row) {
            DB::table('smart_devices')
                ->where('app_id', $this->appId)
                ->where('snmp_index', (int) $snmpIndex)
                ->update([
                    'last_poll_result' => $this->intValue($row['smartmonDeviceLastPollResult'] ?? null),
                    'last_poll_time'   => $this->parseDateAndTime($row['smartmonDeviceLastPollTime'] ?? null),
                    'power_state'      => $this->intValue($row['smartmonDevicePowerState'] ?? null),
                ]);
        }
    }

    /**
     * Poll all SATA tables: for each table walk once, then update per device.
     */
    private function pollSata(): void
    {
        $this->sataDeviceList = $this->sataDevicesFromDb();

        // Table: Health (change-guarded; DB sync; sensors updated below)
        $this->walkAndSyncSataTable('smartmonSataHealthTable', 1, self::SATA_TID_HEALTH, [$this, 'syncSataHealthRow']);

        // Table: Attributes (change-guarded; limited columns for DB sync + RRD)
        $this->walkAndSyncSataAttrPoll();

        // SENSOR-MIB values are polled once in poll(), covering SATA + NVMe.

        // Change-guarded tables:
        $this->walkAndSyncSataPhyEventPoll();
        $this->walkAndSyncSataDevStatPoll();
        $this->walkAndSyncSataTable('smartmonSataErrorLogTable', 2, self::SATA_TID_ERROR_LOG, [$this, 'syncSataErrorLogRows']);
        $this->walkAndSyncSataTable('smartmonSataErrorCmdTable', 3, self::SATA_TID_ERROR_LOG, [$this, 'syncSataErrorCmdRows']);
        $this->walkAndSyncSataTable('smartmonSataSelfTestTable', 2, self::SATA_TID_SELFTEST, [$this, 'syncSataSelfTestRows']);
        $this->walkAndSyncSataTable('smartmonSataSelectiveTestTable', 2, self::SATA_TID_SELECTIVE_TEST, [$this, 'syncSataSelectiveTestRows']);
        $this->walkAndSyncSataTable('smartmonSataPendingDefectsTable', 2, self::SATA_TID_PENDING_DEFECTS, [$this, 'syncSataPendingDefectRows']);

        // Health, self-test status, and self-test age sensors, computed from the
        // tables just synced above and batched through a single updateSensorValues()
        // call per device so stored multipliers (selftest age -> minutes), threshold
        // alerts, and state-change events are all applied.
        foreach ($this->sataDeviceList as $dev) {
            $this->pollSataDeviceSensors($dev);
        }

        $this->persistSataChangeSnapshot();
    }

    /** Update the SATA Health, Self-test Status, and Self-test age sensors for one device. */
    private function pollSataDeviceSensors(array $dev): void
    {
        $diskKey = $dev['disk_key'];
        $idx = $this->mibDiskIndex($diskKey);
        $values = [];

        // Health state sensor, synthesized from DB
        $health = $this->synthesizeHealthFromDb($diskKey);
        if ($health !== null) {
            $values["{$idx}_health"] = (float) $health;
        }

        // Self-test execution status from DB
        $raw = DB::table('smart_sata_health')
            ->where('app_id', $this->appId)
            ->where('disk_key', $diskKey)
            ->value('selftest_exec_status_raw');
        if ($raw !== null) {
            $values["{$idx}_selftest_status"] = (float) $raw;
        }

        // Self-test age (recomputed each poll: grows over time, resets when a test runs).
        // Raw value is hours; updateSensorValues() applies the sensor's stored
        // multiplier (60) to convert to minutes, matching the 'runtime' sensor unit.
        $values += $this->selftestAgeValues($idx, $diskKey, 'smart_sata_health', 'smart_sata_selftest_log');

        if ($values !== []) {
            $this->updateSensorValues($values, "app:smart_mib:{$idx}_");
        }
    }

    /**
     * Walk the four poll-relevant attribute columns and write the per-disk RRD
     * and DB row for every SATA device, every poll.
     *
     * Both the RRD (a time-series) and the displayed raw/normalized values must
     * refresh each interval, so neither is change-gated here. The
     * smartSATAChange stamp is unreliable for the frequently-incrementing
     * attribute values.
     */
    private function walkAndSyncSataAttrPoll(): void
    {
        // Only the four frequently-changing columns: raw value/string, status, normalized.
        // Format isn't walked here -- it's resolved from drivedb.h per drive model/attribute
        // and doesn't change between discovery cycles, so pollSataDeviceRrd() reads the
        // copy discovery already persisted into smart_sata_attributes instead of re-walking it.
        $attrColumns = $this->walkSataColumns([
            'smartmonSataAttrRawValue',
            'smartmonSataAttrRawString',
            'smartmonSataAttrStatus',
            'smartmonSataAttrValue',
        ]);

        foreach ($attrColumns as $devIdx => $attrRows) {
            $dev = $this->sataDeviceList[$devIdx] ?? null;
            if ($dev === null) {
                continue;
            }
            $this->pollSataDeviceRrd($dev, $attrRows);
            $this->syncSataAttributeRowsPoll($dev, $attrRows);
        }
    }

    /**
     * Walk multiple single-column OIDs from a 2-index SATA table and merge into
     * [devIdx][idx2][col] row arrays. Used for poll-time narrow column fetches.
     */
    private function walkSataColumns(array $cols): array
    {
        $result = [];
        foreach ($cols as $col) {
            foreach ($this->walkSataTable($col, 2) as $devIdx => $items) {
                if (! is_array($items)) {
                    continue;
                }
                foreach ($items as $idx2 => $leaf) {
                    // table(2) leaf is [columnName => value]; store the scalar, not the wrapper array.
                    $result[(string) $devIdx][(string) $idx2][$col] = $this->leafValue($leaf, $col);
                }
            }
        }

        return $result;
    }

    /**
     * Poll smartmonSensorValue for every SATA + NVMe device (one SNMP walk for
     * all types). Only the generic SENSOR-MIB sensors (temperature, NVMe
     * spare/used, etc.) are updated here, matched by trailing index. The
     * per-type synthesized sensors (Health, Self-test Status/age) are updated
     * from pollSata()/pollNvme() instead, where the underlying DB tables they
     * read from have just been synced.
     */
    private function pollSensorValues(): void
    {
        // Only the raw value + operational status are needed: the scale is stored on
        // each sensor (sensor_divisor/multiplier at discovery) and applied by
        // updateSensorValues(), so the heavier full-table walk is unnecessary here.
        $sensorValues = SnmpQuery::mibs(self::SENSOR_MIBS)
            ->hideMib()
            ->walk([
                'SMARTMON-SENSOR-MIB::smartmonSensorValue',
                'SMARTMON-SENSOR-MIB::smartmonSensorOperStatus',
            ])
            ->table(2);

        $this->vlog('pollSensorValues: walked smartmonSensorValue/OperStatus for device idx(es) ['
            . implode(', ', array_keys($sensorValues)) . ']; '
            . count($this->sataDeviceList) . ' SATA / ' . count($this->nvmeDeviceList) . ' NVMe device(s) to update');

        foreach ($this->sataDeviceList as $devIdx => $dev) {
            $idx = $this->mibDiskIndex($dev['disk_key']);
            $this->matchSensorMibValues($idx, (string) $devIdx, $sensorValues);
        }

        foreach ($this->nvmeDeviceList as $devIdx => $dev) {
            $idx = $this->mibDiskIndex($dev['disk_key']);
            $this->matchSensorMibValues($idx, (string) $devIdx, $sensorValues);
        }
    }

    /** Load this device's app:smart_mib sensors and update the generic SENSOR-MIB ones from the walked values (matched by trailing index). */
    private function matchSensorMibValues(string $idx, string $devIdx, array $sensorValues): void
    {
        $sensors = Sensor::where('device_id', $this->device['device_id'])
            ->where('sensor_oid', 'like', "app:smart_mib:{$idx}_%")
            ->get()
            ->keyBy('sensor_index');

        // SENSOR-MIB: match by trailing SNMP sensor index (unique within device)
        $bySuffix = [];
        foreach ($sensors as $sIdx => $sensor) {
            if (preg_match('/_(\d+)$/', $sIdx, $m)) {
                $bySuffix[$m[1]] = $sensor;
            }
        }

        $walked = $sensorValues[$devIdx] ?? [];
        $this->vlog("matchSensorMibValues: idx={$idx} devIdx={$devIdx}; "
            . count($sensors) . ' DB sensor(s), suffix key(s) [' . implode(', ', array_keys($bySuffix)) . '], '
            . 'walked sub-index(es) [' . implode(', ', array_keys($walked)) . ']');
        if ($walked === []) {
            $this->vlog("matchSensorMibValues: no walked values for devIdx={$devIdx} (key mismatch?), sensors left unchanged");
        }

        // Collect raw values keyed by sensor_index, then let updateSensorValues()
        // apply each sensor's stored sensor_divisor/multiplier (the smartmonSensorScale).
        $values = [];
        foreach ($walked as $sensorIdx => $rawValue) {
            if ($sensor = $bySuffix[(string) $sensorIdx] ?? null) {
                $raw = $this->leafValue($rawValue, 'smartmonSensorValue');
                $operStatus = $this->intValue($this->leafValue($rawValue, 'smartmonSensorOperStatus'));
                // SmartmonSensorStatus: ok(1) = value reported; unavailable(2)/nonoperational(3) = no trustworthy reading.
                if ($operStatus !== null && $operStatus !== 1) {
                    $this->vlog("matchSensorMibValues: sub-index {$sensorIdx} -> {$sensor->sensor_index} operStatus={$operStatus} (not ok), skipped");
                    continue;
                }
                if (is_numeric($raw)) {
                    $values[$sensor->sensor_index] = (float) $raw;
                }
                $this->vlog("matchSensorMibValues: sub-index {$sensorIdx} -> {$sensor->sensor_index} raw="
                    . var_export($raw, true) . ' operStatus=' . var_export($operStatus, true));
            } else {
                $this->vlog("matchSensorMibValues: sub-index {$sensorIdx} has no matching DB sensor, skipped");
            }
        }

        if ($values !== []) {
            $this->updateSensorValues($values, "app:smart_mib:{$idx}_");
        }
    }

    /** Write per-disk RRDs for one SATA device. */
    private function pollSataDeviceRrd(array $dev, array $attrRows): void
    {
        $device = $this->device;
        $diskKey = $dev['disk_key'];
        $idx = $this->mibDiskIndex($diskKey);

        // Attribute RRD + power state. Keep power_state last because rrdtool tune
        // appends new DS to existing files; keyed updates below tolerate missing
        // source fields while preserving DS order for newly-created RRDs.
        $rrd_def = RrdDefinition::make();
        $fields = [];

        if (! empty($attrRows)) {
            // Format isn't walked at poll time (see walkAndSyncSataAttrPoll()) -- it's
            // resolved from drivedb.h per drive model/attribute and effectively static
            // between discovery cycles, so read the copy discovery already persisted,
            // alongside rrd_type, in a single query.
            $attrMeta = DB::table('smart_sata_attributes')
                ->where('app_id', $this->appId)
                ->where('disk_key', $diskKey)
                ->get(['attribute_id', 'rrd_type', 'format'])
                ->keyBy('attribute_id');

            foreach ($attrRows as $attrId => $row) {
                $id = (int) ($row['smartmonSataAttrId'] ?? $attrId);
                $dsRaw = 'id' . $id;
                $dsNorm = 'id' . $id . 'Normalized';
                if (strlen($dsNorm) > 19) {
                    continue;
                }
                $meta = $attrMeta->get($id);
                $rawType = $meta->rrd_type
                    ?? ($this->isCounterAttrName($row['smartmonSataAttrName'] ?? null) || isset(self::ATA_COUNTER_ATTRS[$id])
                        ? 'COUNTER' : 'GAUGE');

                $format = $this->intValue($meta->format ?? null);
                $rawString = $row['smartmonSataAttrRawString'] ?? null;

                $rrd_def->addDataset($dsNorm, 'GAUGE', 0);
                $fields[$dsNorm] = $row['smartmonSataAttrValue'] ?? null;

                // Multi-value formats (raw8, raw16, raw16raw16, raw24raw8, raw24div24,
                // raw24div32) decode into independent sub-DS that replace the base idXX
                // entirely -- the packed RawValue isn't a meaningful single number for
                // these. Fall back to writing idXX from RawValue if RawString didn't
                // parse, so a malformed string doesn't lose the attribute's data outright.
                $subValues = $this->attrFormatSubValues($format, $rawString);
                if ($subValues !== []) {
                    foreach ($subValues as $suffix => $value) {
                        $dsSub = 'id' . $id . $suffix;
                        if (strlen($dsSub) > 19) {
                            continue;
                        }
                        $rrd_def->addDataset($dsSub, 'GAUGE', 0);
                        $fields[$dsSub] = $value;
                    }

                    continue;
                }

                // For formats that reduce RawString to a single more-meaningful number
                // (e.g. min2hour/msec24hour32 -> total hours as a float) use that
                // instead of the packed RawValue; it's a derived float, so always GAUGE.
                $singleValue = $this->attrFormatSingleValue($format, $rawString);
                if ($singleValue !== null) {
                    $rawType = 'GAUGE';
                }

                $rrd_def->addDataset($dsRaw, $rawType, 0);
                $fields[$dsRaw] = $singleValue ?? ($row['smartmonSataAttrRawValue'] ?? null);
            }
        }

        $rrd_def->addDataset('power_state', 'GAUGE', 0, 8);
        $fields['power_state'] = $this->intValue($dev['power_state'] ?? null);

        $rrdName = ['app', 'smart', $this->appId, $idx];
        $rrd = app(Rrd::class);
        $rrdFile = $rrd->name($device->hostname, $rrdName);
        $hasRrd = $rrd->checkRrdExists($rrdFile);
        if (count($fields) === 1 && ! $hasRrd) {
            return;
        }

        // DS reconciliation (retrofitting power_state onto older files) is a
        // discovery concern, handled by reconcileCommonDeviceRrds(); new files
        // get every DS at create time from $rrd_def. No tune at poll time.
        app('Datastore')->put($this->os->getDeviceArray(), 'app', [
            'name'                => 'smart',
            'app_id'              => $this->appId,
            'rrd_def'             => $rrd_def,
            'rrd_name'            => $rrdName,
            'rrd_update_template' => true,
        ], $fields);
    }

    /**
     * Decode the extra component(s) packed into smartmonSataAttrRawString for
     * multi-value SmartmonAtaSmartAttrFormat encodings (raw8, raw16,
     * raw16raw16, raw24raw8, raw24div24, raw24div32). Returns DS-suffix =>
     * value pairs that replace the base id{N} DS entirely (see
     * pollSataDeviceRrd()) -- the packed RawValue isn't a meaningful single
     * number for these formats. [] for single-value formats, unknown/null
     * format, or an unparseable string.
     */
    private function attrFormatSubValues(?int $format, ?string $rawString): array
    {
        if ($format === null || $rawString === null) {
            return [];
        }
        $s = trim($rawString);

        return match ($format) {
            1 => $this->parseRaw8SubValues($s),
            2 => $this->parseRaw16SubValues($s),
            9 => $this->parseRaw16Raw16SubValues($s),
            11 => $this->parseRaw24Raw8SubValues($s),
            12, 13 => $this->parseRaw24DivSubValues($s),
            default => [],
        };
    }

    /** raw8: 'b5 b4 b3 b2 b1 b0' -> independent byte counters, P5..P0 by position. */
    private function parseRaw8SubValues(string $s): array
    {
        if (! preg_match('/^(\d+) (\d+) (\d+) (\d+) (\d+) (\d+)$/', $s, $m)) {
            return [];
        }

        return [
            'P5' => (float) $m[1], 'P4' => (float) $m[2], 'P3' => (float) $m[3],
            'P2' => (float) $m[4], 'P1' => (float) $m[5], 'P0' => (float) $m[6],
        ];
    }

    /** raw16: 'w2 w1 w0' -> independent word counters, P2..P0 by position. */
    private function parseRaw16SubValues(string $s): array
    {
        if (! preg_match('/^(\d+) (\d+) (\d+)$/', $s, $m)) {
            return [];
        }

        return ['P2' => (float) $m[1], 'P1' => (float) $m[2], 'P0' => (float) $m[3]];
    }

    /** raw16raw16: 'w0' or 'w0 (w2 w1)' -> P2/P1 only when the paren group is present. */
    private function parseRaw16Raw16SubValues(string $s): array
    {
        if (! preg_match('/^\d+ \((\d+) (\d+)\)$/', $s, $m)) {
            return [];
        }

        return ['P2' => (float) $m[1], 'P1' => (float) $m[2]];
    }

    /** raw24raw8: 'low24' or 'low24 (b5 b4 b3)' -> P5/P4/P3 only when the paren group is present. */
    private function parseRaw24Raw8SubValues(string $s): array
    {
        if (! preg_match('/^\d+ \((\d+) (\d+) (\d+)\)$/', $s, $m)) {
            return [];
        }

        return ['P5' => (float) $m[1], 'P4' => (float) $m[2], 'P3' => (float) $m[3]];
    }

    /** raw24div24/raw24div32: 'hi/lo' -> Sum (hi+lo) plus the two parts. */
    private function parseRaw24DivSubValues(string $s): array
    {
        if (! preg_match('#^(\d+)/(\d+)$#', $s, $m)) {
            return [];
        }
        $hi = (float) $m[1];
        $lo = (float) $m[2];

        return ['Sum' => $hi + $lo, 'Hi' => $hi, 'Lo' => $lo];
    }

    /**
     * For SmartmonAtaSmartAttrFormat values that reduce smartmonSataAttrRawString
     * to a single number more meaningful than the packed RawValue (min2hour,
     * msec24hour32 -> total hours as a float), return that value so it can
     * replace the base id{N} DS. Returns null for every other format (or an
     * unparseable string), in which case the caller keeps using RawValue as-is.
     */
    private function attrFormatSingleValue(?int $format, ?string $rawString): ?float
    {
        if ($format === null || $rawString === null) {
            return null;
        }
        $s = trim($rawString);

        return match ($format) {
            15 => $this->parseMin2HourHours($s),
            17 => $this->parseMsec24Hour32Hours($s),
            default => null,
        };
    }

    /** min2hour: 'Hh+MMm' (optional trailing paren extra is ignored) -> total hours as a float. */
    private function parseMin2HourHours(string $s): ?float
    {
        if (! preg_match('/^(\d+)h\+(\d+)m/', $s, $m)) {
            return null;
        }

        return (float) $m[1] + ((float) $m[2]) / 60;
    }

    /** msec24hour32: 'Hh+MMm+SS.mmms' -> total hours as a float, including the ms fraction. */
    private function parseMsec24Hour32Hours(string $s): ?float
    {
        if (! preg_match('/^(\d+)h\+(\d+)m\+(\d+)\.(\d+)s$/', $s, $m)) {
            return null;
        }

        return (float) $m[1] + ((float) $m[2]) / 60 + ((float) $m[3]) / 3600 + ((float) $m[4]) / 3600000;
    }

    // ── NVMe ──────────────────────────────────────────────────────────────────

    /**
     * Discover all NVMe tables. NVMe has no change table, so every table is
     * walked once and synced for each NVMe device. Temperature / spare / used
     * sensors come from the SENSOR-MIB and are registered by discoverMib().
     */
    private function discoverNvme(): void
    {
        $this->nvmeDeviceList = $this->nvmeDevices();
        if ($this->nvmeDeviceList === []) {
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

        foreach ($this->nvmeDeviceList as $devIdx => $dev) {
            $key = (string) $devIdx;
            $this->vlog("discoverNvme: device idx={$key} disk_key={$dev['disk_key']}");

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

        $this->discoverSelftestAgeSensors($this->nvmeDeviceList, 'smart_nvme_selftest_', 'smart_nvme_health', 'smart_nvme_selftest_log');
        $this->syncNvmeSensorTypes();
    }

    /** Register the merged NVMe health-state sensor (overall status + critical warning) for one device. */
    private function discoverNvmeDeviceSensors(array $dev, array $health): void
    {
        if (! isset($health['smartmonNvmeHealthOverallStatus'])) {
            return;
        }

        $device = $this->device;
        $idx = $this->mibDiskIndex($dev['disk_key']);
        $devName = $this->sensorLabel($dev, $dev['snmp_index']);
        $group = 'SMART';

        $state = $this->nvmeHealthLevel(
            $health['smartmonNvmeHealthOverallStatus'],
            $health['smartmonNvmeCriticalWarning'] ?? null
        );

        $this->discoverSensor(
            class: 'state',
            type: 'smart_nvme_health',
            index: "{$idx}_health",
            oid: "app:smart_mib:{$idx}_health",
            descr: "{$group} {$devName} Health",
            current: $state,
            group: $group,
        )
            ->withStateTranslations('smart_nvme_health', [
                StateTranslation::define('OK', 1, Severity::Ok),
                StateTranslation::define('Warning', 2, Severity::Warning),
                StateTranslation::define('Failed', 3, Severity::Error),
                StateTranslation::define('Critical Warning', 4, Severity::Error),
                StateTranslation::define('Unavailable', 5, Severity::Warning),
            ]);
    }

    /** Register the NVMe self-test status sensor (current op, else most recent log result) for one device. */
    private function discoverNvmeSelftestStatusSensor(array $dev, array $health, array $selftestRows): void
    {
        $currentOp = $this->intValue($health['smartmonNvmeCurrentSelfTestOperationValue'] ?? null) ?? 0;
        $entries = array_map(static fn ($row) => [
            'result'         => $row['smartmonNvmeSelfTestResult'] ?? null,
            'power_on_hours' => $row['smartmonNvmeSelfTestPowerOnHours'] ?? null,
        ], $selftestRows);

        $value = $this->nvmeSelftestStatusValue($currentOp, $entries);
        if ($value === null) {
            return;
        }

        $device = $this->device;
        $idx = $this->mibDiskIndex($dev['disk_key']);
        $devName = $this->sensorLabel($dev, $dev['snmp_index']);
        $group = 'SMART';

        $this->discoverSensor(
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
        if ($this->parseBitsValue($critRaw)) {
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

    /**
     * Poll NVMe health (DB + RRD) and refresh the self-test log for each device.
     * State sensors are updated from the DB in pollSensorValues().
     */
    private function pollNvme(): void
    {
        $this->nvmeDeviceList = $this->nvmeDevicesFromDb();
        if ($this->nvmeDeviceList === []) {
            return;
        }

        $health = $this->walkNvmeTable('smartmonNvmeHealthTable', 2);
        $selftests = $this->walkNvmeTable('smartmonNvmeSelfTestTable', 2);
        $errors = $this->walkNvmeTable('smartmonNvmeErrorLogTable', 2);

        foreach ($this->nvmeDeviceList as $devIdx => $dev) {
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

    /** Update the NVMe Health, Self-test Status, and Self-test age sensors for one device. */
    private function pollNvmeDeviceSensors(array $dev): void
    {
        $diskKey = $dev['disk_key'];
        $idx = $this->mibDiskIndex($diskKey);
        $values = [];

        // Merged health state: overall status plus critical warning, stored at poll time.
        $row = DB::table('smart_nvme_health')
            ->where('app_id', $this->appId)
            ->where('disk_key', $diskKey)
            ->first(['overall_status', 'critical_warning', 'current_selftest_op']);
        $currentSelftestOp = 0;
        if ($row !== null) {
            $values["{$idx}_health"] = (float) $this->nvmeHealthLevel($row->overall_status, $row->critical_warning);
            $currentSelftestOp = (int) ($row->current_selftest_op ?? 0);
        }

        // Self-test status from DB (current op, else most recent log result).
        $entries = DB::table('smart_nvme_selftest_log')
            ->where('app_id', $this->appId)
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
        $values += $this->selftestAgeValues($idx, $diskKey, 'smart_nvme_health', 'smart_nvme_selftest_log');

        if ($values !== []) {
            $this->updateSensorValues($values, "app:smart_mib:{$idx}_");
        }
    }

    /** Build the `{idx}_selftest_short`/`_long` raw-hours values for one device, ready to batch into updateSensorValues(). */
    private function selftestAgeValues(string $idx, string $diskKey, string $healthTable, string $logTable): array
    {
        $values = [];
        foreach (['short' => 1, 'long' => 2] as $suffix => $testType) {
            $age = $this->selftestAgeHours($healthTable, $logTable, $diskKey, $testType);
            if ($age !== null) {
                $values["{$idx}_selftest_{$suffix}"] = (float) $age;
            }
        }

        return $values;
    }

    /** Write the per-disk NVMe SMART/Health RRD (['app','smart_nvme',app_id,idx]). */
    private function pollNvmeDeviceRrd(array $dev, array $health): void
    {
        $idx = $this->mibDiskIndex($dev['disk_key']);

        $rrd_def = RrdDefinition::make();
        $fields = [];
        foreach (self::NVME_HEALTH_RRD as $col => [$ds, $type]) {
            $rrd_def->addDataset($ds, $type, 0);
            $value = $col === 'smartmonNvmeCriticalWarning'
                ? $this->parseBitsValue($health[$col] ?? null)
                : $this->intValue($health[$col] ?? null);
            $fields[$ds] = $value;
        }

        $rrd_def->addDataset('power_state', 'GAUGE', 0, 8);
        $fields['power_state'] = $this->intValue($dev['power_state'] ?? null);

        $rrdName = ['app', 'smart_nvme', $this->appId, $idx];

        // DS reconciliation (retrofitting power_state onto older files) is a
        // discovery concern, handled by reconcileCommonDeviceRrds(); new files
        // get every DS at create time from $rrd_def below. No tune at poll time.
        //
        // NVME_HEALTH_RRD is a fixed set, plus power_state, so $fields always carries every DS.
        app('Datastore')->put($this->os->getDeviceArray(), 'app', [
            'name'                => 'smart_nvme',
            'app_id'              => $this->appId,
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

    /** Resolve a SmartmonHealthStatus value (enum int, "passed(1)", or bare name) to 0-4. */
    private function healthStatusValue(mixed $raw): ?int
    {
        $int = $this->intValue($raw);
        if ($int !== null) {
            return $int;
        }

        return self::HEALTH_STATUS_NAMES[strtolower(trim((string) $raw))] ?? null;
    }

    // ── SNMP table fetchers ───────────────────────────────────────────────────

    private function commonDeviceTable(): void
    {
        $metaTable = SnmpQuery::mibs(self::COMMON_MIBS)
            ->hideMib()
            ->walk('SMARTMON-COMMON-MIB::smartmonDeviceMetadataTable')
            ->table(1);
        $statusTable = SnmpQuery::mibs(self::COMMON_MIBS)
            ->hideMib()
            ->walk('SMARTMON-COMMON-MIB::smartmonDeviceStatusTable')
            ->table(1);

        $this->commonDevices = [];
        foreach ($metaTable as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $statusRow = $statusTable[$index] ?? [];
            $this->commonDevices[(string) $index] = [
                'snmp_index'           => (string) $index,
                'disk_key'             => $this->diskKey($row, (string) $index),
                'device_name'          => $row['smartmonDeviceName'] ?? null,
                'device_path'          => $row['smartmonDevicePath'] ?? null,
                'device_type'          => $this->intValue($row['smartmonDeviceType'] ?? null),
                'last_poll_time'       => $statusRow['smartmonDeviceLastPollTime'] ?? null,
                'last_poll_result'     => $this->intValue($statusRow['smartmonDeviceLastPollResult'] ?? null),
                'last_poll_exit_status'=> $this->intValue($statusRow['smartmonDeviceLastPollExitStatus'] ?? null),
                'power_state'          => $this->intValue($statusRow['smartmonDevicePowerState'] ?? null),
                'physical_index'       => $this->intValue($row['smartmonDevicePhysicalIndex'] ?? null),
                'uris'                 => $row['smartmonDeviceUris'] ?? null,
                'model_family'         => $row['smartmonDeviceModelFamily'] ?? null,
                'model_name'           => $row['smartmonDeviceModelName'] ?? null,
                'serial_number'        => $row['smartmonDeviceSerialNumber'] ?? null,
                'firmware_version'     => $row['smartmonDeviceFirmwareVersion'] ?? null,
                'wwn'                  => $row['smartmonDeviceWwn'] ?? null,
            ];
        }

        $this->dedupeCommonDevices();
    }

    /**
     * Collapse device-table entries that describe the same physical drive.
     *
     * A drive enumerated via two transports can appear twice. For example, one
     * path reports a WWN and the other only a serial, yielding two different
     * disk_keys. Entries sharing any non-empty WWN or serial are treated as one
     * logical drive; the most complete entry (WWN-bearing, then lowest
     * snmp_index) is kept as canonical and the rest are dropped so only a single
     * row is discovered, stored, and shown.
     */
    private function dedupeCommonDevices(): void
    {
        if ($this->commonDevices === null || count($this->commonDevices) < 2) {
            return;
        }

        // Order so the most complete identity wins as canonical: WWN-bearing
        // first, then by numeric snmp_index for stable, deterministic results.
        $ordered = $this->commonDevices;
        uksort($ordered, function ($a, $b) use ($ordered) {
            $aw = trim((string) ($ordered[$a]['wwn'] ?? '')) !== '' ? 0 : 1;
            $bw = trim((string) ($ordered[$b]['wwn'] ?? '')) !== '' ? 0 : 1;

            return $aw <=> $bw ?: (int) $a <=> (int) $b;
        });

        $seen = [];   // identity value => canonical snmp_index
        $kept = [];
        foreach ($ordered as $idx => $dev) {
            $identities = array_filter([
                trim((string) ($dev['wwn'] ?? '')),
                trim((string) ($dev['serial_number'] ?? '')),
            ], static fn ($v) => $v !== '');

            $canonical = null;
            foreach ($identities as $id) {
                if (isset($seen[$id])) {
                    $canonical = $seen[$id];
                    break;
                }
            }
            if ($canonical !== null) {
                $this->vlog("dedupeCommonDevices: snmp_index={$idx} (disk_key={$dev['disk_key']}) is a duplicate of snmp_index={$canonical}, dropped");
                continue;
            }
            foreach ($identities as $id) {
                $seen[$id] = $idx;
            }
            $kept[(string) $idx] = $dev;
        }

        $dropped = count($this->commonDevices) - count($kept);
        if ($dropped > 0) {
            $this->vlog("dedupeCommonDevices: collapsed {$dropped} duplicate device entry/entries");
        }
        $this->commonDevices = $kept;
    }

    private function sataChangeByDeviceTable(): void
    {
        if ($this->sataChangeRows !== null) {
            return;
        }
        $this->prevSataChange = $this->loadStoredSataChangeSnapshot();

        // table(2) puts the column name at depth 3 ([devIdx][tableId][colName]),
        // so walk the full table and extract the lastChange column explicitly.
        $this->sataChangeRows = [];
        foreach ($this->walkSataTable('smartSATAChangeByDeviceTable', 2) as $devIdx => $tableRows) {
            foreach ($tableRows as $tableId => $row) {
                if (! is_array($row)) {
                    continue;
                }
                // SNMP returns named enum strings ("sataInfo") when MIBs are loaded; normalize to int.
                $tid = self::SATA_TID_NAMES[(string) $tableId] ?? (is_numeric($tableId) ? (int) $tableId : null);
                if ($tid === null) {
                    continue;
                }
                $this->sataChangeRows[(string) $devIdx][(string) $tid] =
                    $row['smartSATAChangeByDeviceLastChange'] ?? null;
            }
        }

        $this->sataSubindexChangeRows = [];
        foreach ($this->walkSataTable('smartSATAChangeBySubindexTable', 3) as $devIdx => $tableRows) {
            if (! is_array($tableRows)) {
                continue;
            }
            foreach ($tableRows as $tableId => $subindexes) {
                if (! is_array($subindexes)) {
                    continue;
                }
                $tid = self::SATA_TID_NAMES[(string) $tableId] ?? (is_numeric($tableId) ? (int) $tableId : null);
                if ($tid === null) {
                    continue;
                }
                foreach ($subindexes as $subindex => $row) {
                    if (is_array($row)) {
                        $this->sataSubindexChangeRows[(string) $devIdx][(string) $tid][(string) $subindex] =
                            $row['smartSATAChangeBySubindexLastChange'] ?? null;
                    }
                }
            }
        }

        $this->vlog('sataChangeByDeviceTable: loaded ' . count($this->sataChangeRows) . ' device change row(s), prev snapshot ' . ($this->prevSataChange !== null ? 'present' : 'absent'));
    }

    private function sataHealthTable(): void
    {
        $this->sataHealth = [];
        foreach ($this->walkSataTable('smartmonSataHealthTable', 1) as $index => $row) {
            if (is_array($row)) {
                $this->sataHealth[(string) $index] = $row;
            }
        }
    }

    private function sataAttributeTable(): void
    {
        $this->sataAttributes = [];
        foreach ($this->walkSataTable('smartmonSataAttrTable', 2) as $deviceIndex => $deviceAttributes) {
            if (! is_array($deviceAttributes)) {
                continue;
            }
            foreach ($deviceAttributes as $attributeId => $row) {
                if (is_array($row)) {
                    $this->sataAttributes[(string) $deviceIndex][(string) $attributeId] = $row;
                }
            }
        }
    }

    private function sensorTable(): void
    {
        $this->sensorRows = SnmpQuery::mibs(self::SENSOR_MIBS)
            ->hideMib()
            ->walk('SMARTMON-SENSOR-MIB::smartmonSensorTable')
            ->table(2);
    }

    // ── Database sync ─────────────────────────────────────────────────────────

    /**
     * Upsert into $table, deriving the update column list from the row(s)
     * automatically: every key except the identity columns ($uniqueBy plus
     * device_id, which never changes for an existing app_id+disk_key and so
     * is intentionally excluded from every update set in this module).
     *
     * Removes the need to hand-maintain a second column list alongside the
     * insert array -- previously, adding a column to the row but forgetting
     * to add it to the update list meant the column silently stopped
     * updating after the first insert.
     *
     * @param  array<string,mixed>|list<array<string,mixed>>  $rows  one row (associative), or a list of rows sharing the same column set
     * @param  array<int,string>  $uniqueBy
     */
    private function upsert(string $table, array $rows, array $uniqueBy): void
    {
        if ($rows === []) {
            return;
        }

        $sample = array_is_list($rows) ? $rows[0] : $rows;
        $update = array_values(array_diff(array_keys($sample), $uniqueBy, ['device_id']));

        DB::table($table)->upsert($rows, $uniqueBy, $update);
    }

    /**
     * Delete rows for this app/disk whose key is no longer present, so a table
     * sync mirrors exactly the keys just walked. $extra adds further equality
     * constraints (used for nested tables keyed by page/error entry).
     *
     * @param array<int|string> $keepKeys     keys to retain (everything else is pruned)
     * @param array<string,mixed> $extra       additional column => value where clauses
     */
    private function pruneStaleRows(string $table, string $diskKey, string $keyCol, array $keepKeys, array $extra = []): void
    {
        $query = DB::table($table)
            ->where('app_id', $this->appId)
            ->where('disk_key', $diskKey);

        foreach ($extra as $col => $val) {
            $query->where($col, $val);
        }

        $query->whereNotIn($keyCol, $keepKeys)->delete();
    }

    /** Upsert all discovered devices into smart_devices. */
    private function syncDeviceRows(): void
    {
        $this->vlog('syncDeviceRows: upserting ' . count($this->commonDevices) . ' device(s)');
        foreach ($this->commonDevices as $snmpIndex => $dev) {
            $this->upsert('smart_devices', [
                'app_id'           => $this->appId,
                'device_id'        => $this->deviceId,
                'disk_key'         => $dev['disk_key'],
                'snmp_index'       => (int) $snmpIndex,
                'device_name'      => $dev['device_name'],
                'device_path'      => $dev['device_path'],
                'protocol_type'    => $dev['device_type'],
                'model_family'     => $dev['model_family'],
                'model_name'       => $dev['model_name'],
                'serial_number'    => $dev['serial_number'],
                'firmware_version' => $dev['firmware_version'],
                'wwn'              => $dev['wwn'],
                'last_poll_time'   => $this->parseDateAndTime($dev['last_poll_time']),
                'last_poll_result' => $dev['last_poll_result'],
                'last_poll_exit'   => $dev['last_poll_exit_status'],
                'power_state'      => $dev['power_state'],
                'physical_index'   => $dev['physical_index'] ?? 0,
                'uris'             => $dev['uris'],
            ], ['app_id', 'disk_key']);
        }
    }

    private function syncSataInfoRow(array $dev, array $row): void
    {
        $this->upsert('smart_sata_info', [
            'app_id'                               => $this->appId,
            'device_id'                            => $this->deviceId,
            'disk_key'                             => $dev['disk_key'],
            'ata_version'                          => $this->intValue($row['smartmonSataAtaVersion'] ?? null),
            'sata_version'                         => $this->intValue($row['smartmonSataVersion'] ?? null),
            'rotation_rate'                        => $row['smartmonSataRotationRate'] ?? null,
            'form_factor'                          => $this->intValue($row['smartmonSataFormFactor'] ?? null),
            'logical_block_size'                   => $row['smartmonSataLogicalBlockSize'] ?? null,
            'physical_block_size'                  => $row['smartmonSataPhysicalBlockSize'] ?? null,
            'user_capacity_bytes'                  => $row['smartmonSataUserCapacityBytes'] ?? null,
            'sct_hist_op_limit_min'                => $row['smartmonSataSctHistOpLimitMin'] ?? null,
            'sct_hist_op_limit_max'                => $row['smartmonSataSctHistOpLimitMax'] ?? null,
            'sct_hist_limit_min'                   => $row['smartmonSataSctHistLimitMin'] ?? null,
            'sct_hist_limit_max'                   => $row['smartmonSataSctHistLimitMax'] ?? null,
            // New columns
            'ata_version_major'                    => $this->intValue($row['smartmonSataAtaVersionMajor'] ?? null),
            'ata_version_minor'                    => $this->intValue($row['smartmonSataAtaVersionMinor'] ?? null),
            'user_capacity_blocks'                 => $row['smartmonSataUserCapacityBlocks'] ?? null,
            'in_smartctl_database'                 => $this->snmpTruthValue($row['smartmonSataInSmartctlDatabase'] ?? null),
            'smart_available'                      => $this->snmpTruthValue($row['smartmonSataSmartAvailable'] ?? null),
            'smart_enabled'                        => $this->snmpTruthValue($row['smartmonSataSmartEnabled'] ?? null),
            'trim_supported'                       => $this->snmpTruthValue($row['smartmonSataTrimSupported'] ?? null),
            'write_cache_enabled'                  => $this->snmpTruthValue($row['smartmonSataWriteCacheEnabled'] ?? null),
            'read_lookahead_enabled'               => $this->snmpTruthValue($row['smartmonSataReadLookaheadEnabled'] ?? null),
            'apm_enabled'                          => $this->snmpTruthValue($row['smartmonSataApmEnabled'] ?? null),
            'apm_level'                            => $this->intValue($row['smartmonSataApmLevel'] ?? null),
            'security_state'                       => $row['smartmonSataSecurityState'] ?? null,
            'security_enabled'                     => $this->snmpTruthValue($row['smartmonSataSecurityEnabled'] ?? null),
            'security_frozen'                      => $this->snmpTruthValue($row['smartmonSataSecurityFrozen'] ?? null),
            'if_speed_current_value'               => $row['smartmonSataIfSpeedCurrentValue'] ?? null,
            'if_speed_max_value'                   => $row['smartmonSataIfSpeedMaxValue'] ?? null,
            'selftest_polling_short_minutes'       => $row['smartmonSataSelfTestPollingShortMinutes'] ?? null,
            'selftest_polling_extended_minutes'    => $row['smartmonSataSelfTestPollingExtendedMinutes'] ?? null,
            'selftest_polling_conveyance_minutes'  => $row['smartmonSataSelfTestPollingConveyanceMinutes'] ?? null,
            'offline_collection_completion_secs'   => $row['smartmonSataOfflineCollectionCompletionSecs'] ?? null,
            'attr_revision'                        => $row['smartmonSataAttrRevision'] ?? null,
            'error_log_revision'                   => $row['smartmonSataErrorLogRevision'] ?? null,
            'error_log_sectors'                    => $row['smartmonSataErrorLogSectors'] ?? null,
            'selftest_log_revision'                => $row['smartmonSataSelfTestLogRevision'] ?? null,
            'selftest_log_sectors'                 => $row['smartmonSataSelfTestLogSectors'] ?? null,
            'pending_defects_size'                 => $row['smartmonSataPendingDefectsSize'] ?? null,
            'capability_selftests_supported'       => $this->snmpTruthValue($row['smartmonSataCapabilitySelfTestsSupported'] ?? null),
            'capability_conveyance_supported'      => $this->snmpTruthValue($row['smartmonSataCapabilityConveyanceSupported'] ?? null),
            'capability_selective_supported'       => $this->snmpTruthValue($row['smartmonSataCapabilitySelectiveSupported'] ?? null),
            'capability_error_logging_supported'   => $this->snmpTruthValue($row['smartmonSataCapabilityErrorLoggingSupported'] ?? null),
            'capability_gp_logging_supported'      => $this->snmpTruthValue($row['smartmonSataCapabilityGpLoggingSupported'] ?? null),
            'capability_exec_offline_immediate'    => $this->snmpTruthValue($row['smartmonSataCapabilityExecOfflineImmediate'] ?? null),
            'capability_offline_aborted_on_cmd'    => $this->snmpTruthValue($row['smartmonSataCapabilityOfflineAbortedOnCmd'] ?? null),
            'capability_offline_surface_scan'      => $this->snmpTruthValue($row['smartmonSataCapabilityOfflineSurfaceScan'] ?? null),
            'capability_attr_autosave'             => $this->snmpTruthValue($row['smartmonSataCapabilityAttrAutosave'] ?? null),
            'sct_error_recovery_supported'         => $this->snmpTruthValue($row['smartmonSataSctErrorRecoverySupported'] ?? null),
            'sct_feature_control_supported'        => $this->snmpTruthValue($row['smartmonSataSctFeatureControlSupported'] ?? null),
            'sct_data_table_supported'             => $this->snmpTruthValue($row['smartmonSataSctDataTableSupported'] ?? null),
        ], ['app_id', 'disk_key']);
    }

    private function syncSataHealthRow(array $dev, array $row): void
    {
        $this->upsert('smart_sata_health', [
            'app_id'                     => $this->appId,
            'device_id'                  => $this->deviceId,
            'disk_key'                   => $dev['disk_key'],
            'overall_status'             => $this->snmpTruthValue($row['smartmonSataHealthOverallStatus'] ?? null),
            'offline_collection_status'  => $row['smartmonSataOfflineCollectionStatusValue'] ?? null,
            'selftest_exec_status_raw'   => $row['smartmonSataSelfTestExecutionStatusValue'] ?? null,
            'power_cycles'               => $row['smartmonSataPowerCycles'] ?? null,
            'power_on_hours'             => $row['smartmonSataPowerOnHours'] ?? null,
            'error_log_count'            => $row['smartmonSataErrorLogCount'] ?? null,
            'pending_defects_count'      => $row['smartmonSataPendingDefectsCount'] ?? null,
            'selftest_log_count'         => $row['smartmonSataSelfTestLogCount'] ?? null,
            'selftest_log_err_total'     => $row['smartmonSataSelfTestLogErrTotal'] ?? null,
            'selftest_log_err_outdated'  => $row['smartmonSataSelfTestLogErrOutdated'] ?? null,
            'selftest_remaining_pct'     => $row['smartmonSataSelfTestExecutionRemainingPct'] ?? null,
            'sct_format_version'         => $row['smartmonSataSctStatusFormatVersion'] ?? null,
            'sct_version'                => $row['smartmonSataSctStatusSctVersion'] ?? null,
            'sct_device_state'           => $row['smartmonSataSctStatusDeviceState'] ?? null,
            'sct_temp_power_cycle_min'   => $row['smartmonSataSctTempPowerCycleMin'] ?? null,
            'sct_temp_power_cycle_max'   => $row['smartmonSataSctTempPowerCycleMax'] ?? null,
            'sct_temp_lifetime_min'      => $row['smartmonSataSctTempLifetimeMin'] ?? null,
            'sct_temp_lifetime_max'      => $row['smartmonSataSctTempLifetimeMax'] ?? null,
            'sct_temp_under_limit_count' => $row['smartmonSataSctTempUnderLimitCount'] ?? null,
            'sct_temp_over_limit_count'  => $row['smartmonSataSctTempOverLimitCount'] ?? null,
            'sct_smart_status_passed'               => $this->snmpTruthValue($row['smartmonSataSctSmartStatusPassed'] ?? null),
            'selftest_estimated_completion_time'    => $this->parseDateAndTime($row['smartmonSataSelfTestEstimatedCompletionTime'] ?? null),
            'selftest_estimated_bytes_sec'          => $row['smartmonSataSelfTestEstimatedBytesSec'] ?? null,
        ], ['app_id', 'disk_key']);
    }

    private function syncSataAttributeRows(array $dev, array $attrRows): void
    {
        foreach ($attrRows as $attrId => $row) {
            $this->upsert('smart_sata_attributes', [
                'app_id'           => $this->appId,
                'device_id'        => $this->deviceId,
                'disk_key'         => $dev['disk_key'],
                'attribute_id'     => (int) ($row['smartmonSataAttrId'] ?? $attrId),
                'name'             => $row['smartmonSataAttrName'] ?? null,
                'value_norm'       => $row['smartmonSataAttrValue'] ?? null,
                'value_worst'      => $row['smartmonSataAttrWorst'] ?? null,
                'value_threshold'  => $row['smartmonSataAttrThreshold'] ?? null,
                'value_raw'        => $row['smartmonSataAttrRawValue'] ?? null,
                'value_raw_string' => isset($row['smartmonSataAttrRawString'])
                    ? substr((string) $row['smartmonSataAttrRawString'], 0, 32)
                    : null,
                'status'           => $row['smartmonSataAttrStatus'] ?? null,
                'format'           => $this->intValue($row['smartmonSataAttrFormat'] ?? null),
                'flags'            => $this->parseAttrFlags($row['smartmonSataAttrFlags'] ?? null),
                'rrd_type'         => $this->isCounterAttrName($row['smartmonSataAttrName'] ?? null)
                    ? 'COUNTER' : 'GAUGE',
            ], ['app_id', 'disk_key', 'attribute_id']);
        }
    }

    /**
     * Compute average raw-value change per hour over the 8h/24h/168h/672h
     * lookback windows from RRD history, persist into smart_sata_attributes,
     * and resolve rate_status (-1/1/2) against the configured rate-of-change
     * threshold. Runs at discovery time only (RRD history accrues via polling;
     * discovery is the natural cadence to re-evaluate trends).
     */
    private function syncSataAttributeRates(array $dev, array $attrRows): void
    {
        $diskKey = $dev['disk_key'];
        $idx = $this->mibDiskIndex($diskKey);
        $rrd = app(Rrd::class);
        $rrdFilename = $rrd->name($this->device['hostname'], ['app', 'smart', $this->appId, $idx]);
        $now = time();

        $rrdTypes = DB::table('smart_sata_attributes')
            ->where('app_id', $this->appId)
            ->where('disk_key', $diskKey)
            ->pluck('rrd_type', 'attribute_id');

        $counterDs = [];
        $gaugeDs = [];
        $dsByAttrId = [];
        foreach ($attrRows as $attrId => $row) {
            $id = (int) ($row['smartmonSataAttrId'] ?? $attrId);
            $ds = $this->rateDsForAttribute($id, $row);
            $dsByAttrId[$id] = $ds;
            if ($ds === null) {
                continue;
            }
            // id{N}Hi (div-format sub-DS) is always written GAUGE by pollSataDeviceRrd(),
            // regardless of what rrd_type says about the base attribute.
            if ($ds === 'id' . $id && ($rrdTypes[$id] ?? null) === 'COUNTER') {
                $counterDs[] = $ds;
            } else {
                $gaugeDs[] = $ds;
            }
        }

        [$ratesByDs, $failedWindows] = $this->fetchAttributeRates($rrd, $rrdFilename, $counterDs, $gaugeDs, $now);
        $thresholdRows = $this->loadThresholdRows($diskKey);

        // A window whose rrdtool fetch failed outright (timeout, process error) keeps
        // whatever rate was last persisted for it instead of being nulled out. A
        // transient fetch failure must not be indistinguishable from "no data".
        $previousRates = $failedWindows !== []
            ? DB::table('smart_sata_attributes')
                ->where('app_id', $this->appId)
                ->where('disk_key', $diskKey)
                ->get(['attribute_id', 'rate_8h', 'rate_24h', 'rate_168h', 'rate_672h'])
                ->keyBy('attribute_id')
            : collect();

        foreach ($attrRows as $attrId => $row) {
            $id = (int) ($row['smartmonSataAttrId'] ?? $attrId);
            $ds = $dsByAttrId[$id] ?? null;
            $previous = $previousRates->get($id);
            // No trackable single-valued dataset for this attribute's format (e.g. raw8/raw16
            // split into independent id{N}P0..P5 byte/word counters) -- leave rates null rather
            // than guessing at a combined value.
            $rates = $ds === null ? ['8h' => null, '24h' => null, '168h' => null, '672h' => null] : [
                '8h' => $ratesByDs[$ds]['8h'] ?? ($failedWindows['8h'] ?? false ? $previous?->rate_8h : null),
                '24h' => $ratesByDs[$ds]['24h'] ?? ($failedWindows['24h'] ?? false ? $previous?->rate_24h : null),
                '168h' => $ratesByDs[$ds]['168h'] ?? ($failedWindows['168h'] ?? false ? $previous?->rate_168h : null),
                '672h' => $ratesByDs[$ds]['672h'] ?? ($failedWindows['672h'] ?? false ? $previous?->rate_672h : null),
            ];
            $rawStatus = $this->intValue($row['smartmonSataAttrStatus'] ?? null);
            $rateStatus = $this->resolveRateStatus($thresholdRows, $id, $rates);

            $this->upsert('smart_sata_attributes', [
                'app_id'       => $this->appId,
                'device_id'    => $this->deviceId,
                'disk_key'     => $diskKey,
                'attribute_id' => $id,
                'rate_8h'      => $rates['8h'],
                'rate_24h'     => $rates['24h'],
                'rate_168h'    => $rates['168h'],
                'rate_672h'    => $rates['672h'],
                'status'       => $this->combineStatus($rawStatus, $rateStatus),
                'rate_status'  => $rateStatus,
            ], ['app_id', 'disk_key', 'attribute_id']);
        }
    }

    /**
     * The single RRD dataset name to track for rate-of-change, or null if this
     * attribute's format has no one meaningful value to trend. Mirrors the DS
     * naming pollSataDeviceRrd() actually writes: single-value formats keep the
     * base id{N}; div formats (raw24div24/32) track id{N}Hi; multi-part formats
     * (raw8/raw16/raw16raw16/raw24raw8 -> id{N}P0..P5) have no single counter to
     * point at, so rate tracking is skipped for them.
     */
    private function rateDsForAttribute(int $id, array $row): ?string
    {
        $format = $this->intValue($row['smartmonSataAttrFormat'] ?? null);
        $rawString = $row['smartmonSataAttrRawString'] ?? null;
        $subValues = $this->attrFormatSubValues($format, $rawString);

        if ($subValues !== []) {
            return array_key_exists('Hi', $subValues) ? 'id' . $id . 'Hi' : null;
        }

        return 'id' . $id;
    }

    /**
     * Average change per hour, per RRD dataset, for every lookback window.
     *
     * Every dataset for a given window is fetched in ONE batched rrdtool call
     * (Rrd::getWindowAverages() takes the whole dataset list). Each call
     * spawns a separate rrdtool subprocess, so this is 4 calls for all COUNTER
     * datasets plus 8 for all GAUGE datasets (2 boundary probes x 4 windows),
     * regardless of how many SMART attributes the disk has. Looping a single
     * dataset per call here previously spawned one subprocess per attribute
     * per window, which exhausted the open-file limit on disks with 30+
     * attributes.
     *
     * @param  array<string>  $counterDs
     * @param  array<string>  $gaugeDs
     * @return array{0: array<string, array<string, float>>, 1: array<string, bool>} [dataset => window suffix => rate, window suffix => fetch failed]
     */
    private function fetchAttributeRates(Rrd $rrd, string $filename, array $counterDs, array $gaugeDs, int $now): array
    {
        $ratesByDs = [];
        $failedWindows = [];
        $probe = 600; // 10 minutes, well above the default 5-minute poll step

        foreach (self::RATE_WINDOWS as $suffix => $seconds) {
            $start = $now - $seconds;
            $hours = $seconds / 3600;

            if ($counterDs !== []) {
                $counterRates = $rrd->getWindowAverages($filename, $counterDs, $start, $now);
                if ($counterRates === null) {
                    $failedWindows[$suffix] = true;
                    Log::error("smart_mib: fetchAttributeRates: counter fetch FAILED for window={$suffix} file={$filename}, keeping previously persisted rates for this window");
                } else {
                    foreach ($counterRates as $ds => $perSecond) {
                        $ratesByDs[$ds][$suffix] = $perSecond * 3600;
                    }
                }
            }

            if ($gaugeDs !== []) {
                $startVals = $rrd->getWindowAverages($filename, $gaugeDs, $start, min($start + $probe, $now));
                $endVals = $rrd->getWindowAverages($filename, $gaugeDs, max($now - $probe, $start), $now);
                if ($startVals === null || $endVals === null) {
                    $failedWindows[$suffix] = true;
                    Log::error("smart_mib: fetchAttributeRates: gauge fetch FAILED for window={$suffix} file={$filename}, keeping previously persisted rates for this window");
                } else {
                    foreach ($gaugeDs as $ds) {
                        if (isset($startVals[$ds], $endVals[$ds])) {
                            $ratesByDs[$ds][$suffix] = ($endVals[$ds] - $startVals[$ds]) / $hours;
                        }
                    }
                }
            }
        }

        return [$ratesByDs, $failedWindows];
    }

    /**
     * Fold rate_status into the displayed `status`: a rate-of-change breach (rate_status=2)
     * escalates status to 4 (Rate exceeded) even if the device itself reports the attribute
     * fine. A device-reported notRelevant(-1), meaning the disk has no failure threshold
     * for this attribute, is treated as ok(1) once a rate-of-change threshold is enabled and
     * not breached, since the rate threshold then stands in for the missing device one.
     */
    private function combineStatus(?int $rawStatus, int $rateStatus): ?int
    {
        if ($rateStatus === 2) {
            return 4;
        }

        if ($rawStatus === -1 && $rateStatus === 1) {
            return 1;
        }

        return $rawStatus;
    }

    /**
     * Resolve smart_sata_attributes.rate_status for one attribute: -1 (no rate-of-change
     * threshold enabled for this disk/attribute), 1 (enabled, no window exceeds it), or
     * 2 (enabled, at least one window exceeds it). Independent of the device-reported
     * `status` column, so polling and discovery never fight over the same field.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $thresholdRows  this disk's rows, from loadThresholdRows()
     */
    private function resolveRateStatus(\Illuminate\Support\Collection $thresholdRows, int $attrId, array $rates): int
    {
        $rows = $thresholdRows->where('attribute_id', $attrId);
        $diskRow = $rows->firstWhere('disk_key', '!=', '');
        $globalRow = $rows->firstWhere('disk_key', '');

        // Per-disk override decides alerting on/off when present; otherwise the global
        // default's switch applies. Muting here short-circuits before any limit check,
        // so a configured warn_rate_* never alerts while its row is switched off.
        $alertEnabled = (bool) (($diskRow->alert_enabled ?? null) ?? ($globalRow->alert_enabled ?? null) ?? true);
        if (! $alertEnabled) {
            return -1;
        }

        $limits = $this->effectiveLimits($thresholdRows, $attrId);
        if (! $this->hasEnabledThreshold($limits)) {
            return -1;
        }

        return $this->rateExceedsThreshold($limits, $rates) ? 2 : 1;
    }

    /**
     * Every smart_attribute_thresholds row that can apply to this disk: its own per-disk
     * overrides plus every global-default row (app_id=0, disk_key=''). Fetched once per
     * disk so effectiveLimits() can look up a given attribute_id in memory rather than
     * re-querying per attribute. This runs in the poller hot path.
     */
    private function loadThresholdRows(string $diskKey): \Illuminate\Support\Collection
    {
        return DB::table('smart_attribute_thresholds')
            ->where(function ($q) use ($diskKey) {
                $q->where(['app_id' => $this->appId, 'disk_key' => $diskKey])
                    ->orWhere(['app_id' => 0, 'disk_key' => '']);
            })
            ->get();
    }

    /**
     * Effective rate-of-change limit per window, merged column-by-column: the per-disk
     * override wins for a given window only when it's actually enabled there; otherwise
     * that window falls back to the global default. A single ::first() pick between the
     * two rows would let an override with no enabled windows fully shadow a configured
     * global default, instead of falling back to it.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $thresholdRows  this disk's rows, from loadThresholdRows()
     * @return array<string, float|null> window suffix (8h/24h/168h/672h) => limit
     */
    private function effectiveLimits(\Illuminate\Support\Collection $thresholdRows, int $attrId): array
    {
        $rows = $thresholdRows->where('attribute_id', $attrId);
        $diskRow = $rows->firstWhere('disk_key', '!=', '');
        $globalRow = $rows->firstWhere('disk_key', '');

        $limits = [];
        foreach (['8h' => 'warn_rate_8h', '24h' => 'warn_rate_24h', '168h' => 'warn_rate_168h', '672h' => 'warn_rate_672h'] as $suffix => $column) {
            $limits[$suffix] = ($diskRow !== null ? $this->thresholdLimit($diskRow, $column) : null)
                ?? ($globalRow !== null ? $this->thresholdLimit($globalRow, $column) : null);
        }

        return $limits;
    }

    /**
     * A configured warn_rate_* limit, or null if unset/0. 0 means "no limit" (disabled),
     * not "warn on any change", so it must not be treated as an active threshold.
     */
    private function thresholdLimit(object $threshold, string $column): ?float
    {
        $value = $threshold->$column ?? null;

        return $value !== null && (float) $value > 0 ? (float) $value : null;
    }

    /** True if any window has an enabled rate-of-change limit. */
    private function hasEnabledThreshold(array $limits): bool
    {
        foreach ($limits as $limit) {
            if ($limit !== null) {
                return true;
            }
        }

        return false;
    }

    /** True if any rate window exceeds its effective limit. */
    private function rateExceedsThreshold(array $limits, array $rates): bool
    {
        foreach ($limits as $suffix => $limit) {
            $rate = $rates[$suffix] ?? null;
            if ($limit !== null && $rate !== null && abs($rate) > $limit) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update the poll-relevant attribute columns; discovery keeps the rest (the rate_*
     * columns themselves, which need a fresh RRD fetch to recompute).
     *
     * rate_status is still re-evaluated on every poll: it's cheap (just a comparison
     * against the rate_8h/24h/168h/672h values discovery already persisted) and keeps
     * an attribute's rate-warning verdict current between discovery runs, e.g. once
     * thresholds are edited via the settings page.
     */
    private function syncSataAttributeRowsPoll(array $dev, array $attrRows): void
    {
        $diskKey = $dev['disk_key'];

        $existingRates = DB::table('smart_sata_attributes')
            ->where('app_id', $this->appId)
            ->where('disk_key', $diskKey)
            ->get(['attribute_id', 'rate_8h', 'rate_24h', 'rate_168h', 'rate_672h'])
            ->keyBy('attribute_id');
        $thresholdRows = $this->loadThresholdRows($diskKey);

        foreach ($attrRows as $attrId => $row) {
            $id = (int) $attrId;
            $existing = $existingRates->get($id);
            $rates = [
                '8h' => $existing->rate_8h ?? null,
                '24h' => $existing->rate_24h ?? null,
                '168h' => $existing->rate_168h ?? null,
                '672h' => $existing->rate_672h ?? null,
            ];
            $rawStatus = $this->intValue($row['smartmonSataAttrStatus'] ?? null);
            $rateStatus = $this->resolveRateStatus($thresholdRows, $id, $rates);

            $this->upsert('smart_sata_attributes', [
                'app_id'           => $this->appId,
                'device_id'        => $this->deviceId,
                'disk_key'         => $diskKey,
                'attribute_id'     => $id,
                'value_norm'       => $row['smartmonSataAttrValue'] ?? null,
                'value_raw'        => $row['smartmonSataAttrRawValue'] ?? null,
                'value_raw_string' => isset($row['smartmonSataAttrRawString'])
                    ? substr((string) $row['smartmonSataAttrRawString'], 0, 32)
                    : null,
                'status'           => $this->combineStatus($rawStatus, $rateStatus),
                'rate_status'      => $rateStatus,
            ], ['app_id', 'disk_key', 'attribute_id']);
        }
    }

    private function syncSataErcRows(array $dev, array $rows): void
    {
        foreach ($rows as $direction => $row) {
            $this->upsert('smart_sata_erc', [
                'app_id'      => $this->appId,
                'device_id'   => $this->deviceId,
                'disk_key'    => $dev['disk_key'],
                'direction'   => (int) $direction,
                'enabled'     => $this->snmpTruthValue($row['smartmonSataErcEnabled'] ?? null),
                'deciseconds' => $row['smartmonSataErcDeciseconds'] ?? null,
            ], ['app_id', 'disk_key', 'direction']);
        }
        $this->pruneStaleRows('smart_sata_erc', $dev['disk_key'], 'direction', array_keys($rows));
    }

    /** Full discovery sync: name + size_bytes + value + overflow. */
    private function syncSataPhyEventRows(array $dev, array $rows): void
    {
        foreach ($rows as $eventId => $row) {
            $this->upsert('smart_sata_phy_events', [
                'app_id'     => $this->appId,
                'device_id'  => $this->deviceId,
                'disk_key'   => $dev['disk_key'],
                'event_id'   => (int) $eventId,
                'name'       => isset($row['smartmonSataPhyEventName'])
                    ? substr((string) $row['smartmonSataPhyEventName'], 0, 128) : null,
                'size_bytes' => $row['smartmonSataPhyEventSize'] ?? null,
                'value'      => $row['smartmonSataPhyEventValue'] ?? null,
                'overflow'   => $this->snmpTruthValue($row['smartmonSataPhyEventOverflow'] ?? null),
            ], ['app_id', 'disk_key', 'event_id']);
        }
        $this->pruneStaleRows('smart_sata_phy_events', $dev['disk_key'], 'event_id', array_keys($rows));
    }

    /** Poll-only update: value + overflow, no name/size walk needed. */
    private function syncSataPhyEventValueRows(array $dev, array $rows): void
    {
        $upsertRows = [];
        foreach ($rows as $eventId => $row) {
            $upsertRows[] = [
                'app_id'    => $this->appId,
                'device_id' => $this->deviceId,
                'disk_key'  => $dev['disk_key'],
                'event_id'  => (int) $eventId,
                'value'     => $row['smartmonSataPhyEventValue'] ?? null,
                'overflow'  => $this->snmpTruthValue($row['smartmonSataPhyEventOverflow'] ?? null),
            ];
        }
        if (! empty($upsertRows)) {
            $this->upsert('smart_sata_phy_events', $upsertRows, ['app_id', 'disk_key', 'event_id']);
        }
    }

    private function syncSataErrorLogRows(array $dev, array $rows): void
    {
        foreach ($rows as $errorIndex => $row) {
            $this->upsert('smart_sata_error_log', [
                'app_id'          => $this->appId,
                'device_id'       => $this->deviceId,
                'disk_key'        => $dev['disk_key'],
                'entry_num'       => (int) $errorIndex,
                'error_count'     => $row['smartmonSataErrorNumber'] ?? null,
                'lifetime_hours'  => $row['smartmonSataErrorLifetimeHours'] ?? null,
                'error_type'      => isset($row['smartmonSataErrorDescription'])
                    ? substr((string) $row['smartmonSataErrorDescription'], 0, 64) : null,
                'device_state'    => $row['smartmonSataErrorState'] ?? null,
                'status_register' => $row['smartmonSataErrorCompRegStatus'] ?? null,
                'error_register'  => $row['smartmonSataErrorCompRegError'] ?? null,
            ], ['app_id', 'disk_key', 'entry_num']);
        }
        $this->pruneStaleRows('smart_sata_error_log', $dev['disk_key'], 'entry_num', array_keys($rows));
    }

    private function syncSataErrorCmdRows(array $dev, array $rows): void
    {
        foreach ($rows as $errorIndex => $cmdRows) {
            if (! is_array($cmdRows)) {
                continue;
            }
            foreach ($cmdRows as $cmdIndex => $row) {
                $this->upsert('smart_sata_error_cmd', [
                    'app_id'          => $this->appId,
                    'device_id'       => $this->deviceId,
                    'disk_key'        => $dev['disk_key'],
                    'error_entry_num' => (int) $errorIndex,
                    'cmd_slot'        => (int) $cmdIndex,
                    'reg_command'     => $row['smartmonSataErrorCmdRegCommand'] ?? null,
                    'reg_count'       => $row['smartmonSataErrorCmdRegCount'] ?? null,
                    'reg_device'      => $row['smartmonSataErrorCmdRegDevice'] ?? null,
                    'reg_error'       => $row['smartmonSataErrorCmdRegError'] ?? null,
                    'reg_feature'     => $row['smartmonSataErrorCmdRegFeature'] ?? null,
                    'reg_lba'         => $row['smartmonSataErrorCmdRegLba'] ?? null,
                    'powerup_ms'      => $row['smartmonSataErrorCmdTimestamp'] ?? null,
                    'description'     => isset($row['smartmonSataErrorCmdDescription'])
                        ? substr((string) $row['smartmonSataErrorCmdDescription'], 0, 128) : null,
                ], ['app_id', 'disk_key', 'error_entry_num', 'cmd_slot']);
            }
            $this->pruneStaleRows('smart_sata_error_cmd', $dev['disk_key'], 'cmd_slot', array_keys($cmdRows), ['error_entry_num' => (int) $errorIndex]);
        }
        $this->pruneStaleRows('smart_sata_error_cmd', $dev['disk_key'], 'error_entry_num', array_keys($rows));
    }

    private function syncSataSelfTestRows(array $dev, array $rows): void
    {
        foreach ($rows as $testIndex => $row) {
            $this->upsert('smart_sata_selftest_log', [
                'app_id'          => $this->appId,
                'device_id'       => $this->deviceId,
                'disk_key'        => $dev['disk_key'],
                'entry_num'       => (int) $testIndex,
                'test_type'       => $row['smartmonSataSelfTestType'] ?? null,
                'result'          => $row['smartmonSataSelfTestResult'] ?? null,
                'result_passed'   => $this->snmpTruthValue($row['smartmonSataSelfTestResultPassed'] ?? null),
                'remaining_pct'   => $row['smartmonSataSelfTestRemainingPct'] ?? null,
                'power_on_hours'  => $row['smartmonSataSelfTestLifetimeHours'] ?? null,
                'lba_first_error' => $row['smartmonSataSelfTestLbaFirstError'] ?? null,
            ], ['app_id', 'disk_key', 'entry_num']);
        }
        $this->pruneStaleRows('smart_sata_selftest_log', $dev['disk_key'], 'entry_num', array_keys($rows));
    }

    private function syncSataSelectiveTestRows(array $dev, array $rows): void
    {
        foreach ($rows as $slot => $row) {
            $this->upsert('smart_sata_selective_test', [
                'app_id'       => $this->appId,
                'device_id'    => $this->deviceId,
                'disk_key'     => $dev['disk_key'],
                'slot'         => (int) $slot,
                'lba_min'      => $row['smartmonSataSelectiveLbaMin'] ?? null,
                'lba_max'      => $row['smartmonSataSelectiveLbaMax'] ?? null,
                'status_value' => $row['smartmonSataSelectiveStatusValue'] ?? null,
            ], ['app_id', 'disk_key', 'slot']);
        }
        $this->pruneStaleRows('smart_sata_selective_test', $dev['disk_key'], 'slot', array_keys($rows));
    }

    private function syncSataLogDirRows(array $dev, array $rows): void
    {
        foreach ($rows as $address => $row) {
            $this->upsert('smart_sata_log_dir', [
                'app_id'        => $this->appId,
                'device_id'     => $this->deviceId,
                'disk_key'      => $dev['disk_key'],
                'log_address'   => (int) $address,
                'name'          => isset($row['smartmonSataLogDirName'])
                    ? substr((string) $row['smartmonSataLogDirName'], 0, 128) : null,
                'readable'      => $this->snmpTruthValue($row['smartmonSataLogDirReadable'] ?? null),
                'writable'      => $this->snmpTruthValue($row['smartmonSataLogDirWritable'] ?? null),
                'gp_sectors'    => $row['smartmonSataLogDirGpSectors'] ?? null,
                'smart_sectors' => $row['smartmonSataLogDirSmartSectors'] ?? null,
            ], ['app_id', 'disk_key', 'log_address']);
        }
        $this->pruneStaleRows('smart_sata_log_dir', $dev['disk_key'], 'log_address', array_keys($rows));
    }

    /**
     * Full discovery sync: page_name + stat_name + value + flags (with derived valid/normalized).
     * Poll uses walkAndSyncSataDevStatPoll() which only updates value.
     */
    private function syncSataDevStatRows(array $dev, array $rows): void
    {
        foreach ($rows as $pageNum => $offsets) {
            if (! is_array($offsets)) {
                continue;
            }
            foreach ($offsets as $offset => $row) {
                $flagsRaw = $this->parseBitsValue($row['smartmonSataDevStatFlagsValue'] ?? null);
                $valid = $flagsRaw !== null ? (bool) ($flagsRaw & 0x40) : null;
                $normalized = $flagsRaw !== null ? (bool) ($flagsRaw & 0x20) : null;

                $this->upsert('smart_sata_dev_stats', [
                    'app_id'      => $this->appId,
                    'device_id'   => $this->deviceId,
                    'disk_key'    => $dev['disk_key'],
                    'page_num'    => (int) $pageNum,
                    'stat_offset' => (int) $offset,
                    'page_name'   => isset($row['smartmonSataDevStatPageName'])
                        ? substr((string) $row['smartmonSataDevStatPageName'], 0, 64) : null,
                    'stat_name'   => isset($row['smartmonSataDevStatName'])
                        ? substr((string) $row['smartmonSataDevStatName'], 0, 128) : null,
                    'value'       => $row['smartmonSataDevStatValue'] ?? null,
                    'flags_value' => $flagsRaw,
                    'valid'       => $valid,
                    'normalized'  => $normalized,
                ], ['app_id', 'disk_key', 'page_num', 'stat_offset']);
            }
            $this->pruneStaleRows('smart_sata_dev_stats', $dev['disk_key'], 'stat_offset', array_keys($offsets), ['page_num' => (int) $pageNum]);
        }
        $this->pruneStaleRows('smart_sata_dev_stats', $dev['disk_key'], 'page_num', array_keys($rows));
    }

    private function syncSataPendingDefectRows(array $dev, array $rows): void
    {
        foreach ($rows as $entryIndex => $row) {
            $this->upsert('smart_sata_pending_defects', [
                'app_id'    => $this->appId,
                'device_id' => $this->deviceId,
                'disk_key'  => $dev['disk_key'],
                'entry_num' => (int) $entryIndex,
                'lba'       => $row['smartmonSataPendingDefectsLba'] ?? null,
            ], ['app_id', 'disk_key', 'entry_num']);
        }
        $this->pruneStaleRows('smart_sata_pending_defects', $dev['disk_key'], 'entry_num', array_keys($rows));
    }

    // ── NVMe database sync ──────────────────────────────────────────────────────

    private function syncNvmeInfoRow(array $dev, array $row): void
    {
        $this->upsert('smart_nvme_info', [
            'app_id'                         => $this->appId,
            'device_id'                      => $this->deviceId,
            'disk_key'                       => $dev['disk_key'],
            'pci_vendor_id'                  => $this->intValue($row['smartmonNvmePciVendorId'] ?? null),
            'pci_device_id'                  => $this->intValue($row['smartmonNvmePciVendorSubsystemId'] ?? null),
            'ieee_oui'                       => $this->intValue($row['smartmonNvmeIeeeOuiIdentifier'] ?? null),
            'total_nvm_capacity_bytes'       => $this->intValue($row['smartmonNvmeTotalNvmCapacityBytes'] ?? null),
            'unallocated_nvm_capacity_bytes' => $this->intValue($row['smartmonNvmeUnallocatedNvmCapacityBytes'] ?? null),
            'controller_id'                  => $this->intValue($row['smartmonNvmeControllerId'] ?? null),
            'nvme_version'                   => $row['smartmonNvmeVersion'] ?? null,
            'namespace_count'                => $this->intValue($row['smartmonNvmeNamespaceCount'] ?? null),
            'max_data_transfer_pages'        => $this->intValue($row['smartmonNvmeMaximumDataTransferPages'] ?? null),
            'link_power_state'               => $this->intValue($row['smartmonNvmeLinkPowerState'] ?? null),
            'max_link_speed'                 => $this->intValue($row['smartmonNvmeMaxLinkSpeed'] ?? null),
            'max_link_width'                 => $this->intValue($row['smartmonNvmeMaxLinkWidth'] ?? null),
            'current_link_speed'             => $this->intValue($row['smartmonNvmeCurrentLinkSpeed'] ?? null),
            'current_link_width'             => $this->intValue($row['smartmonNvmeCurrentLinkWidth'] ?? null),
        ], ['app_id', 'disk_key']);
    }

    private function syncNvmeHealthRow(array $dev, array $row): void
    {
        // Current self-test: OperationValue is the operation enum (0=none, 1=short,
        // 2=extended, 14=vendor); OperationProgress is the completion percentage.
        $selftestOp = $this->intValue($row['smartmonNvmeCurrentSelfTestOperationValue'] ?? null);

        $this->upsert('smart_nvme_health', [
            'app_id'               => $this->appId,
            'device_id'            => $this->deviceId,
            'disk_key'             => $dev['disk_key'],
            'overall_status'       => $this->healthStatusValue($row['smartmonNvmeHealthOverallStatus'] ?? null),
            'critical_warning'     => $this->parseBitsValue($row['smartmonNvmeCriticalWarning'] ?? null),
            'data_units_read'      => $this->intValue($row['smartmonNvmeDataUnitsRead'] ?? null),
            'data_units_written'   => $this->intValue($row['smartmonNvmeDataUnitsWritten'] ?? null),
            'data_bytes_read'      => $this->intValue($row['smartmonNvmeDataBytesRead'] ?? null),
            'data_bytes_written'   => $this->intValue($row['smartmonNvmeDataBytesWritten'] ?? null),
            'host_read_commands'   => $this->intValue($row['smartmonNvmeHostReadCommands'] ?? null),
            'host_write_commands'  => $this->intValue($row['smartmonNvmeHostWriteCommands'] ?? null),
            'controller_busy_time' => $this->intValue($row['smartmonNvmeControllerBusyTimeMinutes'] ?? null),
            'power_cycles'         => $this->intValue($row['smartmonNvmePowerCycles'] ?? null),
            'power_on_hours'       => $this->intValue($row['smartmonNvmePowerOnHours'] ?? null),
            'unsafe_shutdowns'     => $this->intValue($row['smartmonNvmeUnsafeShutdowns'] ?? null),
            'media_errors'         => $this->intValue($row['smartmonNvmeMediaDataIntegrityErrors'] ?? null),
            'num_err_log_entries'  => $this->intValue($row['smartmonNvmeErrorInformationLogEntries'] ?? null),
            'warning_temp_time'    => $this->intValue($row['smartmonNvmeWarningTemperatureTimeMinutes'] ?? null),
            'critical_comp_time'   => $this->intValue($row['smartmonNvmeCriticalTemperatureTimeMinutes'] ?? null),
            'current_selftest_op'  => $selftestOp,
            'current_selftest_str' => $this->nvmeSelfTestOpLabel($selftestOp),
            'current_selftest_pct' => $this->intValue($row['smartmonNvmeCurrentSelfTestOperationProgress'] ?? null),
        ], ['app_id', 'disk_key']);
    }

    private function syncNvmeNamespaceRows(array $dev, array $rows): void
    {
        foreach ($rows as $nsId => $row) {
            $this->upsert('smart_nvme_namespaces', [
                'app_id'        => $this->appId,
                'device_id'     => $this->deviceId,
                'disk_key'      => $dev['disk_key'],
                'ns_id'         => (int) $nsId,
                'nsze'          => $this->intValue($row['smartmonNvmeNamespaceSizeBlocks'] ?? null),
                'ncap'          => $this->intValue($row['smartmonNvmeNamespaceCapacityBlocks'] ?? null),
                'nuse'          => $this->intValue($row['smartmonNvmeNamespaceUtilizationBlocks'] ?? null),
                'lba_data_size' => $this->intValue($row['smartmonNvmeNamespaceFormattedLbaSizeBytes'] ?? null),
            ], ['app_id', 'disk_key', 'ns_id']);
        }
        $this->pruneStaleRows('smart_nvme_namespaces', $dev['disk_key'], 'ns_id', array_keys($rows));
    }

    private function syncNvmeSelfTestRows(array $dev, array $rows): void
    {
        foreach ($rows as $entryIndex => $row) {
            $this->upsert('smart_nvme_selftest_log', [
                'app_id'               => $this->appId,
                'device_id'            => $this->deviceId,
                'disk_key'             => $dev['disk_key'],
                'entry_num'            => (int) $entryIndex,
                'test_type'            => $this->intValue($row['smartmonNvmeSelfTestType'] ?? null),
                'result'               => $this->intValue($row['smartmonNvmeSelfTestResult'] ?? null),
                'result_text'          => isset($row['smartmonNvmeSelfTestResultText'])
                    ? substr((string) $row['smartmonNvmeSelfTestResultText'], 0, 96) : null,
                'power_on_hours'       => $this->intValue($row['smartmonNvmeSelfTestPowerOnHours'] ?? null),
                'failing_lba'          => $this->intValue($row['smartmonNvmeSelfTestFailingLba'] ?? null),
                'nsid'                 => $this->intValue($row['smartmonNvmeSelfTestNamespaceId'] ?? null),
                'estimated_completion' => $this->parseDateAndTime($row['smartmonNvmeSelfTestEstimatedCompletionTime'] ?? null),
            ], ['app_id', 'disk_key', 'entry_num']);
        }
        $this->pruneStaleRows('smart_nvme_selftest_log', $dev['disk_key'], 'entry_num', array_keys($rows));
    }

    private function syncNvmePowerStateRows(array $dev, array $rows): void
    {
        foreach ($rows as $stateId => $row) {
            $this->upsert('smart_nvme_power_states', [
                'app_id'                => $this->appId,
                'device_id'             => $this->deviceId,
                'disk_key'              => $dev['disk_key'],
                'state_id'              => (int) $stateId,
                'operational'           => $this->snmpTruthValue($row['smartmonNvmePowerStateOperational'] ?? null),
                'max_power_mw'          => $this->intValue($row['smartmonNvmePowerStateMaxPowerMilliWatts'] ?? null),
                'active_power_mw'       => $this->intValue($row['smartmonNvmePowerStateActivePowerMilliWatts'] ?? null),
                'idle_power_mw'         => $this->intValue($row['smartmonNvmePowerStateIdlePowerMilliWatts'] ?? null),
                'read_latency_rank'     => $this->intValue($row['smartmonNvmePowerStateReadLatencyRank'] ?? null),
                'read_throughput_rank'  => $this->intValue($row['smartmonNvmePowerStateReadThroughputRank'] ?? null),
                'write_latency_rank'    => $this->intValue($row['smartmonNvmePowerStateWriteLatencyRank'] ?? null),
                'write_throughput_rank' => $this->intValue($row['smartmonNvmePowerStateWriteThroughputRank'] ?? null),
                'entry_latency_us'      => $this->intValue($row['smartmonNvmePowerStateEntryLatencyUsec'] ?? null),
                'exit_latency_us'       => $this->intValue($row['smartmonNvmePowerStateExitLatencyUsec'] ?? null),
            ], ['app_id', 'disk_key', 'state_id']);
        }
        $this->pruneStaleRows('smart_nvme_power_states', $dev['disk_key'], 'state_id', array_keys($rows));
    }

    /** LBA formats are indexed per namespace: $nsFormats = [nsId => [formatId => row]]. */
    private function syncNvmeLbaFormatRows(array $dev, array $nsFormats): void
    {
        foreach ($nsFormats as $nsId => $formats) {
            if (! is_array($formats)) {
                continue;
            }
            foreach ($formats as $formatId => $row) {
                $this->upsert('smart_nvme_lba_formats', [
                    'app_id'               => $this->appId,
                    'device_id'            => $this->deviceId,
                    'disk_key'             => $dev['disk_key'],
                    'ns_id'                => (int) $nsId,
                    'format_id'            => (int) $formatId,
                    'current'              => $this->snmpTruthValue($row['smartmonNvmeLbaFormatCurrent'] ?? null),
                    'data_size_bytes'      => $this->intValue($row['smartmonNvmeLbaFormatDataSizeBytes'] ?? null),
                    'metadata_size_bytes'  => $this->intValue($row['smartmonNvmeLbaFormatMetadataSizeBytes'] ?? null),
                    'relative_performance' => $this->intValue($row['smartmonNvmeLbaFormatRelativePerformance'] ?? null),
                ], ['app_id', 'disk_key', 'ns_id', 'format_id']);
            }
            $this->pruneStaleRows('smart_nvme_lba_formats', $dev['disk_key'], 'format_id', array_keys($formats), ['ns_id' => (int) $nsId]);
        }
        $this->pruneStaleRows('smart_nvme_lba_formats', $dev['disk_key'], 'ns_id', array_keys($nsFormats));
    }

    private function syncNvmeErrorLogRows(array $dev, array $rows): void
    {
        foreach ($rows as $entryIndex => $row) {
            $this->upsert('smart_nvme_error_log', [
                'app_id'               => $this->appId,
                'device_id'            => $this->deviceId,
                'disk_key'             => $dev['disk_key'],
                'entry_num'            => (int) $entryIndex,
                'error_count'          => $this->intValue($row['smartmonNvmeErrorCount'] ?? null),
                'sq_id'                => $this->intValue($row['smartmonNvmeErrorSubmissionQueueId'] ?? null),
                'command_id'           => $this->intValue($row['smartmonNvmeErrorCommandId'] ?? null),
                'status_field'         => $this->intValue($row['smartmonNvmeErrorStatusField'] ?? null),
                'param_error_location' => $this->intValue($row['smartmonNvmeErrorParameterErrorLocation'] ?? null),
                'lba'                  => $this->intValue($row['smartmonNvmeErrorLba'] ?? null),
                'ns_id'                => $this->intValue($row['smartmonNvmeErrorNamespaceId'] ?? null),
                'vendor_info'          => $this->intValue($row['smartmonNvmeErrorVendorSpecificInfo'] ?? null),
                'status_code'          => $this->intValue($row['smartmonNvmeErrorStatusCode'] ?? null),
                'status_code_type'     => $this->intValue($row['smartmonNvmeErrorStatusCodeType'] ?? null),
                'do_not_retry'         => $this->snmpTruthValue($row['smartmonNvmeErrorDoNotRetry'] ?? null),
                'status_string'        => isset($row['smartmonNvmeErrorStatusString'])
                    ? substr((string) $row['smartmonNvmeErrorStatusString'], 0, 128) : null,
                'error_time'           => $this->parseDateAndTime($row['smartmonNvmeErrorTimestamp'] ?? null),
            ], ['app_id', 'disk_key', 'entry_num']);
        }
        $this->pruneStaleRows('smart_nvme_error_log', $dev['disk_key'], 'entry_num', array_keys($rows));
    }

    private function syncNvmeCapabilityRow(array $dev, array $row): void
    {
        $this->upsert('smart_nvme_capability', [
            'app_id'                  => $this->appId,
            'device_id'               => $this->deviceId,
            'disk_key'                => $dev['disk_key'],
            'firmware_update_raw'     => $this->intValue($row['smartmonNvmeFirmwareUpdateRaw'] ?? null),
            'firmware_slot_count'     => $this->intValue($row['smartmonNvmeFirmwareSlotCount'] ?? null),
            'firmware_reset_required' => $this->snmpTruthValue($row['smartmonNvmeFirmwareResetRequired'] ?? null),
            'optional_admin_cmd_raw'  => $this->bitsValue($row['smartmonNvmeOptionalAdminCommandRaw'] ?? null),
            'optional_nvm_cmd_raw'    => $this->bitsValue($row['smartmonNvmeOptionalNvmCommandRaw'] ?? null),
            'log_page_attrs_raw'      => $this->bitsValue($row['smartmonNvmeLogPageAttributesRaw'] ?? null),
            'optional_admin_cmd_text' => isset($row['smartmonNvmeOptionalAdminCommandText'])
                ? substr((string) $row['smartmonNvmeOptionalAdminCommandText'], 0, 255) : null,
            'optional_nvm_cmd_text'   => isset($row['smartmonNvmeOptionalNvmCommandText'])
                ? substr((string) $row['smartmonNvmeOptionalNvmCommandText'], 0, 255) : null,
            'log_page_attrs_text'     => isset($row['smartmonNvmeLogPageAttributesText'])
                ? substr((string) $row['smartmonNvmeLogPageAttributesText'], 0, 255) : null,
        ], ['app_id', 'disk_key']);
    }

    /** Poll-time narrowed walk: value + overflow only (name/size already in DB from discovery). */
    private function walkAndSyncSataPhyEventPoll(): void
    {
        $this->sataChangeByDeviceTable();
        if (! Debug::isVerbose() && ! $this->anySataDeviceChangedForTable(self::SATA_TID_PHY_EVENT)) {
            return;
        }

        $valueRows = $this->walkSataTable('smartmonSataPhyEventValue', 2);
        $overflowRows = $this->walkSataTable('smartmonSataPhyEventOverflow', 2);

        foreach ($this->sataDeviceList as $devIdx => $dev) {
            if (! $this->sataTableChangedForDevice((string) $devIdx, self::SATA_TID_PHY_EVENT)) {
                continue;
            }
            $merged = [];
            foreach ($valueRows[(string) $devIdx] ?? [] as $eventId => $value) {
                $merged[(string) $eventId] = [
                    'smartmonSataPhyEventValue'    => $this->leafValue($value, 'smartmonSataPhyEventValue'),
                    'smartmonSataPhyEventOverflow' => $this->leafValue($overflowRows[(string) $devIdx][$eventId] ?? null, 'smartmonSataPhyEventOverflow'),
                ];
            }
            $this->syncSataPhyEventValueRows($dev, $merged);
        }
    }

    /**
     * Poll-time narrowed walk for DevStat: only value column, with two-level change guards
     * (device-level via sataChangeByDeviceTable, page-level via sataSubindexChangeRows).
     */
    private function walkAndSyncSataDevStatPoll(): void
    {
        $this->sataChangeByDeviceTable();
        if (! Debug::isVerbose() && ! $this->anySataDeviceChangedForTable(self::SATA_TID_DEV_STAT)) {
            return;
        }

        // Single walk for all devices; depth=3 gives [devIdx][pageNum][offset] => value.
        $allValueRows = $this->walkSataTable('smartmonSataDevStatValue', 3, true);

        foreach ($this->sataDeviceList as $devIdx => $dev) {
            if (! $this->sataTableChangedForDevice((string) $devIdx, self::SATA_TID_DEV_STAT)) {
                continue;
            }
            $upsertRows = [];
            foreach ($allValueRows[(string) $devIdx] ?? [] as $pageNum => $offsets) {
                if (! Debug::isVerbose() && ! $this->sataTableChangedForDevicePage((string) $devIdx, self::SATA_TID_DEV_STAT, (int) $pageNum)) {
                    continue;
                }
                foreach ($offsets as $offset => $value) {
                    $upsertRows[] = [
                        'app_id'      => $this->appId,
                        'device_id'   => $this->deviceId,
                        'disk_key'    => $dev['disk_key'],
                        'page_num'    => (int) $pageNum,
                        'stat_offset' => (int) $offset,
                        'value'       => $this->leafValue($value, 'smartmonSataDevStatValue'),
                    ];
                }
            }
            if (! empty($upsertRows)) {
                $this->upsert('smart_sata_dev_stats', $upsertRows, ['app_id', 'disk_key', 'page_num', 'stat_offset']);
            }
        }
    }

    // ── Change detection ──────────────────────────────────────────────────────

    private function sataTableChangedForDevice(string $devIdx, int $tableId): bool
    {
        $current = $this->sataChangeRows[$devIdx][$tableId] ?? null;
        $prev = $this->prevSataChange !== null ? ($this->prevSataChange[$devIdx][$tableId][0] ?? null) : null;

        return $current !== $prev;
    }

    private function sataTableChangedForDevicePage(string $devIdx, int $tableId, int $subindex): bool
    {
        $current = $this->sataSubindexChangeRows[$devIdx][$tableId][$subindex] ?? null;
        $prev = $this->prevSataChange !== null ? ($this->prevSataChange[$devIdx][$tableId][$subindex] ?? null) : null;

        return $current !== $prev;
    }

    private function anySataDeviceChangedForTable(int $tableId): bool
    {
        foreach (array_keys($this->sataChangeRows) as $devIdx) {
            if ($this->sataTableChangedForDevice((string) $devIdx, $tableId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk one SATA table, normalize rows, and sync each device row that has changed.
     * Pass null for $tableId to sync unconditionally (no change guard).
     * Pass $numericIndex = true to keep OID index components as integers (needed when the
     * MIB index type is an enumeration, e.g. SmartmonAtaDevStatPage).
     */
    private function walkAndSyncSataTable(
        string $table, int $depth, ?int $tableId, callable $sync,
        bool $numericIndex = false
    ): void {
        $unconditional = $tableId === null;
        if (! $unconditional) {
            $this->sataChangeByDeviceTable();
            if (! Debug::isVerbose() && ! $this->anySataDeviceChangedForTable($tableId)) {
                $this->vlog("walkAndSyncSataTable: {$table} skipped (no changes)");

                return;
            }
        }

        $this->vlog("walkAndSyncSataTable: walking {$table} (depth={$depth})");
        $synced = 0;
        foreach ($this->walkSataTable($table, $depth, $numericIndex) as $devIdx => $rows) {
            $dev = $this->sataDeviceList[$devIdx] ?? null;
            if ($dev !== null && ($unconditional || $this->sataTableChangedForDevice($devIdx, $tableId))) {
                $sync($dev, $rows);
                $synced++;
            }
        }
        $this->vlog("walkAndSyncSataTable: {$table} synced {$synced} device(s)");
    }

    private function loadStoredSataChangeSnapshot(): ?array
    {
        $rows = DB::table('smart_sata_change')
            ->where('app_id', $this->appId)
            ->get(['device_idx', 'table_id', 'subindex', 'last_change']);

        if ($rows->isEmpty()) {
            return null;
        }

        // Structure: [devIdx][tableId][subindex] => last_change
        // subindex = 0 for device-level rows; subindex = pageNum/errorIdx for subindex rows.
        $snapshot = [];
        foreach ($rows as $row) {
            $snapshot[$row->device_idx][$row->table_id][$row->subindex] = $row->last_change;
        }

        return $snapshot;
    }

    private function persistSataChangeSnapshot(): void
    {
        $this->sataChangeByDeviceTable();
        $upsertRows = [];

        foreach ($this->sataChangeRows as $devIdx => $tables) {
            foreach ($tables as $tableId => $ts) {
                if ($ts !== null) {
                    $upsertRows[] = [
                        'app_id'      => $this->appId,
                        'device_idx'  => (int) $devIdx,
                        'table_id'    => (int) $tableId,
                        'subindex'    => 0,
                        'last_change' => $ts,
                    ];
                }
            }
        }

        foreach ($this->sataSubindexChangeRows ?? [] as $devIdx => $tables) {
            foreach ($tables as $tableId => $subindexes) {
                foreach ($subindexes as $subindex => $ts) {
                    if ($ts !== null) {
                        $upsertRows[] = [
                            'app_id'      => $this->appId,
                            'device_idx'  => (int) $devIdx,
                            'table_id'    => (int) $tableId,
                            'subindex'    => (int) $subindex,
                            'last_change' => $ts,
                        ];
                    }
                }
            }
        }

        $this->vlog('persistSataChangeSnapshot: upserting ' . count($upsertRows) . ' change row(s)');
        if (! empty($upsertRows)) {
            $this->upsert('smart_sata_change', $upsertRows, ['app_id', 'device_idx', 'table_id', 'subindex']);
        }
    }

    // ── V1 → V2 migration ────────────────────────────────────────────────────

    /**
     * One-shot per-device migration from V1 RRD layout to V2.
     *
     * V1 keyed RRDs by device path (e.g. /dev/sda); V2 uses a stable
     * identity key (WWN or Model+Serial).  For each SATA device that has
     * not been migrated yet:
     *
     *   1. Rename app-smart-{app_id}-{v1_path}.rrd
     *          → app-smart-{app_id}-{v2_idx}.rrd  (no-op if V1 file absent)
     *   2. Discard the V1-only self-test counter DS that have no equivalent
     *      in V2 (completed, interrupted, readfailure, unknownfail, extended,
     *      short, conveyance, selective).
     *   3. Mark the device as migrated so this runs only once.
     *
     * Note: the separate V1 smart_id9 / smart_id232 / smart_maxtemp files are
     * intentionally left untouched. They will simply stop being updated and
     * age out of RRD naturally.
     */
    private function migrateV1Rrds(): void
    {
        $deviceModel = Device::find($this->deviceId);
        if ($deviceModel === null) {
            return;
        }

        $rrd = app(Rrd::class);

        foreach ($this->sataDevices() as $dev) {
            $diskKey = $dev['disk_key'];

            $alreadyDone = DB::table('smart_devices')
                ->where('app_id', $this->appId)
                ->where('disk_key', $diskKey)
                ->value('v1_rrd_migrated');

            if ($alreadyDone) {
                continue;
            }

            $v2Idx = $this->mibDiskIndex($diskKey);
            $v2Name = ['app', 'smart', $this->appId, $v2Idx];

            // V1 used the raw device path as the disk ID (e.g. /dev/sda).
            $v1DiskId = $dev['device_path'];
            if (! empty($v1DiskId)) {
                $v1Name = ['app', 'smart', $this->appId, $v1DiskId];
                $rrd->renameFile($deviceModel, $v1Name, $v2Name);
            }

            // Strip V1-only DS; no-op if they're absent or the file doesn't exist.
            $rrdFile = $rrd->name($deviceModel->hostname, $v2Name);
            $rrd->discardDatasets($rrdFile, self::V1_SATA_DISCARD_DS);

            DB::table('smart_devices')
                ->where('app_id', $this->appId)
                ->where('disk_key', $diskKey)
                ->update(['v1_rrd_migrated' => 1]);
        }
    }

    // ── RRD dataset reconciliation ───────────────────────────────────────────

    /**
     * Reconcile an existing RRD file's datasets against a statically defined
     * set, adding whatever's missing. Used at discovery time to retrofit new
     * DS onto RRD files created by an older version of this module. For
     * example, power_state didn't exist as a DS before this was added, so disks
     * polled by a prior version have files missing it.
     *
     * Skipped if the file doesn't exist yet: a brand-new device gets every
     * currently-defined DS the first time poll() writes to it, since the
     * write path always builds its RrdDefinition from the same static set.
     *
     * @param array<int, array{name:string,type:string,heartbeat:int,min?:int|float|string|null,max?:int|float|string|null}> $datasets
     */
    private function addDatasets(string $rrdFile, array $datasets): void
    {
        $rrd = app(Rrd::class);
        if (! $rrd->checkRrdExists($rrdFile)) {
            return;
        }
        $rrd->addDatasets($rrdFile, $datasets);
    }

    /**
     * Like addDatasets(), but for a config array keyed by dataset name (see
     * Rrd::addDatasetsFromConfig()) -- used where the dataset set is built up
     * incrementally per attribute, so keying by name naturally dedupes.
     *
     * @param array<string, array{type:string,heartbeat:int,min?:int|float|string|null,max?:int|float|string|null}> $config
     */
    private function addDatasetsFromConfig(string $rrdFile, array $config): void
    {
        $rrd = app(Rrd::class);
        if (! $rrd->checkRrdExists($rrdFile)) {
            return;
        }
        $rrd->addDatasetsFromConfig($rrdFile, $config);
    }

    /** Statically defined DS that the per-device RRD families must always carry. */
    private function commonDeviceRrdDatasets(): array
    {
        return [
            ['name' => 'power_state', 'type' => 'GAUGE', 'heartbeat' => LibrenmsConfig::get('rrd.heartbeat'), 'min' => 0, 'max' => 8],
        ];
    }

    /** Retrofit commonDeviceRrdDatasets() onto every existing SATA and NVMe per-disk RRD file. */
    private function reconcileCommonDeviceRrds(): void
    {
        $deviceModel = Device::find($this->deviceId);
        if ($deviceModel === null) {
            return;
        }

        $rrd = app(Rrd::class);
        $datasets = $this->commonDeviceRrdDatasets();

        foreach ($this->sataDevices() as $dev) {
            $idx = $this->mibDiskIndex($dev['disk_key']);
            $this->addDatasets($rrd->name($deviceModel->hostname, ['app', 'smart', $this->appId, $idx]), $datasets);
        }

        foreach ($this->nvmeDevices() as $dev) {
            $idx = $this->mibDiskIndex($dev['disk_key']);
            $this->addDatasets($rrd->name($deviceModel->hostname, ['app', 'smart_nvme', $this->appId, $idx]), $datasets);
        }
    }

    /**
     * Retrofit each attribute's *current* smartmonSataAttrFormat-implied RRD
     * dataset set onto an already-existing per-disk RRD file, via
     * Rrd::addDatasetsFromConfig() (a no-op tune for DS that already exist).
     *
     * smartmontools' drivedb.h changes over time, so an attribute's format
     * can change between discovery cycles (e.g. a drivedb update reclassifies
     * an attribute from a plain format to a div/multi-part one, or vice
     * versa). pollSataDeviceRrd() only ever updates a file's *values* on the
     * DS it expects to already exist -- it never creates new DS on an
     * existing file -- so without this, a drivedb-driven format change would
     * leave the RRD stuck with the previous cycle's DS shape and start
     * failing to write the now-expected ones.
     */
    private function reconcileSataAttributeRrds(array $dev, array $attrRows): void
    {
        if (empty($attrRows)) {
            return;
        }

        $diskKey = $dev['disk_key'];
        $idx = $this->mibDiskIndex($diskKey);
        $rrd = app(Rrd::class);
        $rrdFile = $rrd->name($this->device->hostname, ['app', 'smart', $this->appId, $idx]);

        $heartbeat = LibrenmsConfig::get('rrd.heartbeat');
        $config = [];

        foreach ($attrRows as $attrId => $row) {
            $id = (int) ($row['smartmonSataAttrId'] ?? $attrId);
            $dsRaw = 'id' . $id;
            $dsNorm = $dsRaw . 'Normalized';
            if (strlen($dsNorm) > 19) {
                continue;
            }

            $config[$dsNorm] = ['type' => 'GAUGE', 'heartbeat' => $heartbeat, 'min' => 0, 'max' => 'U'];

            $format = $this->intValue($row['smartmonSataAttrFormat'] ?? null);
            $rawString = $row['smartmonSataAttrRawString'] ?? null;
            $subValues = $this->attrFormatSubValues($format, $rawString);
            if ($subValues !== []) {
                foreach ($subValues as $suffix => $value) {
                    $dsSub = $dsRaw . $suffix;
                    if (strlen($dsSub) > 19) {
                        continue;
                    }
                    $config[$dsSub] = ['type' => 'GAUGE', 'heartbeat' => $heartbeat, 'min' => 0, 'max' => 'U'];
                }

                continue;
            }

            $rawType = $this->isCounterAttrName($row['smartmonSataAttrName'] ?? null) || isset(self::ATA_COUNTER_ATTRS[$id])
                ? 'COUNTER' : 'GAUGE';
            $config[$dsRaw] = ['type' => $rawType, 'heartbeat' => $heartbeat, 'min' => 0, 'max' => 'U'];
        }

        if ($config !== []) {
            $this->addDatasetsFromConfig($rrdFile, $config);
        }
    }

    // ── Handler detection ─────────────────────────────────────────────────────

    /** Detect handler type on first run and persist it; return stored value otherwise. */
    private function detectAndPersistHandler(): string
    {
        $handler = DB::table('smart_app_state')
            ->where('app_id', $this->appId)
            ->value('handler') ?: null;

        if ($handler !== null) {
            $this->vlog("detectAndPersistHandler: using stored handler={$handler}");

            return $handler;
        }

        $response = SnmpQuery::mibs(self::COMMON_MIBS)
            ->hideMib()
            ->get('SMARTMON-COMMON-MIB::smartmonDeviceTableRowCount.0');
        $handler = ($response->isValid() && $response->value('smartmonDeviceTableRowCount.0') !== '')
            ? self::HANDLER_MIB
            : self::HANDLER_V1;

        $this->vlog("detectAndPersistHandler: detected handler={$handler} (MIB valid=" . ($response->isValid() ? 'true' : 'false') . ')');

        $this->upsert('smart_app_state', ['app_id' => $this->appId, 'handler' => $handler], ['app_id']);

        return $handler;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Load the common device table once, syncing smart_devices when the table's
     * LastChange timestamp differs from the stored one. Shared by sataDevices()
     * and nvmeDevices().
     */
    private function ensureCommonDevices(): void
    {
        if ($this->commonDevices !== null) {
            return;
        }

        $snmpTs = SnmpQuery::mibs(self::COMMON_MIBS)->hideMib()
            ->get('SMARTMON-COMMON-MIB::smartmonDeviceTableLastChange.0')
            ->value('smartmonDeviceTableLastChange.0');

        $storedTs = DB::table('smart_app_state')
            ->where('app_id', $this->appId)
            ->value('device_table_last_change');

        $this->commonDeviceTable();

        if ($snmpTs !== $storedTs) {
            $this->vlog("ensureCommonDevices: device table changed (snmp={$snmpTs}, stored={$storedTs}), syncing");
            $this->syncDeviceRows();
            DB::table('smart_app_state')
                ->where('app_id', $this->appId)
                ->update(['device_table_last_change' => $snmpTs]);
        } else {
            $this->vlog("ensureCommonDevices: device table unchanged (ts={$snmpTs})");
        }
    }

    /** Return only SATA/ATA devices from the common device table. */
    private function sataDevices(): array
    {
        $this->ensureCommonDevices();

        $sata = array_filter(
            $this->commonDevices,
            fn ($dev) => in_array($dev['device_type'] ?? 0, self::SATA_TYPES, true)
        );
        $this->vlog('sataDevices: ' . count($sata) . ' SATA / ' . count($this->commonDevices) . ' total device(s)');

        return $sata;
    }

    /** Return only NVMe devices from the common device table. */
    private function nvmeDevices(): array
    {
        $this->ensureCommonDevices();

        $nvme = array_filter(
            $this->commonDevices,
            fn ($dev) => in_array($dev['device_type'] ?? 0, self::NVME_TYPES, true)
        );
        $this->vlog('nvmeDevices: ' . count($nvme) . ' NVMe / ' . count($this->commonDevices) . ' total device(s)');

        return $nvme;
    }

    /**
     * Convert a SNMP DateAndTime string (e.g. "2026-6-6,22:15:11.0,+2:0")
     * to a MySQL-compatible datetime string ("2026-06-06 22:15:11"), or null.
     */
    private function parseDateAndTime(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $pattern = '/^(\d{4})-(\d{1,2})-(\d{1,2}),(\d{1,2}):(\d{2}):(\d{2})(?:\.\d+)?(?:,[+-]\d+:\d+)?$/';
        if (! preg_match($pattern, trim((string) $raw), $m)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
    }

    /** Print a debug line when -vv is active. */
    private function vlog(string $msg): void
    {
        if (Debug::isVerbose()) {
            echo PHP_EOL . "smart_mib: {$msg}";
        }
    }

    /** Load devices of the given protocol types from DB, keyed by snmp_index (no SNMP walk). */
    private function devicesFromDb(array $protocolTypes): array
    {
        $rows = DB::table('smart_devices')
            ->where('app_id', $this->appId)
            ->whereIn('protocol_type', $protocolTypes)
            ->whereNotNull('snmp_index')
            ->get(['snmp_index', 'disk_key', 'power_state']);

        $devices = [];
        foreach ($rows as $row) {
            $devices[(string) $row->snmp_index] = [
                'disk_key'    => $row->disk_key,
                'power_state' => $row->power_state !== null ? (int) $row->power_state : null,
            ];
        }

        return $devices;
    }

    private function sataDevicesFromDb(): array
    {
        return $this->devicesFromDb(self::SATA_TYPES);
    }

    private function nvmeDevicesFromDb(): array
    {
        return $this->devicesFromDb(self::NVME_TYPES);
    }

    /**
     * Map an overall SMART status plus all attribute statuses to a 1–6 health value.
     * Values are coerced through intValue() so the strict comparisons hold whether
     * SNMP/DB hand back ints or enum strings ("failingNow(2)").
     *
     *  1 = OK
     *  2 = Warning  (SMART overall test not passed)
     *  3 = Warning  (an attribute has failed in the past)
     *  4 = Warning  (an attribute's rate of change exceeded a configured threshold)
     *  5 = Error    (an attribute is currently failing)
     *  6 = Unavailable
     *
     * @param iterable<mixed> $attrStatuses raw smartmonSataAttrStatus values
     * @param iterable<mixed> $rateStatuses smart_sata_attributes.rate_status values
     */
    private function healthLevel(mixed $overall, iterable $attrStatuses, iterable $rateStatuses = []): int
    {
        $overall = $this->intValue($overall);
        if ($overall === 4) {
            return 6; // unavailable
        }

        $level = ($overall !== null && $overall !== 1) ? 2 : 1;

        foreach ($attrStatuses as $status) {
            $status = $this->intValue($status);
            if ($status === 3) {       // failedInPast
                $level = max($level, 3);
            } elseif ($status === 2) { // failingNow
                $level = max($level, 5);
            }
        }

        foreach ($rateStatuses as $rateStatus) {
            if ($this->intValue($rateStatus) === 2) { // rate-of-change threshold exceeded
                $level = max($level, 4);
            }
        }

        return $level;
    }

    /**
     * Synthesize the 1–5 health value from a discovery-time health row + attribute rows.
     *
     * rate_status isn't known yet for this discovery cycle (syncSataAttributeRates(),
     * which computes it from a fresh RRD fetch, runs later in the same disk loop). So
     * this reads the rate_status persisted by the previous discovery/poll instead, same
     * as synthesizeHealthFromDb() does for the ongoing poll path.
     */
    private function synthesizeHealthStatus(array $health, array $attrs, string $diskKey): int
    {
        $statuses = [];
        foreach ($attrs as $row) {
            if (is_array($row)) {
                $statuses[] = $row['smartmonSataAttrStatus'] ?? null;
            }
        }

        $rateStatuses = DB::table('smart_sata_attributes')
            ->where('app_id', $this->appId)
            ->where('disk_key', $diskKey)
            ->pluck('rate_status');

        return $this->healthLevel(
            $health['smartmonSataHealthOverallStatus'] ?? null,
            $statuses,
            $rateStatuses
        );
    }

    /** Load health + attribute statuses from DB and run the same 1–5 synthesis. */
    private function synthesizeHealthFromDb(string $diskKey): ?int
    {
        $health = DB::table('smart_sata_health')
            ->where('app_id', $this->appId)
            ->where('disk_key', $diskKey)
            ->first(['overall_status']);

        if ($health === null) {
            return null;
        }

        $attrs = DB::table('smart_sata_attributes')
            ->where('app_id', $this->appId)
            ->where('disk_key', $diskKey)
            ->get(['status', 'rate_status']);

        return $this->healthLevel($health->overall_status, $attrs->pluck('status'), $attrs->pluck('rate_status'));
    }

    /** Human-readable sensor label: "Model Serial (name)" or graceful fallbacks. Shared with HtmlData::diskLabel(). */
    private function sensorLabel(array $dev, string $fallback): string
    {
        return DiskIdentity::label($dev, $fallback);
    }

    /** Sanitized, stable sensor/RRD index from a disk key. Shared with HtmlData::diskIndex() -- must stay identical. */
    private function mibDiskIndex(string $key): string
    {
        return DiskIdentity::index($key);
    }

    /**
     * Compute the actual physical value from a SENSOR-MIB row.
     * Actual value = col × 10^(scale_exponent − precision)
     */
    private function applySensorScaleCol(array $row, string $valueCol): ?float
    {
        $raw = $row[$valueCol] ?? null;
        if ($raw === null) {
            return null;
        }

        // smartmonSensorScale is an enum returned as a name ("units(9)") when MIBs load.
        $scaleEnum = $this->intValue($row['smartmonSensorScale'] ?? null) ?? 9; // units(9 = 10^0)
        $precision = $this->intValue($row['smartmonSensorPrecision'] ?? null) ?? 0;
        $exp = self::SENSOR_SCALE_EXP[$scaleEnum] ?? 0;

        return (float) $raw * (10 ** ($exp - $precision));
    }

    /**
     * Translate smartmonSensorScale + precision into sensor_divisor / sensor_multiplier
     * so updateSensorValues() can scale the raw smartmonSensorValue at poll time
     * (e.g. milli(8) precision 0 → divisor 1000: 12169 → 12.169). Both keys are
     * always returned so a re-discovery resets any stale factor.
     *
     * @return array{sensor_divisor: int, sensor_multiplier: int}
     */
    private function sensorScaleColumns(array $row): array
    {
        $scaleEnum = $this->intValue($row['smartmonSensorScale'] ?? null) ?? 9;
        $precision = $this->intValue($row['smartmonSensorPrecision'] ?? null) ?? 0;
        $exp = (self::SENSOR_SCALE_EXP[$scaleEnum] ?? 0) - $precision;

        return $exp >= 0
            ? ['sensor_divisor' => 1, 'sensor_multiplier' => 10 ** $exp]
            : ['sensor_divisor' => 10 ** (-$exp), 'sensor_multiplier' => 1];
    }

    /**
     * Extract the scalar value from a SnmpQuery table() leaf.
     *
     * A single-column walk grouped with table($n) yields leaves of the form
     * [columnName => value]; return the scalar (preferring the named column),
     * or the value itself when it is already a scalar.
     */
    private function leafValue(mixed $leaf, string $col): mixed
    {
        if (! is_array($leaf)) {
            return $leaf;
        }
        if (array_key_exists($col, $leaf)) {
            return $leaf[$col];
        }

        return $leaf === [] ? null : reset($leaf);
    }

    private function walkSataTable(string $table, int $group, bool $numericIndex = false): array
    {
        $query = SnmpQuery::mibs(self::SATA_MIBS)->hideMib();
        if ($numericIndex) {
            $query = $query->numericIndex();
        }

        return $query->walk("SMARTMON-SATA-MIB::$table")->table($group);
    }

    private function walkNvmeTable(string $table, int $group): array
    {
        return SnmpQuery::mibs(self::NVME_MIBS)->hideMib()
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

    private function diskKey(array $row, string $fallback): string
    {
        $wwn = trim((string) ($row['smartmonDeviceWwn'] ?? ''));
        if ($wwn !== '') {
            return $wwn;
        }

        $model = trim((string) ($row['smartmonDeviceModelName'] ?? ''));
        $serial = trim((string) ($row['smartmonDeviceSerialNumber'] ?? ''));
        if ($model !== '' || $serial !== '') {
            return $model . '+' . $serial;
        }

        return $fallback;
    }

    /**
     * Parse a SNMP BITS value to an integer.
     * Handles hex strings ("E0", "0xE0"), raw integers, and decimal strings.
     */
    private function parseBitsValue(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        if (is_int($raw)) {
            return $raw;
        }
        $str = trim((string) $raw);
        if ($str === '') {
            return null;
        }
        if (! preg_match('/^(?:0x)?([0-9A-Fa-f]+)$/', $str, $m)) {
            return null;
        }

        return (int) hexdec($m[1]);
    }

    /**
     * Parse smartmonSataAttrFlags (SNMP BITS) into a canonical bitmask where
     * bit N = flag N: prefailure(0), onlineCollection(1), performance(2),
     * errorRate(3), eventCount(4), autoKeep(5).
     *
     * Accepts the named form ("F0 prefailure(0) onlineCollection(1) ...") and
     * the bare hex form ("F0"), where bit 0 is the MSB of the first byte.
     */
    private function parseAttrFlags(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        if (is_int($raw)) {
            return $raw;
        }
        $str = trim((string) $raw);
        if ($str === '') {
            return null;
        }

        // Named bits carry the bit number in parentheses. Use them directly.
        if (preg_match_all('/\((\d+)\)/', $str, $m)) {
            $mask = 0;
            foreach ($m[1] as $bit) {
                $mask |= 1 << (int) $bit;
            }

            return $mask;
        }

        // Bare hex BITS: bit 0 is the most-significant bit of the first byte.
        if (preg_match('/^(?:0x)?([0-9A-Fa-f]{2,})/', $str, $hm)) {
            $byte = hexdec(substr($hm[1], 0, 2));
            $mask = 0;
            for ($n = 0; $n < 8; $n++) {
                if ($byte & (0x80 >> $n)) {
                    $mask |= 1 << $n;
                }
            }

            return $mask;
        }

        return null;
    }

    /** Convert SNMPv2 TruthValue to 1/0/null. TruthValue enum: true(1), false(2). */
    private function snmpTruthValue(mixed $value): ?int
    {
        $int = $this->intValue($value);
        if ($int === null) {
            return null;
        }

        return $int === 1 ? 1 : 0;
    }

    /**
     * Parse an SNMP BITS value (e.g. the OACS/ONCS/LPA bitmaps in SMARTMON-TC-MIB) into a
     * plain integer where bit N is set iff the MIB's bit(N) is set, directly usable
     * with `($raw >> $bit) & 1` against the same bit indexes the TEXTUAL-CONVENTION defines.
     *
     * net-snmp renders BITS values as e.g. "E8 00 securitySendReceive(0) formatNvm(1) ...":
     * hex octets (MSB-first per RFC 2578, not directly usable as an integer) followed by
     * the named set bits. The "(n)" suffixes are the authoritative bit indexes, so prefer
     * those; fall back to decoding the hex octets if a build's snmp options hide them.
     */
    private function bitsValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match_all('/\((\d+)\)/', $value, $matches) > 0) {
            $raw = 0;
            foreach ($matches[1] as $bit) {
                $raw |= 1 << (int) $bit;
            }

            return $raw;
        }

        $hex = preg_replace('/^(?:BITS|Hex-STRING|STRING):\s*/i', '', $value);
        if (! preg_match('/^(?:[0-9A-Fa-f]{2}[\s:]*)+$/', $hex)) {
            return null;
        }
        $hex = preg_replace('/[\s:]+/', '', $hex);
        if ($hex === '' || strlen($hex) % 2 !== 0) {
            return null;
        }

        $raw = 0;
        foreach (str_split($hex, 2) as $byteIdx => $byteHex) {
            $byte = hexdec($byteHex);
            for ($bitInByte = 0; $bitInByte < 8; $bitInByte++) {
                if (($byte >> (7 - $bitInByte)) & 1) {
                    $raw |= 1 << ($byteIdx * 8 + $bitInByte);
                }
            }
        }

        return $raw;
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || preg_match('/^(?:STRING:\s*)?\d{4}-\d{1,2}-\d{1,2},/', $value)) {
            return null;
        }

        if (preg_match('/\((-?\d+)\)$/', $value, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/:\s*(-?\d+)/', $value, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/^(-?\d+)(?:\s|$)/', $value, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /** True if $name should be treated as a COUNTER-type ATA attribute (legacy list or "Count" in the name). */
    private function isCounterAttrName(?string $name): bool
    {
        if ($name === null) {
            return false;
        }

        return in_array($name, self::ATA_COUNTER_ATTRS, true) || stripos($name, 'count') !== false;
    }
}
