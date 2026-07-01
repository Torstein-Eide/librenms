<?php

namespace LibreNMS\Agent\Module\Smart\Handler;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\Models\StateTranslation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LibreNMS\Agent\Module\Smart\ChangeTracker;
use LibreNMS\Agent\Module\Smart\Context;
use LibreNMS\Agent\Module\Smart\Helpers\DiskIdentity;
use LibreNMS\Agent\Module\Smart\Support\DbSync;
use LibreNMS\Agent\Module\Smart\Support\RrdReconciler;
use LibreNMS\Agent\Module\Smart\Support\SelftestAge;
use LibreNMS\Agent\Module\Smart\Support\SnmpDecode as SmartSnmpDecode;
use LibreNMS\Data\Store\Rrd;
use LibreNMS\Enum\Severity;
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

    public function __construct(private readonly Context $ctx)
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
            $this->pollSataDeviceSensors($dev);
        }

        $this->changes->persist();
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
    }

    /**
     * Sync the SATA state sensor types (registered in discoverSataDeviceSensors,
     * which runs before this call). The generic SENSOR-MIB types are synced
     * separately by Common::syncMibSensorTypes() after their registration loop.
     */
    private function syncSensorTypes(): void
    {
        foreach (['smart_mib_health', 'smart_selftest_status', 'smart_selftest_short', 'smart_selftest_long'] as $type) {
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
                    ?? ($this->isCounterAttrName($row['smartmonSataAttrName'] ?? null) || isset(self::ATA_COUNTER_ATTRS[$id])
                        ? 'COUNTER' : 'GAUGE');

                $format = (int) ($meta->format ?? null);
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
        $fields['power_state'] = (int) ($dev['power_state'] ?? null);

        $rrdName = ['app', 'smart', $this->ctx->appId, $idx];
        $rrd = app(Rrd::class);
        $rrdFile = $rrd->name($this->ctx->device->hostname, $rrdName);
        $hasRrd = $rrd->checkRrdExists($rrdFile);
        if (count($fields) === 1 && ! $hasRrd) {
            return;
        }

        // DS reconciliation (retrofitting power_state onto older files) is a
        // discovery concern, handled by RrdReconciler::reconcileCommonDeviceRrds();
        // new files get every DS at create time from $rrd_def. No tune at poll time.
        app('Datastore')->put($this->ctx->deviceArray, 'app', [
            'name'                => 'smart',
            'app_id'              => $this->ctx->appId,
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
        $rrd = app(Rrd::class);
        $rrdFilename = $rrd->name($this->ctx->device['hostname'], ['app', 'smart', $this->ctx->appId, $idx]);
        $now = time();

        $rrdTypes = DB::table('smart_sata_attributes')
            ->where('app_id', $this->ctx->appId)
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
                ->where('app_id', $this->ctx->appId)
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
            $rawStatus = (int) ($row['smartmonSataAttrStatus'] ?? null);
            $rateStatus = $this->resolveRateStatus($thresholdRows, $id, $rates);

            DbSync::upsert('smart_sata_attributes', [
                'app_id'       => $this->ctx->appId,
                'device_id'    => $this->ctx->deviceId,
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
        $format = (int) ($row['smartmonSataAttrFormat'] ?? null);
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
                $q->where(['app_id' => $this->ctx->appId, 'disk_key' => $diskKey])
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
            ->where('app_id', $this->ctx->appId)
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
            $rawStatus = (int) ($row['smartmonSataAttrStatus'] ?? null);
            $rateStatus = $this->resolveRateStatus($thresholdRows, $id, $rates);

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
                'status'           => $this->combineStatus($rawStatus, $rateStatus),
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
        $idx = DiskIdentity::index($diskKey);
        $rrd = app(Rrd::class);
        $rrdFile = $rrd->name($this->ctx->device->hostname, ['app', 'smart', $this->ctx->appId, $idx]);

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

            $format = (int) ($row['smartmonSataAttrFormat'] ?? null);
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
            RrdReconciler::addDatasetsFromConfig($rrdFile, $config);
        }
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

        return in_array($name, self::ATA_COUNTER_ATTRS, true) || stripos($name, 'count') !== false;
    }
}
