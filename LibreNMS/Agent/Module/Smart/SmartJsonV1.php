<?php

namespace LibreNMS\Agent\Module\Smart;

use Illuminate\Support\Facades\DB;
use LibreNMS\Agent\Application;
use LibreNMS\Agent\Module\Smart\Helpers\DiskIdentity;
use LibreNMS\Agent\Module\Smart\Support\AttributeRateTracker;
use LibreNMS\Agent\Module\Smart\Support\DbSync;
use LibreNMS\Data\Store\Rrd;
use LibreNMS\Enum\Severity;
use LibreNMS\RRD\RrdDefinition;

/**
 * SMART application handler for Unix-agent / SNMP-extend JSON payloads (v1 schema).
 *
 * Mirrors Common.php's discover/poll lifecycle but reads a single JSON blob
 * instead of walking SMARTMON-*-MIBs. Sensors cover health, max temperature,
 * and wear level. Raw SMART attributes are stored in smart_sata_attributes and in
 * per-disk RRDs for backward compatibility with existing graphs.
 */
class SmartJsonV1 extends Application
{
    private const PROTOCOL_TYPE_V1 = 0;
    private const OID_PREFIX = 'app:smart_v1:';
    private const SENSOR_TYPES = [
        'smart_v1_health',
        'smart_v1_maxtemp',
        'smart_v1_wear',
        'smart_selftest_status',
        'smart_selftest_short',
        'smart_selftest_long',
    ];
    /**
     * The SATA attributes this JSON payload tracks (its keys), each with its
     * standard smartctl name (its values) saved into
     * smart_sata_attributes.name with a trailing "*". This payload only ever
     * carries a bare numeric ID with no name field, unlike the SNMP-MIB path
     * (smartmonSataAttrName) -- these are the conventional/generic ATA names
     * for these IDs (matching what the old per-attribute legacy_id*.inc.php
     * graphs hardcoded as their labels), not necessarily this specific
     * vendor's actual attribute name, hence the "*" marking it as assumed.
     *
     * @var array<int, string>
     */
    private const ASSUMED_ATTR_NAMES = [
        5   => 'Reallocated_Sector_Ct',
        9   => 'Power_On_Hours',
        10  => 'Spin_Retry_Count',
        12  => 'Power_Cycle_Count',
        173 => 'Worst_Case_Erase_Count',
        177 => 'Wear_Leveling_Count',
        183 => 'Runtime_Bad_Block',
        184 => 'End-to-End_Error',
        187 => 'Reported_Uncorrect',
        188 => 'Command_Timeout',
        190 => 'Airflow_Temperature_Cel',
        194 => 'Temperature_Celsius',
        196 => 'Reallocated_Event_Count',
        197 => 'Current_Pending_Sector',
        198 => 'Offline_Uncorrectable',
        199 => 'UDMA_CRC_Error_Count',
        231 => 'SSD_Life_Left',
        232 => 'Available_Reservd_Space',
        233 => 'Media_Wearout_Indicator',
    ];

    /**
     * Main attribute RRD's DS, keyed by DS name -> the numericAttr() key that
     * fills it. max_temp is a sensor instead of an RRD entry at all (see
     * pollRrds()). Matches the modern SataHandler design: DS type is COUNTER
     * for any ID in Common::ATA_COUNTER_ATTRS, GAUGE otherwise -- this class
     * has no attribute name available (only a fixed numeric ID per entry),
     * so it can't apply SataHandler's additional name-based COUNTER
     * heuristic. No self-test pass/fail counters (completed/interrupted/
     * readfailure/unknownfail/extended/short/conveyance/selective) -- V2
     * deliberately dropped those RRD DS in favor of smart_sata_selftest_log,
     * which this class also populates (see V1_SATA_DISCARD_DS in
     * SataHandler.php).
     *
     * @var array<string, string>
     */
    private const MAIN_RRD_ATTR_KEYS = [
        'id5' => '5',
        'id9' => '9',
        'id10' => '10',
        'id173' => '173',
        'id177' => '177',
        'id183' => '183',
        'id184' => '184',
        'id187' => '187',
        'id188' => '188',
        'id190' => '190',
        'id194' => '194',
        'id196' => '196',
        'id197' => '197',
        'id198' => '198',
        'id199' => '199',
        'id231' => '231',
        'id232' => '232',
        'id233' => '233',
    ];

