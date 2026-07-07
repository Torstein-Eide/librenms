<?php

namespace LibreNMS\Agent\Module\Smart\Handler;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\Models\Eventlog;
use App\Models\StateTranslation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LibreNMS\Agent\Module\Smart\ChangeTracker;
use LibreNMS\Agent\Module\Smart\Common;
use LibreNMS\Agent\Module\Smart\Context;
use LibreNMS\Agent\Module\Smart\DeviceTable;
use LibreNMS\Agent\Module\Smart\Helpers\DiskIdentity;
use LibreNMS\Agent\Module\Smart\Support\AttributeRateTracker;
use LibreNMS\Agent\Module\Smart\Support\DbSync;
use LibreNMS\Agent\Module\Smart\Support\DevStatRrdCatalog;
use LibreNMS\Agent\Module\Smart\Support\ExcludedAttributesSetting;
use LibreNMS\Agent\Module\Smart\Support\ExtraDevStatSetting;
use LibreNMS\Agent\Module\Smart\Support\HwForecastSetting;
use LibreNMS\Agent\Module\Smart\Support\NormalizedTrendTracker;
use LibreNMS\Agent\Module\Smart\Support\SelftestAge;
use LibreNMS\Agent\Module\Smart\Support\SnmpDecode as SmartSnmpDecode;
use LibreNMS\Data\Store\Rrd;
use LibreNMS\Enum\Severity;
use LibreNMS\Exceptions\RrdException;
use LibreNMS\RRD\RrdDefinition;
use LibreNMS\Util\Debug;
use LibreNMS\Util\SnmpDecode;
use SnmpQuery;

/**
 * SATA device-type pipeline: discovery, polling, change-detection, and
 * DB/RRD sync for every smartmonDeviceType ata(1)/sat(2) device.
 */
final class SataHandler implements DiskTypeHandler
{
    public const TYPES = [1, 2];

    private const SATA_MIBS = ['SMARTMON-TC-MIB', 'SMARTMON-COMMON-MIB', 'SMARTMON-SATA-MIB'];

    // Per-disk RRD DS heartbeat: generous enough (24h) that a disk sleeping
    // through several poll cycles gets its value distributed across the gap
    // when it wakes, rather than the DS going unknown at the global default
    // heartbeat. Missing disks are the exception -- they get an explicit 'U'
    // written every poll (see markDeviceMissingRrd()) so their gap stays a
    // gap instead of being smoothed over by this wider heartbeat.
    private const RRD_HEARTBEAT = 86400;

    // V1 RRD datasets that have no equivalent in V2 and should be discarded on migration.
    // V1 stored these as self-test pass/fail counters; V2 handles self-test via the log table.
    private const V1_SATA_DISCARD_DS = [
        'completed', 'interrupted', 'readfailure', 'unknownfail',
        'extended', 'short', 'conveyance', 'selective',
    ];

    private array $sataHealth = [];
    private array $sataAttributes = [];
    private array $sataDeviceList = [];
    private readonly ChangeTracker $changes;

    /** rotation_rate per disk_key, cached from smartmonSataInfoTable at discovery time (see syncSataInfoRow()), so discoverSataDeviceSensors() can gate the rotating-disk Wear sensor without a DB round-trip. */
    private array $sataRotationRate = [];

    /**
     * DS the current disk's per-cycle RRD file carries, keyed by DS name ->
     * ['type'=>..,'min'=>..,'max'=>..,'value'=>..]. Re-seeded with power_state
     * on every resolveSataDsCatalog() call (this handler processes multiple
     * disks per discover()/poll() pass, so it can't be cached across disks),
     * then extended with whichever allowlisted Device Statistics entries are
     * present for that disk when the "log extra Device Statistics" setting
     * is enabled. reconcileSataDeviceRrds() additionally appends the current
     * disk's attribute DS directly into this same property (rather than a
     * separate local array), so its final addDatasetsFromConfig() call needs
     * no merge step.
     */
    private array $devStatCatalog = [];

    public function __construct(private readonly Context $ctx, private readonly DeviceTable $deviceTable)
    {
        $this->changes = new ChangeTracker($ctx);
    }

    public static function types(): array
    {
        return self::TYPES;
    }

    /**
     * Discover all SATA tables: for each table, walk once, then process per device.
     */
    public function discover(array $devices, array $sensorRows): void
    {
        // Change index must be loaded first so all table-change guards below are valid.
        $this->changes->load();
        $this->sataDeviceList = $devices;
        $this->ctx->vlog('SataHandler::discover: ' . count($this->sataDeviceList) . ' SATA device(s) found');

        // Info table: sync unconditionally. Static identity data is not tracked in the change table.
        $this->walkAndSyncSataTable('smartmonSataInfoTable', 1, null, [$this, 'syncSataInfoRow']);

        // Tables needed for sensor discovery (always fetched).
        $this->sataAttributeTable();
        $this->sataHealthTable();

        // For each SATA device: register SATA-specific sensors and sync health + attributes to DB.
        foreach ($this->sataDeviceList as $devIdx => $dev) {
            $this->ctx->vlog("SataHandler::discover: device idx={$devIdx} disk_key={$dev['disk_key']}");

            // Missing (grace-period) devices carry no fresh SNMP data to discover
            // against -- just keep their Health sensor registered as Unavailable.
            if (! empty($dev['missing_since'])) {
                $this->deviceTable->markMissingHealthDiscovered($dev, 'smart_mib_health', 6, self::healthStateTranslations());

                continue;
            }

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
                $this->syncSataAttributeTrend($dev, $this->sataAttributes[$devIdx]);
            }
            $this->reconcileSataDeviceRrds($dev, $this->sataAttributes[$devIdx] ?? []);
            $hwForecastEnabled = HwForecastSetting::resolve($this->ctx->appId);
            $this->reconcileHwForecastRra($dev, $hwForecastEnabled);
        }

        // Change-guarded tables (per device):
        $this->walkAndSyncSataTable('smartmonSataErcTable', 2, ChangeTracker::TID_ERC, [$this, 'syncSataErcRows']);
        $this->walkAndSyncSataTable('smartmonSataPhyEventTable', 2, ChangeTracker::TID_PHY_EVENT, [$this, 'syncSataPhyEventRows']);
        $this->walkAndSyncSataTable('smartmonSataErrorLogTable', 2, ChangeTracker::TID_ERROR_LOG, [$this, 'syncSataErrorLogRows']);
        $this->walkAndSyncSataTable('smartmonSataErrorCmdTable', 3, ChangeTracker::TID_ERROR_LOG, [$this, 'syncSataErrorCmdRows']);
        $this->walkAndSyncSataTable('smartmonSataSelfTestTable', 2, ChangeTracker::TID_SELFTEST, [$this, 'syncSataSelfTestRows']);
        $this->walkAndSyncSataTable('smartmonSataSelectiveTestTable', 2, ChangeTracker::TID_SELECTIVE_TEST, [$this, 'syncSataSelectiveTestRows']);
        $this->walkAndSyncSataTable('smartmonSataLogDirTable', 2, ChangeTracker::TID_LOG_DIR, [$this, 'syncSataLogDirRows']);
        $this->walkAndSyncSataTable('smartmonSataDevStatTable', 3, ChangeTracker::TID_DEV_STAT, [$this, 'syncSataDevStatRows'], true);

        // Self-test age sensors, computed from the freshly-synced self-test log + power-on hours.
        SelftestAge::discoverSensors($this->ctx, $this->sataDeviceList, 'smart_selftest_', 'smart_sata_health', 'smart_sata_selftest_log');

        // Register all sensor types with the discovery system.
        $this->syncSensorTypes();

