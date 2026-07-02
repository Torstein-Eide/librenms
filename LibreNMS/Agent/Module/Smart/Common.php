<?php

namespace LibreNMS\Agent\Module\Smart;

use App\Models\Sensor;
use Illuminate\Support\Facades\DB;
use LibreNMS\Agent\Application;
use LibreNMS\Agent\Module\Smart\Handler\NvmeHandler;
use LibreNMS\Agent\Module\Smart\Handler\SataHandler;
use LibreNMS\Agent\Module\Smart\Helpers\DiskIdentity;
use LibreNMS\Agent\Module\Smart\Support\DbSync;
use LibreNMS\Agent\Module\Smart\Support\SelftestAge;
use LibreNMS\Agent\Module\Smart\Support\SnmpDecode as SmartSnmpDecode;
use LibreNMS\Util\Debug;
use LibreNMS\Util\SnmpDecode;
use SnmpQuery;

/**
 * SMART application dispatcher.
 *
 * Detects the available data source on first run and routes all subsequent
 * discover/poll calls to the correct handler:
 *   mib  →  SNMP SMARTMON-*-MIB path (this class, full sensor + DB pipeline)
 *   v1   →  SmartJsonV1 (Unix-agent / SNMP-extend JSON payload)
 *
 * Handler type is stored in smart_app_state after the first successful probe.
 * If neither source is reachable during discover(), all stale DB data is wiped.
 */
class Common extends Application
{
    private const COMMON_MIBS = ['SMARTMON-TC-MIB', 'SMARTMON-COMMON-MIB'];
    private const SENSOR_MIBS = ['SMARTMON-TC-MIB', 'SMARTMON-COMMON-MIB', 'SMARTMON-SENSOR-MIB'];

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

    private const HANDLER_MIB = 'mib'; // SMARTMON-*-MIB
    private const HANDLER_V1 = 'v1';  // Json