    /**
     * MAIN_RRD_ATTR_KEYS behind a method with a widened return type, so
     * pollRrds()'s Common::ATA_COUNTER_ATTRS lookup is checked against
     * "some string ID", not today's fixed set of literal ID strings (which
     * currently never overlaps ATA_COUNTER_ATTRS, but the check should stay
     * live rather than PHPStan proving it permanently unreachable).
     *
     * @return array<string, string>
     */
    private static function mainRrdAttrKeys(): array
    {
        return self::MAIN_RRD_ATTR_KEYS;
    }

    // ── Public lifecycle ──────────────────────────────────────────────────────

    public function shouldDiscover(): bool
    {
        if (DB::table('smart_devices')->where('app_id', $this->app->app_id)->exists()) {
            return true;
        }

        // No DB rows yet: probe payload so we skip the full discover() on dead apps.
        $payload = $this->fetchPayload('smart');

        return $payload !== null && ! empty($payload['data']['disks']);
    }

    public function discover(): void
    {
        $disks = $this->initDisks();
        if ($disks === null) {
            return;
        }

        $keepKeys = [];
        $expectedOids = [];

        foreach ($disks as $disk) {
            $diskKey = $this->diskKey($disk);
            $idx = DiskIdentity::index($diskKey);
            $label = $this->diskLabel($disk, $idx);
            $logEntries = $this->parseSelftestLog((string) ($disk['selftest_log'] ?? ''));

            $this->discoverDiskInDb($diskKey, $disk);
            $expectedOids = array_merge($expectedOids, $this->discoverDiskSensors($idx, $disk, $label, $logEntries));
            $presentAttrIds = $this->syncDiskAttributes($diskKey, $disk);
            $this->syncDiskAttributeRates($diskKey, $presentAttrIds);
            $this->syncSelftestLog($diskKey, $logEntries);
            $expectedOids = array_merge($expectedOids, $this->discoverSelftestAgeSensors($idx, $diskKey, $label));

            $keepKeys[] = $diskKey;
        }

        $this->syncSensors(...self::SENSOR_TYPES);
        $this->deleteStaleAgentSensors(self::OID_PREFIX, self::SENSOR_TYPES, $expectedOids);
        $this->cleanupStaleDevices($keepKeys);
    }

    public function shouldPoll(): bool
    {
        return DB::table('smart_devices')->where('app_id', $this->app->app_id)->exists();
    }

    public function poll(): void
    {
        $disks = $this->initDisks();
        if ($disks === null) {
            return;
        }

        $sensorValues = [];

        foreach ($disks as $disk) {
            $diskKey = $this->diskKey($disk);
            $idx = DiskIdentity::index($diskKey);
            $logEntries = $this->parseSelftestLog((string) ($disk['selftest_log'] ?? ''));

            $presentAttrIds = $this->syncDiskAttributes($diskKey, $disk);
            $this->pollDiskAttributeRates($diskKey, $presentAttrIds);
            $this->syncSelftestLog($diskKey, $logEntries);
            $sensorValues += $this->diskSensorValues($idx, $diskKey, $disk, $logEntries);
            $this->pollRrds($diskKey, $disk);
        }

        if ($sensorValues !== []) {
            $this->updateSensorValues($sensorValues, self::OID_PREFIX);
        }

        $this->pollAlerts($disks);
    }

    public function cleanup(): int
    {
        $appId = $this->app->app_id;
        DB::table('smart_sata_selftest_log')->where('app_id', $appId)->delete();
        DB::table('smart_sata_attributes')->where('app_id', $appId)->delete();
        DB::table('smart_devices')->where('app_id', $appId)->delete();
        DB::table('smart_app_state')->where('app_id', $appId)->delete();

        return parent::cleanup();
    }

    // ── Discovery helpers ─────────────────────────────────────────────────────

    /**
     * Fetch and validate the JSON payload, returning the disk data objects as a
     * flat list. The JSON object keys are discarded; callers use diskKey() on the
     * disk data to obtain the stable identifier.
     *
     * @return list<array<string,mixed>>|null
     */
    private function initDisks(): ?array
    {
        $payload = $this->fetchPayload('smart');
        if ($payload === null || empty($payload['data']['disks'])) {
            return null;
        }

        return array_values((array) $payload['data']['disks']);
    }

