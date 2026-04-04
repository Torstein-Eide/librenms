<?php

require_once base_path('includes/app-log.php');

use App\Models\Eventlog;
use Illuminate\Support\Facades\Log;
use LibreNMS\Enum\Severity;
use LibreNMS\Exceptions\JsonAppException;
use LibreNMS\Polling\Modules\BtrfsPayloadParser;
use LibreNMS\Polling\Modules\BtrfsRrdWriter;
use LibreNMS\Polling\Modules\BtrfsSensorSync;
use LibreNMS\Polling\Modules\BtrfsStatusAggregator;
use LibreNMS\Polling\Modules\BtrfsStatusMapper;

// =============================================================================
// Discovery
// =============================================================================

/**
 * Handles discovery tasks for btrfs:
 * - Detect filesystem add/remove events and log to eventlog
 * - Detect device add/remove events per filesystem and log to eventlog
 * - Clean up obsolete sensors for filesystems/devices that no longer exist
 * - Cache filesystem/device structure for polling loop optimization
 *
 * Discovery is run:
 * - On first poll (when no previous state exists)
 * - When filesystem or device count increases
 * - Periodically (every N polls based on interval)
 *
 * All discovery state is stored in app->data['discovery']:
 *   - schema_version: Structure version number
 *   - poll_count: Polls since last discovery ran (reset to 0 when discovery runs)
 *   - last_run: Unix timestamp of last discovery
 *   - counters: Current counts from agent payload
 *   - filesystems: Cached filesystem structure for change detection
 */
class BtrfsDiscovery
{
    private const SCHEMA_VERSION = 1;

    private bool $discoveryRan = false;

    public function __construct(
        private readonly BtrfsPayloadParser $parser,
        private readonly BtrfsSensorSync $sensorSync,
    ) {
    }

    /**
     * Execute the full discovery scan. Only called when the caller has already
     * determined that discovery is due (skip check done externally).
     */
    public function discover(array $device, App\Models\Application $app, array $tables, array $fsList, array $discovery, array $currentCounters): void
    {
        App_log::section('DISCOVERY');
        App_log::info('Running discovery', ['fs_count' => $currentCounters['filesystems']]);

        $previousFilesystems = $discovery['filesystems'] ?? [];
        $currentFsByMountpoint = $this->parser->getFilesystemsByMountpoint($tables);

        // Snapshot the current filesystem+device structure for change detection
        $currentFilesystems = $this->buildFilesystemState($currentFsByMountpoint, $tables, $fsList);

        // Reset the discovery buffer so sensors from other modules do not bleed into sync().
        $this->sensorSync->resetDiscoveryBuffer();

        // Detect and log add/remove events, then sync sensors for the current topology.
        $this->discoverFilesystems($device, array_keys($previousFilesystems), array_keys($currentFilesystems));
        $this->discoverDevices($device, $previousFilesystems, $currentFilesystems);
        $this->discoverExpectedSensors($device, $currentFilesystems);

        $discovery['last_run'] = time();
        $discovery['counters'] = $currentCounters;
        $discovery['filesystems'] = $currentFilesystems;
        $this->saveDiscoveryData($app, $discovery);

        $this->discoveryRan = true;
        App_log::info('Discovery completed', ['fs_count' => count($currentFilesystems)]);
    }

    /**
     * Returns true if discovery ran during this poll cycle.
     */
    public function wasRun(): bool
    {
        return $this->discoveryRan;
    }

    /**
     * Get current counters from the discovery data (for polling loop).
     * Returns counters from the NEWLY COMPUTED discovery state in app->data.
     */
    public function getCurrentCounters(App\Models\Application $app): array
    {
        return $app->data['discovery']['counters'] ?? [
            'filesystems' => 0,
            'devices' => 0,
            'backing_devices' => 0,
        ];
    }

    /**
     * Check if a filesystem matches the cached discovery state.
     *
     * Compares rrd_key and device structure to determine if the filesystem
     * configuration is unchanged from the last discovery.
     *
     * @param  array  $devices  Current device stats keyed by device path
     * @param  array  $cachedFilesystems  Cached filesystem data from discovery (keyed by UUID)
     */
    public function filesystemMatchesCache(string $fsUuid, string $fsRrdId, array $devices, array $cachedFilesystems): bool
    {
        // No cached entry means this filesystem was not present at last discovery
        $cached = $cachedFilesystems[$fsUuid] ?? null;

        if ($cached === null) {
            return false;
        }

        // If the RRD key changed the filesystem was replaced (e.g. reformatted)
        if (($cached['rrd_key'] ?? '') !== $fsRrdId) {
            return false;
        }

        // Build devid→path map from live stats to compare against the cached map
        $currentDevIds = [];
        foreach ($devices as $devPath => $devStats) {
            $devId = $devStats['devid'] ?? null;
            if ($devId !== null) {
                $currentDevIds[(string) $devId] = $devPath;
            }
        }

        // Any device addition, removal, or path change counts as a structural change
        return $currentDevIds === ($cached['devices'] ?? []);
    }

    /**
     * Load previous discovery state and migrate schema if needed.
     */
    public function initDiscoveryState(App\Models\Application $app): array
    {
        // Previous discovery state is stored in the LibreNMS DB via app->data, not in the agent payload
        $previous = $app->data['discovery'] ?? null;

        // First poll: seed with an empty state
        $discovery = $previous ?? [
            'schema_version' => self::SCHEMA_VERSION,
            'last_run' => 0,
            'counters' => ['filesystems' => 0, 'devices' => 0, 'backing_devices' => 0],
            'filesystems' => [],
        ];

        // Upgrade from an older schema before any other reads
        if (($discovery['schema_version'] ?? 0) < self::SCHEMA_VERSION) {
            $discovery = $this->migrateDiscoverySchema($discovery);
        }

        return $discovery;
    }

    /**
     * Persist discovery state into app->data['discovery'].
     */
    public function saveDiscoveryData(App\Models\Application $app, array $discovery): void
    {
        $data = $app->data ?? [];
        $data['discovery'] = $discovery;
        $app->data = $data;
    }

