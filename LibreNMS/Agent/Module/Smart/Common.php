<?php

namespace LibreNMS\Agent\Module\Smart;

use App\Models\Device;
use App\Models\Sensor;
use App\Models\StateTranslation;
use Illuminate\Support\Facades\DB;
use LibreNMS\Agent\Application;
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

    // smartSATAChangeByDeviceTable IDs (matches sata_table_meta_for() in the agentx)
    private const SATA_TID_INFO = 1;
    private const SATA_TID_HEALTH = 2;
    private const SATA_TID_ATTR = 3;
    private const SATA_TID_ERROR_LOG = 4;
    private const SATA_TID_ERROR_CMD = 5;
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

    private const HANDLER_MIB = 'mib'; // SMARTMON-*-MIB
    private const HANDLER_V1 = 'v1';  // Json

    // V1 RRD datasets that have no equivalent in V2 and should be discarded on migration.
    // V1 stored these as self-test pass/fail counters; V2 handles self-test via the log table.
    private const V1_SATA_DISCARD_DS = [
        'completed', 'interrupted', 'readfailure', 'unknownfail',
        'extended', 'short', 'conveyance', 'selective',
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

        return $rowCount > 0;
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
        update_application($this->app, 'ok', null);
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

        // SENSOR-MIB is common to all device types; walk once before type discovery.
        $this->sensorTable();
        $this->vlog('discoverMib: sensorTable has ' . count($this->sensorRows) . ' device entry/entries');

        // Type: SATA
        $this->discoverSata();

        // Type: NVMe
        $this->discoverNvme();

        // Type: SAS — future (not yet implemented)
        // $this->discoverSas();

        // SENSOR-MIB sensors: register for every discovered device.
        $device = $this->device;
        $group = 'SMART';
        $this->vlog('discoverMib: registering SENSOR-MIB sensors for ' . count($this->commonDevices) . ' device(s)');
        foreach ($this->commonDevices as $devIdx => $dev) {
            $diskKey = $dev['disk_key'];
            $idx = $this->mibDiskIndex($diskKey);
            $devName = $this->sensorLabel($dev, (string) $devIdx);
            foreach ($this->sensorRows[$devIdx] ?? [] as $sensorIdx => $row) {
                $type = $row['smartmonSensorType'] ?? null;
                $value = $this->applySensorScaleCol($row, 'smartmonSensorValue');
                if ($value === null) {
                    continue;
                }
                $meta = self::SENSOR_TYPE_MAP[$type] ?? null;
                if ($meta === null) {
                    continue;
                }
                [$sensorClass, $sensorType, $prefix] = $meta;
                $name = trim((string) ($row['smartmonSensorName'] ?? ''));
                $sIdx = "{$idx}_{$prefix}_{$sensorIdx}";
                $descr = $name !== '' ? "{$group} {$devName} {$name}" : "{$group} {$devName}";
                $highCrit = $this->applySensorScaleCol($row, 'smartmonSensorHighCritical');
                $highWarn = $this->applySensorScaleCol($row, 'smartmonSensorHighWarning');
                $lowWarn = $this->applySensorScaleCol($row, 'smartmonSensorLowWarning');
                $lowCrit = $this->applySensorScaleCol($row, 'smartmonSensorLowCritical');
                $attrs = [
                    'device_id'         => $device['device_id'],
                    'poller_type'       => 'agent',
                    'sensor_class'      => $sensorClass,
                    'sensor_type'       => $sensorType,
                    'sensor_index'      => $sIdx,
                    'sensor_oid'        => "app:smart_mib:{$sIdx}",
                    'group'             => $group,

                    'sensor_descr'      => $descr,
                    'sensor_current'    => $value,
                ];
                if ($highCrit !== null) {
                    $attrs['sensor_limit'] = $highCrit;
                }
                if ($highWarn !== null) {
                    $attrs['sensor_limit_warn'] = $highWarn;
                }
                if ($lowWarn !== null) {
                    $attrs['sensor_limit_low_warn'] = $lowWarn;
                }
                if ($lowCrit !== null) {
                    $attrs['sensor_limit_low'] = $lowCrit;
                }
                app('sensor-discovery')->discover(new Sensor($attrs));
            }
        }

        $this->cleanupStaleMibSensors();
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

        // Info table: sync unconditionally — static identity data not tracked in change table.
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
            $synthesized = $this->synthesizeHealthStatus($health, $attrRows);
            app('sensor-discovery')
                ->discover(new Sensor([
                    'device_id'         => $device['device_id'],
                    'poller_type'       => 'agent',
                    'sensor_class'      => 'state',
                    'sensor_type'       => 'smart_mib_health',
                    'sensor_index'      => "{$idx}_health",
                    'sensor_oid'        => "app:smart_mib:{$idx}_health",
                    'group'             => $group,

                    'sensor_descr'      => "{$group} {$devName} Health",
                    'sensor_current'    => $synthesized,
                ]))
                ->withStateTranslations('smart_mib_health', [
                    StateTranslation::define('OK', 1, Severity::Ok),
                    StateTranslation::define('Warning', 2, Severity::Warning),
                    StateTranslation::define('Warning: Attr Failed', 3, Severity::Warning),
                    StateTranslation::define('Error: Attr Failing', 4, Severity::Error),
                    StateTranslation::define('Unavailable', 5, Severity::Warning),
                ]);
        }

        // Self-test execution status (MIB returns the decoded nibble directly)
        $statusRaw = $health['smartmonSataSelfTestExecutionStatusValue'] ?? null;
        if ($statusRaw !== null) {
            $statusNibble = (int) $statusRaw;
            app('sensor-discovery')
                ->discover(new Sensor([
                    'device_id'         => $device['device_id'],
                    'poller_type'       => 'agent',
                    'sensor_class'      => 'state',
                    'sensor_type'       => 'smart_selftest_status',
                    'sensor_index'      => "{$idx}_selftest_status",
                    'sensor_oid'        => "app:smart_mib:{$idx}_selftest_status",
                    'group'             => $group,

                    'sensor_descr'      => "{$group} {$devName} Self-test Status",
                    'sensor_current'    => $statusNibble,
                ]))
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

    /** Sync all registered sensor types with the sensor-discovery system. */
    private function syncSensorTypes(): void
    {
        $types = array_unique(array_column(self::SENSOR_TYPE_MAP, 1));
        foreach (array_merge($types, ['smart_mib_health', 'smart_selftest_status']) as $type) {
            app('sensor-discovery')->sync(sensor_type: $type);
        }
    }

    /** Remove sensors belonging to drives that no longer appear in the device table. */
    private function cleanupStaleMibSensors(): void
    {
        $device = $this->device;
        $expected = [];
        foreach ($this->commonDevices ?? [] as $snmpIndex => $dev) {
            $idx = $this->mibDiskIndex($dev['disk_key']);

            // Generic SENSOR-MIB sensors (temperature, NVMe spare/used) — all device types.
            foreach ($this->sensorRows[$snmpIndex] ?? [] as $sensorIdx => $row) {
                $type = $row['smartmonSensorType'] ?? null;
                $meta = self::SENSOR_TYPE_MAP[$type] ?? null;
                if ($meta !== null) {
                    $expected[] = "app:smart_mib:{$idx}_{$meta[2]}_{$sensorIdx}";
                }
            }

            // Per-type state sensors.
            $deviceType = $dev['device_type'] ?? 0;
            if (in_array($deviceType, self::SATA_TYPES, true)) {
                $expected[] = "app:smart_mib:{$idx}_health";
                $expected[] = "app:smart_mib:{$idx}_selftest_status";
            } elseif (in_array($deviceType, self::NVME_TYPES, true)) {
                $expected[] = "app:smart_mib:{$idx}_health";
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

    // ── Polling ───────────────────────────────────────────────────────────────

    /** Update common application counters. */
    private function pollCommon(): void
    {
        $rows = SnmpQuery::mibs(self::COMMON_MIBS)->hideMib()->walk([
            'SMARTMON-COMMON-MIB::smartmonDeviceLastPollResult',
            'SMARTMON-COMMON-MIB::smartmonDeviceLastPollTime',
        ])->table(1);

        foreach ($rows as $snmpIndex => $row) {
            DB::table('smart_devices')
                ->where('app_id', $this->appId)
                ->where('snmp_index', (int) $snmpIndex)
                ->update([
                    'last_poll_result' => $this->intValue($row['smartmonDeviceLastPollResult'] ?? null),
                    'last_poll_time'   => $this->parseDateAndTime($row['smartmonDeviceLastPollTime'] ?? null),
                ]);
        }

        update_application($this->app, 'ok', null);
    }

    /**
     * Poll all SATA tables: for each table walk once, then update per device.
     */
    private function pollSata(): void
    {
        $this->sataDeviceList = $this->sataDevicesFromDb();

        // Table: Health (change-guarded; DB sync — sensors updated from DB in pollSensorValues)
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

        $this->persistSataChangeSnapshot();
        update_application($this->app, 'ok', null);
    }

    /**
     * Walk the four poll-relevant attribute columns and write the per-disk RRD
     * and DB row for every SATA device, every poll.
     *
     * Both the RRD (a time-series) and the displayed raw/normalized values must
     * refresh each interval, so neither is change-gated here — the
     * smartSATAChange stamp is unreliable for the frequently-incrementing
     * attribute values.
     */
    private function walkAndSyncSataAttrPoll(): void
    {
        // Only the four frequently-changing columns: raw value/string, status, normalized.
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
     * all types). Generic SENSOR-MIB sensors (temperature, NVMe spare/used) are
     * matched by trailing index; the per-type state sensors are read from the DB.
     */
    private function pollSensorValues(): void
    {
        $sensorValues = SnmpQuery::mibs(self::SENSOR_MIBS)
            ->hideMib()
            ->walk('SMARTMON-SENSOR-MIB::smartmonSensorValue')
            ->table(2);

        foreach ($this->sataDeviceList as $devIdx => $dev) {
            $diskKey = $dev['disk_key'];
            $idx = $this->mibDiskIndex($diskKey);
            $sensors = $this->matchSensorMibValues($idx, (string) $devIdx, $sensorValues);

            // Health state sensor — synthesized from DB
            if ($sensor = $sensors->get("{$idx}_health")) {
                $value = $this->synthesizeHealthFromDb($diskKey);
                $this->updateMibSensor($this->device, $sensor, $value !== null ? (float) $value : null);
            }

            // Self-test execution status from DB
            if ($sensor = $sensors->get("{$idx}_selftest_status")) {
                $raw = DB::table('smart_sata_health')
                    ->where('app_id', $this->appId)
                    ->where('disk_key', $diskKey)
                    ->value('selftest_exec_status_raw');
                $this->updateMibSensor($this->device, $sensor, $raw !== null ? (float) $raw : null);
            }
        }

        foreach ($this->nvmeDeviceList as $devIdx => $dev) {
            $diskKey = $dev['disk_key'];
            $idx = $this->mibDiskIndex($diskKey);
            $sensors = $this->matchSensorMibValues($idx, (string) $devIdx, $sensorValues);

            // Merged health state — overall status + critical warning stored at poll time.
            if ($sensor = $sensors->get("{$idx}_health")) {
                $row = DB::table('smart_nvme_health')
                    ->where('app_id', $this->appId)
                    ->where('disk_key', $diskKey)
                    ->first(['overall_status', 'critical_warning']);
                $value = $row === null ? null : $this->nvmeHealthLevel($row->overall_status, $row->critical_warning);
                $this->updateMibSensor($this->device, $sensor, $value !== null ? (float) $value : null);
            }
        }
    }

    /**
     * Load this device's app:smart_mib sensors, update the generic SENSOR-MIB
     * ones from the walked values (matched by trailing index), and return the
     * keyed collection so the caller can update its per-type state sensors.
     *
     * @return \Illuminate\Support\Collection<string, Sensor>
     */
    private function matchSensorMibValues(string $idx, string $devIdx, array $sensorValues): \Illuminate\Support\Collection
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
        foreach ($sensorValues[$devIdx] ?? [] as $sensorIdx => $rawValue) {
            if ($sensor = $bySuffix[(string) $sensorIdx] ?? null) {
                $this->updateMibSensor($this->device, $sensor, (float) $rawValue);
            }
        }

        return $sensors;
    }

    /** Write per-disk RRDs for one SATA device. */
    private function pollSataDeviceRrd(array $dev, array $attrRows): void
    {
        $device = $this->device;
        $diskKey = $dev['disk_key'];
        $idx = $this->mibDiskIndex($diskKey);

        // Attribute RRD
        // V2 uses ['app','smart',app_id,idx] with DS id{N} / id{N}Normalized.
        if (! empty($attrRows)) {
            $rrdTypes = DB::table('smart_sata_attributes')
                ->where('app_id', $this->appId)
                ->where('disk_key', $diskKey)
                ->pluck('rrd_type', 'attribute_id');

            $rrd_def = RrdDefinition::make();
            $fields = [];
            foreach ($attrRows as $attrId => $row) {
                $id = (int) ($row['smartmonSataAttrId'] ?? $attrId);
                $dsRaw = 'id' . $id;
                $dsNorm = 'id' . $id . 'Normalized';
                if (strlen($dsNorm) > 19) {
                    continue;
                }
                $rawType = $rrdTypes[$id]
                    ?? (isset(self::ATA_COUNTER_ATTRS[$id]) ? 'COUNTER' : 'GAUGE');
                $rrd_def->addDataset($dsRaw, $rawType, 0);
                $rrd_def->addDataset($dsNorm, 'GAUGE', 0);
                $fields[$dsRaw] = $row['smartmonSataAttrRawValue'] ?? null;
                $fields[$dsNorm] = $row['smartmonSataAttrValue'] ?? null;
            }
            if (! empty($fields)) {
                app('Datastore')->put($device, 'app', [
                    'name'     => 'smart',
                    'app_id'   => $this->appId,
                    'rrd_def'  => $rrd_def,
                    'rrd_name' => ['app', 'smart', $this->appId, $idx],
                ], $fields);
            }
        }
    }

    private function updateMibSensor(Device $device, Sensor $sensor, ?float $value): void
    {
        $sensor->sensor_current = $value;
        $sensor->save();

        $tags = [
            'sensor_class' => $sensor->sensor_class,
            'sensor_type'  => $sensor->sensor_type,
            'sensor_descr' => $sensor->sensor_descr,
            'sensor_index' => $sensor->sensor_index,
            'rrd_name'     => ['sensor', $sensor->sensor_class, $sensor->sensor_type, $sensor->sensor_index],
            'rrd_def'      => RrdDefinition::make()->addDataset('sensor', 'GAUGE'),
        ];
        app('Datastore')->put($device, 'sensor', $tags, ['sensor' => $value]);
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
            }
        }

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

        app('sensor-discovery')
            ->discover(new Sensor([
                'device_id'      => $device['device_id'],
                'poller_type'    => 'agent',
                'sensor_class'   => 'state',
                'sensor_type'    => 'smart_nvme_health',
                'sensor_index'   => "{$idx}_health",
                'sensor_oid'     => "app:smart_mib:{$idx}_health",
                'group'          => $group,
                'sensor_descr'   => "{$group} {$devName} Health",
                'sensor_current' => $state,
            ]))
            ->withStateTranslations('smart_nvme_health', [
                StateTranslation::define('OK', 1, Severity::Ok),
                StateTranslation::define('Warning', 2, Severity::Warning),
                StateTranslation::define('Failed', 3, Severity::Error),
                StateTranslation::define('Critical Warning', 4, Severity::Error),
                StateTranslation::define('Unavailable', 5, Severity::Warning),
            ]);
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
        app('sensor-discovery')->sync(sensor_type: 'smart_nvme_health');
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
        }
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

        // NVME_HEALTH_RRD is a fixed set, so $fields always carries every DS.
        app('Datastore')->put($this->device, 'app', [
            'name'     => 'smart_nvme',
            'app_id'   => $this->appId,
            'rrd_def'  => $rrd_def,
            'rrd_name' => ['app', 'smart_nvme', $this->appId, $idx],
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
        $table = SnmpQuery::mibs(self::COMMON_MIBS)
            ->hideMib()
            ->walk('SMARTMON-COMMON-MIB::smartmonDeviceTable')
            ->table(1);

        $this->commonDevices = [];
        foreach ($table as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->commonDevices[(string) $index] = [
                'snmp_index'           => (string) $index,
                'disk_key'             => $this->diskKey($row, (string) $index),
                'device_name'          => $row['smartmonDeviceName'] ?? null,
                'device_path'          => $row['smartmonDevicePath'] ?? null,
                'device_type'          => $this->intValue($row['smartmonDeviceType'] ?? null),
                'last_poll_time'       => $row['smartmonDeviceLastPollTime'] ?? null,
                'last_poll_result'     => $this->intValue($row['smartmonDeviceLastPollResult'] ?? null),
                'last_poll_exit_status'=> $this->intValue($row['smartmonDeviceLastPollExitStatus'] ?? null),
                'physical_index'       => $this->intValue($row['smartmonDevicePhysicalIndex'] ?? null),
                'uris'                 => $row['smartmonDeviceUris'] ?? null,
                'model_family'         => $row['smartmonDeviceModelFamily'] ?? null,
                'model_name'           => $row['smartmonDeviceModelName'] ?? null,
                'serial_number'        => $row['smartmonDeviceSerialNumber'] ?? null,
                'firmware_version'     => $row['smartmonDeviceFirmwareVersion'] ?? null,
                'wwn'                  => $row['smartmonDeviceWwn'] ?? null,
            ];
        }
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
            DB::table('smart_devices')->upsert([
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
                'physical_index'   => $dev['physical_index'] ?? 0,
                'uris'             => $dev['uris'],
            ], ['app_id', 'disk_key'], [
                'snmp_index', 'device_name', 'device_path', 'protocol_type', 'model_family',
                'model_name', 'serial_number', 'firmware_version', 'wwn',
                'last_poll_time', 'last_poll_result', 'last_poll_exit',
                'physical_index', 'uris',
            ]);
        }
    }

    private function syncSataInfoRow(array $dev, array $row): void
    {
        DB::table('smart_sata_info')->upsert([
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
        ], ['app_id', 'disk_key'], [
            'ata_version', 'sata_version', 'rotation_rate', 'form_factor',
            'logical_block_size', 'physical_block_size', 'user_capacity_bytes',
            'sct_hist_op_limit_min', 'sct_hist_op_limit_max', 'sct_hist_limit_min', 'sct_hist_limit_max',
            'ata_version_major', 'ata_version_minor', 'user_capacity_blocks',
            'in_smartctl_database', 'smart_available', 'smart_enabled', 'trim_supported',
            'write_cache_enabled', 'read_lookahead_enabled', 'apm_enabled', 'apm_level',
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
            'sct_error_recovery_supported', 'sct_feature_control_supported', 'sct_data_table_supported',
        ]);
    }

    private function syncSataHealthRow(array $dev, array $row): void
    {
        DB::table('smart_sata_health')->upsert([
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
        ], ['app_id', 'disk_key'], [
            'overall_status',
            'offline_collection_status',
            'selftest_exec_status_raw',
            'power_cycles',
            'power_on_hours',
            'error_log_count',
            'pending_defects_count',
            'selftest_log_count',
            'selftest_log_err_total',
            'selftest_log_err_outdated',
            'selftest_remaining_pct',
            'sct_format_version',
            'sct_version',
            'sct_device_state',
            'sct_temp_power_cycle_min',
            'sct_temp_power_cycle_max',
            'sct_temp_lifetime_min',
            'sct_temp_lifetime_max',
            'sct_temp_under_limit_count',
            'sct_temp_over_limit_count',
            'sct_smart_status_passed',
            'selftest_estimated_completion_time',
            'selftest_estimated_bytes_sec',
        ]);
    }

    private function syncSataAttributeRows(array $dev, array $attrRows): void
    {
        foreach ($attrRows as $attrId => $row) {
            DB::table('smart_sata_attributes')->upsert([
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
                'flags'            => $this->parseAttrFlags($row['smartmonSataAttrFlags'] ?? null),
                'rrd_type'         => in_array($row['smartmonSataAttrName'] ?? null, self::ATA_COUNTER_ATTRS, true)
                    ? 'COUNTER' : 'GAUGE',
            ], ['app_id', 'disk_key', 'attribute_id'], [
                'name', 'value_norm', 'value_worst',
                'value_threshold', 'value_raw', 'value_raw_string', 'status', 'flags', 'rrd_type',
            ]);
        }
    }

    /** Update only the four poll-relevant attribute columns; discovery keeps the rest. */
    private function syncSataAttributeRowsPoll(array $dev, array $attrRows): void
    {
        foreach ($attrRows as $attrId => $row) {
            DB::table('smart_sata_attributes')->upsert([
                'app_id'           => $this->appId,
                'device_id'        => $this->deviceId,
                'disk_key'         => $dev['disk_key'],
                'attribute_id'     => (int) $attrId,
                'value_norm'       => $row['smartmonSataAttrValue'] ?? null,
                'value_raw'        => $row['smartmonSataAttrRawValue'] ?? null,
                'value_raw_string' => isset($row['smartmonSataAttrRawString'])
                    ? substr((string) $row['smartmonSataAttrRawString'], 0, 32)
                    : null,
                'status'           => $row['smartmonSataAttrStatus'] ?? null,
            ], ['app_id', 'disk_key', 'attribute_id'], [
                'value_norm', 'value_raw', 'value_raw_string', 'status',
            ]);
        }
    }

    private function syncSataErcRows(array $dev, array $rows): void
    {
        foreach ($rows as $direction => $row) {
            DB::table('smart_sata_erc')->upsert([
                'app_id'      => $this->appId,
                'device_id'   => $this->deviceId,
                'disk_key'    => $dev['disk_key'],
                'direction'   => (int) $direction,
                'enabled'     => $this->snmpTruthValue($row['smartmonSataErcEnabled'] ?? null),
                'deciseconds' => $row['smartmonSataErcDeciseconds'] ?? null,
            ], ['app_id', 'disk_key', 'direction'], ['enabled', 'deciseconds']);
        }
        $this->pruneStaleRows('smart_sata_erc', $dev['disk_key'], 'direction', array_keys($rows));
    }

    /** Full discovery sync: name + size_bytes + value + overflow. */
    private function syncSataPhyEventRows(array $dev, array $rows): void
    {
        foreach ($rows as $eventId => $row) {
            DB::table('smart_sata_phy_events')->upsert([
                'app_id'     => $this->appId,
                'device_id'  => $this->deviceId,
                'disk_key'   => $dev['disk_key'],
                'event_id'   => (int) $eventId,
                'name'       => isset($row['smartmonSataPhyEventName'])
                    ? substr((string) $row['smartmonSataPhyEventName'], 0, 128) : null,
                'size_bytes' => $row['smartmonSataPhyEventSize'] ?? null,
                'value'      => $row['smartmonSataPhyEventValue'] ?? null,
                'overflow'   => $this->snmpTruthValue($row['smartmonSataPhyEventOverflow'] ?? null),
            ], ['app_id', 'disk_key', 'event_id'], ['name', 'size_bytes', 'value', 'overflow']);
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
            DB::table('smart_sata_phy_events')->upsert(
                $upsertRows,
                ['app_id', 'disk_key', 'event_id'],
                ['value', 'overflow']
            );
        }
    }

    private function syncSataErrorLogRows(array $dev, array $rows): void
    {
        foreach ($rows as $errorIndex => $row) {
            DB::table('smart_sata_error_log')->upsert([
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
            ], ['app_id', 'disk_key', 'entry_num'], [
                'error_count', 'lifetime_hours', 'error_type',
                'device_state', 'status_register', 'error_register',
            ]);
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
                DB::table('smart_sata_error_cmd')->upsert([
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
                ], ['app_id', 'disk_key', 'error_entry_num', 'cmd_slot'], [
                    'reg_command', 'reg_count', 'reg_device', 'reg_error',
                    'reg_feature', 'reg_lba', 'powerup_ms', 'description',
                ]);
            }
            $this->pruneStaleRows('smart_sata_error_cmd', $dev['disk_key'], 'cmd_slot', array_keys($cmdRows), ['error_entry_num' => (int) $errorIndex]);
        }
        $this->pruneStaleRows('smart_sata_error_cmd', $dev['disk_key'], 'error_entry_num', array_keys($rows));
    }

    private function syncSataSelfTestRows(array $dev, array $rows): void
    {
        foreach ($rows as $testIndex => $row) {
            DB::table('smart_sata_selftest_log')->upsert([
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
            ], ['app_id', 'disk_key', 'entry_num'], [
                'test_type', 'result', 'result_passed', 'remaining_pct', 'power_on_hours', 'lba_first_error',
            ]);
        }
        $this->pruneStaleRows('smart_sata_selftest_log', $dev['disk_key'], 'entry_num', array_keys($rows));
    }

    private function syncSataSelectiveTestRows(array $dev, array $rows): void
    {
        foreach ($rows as $slot => $row) {
            DB::table('smart_sata_selective_test')->upsert([
                'app_id'       => $this->appId,
                'device_id'    => $this->deviceId,
                'disk_key'     => $dev['disk_key'],
                'slot'         => (int) $slot,
                'lba_min'      => $row['smartmonSataSelectiveLbaMin'] ?? null,
                'lba_max'      => $row['smartmonSataSelectiveLbaMax'] ?? null,
                'status_value' => $row['smartmonSataSelectiveStatusValue'] ?? null,
            ], ['app_id', 'disk_key', 'slot'], ['lba_min', 'lba_max', 'status_value']);
        }
        $this->pruneStaleRows('smart_sata_selective_test', $dev['disk_key'], 'slot', array_keys($rows));
    }

    private function syncSataLogDirRows(array $dev, array $rows): void
    {
        foreach ($rows as $address => $row) {
            DB::table('smart_sata_log_dir')->upsert([
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
            ], ['app_id', 'disk_key', 'log_address'], [
                'name', 'readable', 'writable', 'gp_sectors', 'smart_sectors',
            ]);
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

                DB::table('smart_sata_dev_stats')->upsert([
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
                ], ['app_id', 'disk_key', 'page_num', 'stat_offset'], [
                    'page_name', 'stat_name', 'value', 'flags_value', 'valid', 'normalized',
                ]);
            }
            $this->pruneStaleRows('smart_sata_dev_stats', $dev['disk_key'], 'stat_offset', array_keys($offsets), ['page_num' => (int) $pageNum]);
        }
        $this->pruneStaleRows('smart_sata_dev_stats', $dev['disk_key'], 'page_num', array_keys($rows));
    }

    private function syncSataPendingDefectRows(array $dev, array $rows): void
    {
        foreach ($rows as $entryIndex => $row) {
            DB::table('smart_sata_pending_defects')->upsert([
                'app_id'    => $this->appId,
                'device_id' => $this->deviceId,
                'disk_key'  => $dev['disk_key'],
                'entry_num' => (int) $entryIndex,
                'lba'       => $row['smartmonSataPendingDefectsLba'] ?? null,
            ], ['app_id', 'disk_key', 'entry_num'], ['lba']);
        }
        $this->pruneStaleRows('smart_sata_pending_defects', $dev['disk_key'], 'entry_num', array_keys($rows));
    }

    // ── NVMe database sync ──────────────────────────────────────────────────────

    private function syncNvmeInfoRow(array $dev, array $row): void
    {
        DB::table('smart_nvme_info')->upsert([
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
        ], ['app_id', 'disk_key'], [
            'pci_vendor_id', 'pci_device_id', 'ieee_oui',
            'total_nvm_capacity_bytes', 'unallocated_nvm_capacity_bytes',
            'controller_id', 'nvme_version', 'namespace_count', 'max_data_transfer_pages',
        ]);
    }

    private function syncNvmeHealthRow(array $dev, array $row): void
    {
        // Current self-test: OperationValue is the operation enum (0=none, 1=short,
        // 2=extended, 14=vendor); OperationProgress is the completion percentage.
        $selftestOp = $this->intValue($row['smartmonNvmeCurrentSelfTestOperationValue'] ?? null);

        DB::table('smart_nvme_health')->upsert([
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
        ], ['app_id', 'disk_key'], [
            'overall_status', 'critical_warning',
            'data_units_read', 'data_units_written', 'data_bytes_read', 'data_bytes_written',
            'host_read_commands', 'host_write_commands', 'controller_busy_time',
            'power_cycles', 'power_on_hours', 'unsafe_shutdowns', 'media_errors',
            'num_err_log_entries', 'warning_temp_time', 'critical_comp_time',
            'current_selftest_op', 'current_selftest_str', 'current_selftest_pct',
        ]);
    }

    private function syncNvmeNamespaceRows(array $dev, array $rows): void
    {
        foreach ($rows as $nsId => $row) {
            DB::table('smart_nvme_namespaces')->upsert([
                'app_id'        => $this->appId,
                'device_id'     => $this->deviceId,
                'disk_key'      => $dev['disk_key'],
                'ns_id'         => (int) $nsId,
                'nsze'          => $this->intValue($row['smartmonNvmeNamespaceSizeBlocks'] ?? null),
                'ncap'          => $this->intValue($row['smartmonNvmeNamespaceCapacityBlocks'] ?? null),
                'nuse'          => $this->intValue($row['smartmonNvmeNamespaceUtilizationBlocks'] ?? null),
                'lba_data_size' => $this->intValue($row['smartmonNvmeNamespaceFormattedLbaSizeBytes'] ?? null),
            ], ['app_id', 'disk_key', 'ns_id'], ['nsze', 'ncap', 'nuse', 'lba_data_size']);
        }
        $this->pruneStaleRows('smart_nvme_namespaces', $dev['disk_key'], 'ns_id', array_keys($rows));
    }

    private function syncNvmeSelfTestRows(array $dev, array $rows): void
    {
        foreach ($rows as $entryIndex => $row) {
            DB::table('smart_nvme_selftest_log')->upsert([
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
            ], ['app_id', 'disk_key', 'entry_num'], [
                'test_type', 'result', 'result_text', 'power_on_hours',
                'failing_lba', 'nsid', 'estimated_completion',
            ]);
        }
        $this->pruneStaleRows('smart_nvme_selftest_log', $dev['disk_key'], 'entry_num', array_keys($rows));
    }

    private function syncNvmePowerStateRows(array $dev, array $rows): void
    {
        foreach ($rows as $stateId => $row) {
            DB::table('smart_nvme_power_states')->upsert([
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
            ], ['app_id', 'disk_key', 'state_id'], [
                'operational', 'max_power_mw', 'active_power_mw', 'idle_power_mw',
                'read_latency_rank', 'read_throughput_rank', 'write_latency_rank',
                'write_throughput_rank', 'entry_latency_us', 'exit_latency_us',
            ]);
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
                DB::table('smart_nvme_lba_formats')->upsert([
                    'app_id'               => $this->appId,
                    'device_id'            => $this->deviceId,
                    'disk_key'             => $dev['disk_key'],
                    'ns_id'                => (int) $nsId,
                    'format_id'            => (int) $formatId,
                    'current'              => $this->snmpTruthValue($row['smartmonNvmeLbaFormatCurrent'] ?? null),
                    'data_size_bytes'      => $this->intValue($row['smartmonNvmeLbaFormatDataSizeBytes'] ?? null),
                    'metadata_size_bytes'  => $this->intValue($row['smartmonNvmeLbaFormatMetadataSizeBytes'] ?? null),
                    'relative_performance' => $this->intValue($row['smartmonNvmeLbaFormatRelativePerformance'] ?? null),
                ], ['app_id', 'disk_key', 'ns_id', 'format_id'], [
                    'current', 'data_size_bytes', 'metadata_size_bytes', 'relative_performance',
                ]);
            }
            $this->pruneStaleRows('smart_nvme_lba_formats', $dev['disk_key'], 'format_id', array_keys($formats), ['ns_id' => (int) $nsId]);
        }
        $this->pruneStaleRows('smart_nvme_lba_formats', $dev['disk_key'], 'ns_id', array_keys($nsFormats));
    }

    private function syncNvmeErrorLogRows(array $dev, array $rows): void
    {
        foreach ($rows as $entryIndex => $row) {
            DB::table('smart_nvme_error_log')->upsert([
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
            ], ['app_id', 'disk_key', 'entry_num'], [
                'error_count', 'sq_id', 'command_id', 'status_field', 'param_error_location',
                'lba', 'ns_id', 'vendor_info', 'status_code', 'status_code_type',
                'do_not_retry', 'status_string', 'error_time',
            ]);
        }
        $this->pruneStaleRows('smart_nvme_error_log', $dev['disk_key'], 'entry_num', array_keys($rows));
    }

    private function syncNvmeCapabilityRow(array $dev, array $row): void
    {
        DB::table('smart_nvme_capability')->upsert([
            'app_id'                  => $this->appId,
            'device_id'               => $this->deviceId,
            'disk_key'                => $dev['disk_key'],
            'firmware_update_raw'     => $this->intValue($row['smartmonNvmeFirmwareUpdateRaw'] ?? null),
            'firmware_slot_count'     => $this->intValue($row['smartmonNvmeFirmwareSlotCount'] ?? null),
            'firmware_reset_required' => $this->snmpTruthValue($row['smartmonNvmeFirmwareResetRequired'] ?? null),
            'optional_admin_cmd_raw'  => $this->intValue($row['smartmonNvmeOptionalAdminCommandRaw'] ?? null),
            'optional_nvm_cmd_raw'    => $this->intValue($row['smartmonNvmeOptionalNvmCommandRaw'] ?? null),
            'log_page_attrs_raw'      => $this->intValue($row['smartmonNvmeLogPageAttributesRaw'] ?? null),
            'optional_admin_cmd_text' => isset($row['smartmonNvmeOptionalAdminCommandText'])
                ? substr((string) $row['smartmonNvmeOptionalAdminCommandText'], 0, 255) : null,
            'optional_nvm_cmd_text'   => isset($row['smartmonNvmeOptionalNvmCommandText'])
                ? substr((string) $row['smartmonNvmeOptionalNvmCommandText'], 0, 255) : null,
            'log_page_attrs_text'     => isset($row['smartmonNvmeLogPageAttributesText'])
                ? substr((string) $row['smartmonNvmeLogPageAttributesText'], 0, 255) : null,
        ], ['app_id', 'disk_key'], [
            'firmware_update_raw', 'firmware_slot_count', 'firmware_reset_required',
            'optional_admin_cmd_raw', 'optional_nvm_cmd_raw', 'log_page_attrs_raw',
            'optional_admin_cmd_text', 'optional_nvm_cmd_text', 'log_page_attrs_text',
        ]);
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
                DB::table('smart_sata_dev_stats')->upsert(
                    $upsertRows,
                    ['app_id', 'disk_key', 'page_num', 'stat_offset'],
                    ['value']
                );
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
            DB::table('smart_sata_change')->upsert(
                $upsertRows,
                ['app_id', 'device_idx', 'table_id', 'subindex'],
                ['last_change']
            );
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
     * intentionally left untouched — they will simply stop being updated and
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

        DB::table('smart_app_state')->upsert(
            ['app_id' => $this->appId, 'handler' => $handler],
            ['app_id'],
            ['handler']
        );

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
            ->get(['snmp_index', 'disk_key']);

        $devices = [];
        foreach ($rows as $row) {
            $devices[(string) $row->snmp_index] = [
                'disk_key' => $row->disk_key,
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
     * Map an overall SMART status plus all attribute statuses to a 1–5 health value.
     * Values are coerced through intValue() so the strict comparisons hold whether
     * SNMP/DB hand back ints or enum strings ("failingNow(2)").
     *
     *  1 = OK
     *  2 = Warning  (SMART overall test not passed)
     *  3 = Warning  (an attribute has failed in the past)
     *  4 = Error    (an attribute is currently failing)
     *  5 = Unavailable
     *
     * @param iterable<mixed> $attrStatuses raw smartmonSataAttrStatus values
     */
    private function healthLevel(mixed $overall, iterable $attrStatuses): int
    {
        $overall = $this->intValue($overall);
        if ($overall === 4) {
            return 5; // unavailable
        }

        $level = ($overall !== null && $overall !== 1) ? 2 : 1;

        foreach ($attrStatuses as $status) {
            $status = $this->intValue($status);
            if ($status === 3) {       // failedInPast
                $level = max($level, 3);
            } elseif ($status === 2) { // failingNow
                $level = max($level, 4);
            }
        }

        return $level;
    }

    /** Synthesize the 1–5 health value from a discovery-time health row + attribute rows. */
    private function synthesizeHealthStatus(array $health, array $attrs): int
    {
        $statuses = [];
        foreach ($attrs as $row) {
            if (is_array($row)) {
                $statuses[] = $row['smartmonSataAttrStatus'] ?? null;
            }
        }

        return $this->healthLevel(
            $health['smartmonSataHealthOverallStatus'] ?? null,
            $statuses
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

        $statuses = DB::table('smart_sata_attributes')
            ->where('app_id', $this->appId)
            ->where('disk_key', $diskKey)
            ->pluck('status');

        return $this->healthLevel($health->overall_status, $statuses);
    }

    /** Human-readable sensor label: "Model Serial (name)" or graceful fallbacks. */
    private function sensorLabel(array $dev, string $fallback): string
    {
        $model = trim((string) ($dev['model_name'] ?? ''));
        $serial = trim((string) ($dev['serial_number'] ?? ''));
        $name = trim((string) ($dev['device_name'] ?? ''));

        $parts = array_filter([$model, $serial]);
        $label = implode(' ', $parts);

        if ($name !== '') {
            $label = $label !== '' ? "{$label} ({$name})" : $name;
        }

        return $label !== '' ? $label : $fallback;
    }

    /** Sanitized, stable sensor/RRD index from a disk key (max 80 chars, safe chars). */
    private function mibDiskIndex(string $key): string
    {
        return substr(preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key), 0, 80);
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

        $scaleEnum = $row['smartmonSensorScale'] ?? 9; // units(9 = 10^0)
        $precision = $row['smartmonSensorPrecision'] ?? 0;
        $exp = self::SENSOR_SCALE_EXP[$scaleEnum] ?? 0;

        return (float) $raw * (10 ** ($exp - $precision));
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

        // Named bits carry the bit number in parentheses — use them directly.
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
}
