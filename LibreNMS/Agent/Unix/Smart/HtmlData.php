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
 * Scope: SATA / ATA devices (protocol_type 1 = ata, 2 = sat) and NVMe devices
 * (protocol_type 5). SAS tables exist in the schema but are not yet populated
 * by the MIB handler, so they are intentionally not loaded here.
 *
 * Integer codes stored in the database are SMARTMON-TC-MIB textual-convention
 * enumeration values; this class owns the value → human-label mappings.
 */
class HtmlData
{
    private const CACHE_TTL = 300; // seconds (5 min — matches default poll interval)

    /** SATA device protocol_type values (SmartmonDeviceType: ata=1, sat=2). */
    private const SATA_TYPES = [1, 2];

    /** NVMe device protocol_type value (SmartmonDeviceType: nvme=5). */
    private const NVME_TYPES = [5];

    /**
     * Fallback COUNTER attribute names used to pick a rate unit only before any
     * rate history exists (fresh attribute, no rate_8h/24h/168h/672h yet). Once
     * rate history is available, attributeRateUnit() picks the unit from the
     * actual average rate instead. See attributeRateUnit().
     */
    private const LEGACY_PERSEC_COUNTER_NAMES = [
        'Used_Rsvd_Blk_Cnt_Tot', 'Unused_Rsvd_Blk_Cnt_Tot',
        'Total_LBAs_Written', 'Total_LBAs_Read',
        'Timed_Workld_Media_Wear', 'Timed_Workld_RdWr_Ratio',
        'Timed_Workld_Timer', 'NAND_Writes',
    ];

    /**
     * Average raw-units-per-hour rate above which attributeRateUnit() switches
     * a COUNTER attribute's display unit from changes/hour to changes/second
     * (3600 changes/hour == 1 change/second on average).
     */
    private const RATE_UNIT_PERSEC_THRESHOLD = 3600.0;

    /** System PCI ID database candidates (pciutils / hwdata). */
    private const PCI_IDS_PATHS = ['/usr/share/misc/pci.ids', '/usr/share/hwdata/pci.ids'];

    /** System IEEE OUI database candidates (ieee-data). */
    private const OUI_PATHS = ['/usr/share/ieee-data/oui.txt', '/usr/share/misc/oui.txt'];

    /**
     * @var array<string, array<string, mixed>> Per-disk data keyed by disk_key.
     */
    public readonly array $disks;

    /** @var EloquentCollection<int, Sensor> All app:smart_mib:* sensors, keyed by sensor_index. */
    public readonly EloquentCollection $allSensors;

    /** @var array<int, string|null> Per-request cache of PCI vendor id => name lookups. */
    private array $pciVendorCache = [];

    /** @var array<int, string|null> Per-request cache of IEEE OUI => org name lookups. */
    private array $ouiVendorCache = [];

    /**
     * Sentinel app_id for the global naming-template default — shared across
     * every device, same convention as smart_attribute_thresholds' app_id=0
     * global-default row.
     */
    private const GLOBAL_SETTINGS_APP_ID = 0;

    /** Global naming template (smart_app_settings.naming_template on the app_id=0 row), or null if unset. */
    private readonly ?string $namingTemplateDefault;

    /** Saved default disk-view mode for this device (smart_app_settings.default_view_mode), or null if unset. */
    private readonly ?string $defaultViewModeValue;

    /** @var array<string, string> Per-disk naming template overrides for this device, keyed by disk_key. */
    private readonly array $namingTemplatesPerDisk;