    /**
     * ATA attributes whose raw RRD DS should be COUNTER rather than GAUGE,
     * keyed by [id => smartmontools name]. Shared by every SATA/ATA
     * pipeline: SataHandler (SNMP-MIB path, which can also classify by
     * attribute name via its isCounterAttrName() heuristic) and SmartJsonV1
     * (Unix-agent JSON path, which only ever sees a fixed attribute ID with
     * no name available, so it can only apply this ID-keyed part).
     */
    public const ATA_COUNTER_ATTRS = [
        179 => 'Used_Rsvd_Blk_Cnt_Tot',
        180 => 'Unused_Rsvd_Blk_Cnt_Tot',
        241 => 'Total_LBAs_Written',
        242 => 'Total_LBAs_Read',
        245 => 'Timed_Workld_Media_Wear',
        246 => 'Timed_Workld_RdWr_Ratio',
        247 => 'Timed_Workld_Timer',
        251 => 'NAND_Writes',
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

    private array  $sensorRows = [];

    // Stable per-run identity context, initialized at the top of discover()/poll().
    private Context $context;
    private DeviceTable $deviceTable;
    private SataHandler $sataHandler;
    private NvmeHandler $nvmeHandler;
    // SATA / NVMe device lists for the current run, keyed by snmp_index.
    private array   $sataDeviceList = [];
    private array   $nvmeDeviceList = [];

    // ── Public interface ──────────────────────────────────────────────────────

    public function shouldDiscover(): bool
    {
        $rowCount = (int) (
            SnmpQuery::mibs(self::COMMON_MIBS)->mibDir('smart')->hideMib()
                ->get('SMARTMON-COMMON-MIB::smartmonDeviceTableRowCount.0')
                ->value('smartmonDeviceTableRowCount.0')
        );

        $this->vlog("shouldDiscover: MIB rowCount={$rowCount}");

        if ($rowCount > 0) {
            return true;
        }

        // A stored handler means we have a known data source (mib or v1). Always
        // re-run discovery to refresh data and pick up handler-type changes.
        if (DB::table('smart_app_state')->where('app_id', $this->app->app_id)->exists()) {
            $this->vlog('shouldDiscover: stored handler found, running discovery');

            return true;
        }

        // No MIB and no stored handler yet: probe V1 JSON for first-time detection.
        if ($this->v1PayloadAvailable()) {
            $this->vlog('shouldDiscover: no MIB, V1 payload available');

            return true;
        }

        // No data source found. Still run if the DB holds rows from a previous
        // deployment so the cleanup pass can remove them.
        $hasRows = DB::table('smart_devices')
            ->where('app_id', $this->app->app_id)
            ->exists();
        $this->vlog('shouldDiscover: no data source, DB ' . ($hasRows ? 'non-empty, running for cleanup' : 'empty, skipping'));

        return $hasRows;
    }

    public function discover(): void
    {
        $this->initContext();

        $handler = $this->detectAndPersistHandler();
        $this->vlog('discover: handler=' . ($handler ?? 'null'));

        if ($handler === self::HANDLER_MIB) {
            $this->discoverMib();
        } elseif ($handler === self::HANDLER_V1) {
            $this->vlog('discover: delegating to SmartJsonV1');
            $this->makeV1Handler()->discover();
        } else {
            // Neither MIB nor V1 JSON is reachable; wipe any stale data.
            $this->vlog('discover: no data source detected, cleaning up');
            $this->cleanup();
        }
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

        if ($this->storedHandler() === self::HANDLER_V1) {
            $this->vlog('poll: delegating to SmartJsonV1');
            $this->makeV1Handler()->poll();

            return;
        }

        $this->pollCommon();
        $this->sataDeviceList = $this->sataDevicesFromDb();
        $this->sataHandler->poll($this->sataDeviceList);
        $this->nvmeDeviceList = $this->nvmeDevicesFromDb();
        $this->nvmeHandler->poll($this->nvmeDeviceList);
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
        $this->context = new Context(
            $this->app->app_id,
            $this->os->getDeviceId(),
            $this->os->getDevice(),
            $this->os->getDeviceArray(),
            $this,
        );
        $this->deviceTable = new DeviceTable($this->context);
        $this->sataHandler = new SataHandler($this->context);
        $this->nvmeHandler = new NvmeHandler($this->context);
    }

    /** @internal public delegate for Context; only Context should call this. */
    public function discoverSensorPublic(
        string $class,
        string $type,
        string $index,
        string $oid,
        string $descr,
        int|float $current = 0,
        string $poller_type = 'agent',
        ?string $group = null,
        ?string $navigation = null,
        int|float $divisor = 1,
        int|float $multiplier = 1,
        int|float|null $lowLimit = null,
        int|float|null $lowWarnLimit = null,
        int|float|null $warnLimit = null,
        int|float|null $highLimit = null,
        string $rrd_type = 'GAUGE',
    ): static {
        return $this->discoverSensor(
            class: $class, type: $type, index: $index, oid: $oid, descr: $descr, current: $current,
            poller_type: $poller_type, group: $group, navigation: $navigation, divisor: $divisor,
            multiplier: $multiplier, lowLimit: $lowLimit, lowWarnLimit: $lowWarnLimit,
            warnLimit: $warnLimit, highLimit: $highLimit, rrd_type: $rrd_type,
        );
    }

    /** @internal public delegate for Context; only Context should call this. */
    public function withStateTranslationsPublic(string $stateName, array $translations): static
    {
        return $this->withStateTranslations($stateName, $translations);
    }

    /** @internal public delegate for Context; only Context should call this. */
    public function updateSensorValuesPublic(array $values, string $oidPrefix): void
    {
        $this->updateSensorValues($values, $oidPrefix);
    }

    /** @internal public delegate for Context; only Context should call this. */
    public function vlogPublic(string $msg): void
    {
        $this->vlog($msg);
    }

    // ── Discovery ─────────────────────────────────────────────────────────────

    private function discoverMib(): void
    {
        $this->vlog('discoverMib: starting MIB discovery');
        app()->forgetInstance('sensor-discovery');

        // One-shot V1→V2 RRD migration (no-op once all devices are marked done).
        $this->sataHandler->migrateV1Rrds($this->sataDevices());

        // SENSOR-MIB is common to all device types; walk once before type discovery.
        $this->sensorTable();
        $this->vlog('discoverMib: sensorTable has ' . count($this->sensorRows) . ' device entry/entries');

        // Type: SATA
        $this->sataDeviceList = $this->sataDevices();
        $this->sataHandler->discover($this->sataDeviceList, $this->sensorRows);

        // Type: NVMe
        $this->nvmeDeviceList = $this->nvmeDevices();
        $this->nvmeHandler->discover($this->nvmeDeviceList, $this->sensorRows);

        // Type: SAS. Not yet implemented.
        // $this->discoverSas();

        // SENSOR-MIB sensors: register for every discovered device.
        $device = $this->context->device;
        $group = 'SMART';
        // Source-defined limits per sensor_oid. LibreNMS guesses limits for some
        // classes (e.g. temperature low = current - 10) when a column is null, so
        // after sync we force these back to exactly what the source reported.
        $intendedLimits = [];
        $commonDevices = $this->deviceTable->ensureCommonDevices();
        $this->vlog('discoverMib: registering SENSOR-MIB sensors for ' . count($commonDevices) . ' device(s)');
        foreach ($commonDevices as $devIdx => $dev) {
            $diskKey = $dev['disk_key'];
            $idx = $this->mibDiskIndex($diskKey);
            $devName = $this->sensorLabel($dev, (string) $devIdx);
            foreach ($this->sensorRows[$devIdx] ?? [] as $sensorIdx => $row) {
                // smartmonSensorType is an enum returned as a name ("celsius(3)") when MIBs load.
                $type = (int) ($row['smartmonSensorType'] ?? null);
                $value = SnmpDecode::applySensorScaleCol($row, 'smartmonSensorValue', 'smartmonSensorScale', 'smartmonSensorPrecision', self::SENSOR_SCALE_EXP);
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
                $highCrit = SnmpDecode::applySensorScaleCol($row, 'smartmonSensorHighCritical', 'smartmonSensorScale', 'smartmonSensorPrecision', self::SENSOR_SCALE_EXP);
                $highWarn = SnmpDecode::applySensorScaleCol($row, 'smartmonSensorHighWarning', 'smartmonSensorScale', 'smartmonSensorPrecision', self::SENSOR_SCALE_EXP);
                $lowWarn = SnmpDecode::applySensorScaleCol($row, 'smartmonSensorLowWarning', 'smartmonSensorScale', 'smartmonSensorPrecision', self::SENSOR_SCALE_EXP);
                $lowCrit = SnmpDecode::applySensorScaleCol($row, 'smartmonSensorLowCritical', 'smartmonSensorScale', 'smartmonSensorPrecision', self::SENSOR_SCALE_EXP);
                // A warning threshold equal to critical gives no early notice; nudge it
                // one notch less severe so "warning" fires before "critical".
                if ($highCrit !== null && $highWarn !== null && $highWarn == $highCrit) {
                    $highWarn = $highCrit - 5;
                }
                if ($lowCrit !== null && $lowWarn !== null && $lowWarn == $lowCrit) {
                    $lowWarn = $lowCrit + 5;
                }
                // Carry the scale as divisor/multiplier so the poll can rescale the raw value.
                $scale = SnmpDecode::sensorScaleColumns($row, 'smartmonSensorScale', 'smartmonSensorPrecision', self::SENSOR_SCALE_EXP);
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
                ->where('device_id', $this->context->device['device_id'])
                ->where('sensor_oid', $oid)
                ->where('sensor_custom', 'No') // never override user-customized limits
                ->update($limits);
        }
    }

    /**
     * Every per-device-type pipeline, for sweeps that must cover all of them
     * generically (currently just cleanupStaleMibSensors()). A future
     * SasHandler is added here and nowhere else needs to change.
     *
     * @return array<Handler\DiskTypeHandler>
     */
    private function handlers(): array
    {
        return [$this->sataHandler, $this->nvmeHandler];
    }

    /** Remove sensors belonging to drives that no longer appear in the device table. */
    private function cleanupStaleMibSensors(): void
    {
        $device = $this->context->device;
        $expected = [];
        foreach ($this->deviceTable->ensureCommonDevices() as $snmpIndex => $dev) {
            $idx = $this->mibDiskIndex($dev['disk_key']);

            // Generic SENSOR-MIB sensors (temperature, NVMe spare/used). Applies to all device types.
            foreach ($this->sensorRows[$snmpIndex] ?? [] as $sensorIdx => $row) {
                $type = (int) ($row['smartmonSensorType'] ?? null);
                $meta = self::SENSOR_TYPE_MAP[$type] ?? null;
                if ($meta !== null) {
                    $expected[] = "app:smart_mib:{$idx}_{$meta[2]}_{$sensorIdx}";
                }
            }

            // Per-type state sensors: ask whichever handler owns this device's
            // type for the OIDs it expects, so adding a future SasHandler to
            // handlers() is the only change needed to cover a third type here.
            $deviceType = $dev['device_type'] ?? 0;
            foreach ($this->handlers() as $handler) {
                if (in_array($deviceType, $handler::types(), true)) {
                    foreach ($handler->expectedSensorOids($idx) as $oid) {
                        $expected[] = "app:smart_mib:{$oid}";
                    }
                }
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
        $commonDevices = $this->deviceTable->ensureCommonDevices();
        $keepKeys = array_values(array_map(
            static fn ($dev) => $dev['disk_key'],
            $commonDevices
        ));
        $keepIdx = array_map('intval', array_keys($commonDevices));

        $totalDeleted = 0;

        // Disk-keyed child tables + the device table itself.
        foreach ([...self::DEVICE_CHILD_TABLES, 'smart_devices'] as $table) {
            $query = DB::table($table)->where('app_id', $this->context->appId);
            if ($keepKeys !== []) {
                $query->whereNotIn('disk_key', $keepKeys);
            }
            $totalDeleted += $query->delete();
        }

        // smart_sata_change is keyed by device_idx (snmp_index), not disk_key.
        $changeQuery = DB::table('smart_sata_change')->where('app_id', $this->context->appId);
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
        $rows = SnmpQuery::mibs(self::COMMON_MIBS)->mibDir('smart')->hideMib()->walk([
            'SMARTMON-COMMON-MIB::smartmonDeviceLastPollResult',
            'SMARTMON-COMMON-MIB::smartmonDeviceLastPollTime',
            'SMARTMON-COMMON-MIB::smartmonDevicePowerState',
        ])->table(1);

        foreach ($rows as $snmpIndex => $row) {
            DB::table('smart_devices')
                ->where('app_id', $this->context->appId)
                ->where('snmp_index', (int) $snmpIndex)
                ->update([
                    'last_poll_result' => (int) ($row['smartmonDeviceLastPollResult'] ?? null),
                    'last_poll_time'   => SnmpDecode::parseDateAndTime($row['smartmonDeviceLastPollTime'] ?? null),
                    'power_state'      => (int) ($row['smartmonDevicePowerState'] ?? null),
                ]);
        }
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
        $sensorValues = SnmpQuery::mibs(self::SENSOR_MIBS)->mibDir('smart')
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
        $sensors = Sensor::where('device_id', $this->context->device['device_id'])
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
                $raw = SmartSnmpDecode::leafValue($rawValue, 'smartmonSensorValue');
                $operStatus = (int) (SmartSnmpDecode::leafValue($rawValue, 'smartmonSensorOperStatus'));
                // SmartmonSensorStatus: ok(1) = value reported; unavailable(2)/nonoperational(3) = no trustworthy reading.
                if ($operStatus !== 1) {
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

    // ── SNMP table fetchers ───────────────────────────────────────────────────

    private function sensorTable(): void
    {
        $this->sensorRows = SnmpQuery::mibs(self::SENSOR_MIBS)->mibDir('smart')
            ->hideMib()
            ->walk('SMARTMON-SENSOR-MIB::smartmonSensorTable')
            ->table(2);
    }

    // ── Handler detection ─────────────────────────────────────────────────────

    /**
     * Return the stored handler type, or detect and store it on first run.
     * Returns null when neither MIB nor V1 JSON is reachable (discover() will
     * call cleanup() in that case).
     */
    private function detectAndPersistHandler(): ?string
    {
        $handler = $this->storedHandler();

        if ($handler !== null) {
            $this->vlog("detectAndPersistHandler: using stored handler={$handler}");

            return $handler;
        }

        // Probe MIB first.
        $response = SnmpQuery::mibs(self::COMMON_MIBS)->mibDir('smart')
            ->hideMib()
            ->get('SMARTMON-COMMON-MIB::smartmonDeviceTableRowCount.0');
        if ($response->isValid() && $response->value('smartmonDeviceTableRowCount.0') !== '') {
            $this->vlog('detectAndPersistHandler: MIB detected');
            $this->persistHandler(self::HANDLER_MIB);

            return self::HANDLER_MIB;
        }

        // MIB unavailable: try V1 JSON.
        if ($this->v1PayloadAvailable()) {
            $this->vlog('detectAndPersistHandler: V1 JSON detected');
            $this->persistHandler(self::HANDLER_V1);

            return self::HANDLER_V1;
        }

        $this->vlog('detectAndPersistHandler: no handler detected (MIB valid=' . ($response->isValid() ? 'true' : 'false') . ')');

        return null;
    }

    /** Read the persisted handler type without triggering SNMP or payload probes. */
    private function storedHandler(): ?string
    {
        return DB::table('smart_app_state')
            ->where('app_id', $this->context->appId)
            ->value('handler') ?: null;
    }

    private function persistHandler(string $handler): void
    {
        DbSync::upsert('smart_app_state', ['app_id' => $this->context->appId, 'handler' => $handler], ['app_id']);
    }

    /**
     * Probe V1 JSON availability by checking nsExtendStatus."smart" = active(1).
     * Lighter than fetching the full payload: no script execution, no JSON parsing.
     */
    private function v1PayloadAvailable(): bool
    {
        $status = (int) SnmpQuery::mibs(['NET-SNMP-EXTEND-MIB'])
            ->hideMib()
            ->get('NET-SNMP-EXTEND-MIB::nsExtendStatus."smart"')
            ->value('nsExtendStatus."smart"');

        return $status === 1; // RowStatus active(1)
    }

    private function makeV1Handler(): SmartJsonV1
    {
        return new SmartJsonV1($this->os, $this->app, $this->agent_data);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Return only SATA/ATA devices from the common device table. */
    private function sataDevices(): array
    {
        return $this->deviceTable->devicesOfTypes(SataHandler::TYPES);
    }

    /** Return only NVMe devices from the common device table. */
    private function nvmeDevices(): array
    {
        return $this->deviceTable->devicesOfTypes(NvmeHandler::TYPES);
    }

    /** Print a debug line when -vv is active. */
    private function vlog(string $msg): void
    {
        if (Debug::isVerbose()) {
            echo PHP_EOL . "smart_mib: {$msg}";
        }
    }

    private function sataDevicesFromDb(): array
    {
        return $this->deviceTable->devicesFromDb(SataHandler::TYPES);
    }

    private function nvmeDevicesFromDb(): array
    {
        return $this->deviceTable->devicesFromDb(NvmeHandler::TYPES);
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
}