    /**
     * Upgrade discovery state from an older schema version to the current one.
     * Preserves poll counts and existing filesystem data; resets counters.
     */
    private function migrateDiscoverySchema(array $discovery): array
    {
        App_log::info('Migrating discovery schema', [
            'from' => $discovery['schema_version'] ?? 0,
            'to' => self::SCHEMA_VERSION,
        ]);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'last_run' => $discovery['last_run'] ?? time(),
            'counters' => ['filesystems' => 0, 'devices' => 0, 'backing_devices' => 0],
            'filesystems' => $discovery['filesystems'] ?? [],
        ];
    }

    /**
     * Returns true if discovery should be skipped this poll.
     * Forces discovery (returns false) on first poll, when filesystem or device
     * count has increased, or when the interval has elapsed.
     *
     * @param  array  $currentCounters  Counts from the agent payload (filesystems, devices, backing_devices)
     */
    public function shouldSkip(array $currentCounters, array $discovery, int $pollCount, int $interval): bool
    {
        // true = skip discovery, false = run discovery
        if (empty($discovery['filesystems'])) {
            App_log::info('No previous discovery data - first poll, discovery will run');

            return false;  // force: first poll
        }

        if ($currentCounters['filesystems'] > ($discovery['counters']['filesystems'] ?? 0)) {
            App_log::info('Filesystem count increased - discovery will run', [
                'current' => $currentCounters['filesystems'],
                'previous' => $discovery['counters']['filesystems'] ?? 0,
            ]);

            return false;  // force: new filesystem added
        }

        if ($currentCounters['devices'] > ($discovery['counters']['devices'] ?? 0)) {
            App_log::info('Device count increased - discovery will run', [
                'current' => $currentCounters['devices'],
                'previous' => $discovery['counters']['devices'] ?? 0,
            ]);

            return false;  // force: new device added
        }

        // poll_count is reset to 0 each time discovery runs, so it means
        // "polls since last discovery". Skip until we reach the interval.
        if ($interval > 0 && $pollCount < $interval) {
            return true;   // timer not reached
        }

        return false;      // interval reached, run
    }

    /**
     * Extract current filesystem/device/backing-device counts from the agent payload.
     * Falls back to counting table rows when explicit counters are absent.
     */
    public function extractCounters(array $agentOutput, array $tables, array $fsList): array
    {
        return [
            'filesystems' => (int) ($agentOutput['counters']['filesystems'] ?? 0),
            'devices' => (int) ($agentOutput['counters']['devices'] ?? 0),
            'backing_devices' => (int) ($agentOutput['counters']['backing_devices'] ?? 0),
        ];
    }

    /**
     * Build filesystem state structure with all info needed by polling loop.
     *
     * @param  array  $fsByMountpoint  Filesystem UUIDs keyed by mountpoint
     * @return array Filesystem state keyed by UUID
     */
    private function buildFilesystemState(array $fsByMountpoint, array $tables, array $fsList): array
    {
        $state = [];
        App_log::section('Discovery: FS state');
        foreach ($fsByMountpoint as $fsMountpoint => $fsUuid) {
            $fsRow = $fsList[$fsUuid] ?? [];
            if (! is_array($fsRow)) {
                continue;
            }

            // RRD key is the first 8 chars of the UUID, normalised to a safe identifier
            $rrdKey = $this->parser->normalizeId(substr($fsUuid, 0, 8));
            if ($rrdKey === '') {
                App_log::warning('Skipping filesystem with invalid rrd_key', [
                    'fs_mountpoint' => $fsMountpoint,
                    'fs_uuid' => $fsUuid,
                ]);
                continue;
            }

            // Build a devid→path map so the polling loop can detect device changes
            $fsInfo = $this->parser->getFsInfo($tables, $fsUuid);
            $devices = $this->parser->extractShowDevices($tables, $fsUuid);
            $usageDevices = $this->parser->extractDeviceUsage($tables, $fsUuid);
            $deviceMap = [];
            $deviceMetadata = [];
            $deviceCount = 0;

            $devPathToBacking = [];
            foreach ($tables['backing_devices'] ?? [] as $backingPath => $backingInfo) {
                if (is_array($backingInfo)) {
                    $devPathToBacking[$backingPath] = $backingInfo;
                }
            }

            foreach ($devices as $devPath => $devId) {
                $deviceMap[(string) $devId] = $devPath;
                $deviceCount++;

                $usageStats = $usageDevices[$devPath] ?? [];
                $backingPath = $usageStats['backing_device_path'] ?? null;
                $sysBlock = $tables['devices'][$devPath] ?? null;
                $deviceMetadata[(string) $devId] = [
                    'backing' => $backingPath !== null ? ($devPathToBacking[$backingPath] ?? null) : null,
                    'backing_path' => $backingPath,
                    'sys_block' => $sysBlock,
                ];
            }

            $state[$fsUuid] = [
                'mountpoint' => $fsMountpoint,
                'label' => $fsInfo['label'] ?? '',
                'rrd_key' => $rrdKey,
                'total_devices' => (int) ($fsRow['total_devices'] ?? 0),
                'device_count' => $deviceCount,
                'devices' => $deviceMap,
                'device_metadata' => $deviceMetadata,
            ];
        }

        App_log::info('Filesystem state built', [
            'count' => count($state),
            'uuids' => array_keys($state),
        ]);

        return $state;
    }

    /**
     * Detect and log filesystem add/remove events.
     */
    private function discoverFilesystems(array $device, array $previousFsUuids, array $currentFsUuids): void
    {
        App_log::section('discovery FS');
        // Set-difference gives us the added and removed UUIDs in one pass each
        $added = array_diff($currentFsUuids, $previousFsUuids);
        $removed = array_diff($previousFsUuids, $currentFsUuids);

        foreach ($added as $fsUuid) {
            App_log::info('Filesystem discovered', ['fs_uuid' => $fsUuid]);
            Eventlog::log("BTRFS Filesystem added: $fsUuid", $device['device_id'], 'application');
        }

        foreach ($removed as $fsUuid) {
            App_log::info('Filesystem removed', ['fs_uuid' => $fsUuid]);
            Eventlog::log("BTRFS Filesystem removed: $fsUuid", $device['device_id'], 'application');
        }

        if (empty($added) && empty($removed)) {
            App_log::warning('No filesystem changes detected');
        }
    }

    /**
     * Detect and log device add/remove events per filesystem.
     */
    private function discoverDevices(array $device, array $previousFilesystems, array $currentFilesystems): void
    {
        App_log::section('discovery devices');
        foreach ($currentFilesystems as $fsUuid => $currentData) {
            // Fall back to empty device list for new filesystems not in previous state
            $previousData = $previousFilesystems[$fsUuid] ?? ['devices' => []];
            $previousDevices = $previousData['devices'] ?? [];
            $currentDevices = $currentData['devices'] ?? [];

            // Compare by devid so a device that moved to a different path is treated as removed+added
            $addedDevs = array_diff(array_keys($currentDevices), array_keys($previousDevices));
            $removedDevs = array_diff(array_keys($previousDevices), array_keys($currentDevices));

            foreach ($addedDevs as $devId) {
                $devPath = $currentDevices[$devId] ?? 'unknown';
                App_log::info('Device discovered', ['fs_uuid' => $fsUuid, 'dev_id' => $devId, 'path' => $devPath]);
                Eventlog::log("BTRFS Device added: $devPath on $fsUuid", $device['device_id'], 'application');
            }

            foreach ($removedDevs as $devId) {
                $devPath = $previousDevices[$devId] ?? 'unknown';
                App_log::info('Device removed', ['fs_uuid' => $fsUuid, 'dev_id' => $devId, 'path' => $devPath]);
                Eventlog::log("BTRFS Device removed: $devPath on $fsUuid", $device['device_id'], 'application');
            }
        }
    }

    /**
     * Discover all sensors expected for the current filesystem/device topology.
     *
     * Buffers every sensor into the app('sensor-discovery') singleton using placeholder
     * values; the polling loop overwrites sensor_current with the real value each poll.
     * After all sensors are buffered, syncDiscoveredSensors() creates new sensors,
     * updates existing ones, and deletes any whose indexes are no longer present.
     */
    private function discoverExpectedSensors(array $device, array $currentFilesystems): void
    {
        App_log::section('Discovery: SENSORS');

        $sensorCount = 0;

        foreach ($currentFilesystems as $fsData) {
            $rrdId = $fsData['rrd_key'];
            if ($rrdId === '') {
                continue;
            }

            $label = $fsData['label'] ?? '';
            $mountpoint = $fsData['mountpoint'] ?? '';
            $displayName = $label !== '' ? $label : ($mountpoint === '/' ? 'root' : $mountpoint);

            // Filesystem-level state sensors (placeholder value -1; polling overwrites each poll).
            $this->sensorSync->discoverStateSensor($device, "$rrdId.io", BtrfsSensorSync::STATE_SENSOR_IO, "$displayName IO", BtrfsStatusMapper::STATUS_NA, 'btrfs filesystems');
            $this->sensorSync->discoverStateSensor($device, "$rrdId.scrub", BtrfsSensorSync::STATE_SENSOR_SCRUB, "$displayName Scrub", BtrfsStatusMapper::STATUS_NA, 'btrfs filesystems');
            $this->sensorSync->discoverStateSensor($device, "$rrdId.scrub_ops", BtrfsSensorSync::STATE_SENSOR_SCRUB_OPS, "$displayName Scrub Ops", BtrfsStatusMapper::STATUS_NA, 'btrfs filesystems');
            $this->sensorSync->discoverStateSensor($device, "$rrdId.balance", BtrfsSensorSync::STATE_SENSOR_BALANCE, "$displayName Balance", BtrfsStatusMapper::STATUS_NA, 'btrfs filesystems');

            // Filesystem-level IO error count sensor.
            $this->sensorSync->discoverCountSensor($device, $rrdId, "$displayName IO Errors", 0, 'btrfs filesystem errors');
            $sensorCount += 5;

            // Per-device sensors.
            foreach ($fsData['devices'] as $devId => $devPath) {
                $this->sensorSync->discoverStateSensor($device, "$rrdId.dev.$devId.io", BtrfsSensorSync::STATE_SENSOR_IO, "$displayName $devPath IO", BtrfsStatusMapper::STATUS_NA, 'btrfs devices');
                $this->sensorSync->discoverStateSensor($device, "$rrdId.dev.$devId.scrub", BtrfsSensorSync::STATE_SENSOR_SCRUB, "$displayName $devPath Scrub", BtrfsStatusMapper::STATUS_NA, 'btrfs devices');
                $this->sensorSync->discoverCountSensor($device, "$rrdId.dev.$devId", "$displayName $devPath IO Errors", 0, 'btrfs device errors');
                $sensorCount += 3;
            }
        }

        App_log::info('Sensors discovered', ['count' => $sensorCount]);

        // Sync: creates new sensors, updates existing, deletes sensors not in the buffer.
        $this->sensorSync->syncDiscoveredSensors();
    }
}