    private function discoverDiskInDb(string $diskKey, array $disk): void
    {
        DbSync::upsert('smart_devices', [
            'app_id'           => $this->app->app_id,
            'device_id'        => $this->os->getDeviceId(),
            'disk_key'         => $diskKey,
            'snmp_index'       => 0,
            'protocol_type'    => self::PROTOCOL_TYPE_V1,
            'device_name'      => $this->diskDeviceName($disk),
            'model_name'       => (string) ($disk['model_name'] ?? ''),
            'serial_number'    => (string) ($disk['serial'] ?? ''),
            'firmware_version' => (string) ($disk['fw_version'] ?? ''),
        ], ['app_id', 'disk_key']);
    }

    /**
     * Register LibreNMS sensors for one disk (health, max-temp, wear, selftest status).
     * Raw SMART attribute values are stored in smart_sata_attributes instead.
     * Selftest age sensors are registered separately after the log is synced.
     *
     * @param  array<int, array<string,mixed>>  $logEntries  parsed selftest log, keyed by entry_num
     * @return list<string> expected sensor OIDs, for deleteStaleAgentSensors()
     */
    private function discoverDiskSensors(string $idx, array $disk, string $label, array $logEntries): array
    {
        $group = 'SMART';
        $oids = [];

        // Health – always present; state sensor with pass/fail translations.
        $oid = self::OID_PREFIX . "{$idx}_health";
        $this->discoverSensor(
            class: 'state',
            type: 'smart_v1_health',
            index: "{$idx}_health",
            oid: $oid,
            descr: "SMART {$label} Health",
            current: (int) ($disk['health_pass'] ?? 1),
            group: $group,
            lowLimit: 1,
            lowWarnLimit: 1,
        )->withStateTranslations('smart_v1_health', [
            1 => ['label' => 'Passed', 'color' => 'success', 'generic' => 0],
            0 => ['label' => 'Failed', 'color' => 'danger',  'generic' => 2],
        ]);
        $oids[] = $oid;

        // Max temperature.
        if (isset($disk['max_temp']) && is_numeric($disk['max_temp'])) {
            $oid = self::OID_PREFIX . "{$idx}_maxtemp";
            $this->discoverSensor(
                class: 'temperature',
                type: 'smart_v1_maxtemp',
                index: "{$idx}_maxtemp",
                oid: $oid,
                descr: "SMART {$label} Max Temperature",
                current: (float) $disk['max_temp'],
                group: $group,
            );
            $oids[] = $oid;
        }

        // Wear level – first non-null SSD attribute (173, 177, 231, 232, 233).
        $wearVal = $this->wearAttr($disk);
        if ($wearVal !== null) {
            $oid = self::OID_PREFIX . "{$idx}_wear";
            $this->discoverSensor(
                class: 'percent',
                type: 'smart_v1_wear',
                index: "{$idx}_wear",
                oid: $oid,
                descr: "SMART {$label} Wear Level",
                current: $wearVal,
                group: $group,
                lowWarnLimit: 10,
                lowLimit: 5,
            );
            $oids[] = $oid;
        }

        // Selftest status – simulated from the most recent log entry.
        $nibble = $this->selftestStatusNibble($logEntries);
        if ($nibble !== null) {
            $oid = self::OID_PREFIX . "{$idx}_selftest_status";
            $this->discoverSensor(
                class: 'state',
                type: 'smart_selftest_status',
                index: "{$idx}_selftest_status",
                oid: $oid,
                descr: "SMART {$label} Self-test Status",
                current: $nibble,
                group: $group,
            )->withStateTranslations('smart_selftest_status', [
                0x0 => ['label' => 'Completed without error',   'color' => 'success', 'generic' => 0],
                0x1 => ['label' => 'Aborted by host',           'color' => 'success', 'generic' => 0],
                0x2 => ['label' => 'Interrupted (host reset)',   'color' => 'success', 'generic' => 0],
                0x3 => ['label' => 'Fatal or unknown error',    'color' => 'warning', 'generic' => 2],
                0x4 => ['label' => 'Completed: unknown failure', 'color' => 'warning', 'generic' => 2],
                0x5 => ['label' => 'Completed: electrical fail', 'color' => 'warning', 'generic' => 2],
                0x6 => ['label' => 'Completed: servo failure',   'color' => 'warning', 'generic' => 2],
                0x7 => ['label' => 'Completed: read failure',    'color' => 'warning', 'generic' => 2],
                0x8 => ['label' => 'Completed: handling damage', 'color' => 'warning', 'generic' => 2],
                0xf => ['label' => 'Self-test in progress',      'color' => 'success', 'generic' => 0],
            ]);
            $oids[] = $oid;
        }

        return $oids;
    }

