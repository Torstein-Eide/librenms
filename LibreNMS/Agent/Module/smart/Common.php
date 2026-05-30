<?php

namespace LibreNMS\Agent\Module\Smart;

use App\Models\Device;
use App\Models\Sensor;
use App\Models\StateTranslation;
use Illuminate\Support\Facades\DB;
use LibreNMS\Agent\Application;
use LibreNMS\Data\Store\Rrd;
use LibreNMS\Util\Debug;
use LibreNMS\Enum\Severity;
use LibreNMS\RRD\RrdDefinition;
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
    private const SATA_MIBS   = ['SMARTMON-TC-MIB', 'SMARTMON-COMMON-MIB', 'SMARTMON-SATA-MIB'];
    private const SENSOR_MIBS = ['SMARTMON-TC-MIB', 'SMARTMON-COMMON-MIB', 'SMARTMON-SENSOR-MIB'];

    // smartSATAChangeByDeviceTable IDs (matches sata_table_meta_for() in the agentx)
    private const SATA_TID_INFO            = 1;
    private const SATA_TID_HEALTH          = 2;
    private const SATA_TID_ATTR            = 3;
    private const SATA_TID_ERROR_LOG       = 4;
    private const SATA_TID_ERROR_CMD       = 5;
    private const SATA_TID_SELFTEST        = 6;
    private const SATA_TID_ERC             = 7;
    private const SATA_TID_PHY_EVENT       = 8;
    private const SATA_TID_SELECTIVE_TEST  = 9;
    private const SATA_TID_LOG_DIR         = 10;
    private const SATA_TID_DEV_STAT        = 11;
    private const SATA_TID_PENDING_DEFECTS = 12;

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

    // ATA attribute IDs that carry SSD wear-remaining as normalised value
    private const ATA_WEAR_ATTR_IDS = [173, 177, 202, 231, 233];

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
    private const HANDLER_V1  = 'v1';  // Json

    // V1 RRD datasets that have no equivalent in V2 and should be discarded on migration.
    // V1 stored these as self-test pass/fail counters; V2 handles self-test via the log table.
    private const V1_SATA_DISCARD_DS = [
        'completed', 'interrupted', 'readfailure', 'unknownfail',
        'extended', 'short', 'conveyance', 'selective',
    ];

    

    // ── Public interface ──────────────────────────────────────────────────────

    public function shouldDiscover(): bool
    {
        $rowCount = $this->intValue(
            SnmpQuery::mibs(self::COMMON_MIBS)->hideMib()
                ->get('SMARTMON-COMMON-MIB::smartmonDeviceTableRowCount.0')
                ->value('smartmonDeviceTableRowCount.0')
        );

        if ($rowCount > 0) {
            return true;
        }

        return (new SmartV1($this->os, $this->app, $this->agent_data))->shouldDiscover();
    }

    public function discover(): void
    {
        $handler = $this->detectAndPersistHandler();

        if ($handler !== self::HANDLER_MIB) {
            return; // V1 has no MIB-based discovery
        }

        $this->discoverMib();
    }

    public function shouldPoll(): bool
    {
        return DB::table('smart_devices')
            ->where('app_id', $this->app->app_id)
            ->exists();
    }

    public function poll(): void
    {
        $this->pollCommon();
        $this->pollSata();
        // $this->pollNvme();  // future
        // $this->pollSAS();  // future
    }

    // ── Discovery ─────────────────────────────────────────────────────────────

    private function discoverMib(): void
    {
        app()->forgetInstance('sensor-discovery');

        // One-shot V1→V2 RRD migration (no-op once all devices are marked done).
        $this->migrateV1Rrds();

        // SENSOR-MIB is common to all device types; walk once before type discovery.
        $this->sensorTable();

        // Type: SATA
        $this->discoverSata();

        // Type: NVMe — future
        // $this->discoverNvme();

        // Type: SAS — future (not yet implemented)
        // $this->discoverSas();

        // SENSOR-MIB sensors: register for every discovered device.
        $device = $this->os->getDevice();
        $group  = 'SMART';
        foreach ($this->commonDevices as $devIdx => $dev) {
            $diskKey = $dev['disk_key'];
            $idx     = $this->mibDiskIndex($diskKey);
            $nav     = $this->mibDiskNavigation($diskKey);
            $devName = $this->sensorLabel($dev, (string) $devIdx);
            foreach ($this->sensorRows[$devIdx] ?? [] as $sensorIdx => $row) {
                $type  = $row['smartmonSensorType'] ?? null;
                $value = $this->applySensorScale($row);
                if ($value === null) {
                    continue;
                }
                $meta = self::SENSOR_TYPE_MAP[$type] ?? null;
                if ($meta === null) {
                    continue;
                }
                [$sensorClass, $sensorType, $prefix] = $meta;
                $name     = trim((string) ($row['smartmonSensorName'] ?? ''));
                $sIdx     = "{$idx}_{$prefix}_{$sensorIdx}";
                $descr    = $name !== '' ? "{$group} {$devName} {$name}" : "{$group} {$devName}";
                $highCrit = $this->applySensorScaleCol($row, 'smartmonSensorHighCritical');
                $highWarn = $this->applySensorScaleCol($row, 'smartmonSensorHighWarning');
                $lowWarn  = $this->applySensorScaleCol($row, 'smartmonSensorLowWarning');
                $lowCrit  = $this->applySensorScaleCol($row, 'smartmonSensorLowCritical');
                $attrs = [
                    'device_id'         => $device['device_id'],
                    'poller_type'       => 'agent',
                    'sensor_class'      => $sensorClass,
                    'sensor_type'       => $sensorType,
                    'sensor_index'      => $sIdx,
                    'sensor_oid'        => "app:smart_mib:{$sIdx}",
                    'group'             => $group,
                    'sensor_navigation' => $nav,
                    'sensor_descr'      => $descr,
                    'sensor_current'    => $value,
                ];
                if ($highCrit !== null) { $attrs['sensor_limit']          = $highCrit; }
                if ($highWarn !== null) { $attrs['sensor_limit_warn']     = $highWarn; }
                if ($lowWarn  !== null) { $attrs['sensor_limit_low_warn'] = $lowWarn; }
                if ($lowCrit  !== null) { $attrs['sensor_limit_low']      = $lowCrit; }
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
        $devices = $this->sataDevices();

        $this->walkAndSyncSataTable('smartmonSataInfoTable', 1, $devices, self::SATA_TID_INFO, [$this, 'syncSataInfoRow']);

        // Tables needed for sensor discovery (always fetched).
        $this->sataAttributeTable();
        $this->sataHealthTable();

        // For each SATA device: register SATA-specific sensors.
        foreach ($devices as $devIdx => $dev) {
            $this->discoverSataDeviceSensors(
                $dev,
                $this->sataHealth[$devIdx]     ?? [],
                $this->sataAttributes[$devIdx] ?? []
            );
        }

        // Change-guarded tables (per device):
        $this->walkAndSyncSataTable('smartmonSataErcTable',          2, $devices, self::SATA_TID_ERC,            [$this, 'syncSataErcRows']);
        $this->walkAndSyncSataTable('smartmonSataPhyEventTable',     2, $devices, self::SATA_TID_PHY_EVENT,      [$this, 'syncSataPhyEventRows']);
        $this->walkAndSyncSataTable('smartmonSataErrorLogTable',     2, $devices, self::SATA_TID_ERROR_LOG,      [$this, 'syncSataErrorLogRows']);
        $this->walkAndSyncSataTable('smartmonSataErrorCmdTable',     3, $devices, self::SATA_TID_ERROR_LOG,      [$this, 'syncSataErrorCmdRows']);
        $this->walkAndSyncSataTable('smartmonSataSelfTestTable',     2, $devices, self::SATA_TID_SELFTEST,       [$this, 'syncSataSelfTestRows']);
        $this->walkAndSyncSataTable('smartmonSataSelectiveTestTable',2, $devices, self::SATA_TID_SELECTIVE_TEST, [$this, 'syncSataSelectiveTestRows']);
        $this->walkAndSyncSataTable('smartmonSataLogDirTable',       2, $devices, self::SATA_TID_LOG_DIR,        [$this, 'syncSataLogDirRows']);
        $this->walkAndSyncSataTable('smartmonSataDevStatTable',      3, $devices, self::SATA_TID_DEV_STAT,       [$this, 'syncSataDevStatRows']);

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
        $device  = $this->os->getDevice();
        $diskKey = $dev['disk_key'];
        $devName = $this->sensorLabel($dev, $dev['snmp_index']);
        $idx     = $this->mibDiskIndex($diskKey);
        $nav     = $this->mibDiskNavigation($diskKey);
        $group   = 'SMART';

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
                    'sensor_navigation' => $nav,
                    'sensor_descr'      => "{$group} {$devName} Health",
                    'sensor_current'    => $synthesized,
                ]))
                ->withStateTranslations('smart_mib_health', [
                    StateTranslation::define('OK',                   1, Severity::Ok),
                    StateTranslation::define('Warning',              2, Severity::Warning),
                    StateTranslation::define('Warning: Attr Failed', 3, Severity::Warning),
                    StateTranslation::define('Error: Attr Failing',  4, Severity::Error),
                    StateTranslation::define('Unavailable',          5, Severity::Warning),
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
                    'sensor_navigation' => $nav,
                    'sensor_descr'      => "{$group} {$devName} Self-test Status",
                    'sensor_current'    => $statusNibble,
                ]))
                ->withStateTranslations('smart_selftest_status', [
                    StateTranslation::define('Completed without error',    0x0, Severity::Ok),
                    StateTranslation::define('Aborted by host',            0x1, Severity::Ok),
                    StateTranslation::define('Interrupted (host reset)',   0x2, Severity::Ok),
                    StateTranslation::define('Fatal or unknown error',     0x3, Severity::Warning),
                    StateTranslation::define('Completed: unknown failure', 0x4, Severity::Warning),
                    StateTranslation::define('Completed: electrical fail', 0x5, Severity::Warning),
                    StateTranslation::define('Completed: servo failure',   0x6, Severity::Warning),
                    StateTranslation::define('Completed: read failure',    0x7, Severity::Warning),
                    StateTranslation::define('Completed: handling damage', 0x8, Severity::Warning),
                    StateTranslation::define('Self-test in progress',      0xf, Severity::Ok),
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
        $device = $this->os->getDevice();
        $expected = [];
        foreach ($this->sataDevices() as $snmpIndex => $dev) {
            $idx = $this->mibDiskIndex($dev['disk_key']);
            foreach ($this->sensorRows[$snmpIndex] ?? [] as $sensorIdx => $row) {
                $type = $row['smartmonSensorType'] ?? null;
                $meta = self::SENSOR_TYPE_MAP[$type] ?? null;
                if ($meta !== null) {
                    $expected[] = "app:smart_mib:{$idx}_{$meta[2]}_{$sensorIdx}";
                }
            }
            $expected[] = "app:smart_mib:{$idx}_health";
            $expected[] = "app:smart_mib:{$idx}_selftest_status";
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
                ->where('app_id', $this->app->app_id)
                ->where('snmp_index', (int) $snmpIndex)
                ->update([
                    'last_poll_result' => $this->intValue($row['smartmonDeviceLastPollResult'] ?? null),
                    'last_poll_time'   => $row['smartmonDeviceLastPollTime'] ?? null,
                ]);
        }

        $pollFailures = DB::table('smart_devices')
            ->where('app_id', $this->app->app_id)
            ->where('last_poll_result', '>', 1)
            ->count();

        update_application($this->app, 'ok', [
            'poll_failures' => $pollFailures,
        ]);
    }

    /**
     * Poll all SATA tables: for each table walk once, then update per device.
     */
    private function pollSata(): void
    {
        $devices = $this->sataDevicesFromDb();

        // Table: Health (change-guarded; DB sync — sensors updated from DB in pollSensorValues)
        $this->walkAndSyncSataTable('smartmonSataHealthTable', 1, $devices, self::SATA_TID_HEALTH, [$this, 'syncSataHealthRow']);

        // Table: Attributes (change-guarded; limited columns for DB sync + RRD)
        $this->walkAndSyncSataAttrPoll($devices);

        // SENSOR-MIB: only smartmonSensorValue (common to all device types)
        $this->pollSensorValues($devices);

        // Change-guarded tables:
        $this->walkAndSyncSataPhyEventPoll($devices);
        $this->walkAndSyncSataDevStatPoll($devices);
        $this->walkAndSyncSataTable('smartmonSataErrorLogTable',      2, $devices, self::SATA_TID_ERROR_LOG,       [$this, 'syncSataErrorLogRows']);
        $this->walkAndSyncSataTable('smartmonSataErrorCmdTable',      3, $devices, self::SATA_TID_ERROR_LOG,       [$this, 'syncSataErrorCmdRows']);
        $this->walkAndSyncSataTable('smartmonSataSelfTestTable',      2, $devices, self::SATA_TID_SELFTEST,        [$this, 'syncSataSelfTestRows']);
        $this->walkAndSyncSataTable('smartmonSataSelectiveTestTable', 2, $devices, self::SATA_TID_SELECTIVE_TEST,  [$this, 'syncSataSelectiveTestRows']);
        $this->walkAndSyncSataTable('smartmonSataPendingDefectsTable',2, $devices, self::SATA_TID_PENDING_DEFECTS, [$this, 'syncSataPendingDefectRows']);

        $this->persistSataChangeSnapshot();
        update_application($this->app, 'ok', $this->app->data);
    }

    /**
     * Walk the four poll-relevant attribute columns (change-guarded) and sync DB + RRD
     * for devices whose attribute table has changed since last poll.
     */
    private function walkAndSyncSataAttrPoll(array $devices): void
    {
        $this->sataChangeByDeviceTable();
        if (! Debug::isVerbose() && ! $this->anySataDeviceChangedForTable(self::SATA_TID_ATTR)) {
            return;
        }

        foreach ($this->walkSataAttrLimitedColumns() as $devIdx => $attrRows) {
            if (! isset($devices[$devIdx]) || ! $this->sataTableChangedForDevice($devIdx, self::SATA_TID_ATTR)) {
                continue;
            }
            $dev = $devices[$devIdx];
            $this->syncSataAttributeRowsPoll($dev, $attrRows);
            $this->pollSataDeviceRrd($dev, $attrRows);
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
                foreach ($items as $idx2 => $value) {
                    $result[(string) $devIdx][(string) $idx2][$col] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Walk only the four frequently-changing SATA attribute columns.
     * Returns [devIdx][attrId] => ['smartmonSataAttr...' => value, ...]
     */
    private function walkSataAttrLimitedColumns(): array
    {
        return $this->walkSataColumns([
            'smartmonSataAttrRawValue',
            'smartmonSataAttrRawString',
            'smartmonSataAttrStatus',
            'smartmonSataAttrValue',
        ]);
    }

    /**
     * Poll only smartmonSensorValue for all devices (one SNMP walk, all types).
     * Health and self-test state sensors are read from the DB.
     */
    private function pollSensorValues(array $devices): void
    {
        $device = $this->os->getDevice();

        $sensorValues = SnmpQuery::mibs(self::SENSOR_MIBS)
            ->hideMib()
            ->walk('SMARTMON-SENSOR-MIB::smartmonSensorValue')
            ->table(2);

        foreach ($devices as $devIdx => $dev) {
            $diskKey = $dev['disk_key'];
            $idx     = $this->mibDiskIndex($diskKey);

            $sensors = Sensor::where('device_id', $device['device_id'])
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
            foreach ($sensorValues[(string) $devIdx] ?? [] as $sensorIdx => $rawValue) {
                if ($sensor = $bySuffix[(string) $sensorIdx] ?? null) {
                    $this->updateMibSensor($device, $sensor, (float) $rawValue);
                }
            }

            // Health state sensor — synthesized from DB
            if ($sensor = $sensors->get("{$idx}_health")) {
                $value = $this->synthesizeHealthFromDb($diskKey);
                $this->updateMibSensor($device, $sensor, $value !== null ? (float) $value : null);
            }

            // Self-test execution status from DB
            if ($sensor = $sensors->get("{$idx}_selftest_status")) {
                $raw = DB::table('smart_sata_health')
                    ->where('app_id', $this->app->app_id)
                    ->where('disk_key', $diskKey)
                    ->value('selftest_exec_status_raw');
                $this->updateMibSensor($device, $sensor, $raw !== null ? (float) $raw : null);
            }
        }
    }

    /** Write per-disk RRDs for one SATA device. */
    private function pollSataDeviceRrd(array $dev, array $attrRows): void
    {
        $device  = $this->os->getDevice();
        $diskKey = $dev['disk_key'];
        $idx     = $this->mibDiskIndex($diskKey);

        // Attribute RRD
        // V2 uses ['app','smart',app_id,idx] with DS id{N} / id{N}Normalized.
        if (! empty($attrRows)) {
            $rrdTypes = DB::table('smart_sata_attributes')
                ->where('app_id', $this->app->app_id)
                ->where('disk_key', $diskKey)
                ->pluck('rrd_type', 'attribute_id');

            $rrd_def = RrdDefinition::make();
            $fields  = [];
            foreach ($attrRows as $attrId => $row) {
                $id     = (int) ($row['smartmonSataAttrId'] ?? $attrId);
                $dsRaw  = 'id' . $id;
                $dsNorm = 'id' . $id . 'Normalized';
                if (strlen($dsNorm) > 19) {
                    continue;
                }
                $rawType = $rrdTypes[$id]
                    ?? (isset(self::ATA_COUNTER_ATTRS[$id]) ? 'COUNTER' : 'GAUGE');
                $rrd_def->addDataset($dsRaw,  $rawType, 0);
                $rrd_def->addDataset($dsNorm, 'GAUGE', 0);
                $fields[$dsRaw]  = $row['smartmonSataAttrRawValue'] ?? null;
                $fields[$dsNorm] = $row['smartmonSataAttrValue']    ?? null;
            }
            if (! empty($fields)) {
                app('Datastore')->put($device, 'app', [
                    'name'     => 'smart',
                    'app_id'   => $this->app->app_id,
                    'rrd_def'  => $rrd_def,
                    'rrd_name' => ['app', 'smart', $this->app->app_id, $idx],
                ], $fields);
            }
        }

    }

    private function updateMibSensor(array $device, Sensor $sensor, ?float $value): void
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

    // ── SNMP table fetchers ───────────────────────────────────────────────────

    private function commonMeta(): void
    {
        $response = SnmpQuery::mibs(self::COMMON_MIBS)->hideMib()->get([
            'SMARTMON-COMMON-MIB::smartmonDeviceTableRowCount.0',
            'SMARTMON-COMMON-MIB::smartmonDeviceTableLastChange.0',
            'SMARTMON-COMMON-MIB::smartmonDeviceCountNvme.0',
            'SMARTMON-COMMON-MIB::smartmonDeviceCountAta.0',
            // 'SMARTMON-COMMON-MIB::smartmonDeviceCountSas.0',  // SAS not yet implemented
        ]);

        $this->commonMeta = [
            'device_row_count'   => $response->value('smartmonDeviceTableRowCount.0'),
            'device_last_change' => $response->value('smartmonDeviceTableLastChange.0'),
            'device_count_nvme'  => $response->value('smartmonDeviceCountNvme.0'),
            'device_count_ata'   => $response->value('smartmonDeviceCountAta.0'),
            // 'device_count_sas' => ...,  // SAS not yet implemented
        ];
    }

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
                'device_name'          => $row['smartmonDeviceName']            ?? null,
                'device_path'          => $row['smartmonDevicePath']            ?? null,
                'device_type'          => $row['smartmonDeviceType'] ?? null,
                'last_poll_time'       => $row['smartmonDeviceLastPollTime']    ?? null,
                'last_poll_result'     => $row['smartmonDeviceLastPollResult'] ?? null,
                'last_poll_exit_status'=> $row['smartmonDeviceLastPollExitStatus'] ?? null,
                'physical_index'       => $row['smartmonDevicePhysicalIndex'] ?? null,
                'uris'                 => $row['smartmonDeviceUris']            ?? null,
                'model_family'         => $row['smartmonDeviceModelFamily']     ?? null,
                'model_name'           => $row['smartmonDeviceModelName']       ?? null,
                'serial_number'        => $row['smartmonDeviceSerialNumber']    ?? null,
                'firmware_version'     => $row['smartmonDeviceFirmwareVersion'] ?? null,
                'wwn'                  => $row['smartmonDeviceWwn']             ?? null,
            ];
        }
    }

    private function sataChangeByDeviceTable(): void
    {
        if (isset($this->sataChangeRows)) {
            return;
        }
        $this->prevSataChange         = $this->loadStoredSataChangeSnapshot();
        $this->sataChangeRows         = $this->walkSataTable('smartSATAChangeByDeviceLastChange',   2);
        $this->sataSubindexChangeRows = $this->walkSataTable('smartSATAChangeBySubindexLastChange', 3);
    }

    private function sataHealthTable(): void
    {
        $this->sataHealth = [];
        foreach ($this->walkSataTable('smartmonSataHealthTable', 1) as $index => $row) {
            if (is_array($row)) {
                $this->sataHealth[(string) $index] = $this->normalizeIntegerRow($row);
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

    /** Upsert all discovered devices into smart_devices. */
    private function syncDeviceRows(): void
    {
        foreach ($this->commonDevices as $snmpIndex => $dev) {
            DB::table('smart_devices')->upsert([
                'app_id'           => $this->app->app_id,
                'device_id'        => $this->os->getDeviceId(),
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
                'last_poll_time'   => $dev['last_poll_time'] ,
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
            'app_id'   => $this->app->app_id,
            'device_id'=> $this->os->getDeviceId(),
            'disk_key' => $dev['disk_key'],
        ] + $row, ['app_id', 'disk_key'], array_keys($row));
    }

    private function syncSataHealthRow(array $dev, array $row): void
    {
        DB::table('smart_sata_health')->upsert([
            'app_id'                     => $this->app->app_id,
            'device_id'                  => $this->os->getDeviceId(),
            'disk_key'                   => $dev['disk_key'],
            'overall_status'             => $this->snmpTruthValue($row['smartmonSataHealthOverallStatus']     ?? null),
            'offline_collection_status'  => $row['smartmonSataOfflineCollectionStatusValue']   ?? null,
            'selftest_exec_status_raw'   => $row['smartmonSataSelfTestExecutionStatusValue']   ?? null,
            'power_cycles'               => $row['smartmonSataPowerCycles']                    ?? null,
            'power_on_hours'             => $row['smartmonSataPowerOnHours']                   ?? null,
            'error_log_count'            => $row['smartmonSataErrorLogCount']                  ?? null,
            'pending_defects_count'      => $row['smartmonSataPendingDefectsCount']            ?? null,
            'selftest_log_count'         => $row['smartmonSataSelfTestLogCount']               ?? null,
            'selftest_log_err_total'     => $row['smartmonSataSelfTestLogErrTotal']            ?? null,
            'selftest_log_err_outdated'  => $row['smartmonSataSelfTestLogErrOutdated']         ?? null,
            'selftest_remaining_pct'     => $row['smartmonSataSelfTestExecutionRemainingPct']  ?? null,
            'sct_format_version'         => $row['smartmonSataSctStatusFormatVersion']         ?? null,
            'sct_version'                => $row['smartmonSataSctStatusSctVersion']            ?? null,
            'sct_device_state'           => $row['smartmonSataSctStatusDeviceState']           ?? null,
            'sct_temp_power_cycle_min'   => $row['smartmonSataSctTempPowerCycleMin']           ?? null,
            'sct_temp_power_cycle_max'   => $row['smartmonSataSctTempPowerCycleMax']           ?? null,
            'sct_temp_lifetime_min'      => $row['smartmonSataSctTempLifetimeMin']             ?? null,
            'sct_temp_lifetime_max'      => $row['smartmonSataSctTempLifetimeMax']             ?? null,
            'sct_temp_under_limit_count' => $row['smartmonSataSctTempUnderLimitCount']         ?? null,
            'sct_temp_over_limit_count'  => $row['smartmonSataSctTempOverLimitCount']          ?? null,
            'sct_smart_status_passed'    => $this->snmpTruthValue($row['smartmonSataSctSmartStatusPassed']     ?? null),
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
        ]);
    }

    private function syncSataAttributeRows(array $dev, array $attrRows): void
    {
        foreach ($attrRows as $attrId => $row) {
            DB::table('smart_sata_attributes')->upsert([
                'app_id'           => $this->app->app_id,
                'device_id'        => $this->os->getDeviceId(),
                'disk_key'         => $dev['disk_key'],
                'attribute_id'     => (int) ($row['smartmonSataAttrId'] ?? $attrId),
                'name'             => $row['smartmonSataAttrName']      ?? null,
                'attr_type'        => $row['smartmonSataAttrType']      ?? null,
                'updated_when'     => $row['smartmonSataAttrUpdated']   ?? null,
                'value_norm'       => $row['smartmonSataAttrValue']     ?? null,
                'value_worst'      => $row['smartmonSataAttrWorst']     ?? null,
                'value_threshold'  => $row['smartmonSataAttrThreshold'] ?? null,
                'value_raw'        => $row['smartmonSataAttrRawValue']  ?? null,
                'value_raw_string' => isset($row['smartmonSataAttrRawString'])
                    ? substr((string) $row['smartmonSataAttrRawString'], 0, 32)
                    : null,
                'status'           => $row['smartmonSataAttrStatus']    ?? null,
                'rrd_type'         => in_array($row['smartmonSataAttrName'] ?? null, self::ATA_COUNTER_ATTRS, true)
                    ? 'COUNTER' : 'GAUGE',
            ], ['app_id', 'disk_key', 'attribute_id'], [
                'name', 'attr_type', 'updated_when', 'value_norm', 'value_worst',
                'value_threshold', 'value_raw', 'value_raw_string', 'status', 'rrd_type',
            ]);
        }
    }

    /** Update only the four poll-relevant attribute columns; discovery keeps the rest. */
    private function syncSataAttributeRowsPoll(array $dev, array $attrRows): void
    {
        foreach ($attrRows as $attrId => $row) {
            DB::table('smart_sata_attributes')->upsert([
                'app_id'           => $this->app->app_id,
                'device_id'        => $this->os->getDeviceId(),
                'disk_key'         => $dev['disk_key'],
                'attribute_id'     => (int) $attrId,
                'value_norm'       => $row['smartmonSataAttrValue']    ?? null,
                'value_raw'        => $row['smartmonSataAttrRawValue'] ?? null,
                'value_raw_string' => isset($row['smartmonSataAttrRawString'])
                    ? substr((string) $row['smartmonSataAttrRawString'], 0, 32)
                    : null,
                'status'           => $row['smartmonSataAttrStatus']   ?? null,
            ], ['app_id', 'disk_key', 'attribute_id'], [
                'value_norm', 'value_raw', 'value_raw_string', 'status',
            ]);
        }
    }

    private function syncSataErcRows(array $dev, array $rows): void
    {
        foreach ($rows as $direction => $row) {
            DB::table('smart_sata_erc')->upsert([
                'app_id'      => $this->app->app_id,
                'device_id'   => $this->os->getDeviceId(),
                'disk_key'    => $dev['disk_key'],
                'direction'   => (int) $direction,
                'enabled'     => $this->snmpTruthValue($row['smartmonSataErcEnabled']    ?? null),
                'deciseconds' => $row['smartmonSataErcDeciseconds'] ?? null,
            ], ['app_id', 'disk_key', 'direction'], ['enabled', 'deciseconds']);
        }
        DB::table('smart_sata_erc')
            ->where('app_id', $this->app->app_id)
            ->where('disk_key', $dev['disk_key'])
            ->whereNotIn('direction', array_keys($rows))
            ->delete();
    }

    /** Full discovery sync: name + size_bytes + value + overflow. */
    private function syncSataPhyEventRows(array $dev, array $rows): void
    {
        foreach ($rows as $eventId => $row) {
            DB::table('smart_sata_phy_events')->upsert([
                'app_id'     => $this->app->app_id,
                'device_id'  => $this->os->getDeviceId(),
                'disk_key'   => $dev['disk_key'],
                'event_id'   => (int) $eventId,
                'name'       => isset($row['smartmonSataPhyEventName'])
                    ? substr((string) $row['smartmonSataPhyEventName'], 0, 128) : null,
                'size_bytes' => $row['smartmonSataPhyEventSize']     ?? null,
                'value'      => $row['smartmonSataPhyEventValue']    ?? null,
                'overflow'   => $this->snmpTruthValue($row['smartmonSataPhyEventOverflow'] ?? null),
            ], ['app_id', 'disk_key', 'event_id'], ['name', 'size_bytes', 'value', 'overflow']);
        }
        DB::table('smart_sata_phy_events')
            ->where('app_id', $this->app->app_id)
            ->where('disk_key', $dev['disk_key'])
            ->whereNotIn('event_id', array_keys($rows))
            ->delete();
    }

    /** Poll-only update: value + overflow, no name/size walk needed. */
    private function syncSataPhyEventValueRows(array $dev, array $rows): void
    {
        $upsertRows = [];
        foreach ($rows as $eventId => $row) {
            $upsertRows[] = [
                'app_id'    => $this->app->app_id,
                'device_id' => $this->os->getDeviceId(),
                'disk_key'  => $dev['disk_key'],
                'event_id'  => (int) $eventId,
                'value'     => $row['smartmonSataPhyEventValue']    ?? null,
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
                'app_id'          => $this->app->app_id,
                'device_id'       => $this->os->getDeviceId(),
                'disk_key'        => $dev['disk_key'],
                'entry_num'       => (int) $errorIndex,
                'error_count'     => $row['smartmonSataErrorNumber']         ?? null,
                'lifetime_hours'  => $row['smartmonSataErrorLifetimeHours']  ?? null,
                'error_type'      => isset($row['smartmonSataErrorDescription'])
                    ? substr((string) $row['smartmonSataErrorDescription'], 0, 64) : null,
                'device_state'    => $row['smartmonSataErrorState']          ?? null,
                'status_register' => $row['smartmonSataErrorCompRegStatus']  ?? null,
                'error_register'  => $row['smartmonSataErrorCompRegError']   ?? null,
            ], ['app_id', 'disk_key', 'entry_num'], [
                'error_count', 'lifetime_hours', 'error_type',
                'device_state', 'status_register', 'error_register',
            ]);
        }
        DB::table('smart_sata_error_log')
            ->where('app_id', $this->app->app_id)
            ->where('disk_key', $dev['disk_key'])
            ->whereNotIn('entry_num', array_keys($rows))
            ->delete();
    }

    private function syncSataErrorCmdRows(array $dev, array $rows): void
    {
        foreach ($rows as $errorIndex => $cmdRows) {
            if (! is_array($cmdRows)) {
                continue;
            }
            foreach ($cmdRows as $cmdIndex => $row) {
                DB::table('smart_sata_error_cmd')->upsert([
                    'app_id'          => $this->app->app_id,
                    'device_id'       => $this->os->getDeviceId(),
                    'disk_key'        => $dev['disk_key'],
                    'error_entry_num' => (int) $errorIndex,
                    'cmd_slot'        => (int) $cmdIndex,
                    'reg_command'     => $row['smartmonSataErrorCmdRegCommand']     ?? null,
                    'reg_count'       => $row['smartmonSataErrorCmdRegCount']       ?? null,
                    'reg_device'      => $row['smartmonSataErrorCmdRegDevice']      ?? null,
                    'reg_error'       => $row['smartmonSataErrorCmdRegError']       ?? null,
                    'reg_feature'     => $row['smartmonSataErrorCmdRegFeature']     ?? null,
                    'reg_lba'         => $row['smartmonSataErrorCmdRegLba']         ?? null,
                    'powerup_ms'      => $row['smartmonSataErrorCmdTimestamp']      ?? null,
                    'description'     => isset($row['smartmonSataErrorCmdDescription'])
                        ? substr((string) $row['smartmonSataErrorCmdDescription'], 0, 128) : null,
                ], ['app_id', 'disk_key', 'error_entry_num', 'cmd_slot'], [
                    'reg_command', 'reg_count', 'reg_device', 'reg_error',
                    'reg_feature', 'reg_lba', 'powerup_ms', 'description',
                ]);
            }
            DB::table('smart_sata_error_cmd')
                ->where('app_id', $this->app->app_id)
                ->where('disk_key', $dev['disk_key'])
                ->where('error_entry_num', (int) $errorIndex)
                ->whereNotIn('cmd_slot', array_keys($cmdRows))
                ->delete();
        }
        DB::table('smart_sata_error_cmd')
            ->where('app_id', $this->app->app_id)
            ->where('disk_key', $dev['disk_key'])
            ->whereNotIn('error_entry_num', array_keys($rows))
            ->delete();
    }

    private function syncSataSelfTestRows(array $dev, array $rows): void
    {
        foreach ($rows as $testIndex => $row) {
            DB::table('smart_sata_selftest_log')->upsert([
                'app_id'          => $this->app->app_id,
                'device_id'       => $this->os->getDeviceId(),
                'disk_key'        => $dev['disk_key'],
                'entry_num'       => (int) $testIndex,
                'test_type'       => $row['smartmonSataSelfTestType']          ?? null,
                'result'          => $row['smartmonSataSelfTestResult']        ?? null,
                'result_passed'   => $this->snmpTruthValue($row['smartmonSataSelfTestResultPassed'] ?? null),
                'remaining_pct'   => $row['smartmonSataSelfTestRemainingPct']  ?? null,
                'power_on_hours'  => $row['smartmonSataSelfTestLifetimeHours'] ?? null,
                'lba_first_error' => $row['smartmonSataSelfTestLbaFirstError'] ?? null,
            ], ['app_id', 'disk_key', 'entry_num'], [
                'test_type', 'result', 'result_passed', 'remaining_pct', 'power_on_hours', 'lba_first_error',
            ]);
        }
        DB::table('smart_sata_selftest_log')
            ->where('app_id', $this->app->app_id)
            ->where('disk_key', $dev['disk_key'])
            ->whereNotIn('entry_num', array_keys($rows))
            ->delete();
    }

    private function syncSataSelectiveTestRows(array $dev, array $rows): void
    {
        foreach ($rows as $slot => $row) {
            DB::table('smart_sata_selective_test')->upsert([
                'app_id'       => $this->app->app_id,
                'device_id'    => $this->os->getDeviceId(),
                'disk_key'     => $dev['disk_key'],
                'slot'         => (int) $slot,
                'lba_min'      => $row['smartmonSataSelectiveLbaMin']      ?? null,
                'lba_max'      => $row['smartmonSataSelectiveLbaMax']      ?? null,
                'status_value' => $row['smartmonSataSelectiveStatusValue'] ?? null,
            ], ['app_id', 'disk_key', 'slot'], ['lba_min', 'lba_max', 'status_value']);
        }
        DB::table('smart_sata_selective_test')
            ->where('app_id', $this->app->app_id)
            ->where('disk_key', $dev['disk_key'])
            ->whereNotIn('slot', array_keys($rows))
            ->delete();
    }

    private function syncSataLogDirRows(array $dev, array $rows): void
    {
        foreach ($rows as $address => $row) {
            DB::table('smart_sata_log_dir')->upsert([
                'app_id'        => $this->app->app_id,
                'device_id'     => $this->os->getDeviceId(),
                'disk_key'      => $dev['disk_key'],
                'log_address'   => (int) $address,
                'name'          => isset($row['smartmonSataLogDirName'])
                    ? substr((string) $row['smartmonSataLogDirName'], 0, 128) : null,
                'readable'      => $this->snmpTruthValue($row['smartmonSataLogDirReadable'] ?? null),
                'writable'      => $this->snmpTruthValue($row['smartmonSataLogDirWritable'] ?? null),
                'gp_sectors'    => $row['smartmonSataLogDirGpSectors']    ?? null,
                'smart_sectors' => $row['smartmonSataLogDirSmartSectors'] ?? null,
            ], ['app_id', 'disk_key', 'log_address'], [
                'name', 'readable', 'writable', 'gp_sectors', 'smart_sectors',
            ]);
        }
        DB::table('smart_sata_log_dir')
            ->where('app_id', $this->app->app_id)
            ->where('disk_key', $dev['disk_key'])
            ->whereNotIn('log_address', array_keys($rows))
            ->delete();
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
                $flagsRaw   = $this->parseBitsValue($row['smartmonSataDevStatFlagsValue'] ?? null);
                $valid      = $flagsRaw !== null ? (bool) ($flagsRaw & 0x40) : null;
                $normalized = $flagsRaw !== null ? (bool) ($flagsRaw & 0x20) : null;

                DB::table('smart_sata_dev_stats')->upsert([
                    'app_id'      => $this->app->app_id,
                    'device_id'   => $this->os->getDeviceId(),
                    'disk_key'    => $dev['disk_key'],
                    'page_num'    => (int) $pageNum,
                    'stat_offset' => (int) $offset,
                    'page_name'   => isset($row['smartmonSataDevStatPageName'])
                        ? substr((string) $row['smartmonSataDevStatPageName'], 0, 64) : null,
                    'stat_name'   => isset($row['smartmonSataDevStatName'])
                        ? substr((string) $row['smartmonSataDevStatName'], 0, 128) : null,
                    'value'       => $row['smartmonSataDevStatValue']      ?? null,
                    'flags_value' => $flagsRaw,
                    'valid'       => $valid,
                    'normalized'  => $normalized,
                ], ['app_id', 'disk_key', 'page_num', 'stat_offset'], [
                    'page_name', 'stat_name', 'value', 'flags_value', 'valid', 'normalized',
                ]);
            }
            DB::table('smart_sata_dev_stats')
                ->where('app_id', $this->app->app_id)
                ->where('disk_key', $dev['disk_key'])
                ->where('page_num', (int) $pageNum)
                ->whereNotIn('stat_offset', array_keys($offsets))
                ->delete();
        }
        DB::table('smart_sata_dev_stats')
            ->where('app_id', $this->app->app_id)
            ->where('disk_key', $dev['disk_key'])
            ->whereNotIn('page_num', array_keys($rows))
            ->delete();
    }

    private function syncSataPendingDefectRows(array $dev, array $rows): void
    {
        foreach ($rows as $entryIndex => $row) {
            DB::table('smart_sata_pending_defects')->upsert([
                'app_id'    => $this->app->app_id,
                'device_id' => $this->os->getDeviceId(),
                'disk_key'  => $dev['disk_key'],
                'entry_num' => (int) $entryIndex,
                'lba'       => $row['smartmonSataPendingDefectsLba'] ?? null,
            ], ['app_id', 'disk_key', 'entry_num'], ['lba']);
        }
        DB::table('smart_sata_pending_defects')
            ->where('app_id', $this->app->app_id)
            ->where('disk_key', $dev['disk_key'])
            ->whereNotIn('entry_num', array_keys($rows))
            ->delete();
    }

    /** Poll-time narrowed walk: value + overflow only (name/size already in DB from discovery). */
    private function walkAndSyncSataPhyEventPoll(array $devices): void
    {
        $this->sataChangeByDeviceTable();
        if (! Debug::isVerbose() && ! $this->anySataDeviceChangedForTable(self::SATA_TID_PHY_EVENT)) {
            return;
        }

        $valueRows    = $this->walkSataTable('smartmonSataPhyEventValue',    2);
        $overflowRows = $this->walkSataTable('smartmonSataPhyEventOverflow', 2);

        foreach ($devices as $devIdx => $dev) {
            if (! $this->sataTableChangedForDevice((string) $devIdx, self::SATA_TID_PHY_EVENT)) {
                continue;
            }
            $merged = [];
            foreach ($valueRows[(string) $devIdx] ?? [] as $eventId => $value) {
                $merged[(string) $eventId] = [
                    'smartmonSataPhyEventValue'    => $value,
                    'smartmonSataPhyEventOverflow' => $overflowRows[(string) $devIdx][$eventId] ?? null,
                ];
            }
            $this->syncSataPhyEventValueRows($dev, $merged);
        }
    }

    /**
     * Poll-time narrowed walk for DevStat: only value column, with two-level change guards
     * (device-level via sataChangeByDeviceTable, page-level via sataSubindexChangeRows).
     */
    private function walkAndSyncSataDevStatPoll(array $devices): void
    {
        $this->sataChangeByDeviceTable();
        if (! Debug::isVerbose() && ! $this->anySataDeviceChangedForTable(self::SATA_TID_DEV_STAT)) {
            return;
        }

        // Single walk for all devices; depth=3 gives [devIdx][pageNum][offset] => value.
        $allValueRows = $this->walkSataTable('smartmonSataDevStatValue', 3);

        foreach ($devices as $devIdx => $dev) {
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
                        'app_id'      => $this->app->app_id,
                        'device_id'   => $this->os->getDeviceId(),
                        'disk_key'    => $dev['disk_key'],
                        'page_num'    => (int) $pageNum,
                        'stat_offset' => (int) $offset,
                        'value'       => $value,
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
        $prev    = $this->prevSataChange !== null ? ($this->prevSataChange[$devIdx][$tableId][0] ?? null) : null;

        return $current !== $prev;
    }

    private function sataTableChangedForDevicePage(string $devIdx, int $tableId, int $subindex): bool
    {
        $current = $this->sataSubindexChangeRows[$devIdx][$tableId][$subindex] ?? null;
        $prev    = $this->prevSataChange !== null ? ($this->prevSataChange[$devIdx][$tableId][$subindex] ?? null) : null;

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
     */
    private function walkAndSyncSataTable(
        string $table, int $depth, array $devices, int $tableId, callable $sync
    ): void {
        $this->sataChangeByDeviceTable();
        if (! Debug::isVerbose() && ! $this->anySataDeviceChangedForTable($tableId)) {
            return;
        }

        foreach ($this->normalizeNestedIntegerRows($this->walkSataTable($table, $depth)) as $devIdx => $rows) {
            if (isset($devices[$devIdx]) && $this->sataTableChangedForDevice($devIdx, $tableId)) {
                $sync($devices[$devIdx], $rows);
            }
        }
    }

    private function loadStoredSataChangeSnapshot(): ?array
    {
        $rows = DB::table('smart_sata_change')
            ->where('app_id', $this->app->app_id)
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
                        'app_id'      => $this->app->app_id,
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
                            'app_id'      => $this->app->app_id,
                            'device_idx'  => (int) $devIdx,
                            'table_id'    => (int) $tableId,
                            'subindex'    => (int) $subindex,
                            'last_change' => $ts,
                        ];
                    }
                }
            }
        }

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
        $deviceModel = Device::find($this->os->getDeviceId());
        if ($deviceModel === null) {
            return;
        }

        $rrd = app(Rrd::class);

        foreach ($this->sataDevices() as $dev) {
            $diskKey = $dev['disk_key'];

            $alreadyDone = DB::table('smart_devices')
                ->where('app_id', $this->app->app_id)
                ->where('disk_key', $diskKey)
                ->value('v1_rrd_migrated');

            if ($alreadyDone) {
                continue;
            }

            $v2Idx = $this->mibDiskIndex($diskKey);
            $v2Name = ['app', 'smart', $this->app->app_id, $v2Idx];

            // V1 used the raw device path as the disk ID (e.g. /dev/sda).
            $v1DiskId = $dev['device_path'];
            if (! empty($v1DiskId)) {
                $v1Name = ['app', 'smart', $this->app->app_id, $v1DiskId];
                $rrd->renameFile($deviceModel, $v1Name, $v2Name);
            }

            // Strip V1-only DS; no-op if they're absent or the file doesn't exist.
            $rrdFile = Rrd::name($deviceModel->hostname, $v2Name);
            $rrd->discardDatasets($rrdFile, self::V1_SATA_DISCARD_DS);

            DB::table('smart_devices')
                ->where('app_id', $this->app->app_id)
                ->where('disk_key', $diskKey)
                ->update(['v1_rrd_migrated' => 1]);
        }
    }

    // ── Handler detection ─────────────────────────────────────────────────────

    /** Detect handler type on first run and persist it; return stored value otherwise. */
    private function detectAndPersistHandler(): string
    {
        $handler = DB::table('smart_app_state')
            ->where('app_id', $this->app->app_id)
            ->value('handler') ?: null;

        if ($handler !== null) {
            return $handler;
        }

        $response = SnmpQuery::mibs(self::COMMON_MIBS)
            ->hideMib()
            ->get('SMARTMON-COMMON-MIB::smartmonDeviceTableRowCount.0');
        $handler = ($response->isValid() && $response->value('smartmonDeviceTableRowCount.0') !== '')
            ? self::HANDLER_MIB
            : self::HANDLER_V1;

        DB::table('smart_app_state')->upsert(
            ['app_id' => $this->app->app_id, 'handler' => $handler],
            ['app_id'],
            ['handler']
        );

        return $handler;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Return only SATA/ATA devices from the common device table. */
    private function sataDevices(): array
    {
        if ($this->commonDevices === null) {
            $snmpTs = SnmpQuery::mibs(self::COMMON_MIBS)->hideMib()
                ->get('SMARTMON-COMMON-MIB::smartmonDeviceTableLastChange.0')
                ->value('smartmonDeviceTableLastChange.0');

            $storedTs = DB::table('smart_app_state')
                ->where('app_id', $this->app->app_id)
                ->value('device_table_last_change');

            $this->commonDeviceTable();

            if ($snmpTs !== $storedTs) {
                $this->syncDeviceRows();
                DB::table('smart_app_state')
                    ->where('app_id', $this->app->app_id)
                    ->update(['device_table_last_change' => $snmpTs]);
            }
        }

        return array_filter(
            $this->commonDevices,
            fn($dev) => in_array($dev['device_type'] ?? 0, self::SATA_TYPES, true)
        );
    }

    /** Load SATA devices from DB, keyed by snmp_index. Used during polling to avoid SNMP walk. */
    private function sataDevicesFromDb(): array
    {
        $rows = DB::table('smart_devices')
            ->where('app_id', $this->app->app_id)
            ->whereIn('protocol_type', self::SATA_TYPES)
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

    /**
     * Synthesize a 1–5 health value from the SATA health row and all attribute statuses.
     *
     *  1 = OK
     *  2 = Warning  (SMART overall test not passed)
     *  3 = Warning  (an attribute has failed in the past)
     *  4 = Error    (an attribute is currently failing)
     *  5 = Unavailable
     */
    private function synthesizeHealthStatus(array $health, array $attrs): int
    {
        $overall = $health['smartmonSataHealthOverallStatus'] ?? null;

        if ($overall === 4) {
            return 5; // unavailable
        }

        $result = 1;

        if ($overall !== null && $overall !== 1) {
            $result = 2;
        }

        foreach ($attrs as $row) {
            if (! is_array($row)) {
                continue;
            }
            $status = $row['smartmonSataAttrStatus'] ?? null;
            if ($status === 3) {       // failedInPast
                $result = max($result, 3);
            } elseif ($status === 2) { // failingNow
                $result = max($result, 4);
            }
        }

        return $result;
    }

    /** Load health + attribute statuses from DB and run the same 1–5 synthesis. */
    private function synthesizeHealthFromDb(string $diskKey): ?int
    {
        $health = DB::table('smart_sata_health')
            ->where('app_id', $this->app->app_id)
            ->where('disk_key', $diskKey)
            ->first(['overall_status', 'selftest_exec_status_raw']);

        if ($health === null) {
            return null;
        }

        $overall = $health->overall_status;
        if ($overall === 4) {
            return 5; // unavailable
        }

        $result = 1;
        if ($overall !== null && $overall !== 1) {
            $result = 2;
        }

        $statuses = DB::table('smart_sata_attributes')
            ->where('app_id', $this->app->app_id)
            ->where('disk_key', $diskKey)
            ->pluck('status');

        foreach ($statuses as $status) {
            if ($status === 3) {       // failedInPast
                $result = max($result, 3);
            } elseif ($status === 2) { // failingNow
                $result = max($result, 4);
            }
        }

        return $result;
    }

    /** Human-readable sensor label: "Model Serial (name)" or graceful fallbacks. */
    private function sensorLabel(array $dev, string $fallback): string
    {
        $model  = trim((string) ($dev['model_name']    ?? ''));
        $serial = trim((string) ($dev['serial_number'] ?? ''));
        $name   = trim((string) ($dev['device_name']   ?? ''));

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

    /** UI navigation link for a MIB-sourced disk. */
    private function mibDiskNavigation(string $diskKey): string
    {
        return 'tab=apps/app=smart/disk=' . rawurlencode($diskKey) . '/';
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
        $exp       = self::SENSOR_SCALE_EXP[$scaleEnum] ?? 0;

        return (float) $raw * (10 ** ($exp - $precision));
    }

    private function applySensorScale(array $row): ?float
    {
        return $this->applySensorScaleCol($row, 'smartmonSensorValue');
    }

    /**
     * Extract wear-remaining percentage from ATA/SATA attribute rows.
     * Returns null when no SSD wear attribute is present.
     */
    private function extractAtaWear(array $attrRows): ?float
    {
        foreach (self::ATA_WEAR_ATTR_IDS as $id) {
            $row = $attrRows[$id] ?? $attrRows[(string) $id] ?? null;
            if ($row === null) {
                foreach ($attrRows as $candidate) {
                    if (is_array($candidate) && (int) ($candidate['smartmonSataAttrId'] ?? -1) === $id) {
                        $row = $candidate;
                        break;
                    }
                }
            }
            if ($row !== null) {
                $norm = $row['smartmonSataAttrValue'] ?? null;
                if ($norm !== null) {
                    return (float) $norm;
                }
            }
        }

        return null;
    }

    private function pollFailureCount(array $devices): int
    {
        $count = 0;
        foreach ($devices as $device) {
            if (($device['last_poll_result'] ?? 1) !== 1 || ($device['last_poll_exit_status'] ?? 0) !== 0) {
                $count++;
            }
        }

        return $count;
    }

    private function walkSataTable(string $table, int $group): array
    {
        return SnmpQuery::mibs(self::SATA_MIBS)
            ->hideMib()
            ->walk("SMARTMON-SATA-MIB::$table")
            ->table($group);
    }

    private function diskKey(array $row, string $fallback): string
    {
        $wwn = trim((string) ($row['smartmonDeviceWwn'] ?? ''));
        if ($wwn !== '') {
            return $wwn;
        }

        $model  = trim((string) ($row['smartmonDeviceModelName']   ?? ''));
        $serial = trim((string) ($row['smartmonDeviceSerialNumber'] ?? ''));
        if ($model !== '' || $serial !== '') {
            return $model . '+' . $serial;
        }

        return $fallback;
    }

    private function normalizeIntegerRow(array $row): array
    {
        foreach ($row as $key => $value) {
            $integer = $value;
            if ($integer !== null) {
                $row[$key] = $integer;
            }
        }

        return $row;
    }

    private function normalizeNestedIntegerRows(array $data): array
    {
        foreach ($data as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            $data[$key] = $this->isLeafRow($value)
                ? $this->normalizeIntegerRow($value)
                : $this->normalizeNestedIntegerRows($value);
        }

        return $data;
    }

    private function isLeafRow(array $row): bool
    {
        foreach (array_keys($row) as $key) {
            if (is_string($key) && str_starts_with($key, 'smart')) {
                return true;
            }
        }

        return false;
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

    /** Convert SNMPv2 TruthValue to 1/0/null. TruthValue enum: true(1), false(2). */
    private function snmpTruthValue(mixed $value): ?int
    {
        $int = $value;
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