// =============================================================================
// Poller
// =============================================================================

class BtrfsPoller
{
    // =============================================================================
    // Constants
    // =============================================================================

    // Minimum progress percentage at which a null scrub status is treated as finished (RAID5/6 fallback)
    private const SCRUB_ASSUMED_DONE_THRESHOLD = 90;

    // Run discovery every N polls (0 = disabled, discovery only on topology changes)
    private const DISCOVERY_INTERVAL = 3;

    // Maximum length for filesystem labels stored in app->data
    private const MAX_LABEL_LENGTH = 256;

    // Maximum length for the overall status text stored in app->data
    private const MAX_STATUS_TEXT_LENGTH = 64;

    // =============================================================================
    // Instance Properties
    // All properties are set once during initialize() and read throughout polling.
    // =============================================================================

    // Device array passed to poll(); set once in poll().
    private array $device = [];

    // The LibreNMS Application model for this btrfs app; set once in poll().
    private App\Models\Application $app;

    // Parsed JSON payload from the btrfs agent; set in fetchPayload().
    private array $appPayload = [];

    // Accumulator: the app->data structure being built for this poll. Persisted to
    // the DB at the end of polling via $this->app->data = $this->newData.
    private array $newData = [];

    // Working state set per-filesystem during processFilesystem(); reset at the start
    // of each filesystem loop. Holds fs context ($working['fs']) and running metrics
    // accumulators ($working['acc']) shared across processFilesystem() and processDevices().
    private array $working = [];

    // =============================================================================
    // Service Objects
    // Instantiated once in initialize(); used throughout polling.
    // =============================================================================

    private BtrfsPayloadParser $parser;
    private BtrfsStatusMapper $mapper;
    private BtrfsSensorSync $sensorSync;
    private BtrfsRrdWriter $rrdWriter;
    private BtrfsStatusAggregator $agg;
    private BtrfsDiscovery $discovery;