    /**
     * Discover "hours since last short/long test" sensors for one disk.
     * Must be called after syncDiskAttributes() and syncSelftestLog() for the
     * same disk so both tables reflect the current payload.
     *
     * @return list<string>
     */
    private function discoverSelftestAgeSensors(string $idx, string $diskKey, string $label): array
    {
        $oids = [];
        $group = 'SMART';

        foreach ([
            ['short', 1, 'Last Short SelfTest', 12000, 16000],
            ['long',  2, 'Last Long SelfTest',  57600, 60000],
        ] as [$suffix, $testType, $sensorLabel, $warn, $max]) {
            $ageHours = $this->selftestAgeHours($diskKey, $testType);
            if ($ageHours === null) {
                continue;
            }

            $oid = self::OID_PREFIX . "{$idx}_selftest_{$suffix}";
            $this->discoverSensor(
                class: 'runtime',
                type: "smart_selftest_{$suffix}",
                index: "{$idx}_selftest_{$suffix}",
                oid: $oid,
                descr: "SMART {$label} {$sensorLabel}",
                current: (float) $ageHours * 60,
                group: $group,
                multiplier: 60,
                warnLimit: $warn,
                highLimit: $max,
            );
            $oids[] = $oid;
        }

        return $oids;
    }

    /**
     * Upsert non-null numeric SMART attributes into smart_sata_attributes and
     * prune any attribute IDs that no longer appear in the payload.
     * Called in both discover() and poll() to keep the table current.
     *
     * @return list<int> attribute IDs present in this poll's payload, for syncDiskAttributeRates()/pollDiskAttributeRates()
     */
    private function syncDiskAttributes(string $diskKey, array $disk): array
    {
        $appId = $this->app->app_id;
        $deviceId = $this->os->getDeviceId();
        $rows = [];
        $presentIds = [];

        foreach (array_keys(self::ASSUMED_ATTR_NAMES) as $attrId) {
            $raw = $disk[(string) $attrId] ?? null;
            if (! is_numeric($raw)) {
                continue;
            }

            $rows[] = [
                'app_id'       => $appId,
                'device_id'    => $deviceId,
                'disk_key'     => $diskKey,
                'attribute_id' => $attrId,
                'name'         => self::ASSUMED_ATTR_NAMES[$attrId] . '*',
                'value_raw'    => (int) $raw,
                'rrd_type'     => isset(Common::ATA_COUNTER_ATTRS[$attrId]) ? 'COUNTER' : 'GAUGE',
            ];
            $presentIds[] = $attrId;
        }

        if ($rows !== []) {
            DbSync::upsert('smart_sata_attributes', $rows, ['app_id', 'disk_key', 'attribute_id']);
        }

        if ($presentIds !== []) {
            DbSync::pruneStaleRows('smart_sata_attributes', $appId, $diskKey, 'attribute_id', $presentIds);
        } else {
            DB::table('smart_sata_attributes')
                ->where('app_id', $appId)
                ->where('disk_key', $diskKey)
                ->delete();
        }

        return $presentIds;
    }

