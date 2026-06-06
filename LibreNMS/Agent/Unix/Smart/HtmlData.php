<?php

namespace LibreNMS\Agent\Unix\Smart;

use App\Facades\Rrd;
use App\Models\Application;
use App\Models\Sensor;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data layer for the SMART HTML view (SNMP / SMARTMON-MIB handler).
 *
 * Reads the structured smart_* database tables populated by
 * {@see \LibreNMS\Agent\Module\Smart\Common} and exposes a clean, pre-decoded
 * per-disk structure for the Blade view. Modelled on the mdadm HtmlData layer.
 *
 * Scope: SATA / ATA devices (protocol_type 1 = ata, 2 = sat). NVMe and SAS
 * tables exist in the schema but are not yet populated by the MIB handler, so
 * they are intentionally not loaded here.
 *
 * Integer codes stored in the database are SMARTMON-TC-MIB textual-convention
 * enumeration values; this class owns the value → human-label mappings.
 */
class HtmlData
{
    private const CACHE_TTL = 300; // seconds (5 min — matches default poll interval)

    /** SATA device protocol_type values (SmartmonDeviceType: ata=1, sat=2). */
    private const SATA_TYPES = [1, 2];

    /** ATA SMART attribute IDs that carry SSD wear-remaining as the normalised value. */
    private const WEAR_ATTR_IDS = [173, 177, 202, 231, 233];

    /**
     * @var array<string, array<string, mixed>> Per-disk data keyed by disk_key.
     */
    public readonly array $disks;

    /** @var EloquentCollection<int, Sensor> All app:smart_mib:* sensors, keyed by sensor_index. */
    public readonly EloquentCollection $allSensors;

    private function __construct(
        public readonly Application $app,
        public readonly array $device,
    ) {
        $this->allSensors = $this->loadSensors();
        $this->disks = $this->loadDisks();
    }

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /** Load data for one device+app, cached for 5 minutes. */
    public static function forDevice(Application $app, array $device): self
    {
        return Cache::remember(
            self::cacheKey((int) ($device['device_id'] ?? 0), (int) $app->app_id),
            self::CACHE_TTL,
            fn () => new self($app, $device)
        );
    }

    /** Flush the cache for this device+app so the next request reloads fresh data. */
    public function invalidate(): void
    {
        Cache::forget(self::cacheKey((int) ($this->device['device_id'] ?? 0), (int) $this->app->app_id));
    }

    private static function cacheKey(int $deviceId, int $appId): string
    {
        return "smart.htmldata.{$deviceId}.{$appId}";
    }

    // -------------------------------------------------------------------------
    // Public accessors
    // -------------------------------------------------------------------------

    /** True when the MIB/DB handler has discovered SATA disks for this app. */
    public function hasDisks(): bool
    {
        return $this->disks !== [];
    }

    /** Disk keys in display order (sorted by device name). */
    public function diskKeys(): array
    {
        return array_keys($this->disks);
    }

    public function disk(string $diskKey): ?array
    {
        return $this->disks[$diskKey] ?? null;
    }

    /** Stable sensor/RRD index for a disk key — must match Common::mibDiskIndex(). */
    public function diskIndex(string $diskKey): string
    {
        return substr((string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $diskKey), 0, 80);
    }

    public function diskNavigation(string $diskKey): string
    {
        return 'tab=apps/app=smart/disk=' . rawurlencode($diskKey) . '/';
    }

    // -------------------------------------------------------------------------
    // Data loading
    // -------------------------------------------------------------------------

    /** @return EloquentCollection<int, Sensor> */
    private function loadSensors(): EloquentCollection
    {
        return Sensor::where('device_id', $this->device['device_id'])
            ->where('sensor_oid', 'like', 'app:smart_mib:%')
            ->with('translations')
            ->get()
            ->keyBy('sensor_index');
    }