    /**
     * Main entry point: fetch payload, run discovery, process all filesystems, persist app data.
     */
    public function poll(array $device, App\Models\Application $app): void
    {
        // Store device and app references for use in all private methods.
        $this->device = $device;
        $this->app = $app;

        // Initialise logging context for this poll cycle.
        App_log::setApp('btrfs');
        App_log::setHostname($device['hostname'] ?? 'unknown');
        App_log::setLevel('DEBUG');

        // Reset RRD call counters so each poll starts with a clean baseline.
        BtrfsRrdWriter::resetCallCounters();

        // Instantiate all service objects (parser, mapper, sensor sync, RRD writer, aggregator, discovery).
        $this->initialize();

        // Attempt to fetch and parse the JSON payload from the btrfs agent.
        // Returns the btrfs agent protocol version on success, or false on any failure.
        // Initialise reference parameters before the call — PHP requires the caller's variable
        // to already be an array when passed by reference.
        $agentOutput = [];
        $oldData = [];
        $fetchResult = $this->fetchPayload($agentOutput, $oldData);
        if ($fetchResult === false) {
            return;
        }

        // Seed the new app->data structure, preserving the existing discovery block so it is
        // carried forward even if discovery is skipped this poll.
        $this->newData = [
            'discovery' => $this->app->data['discovery'] ?? null,
            'filesystems' => [],
        ];

        // Run discovery if due (detects topology changes and syncs sensor rows to DB).
        $this->runDiscovery();

        // Reset per-poll accumulators and iterate over every filesystem discovered.
        $this->working = ['acc' => ['metrics' => []]];
        $this->processFilesystems();

        // Compute overall app status, assemble the complete app->data, write RRD, and persist.
        $this->persistAppData($fetchResult);

        // Log RRD call statistics for this poll cycle.
        BtrfsRrdWriter::printCallCounters();
    }

    /**
     * Instantiate all service objects used during polling.
     */
    private function initialize(): void
    {
        $this->parser = new BtrfsPayloadParser();
        $this->mapper = new BtrfsStatusMapper();
        $this->sensorSync = new BtrfsSensorSync();
        $this->rrdWriter = new BtrfsRrdWriter();
        $this->agg = new BtrfsStatusAggregator();
        $this->discovery = new BtrfsDiscovery($this->parser, $this->sensorSync);
    }

    /**
     * Fetch and validate the agent JSON payload.
     *
     * Returns the btrfs agent protocol version (int >= 1) on success, or false if the
     * payload is missing, unparseable, or reports version 0 with no filesystem data.
     *
     * @param  array &$agentOutput  Populated with the 'data' section of the payload.
     * @param  array &$oldData      Populated with the previous app->data for reference.
     * @return int|false           Agent protocol version on success, false on failure.
     */
    private function fetchPayload(array &$agentOutput, array &$oldData): int|false
    {
        // Initialise output parameters so they are always defined on failure paths.
        $agentOutput = [];
        $oldData = [];

        // Attempt to retrieve and parse the JSON payload from the btrfs agent.
        try {
            $this->appPayload = json_app_get($this->device, 'btrfs', 1);

            // json_app_get() returns null when no data is available (no exception thrown).
            if ($this->appPayload === null) {
                throw new JsonAppException('No data received from agent', -1);
            }

            $agentOutput = $this->appPayload['data'] ?? [];
            $oldData = $this->app->data ?? [];
        } catch (JsonAppException $e) {
            // Agent returned no data or the payload was malformed; mark app as unavailable.
            echo PHP_EOL . 'btrfs:' . $e->getCode() . ':' . $e->getMessage() . PHP_EOL;
            update_application($this->app, $e->getCode() . ':' . $e->getMessage(), []);

            return false;
        }

        // Determine the btrfs agent protocol version from the payload.
        $tables = $agentOutput['tables'] ?? [];
        $fsList = $tables['filesystems'] ?? [];
        $btrfsDevVersion = (int) ($agentOutput['version'] ?? $this->appPayload['version'] ?? 0);

        // Treat a version-0 payload with filesystem rows as a valid protocol v1 agent.
        if ($btrfsDevVersion < 1 && count($fsList) > 0) {
            $btrfsDevVersion = 1;
        }

        // Reject payloads that report no version and contain no filesystem data.
        if ($btrfsDevVersion < 1) {
            $this->sensorSync->deleteAllStateAndCountSensors($this->device);
            $this->app->data = [];
            update_application($this->app, 'Unsupported btrfs agent payload version', ['status_code' => BtrfsStatusMapper::STATUS_NA]);

            return false;
        }

        return $btrfsDevVersion;
    }

    /**
     * Decide whether discovery is due and run it if so.
     *
     * Discovery runs when:
     * - This is the first poll (no cached discovery state)
     * - The filesystem or device count in the payload has increased
     * - The periodic interval (DISCOVERY_INTERVAL polls) has elapsed
     *
     * Discovery syncs sensor rows to the DB (creates new, updates existing, deletes removed).
     * The polling loop then updates sensor_current and writes RRDs every poll regardless.
     */
    private function runDiscovery(): void
    {
        $tables = $this->appPayload['data']['tables'] ?? [];
        $fsList = $tables['filesystems'] ?? [];

        // Load or seed the discovery state and bump the poll-count (polls since last discovery).
        $discovery = $this->discovery->initDiscoveryState($this->app);
        $discovery['poll_count'] = ($discovery['poll_count'] ?? 0) + 1;
        $pollCount = $discovery['poll_count'];

        // Extract authoritative filesystem/device/backing-device counts from the agent payload.
        $currentCounters = $this->discovery->extractCounters($this->appPayload['data'], $tables, $fsList);

        App_log::debug('Discovery state loaded', [
            'current_fs_count' => $currentCounters['filesystems'],
            'previous_fs_count' => count($discovery['filesystems'] ?? []),
        ]);

        // Decide whether to run discovery this poll, or skip and persist the incremented poll_count.
        if ($this->discovery->shouldSkip($currentCounters, $discovery, $pollCount, self::DISCOVERY_INTERVAL)) {
            App_log::info('Discovery skipped - not yet due', [
                'polls_since_last_discovery' => $pollCount,
                'interval' => self::DISCOVERY_INTERVAL,
            ]);
            // Carry the bumped count forward even when skipping so the interval timer advances.
            $this->discovery->saveDiscoveryData($this->app, $discovery);
        } else {
            // Reset poll_count before discovery so it persists as 0 (polls since last discovery ran).
            $discovery['poll_count'] = 0;
            $this->discovery->discover($this->device, $this->app, $tables, $fsList, $discovery, $currentCounters);
        }

        // Carry the discovery block into the new app->data (preserved even when discovery was skipped).
        $this->newData['discovery'] = $this->app->data['discovery'] ?? $discovery;

        if ($this->discovery->wasRun()) {
            App_log::info('Discovery ran this poll');
        }
    }