    /**
     * Compute rate-of-change (RRD-history-based) for this disk's present
     * attributes and persist rate_8h/24h/168h/672h + rate_status/status.
     * Discovery only (RRD history accrues via polling; discovery is the
     * natural cadence to re-evaluate trends) -- mirrors
     * SataHandler::syncSataAttributeRates(), sharing the same
     * AttributeRateTracker since both write "id{N}" DS into a same-shaped
     * per-disk "smart" RRD file. This class has no device-reported status
     * per attribute (unlike the SNMP-MIB path), so status only ever reflects
     * a rate-of-change breach, never an underlying device-reported failure.
     *
     * @param  list<int>  $presentIds
     */
    private function syncDiskAttributeRates(string $diskKey, array $presentIds): void
    {
        if ($presentIds === []) {
            return;
        }

        $appId = $this->app->app_id;
        $deviceId = $this->os->getDeviceId();
        $hostname = $this->os->getDeviceArray()['hostname'];
        $rrdFilename = app(Rrd::class)->name($hostname, ['app', 'smart', $appId, $diskKey]);

        $attrs = [];
        foreach ($presentIds as $attrId) {
            $attrs[$attrId] = ['ds' => 'id' . $attrId, 'status' => null];
        }

        AttributeRateTracker::sync($appId, $deviceId, $hostname, $diskKey, $rrdFilename, $attrs);
    }

    /**
     * Cheap poll-time re-evaluation of rate_status (and status) against the
     * rate_8h/24h/168h/672h values discovery already persisted, without
     * recomputing them from RRD history every poll -- mirrors
     * SataHandler::syncSataAttributeRowsPoll(), so a threshold edited via the
     * settings page takes effect before the next discovery.
     *
     * @param  list<int>  $presentIds
     */
    private function pollDiskAttributeRates(string $diskKey, array $presentIds): void
    {
        if ($presentIds === []) {
            return;
        }

        $appId = $this->app->app_id;
        $deviceId = $this->os->getDeviceId();

        $existingRates = DB::table('smart_sata_attributes')
            ->where('app_id', $appId)
            ->where('disk_key', $diskKey)
            ->get(['attribute_id', 'rate_8h', 'rate_24h', 'rate_168h', 'rate_672h'])
            ->keyBy('attribute_id');
        $thresholdRows = AttributeRateTracker::loadThresholdRows($appId, $diskKey);

        foreach ($presentIds as $attrId) {
            $existing = $existingRates->get($attrId);
            $rates = [
                '8h' => $existing->rate_8h ?? null,
                '24h' => $existing->rate_24h ?? null,
                '168h' => $existing->rate_168h ?? null,
                '672h' => $existing->rate_672h ?? null,
            ];
            $rateStatus = AttributeRateTracker::resolveRateStatus($thresholdRows, $attrId, $rates);

            DbSync::upsert('smart_sata_attributes', [
                'app_id'       => $appId,
                'device_id'    => $deviceId,
                'disk_key'     => $diskKey,
                'attribute_id' => $attrId,
                'status'       => AttributeRateTracker::combineStatus(null, $rateStatus),
                'rate_status'  => $rateStatus,
            ], ['app_id', 'disk_key', 'attribute_id']);
        }
    }

    /**
     * Upsert parsed selftest log entries into smart_sata_selftest_log and prune
     * entries that no longer appear. An empty $entries list clears all rows for
     * the disk (covers disks whose selftest_log field is null, e.g. NVMe).
     *
     * @param array<int, array<string,mixed>> $entries  keyed by entry_num
     */
    private function syncSelftestLog(string $diskKey, array $entries): void
    {
        $appId = $this->app->app_id;
        $deviceId = $this->os->getDeviceId();

        if ($entries === []) {
            DB::table('smart_sata_selftest_log')
                ->where('app_id', $appId)
                ->where('disk_key', $diskKey)
                ->delete();

            return;
        }

        foreach ($entries as $entryNum => $e) {
            DbSync::upsert('smart_sata_selftest_log', [
                'app_id'          => $appId,
                'device_id'       => $deviceId,
                'disk_key'        => $diskKey,
                'entry_num'       => $entryNum,
                'test_type'       => $e['test_type'],
                'result'          => $e['result'],
                'result_passed'   => $e['result_passed'],
                'remaining_pct'   => $e['remaining_pct'],
                'power_on_hours'  => $e['power_on_hours'],
                'lba_first_error' => $e['lba_first_error'],
            ], ['app_id', 'disk_key', 'entry_num']);
        }

        DbSync::pruneStaleRows('smart_sata_selftest_log', $appId, $diskKey, 'entry_num', array_keys($entries));
    }