    /** @return array<string, array<string, mixed>> */
    private function loadDisks(): array
    {
        $appId = (int) $this->app->app_id;

        $devices = DB::table('smart_devices')
            ->where('app_id', $appId)
            ->whereIn('protocol_type', self::SATA_TYPES)
            ->get();

        if ($devices->isEmpty()) {
            return [];
        }

        $diskKeys = $devices->pluck('disk_key')->all();

        $info = $this->groupByDiskKey('smart_sata_info', $appId, $diskKeys);
        $health = $this->groupByDiskKey('smart_sata_health', $appId, $diskKeys);
        $attributes = $this->groupByDiskKey('smart_sata_attributes', $appId, $diskKeys, 'attribute_id');
        $selftests = $this->groupByDiskKey('smart_sata_selftest_log', $appId, $diskKeys, 'entry_num');
        $errors = $this->groupByDiskKey('smart_sata_error_log', $appId, $diskKeys, 'entry_num');
        $errorCmds = $this->groupByDiskKey('smart_sata_error_cmd', $appId, $diskKeys, ['error_entry_num', 'cmd_slot']);
        $devStats = $this->groupByDiskKey('smart_sata_dev_stats', $appId, $diskKeys, ['page_num', 'stat_offset']);
        $phyEvents = $this->groupByDiskKey('smart_sata_phy_events', $appId, $diskKeys, 'event_id');
        $erc = $this->groupByDiskKey('smart_sata_erc', $appId, $diskKeys, 'direction');
        $pending = $this->groupByDiskKey('smart_sata_pending_defects', $appId, $diskKeys, 'entry_num');

        $disks = [];
        foreach ($devices as $dev) {
            $key = (string) $dev->disk_key;
            $disks[$key] = [
                'disk_key'         => $key,
                'idx'              => $this->diskIndex($key),
                'protocol'         => $dev->protocol_type !== null ? (int) $dev->protocol_type : null,
                'device_name'      => $dev->device_name,
                'device_path'      => $dev->device_path,
                'model_name'       => $dev->model_name,
                'model_family'     => $dev->model_family,
                'serial_number'    => $dev->serial_number,
                'firmware_version' => $dev->firmware_version,
                'wwn'              => $dev->wwn,
                'last_poll_time'   => $dev->last_poll_time,
                'last_poll_result' => $dev->last_poll_result !== null ? (int) $dev->last_poll_result : null,
                'info'             => isset($info[$key][0]) ? (array) $info[$key][0] : [],
                'health'           => isset($health[$key][0]) ? (array) $health[$key][0] : [],
                'attributes'       => array_map(fn ($r) => (array) $r, $attributes[$key] ?? []),
                'selftests'        => array_map(fn ($r) => (array) $r, $selftests[$key] ?? []),
                'errors'           => array_map(fn ($r) => (array) $r, $errors[$key] ?? []),
                'error_cmds'       => $this->indexErrorCmds($errorCmds[$key] ?? []),
                'dev_stats'        => $this->indexDevStats($devStats[$key] ?? []),
                'phy_events'       => array_map(fn ($r) => (array) $r, $phyEvents[$key] ?? []),
                'erc'              => $this->indexByColumn($erc[$key] ?? [], 'direction'),
                'pending_defects'  => array_map(fn ($r) => (array) $r, $pending[$key] ?? []),
            ];
        }

        // Sort by device name (then disk key) for stable display order.
        uasort($disks, static function (array $a, array $b): int {
            return strcmp(
                strtolower((string) ($a['device_name'] ?? $a['disk_key'])),
                strtolower((string) ($b['device_name'] ?? $b['disk_key']))
            );
        });

        return $disks;
    }

    /**
     * Load all rows of a table for the given disk keys and group by disk_key.
     * When $orderBy is given, rows within each group are ordered by it.
     *
     * @param  string|array<int,string>|null  $orderBy
     * @return array<string, array<int, object>>
     */
    private function groupByDiskKey(string $table, int $appId, array $diskKeys, string|array|null $orderBy = null): array
    {
        $query = DB::table($table)
            ->where('app_id', $appId)
            ->whereIn('disk_key', $diskKeys);

        foreach ((array) $orderBy as $col) {
            $query->orderBy($col);
        }

        $grouped = [];
        foreach ($query->get() as $row) {
            $grouped[(string) $row->disk_key][] = $row;
        }

        return $grouped;
    }