    /**
     * Iterate over all filesystems in the discovery cache and process each one.
     */
    private function processFilesystems(): void
    {
        $cachedFilesystems = $this->app->data['discovery']['filesystems'] ?? [];
        $this->working['fs'] = [];

        foreach (array_keys($cachedFilesystems) as $fsUuid) {
            $this->processFilesystem($fsUuid);
        }
    }

    /**
     * Process a single filesystem: extract space/scrub/balance data, write RRDs,
     * upsert sensors (when structure has changed), process per-device data, and
     * build the filesystem entry for app->data persistence.
     */
    private function processFilesystem(string $fsUuid): void
    {
        // Load tables from the agent payload.
        $tables = $this->appPayload['data']['tables'] ?? [];
        $fsList = $tables['filesystems'] ?? [];
        if (! isset($fsList[$fsUuid])) {
            return;
        }

        // Seed per-filesystem working context from the cached discovery state.
        $cachedFs = $this->app->data['discovery']['filesystems'][$fsUuid] ?? [];
        $this->working['fs'] = [
            'uuid' => $fsUuid,
            'rrd_id' => $cachedFs['rrd_key'] ?? '',
            'name' => $cachedFs['mountpoint'] ?? '',
        ];

        // Derive the human-readable display name (label, or mountpoint, or 'root' for '/').
        $fsInfo = $this->parser->getFsInfo($tables, $fsUuid);
        $fsLabel = self::truncate($fsInfo['label'] ?? '', self::MAX_LABEL_LENGTH);
        $this->working['fs']['display_name'] = $fsLabel !== ''
            ? $fsLabel
            : ($this->working['fs']['name'] === '/' ? 'root' : $this->working['fs']['name']);

        // Extract space usage fields from the overall normalisation block and build the RRD field list.
        $overall = $this->parser->normalizeOverall($tables, $fsUuid);
        $fields = [];
        foreach ($this->rrdWriter->fsSpaceDatasets as $ds => $key) {
            $fields[$ds] = $overall[$key] ?? null;
        }

        // Build the metric prefix used for update_application() output (e.g. "fs_84294f69_device_size").
        $fsMetricPrefix = 'fs_' . $this->working['fs']['rrd_id'] . '_';

        // Extract all per-device data from the payload tables.
        $devices = $this->parser->extractDeviceStats($tables, $fsUuid);
        $usageDevices = $this->parser->extractDeviceUsage($tables, $fsUuid);
        $usageTypeTotals = $this->parser->extractUsageTypeTotals($tables, $fsUuid);
        $rawScrubDevices = $this->parser->extractScrubDevices($tables, $fsUuid);

        // Retrieve the previous scrub state so this poll can detect counter/session resets.
        $previousScrubState = $this->app->data['filesystems'][$fsUuid]['scrub']['status'] ?? [];
        $fsPreviousProgress = is_numeric($previousScrubState['progress'] ?? null) ? (float) $previousScrubState['progress'] : null;
        $this->working['fs']['previous_progress'] = $fsPreviousProgress;

        // Normalise the raw scrub status from the agent, applying RAID5/6 fallback logic.
        $scrubNormalized = $this->normalizeStatus(
            $this->parser->extractScrubStatus($tables, $fsUuid),
            $fsPreviousProgress
        );
        $this->working['fs']['scrub_status'] = $scrubNormalized['fs_scrub_status'];
        $scrubBytesScrubbed = $scrubNormalized['bytes_scrubbed'];
        $scrubStarted = $scrubNormalized['started'];
        $scrubProgress = $scrubNormalized['progress'];

        // Detect counter/session resets before writing the scrub bytes RRD.
        $scrubBytesForRrd = $this->bytesForRrd(
            $scrubBytesScrubbed,
            $scrubStarted,
            $this->app->data['filesystems'][$fsUuid]['scrub']['status'] ?? []
        );

        // Persist the current scrub state (bytes, started, progress) so the next poll can detect resets.
        $this->newData['filesystems'][$fsUuid]['scrub']['status'] = [
            'bytes' => $scrubBytesScrubbed,
            'scrub_started' => $scrubStarted,
            'progress' => $scrubProgress,
        ];

        // Extract and normalise the balance status for this filesystem.
        $fsBalanceStatus = $this->parser->extractBalanceStatus($tables, $fsUuid);
        $balanceStatusCode = $this->mapper->getBalanceStatusCodeFromFlat($fsBalanceStatus);

        // Detect whether any device is currently missing from the filesystem.
        $this->working['fs']['has_missing'] = $this->parser->filesystemHasMissingDevice($tables, $fsUuid);

        // Process all devices: write per-device RRDs, build table rows, accumulate error counts,
        // log new errors, and upsert per-device sensors. Returns aggregated results for this fs.
        $devResult = $this->processDevices();

        // Unpack aggregated results from processDevices().
        $ioStatusCode = $devResult['io_status_code'];
        $scrubStatusCode = $devResult['scrub_status_code'];
        $scrubIsRunning = $devResult['scrub_is_running'];
        $scrubOperation = (int) ($this->working['fs']['scrub_status']['ops_status'] ?? -1);
        $fsScrubHealth = $devResult['fs_scrub_health'];

        // Sum device-level usage into filesystem totals and add to the RRD fields and metrics.
        $usageTotals = $this->rrdWriter->sumUsageTotals($usageDevices);
        foreach ($usageTotals as $k => $v) {
            $fields[$k] = $v;
            $this->working['acc']['metrics'][$fsMetricPrefix . $k] = $v;
        }

        // Add the scrub bytes (rate) to the fields and metrics.
        $fields[BtrfsRrdWriter::DS_SCRUB_BYTES] = $scrubBytesForRrd;
        $this->working['acc']['metrics'][$fsMetricPrefix . BtrfsRrdWriter::DS_SCRUB_BYTES] = $scrubBytesForRrd;

        // Write one RRD per RAID profile type (e.g. data_single, metadata_raid1) on this filesystem.
        foreach ($usageTypeTotals as $typeKey => $typeValue) {
            $typeId = $this->parser->normalizeId((string) $typeKey);
            $this->rrdWriter->writeTypeRrd($this->device, 'btrfs', $this->app->app_id, $this->working['fs']['rrd_id'], $typeId, $typeValue);
            $this->working['acc']['metrics'][$fsMetricPrefix . 'type_' . $typeId] = $typeValue;
        }

        // Append IO/scrub/balance status codes to the RRD fields and metrics accumulator.
        $fields[BtrfsRrdWriter::DS_IO_STATUS] = $ioStatusCode;
        $fields[BtrfsRrdWriter::DS_SCRUB_STATUS] = $scrubStatusCode;
        $fields[BtrfsRrdWriter::DS_SCRUB_OPERATION] = $scrubOperation;
        $fields[BtrfsRrdWriter::DS_SCRUB_HEALTH] = $fsScrubHealth;
        $fields[BtrfsRrdWriter::DS_BALANCE_STATUS] = $balanceStatusCode;
        $this->working['acc']['metrics'][$fsMetricPrefix . BtrfsRrdWriter::DS_IO_STATUS] = $ioStatusCode;
        $this->working['acc']['metrics'][$fsMetricPrefix . BtrfsRrdWriter::DS_SCRUB_STATUS] = $scrubStatusCode;
        $this->working['acc']['metrics'][$fsMetricPrefix . BtrfsRrdWriter::DS_SCRUB_OPERATION] = $scrubOperation;
        $this->working['acc']['metrics'][$fsMetricPrefix . BtrfsRrdWriter::DS_SCRUB_HEALTH] = $fsScrubHealth;
        $this->working['acc']['metrics'][$fsMetricPrefix . BtrfsRrdWriter::DS_BALANCE_STATUS] = $balanceStatusCode;

        // Feed the aggregator so persistAppData() can derive the overall application status.
        $this->agg->addIoStatus($ioStatusCode);
        $this->agg->addScrubStatus($scrubStatusCode);
        $this->agg->addBalanceStatus($balanceStatusCode);

        // Update sensor_current in the DB and write RRDs for all filesystem-level state sensors.
        // Discovery (run periodically) already created/synced the sensor rows; here we just
        // refresh the values every poll so graphs and alert evaluations stay current.
        $this->sensorSync->writeStateSensorRrd($this->device, $this->working['fs']['rrd_id'] . '.io', BtrfsSensorSync::STATE_SENSOR_IO, $this->working['fs']['display_name'] . ' IO', $ioStatusCode);
        $this->sensorSync->writeStateSensorRrd($this->device, $this->working['fs']['rrd_id'] . '.scrub', BtrfsSensorSync::STATE_SENSOR_SCRUB, $this->working['fs']['display_name'] . ' Scrub', $scrubStatusCode);
        $this->sensorSync->writeStateSensorRrd($this->device, $this->working['fs']['rrd_id'] . '.scrub_ops', BtrfsSensorSync::STATE_SENSOR_SCRUB_OPS, $this->working['fs']['display_name'] . ' Scrub Ops', $scrubOperation);
        $this->sensorSync->writeStateSensorRrd($this->device, $this->working['fs']['rrd_id'] . '.balance', BtrfsSensorSync::STATE_SENSOR_BALANCE, $this->working['fs']['display_name'] . ' Balance', $balanceStatusCode);

        // Write the consolidated filesystem-level RRD (all space/status fields in one file).
        $this->rrdWriter->writeFsRrd($this->device, 'btrfs', $this->app->app_id, $this->working['fs']['rrd_id'], $fields);

        // Mirror RRD fields into the update_application() metrics map.
        foreach ($fields as $field => $value) {
            $this->working['acc']['metrics'][$fsMetricPrefix . $field] = $value;
        }

        // Merge per-device metrics into the filesystem metrics map.
        foreach ($devResult['metrics'] as $k => $v) {
            $this->working['acc']['metrics'][$fsMetricPrefix . $k] = $v;
        }

        // Build the per-device scrub data block for persistence in app->data.
        $scrubDevicesData = [];
        foreach ($rawScrubDevices as $devId => $devScrubData) {
            if (! is_array($devScrubData)) {
                continue;
            }
            $processed = $this->parser->processSingleDeviceScrub($devScrubData, $fsPreviousProgress);
            $scrubDevicesData[$devId] = array_merge($devScrubData, [
                'ops_status' => $processed['ops'],
                'health' => $processed['health'],
            ]);
        }

        // Assemble and persist the complete per-filesystem poll data block.
        $this->newData['filesystems'][$fsUuid] = [
            'fs_bytes_used' => $fsInfo['fs_bytes_used'],
            'table' => $fields,
            'device_tables' => $devResult['device_tables'],
            'profiles' => $usageTypeTotals,
            'scrub' => [
                'status' => $this->working['fs']['scrub_status'],
                'devices' => $scrubDevicesData,
                'operation' => $scrubOperation,
                'health' => $fsScrubHealth,
            ],
            'balance' => [
                'status' => $fsBalanceStatus,
            ],
        ];

        // Update the filesystem-level IO error count sensor.
        $this->sensorSync->updateCountSensorValue(
            $this->device,
            $this->working['fs']['rrd_id'],
            $this->working['fs']['display_name'] . ' IO Errors',
            $devResult['fs_io_errors_sum']
        );
    }