    private function cleanupStaleDevices(array $keepKeys): void
    {
        $query = DB::table('smart_devices')->where('app_id', $this->app->app_id);
        if ($keepKeys !== []) {
            $query->whereNotIn('disk_key', $keepKeys);
        }
        $deleted = $query->delete();
        if ($deleted > 0) {
            echo PHP_EOL . "smart_v1: removed {$deleted} stale device row(s)" . PHP_EOL;
        }
    }

    // ── Poll helpers ──────────────────────────────────────────────────────────

    /**
     * Build the sensor_index => raw_value map for one disk.
     * syncDiskAttributes() and syncSelftestLog() must be called first so that
     * selftestAgeHours() reads up-to-date values from the DB.
     *
     * @param  array<int, array<string,mixed>>  $logEntries  parsed selftest log
     * @return array<string, int|float>
     */
    private function diskSensorValues(string $idx, string $diskKey, array $disk, array $logEntries): array
    {
        $values = [];

        $values["{$idx}_health"] = (int) ($disk['health_pass'] ?? 1);

        if (isset($disk['max_temp']) && is_numeric($disk['max_temp'])) {
            $values["{$idx}_maxtemp"] = (float) $disk['max_temp'];
        }

        $wearVal = $this->wearAttr($disk);
        if ($wearVal !== null) {
            $values["{$idx}_wear"] = $wearVal;
        }

        $nibble = $this->selftestStatusNibble($logEntries);
        if ($nibble !== null) {
            $values["{$idx}_selftest_status"] = $nibble;
        }

        foreach ([1 => 'short', 2 => 'long'] as $testType => $suffix) {
            $age = $this->selftestAgeHours($diskKey, $testType);
            if ($age !== null) {
                $values["{$idx}_selftest_{$suffix}"] = (float) $age;
            }
        }

        return $values;
    }

    private function pollRrds(string $diskKey, array $disk): void
    {
        $appId = $this->app->app_id;
        $name = 'smart';

        // Single per-disk attribute RRD, every attribute (including id9/id232,
        // which used to get their own separate RRD files) in one file with
        // one DS each -- matching the modern SataHandler design, where every
        // attribute lives in the same per-disk RRD regardless of ID.
        $rrdDef = RrdDefinition::make();
        $fields = [];
        foreach (self::mainRrdAttrKeys() as $ds => $attrKey) {
            $type = isset(Common::ATA_COUNTER_ATTRS[(int) $attrKey]) ? 'COUNTER' : 'GAUGE';
            $rrdDef->addDataset($ds, $type, 0);
            $fields[$ds] = $this->numericAttr($disk, $attrKey);
        }

        $this->putRrd('app', [
            'name'    => $name,
            'app_id'  => $appId,
            'rrd_def' => $rrdDef,
            'rrd_name' => ['app', $name, $appId, $diskKey],
        ], $fields);

        // Max temperature is a sensor (smart_v1_maxtemp, see discoverSensors()/
        // updateSensorValues()), which gets its own RRD via the sensor framework
        // -- no separate hand-rolled RRD needed here.
    }

