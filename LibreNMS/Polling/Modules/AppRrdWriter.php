<?php

namespace LibreNMS\Polling\Modules;

/**
 * Base class for application-specific RRD writers.
 *
 * PURPOSE
 * =======
 * This class provides a foundation for building RRD data writers in LibreNMS
 * application pollers. It encapsulates common patterns for:
 *   - Defining RRD dataset structures
 *   - Building field arrays for RRD updates
 *   - Aggregating metrics across multiple data points
 *   - Error detection and reporting
 *
 * RELATIONSHIP TO LIBRENMS DATASTORE
 * ==================================
 * LibreNMS uses a datastore abstraction pattern where:
 *
 *   1. app('Datastore') returns a BaseDatastore instance (typically LibreNMS\Data\Store\Rrd)
 *
 *   2. LibreNMS\Data\Store\Rrd is the LOW-LEVEL backend that handles:
 *      - Actual RRD file I/O operations
 *      - rrdcached communication for performance
 *      - RRD tool command execution
 *      - File existence checks and locking
 *      - RRA (Round Robin Archive) configuration
 *
 *   3. Application pollers should NOT extend Rrd directly. Instead, they use
 *      app('Datastore')->put() to write data through it.
 *
 *   4. This class (AppRrdWriter) provides app-specific helpers that prepare data
 *      and call app('Datastore')->put() with proper structure.
 *
 * RRD DATASET TYPES
 * ==================
 * Understanding DS types is important when defining datasets:
 *
 *   GAUGE   - Stores absolute values. Good for temperatures, counts, etc.
 *             The value is stored as-is. Rate is computed over the interval.
 *
 *   DERIVE  - Like COUNTER but allows negative rates (for reverse counters).
 *             Computes rate of change. Resets to 0 on counter wrap.
 *
 *   COUNTER - Stores continuously incrementing counters (e.g., byte counts).
 *             Computes rate of change per second. Handles counter wraps.
 *             WARNING: Counter resets cause huge spikes. Send 'U' for one poll
 *             after a reset to prevent this.
 *
 *   ABSOLUTE - Like COUNTER but resets on each read. Good for counter that
 *              resets when read (rare).
 *
 * IMPLEMENTING A CONCRETE WRITER
 * ==============================
 * To create an RRD writer for your application:
 *
 *   class MyAppRrdWriter extends AppRrdWriter
 *   {
 *       protected function defineDatasets(): array
 *       {
 *           return [
 *               'temperature' => 'GAUGE',
 *               'bytes_in'   => 'COUNTER',
 *               'bytes_out'  => 'COUNTER',
 *               'errors'     => 'DERIVE',
 *           ];
 *       }
 *
 *       public function buildFields(array $data): array
 *       {
 *           return [
 *               'temperature' => $data['temp'],
 *               'bytes_in'    => $data['rx_bytes'],
 *               'bytes_out'   => $data['tx_bytes'],
 *               'errors'      => $data['error_count'],
 *           ];
 *       }
 *
 *       // Optionally override write() for custom RRD paths:
 *       public function writeDeviceRrd(array $device, string $appName,
 *           int $appId, string $deviceId, array $fields): void
 *       {
 *           $this->write($device, $appName, $appId,
 *               "device_$deviceId", $fields);
 *       }
 *   }
 *
 * RRD NAMING CONVENTION
 * =====================
 * LibreNMS RRD files follow this pattern:
 *   {rrd_dir}/app/{app_name}/{app_id}/{name}.rrd
 *
 * For app pollers, the typical structure is:
 *   app-{app_name}-{app_id}-{fs_name}.rrd
 *   app-{app_name}-{app_id}-{fs_name}-device_{devid}.rrd
 *
 * DS NAMES MUST BE <= 19 CHARACTERS (RRD limitation).
 *
 * @see LibreNMS\RRD\RrdDefinition For RRD definition building
 * @see LibreNMS\Data\Store\Rrd For the low-level RRD backend
 * @see https://oss.oetiker.ch/rrdtool/doc/rrdcreate.en.html For RRD DS docs
 */