    /**
     * Process all devices for a filesystem.
     *
     * For each device discovered in the payload, this method:
     * - Extracts and normalises device stats, usage, and scrub data
     * - Writes per-device RRDs (device file and per-RAID-profile files)
     * - Builds the device table row used by the UI
     * - Accumulates IO error counts for the filesystem
     * - Logs new errors to the eventlog
     * - Updates IO and scrub state sensor values (sensor rows created during discovery)
     * - Derives filesystem-level IO and scrub status codes
     *
     * @return array  Aggregated results: device_tables, per-device metrics, fs error sum, and status codes.
     */
    private function processDevices(): array
    {
        $fsUuid = $this->working['fs']['uuid'];
        $tables = $this->appPayload['data']['tables'] ?? [];

        // Extract all per-device data blocks from the payload tables.
        $devices = $this->parser->extractDeviceStats($tables, $fsUuid);
        $rawScrubDevices = $this->parser->extractScrubDevices($tables, $fsUuid);
        $usageDevices = $this->parser->extractDeviceUsage($tables, $fsUuid);
        $showDevicesByPath = $this->parser->extractShowDevices($tables, $fsUuid);

        // Union all device paths across all three sources so devices that only appear in scrub
        // or usage (not in stats) are still included and processed.
        $allDevPaths = array_unique(array_merge(
            array_keys($devices),
            array_keys($usageDevices),
            array_keys($showDevicesByPath)
        ));

        // Output accumulators.
        $deviceTables = [];
        $metrics = [];
        $fsIoErrorsSum = 0.0;

        // Track scrub state across all devices to derive the filesystem-level scrub status.
        $hasScrubData = false;
        $scrubHasError = false;
        $scrubIsRunning = (int) ($this->working['fs']['scrub_status']['ops_status'] ?? -1) === 1;
        $fsScrubHealth = 0;

        // -------------------------------------------------------------------------
        // Per-device loop
        // -------------------------------------------------------------------------
        foreach ($allDevPaths as $devPath) {
            $devStats = $devices[$devPath] ?? [];
            $usageStats = $usageDevices[$devPath] ?? [];

            // Resolve the numeric device ID (btrfs devid). Skip paths that cannot be mapped.
            $deviceNumericId = $devStats['devid'] ?? $showDevicesByPath[$devPath] ?? null;
            if (! is_scalar($deviceNumericId) || (string) $deviceNumericId === '') {
                continue;
            }
            $devId = (string) $deviceNumericId;
            $devStats['missing'] = (bool) ($devStats['missing'] ?? false);

            $scrubData = $rawScrubDevices[$devId] ?? null;

            // No scrub data for this device: write the table row with no scrub status and continue.
            if ($scrubData === null || ! is_array($scrubData)) {
                $deviceTables[$devId] = $this->rrdWriter->buildDeviceTableRow($devPath, $deviceNumericId, $devStats, $usageStats);
                $deviceTables[$devId]['io_status_code'] = $this->mapper->getDevIoStatusCode(
                    count($devStats) > 0,
                    $this->rrdWriter->hasDeviceError($devStats),
                    $devStats['missing']
                );
                $deviceTables[$devId]['scrub_status_code'] = 0;
                continue;
            }

            // Process the raw scrub data into derived fields (running, health, bytes, progress).
            $processedScrub = $this->parser->processSingleDeviceScrub($scrubData, $this->working['fs']['previous_progress']);
            $hasScrubData = true;

            // Track the worst health and running state across all devices for the fs-level status.
            if ($processedScrub['running'] === true) {
                $scrubIsRunning = true;
            }
            if ($processedScrub['health'] > $fsScrubHealth) {
                $fsScrubHealth = $processedScrub['health'];
            }
            if ($processedScrub['health'] === 2) {
                $scrubHasError = true;
            }

            // Persist per-device scrub state so the next poll can detect resets.
            $this->newData['filesystems'][$fsUuid]['scrub']['status']['devices'][$devId] = [
                'bytes' => $processedScrub['bytes_scrubbed'],
                'scrub_started' => $processedScrub['scrub_started'],
                'progress' => $processedScrub['progress'],
            ];

            // Build per-device RRD fields and write the device RRD file.
            $devFields = $this->rrdWriter->buildDeviceFields(
                $devStats,
                $processedScrub['data'],
                $usageStats,
                $processedScrub['ops'],
                $processedScrub['health']
            );
            $this->rrdWriter->writeDeviceRrd($this->device, 'btrfs', $this->app->app_id, $this->working['fs']['rrd_id'], $devId, $devFields);

            // Write per-device per-RAID-profile RRDs (one file per type on this device).
            $devTypeValues = $usageStats['type_values'] ?? [];
            if (is_array($devTypeValues)) {
                foreach ($devTypeValues as $typeKey => $typeValue) {
                    if (! is_numeric($typeValue)) {
                        continue;
                    }
                    $typeId = $this->parser->normalizeId((string) $typeKey);
                    $this->rrdWriter->writeDevTypeRrd($this->device, 'btrfs', $this->app->app_id, $this->working['fs']['rrd_id'], $devId, $typeId, $typeValue);
                }
            }

            // Build the device table row for the UI.
            $deviceTables[$devId] = $this->rrdWriter->buildDeviceTableRow($devPath, $deviceNumericId, $devStats, $usageStats);

            // Sum IO errors and log a one-time eventlog entry when errors first appear.
            $ioErrs = $this->rrdWriter->sumDeviceErrors($devStats);
            if ($ioErrs > 0) {
                Eventlog::log("BTRFS device errors detected on {$this->working['fs']['name']} ($devPath)", $this->device['device_id'], 'application', Severity::Error);
            }
            $fsIoErrorsSum += (float) $ioErrs;

            // Update the per-device IO error count sensor (row created during discovery).
            $this->sensorSync->updateCountSensorValue(
                $this->device,
                $this->working['fs']['rrd_id'] . '.dev.' . $devId,
                $this->working['fs']['display_name'] . ' ' . $devPath . ' IO Errors',
                (float) $ioErrs
            );

            // Publish device RRD field values into the per-filesystem metrics map.
            $devMetricPrefix = 'device_' . $devId . '_';
            foreach ($devFields as $field => $value) {
                $metrics[$devMetricPrefix . $field] = $value;
            }

            // Derive per-device IO and scrub status codes for the table row and sensors.
            $devIoStatusCode = $this->mapper->getDevIoStatusCode(
                count($devStats) > 0,
                $this->rrdWriter->hasDeviceError($devStats),
                $devStats['missing']
            );
            $devScrubStatusCode = $this->mapper->getDevScrubStatusCode(
                true,
                $processedScrub['health'] > 0
            );

            $deviceTables[$devId]['io_status_code'] = $devIoStatusCode;
            $deviceTables[$devId]['scrub_status_code'] = $devScrubStatusCode;

            // Update per-device state sensor values and write RRDs (rows created during discovery).
            $this->sensorSync->writeStateSensorRrd($this->device, $this->working['fs']['rrd_id'] . '.dev.' . $devId . '.io', BtrfsSensorSync::STATE_SENSOR_IO, $this->working['fs']['display_name'] . ' ' . $devPath . ' IO', $devIoStatusCode);
            $this->sensorSync->writeStateSensorRrd($this->device, $this->working['fs']['rrd_id'] . '.dev.' . $devId . '.scrub', BtrfsSensorSync::STATE_SENSOR_SCRUB, $this->working['fs']['display_name'] . ' ' . $devPath . ' Scrub', $devScrubStatusCode);
        }

        // -------------------------------------------------------------------------
        // Derive filesystem-level status codes from per-device aggregates
        // -------------------------------------------------------------------------
        $ioHasError = $this->rrdWriter->hasAnyDeviceError($devices);
        $ioStatusCode = $this->mapper->getIoStatusCode(count($devices) > 0, $ioHasError, $this->working['fs']['has_missing']);
        $scrubStatusCode = $this->mapper->getScrubStatusCode($hasScrubData, $scrubHasError, $scrubIsRunning);

        return [
            'device_tables' => $deviceTables,
            'metrics' => $metrics,
            'fs_io_errors_sum' => $fsIoErrorsSum,
            'io_status_code' => $ioStatusCode,
            'scrub_status_code' => $scrubStatusCode,
            'scrub_is_running' => $scrubIsRunning,
            'fs_scrub_health' => $fsScrubHealth,
        ];
    }