    /**
     * Compare each alert category against the previous poll's saved state and
     * fire logEvent() for newly detected or cleared conditions.
     *
     * @param list<array<string,mixed>> $disks
     */
    private function pollAlerts(array $disks): void
    {
        $old = $this->getAppData() + [
            'disks_with_failed_tests'  => [],
            'disks_with_failed_health' => [],
            'disks_with_over_temp'     => [],
            'disks_with_dev_error'     => [],
        ];

        $current = [
            'disks_with_failed_tests'  => [],
            'disks_with_failed_health' => [],
            'disks_with_over_temp'     => [],
            'disks_with_dev_error'     => [],
        ];
        $newFailedTests = [];
        $newFailedHealth = [];
        $newOverTemp = [];
        $newDevError = [];

        foreach ($disks as $disk) {
            $key = $this->diskKey($disk);

            if ((is_numeric($disk['read_failure'] ?? null) && $disk['read_failure'] > 0) ||
                (is_numeric($disk['unknown_failure'] ?? null) && $disk['unknown_failure'] > 0)) {
                $current['disks_with_failed_tests'][$key] = 1;
                if (! isset($old['disks_with_failed_tests'][$key])) {
                    $newFailedTests[] = $key;
                }
            }

            if (isset($disk['health_pass']) && is_numeric($disk['health_pass']) && $disk['health_pass'] < 1) {
                $current['disks_with_failed_health'][$key] = 1;
                if (! isset($old['disks_with_failed_health'][$key])) {
                    $newFailedHealth[] = $key;
                }
            }

            if (isset($disk['over_temp']) && is_numeric($disk['over_temp']) && $disk['over_temp'] > 0) {
                $current['disks_with_over_temp'][$key] = 1;
                if (! isset($old['disks_with_over_temp'][$key])) {
                    $newOverTemp[] = $key;
                }
            }

            if (isset($disk['dev_error']) && is_numeric($disk['dev_error']) && $disk['dev_error'] > 0) {
                $current['disks_with_dev_error'][$key] = 1;
                if (! isset($old['disks_with_dev_error'][$key])) {
                    $newDevError[] = $key;
                }
            }
        }

        if ($newFailedTests !== []) {
            $this->logEvent(Severity::Error, 'SMART found new disks with failed tests: ' . json_encode($newFailedTests));
        }
        if ($current['disks_with_failed_tests'] === [] && $old['disks_with_failed_tests'] !== []) {
            $this->logEvent(Severity::Ok, 'SMART is no longer finding any disks with failed tests');
        }

        if ($newFailedHealth !== []) {
            $this->logEvent(Severity::Error, 'SMART found new disks with failed health checks: ' . json_encode($newFailedHealth));
        }
        if ($current['disks_with_failed_health'] === [] && $old['disks_with_failed_health'] !== []) {
            $this->logEvent(Severity::Ok, 'SMART is no longer finding any disks with failed health checks');
        }

        if ($newOverTemp !== []) {
            $this->logEvent(Severity::Error, 'SMART found new disks over heating: ' . json_encode($newOverTemp));
        }
        if ($current['disks_with_over_temp'] === [] && $old['disks_with_over_temp'] !== []) {
            $this->logEvent(Severity::Ok, 'SMART is no longer finding any disks over heating');
        }

        if ($newDevError !== []) {
            $this->logEvent(Severity::Error, 'SMART found new disks polling errors: ' . json_encode($newDevError));
        }

        $this->saveAppData($current);
    }

    // ── Disk identity / attribute helpers ─────────────────────────────────────

    /**
     * Derive a stable disk key from the disk data object, independent of the
     * JSON object key (which is serial-based only when useSN=1).
     */
    private function diskKey(array $disk): string
    {
        $serial = trim((string) ($disk['serial'] ?? ''));
        if ($serial !== '') {
            return $serial;
        }

        // Unstable fallback when serial is absent.
        $model = trim((string) ($disk['model_name'] ?? ''));
        $path = $this->diskDeviceName($disk);

        return $model !== '' ? "{$model}_{$path}" : $path;
    }

    private function diskLabel(array $disk, string $fallback): string
    {
        return DiskIdentity::label([
            'model_name'    => $disk['model_name'] ?? '',
            'serial_number' => $disk['serial'] ?? '',
            'device_name'   => $disk['disk'] ?? '',
        ], $fallback);
    }

    /** First word of disk['disk'], e.g. "sdc" from "sdc -d sat". */
    private function diskDeviceName(array $disk): string
    {
        $raw = trim((string) ($disk['disk'] ?? ''));

        return explode(' ', $raw, 2)[0];
    }

    /**
     * Parse smartctl self-test log text into structured entries keyed by entry_num.
     * Each entry carries test_type, result (MIB enum), result_passed, remaining_pct,
     * power_on_hours, and lba_first_error. Returns [] when text is empty or null.
     *
     * @return array<int, array<string,mixed>>
     */
    private function parseSelftestLog(string $text): array
    {
        $entries = [];
        foreach (explode("\n", $text) as $line) {
            if (! preg_match('/^#\s*(\d+)\s+(.+)/', trim($line), $m)) {
                continue;
            }
            $parts = preg_split('/\s{2,}/', trim($m[2]), 5);
            if (count($parts) < 5) {
                continue;
            }
            [$typeName, $status, $pctStr, $hours, $lbaStr] = $parts;
            $lbaStr = trim($lbaStr);
            $entries[(int) $m[1]] = [
                'test_type'      => $this->selftestTypeId($typeName),
                'result'         => $this->selftestResultEnum($status),
                'result_passed'  => $this->selftestResultEnum($status) === 1,
                'remaining_pct'  => (int) $pctStr,
                'power_on_hours' => (int) $hours,
                'lba_first_error' => ($lbaStr === '-' || $lbaStr === '') ? null : (int) hexdec(ltrim($lbaStr, '0x')),
            ];
        }

        return $entries;
    }