abstract class AppRrdWriter
{
    /**
     * Dataset definitions in format ['ds_name' => 'GAUGE|DERIVE|COUNTER'].
     * Populated by constructor from defineDatasets().
     */
    protected array $datasets = [];

    /**
     * Default error keys to check when using sumErrors/hasErrors helpers.
     * Override in subclass or pass keys array to helper methods.
     */
    protected array $errorKeys = [];

    /**
     * Initialize the writer and populate dataset definitions.
     */
    public function __construct()
    {
        $this->datasets = $this->defineDatasets();
    }

    /**
     * Define the RRD datasets for this application.
     *
     * Return an associative array of dataset definitions:
     *   ['ds_name' => 'GAUGE|DERIVE|COUNTER', ...]
     *
     * The keys are dataset names (max 19 chars for RRD compatibility).
     * The values are RRD dataset types.
     *
     * Example:
     *   return [
     *       'cpu_usage'  => 'GAUGE',
     *       'bytes_rcvd' => 'COUNTER',
     *       'bytes_sent' => 'COUNTER',
     *       'errors'     => 'DERIVE',
     *   ];
     *
     * @return array Dataset definitions
     */
    abstract protected function defineDatasets(): array;

    /**
     * Build field values array from raw application data.
     *
     * This method maps application-specific data structures to the RRD
     * dataset field names defined in defineDatasets().
     *
     * Example:
     *   // Given raw $data from your app:
     *   // ['temperature' => 45.2, 'humidity' => 65.0]
     *
     *   return [
     *       'cpu_usage'  => $data['temperature'],
     *       'bytes_rcvd' => $data['rx_bytes'],
     *   ];
     *
     * @param array $data Raw data from your application poller
     * @return array Field values keyed by dataset name
     */
    abstract public function buildFields(array $data): array;

    /**
     * Build an RrdDefinition from the dataset definitions.
     *
     * Creates an RrdDefinition object that can be passed to the datastore
     * for RRD file creation. The definition includes all datasets
     * defined in defineDatasets().
     *
     * @return \LibreNMS\RRD\RrdDefinition The RRD definition for this app
     */
    public function getRrdDefinition(): \LibreNMS\RRD\RrdDefinition
    {
        $def = \LibreNMS\RRD\RrdDefinition::make();
        foreach ($this->datasets as $ds => $type) {
            $def->addDataset($ds, $type, 0);
        }

        return $def;
    }

    /**
     * Sum error counts from a stats array.
     *
     * Useful for aggregating multiple error counters into a single
     * value for alerting or status determination.
     *
     * @param array $stats Associative array of stat values
     * @param array|null $keys Specific keys to sum, or null to use $this->errorKeys
     * @return float Sum of all specified error values
     *
     * @example
     *   $stats = [
     *       'read_errs' => 5,
     *       'write_errs' => 3,
     *       'flush_errs' => 0,
     *   ];
     *   $total = $writer->sumErrors($stats);  // Returns 8.0
     */
    public function sumErrors(array $stats, array $keys = null): float
    {
        $keys = $keys ?? $this->errorKeys;
        $total = 0.0;
        foreach ($keys as $key) {
            $total += (float) ($stats[$key] ?? 0);
        }

        return $total;
    }