    /**
     * Derive the overall app status, assemble the full app->data structure,
     * and call update_application() to persist metrics and status.
     */
    private function persistAppData($btrfsDevVersion): void
    {
        // Roll up per-filesystem statuses into one overall application status code.
        $appStatusCode = $this->mapper->deriveAppStatusCode(
            $this->agg->hasMissing(), $this->agg->hasError(), $this->agg->hasRunning(), $this->agg->hasData()
        );
        $this->working['acc']['metrics']['status_code'] = $appStatusCode;
        $appStatusText = $this->mapper->getStatusText($appStatusCode);

        $agentData = $this->appPayload['data'] ?? [];

        // Persist the top-level app fields.
        $this->newData['schema_version'] = 7;
        $this->newData['status_code'] = $appStatusCode;
        $this->newData['status_text'] = self::truncate($appStatusText, self::MAX_STATUS_TEXT_LENGTH);
        $this->newData['btrfs_dev_version'] = $btrfsDevVersion;
        $this->newData['version'] = $agentData['version'] ?? ($this->appPayload['version'] ?? null);

        // Write the complete app->data to the DB before update_application() (update_application
        // may re-read $this->app->data internally).
        $this->app->data = $this->newData;

        // Persist metrics to RRD and update the application row in the DB with the computed
        // overall status text and all collected metric values.
        update_application($this->app, $appStatusText, $this->working['acc']['metrics']);
    }