    /** Map "Short offline" / "Extended offline" etc. to the MIB SmartmonAtaSelfTestType enum. */
    private function selftestTypeId(string $typeName): int
    {
        return match (strtolower(strtok($typeName, ' '))) {
            'short'      => 1,
            'extended',
            'long'       => 2,
            'conveyance' => 3,
            'selective'  => 4,
            default      => 0,
        };
    }

    /** Map a smartctl status string to the MIB SmartmonAtaSelfTestResult enum (1-9, 15, 0=unknown). */
    private function selftestResultEnum(string $status): int
    {
        $s = strtolower($status);
        if (str_contains($s, 'in progress')) {
            return 15;
        }
        if (str_contains($s, 'without error')) {
            return 1;
        }
        if (str_contains($s, 'aborted')) {
            return 2;
        }
        if (str_contains($s, 'interrupted')) {
            return 3;
        }
        if (str_contains($s, 'handling damage')) {
            return 9;
        }
        if (str_contains($s, 'read failure')) {
            return 8;
        }
        if (str_contains($s, 'servo')) {
            return 7;
        }
        if (str_contains($s, 'electrical')) {
            return 6;
        }
        if (str_contains($s, 'unknown failure')) {
            return 5;
        }
        if (str_contains($s, 'fatal')) {
            return 4;
        }

        return 0;
    }

    /**
     * Convert the most recent log entry's MIB result enum to the ATA status nibble
     * expected by the smart_selftest_status sensor (0-8, 0xF). Returns null when
     * the log is empty or the result is unknown (enum 0), suppressing the sensor.
     *
     * @param array<int, array<string,mixed>> $logEntries  keyed by entry_num
     */
    private function selftestStatusNibble(array $logEntries): ?int
    {
        if ($logEntries === []) {
            return null;
        }
        $first = $logEntries[1] ?? reset($logEntries);
        $enum = (int) $first['result'];
        if ($enum === 0) {
            return null;
        }

        return $enum === 15 ? 0xF : $enum - 1;
    }

    /**
     * Hours elapsed since the most recent self-test of $testType (1=short, 2=long).
     * Reads current POH from smart_sata_attributes (id9) and last test POH from
     * smart_sata_selftest_log. Returns null when either value is unavailable.
     */
    private function selftestAgeHours(string $diskKey, int $testType): ?int
    {
        $appId = $this->app->app_id;

        $currentPoh = DB::table('smart_sata_attributes')
            ->where('app_id', $appId)
            ->where('disk_key', $diskKey)
            ->where('attribute_id', 9)
            ->value('value_raw');
        if ($currentPoh === null) {
            return null;
        }

        $lastTestPoh = DB::table('smart_sata_selftest_log')
            ->where('app_id', $appId)
            ->where('disk_key', $diskKey)
            ->where('test_type', $testType)
            ->max('power_on_hours');
        if ($lastTestPoh === null) {
            return null;
        }

        return max(0, (int) $currentPoh - (int) $lastTestPoh);
    }

    /** Wear level: first non-null value of attribute IDs 173, 177, 231, 232, 233. */
    private function wearAttr(array $disk): ?int
    {
        foreach (['173', '177', '231', '232', '233'] as $id) {
            if (isset($disk[$id]) && is_numeric($disk[$id])) {
                return (int) $disk[$id];
            }
        }

        return null;
    }

    /** Return a disk attribute cast to float, or null if absent or non-numeric. */
    private function numericAttr(array $disk, string $key): ?float
    {
        return isset($disk[$key]) && is_numeric($disk[$key]) ? (float) $disk[$key] : null;
    }
}