    /**
     * Check if any error counter is non-zero.
     *
     * Convenience method to determine if any errors have occurred,
     * useful for setting status indicators or triggering alerts.
     *
     * @param array $stats Associative array of stat values
     * @param array|null $keys Specific keys to check, or null to use $this->errorKeys
     * @return bool True if any specified key has a value > 0
     *
     * @example
     *   $stats = ['read_errs' => 0, 'write_errs' => 0];
     *   $writer->hasErrors($stats);  // Returns false
     *
     *   $stats = ['read_errs' => 1, 'write_errs' => 0];
     *   $writer->hasErrors($stats);  // Returns true
     */
    public function hasErrors(array $stats, array $keys = null): bool
    {
        $keys = $keys ?? $this->errorKeys;
        foreach ($keys as $key) {
            if (isset($stats[$key]) && is_numeric($stats[$key]) && (float) $stats[$key] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sum numeric values from multiple stat rows.
     *
     * Aggregates values across multiple data points, useful when you have
     * per-device or per-interface data and need totals.
     *
     * @param array $rows Array of stat arrays
     * @param array $fields Field names to aggregate
     * @return array Aggregated totals keyed by field name
     *
     * @example
     *   $devices = [
     *       ['bytes_in' => 1000, 'bytes_out' => 500],
     *       ['bytes_in' => 2000, 'bytes_out' => 750],
     *   ];
     *   $totals = $writer->sumTotals($devices, ['bytes_in', 'bytes_out']);
     *   // Returns ['bytes_in' => 3000, 'bytes_out' => 1250]
     */
    public function sumTotals(array $rows, array $fields): array
    {
        $totals = array_fill_keys($fields, 0);
        foreach ($rows as $row) {
            foreach ($fields as $field) {
                $totals[$field] += (float) ($row[$field] ?? 0);
            }
        }

        return $totals;
    }

    /**
     * Write RRD data through the LibreNMS datastore.
     *
     * This is the main entry point for writing RRD data. It:
     *   1. Builds the RRD name from the components
     *   2. Merges provided tags with defaults
     *   3. Calls app('Datastore')->put() to write the data
     *
     * @param array $device Device array from poller context
     * @param string $appName Application name (e.g., 'btrfs')
     * @param int $appId Application ID from LibreNMS
     * @param string $rrdName RRD file name component (e.g., 'fs_root')
     * @param array $fields Dataset values to write
     * @param array $tags Additional tags (e.g., custom rrd_def)
     *
     * @example
     *   $writer->write($device, 'btrfs', 13, 'fs_root', [
     *       'device_size' => 1000000000,
     *       'used' => 500000000,
     *   ]);
     */
    public function write(array $device, string $appName, int $appId, string $rrdName, array $fields, array $tags = []): void
    {
        $defaultTags = [
            'name' => $appName,
            'app_id' => $appId,
            'rrd_def' => $this->getRrdDefinition(),
            'rrd_name' => ['app', $appName, $appId, $rrdName],
        ];
        $mergedTags = array_merge($defaultTags, $tags);
        app('Datastore')->put($device, 'app', $mergedTags, $fields);
    }

    /**
     * Normalize a device path string for safe storage.
     *
     * Handles null inputs and empty strings, returning null for invalid
     * inputs and trimming whitespace from valid strings.
     *
     * @param string|null $path Raw path string
     * @return string|null Normalized path or null if invalid
     *
     * @example
     *   $writer->normalizePath('/dev/sda1');  // Returns '/dev/sda1'
     *   $writer->normalizePath('  /dev/sda1  ');  // Returns '/dev/sda1'
     *   $writer->normalizePath('');  // Returns null
     *   $writer->normalizePath(null);  // Returns null
     */
    protected function normalizePath(?string $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $trimmed = trim($path);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Check if a device entry represents a missing device.
     *
     * Checks both 'missing' and 'device_missing' flags which are
     * commonly used in multi-device filesystems.
     *
     * @param array $entry Device entry array
     * @return bool True if device is marked as missing
     *
     * @example
     *   $entry = ['path' => '/dev/sda1', 'missing' => 0];
     *   $writer->isMissing($entry);  // Returns false
     *
     *   $entry = ['devid' => 5, 'device_missing' => 1];
     *   $writer->isMissing($entry);  // Returns true
     */
    protected function isMissing(array $entry): bool
    {
        return ! empty($entry['missing']) || ! empty($entry['device_missing']);
    }

    /**
     * Generate a display key for missing devices.
     *
     * Creates a consistent placeholder string for devices that are
     * absent from the filesystem, used in UI display and RRD naming.
     *
     * @param string $devid Device ID
     * @return string Placeholder string like '<missing disk #5>'
     *
     * @example
     *   $writer->missingKey('5');  // Returns '<missing disk #5>'
     */
    protected function missingKey(string $devid): string
    {
        return '<missing disk #' . $devid . '>';
    }
}