    /**
     * Truncate a string to at most $maxLength multibyte characters. Returns null unchanged.
     */
    private static function truncate(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            return mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    /**
     * Normalize raw scrub status from the agent payload.
     *
     * Handles RAID5/6 edge cases where non-active mirror devices report null status:
     * if the previous progress was at or above the done threshold the scrub is assumed
     * finished. Also fills in progress_percent=100 when status is 'finished' but the
     * field is missing, and skips a null bytes_scrubbed so the last known value is kept.
     *
     * @return array{fs_scrub_status: mixed, bytes_scrubbed: ?float, started: ?string, progress: ?float}
     */
    private function normalizeStatus(mixed $rawStatus, ?float $previousProgress): array
    {
        $scrubBytesScrubbed = null;
        $scrubStarted = null;
        $scrubProgress = null;

        if (is_array($rawStatus)) {
            // Handle Raid5/6 non-active devices with null status
            // If status is null but old progress >= threshold, assume finished
            if (($rawStatus['status'] ?? null) === null) {
                if ($previousProgress !== null && $previousProgress >= self::SCRUB_ASSUMED_DONE_THRESHOLD) {
                    $rawStatus['status'] = 'finished';
                    $rawStatus['progress_percent'] = '100';
                    $scrubProgress = 100.0;
                }
            }

            // Set progress_percent to 100 if null and status is finished
            if (array_key_exists('progress_percent', $rawStatus) && $rawStatus['progress_percent'] === null) {
                if (strtolower(trim($rawStatus['status'] ?? '')) === 'finished') {
                    $rawStatus['progress_percent'] = '100';
                }
            }

            // Don't update bytes_scrubbed if null (leave existing value)
            if (array_key_exists('bytes_scrubbed', $rawStatus) && $rawStatus['bytes_scrubbed'] === null) {
                // Skip - don't update
            } elseif (is_numeric($rawStatus['bytes_scrubbed'] ?? null)) {
                $scrubBytesScrubbed = (float) $rawStatus['bytes_scrubbed'];
            }

            $scrubStartedRaw = $rawStatus['scrub_started'] ?? null;
            if (is_string($scrubStartedRaw) && trim($scrubStartedRaw) !== '') {
                $scrubStarted = trim($scrubStartedRaw);
            }

            // Get current progress
            if ($scrubProgress === null && is_numeric($rawStatus['progress_percent'] ?? null)) {
                $scrubProgress = (float) $rawStatus['progress_percent'];
            }
        }

        return [
            'fs_scrub_status' => $rawStatus,
            'bytes_scrubbed' => $scrubBytesScrubbed,
            'started' => $scrubStarted,
            'progress' => $scrubProgress,
        ];
    }

    /**
     * Detect a scrub counter or session reset and return the safe bytes value for the RRD.
     *
     * RRD DERIVE datasets record the *rate of change* of a counter. If the counter resets
     * (btrfs scrub restarts) the bytes value drops to 0, producing a large negative spike
     * in the RRD. This method detects resets by comparing the new value against the last
     * known state persisted in app->data and returns null when a reset is detected, signalling
     * the RRD writer to skip this sample.
     *
     * Two reset patterns are detected:
     * - Counter reset: bytes decreased within the same scrub session (unexpected, should not happen
     *   but is handled for safety)
     * - Session reset: a new scrub session started ($started changed) and the byte counter
     *   restarted from a lower value
     *
     * @param  float|null $bytes    Current bytes_scrubbed from the agent payload.
     * @param  string|null $started  Current scrub_started timestamp from the agent payload.
     * @param  array      $oldState Previous scrub status block persisted in app->data.
     * @return float|null           Safe value for the RRD, or null if a reset was detected.
     */
    private function bytesForRrd(?float $bytes, ?string $started, array $oldState): ?float
    {
        $bytesForRrd = $bytes;

        // Recover the previous state values persisted by the previous poll.
        $previousBytes = is_numeric($oldState['bytes'] ?? null)
            ? (float) $oldState['bytes']
            : null;
        $previousStarted = is_string($oldState['scrub_started'] ?? null)
            ? trim((string) $oldState['scrub_started'])
            : null;
        if ($previousStarted === '') {
            $previousStarted = null;
        }

        if ($bytesForRrd !== null && $previousBytes !== null) {
            // Counter reset: bytes went backwards within the same scrub session.
            $counterReset = $bytesForRrd < $previousBytes;

            // Session reset: a new scrub started ($started changed) and the byte counter restarted
            // from a lower value than before.
            $sessionReset = $started !== null
                && $previousStarted !== null
                && $started !== $previousStarted
                && $bytesForRrd <= $previousBytes;

            // Return null to tell the RRD writer to skip this sample rather than record a spike.
            if ($counterReset || $sessionReset) {
                $bytesForRrd = null;
            }
        }

        return $bytesForRrd;
    }
}

// =============================================================================
// Entry point shim
// =============================================================================

function btrfs_poll_app(array $device, App\Models\Application $app): void
{
    (new BtrfsPoller())->poll($device, $app);
}