        // Persist change snapshot for the next cycle's change detection.
        $this->changes->persist();
    }

    /**
     * Poll all SATA tables: for each table walk once, then update per device.
     */
    public function poll(array $devices): void
    {
        $this->sataDeviceList = $devices;

        // Table: Health (change-guarded; DB sync; sensors updated below)
        $this->walkAndSyncSataTable('smartmonSataHealthTable', 1, ChangeTracker::TID_HEALTH, [$this, 'syncSataHealthRow']);

        // Table: Attributes (change-guarded; limited columns for DB sync + RRD)
        $this->walkAndSyncSataAttrPoll();

        // SENSOR-MIB values are polled once in Common::poll(), covering SATA + NVMe.

        // Change-guarded tables:
        $this->walkAndSyncSataPhyEventPoll();
        $this->walkAndSyncSataDevStatPoll();
        $this->walkAndSyncSataTable('smartmonSataErrorLogTable', 2, ChangeTracker::TID_ERROR_LOG, [$this, 'syncSataErrorLogRows']);
        $this->walkAndSyncSataTable('smartmonSataErrorCmdTable', 3, ChangeTracker::TID_ERROR_LOG, [$this, 'syncSataErrorCmdRows']);
        $this->walkAndSyncSataTable('smartmonSataSelfTestTable', 2, ChangeTracker::TID_SELFTEST, [$this, 'syncSataSelfTestRows']);
        $this->walkAndSyncSataTable('smartmonSataSelectiveTestTable', 2, ChangeTracker::TID_SELECTIVE_TEST, [$this, 'syncSataSelectiveTestRows']);
        $this->walkAndSyncSataTable('smartmonSataPendingDefectsTable', 2, ChangeTracker::TID_PENDING_DEFECTS, [$this, 'syncSataPendingDefectRows']);

        // Health, self-test status, and self-test age sensors, computed from the
        // tables just synced above and batched through a single updateSensorValues()
        // call per device so stored multipliers (selftest age -> minutes), threshold
        // alerts, and state-change events are all applied.
        foreach ($this->sataDeviceList as $dev) {
            $skip = $this->deviceTable->pollSkipReason($dev);
            if ($skip === 'missing') {
                $this->deviceTable->markMissingHealthPolled($dev, 6);
                $this->markDeviceMissingRrd($dev);
                continue;
            }
            if ($skip === 'idle') {
                continue;
            }
            $this->pollSataDeviceSensors($dev);
        }

        $this->changes->persist();
    }

    /**
     * Write an explicit 'U' (unknown) to a missing disk's RRD file this poll
     * cycle, so the gap while it's gone reads as unknown rather than being
     * linearly smoothed over once real data resumes -- RRD_HEARTBEAT is
     * generous enough (24h) that a silent gap that long would otherwise get
     * interpolated across, same as a legitimate sleep.
     */
    private function markDeviceMissingRrd(array $dev): void
    {
        $idx = DiskIdentity::index($dev['disk_key']);
        $rrdFile = app(Rrd::class)->name($this->ctx->device->hostname, ['app', 'smart', $this->ctx->appId, $idx]);
        app(Rrd::class)->writeUnknown($rrdFile);
    }

    public function expectedSensorOids(string $idx): array
    {
        return [
            "{$idx}_health",
            "{$idx}_selftest_status",
            "{$idx}_selftest_short",
            "{$idx}_selftest_long",
            "{$idx}_wear",
        ];
    }

    /** One-shot per-device migration from V1 RRD layout to V2. Called once from Common::discoverMib(). */
    public function migrateV1Rrds(array $devices): void
    {
        $deviceModel = Device::find($this->ctx->deviceId);
        if ($deviceModel === null) {
            return;
        }

        $rrd = app(Rrd::class);

        foreach ($devices as $dev) {
            $diskKey = $dev['disk_key'];

            $alreadyDone = DB::table('smart_devices')
                ->where('app_id', $this->ctx->appId)
                ->where('disk_key', $diskKey)
                ->value('v1_rrd_migrated');

            if ($alreadyDone) {
                continue;
            }

            $v2Idx = DiskIdentity::index($diskKey);
            $v2Name = ['app', 'smart', $this->ctx->appId, $v2Idx];

            // V1 used the raw device path as the disk ID (e.g. /dev/sda).
            $v1DiskId = $dev['device_path'];
            if (! empty($v1DiskId)) {
                $v1Name = ['app', 'smart', $this->ctx->appId, $v1DiskId];
                $rrd->renameFile($deviceModel, $v1Name, $v2Name);
            }

            // Strip V1-only DS; no-op if they're absent or the file doesn't exist.
            $rrdFile = $rrd->name($deviceModel->hostname, $v2Name);
            $rrd->discardDatasets($rrdFile, self::V1_SATA_DISCARD_DS);

            DB::table('smart_devices')
                ->where('app_id', $this->ctx->appId)
                ->where('disk_key', $diskKey)
                ->update(['v1_rrd_migrated' => 1]);
        }
    }

    /** @return array<int, StateTranslation> */
    private static function healthStateTranslations(): array
    {
        return [
            StateTranslation::define('OK', 1, Severity::Ok),
            StateTranslation::define('Warning', 2, Severity::Warning),
            StateTranslation::define('Warning: Attr Failed', 3, Severity::Warning),
            StateTranslation::define('Warning: Attr Rate', 4, Severity::Warning),
            StateTranslation::define('Error: Attr Failing', 5, Severity::Error),
            StateTranslation::define('Unavailable', 6, Severity::Error),
        ];
    }

    /**
     * Register LibreNMS sensors for one SATA device.
     * Called once per device with pre-fetched table data.
     */
    private function discoverSataDeviceSensors(array $dev, array $health, array $attrRows): void
    {
        $diskKey = $dev['disk_key'];
        $devName = DiskIdentity::label($dev, $dev['snmp_index']);
        $idx = DiskIdentity::index($diskKey);
        $group = 'SMART';

        // Health: synthesised from overall status + attribute statuses
        if (isset($health['smartmonSataHealthOverallStatus'])) {
            $synthesized = $this->synthesizeHealthStatus($health, $attrRows, $diskKey);
            $this->ctx->discoverSensor(
                class: 'state',
                type: 'smart_mib_health',
                index: "{$idx}_health",
                oid: "app:smart_mib:{$idx}_health",
                descr: "{$group} {$devName} Health",
                current: $synthesized,
                group: $group,
            )->withStateTranslations('smart_mib_health', self::healthStateTranslations());
        }

        // Self-test execution status (MIB returns the decoded nibble directly)
        $statusRaw = $health['smartmonSataSelfTestExecutionStatusValue'] ?? null;
        if ($statusRaw !== null) {
            $statusNibble = (int) $statusRaw;
            $this->ctx->discoverSensor(
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

        // Rotating Wear: only for spinning (non-SSD) disks, which have no single
        // standard "% used" attribute the way SSDs have Wear_Leveling_Count. Value
        // is 1 - (the lowest SMART normalized value among this disk's attributes),
        // excluding whatever ExcludedAttributesSetting says (see its doc comment).
        // Trends upward as the disk wears (like percentageUsedSensor()). No default
        // thresholds -- left for the user to set via the sensor's own edit page.
        if ($this->isRotatingDisk($this->sataRotationRate[$diskKey] ?? null)) {
            $normalizedAttrs = [];
            foreach ($attrRows as $attrId => $row) {
                $normalizedAttrs[] = [
                    'id'         => (int) ($row['smartmonSataAttrId'] ?? $attrId),
                    'name'       => $row['smartmonSataAttrName'] ?? null,
                    'value_norm' => $row['smartmonSataAttrValue'] ?? null,
                    'status'     => (int) ($row['smartmonSataAttrStatus'] ?? 0),
                ];
            }
            $wear = $this->rotatingWearPercent($normalizedAttrs, $diskKey);
            if ($wear !== null) {
                $this->ctx->discoverSensor(
                    class: 'percent',
                    type: 'smart_rotating_wear',
                    index: "{$idx}_wear",
                    oid: "app:smart_mib:{$idx}_wear",
                    descr: "{$group} {$devName} Rotating Wear",
                    current: $wear,
                    group: $group,
                );
            }
        }
    }

    /**
     * Sync the SATA sensor types (registered in discoverSataDeviceSensors, which
     * runs before this call). The generic SENSOR-MIB types are synced separately
     * by Common::syncMibSensorTypes() after their registration loop.
     */
    private function syncSensorTypes(): void
    {
        foreach (['smart_mib_health', 'smart_selftest_status', 'smart_selftest_short', 'smart_selftest_long', 'smart_rotating_wear'] as $type) {
            app('sensor-discovery')->sync(sensor_type: $type);
        }
    }

    /** Update the SATA Health, Self-test Status, and Self-test age sensors for one device. */
    private function pollSataDeviceSensors(array $dev): void
    {
        $diskKey = $dev['disk_key'];
        $idx = DiskIdentity::index($diskKey);
        $values = [];

        // Health state sensor, synthesized from DB
        $health = $this->synthesizeHealthFromDb($diskKey);
        if ($health !== null) {
            $values["{$idx}_health"] = (float) $health;
        }

        // Self-test execution status from DB
        $raw = DB::table('smart_sata_health')
            ->where('app_id', $this->ctx->appId)
            ->where('disk_key', $diskKey)
            ->value('selftest_exec_status_raw');
        if ($raw !== null) {
            $values["{$idx}_selftest_status"] = (float) $raw;
        }

        // Self-test age (recomputed each poll: grows over time, resets when a test runs).
        // Raw value is hours; updateSensorValues() applies the sensor's stored
        // multiplier (60) to convert to minutes, matching the 'runtime' sensor unit.
        $values += SelftestAge::values($this->ctx, $idx, $diskKey, 'smart_sata_health', 'smart_sata_selftest_log');

        // Rotating Wear (recomputed each poll from the freshly-polled attribute
        // rows). Info table isn't re-walked every poll, so rotation_rate comes
        // from a DB lookup here rather than the discovery-time $sataRotationRate
        // cache, which isn't populated on a poll-only cycle.
        $rotationRate = DB::table('smart_sata_info')
            ->where('app_id', $this->ctx->appId)
            ->where('disk_key', $diskKey)
            ->value('rotation_rate');
        if ($this->isRotatingDisk($rotationRate)) {
            $normalizedAttrs = DB::table('smart_sata_attributes')
                ->where('app_id', $this->ctx->appId)
                ->where('disk_key', $diskKey)
                ->get(['attribute_id', 'name', 'value_norm', 'status'])
                ->map(fn ($row) => [
                    'id'         => (int) $row->attribute_id,
                    'name'       => $row->name,
                    'value_norm' => $row->value_norm,
                    'status'     => (int) $row->status,
                ])
                ->all();
            $wear = $this->rotatingWearPercent($normalizedAttrs, $diskKey);
            if ($wear !== null) {
                $values["{$idx}_wear"] = $wear;
            }
        }

        if ($values !== []) {
            $this->ctx->updateSensorValues($values, "app:smart_mib:{$idx}_");
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
                    $result[(string) $devIdx][(string) $idx2][$col] = SmartSnmpDecode::leafValue($leaf, $col);
                }
            }
        }

        return $result;
    }

    /** Write per-disk RRDs for one SATA device. */
    private function pollSataDeviceRrd(array $dev, array $attrRows): void
    {
        $diskKey = $dev['disk_key'];
        $idx = DiskIdentity::index($diskKey);

        // Attribute RRD, then power_state + any allowlisted dev-stat DS (see
        // resolveSataDsCatalog()). rrdtool tune appends new DS to existing
        // files regardless of call order, so this ordering only matters for
        // the DS layout of newly-created files.
        $rrd_def = RrdDefinition::make();
        $fields = [];

        if (! empty($attrRows)) {
            // Format isn't walked at poll time (see walkAndSyncSataAttrPoll()) -- it's
            // resolved from drivedb.h per drive model/attribute and effectively static
            // between discovery cycles, so read the copy discovery already persisted,
            // alongside rrd_type, in a single query.
            $attrMeta = DB::table('smart_sata_attributes')
                ->where('app_id', $this->ctx->appId)
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
                    ?? ($this->isCounterAttrName($row['smartmonSataAttrName'] ?? null) || isset(Common::ATA_COUNTER_ATTRS[$id])
                        ? 'COUNTER' : 'GAUGE');

                $format = (int) ($meta->format ?? null);
                $rawString = $row['smartmonSataAttrRawString'] ?? null;

                $rrd_def->addDataset($dsNorm, 'GAUGE', 0, null, self::RRD_HEARTBEAT);
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
                        $rrd_def->addDataset($dsSub, 'GAUGE', 0, null, self::RRD_HEARTBEAT);
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

                $rrd_def->addDataset($dsRaw, $rawType, 0, null, self::RRD_HEARTBEAT);
                $fields[$dsRaw] = $singleValue ?? ($row['smartmonSataAttrRawValue'] ?? null);
            }
        }

        foreach ($this->resolveSataDsCatalog($dev) as $ds => $shape) {
            $rrd_def->addDataset($ds, $shape['type'], $shape['min'], $shape['max'], $shape['heartbeat']);
            $fields[$ds] = $shape['value'];
        }

        $rrdName = ['app', 'smart', $this->ctx->appId, $idx];
        $rrd = app(Rrd::class);
        $rrdFile = $rrd->name($this->ctx->device->hostname, $rrdName);
        $hasRrd = $rrd->checkRrdExists($rrdFile);
        if (count($fields) === 1 && ! $hasRrd) {
            return;
        }

        // DS reconciliation (retrofitting power_state/attribute/dev-stat DS onto
        // older files) is a discovery concern, handled by reconcileSataDeviceRrds();
        // new files get every DS at create time from $rrd_def. No tune at poll time.
        $hwForecastEnabled = HwForecastSetting::resolve($this->ctx->appId);
        $meta = [
            'name'                => 'smart',
            'app_id'              => $this->ctx->appId,
            'rrd_def'             => $rrd_def,
            'rrd_name'            => $rrdName,
            'rrd_update_template' => true,
        ];
        if ($hwForecastEnabled) {
            $meta['rrd_rra'] = $this->hwForecastRra();
        }
        app('Datastore')->put($this->ctx->deviceArray, 'app', $meta, $fields);
    }

    /**
     * RRA list for a disk's per-disk RRD file when Holt-Winters forecasting is
     * enabled: the same AVERAGE/MIN/MAX/LAST RRAs as the global `rrd_rra`
     * default, plus HWPREDICT (rrdtool auto-derives
     * SEASONAL/DEVSEASONAL/DEVPREDICT/FAILURES from it). This RRA list is
     * file-wide -- every DS in the file (normalized values, power_state,
     * dev-stat counters, not just raw attribute values) gets it, since
     * RRDtool RRAs are not per-DS. MIN/MAX/LAST are required here (not just
     * for parity with the default): other SMART graphs DEF against them
     * directly (e.g. disk_power_state.inc.php's power_state:MIN/:MAX,
     * disk_lba_units.inc.php's SATA read/write :MAX), and rrdtool graph
     * errors at render time if a DEF's consolidation function has no
     * matching RRA in the file. See HwForecastSetting for the enable/disable
     * gate.
     */
    private function hwForecastRra(): array
    {
        // Seasonal period = one day's worth of steps; HWPREDICT rows cover ~30 days
        // of history (288 steps/day * 30 = 8640 rows at the default 300s step).
        $step = (int) LibrenmsConfig::get('rrd.step', 300);
        $seasonalPeriod = max(1, (int) round(86400 / $step));
        $rows = $seasonalPeriod * 30;

        return [
            'RRA:AVERAGE:0.5:1:2016',
            'RRA:AVERAGE:0.5:6:1440',
            'RRA:AVERAGE:0.5:24:1440',
            'RRA:AVERAGE:0.5:288:1440',
            'RRA:MIN:0.5:1:2016',
            'RRA:MIN:0.5:6:1440',
            'RRA:MIN:0.5:24:1440',
            'RRA:MIN:0.5:288:1440',
            'RRA:MAX:0.5:1:2016',
            'RRA:MAX:0.5:6:1440',
            'RRA:MAX:0.5:24:1440',
            'RRA:MAX:0.5:288:1440',
            'RRA:LAST:0.5:1:2016',
            "RRA:HWPREDICT:{$rows}:0.1:0.0035:{$seasonalPeriod}",
        ];
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

    private function syncSataInfoRow(array $dev, array $row): void
    {
        $this->sataRotationRate[$dev['disk_key']] = $row['smartmonSataRotationRate'] ?? null;

        DbSync::upsert('smart_sata_info', [
            'app_id'                               => $this->ctx->appId,
            'device_id'                            => $this->ctx->deviceId,
            'disk_key'                             => $dev['disk_key'],
            'ata_version'                          => (int) ($row['smartmonSataAtaVersion'] ?? null),
            'sata_version'                         => (int) ($row['smartmonSataVersion'] ?? null),
            'rotation_rate'                        => $row['smartmonSataRotationRate'] ?? null,
            'form_factor'                          => (int) ($row['smartmonSataFormFactor'] ?? null),
            'logical_block_size'                   => $row['smartmonSataLogicalBlockSize'] ?? null,
            'physical_block_size'                  => $row['smartmonSataPhysicalBlockSize'] ?? null,
            'user_capacity_bytes'                  => $row['smartmonSataUserCapacityBytes'] ?? null,
            'sct_hist_op_limit_min'                => $row['smartmonSataSctHistOpLimitMin'] ?? null,
            'sct_hist_op_limit_max'                => $row['smartmonSataSctHistOpLimitMax'] ?? null,
            'sct_hist_limit_min'                   => $row['smartmonSataSctHistLimitMin'] ?? null,
            'sct_hist_limit_max'                   => $row['smartmonSataSctHistLimitMax'] ?? null,
            // New columns
            'ata_version_major'                    => (int) ($row['smartmonSataAtaVersionMajor'] ?? null),
            'ata_version_minor'                    => (int) ($row['smartmonSataAtaVersionMinor'] ?? null),
            'user_capacity_blocks'                 => $row['smartmonSataUserCapacityBlocks'] ?? null,
            'in_smartctl_database'                 => SmartSnmpDecode::snmpTruthValue($row['smartmonSataInSmartctlDatabase'] ?? null),
            'smart_available'                      => SmartSnmpDecode::snmpTruthValue($row['smartmonSataSmartAvailable'] ?? null),
            'smart_enabled'                        => SmartSnmpDecode::snmpTruthValue($row['smartmonSataSmartEnabled'] ?? null),
            'trim_supported'                       => SmartSnmpDecode::snmpTruthValue($row['smartmonSataTrimSupported'] ?? null),
            'write_cache_enabled'                  => SmartSnmpDecode::snmpTruthValue($row['smartmonSataWriteCacheEnabled'] ?? null),
            'read_lookahead_enabled'               => SmartSnmpDecode::snmpTruthValue($row['smartmonSataReadLookaheadEnabled'] ?? null),
            'apm_enabled'                          => SmartSnmpDecode::snmpTruthValue($row['smartmonSataApmEnabled'] ?? null),
            'apm_level'                            => (int) ($row['smartmonSataApmLevel'] ?? null),
            'security_state'                       => $row['smartmonSataSecurityState'] ?? null,
            'security_enabled'                     => SmartSnmpDecode::snmpTruthValue($row['smartmonSataSecurityEnabled'] ?? null),
            'security_frozen'                      => SmartSnmpDecode::snmpTruthValue($row['smartmonSataSecurityFrozen'] ?? null),
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
            'capability_selftests_supported'       => SmartSnmpDecode::snmpTruthValue($row['smartmonSataCapabilitySelfTestsSupported'] ?? null),
            'capability_conveyance_supported'      => SmartSnmpDecode::snmpTruthValue($row['smartmonSataCapabilityConveyanceSupported'] ?? null),
            'capability_selective_supported'       => SmartSnmpDecode::snmpTruthValue($row['smartmonSataCapabilitySelectiveSupported'] ?? null),
            'capability_error_logging_supported'   => SmartSnmpDecode::snmpTruthValue($row['smartmonSataCapabilityErrorLoggingSupported'] ?? null),
            'capability_gp_logging_supported'      => SmartSnmpDecode::snmpTruthValue($row['smartmonSataCapabilityGpLoggingSupported'] ?? null),
            'capability_exec_offline_immediate'    => SmartSnmpDecode::snmpTruthValue($row['smartmonSataCapabilityExecOfflineImmediate'] ?? null),
            'capability_offline_aborted_on_cmd'    => SmartSnmpDecode::snmpTruthValue($row['smartmonSataCapabilityOfflineAbortedOnCmd'] ?? null),
            'capability_offline_surface_scan'      => SmartSnmpDecode::snmpTruthValue($row['smartmonSataCapabilityOfflineSurfaceScan'] ?? null),
            'capability_attr_autosave'             => SmartSnmpDecode::snmpTruthValue($row['smartmonSataCapabilityAttrAutosave'] ?? null),
            'sct_error_recovery_supported'         => SmartSnmpDecode::snmpTruthValue($row['smartmonSataSctErrorRecoverySupported'] ?? null),
            'sct_feature_control_supported'        => SmartSnmpDecode::snmpTruthValue($row['smartmonSataSctFeatureControlSupported'] ?? null),
            'sct_data_table_supported'             => SmartSnmpDecode::snmpTruthValue($row['smartmonSataSctDataTableSupported'] ?? null),
        ], ['app_id', 'disk_key']);
    }

    private function syncSataHealthRow(array $dev, array $row): void
    {
        DbSync::upsert('smart_sata_health', [
            'app_id'                     => $this->ctx->appId,
            'device_id'                  => $this->ctx->deviceId,
            'disk_key'                   => $dev['disk_key'],
            'overall_status'             => SmartSnmpDecode::snmpTruthValue($row['smartmonSataHealthOverallStatus'] ?? null),
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
            'sct_smart_status_passed'               => SmartSnmpDecode::snmpTruthValue($row['smartmonSataSctSmartStatusPassed'] ?? null),
            'selftest_estimated_completion_time'    => SnmpDecode::parseDateAndTime($row['smartmonSataSelfTestEstimatedCompletionTime'] ?? null),
            'selftest_estimated_bytes_sec'          => $row['smartmonSataSelfTestEstimatedBytesSec'] ?? null,
        ], ['app_id', 'disk_key']);
    }

    private function syncSataAttributeRows(array $dev, array $attrRows): void
    {
        foreach ($attrRows as $attrId => $row) {
            DbSync::upsert('smart_sata_attributes', [
                'app_id'           => $this->ctx->appId,
                'device_id'        => $this->ctx->deviceId,
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
                'format'           => (int) ($row['smartmonSataAttrFormat'] ?? null),
                'flags'            => SmartSnmpDecode::bitsValue($row['smartmonSataAttrFlags'] ?? null),
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
        $idx = DiskIdentity::index($diskKey);
        $rrdFilename = app(Rrd::class)->name($this->ctx->device['hostname'], ['app', 'smart', $this->ctx->appId, $idx]);

        $attrs = [];
        foreach ($attrRows as $attrId => $row) {
            $id = (int) ($row['smartmonSataAttrId'] ?? $attrId);
            $attrs[$id] = [
                'ds'     => $this->rateDsForAttribute($id, $row),
                'status' => (int) ($row['smartmonSataAttrStatus'] ?? null),
            ];
        }

        AttributeRateTracker::sync($this->ctx->appId, $this->ctx->deviceId, $this->ctx->device['hostname'], $diskKey, $rrdFilename, $attrs);
    }

    /**
     * Fit two straight-line trends (1-month and 6-month lookback) per
     * attribute against its Normalized DS and persist the resulting "days
     * until Thresh crossing" estimates, backing the SATA Basic view's "Time
     * to Thresh" column (HtmlData::attrNormalizedTrendRanges()). Runs at
     * discovery time only, same reasoning as syncSataAttributeRates() above --
     * see NormalizedTrendTracker's own class doc for why this one especially
     * doesn't need every-poll freshness.
     */
    private function syncSataAttributeTrend(array $dev, array $attrRows): void
    {
        $diskKey = $dev['disk_key'];
        $idx = DiskIdentity::index($diskKey);
        $rrdFilename = app(Rrd::class)->name($this->ctx->device['hostname'], ['app', 'smart', $this->ctx->appId, $idx]);

        $attrs = [];
        foreach ($attrRows as $attrId => $row) {
            $id = (int) ($row['smartmonSataAttrId'] ?? $attrId);
            $attrs[$id] = [
                'threshold' => is_numeric($row['smartmonSataAttrThreshold'] ?? null) ? (float) $row['smartmonSataAttrThreshold'] : null,
                'status'    => (int) ($row['smartmonSataAttrStatus'] ?? null),
            ];
        }

        NormalizedTrendTracker::sync($this->ctx->appId, $this->ctx->deviceId, $diskKey, $rrdFilename, $attrs);
    }

    /** True for a spinning (non-SSD) disk: numeric rotation_rate greater than zero. */
    private function isRotatingDisk(mixed $rotationRate): bool
    {
        return is_numeric($rotationRate) && (float) $rotationRate > 0;
    }

    /**
     * This disk's Wear percentage: 1 - (lowest SMART normalized value among
     * $normalizedAttrs / 100), excluding NA-status rows and anything this
     * disk's ExcludedAttributesSetting list matches (see that class's doc
     * comment for why -- temperature/workload counters/typically-SSD-specific
     * spare-endurance attributes would otherwise corrupt this reading). SMART
     * normalized values are 100=new decreasing toward each attribute's
     * failure threshold as it wears; inverting puts this sensor on the same
     * "higher = more worn/used" scale as percentageUsedSensor() (NVMe
     * "Percentage Used" / SATA "Endurance Used"), so the two are directly
     * comparable wherever a disk's Wear sensor is looked up regardless of
     * whether it's rotating or solid-state. Returns null if nothing
     * qualifies (no sensor is registered/updated that cycle).
     *
     * @param  array<int, array{id: int, name: ?string, value_norm: mixed, status: int}>  $normalizedAttrs
     */
    private function rotatingWearPercent(array $normalizedAttrs, string $diskKey): ?float
    {
        $excluded = ExcludedAttributesSetting::resolve($this->ctx->appId, $diskKey);
        $lowest = null;

        foreach ($normalizedAttrs as $attr) {
            if ($attr['status'] === -1 || ! is_numeric($attr['value_norm'])) {
                continue;
            }
            if (ExcludedAttributesSetting::isExcluded($attr['name'], $attr['id'], $excluded)) {
                continue;
            }

            $value = (float) $attr['value_norm'];
            if ($lowest === null || $value < $lowest) {
                $lowest = $value;
            }
        }

        return $lowest === null ? null : max(0.0, min(100.0, 100.0 - $lowest));
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
        $format = (int) ($row['smartmonSataAttrFormat'] ?? null);
        $rawString = $row['smartmonSataAttrRawString'] ?? null;
        $subValues = $this->attrFormatSubValues($format, $rawString);

        if ($subValues !== []) {
            return array_key_exists('Hi', $subValues) ? 'id' . $id . 'Hi' : null;
        }

        return 'id' . $id;
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
            ->where('app_id', $this->ctx->appId)
            ->where('disk_key', $diskKey)
            ->get(['attribute_id', 'rate_8h', 'rate_24h', 'rate_168h', 'rate_672h'])
            ->keyBy('attribute_id');
        $thresholdRows = AttributeRateTracker::loadThresholdRows($this->ctx->appId, $diskKey);

        foreach ($attrRows as $attrId => $row) {
            $id = (int) $attrId;
            $existing = $existingRates->get($id);
            $rates = [
                '8h' => $existing->rate_8h ?? null,
                '24h' => $existing->rate_24h ?? null,
                '168h' => $existing->rate_168h ?? null,
                '672h' => $existing->rate_672h ?? null,
            ];
            $rawStatus = (int) ($row['smartmonSataAttrStatus'] ?? null);
            $rateStatus = AttributeRateTracker::resolveRateStatus($thresholdRows, $id, $rates);

            DbSync::upsert('smart_sata_attributes', [
                'app_id'           => $this->ctx->appId,
                'device_id'        => $this->ctx->deviceId,
                'disk_key'         => $diskKey,
                'attribute_id'     => $id,
                'value_norm'       => $row['smartmonSataAttrValue'] ?? null,
                'value_raw'        => $row['smartmonSataAttrRawValue'] ?? null,
                'value_raw_string' => isset($row['smartmonSataAttrRawString'])
                    ? substr((string) $row['smartmonSataAttrRawString'], 0, 32)
                    : null,
                'status'           => AttributeRateTracker::combineStatus($rawStatus, $rateStatus),
                'rate_status'      => $rateStatus,
            ], ['app_id', 'disk_key', 'attribute_id']);
        }
    }

    private function syncSataErcRows(array $dev, array $rows): void
    {
        foreach ($rows as $direction => $row) {
            DbSync::upsert('smart_sata_erc', [
                'app_id'      => $this->ctx->appId,
                'device_id'   => $this->ctx->deviceId,
                'disk_key'    => $dev['disk_key'],
                'direction'   => (int) $direction,
                'enabled'     => SmartSnmpDecode::snmpTruthValue($row['smartmonSataErcEnabled'] ?? null),
                'deciseconds' => $row['smartmonSataErcDeciseconds'] ?? null,
            ], ['app_id', 'disk_key', 'direction']);
        }
        DbSync::pruneStaleRows('smart_sata_erc', $this->ctx->appId, $dev['disk_key'], 'direction', array_keys($rows));
    }

    /** Full discovery sync: name + size_bytes + value + overflow. */
    private function syncSataPhyEventRows(array $dev, array $rows): void
    {
        foreach ($rows as $eventId => $row) {
            DbSync::upsert('smart_sata_phy_events', [
                'app_id'     => $this->ctx->appId,
                'device_id'  => $this->ctx->deviceId,
                'disk_key'   => $dev['disk_key'],
                'event_id'   => (int) $eventId,
                'name'       => isset($row['smartmonSataPhyEventName'])
                    ? substr((string) $row['smartmonSataPhyEventName'], 0, 128) : null,
                'size_bytes' => $row['smartmonSataPhyEventSize'] ?? null,
                'value'      => $row['smartmonSataPhyEventValue'] ?? null,
                'overflow'   => SmartSnmpDecode::snmpTruthValue($row['smartmonSataPhyEventOverflow'] ?? null),
            ], ['app_id', 'disk_key', 'event_id']);
        }
        DbSync::pruneStaleRows('smart_sata_phy_events', $this->ctx->appId, $dev['disk_key'], 'event_id', array_keys($rows));
    }

    /** Poll-only update: value + overflow, no name/size walk needed. */
    private function syncSataPhyEventValueRows(array $dev, array $rows): void
    {
        $upsertRows = [];
        foreach ($rows as $eventId => $row) {
            $upsertRows[] = [
                'app_id'    => $this->ctx->appId,
                'device_id' => $this->ctx->deviceId,
                'disk_key'  => $dev['disk_key'],
                'event_id'  => (int) $eventId,
                'value'     => $row['smartmonSataPhyEventValue'] ?? null,
                'overflow'  => SmartSnmpDecode::snmpTruthValue($row['smartmonSataPhyEventOverflow'] ?? null),
            ];
        }
        if (! empty($upsertRows)) {
            DbSync::upsert('smart_sata_phy_events', $upsertRows, ['app_id', 'disk_key', 'event_id']);
        }
    }

    private function syncSataErrorLogRows(array $dev, array $rows): void
    {
        foreach ($rows as $errorIndex => $row) {
            DbSync::upsert('smart_sata_error_log', [
                'app_id'          => $this->ctx->appId,
                'device_id'       => $this->ctx->deviceId,
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
        DbSync::pruneStaleRows('smart_sata_error_log', $this->ctx->appId, $dev['disk_key'], 'entry_num', array_keys($rows));
    }

    private function syncSataErrorCmdRows(array $dev, array $rows): void
    {
        foreach ($rows as $errorIndex => $cmdRows) {
            if (! is_array($cmdRows)) {
                continue;
            }
            foreach ($cmdRows as $cmdIndex => $row) {
                DbSync::upsert('smart_sata_error_cmd', [
                    'app_id'          => $this->ctx->appId,
                    'device_id'       => $this->ctx->deviceId,
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
            DbSync::pruneStaleRows('smart_sata_error_cmd', $this->ctx->appId, $dev['disk_key'], 'cmd_slot', array_keys($cmdRows), ['error_entry_num' => (int) $errorIndex]);
        }
        DbSync::pruneStaleRows('smart_sata_error_cmd', $this->ctx->appId, $dev['disk_key'], 'error_entry_num', array_keys($rows));
    }

    private function syncSataSelfTestRows(array $dev, array $rows): void
    {
        foreach ($rows as $testIndex => $row) {
            DbSync::upsert('smart_sata_selftest_log', [
                'app_id'          => $this->ctx->appId,
                'device_id'       => $this->ctx->deviceId,
                'disk_key'        => $dev['disk_key'],
                'entry_num'       => (int) $testIndex,
                'test_type'       => $row['smartmonSataSelfTestType'] ?? null,
                'result'          => $row['smartmonSataSelfTestResult'] ?? null,
                'result_passed'   => SmartSnmpDecode::snmpTruthValue($row['smartmonSataSelfTestResultPassed'] ?? null),
                'remaining_pct'   => $row['smartmonSataSelfTestRemainingPct'] ?? null,
                'power_on_hours'  => $row['smartmonSataSelfTestLifetimeHours'] ?? null,
                'lba_first_error' => $row['smartmonSataSelfTestLbaFirstError'] ?? null,
            ], ['app_id', 'disk_key', 'entry_num']);
        }
        DbSync::pruneStaleRows('smart_sata_selftest_log', $this->ctx->appId, $dev['disk_key'], 'entry_num', array_keys($rows));
    }

    private function syncSataSelectiveTestRows(array $dev, array $rows): void
    {
        foreach ($rows as $slot => $row) {
            DbSync::upsert('smart_sata_selective_test', [
                'app_id'       => $this->ctx->appId,
                'device_id'    => $this->ctx->deviceId,
                'disk_key'     => $dev['disk_key'],
                'slot'         => (int) $slot,
                'lba_min'      => $row['smartmonSataSelectiveLbaMin'] ?? null,
                'lba_max'      => $row['smartmonSataSelectiveLbaMax'] ?? null,
                'status_value' => $row['smartmonSataSelectiveStatusValue'] ?? null,
            ], ['app_id', 'disk_key', 'slot']);
        }
        DbSync::pruneStaleRows('smart_sata_selective_test', $this->ctx->appId, $dev['disk_key'], 'slot', array_keys($rows));
    }

    private function syncSataLogDirRows(array $dev, array $rows): void
    {
        foreach ($rows as $address => $row) {
            DbSync::upsert('smart_sata_log_dir', [
                'app_id'        => $this->ctx->appId,
                'device_id'     => $this->ctx->deviceId,
                'disk_key'      => $dev['disk_key'],
                'log_address'   => (int) $address,
                'name'          => isset($row['smartmonSataLogDirName'])
                    ? substr((string) $row['smartmonSataLogDirName'], 0, 128) : null,
                'readable'      => SmartSnmpDecode::snmpTruthValue($row['smartmonSataLogDirReadable'] ?? null),
                'writable'      => SmartSnmpDecode::snmpTruthValue($row['smartmonSataLogDirWritable'] ?? null),
                'gp_sectors'    => $row['smartmonSataLogDirGpSectors'] ?? null,
                'smart_sectors' => $row['smartmonSataLogDirSmartSectors'] ?? null,
            ], ['app_id', 'disk_key', 'log_address']);
        }
        DbSync::pruneStaleRows('smart_sata_log_dir', $this->ctx->appId, $dev['disk_key'], 'log_address', array_keys($rows));
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
                $flagsRaw = SnmpDecode::parseBitsValue($row['smartmonSataDevStatFlagsValue'] ?? null);
                $valid = $flagsRaw !== null ? (bool) ($flagsRaw & 0x40) : null;
                $normalized = $flagsRaw !== null ? (bool) ($flagsRaw & 0x20) : null;

                DbSync::upsert('smart_sata_dev_stats', [
                    'app_id'      => $this->ctx->appId,
                    'device_id'   => $this->ctx->deviceId,
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
            DbSync::pruneStaleRows('smart_sata_dev_stats', $this->ctx->appId, $dev['disk_key'], 'stat_offset', array_keys($offsets), ['page_num' => (int) $pageNum]);
        }
        DbSync::pruneStaleRows('smart_sata_dev_stats', $this->ctx->appId, $dev['disk_key'], 'page_num', array_keys($rows));
    }

    private function syncSataPendingDefectRows(array $dev, array $rows): void
    {
        $newLbas = array_values(array_unique(array_filter(array_map(
            static fn ($row) => $row['smartmonSataPendingDefectsLba'] ?? null,
            $rows
        ), static fn ($lba) => $lba !== null)));

        $this->recordBadSectorHistory($dev, $newLbas);

        foreach ($rows as $entryIndex => $row) {
            DbSync::upsert('smart_sata_pending_defects', [
                'app_id'    => $this->ctx->appId,
                'device_id' => $this->ctx->deviceId,
                'disk_key'  => $dev['disk_key'],
                'entry_num' => (int) $entryIndex,
                'lba'       => $row['smartmonSataPendingDefectsLba'] ?? null,
            ], ['app_id', 'disk_key', 'entry_num']);
        }
        DbSync::pruneStaleRows('smart_sata_pending_defects', $this->ctx->appId, $dev['disk_key'], 'entry_num', array_keys($rows));
    }

    /**
     * Keep a permanent record of every LBA ever reported as a pending defect,
     * separate from smart_sata_pending_defects (which only reflects what the
     * controller currently considers pending -- an entry drops out of that
     * live table the moment the sector is reallocated). Without this, a
     * relocated sector's history would vanish the instant it's no longer
     * "currently" pending.
     *
     * @param  array<int,int|string>  $newLbas  deduplicated LBAs from this poll
     */
    private function recordBadSectorHistory(array $dev, array $newLbas): void
    {
        $previousLbas = DB::table('smart_sata_pending_defects')
            ->where('app_id', $this->ctx->appId)
            ->where('disk_key', $dev['disk_key'])
            ->whereNotNull('lba')
            ->pluck('lba')
            ->all();

        $historyLbas = DB::table('smart_sata_bad_sector_history')
            ->where('app_id', $this->ctx->appId)
            ->where('disk_key', $dev['disk_key'])
            ->pluck('lba')
            ->all();

        $now = date('Y-m-d H:i:s');

        $existingLbas = array_values(array_intersect($newLbas, $historyLbas));
        if ($existingLbas !== []) {
            DB::table('smart_sata_bad_sector_history')
                ->where('app_id', $this->ctx->appId)
                ->where('disk_key', $dev['disk_key'])
                ->whereIn('lba', $existingLbas)
                ->update(['last_seen' => $now, 'cleared_at' => null]);
        }

        $insertLbas = array_values(array_diff($newLbas, $historyLbas));
        if ($insertLbas !== []) {
            DB::table('smart_sata_bad_sector_history')->insert(array_map(fn ($lba) => [
                'app_id'     => $this->ctx->appId,
                'device_id'  => $this->ctx->deviceId,
                'disk_key'   => $dev['disk_key'],
                'lba'        => $lba,
                'first_seen' => $now,
                'last_seen'  => $now,
                'cleared_at' => null,
            ], $insertLbas));
        }

        $clearedLbas = array_values(array_diff($previousLbas, $newLbas));
        if ($clearedLbas !== []) {
            DB::table('smart_sata_bad_sector_history')
                ->where('app_id', $this->ctx->appId)
                ->where('disk_key', $dev['disk_key'])
                ->whereIn('lba', $clearedLbas)
                ->whereNull('cleared_at')
                ->update(['cleared_at' => $now]);
        }
    }

    /** Poll-time narrowed walk: value + overflow only (name/size already in DB from discovery). */
    private function walkAndSyncSataPhyEventPoll(): void
    {
        $this->changes->load();
        if (! Debug::isVerbose() && ! $this->changes->anyDeviceChangedForTable(ChangeTracker::TID_PHY_EVENT)) {
            return;
        }

        $valueRows = $this->walkSataTable('smartmonSataPhyEventValue', 2);
        $overflowRows = $this->walkSataTable('smartmonSataPhyEventOverflow', 2);

        foreach ($this->sataDeviceList as $devIdx => $dev) {
            if (! $this->changes->tableChangedForDevice((string) $devIdx, ChangeTracker::TID_PHY_EVENT)) {
                continue;
            }
            $merged = [];
            foreach ($valueRows[(string) $devIdx] ?? [] as $eventId => $value) {
                $merged[(string) $eventId] = [
                    'smartmonSataPhyEventValue'    => SmartSnmpDecode::leafValue($value, 'smartmonSataPhyEventValue'),
                    'smartmonSataPhyEventOverflow' => SmartSnmpDecode::leafValue($overflowRows[(string) $devIdx][$eventId] ?? null, 'smartmonSataPhyEventOverflow'),
                ];
            }
            $this->syncSataPhyEventValueRows($dev, $merged);
        }
    }

    /**
     * Poll-time narrowed walk for DevStat: only value column, with two-level change guards
     * (device-level and page-level, both via ChangeTracker).
     */
    private function walkAndSyncSataDevStatPoll(): void
    {
        $this->changes->load();
        if (! Debug::isVerbose() && ! $this->changes->anyDeviceChangedForTable(ChangeTracker::TID_DEV_STAT)) {
            return;
        }

        // Single walk for all devices; depth=3 gives [devIdx][pageNum][offset] => value.
        $allValueRows = $this->walkSataTable('smartmonSataDevStatValue', 3, true);

        foreach ($this->sataDeviceList as $devIdx => $dev) {
            if (! $this->changes->tableChangedForDevice((string) $devIdx, ChangeTracker::TID_DEV_STAT)) {
                continue;
            }
            $upsertRows = [];
            foreach ($allValueRows[(string) $devIdx] ?? [] as $pageNum => $offsets) {
                if (! Debug::isVerbose() && ! $this->changes->tableChangedForDevicePage((string) $devIdx, ChangeTracker::TID_DEV_STAT, (int) $pageNum)) {
                    continue;
                }
                foreach ($offsets as $offset => $value) {
                    $upsertRows[] = [
                        'app_id'      => $this->ctx->appId,
                        'device_id'   => $this->ctx->deviceId,
                        'disk_key'    => $dev['disk_key'],
                        'page_num'    => (int) $pageNum,
                        'stat_offset' => (int) $offset,
                        'value'       => SmartSnmpDecode::leafValue($value, 'smartmonSataDevStatValue'),
                    ];
                }
            }
            if (! empty($upsertRows)) {
                DbSync::upsert('smart_sata_dev_stats', $upsertRows, ['app_id', 'disk_key', 'page_num', 'stat_offset']);
            }
        }
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
            $this->changes->load();
            if (! Debug::isVerbose() && ! $this->changes->anyDeviceChangedForTable($tableId)) {
                $this->ctx->vlog("walkAndSyncSataTable: {$table} skipped (no changes)");

                return;
            }
        }

        $this->ctx->vlog("walkAndSyncSataTable: walking {$table} (depth={$depth})");
        $synced = 0;
        foreach ($this->walkSataTable($table, $depth, $numericIndex) as $devIdx => $rows) {
            $dev = $this->sataDeviceList[$devIdx] ?? null;
            if ($dev !== null && ($unconditional || $this->changes->tableChangedForDevice($devIdx, $tableId))) {
                $sync($dev, $rows);
                $synced++;
            }
        }
        $this->ctx->vlog("walkAndSyncSataTable: {$table} synced {$synced} device(s)");
    }

    /**
     * Retrofit this disk's RRD file's dataset set to match what it should
     * currently be, via a single Rrd::addDatasetsFromConfig() call (a no-op
     * tune for DS that already exist, skipped entirely if the file doesn't
     * exist yet -- a brand-new device gets every DS at create time from
     * pollSataDeviceRrd()'s own RrdDefinition instead).
     *
     * Covers three things in one $config/one call: power_state (a fixed DS
     * every per-disk RRD file carries); each attribute's *current*
     * smartmonSataAttrFormat-implied DS set (smartmontools' drivedb.h changes
     * over time, so an attribute's format can change between discovery
     * cycles -- e.g. a drivedb update reclassifies an attribute from a plain
     * format to a div/multi-part one, or vice versa; pollSataDeviceRrd() only
     * ever updates a file's *values* on the DS it expects to already exist,
     * it never creates new DS on an existing file, so without this a
     * drivedb-driven format change would leave the RRD stuck with the
     * previous cycle's DS shape); and, if the "log extra Device Statistics"
     * setting is enabled for this device, whichever allowlisted
     * (page_num, stat_offset) dev-stat rows are present for this disk.
     */
    private function reconcileSataDeviceRrds(array $dev, array $attrRows): void
    {
        $diskKey = $dev['disk_key'];
        $idx = DiskIdentity::index($diskKey);
        $rrd = app(Rrd::class);
        $rrdFile = $rrd->name($this->ctx->device->hostname, ['app', 'smart', $this->ctx->appId, $idx]);

        $heartbeat = self::RRD_HEARTBEAT;

        // Seeds $this->devStatCatalog with power_state + any allowlisted dev-stat
        // DS; the attribute loop below appends directly into the same property,
        // so the final addDatasetsFromConfig() call needs no separate merge.
        $this->resolveSataDsCatalog($dev);

        foreach ($attrRows as $attrId => $row) {
            $id = (int) ($row['smartmonSataAttrId'] ?? $attrId);
            $dsRaw = 'id' . $id;
            $dsNorm = $dsRaw . 'Normalized';
            if (strlen($dsNorm) > 19) {
                continue;
            }

            $this->devStatCatalog[$dsNorm] = ['type' => 'GAUGE', 'heartbeat' => $heartbeat, 'min' => 0, 'max' => 'U'];

            $format = (int) ($row['smartmonSataAttrFormat'] ?? null);
            $rawString = $row['smartmonSataAttrRawString'] ?? null;
            $subValues = $this->attrFormatSubValues($format, $rawString);
            if ($subValues !== []) {
                foreach ($subValues as $suffix => $value) {
                    $dsSub = $dsRaw . $suffix;
                    if (strlen($dsSub) > 19) {
                        continue;
                    }
                    $this->devStatCatalog[$dsSub] = ['type' => 'GAUGE', 'heartbeat' => $heartbeat, 'min' => 0, 'max' => 'U'];
                }

                continue;
            }

            $rawType = $this->isCounterAttrName($row['smartmonSataAttrName'] ?? null) || isset(Common::ATA_COUNTER_ATTRS[$id])
                ? 'COUNTER' : 'GAUGE';
            $this->devStatCatalog[$dsRaw] = ['type' => $rawType, 'heartbeat' => $heartbeat, 'min' => 0, 'max' => 'U'];
        }

        $rrd->addDatasetsFromConfig($rrdFile, $this->devStatCatalog);
    }

    /**
     * Detection (not automatic action) for the HW-forecast RRA merge:
     *  - If forecasting is enabled but this disk's existing per-disk RRD file
     *    predates the setting (no HWPREDICT RRA - RRAs can't be added via
     *    tune), log an Eventlog notice telling the admin to delete the file
     *    manually so the next poll's Datastore->put() recreates it fresh
     *    with the HWPREDICT-inclusive RRA list. Deleting the file loses its
     *    entire existing history (every RRA, not just one poll cycle) -
     *    that's a decision left to the admin, not made automatically here.
     *  - Toggling forecasting OFF is left alone: the extra HWPREDICT RRA is
     *    harmless to keep, and forcibly stripping it would be pointless data
     *    loss for no functional gain.
     *  - Any leftover `_hw`-suffixed RRD file from the pre-merge dedicated-file
     *    layout is flagged the same way, for manual cleanup.
     */
    private function reconcileHwForecastRra(array $dev, bool $hwForecastEnabled): void
    {
        $idx = DiskIdentity::index($dev['disk_key']);
        $rrd = app(Rrd::class);

        $rrdFile = $rrd->name($this->ctx->device->hostname, ['app', 'smart', $this->ctx->appId, $idx]);
        if ($hwForecastEnabled && $rrd->checkRrdExists($rrdFile)) {
            try {
                $hasHwRra = $rrd->hasRraConsolidationFunction($rrdFile, 'HWPREDICT');
            } catch (RrdException $e) {
                // A failed/timed-out check is not the same as "no HWPREDICT RRA" - don't
                // raise a false migration notice off an inconclusive check.
                Log::warning("SMART HW-forecast RRA check failed for $rrdFile: " . $e->getMessage());
                $hasHwRra = true;
            }
            if (! $hasHwRra) {
                Eventlog::log(
                    "SMART: $rrdFile predates Holt-Winters forecasting and has no HWPREDICT RRA. " .
                    'Delete this file manually to let it be recreated with forecasting enabled ' .
                    '(this will lose its existing history).',
                    $this->ctx->device,
                    'poller',
                    Severity::Warning
                );
            }
        }

        $legacyHwFile = $rrd->name($this->ctx->device->hostname, ['app', 'smart', $this->ctx->appId, $idx . '_hw']);
        if ($rrd->checkRrdExists($legacyHwFile)) {
            Eventlog::log(
                "SMART: leftover forecast RRD $legacyHwFile from the old dedicated-file layout is no longer used. " .
                'Delete it manually to clean up.',
                $this->ctx->device,
                'poller',
                Severity::Notice
            );
        }
    }

    /**
     * (Re)resolves $this->devStatCatalog for one disk: always seeded with
     * power_state (every per-disk RRD file carries it), plus -- when the
     * "log extra Device Statistics" setting is enabled -- whichever
     * allowlisted (per DevStatRrdCatalog) Device Statistics rows are present
     * for this disk. Each entry is already shaped like
     * Rrd::addDatasetsFromConfig() expects (type/heartbeat/min/max), plus a
     * 'value' key that config-only callers (reconcileSataDeviceRrds()) can
     * just ignore -- addDatasetsFromConfig() only reads the DS-shape keys.
     *
     * @return array<string, array{type: string, heartbeat: int, min: int, max: int|string, value: int|string|null}>
     */
    private function resolveSataDsCatalog(array $dev): array
    {
        $heartbeat = self::RRD_HEARTBEAT;

        $this->devStatCatalog = [
            'power_state' => ['type' => 'GAUGE', 'heartbeat' => $heartbeat, 'min' => 0, 'max' => 8, 'value' => (int) ($dev['power_state'] ?? null)],
        ];

        if (! ExtraDevStatSetting::resolve($this->ctx->appId)) {
            return $this->devStatCatalog;
        }

        $catalog = DevStatRrdCatalog::entries();
        $rows = DB::table('smart_sata_dev_stats')
            ->where('app_id', $this->ctx->appId)
            ->where('disk_key', $dev['disk_key'])
            ->get(['page_num', 'stat_offset', 'value']);

        foreach ($rows as $row) {
            $entry = $catalog[DevStatRrdCatalog::key((int) $row->page_num, (int) $row->stat_offset)] ?? null;
            if ($entry === null) {
                continue;
            }
            $this->devStatCatalog[$entry['ds']] = ['type' => $entry['type'], 'heartbeat' => $heartbeat, 'min' => 0, 'max' => 'U', 'value' => $row->value];
        }

        return $this->devStatCatalog;
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
        $overall = (int) ($overall);
        if ($overall === 4) {
            return 6; // unavailable
        }

        $level = $overall !== 1 ? 2 : 1;

        foreach ($attrStatuses as $status) {
            $status = (int) ($status);
            if ($status === 3) {       // failedInPast
                $level = max($level, 3);
            } elseif ($status === 2) { // failingNow
                $level = max($level, 5);
            }
        }

        foreach ($rateStatuses as $rateStatus) {
            if ((int) ($rateStatus) === 2) { // rate-of-change threshold exceeded
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
            ->where('app_id', $this->ctx->appId)
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
            ->where('app_id', $this->ctx->appId)
            ->where('disk_key', $diskKey)
            ->first(['overall_status']);

        if ($health === null) {
            return null;
        }

        $attrs = DB::table('smart_sata_attributes')
            ->where('app_id', $this->ctx->appId)
            ->where('disk_key', $diskKey)
            ->get(['status', 'rate_status']);

        return $this->healthLevel($health->overall_status, $attrs->pluck('status'), $attrs->pluck('rate_status'));
    }

    private function walkSataTable(string $table, int $group, bool $numericIndex = false): array
    {
        $query = SnmpQuery::mibs(self::SATA_MIBS)->mibDir('smart')->hideMib();
        if ($numericIndex) {
            $query = $query->numericIndex();
        }

        return $query->walk("SMARTMON-SATA-MIB::$table")->table($group);
    }

    /** True if $name should be treated as a COUNTER-type ATA attribute (legacy list or "Count" in the name). */
    private function isCounterAttrName(?string $name): bool
    {
        if ($name === null) {
            return false;
        }

        return in_array($name, Common::ATA_COUNTER_ATTRS, true) || stripos($name, 'count') !== false;
    }
}