    /** @return array<int, array<int, array<string,mixed>>> [error_entry_num => [cmd rows]] */
    private function indexErrorCmds(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->error_entry_num][] = (array) $row;
        }

        return $out;
    }

    /**
     * Group device-statistics rows by page.
     *
     * @return array<int, array{page_num:int, page_name:?string, rows:array<int,array<string,mixed>>}>
     */
    private function indexDevStats(array $rows): array
    {
        $pages = [];
        foreach ($rows as $row) {
            $pageNum = (int) $row->page_num;
            if (! isset($pages[$pageNum])) {
                $pages[$pageNum] = [
                    'page_num'  => $pageNum,
                    'page_name' => $row->page_name,
                    'rows'      => [],
                ];
            }
            $pages[$pageNum]['rows'][] = (array) $row;
        }

        return $pages;
    }

    /** @return array<int|string, array<string,mixed>> */
    private function indexByColumn(array $rows, string $column): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row->{$column}] = (array) $row;
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Sensor lookups
    // -------------------------------------------------------------------------

    /** First temperature sensor for a disk (SENSOR-MIB index "{idx}_temp_{n}"). */
    public function temperatureSensor(string $diskKey): ?Sensor
    {
        $prefix = $this->diskIndex($diskKey) . '_temp_';

        foreach ($this->allSensors as $index => $sensor) {
            if (str_starts_with((string) $index, $prefix) && $sensor->sensor_class === 'temperature') {
                return $sensor;
            }
        }

        return null;
    }

    public function healthSensor(string $diskKey): ?Sensor
    {
        return $this->allSensors->get($this->diskIndex($diskKey) . '_health');
    }

    public function selftestStatusSensor(string $diskKey): ?Sensor
    {
        return $this->allSensors->get($this->diskIndex($diskKey) . '_selftest_status');
    }

    /** All sensors belonging to a disk, keyed by sensor_index. */
    public function diskSensors(string $diskKey): array
    {
        $prefix = $this->diskIndex($diskKey) . '_';
        $out = [];
        foreach ($this->allSensors as $index => $sensor) {
            if (str_starts_with((string) $index, $prefix)) {
                $out[(string) $index] = $sensor;
            }
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Display modes (navigation)
    // -------------------------------------------------------------------------

    /** Available drive-label modes for the navigation selector. */
    public function labelModes(): array
    {
        return [
            'device'        => 'Device',
            'serial'        => 'Serial',
            'device_serial' => 'Device (Serial)',
            'model_serial'  => 'Model (Serial)',
        ];
    }

    /** Available per-disk view modes. */
    public function diskViewModes(): array
    {
        return [
            'basic'    => 'Basic',
            'detailed' => 'Detailed',
            'graphs'   => 'Graphs',
        ];
    }

    /** Resolve a navigation label for a disk under the given label mode. */
    public function displayLabel(array $disk, string $mode): string
    {
        $device = $this->deviceLabel($disk);
        $serial = $this->serial($disk);
        $model = $this->model($disk);

        return match ($mode) {
            'serial'        => $serial !== '' ? $serial : $device,
            'device_serial' => $serial !== '' ? "{$device} ({$serial})" : $device,
            'model_serial'  => $serial !== '' ? "{$model} ({$serial})" : $model,
            default         => $device,
        };
    }

    // -------------------------------------------------------------------------
    // Derived overview values
    // -------------------------------------------------------------------------

    public function deviceLabel(array $disk): string
    {
        $name = trim((string) ($disk['device_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return (string) ($disk['disk_key'] ?? '');
    }

    public function model(array $disk): string
    {
        $model = trim((string) ($disk['model_name'] ?? ''));

        return $model !== '' ? $model : '-';
    }

    public function serial(array $disk): string
    {
        return trim((string) ($disk['serial_number'] ?? ''));
    }

    /** Human label for the device protocol/media (e.g. "SATA SSD", "SATA HDD"). */
    public function typeLabel(array $disk): string
    {
        $protocol = $this->decode('protocol', $disk['protocol'] ?? null);
        $protocol = $protocol === '-' ? '' : $protocol;

        $rotation = $disk['info']['rotation_rate'] ?? null;
        $media = null;
        if (is_numeric($rotation)) {
            $media = (int) $rotation === 0 ? 'SSD' : 'HDD';
        }

        $label = trim(($protocol === 'SAT' ? 'SATA' : $protocol) . ' ' . (string) $media);

        return $label !== '' ? $label : '-';
    }

    /** SSD wear-remaining percentage from the normalised value of a wear attribute, or null. */
    public function wearRemaining(array $disk): ?float
    {
        foreach ($disk['attributes'] as $attr) {
            if (in_array((int) ($attr['attribute_id'] ?? -1), self::WEAR_ATTR_IDS, true)
                && is_numeric($attr['value_norm'] ?? null)) {
                return (float) $attr['value_norm'];
            }
        }

        return null;
    }

    public function powerOnHours(array $disk): ?int
    {
        $hours = $disk['health']['power_on_hours'] ?? null;

        return is_numeric($hours) ? (int) $hours : null;
    }

    /**
     * Hours since the most recent self-test of a given type (1 = short, 2 = extended),
     * or null when unknown.
     */
    public function selftestAgeHours(array $disk, int $testType): ?int
    {
        $current = $this->powerOnHours($disk);
        if ($current === null) {
            return null;
        }

        $best = null;
        foreach ($disk['selftests'] as $entry) {
            if ((int) ($entry['test_type'] ?? -1) !== $testType) {
                continue;
            }
            $hours = $entry['power_on_hours'] ?? null;
            if (is_numeric($hours)) {
                $best = $best === null ? (int) $hours : max($best, (int) $hours);
            }
        }

        return $best === null ? null : max(0, $current - $best);
    }

    /**
     * Distinct ATA attribute IDs across all disks, for the overview multi-disk
     * attribute graphs. Returns [id => display name] sorted by id.
     *
     * @return array<int, string>
     */
    public function overviewAttributeIds(): array
    {
        $ids = [];
        foreach ($this->disks as $disk) {
            foreach ($disk['attributes'] as $attr) {
                $id = (int) ($attr['attribute_id'] ?? 0);
                if ($id <= 0 || isset($ids[$id])) {
                    continue;
                }
                $ids[$id] = str_replace('_', ' ', trim((string) ($attr['name'] ?? ('Attribute ' . $id))));
            }
        }
        ksort($ids);

        return $ids;
    }

    /** Header badge value for the reliability (Big 5) graph: SMART pass + wear. */
    public function reliabilityHeader(array $disk): string
    {
        $parts = [];
        $passed = $disk['health']['sct_smart_status_passed'] ?? $disk['health']['overall_status'] ?? null;
        if ($passed !== null) {
            $parts[] = (int) $passed === 1 ? 'OK' : 'FAIL';
        }
        $wear = $this->wearRemaining($disk);
        if ($wear !== null) {
            $parts[] = (int) round(max(0.0, min(100.0, $wear))) . '% wear';
        }

        return $parts !== [] ? implode(' | ', $parts) : '-';
    }

    /** Header badge value for the power-on hours graph. */
    public function powerHeader(array $disk): string
    {
        $hours = $this->powerOnHours($disk);

        return $hours !== null ? 'Hours: ' . number_format($hours, 0, '.', ' ') : '-';
    }

    /**
     * Human-readable flag lines for an attribute, for the Flags tooltip.
     * Only type (pre-fail/old-age) and update mode are available in the DB.
     *
     * @return array<int, string>
     */
    public function attributeFlagLines(array $attr): array
    {
        $lines = [];
        if (isset($attr['attr_type'])) {
            $lines[] = 'Type: ' . $this->decode('attr_type', $attr['attr_type']);
        }
        if (isset($attr['updated_when'])) {
            $lines[] = 'Updated: ' . $this->decode('attr_updated', $attr['updated_when']);
        }

        return $lines;
    }

    /** Short flags summary shown in the attribute table cell. */
    public function attributeFlagsShort(array $attr): string
    {
        $parts = [];
        if (($t = (int) ($attr['attr_type'] ?? 0)) > 0) {
            $parts[] = $t === 1 ? 'P' : 'O';
        }
        if (($u = (int) ($attr['updated_when'] ?? 0)) > 0) {
            $parts[] = $u === 1 ? 'C' : 'O';
        }

        return implode('-', $parts);
    }

    /** Device-statistics rows hidden in the detailed view (shown elsewhere). */
    public const DEV_STAT_SKIP_ROWS = ['Lifetime Power-On Resets', 'Power-on Hours'];

    /** Device-statistics pages hidden in the detailed view (shown elsewhere). */
    public const DEV_STAT_SKIP_PAGES = [
        'Temperature Statistics', 'Vendor Specific Statistics', 'Solid State Device Statistics',
    ];

    // -------------------------------------------------------------------------
    // RRD-derived graph specs (ATA attributes share V2 RRD layout)
    // -------------------------------------------------------------------------

    /** Datasets present in the per-disk attribute RRD (['app','smart',app_id,idx]). */
    private function rrdDatasets(string $diskKey): array
    {
        $rrdFile = Rrd::name((string) ($this->device['hostname'] ?? ''), ['app', 'smart', $this->app->app_id, $this->diskIndex($diskKey)]);
        if (! Rrd::checkRrdExists($rrdFile)) {
            return [];
        }

        $point = Rrd::lastUpdate($rrdFile);
        if ($point === null || ! is_array($point->data ?? null)) {
            return [];
        }

        return array_keys($point->data);
    }

    public function hasBig5Rrd(string $diskKey): bool
    {
        return array_intersect($this->rrdDatasets($diskKey), ['id5', 'id187', 'id188', 'id197', 'id198']) !== [];
    }

    public function hasOtherRrd(string $diskKey): bool
    {
        return array_intersect($this->rrdDatasets($diskKey), ['id10', 'id183', 'id184', 'id196', 'id199']) !== [];
    }

    /**
     * Per-attribute graph specs for a disk, limited to attributes whose RRD
     * datasets actually exist.
     *
     * @return array<int, array{id:int, title:string, header:string, thresh:?float, has_raw:bool, has_norm:bool}>
     */
    public function attributeGraphSpecs(string $diskKey): array
    {
        $disk = $this->disks[$diskKey] ?? null;
        if ($disk === null) {
            return [];
        }

        $available = $this->rrdDatasets($diskKey);
        if ($available === []) {
            return [];
        }

        $specs = [];
        foreach ($disk['attributes'] as $attr) {
            $id = (int) ($attr['attribute_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $hasRaw = in_array('id' . $id, $available, true);
            $hasNorm = in_array('id' . $id . 'Normalized', $available, true);
            if (! $hasRaw && ! $hasNorm) {
                continue;
            }

            $name = str_replace('_', ' ', trim((string) ($attr['name'] ?? ('Attribute ' . $id))));
            $rawValue = $attr['value_raw_string'] ?? $attr['value_raw'] ?? null;
            $header = 'Normalized:' . ($hasNorm ? $this->numericString($attr['value_norm'] ?? null) : '-')
                . ' Raw:' . ($hasRaw ? $this->numericString($rawValue) : '-');

            $specs[$id] = [
                'id'       => $id,
                'title'    => 'ID# ' . $id . ', ' . $name,
                'header'   => $header,
                'thresh'   => is_numeric($attr['value_threshold'] ?? null) ? (float) $attr['value_threshold'] : null,
                'has_raw'  => $hasRaw,
                'has_norm' => $hasNorm,
            ];
        }

        return $specs;
    }

    private function numericString(mixed $value): string
    {
        if (is_numeric($value)) {
            return (string) (0 + $value);
        }

        return is_string($value) && $value !== '' ? $value : '-';
    }

    // -------------------------------------------------------------------------
    // Enum decoding (SMARTMON-TC-MIB textual conventions)
    // -------------------------------------------------------------------------

    private const LABELS = [
        // SmartmonDeviceType
        'protocol' => [
            0 => 'Unknown', 1 => 'ATA', 2 => 'SAT', 3 => 'SCSI', 4 => 'SAS', 5 => 'NVMe',
            6 => 'USB Bridge', 7 => 'MegaRAID', 8 => 'cciss', 9 => 'Areca', 255 => 'Other',
        ],
        // SmartmonPollResult
        'poll_result' => [
            0 => 'Unknown', 1 => 'OK', 2 => 'Failed', 3 => 'Timeout',
            4 => 'Permission denied', 5 => 'Unsupported', 6 => 'Parse error',
        ],
        // SmartmonAtaSmartAttrType
        'attr_type' => [0 => 'Unknown', 1 => 'Pre-fail', 2 => 'Old age'],
        // SmartmonAtaSmartAttrUpdated
        'attr_updated' => [0 => 'Unknown', 1 => 'Always', 2 => 'Offline'],
        // SmartmonAtaSmartAttrStatus
        'attr_status' => [
            -1 => '-', 0 => 'Unknown', 1 => 'OK', 2 => 'Failing now', 3 => 'Failed in past',
        ],
        // SmartmonAtaSelfTestType
        'selftest_type' => [
            0 => 'Offline', 1 => 'Short', 2 => 'Extended', 3 => 'Conveyance', 4 => 'Selective',
            127 => 'Abort offline', 129 => 'Short (captive)', 130 => 'Extended (captive)',
            131 => 'Conveyance (captive)', 132 => 'Selective (captive)',
        ],
        // SmartmonAtaSelfTestResult
        'selftest_result' => [
            0 => 'Unknown', 1 => 'Completed without error', 2 => 'Aborted by host',
            3 => 'Interrupted (reset)', 4 => 'Fatal or unknown failure',
            5 => 'Completed: unknown failure', 6 => 'Completed: electrical failure',
            7 => 'Completed: servo/seek failure', 8 => 'Completed: read failure',
            9 => 'Completed: handling damage', 15 => 'In progress',
        ],
        // SmartmonAtaSelfTestExecStatus (status nibble in smart_sata_health)
        'selftest_exec' => [
            0 => 'Completed without error', 1 => 'Aborted by host', 2 => 'Interrupted (reset)',
            3 => 'Fatal error', 4 => 'Failed: unknown element', 5 => 'Failed: electrical',
            6 => 'Failed: servo', 7 => 'Failed: read', 8 => 'Failed: handling damage',
            15 => 'In progress',
        ],
        // SmartmonAtaDeviceState (error log)
        'device_state' => [
            0 => 'Unknown', 1 => 'Sleeping', 2 => 'Standby', 3 => 'Active or Idle',
            4 => 'SMART offline / self-test',
        ],
        // SmartmonAtaDevStatPage
        'dev_stat_page' => [
            0 => 'Supported Log Pages', 1 => 'General Statistics', 2 => 'Free-Fall Statistics',
            3 => 'Rotating Media Statistics', 4 => 'General Errors Statistics',
            5 => 'Temperature Statistics', 6 => 'Transport Statistics',
            7 => 'Solid State Device Statistics', 255 => 'Vendor Specific',
        ],
        // SmartmonAtaFormFactor
        'form_factor' => [
            0 => 'Unknown', 1 => '5.25"', 2 => '3.5"', 3 => '2.5"', 4 => '1.8"',
            5 => '< 1.8"', 6 => 'mSATA', 7 => 'M.2', 8 => 'MicroSSD', 9 => 'CFast',
        ],
        // SmartmonAtaVersion
        'ata_version' => [
            0 => 'Unknown', 1 => 'ATA-1', 2 => 'ATA-2', 3 => 'ATA-3', 4 => 'ATA/ATAPI-4',
            5 => 'ATA/ATAPI-5', 6 => 'ATA/ATAPI-6', 7 => 'ATA/ATAPI-7', 8 => 'ATA8-ACS',
            9 => 'ACS-2', 10 => 'ACS-3', 11 => 'ACS-4', 12 => 'ACS-5', 13 => 'ACS-6',
            14 => 'ACS-7+',
        ],
        // SmartmonSataVersion
        'sata_version' => [
            0 => 'Unknown', 1 => 'ATA8-AST', 2 => 'SATA 1.0a', 3 => 'SATA II Extensions',
            4 => 'SATA 2.5', 5 => 'SATA 2.6', 6 => 'SATA 3.0', 7 => 'SATA 3.1', 8 => 'SATA 3.2',
            9 => 'SATA 3.3', 10 => 'SATA 3.4', 11 => 'SATA 3.5', 12 => '> SATA 3.5',
        ],
        // SmartmonScsiErrorDirection (reused for ERC direction labels)
        'erc_direction' => [0 => 'Disabled', 1 => 'Read', 2 => 'Write', 3 => 'Verify'],
    ];

    /** Decode a stored integer code to a human label; returns '-' for null/unmapped. */
    public function decode(string $kind, mixed $value): string
    {
        if ($value === null || ! is_numeric($value)) {
            return '-';
        }

        $map = self::LABELS[$kind] ?? [];

        return $map[(int) $value] ?? (string) (int) $value;
    }
}