    private function __construct(
        public readonly Application $app,
        public readonly array $device,
    ) {
        $this->allSensors = $this->loadSensors();
        $this->disks = $this->loadDisks();

        $settings = DB::table('smart_app_settings')->where('app_id', $this->app->app_id)->first();
        $this->defaultViewModeValue = $settings !== null ? trim((string) ($settings->default_view_mode ?? '')) ?: null : null;
        $this->namingTemplatesPerDisk = $settings !== null ? (json_decode((string) ($settings->disk_naming_templates ?? ''), true) ?: []) : [];

        $global = DB::table('smart_app_settings')->where('app_id', self::GLOBAL_SETTINGS_APP_ID)->value('naming_template');
        $this->namingTemplateDefault = trim((string) ($global ?? '')) ?: null;
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

    /**
     * Resolve a URL disk identifier to its canonical disk_key. Accepts either the disk_key
     * itself (old links/bookmarks) or the disk's device_name (e.g. "sda", "nvme0"), which is
     * what diskUrlId() now hands out for new links.
     */
    public function resolveDiskKey(string $id): ?string
    {
        if (isset($this->disks[$id])) {
            return $id;
        }

        foreach ($this->disks as $key => $disk) {
            if (strcasecmp((string) ($disk['device_name'] ?? ''), $id) === 0) {
                return $key;
            }
        }

        return null;
    }

    /** Canonical URL identifier for a disk: its device_name (e.g. "sda") if known, else the disk_key. */
    public function diskUrlId(string $diskKey): string
    {
        $name = trim((string) ($this->disks[$diskKey]['device_name'] ?? ''));

        return $name !== '' ? $name : $diskKey;
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
            ->whereIn('protocol_type', array_merge(self::SATA_TYPES, self::NVME_TYPES))
            ->get();

        if ($devices->isEmpty()) {
            return [];
        }

        $sataKeys = $devices->whereIn('protocol_type', self::SATA_TYPES)->pluck('disk_key')->all();
        $nvmeKeys = $devices->whereIn('protocol_type', self::NVME_TYPES)->pluck('disk_key')->all();

        $info = $this->groupByDiskKey('smart_sata_info', $appId, $sataKeys);
        $health = $this->groupByDiskKey('smart_sata_health', $appId, $sataKeys);
        $attributes = $this->groupByDiskKey('smart_sata_attributes', $appId, $sataKeys, 'attribute_id');
        $selftests = $this->groupByDiskKey('smart_sata_selftest_log', $appId, $sataKeys, 'entry_num');
        $errors = $this->groupByDiskKey('smart_sata_error_log', $appId, $sataKeys, 'entry_num');
        $errorCmds = $this->groupByDiskKey('smart_sata_error_cmd', $appId, $sataKeys, ['error_entry_num', 'cmd_slot']);
        $devStats = $this->groupByDiskKey('smart_sata_dev_stats', $appId, $sataKeys, ['page_num', 'stat_offset']);
        $phyEvents = $this->groupByDiskKey('smart_sata_phy_events', $appId, $sataKeys, 'event_id');
        $erc = $this->groupByDiskKey('smart_sata_erc', $appId, $sataKeys, 'direction');
        $pending = $this->groupByDiskKey('smart_sata_pending_defects', $appId, $sataKeys, 'entry_num');
        $logDir = $this->groupByDiskKey('smart_sata_log_dir', $appId, $sataKeys, 'log_address');
        $selectiveTest = $this->groupByDiskKey('smart_sata_selective_test', $appId, $sataKeys, 'slot');

        // NVMe tables (only loaded when NVMe disks are present).
        $nvInfo = $nvHealth = $nvNs = $nvPwr = $nvLba = $nvSt = $nvErr = $nvCap = [];
        if ($nvmeKeys !== []) {
            $nvInfo = $this->groupByDiskKey('smart_nvme_info', $appId, $nvmeKeys);
            $nvHealth = $this->groupByDiskKey('smart_nvme_health', $appId, $nvmeKeys);
            $nvNs = $this->groupByDiskKey('smart_nvme_namespaces', $appId, $nvmeKeys, 'ns_id');
            $nvPwr = $this->groupByDiskKey('smart_nvme_power_states', $appId, $nvmeKeys, 'state_id');
            $nvLba = $this->groupByDiskKey('smart_nvme_lba_formats', $appId, $nvmeKeys, ['ns_id', 'format_id']);
            $nvSt = $this->groupByDiskKey('smart_nvme_selftest_log', $appId, $nvmeKeys, 'entry_num');
            $nvErr = $this->groupByDiskKey('smart_nvme_error_log', $appId, $nvmeKeys, 'entry_num');
            $nvCap = $this->groupByDiskKey('smart_nvme_capability', $appId, $nvmeKeys);
        }

        $rowsToArrays = static fn (array $rows): array => array_map(static fn ($r) => (array) $r, $rows);

        $disks = [];
        foreach ($devices as $dev) {
            $key = (string) $dev->disk_key;
            $isNvme = in_array((int) $dev->protocol_type, self::NVME_TYPES, true);

            $common = [
                'disk_key'         => $key,
                'idx'              => $this->diskIndex($key),
                'kind'             => $isNvme ? 'nvme' : 'sata',
                'protocol'         => $dev->protocol_type !== null ? (int) $dev->protocol_type : null,
                'device_name'      => $dev->device_name,
                'device_path'      => $dev->device_path,
                'uris'             => $dev->uris ?? null,
                'model_name'       => $dev->model_name,
                'model_family'     => $dev->model_family,
                'serial_number'    => $dev->serial_number,
                'firmware_version' => $dev->firmware_version,
                'wwn'              => $dev->wwn,
                'last_poll_time'   => $dev->last_poll_time,
                'last_poll_result' => $dev->last_poll_result !== null ? (int) $dev->last_poll_result : null,
                'power_state'      => $dev->power_state !== null ? (int) $dev->power_state : null,
            ];

            if ($isNvme) {
                $disks[$key] = $common + [
                    'info'            => isset($nvInfo[$key][0]) ? (array) $nvInfo[$key][0] : [],
                    'health'          => isset($nvHealth[$key][0]) ? (array) $nvHealth[$key][0] : [],
                    'attributes'      => [],
                    'selftests'       => $rowsToArrays($nvSt[$key] ?? []),
                    'nvme_namespaces' => $rowsToArrays($nvNs[$key] ?? []),
                    'nvme_power_states' => $rowsToArrays($nvPwr[$key] ?? []),
                    'nvme_lba_formats' => $rowsToArrays($nvLba[$key] ?? []),
                    'nvme_errors'     => $rowsToArrays($nvErr[$key] ?? []),
                    'nvme_capability' => isset($nvCap[$key][0]) ? (array) $nvCap[$key][0] : [],
                ];

                continue;
            }

            $diskAttributes = $rowsToArrays($attributes[$key] ?? []);
            foreach ($diskAttributes as &$attrRow) {
                $attrRow['rate_unit'] = $this->attributeRateUnit($attrRow);
            }
            unset($attrRow);

            $disks[$key] = $common + [
                'info'             => isset($info[$key][0]) ? (array) $info[$key][0] : [],
                'health'           => isset($health[$key][0]) ? (array) $health[$key][0] : [],
                'attributes'       => $diskAttributes,
                'selftests'        => $rowsToArrays($selftests[$key] ?? []),
                'errors'           => $rowsToArrays($errors[$key] ?? []),
                'error_cmds'       => $this->indexErrorCmds($errorCmds[$key] ?? []),
                'dev_stats'        => $this->indexDevStats($devStats[$key] ?? []),
                'phy_events'       => $rowsToArrays($phyEvents[$key] ?? []),
                'erc'              => $this->indexByColumn($erc[$key] ?? [], 'direction'),
                'pending_defects'  => $rowsToArrays($pending[$key] ?? []),
                'log_dir'          => $rowsToArrays($logDir[$key] ?? []),
                'selective_test'   => $rowsToArrays($selectiveTest[$key] ?? []),
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
        return collect($rows)->keyBy($column)->map(static fn ($row) => (array) $row)->all();
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

    /** NVMe "Available Spare" SENSOR-MIB percent sensor for a disk, or null. */
    public function availableSpareSensor(string $diskKey): ?Sensor
    {
        foreach ($this->diskSensors($diskKey) as $sensor) {
            if ($sensor->sensor_class === 'percent' && stripos((string) $sensor->sensor_descr, 'spare') !== false) {
                return $sensor;
            }
        }

        return null;
    }

    /** "Percentage Used" (NVMe) / "Endurance Used" (SATA) SENSOR-MIB percent sensor for a disk, or null. */
    public function percentageUsedSensor(string $diskKey): ?Sensor
    {
        foreach ($this->diskSensors($diskKey) as $sensor) {
            if ($sensor->sensor_class === 'percent' && stripos((string) $sensor->sensor_descr, 'used') !== false) {
                return $sensor;
            }
        }

        return null;
    }

    /**
     * The sensor's own name with the redundant disk-label prefix stripped.
     *
     * Descriptions are built as "SMART {disk label} {name}" at discovery time
     * (see Smart\Common::sensorLabel); in a per-disk table the label is just
     * noise, so return e.g. "Composite" / "Sensor 1" instead of the full string.
     */
    public function shortSensorName(Sensor $sensor, array $disk): string
    {
        $descr = (string) $sensor->sensor_descr;
        $label = $this->diskLabel($disk);
        $prefix = rtrim('SMART ' . $label) . ' ';

        if ($label !== '' && str_starts_with($descr, $prefix)) {
            return substr($descr, strlen($prefix));
        }

        return (string) preg_replace('/^SMART\s+/', '', $descr);
    }

    /**
     * Resolve a PCI vendor id to its name via the system pci.ids database
     * (vendor lines are "<4-hex-id>  Vendor Name" at column 0). Results are
     * cached per request. Returns null if the file is missing or id unknown.
     */
    public function pciVendorName(int $id): ?string
    {
        if (array_key_exists($id, $this->pciVendorCache)) {
            return $this->pciVendorCache[$id];
        }

        $hex = sprintf('%04x', $id);

        return $this->pciVendorCache[$id] = $this->lookupInIdsFile(
            self::PCI_IDS_PATHS,
            // Skip device (tab-indented) and comment lines; match vendor id.
            static fn (string $line): ?string => $line[0] !== "\t" && $line[0] !== '#'
                && strncmp($line, $hex, 4) === 0 && ($line[4] ?? '') === ' '
                ? trim(substr($line, 4))
                : null
        );
    }

    /** Whether the PCI ID database is installed (for surfacing a missing-dependency hint). */
    public function pciIdsAvailable(): bool
    {
        return $this->firstReadable(self::PCI_IDS_PATHS) !== null;
    }

    /** Whether the IEEE OUI database is installed (for surfacing a missing-dependency hint). */
    public function ouiDbAvailable(): bool
    {
        return $this->firstReadable(self::OUI_PATHS) !== null;
    }

    /** First readable path from the list, or null if none exist. */
    private function firstReadable(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Scan the first readable file from $paths line by line, returning the
     * first non-null result of $lineMatcher, or null if the file is missing
     * or no line matches.
     *
     * @param  array<int,string>  $paths
     * @param  callable(string): ?string  $lineMatcher
     */
    private function lookupInIdsFile(array $paths, callable $lineMatcher): ?string
    {
        if (($path = $this->firstReadable($paths)) === null || ($fh = fopen($path, 'r')) === false) {
            return null;
        }

        try {
            while (($line = fgets($fh)) !== false) {
                if (($match = $lineMatcher($line)) !== null) {
                    return $match;
                }
            }
        } finally {
            fclose($fh);
        }

        return null;
    }

    /**
     * Resolve an IEEE OUI (24-bit) to its organisation name via the system
     * oui.txt database. Lines are "<6-HEX-OUI>     (base 16)<tabs>Org Name".
     * Cached per request; returns null when the file is missing or OUI unknown.
     */
    public function ouiVendorName(int $oui): ?string
    {
        if (array_key_exists($oui, $this->ouiVendorCache)) {
            return $this->ouiVendorCache[$oui];
        }

        $hex = sprintf('%06X', $oui);

        return $this->ouiVendorCache[$oui] = $this->lookupInIdsFile(
            self::OUI_PATHS,
            static fn (string $line): ?string => strncmp($line, $hex, 6) === 0 && ($pos = strpos($line, '(base 16)')) !== false
                ? trim(substr($line, $pos + strlen('(base 16)')))
                : null
        );
    }

    /** Reconstruct the disk label used in sensor descriptions: "model serial (device)". */
    private function diskLabel(array $disk): string
    {
        $model = trim((string) ($disk['model_name'] ?? ''));
        $serial = trim((string) ($disk['serial_number'] ?? ''));
        $name = trim((string) ($disk['device_name'] ?? ''));

        $label = trim(implode(' ', array_filter([$model, $serial])));
        if ($name !== '') {
            $label = $label !== '' ? "{$label} ({$name})" : $name;
        }

        return $label;
    }

    public function selftestStatusSensor(string $diskKey): ?Sensor
    {
        return $this->allSensors->get($this->diskIndex($diskKey) . '_selftest_status');
    }

    /** Self-test age sensor for a disk: $suffix is 'short' or 'long'. Returns null when not available. */
    public function selftestAgeSensor(string $diskKey, string $suffix): ?Sensor
    {
        return $this->allSensors->get($this->diskIndex($diskKey) . "_selftest_{$suffix}");
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
            'custom'        => 'Custom',
        ];
    }

    /** The saved naming template: per-disk override (when $diskKey given and set) falling back to the device-wide default, or null if neither is set. */
    public function namingTemplate(?string $diskKey = null): ?string
    {
        if ($diskKey !== null && isset($this->namingTemplatesPerDisk[$diskKey])) {
            return $this->namingTemplatesPerDisk[$diskKey];
        }

        return $this->namingTemplateDefault;
    }

    /** The saved default disk-view mode (overview page), falling back to 'basic'. */
    public function defaultViewMode(): string
    {
        $mode = $this->defaultViewModeValue ?? '';

        return array_key_exists($mode, $this->diskViewModes()) ? $mode : 'basic';
    }

    /** Render a naming template's $variable placeholders against a disk's data. */
    public function renderNamingTemplate(array $disk, string $template): string
    {
        return preg_replace_callback('/\$(device|model|serial|wwn|model_family)\b/', function (array $m) use ($disk): string {
            return match ($m[1]) {
                'device'       => $this->deviceLabel($disk),
                'model'        => $this->model($disk),
                'serial'       => $this->serial($disk),
                'wwn'          => trim((string) ($disk['wwn'] ?? '')),
                'model_family' => trim((string) ($disk['model_family'] ?? '')),
            };
        }, $template);
    }

    /** Available per-disk view modes, across all disk types. */
    public function diskViewModes(): array
    {
        return [
            'basic'    => 'Basic',
            'metadata' => 'Metadata',
            'selftest' => 'Self-test',
            'tables'   => 'Statistics',
            'graphs'   => 'Graphs',
        ];
    }

    /** Available per-disk view modes for the given disk, filtered by NVMe vs SATA/SAS capability. */
    public function diskViewModesFor(array $disk): array
    {
        if ($this->isNvme($disk)) {
            return [
                'basic'    => 'Basic',
                'metadata' => 'Metadata',
                'selftest' => 'Self-test',
                'graphs'   => 'Graphs',
            ];
        }

        return $this->diskViewModes();
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
            'custom'        => $this->renderNamingTemplate($disk, $this->namingTemplate((string) ($disk['disk_key'] ?? '')) ?? '$device'),
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

    /** True for NVMe disks. */
    public function isNvme(array $disk): bool
    {
        return ($disk['kind'] ?? '') === 'nvme';
    }

    /** Human label for the device protocol/media (e.g. "SATA SSD", "NVMe SSD"). */
    public function typeLabel(array $disk): string
    {
        $protocol = $this->decode('protocol', $disk['protocol'] ?? null);
        $protocol = $protocol === '-' ? '' : $protocol;

        // NVMe is always solid-state; SATA media is derived from the rotation rate.
        $rotation = $disk['info']['rotation_rate'] ?? null;
        $media = null;
        if ($this->isNvme($disk)) {
            $media = 'SSD';
        } elseif (is_numeric($rotation)) {
            $media = (int) $rotation === 0 ? 'SSD' : 'HDD';
        }

        $label = trim(($protocol === 'SAT' ? 'SATA' : $protocol) . ' ' . (string) $media);

        return $label !== '' ? $label : '-';
    }

    /**
     * SSD wear-remaining percentage, from the "Percentage Used" (NVMe) /
     * "Endurance Used" (SATA) SENSOR-MIB sensor.
     */
    public function wearRemaining(array $disk): ?float
    {
        $sensor = $this->percentageUsedSensor((string) $disk['disk_key']);

        return $sensor && is_numeric($sensor->sensor_current)
            ? max(0.0, min(100.0, (float) $sensor->sensor_current))
            : null;
    }

    /**
     * Estimated total service life in years, extrapolated from wear consumed so far
     * against power-on age (assumes a constant wear rate). SATA is gated to attribute
     * 177 "Wear_Leveling_Count" specifically — its normalised value (100 = new, falling
     * to the failure threshold as the drive wears) is a reliable remaining-life percentage,
     * unlike some of the other vendor-specific wear attributes. NVMe uses the
     * spec-defined "Percentage Used" sensor directly. Returns null when the required
     * attribute/sensor is absent or power-on hours are unknown/zero.
     */
    public function estimatedLifetimeYears(array $disk): ?float
    {
        $hours = $this->powerOnHours($disk);
        if ($hours === null || $hours <= 0) {
            return null;
        }

        $wearRemaining = $this->isNvme($disk)
            ? $this->nvmePercentageUsedRemaining((string) $disk['disk_key'])
            : $this->wearLevelingCountRemaining($disk);

        if ($wearRemaining === null) {
            return null;
        }

        $wearUsed = 100.0 - $wearRemaining;
        if ($wearUsed <= 0) {
            return null;
        }

        return ($hours / 8760) * (100.0 / $wearUsed);
    }

    /** SATA attribute 177 "Wear_Leveling_Count" normalised value (remaining-life %), or null. */
    private function wearLevelingCountRemaining(array $disk): ?float
    {
        foreach ($disk['attributes'] as $attr) {
            if ((int) ($attr['attribute_id'] ?? 0) === 177
                && ($attr['name'] ?? null) === 'Wear_Leveling_Count'
                && is_numeric($attr['value_norm'] ?? null)) {
                return (float) $attr['value_norm'];
            }
        }

        return null;
    }

    /** NVMe remaining-life % derived from the "Percentage Used" SENSOR-MIB sensor, or null. */
    private function nvmePercentageUsedRemaining(string $diskKey): ?float
    {
        $sensor = $this->percentageUsedSensor($diskKey);

        return $sensor && is_numeric($sensor->sensor_current)
            ? 100.0 - max(0.0, min(100.0, (float) $sensor->sensor_current))
            : null;
    }

    /**
     * Drive Writes Per Day: average bytes written per day (since first power-on) divided
     * by the drive's own measured capacity. SATA uses attribute 241 (Total_LBAs_Written);
     * NVMe uses data_units_written (1 unit = 512,000 bytes per NVMe spec). Returns null
     * when capacity, power-on hours, or the write counter are missing/zero.
     */
    public function dwpd(array $disk): ?float
    {
        $hours = $this->powerOnHours($disk);
        if ($hours === null || $hours <= 0) {
            return null;
        }
        $days = $hours / 24;

        if ($this->isNvme($disk)) {
            $unitsWritten = $disk['health']['data_units_written'] ?? null;
            $capacityBytes = $disk['info']['total_nvm_capacity_bytes'] ?? null;
            if (! is_numeric($unitsWritten) || ! is_numeric($capacityBytes) || (float) $capacityBytes <= 0) {
                return null;
            }

            return ((float) $unitsWritten * 512000) / (float) $capacityBytes / $days;
        }

        $bytesWritten = null;
        foreach ($disk['attributes'] as $attr) {
            if ((int) ($attr['attribute_id'] ?? 0) === 241 && is_numeric($attr['value_raw'] ?? null)) {
                $bytesWritten = (float) $attr['value_raw'] * 512;
                break;
            }
        }
        $capacityBytes = $disk['info']['user_capacity_bytes'] ?? null;
        if ($bytesWritten === null || ! is_numeric($capacityBytes) || (float) $capacityBytes <= 0) {
            return null;
        }

        return $bytesWritten / (float) $capacityBytes / $days;
    }

    public function powerOnHours(array $disk): ?int
    {
        $hours = $disk['health']['power_on_hours'] ?? null;

        return is_numeric($hours) ? (int) $hours : null;
    }

    /**
     * Distinct (ATA attribute ID, name) pairs across all disks, for the overview
     * multi-disk attribute graphs. Numbered SMART attributes above the
     * standardized "Big 5" are vendor-defined, so the same numeric ID can mean
     * a different counter on different disk vendors/models — these are kept as
     * separate entries (rather than deduped by ID alone) so they're never
     * graphed together as if they were the same metric. `raw_name` is the
     * exact smart_sata_attributes.name value (e.g. "Raw_Read_Error_Rate"),
     * for exact-match DB filtering; `name` is the prettified display form.
     *
     * @return array<int, array{id:int, name:string, raw_name:string}>
     */
    public function overviewAttributeIds(): array
    {
        $seen = [];
        $entries = [];
        foreach ($this->disks as $disk) {
            foreach ($disk['attributes'] as $attr) {
                $id = (int) ($attr['attribute_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $rawName = trim((string) ($attr['name'] ?? ('Attribute ' . $id)));
                $name = str_replace('_', ' ', $rawName);
                $dedupeKey = $id . '|' . strtolower($rawName);
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;
                $entries[] = ['id' => $id, 'name' => $name, 'raw_name' => $rawName];
            }
        }

        usort($entries, static fn (array $a, array $b): int => $a['id'] <=> $b['id'] ?: strcmp($a['name'], $b['name']));

        return $entries;
    }

    /** Header badge value for the reliability (Big 5) graph: SMART pass + wear. */
    public function reliabilityHeader(array $disk): string
    {
        $parts = [];
        $healthSensor = $this->healthSensor((string) $disk['disk_key']);
        if ($healthSensor !== null && $healthSensor->sensor_current !== null && (int) $healthSensor->sensor_current >= 0) {
            $translation = $healthSensor->currentTranslation();
            $parts[] = $translation ? $translation->state_descr : (string) (int) $healthSensor->sensor_current;
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
     * Which rate unit the attribute's raw RRD series should be graphed in:
     * 'hour' for newly-detected ("Count" in the name) counters, 'second' for
     * the original fixed-list counters, null for GAUGE (no rate semantics).
     */
    public function attributeRateUnit(array $attr): ?string
    {
        if (($attr['rrd_type'] ?? null) !== 'COUNTER') {
            return null;
        }

        $rates = array_filter(
            [$attr['rate_8h'] ?? null, $attr['rate_24h'] ?? null, $attr['rate_168h'] ?? null, $attr['rate_672h'] ?? null],
            static fn ($rate): bool => is_numeric($rate)
        );

        if (empty($rates)) {
            // No rate history yet (e.g. just discovered) — fall back to the legacy name list.
            return in_array($attr['name'] ?? null, self::LEGACY_PERSEC_COUNTER_NAMES, true) ? 'second' : 'hour';
        }

        $avgRate = array_sum($rates) / count($rates);

        return $avgRate > self::RATE_UNIT_PERSEC_THRESHOLD ? 'second' : 'hour';
    }

    /**
     * Human-readable flag lines for an attribute, for the Flags tooltip,
     * derived from the smartmonSataAttrFlags bitmask.
     *
     * @return array<int, string>
     */
    public function attributeFlagLines(array $attr): array
    {
        $lines = [];
        $flags = $attr['flags'] ?? null;
        if ($flags !== null && $flags !== '') {
            $mask = (int) $flags;
            foreach (self::ATTR_FLAG_BITS as $bit => [$letter, $label]) {
                if ($mask & (1 << $bit)) {
                    $lines[] = $letter . ' = ' . $label;
                }
            }
        }

        return $lines;
    }

    /** smartmonSataAttrFlags bit => [letter, label], canonical order P O S R C K. */
    private const ATTR_FLAG_BITS = [
        0 => ['P', 'Pre-fail'],
        1 => ['O', 'Updated online'],
        2 => ['S', 'Speed/performance'],
        3 => ['R', 'Error rate'],
        4 => ['C', 'Event count'],
        5 => ['K', 'Auto-keep'],
    ];

    /**
     * Fixed-width smartmontools-style flag string in canonical order P O S R C K,
     * built from the stored smartmonSataAttrFlags bitmask. Each slot is its
     * letter when the flag is set, or '-' otherwise.
     */
    public function attributeFlagsPositional(array $attr): string
    {
        $mask = (int) ($attr['flags'] ?? 0);
        $out = '';
        foreach (self::ATTR_FLAG_BITS as $bit => [$letter]) {
            $out .= ($mask & (1 << $bit)) ? $letter : '-';
        }

        return $out;
    }

    /**
     * Add thin thousands separators to every long digit run in a raw reading,
     * leaving surrounding text intact: "433684413" => "433 684 413",
     * "0/433684413" => "0/433 684 413", "31 (Min/Max 24/40)" unchanged.
     */
    public function formatRawSpaced(mixed $raw): string
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return '';
        }

        return preg_replace_callback(
            '/\d{4,}/',
            static fn ($m) => preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $m[0]),
            $s
        );
    }

    /** Device-statistics rows hidden in the detailed view (shown elsewhere). */
    public const DEV_STAT_SKIP_ROWS = ['Lifetime Power-On Resets', 'Power-on Hours'];

    /** Device-statistics pages hidden in the detailed view (shown elsewhere). */
    public const DEV_STAT_SKIP_PAGES = [
        'Temperature Statistics', 'Vendor Specific Statistics',
    ];

    // -------------------------------------------------------------------------
    // RRD-derived graph specs (ATA attributes share V2 RRD layout)
    // -------------------------------------------------------------------------

    /** Datasets present in the per-disk attribute RRD (['app','smart',app_id,idx]). */
    private function rrdDatasets(string $diskKey, string $rrdName = 'smart'): array
    {
        $rrdFile = Rrd::name((string) ($this->device['hostname'] ?? ''), ['app', $rrdName, $this->app->app_id, $this->diskIndex($diskKey)]);
        if (! Rrd::checkRrdExists($rrdFile)) {
            return [];
        }

        // listDatasets() reads the file header via a one-shot process; lastUpdate()
        // goes through the persistent pipe, which can truncate its output for files
        // with many DS (this RRD can carry 50+ SATA attribute DS), making a present
        // dataset look missing.
        return Rrd::listDatasets($rrdFile);
    }

    public function hasPowerStateRrd(string $diskKey): bool
    {
        $disk = $this->disks[$diskKey] ?? null;
        $rrdName = $disk !== null && $this->isNvme($disk) ? 'smart_nvme' : 'smart';

        return in_array('power_state', $this->rrdDatasets($diskKey, $rrdName), true);
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
     * @return array<int, array{id:int, name:string, raw_name:string, title:string, header:string, thresh:?float, has_raw:bool, has_norm:bool, rate_unit:?string}>
     */
    public function attributeGraphSpecs(string $diskKey): array
    {
        $disk = $this->disks[$diskKey] ?? null;
        if ($disk === null) {
            return [];
        }

        // Attribute list is sourced from the discovered DB rows, not the RRD's
        // dataset list — so a graph appears as soon as an attribute is known,
        // independent of RRD readback. The poller writes both a raw (id{N}) and
        // normalized (id{N}Normalized) dataset for every attribute, so both
        // lines are graphable.
        $specs = [];
        foreach ($disk['attributes'] as $attr) {
            $id = (int) ($attr['attribute_id'] ?? 0);
            if ($id <= 0 || isset($specs[$id])) {
                continue;
            }

            $rawName = trim((string) ($attr['name'] ?? ('Attribute ' . $id)));
            $name = str_replace('_', ' ', $rawName);
            $rawValue = $attr['value_raw_string'] ?? $attr['value_raw'] ?? null;
            $header = 'Normalized:' . $this->numericString($attr['value_norm'] ?? null)
                . ' Raw:' . $this->numericString($rawValue);

            $specs[$id] = [
                'id'       => $id,
                'name'     => $name,
                'raw_name' => $rawName,
                'title'    => 'ID# ' . $id . ', ' . $name,
                'header'   => $header,
                'thresh'   => is_numeric($attr['value_threshold'] ?? null) ? (float) $attr['value_threshold'] : null,
                'has_raw'  => true,
                'has_norm' => true,
                'rate_unit' => $attr['rate_unit'] ?? null,
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
        // SmartmonAtaSmartAttrStatus (device-reported), plus 4 = rate-of-change
        // threshold breached, synthesized by Common::combineStatus().
        'attr_status' => [
            -1 => '-', 0 => 'Unknown', 1 => 'OK', 2 => 'Failing now', 3 => 'Failed in past', 4 => 'Rate exceeded',
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
        // SmartmonAtaOfflineStatus (offline collection status)
        'offline_status' => [
            0 => 'Never started', 2 => 'Completed', 3 => 'In progress',
            4 => 'Suspended', 5 => 'Aborted', 6 => 'Aborted (fatal)',
        ],
        // SmartmonDevicePowerState
        'power_state' => [
            0 => 'Unknown', 1 => 'Active', 2 => 'Idle (A)', 3 => 'Idle (B)', 4 => 'Idle (C)',
            5 => 'Standby (Y)', 6 => 'Standby (Z)', 7 => 'Sleeping', 8 => 'Standby',
        ],
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

    /** Format interface speed: "6000 / 6000 Mb/s (6.0 / 6.0 Gb/s)" or '-'. */
    public function interfaceSpeed(array $info): string
    {
        $current = $info['if_speed_current_value'] ?? null;
        $max = $info['if_speed_max_value'] ?? null;
        if (! is_numeric($current) && ! is_numeric($max)) {
            return '-';
        }
        $fmt = static function (mixed $mbps): string {
            if (! is_numeric($mbps)) {
                return '?';
            }
            $mbps = (int) $mbps;
            $gb = rtrim(rtrim(number_format($mbps / 1000, 1, '.', ''), '0'), '.');

            return "{$gb} Gb/s";
        };

        if (is_numeric($current) && is_numeric($max) && (int) $current === (int) $max) {
            return $fmt($current);
        }

        return $fmt($current) . ' / ' . $fmt($max);
    }

    /** Format APM: "Enabled, level 254" or "Disabled" or '-'. */
    public function apmLabel(array $info): string
    {
        $enabled = $info['apm_enabled'] ?? null;
        if ($enabled === null) {
            return '-';
        }
        if (! (int) $enabled) {
            return 'Disabled';
        }
        $level = $info['apm_level'] ?? null;

        return 'Enabled' . (is_numeric($level) ? ', level ' . (int) $level : '');
    }

    /** Format Security: "Enabled, not frozen" / "Enabled, frozen" / "Disabled" or '-'. */
    public function securityLabel(array $info): string
    {
        $enabled = $info['security_enabled'] ?? null;
        if ($enabled === null) {
            return '-';
        }
        if (! (int) $enabled) {
            return 'Disabled';
        }
        $frozen = $info['security_frozen'] ?? null;

        return 'Enabled' . (is_numeric($frozen) ? ((int) $frozen ? ', frozen' : ', not frozen') : '');
    }
}
