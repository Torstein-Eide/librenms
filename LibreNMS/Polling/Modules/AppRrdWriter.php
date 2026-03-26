<?php

namespace LibreNMS\Polling\Modules;

/**
 * Base class for application-specific RRD writers.
 *
 * Why not extend LibreNMS\Data\Store\Rrd?
 * ==========================================
 * LibreNMS\Data\Store\Rrd is the low-level datastore backend that handles:
 *   - Actual RRD file I/O and rrdcached communication
 *   - RRD tool command execution
 *   - File existence checks and locking
 *
 * It is accessed via app('Datastore') which returns a BaseDatastore instance.
 * Application pollers use app('Datastore')->put() to write data.
 *
 * This class provides app-specific helpers for:
 *   - Building RrdDefinition objects with app-specific DS patterns
 *   - Mapping raw data to RRD field arrays
 *   - Computing aggregate metrics
 *
 * Usage:
 *   class MyAppRrdWriter extends AppRrdWriter
 *   {
 *       protected function defineDatasets(): array { ... }
 *       protected function buildFields(array $data): array { ... }
 *   }
 */
abstract class AppRrdWriter
{
    protected array $datasets = [];
    protected array $errorKeys = [];

    public function __construct()
    {
        $this->datasets = $this->defineDatasets();
    }

    /**
     * Define RRD datasets as ['ds_name' => 'GAUGE|DERIVE|COUNTER', ...]
     */
    abstract protected function defineDatasets(): array;

    /**
     * Build field values array from raw data for RRD update.
     */
    abstract public function buildFields(array $data): array;

    /**
     * Get the RrdDefinition for this app's RRD.
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
     * Sum error counts from device stats array.
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
     * Check if any error key has a non-zero value.
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
     * Sum numeric values from an array of stat rows.
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
     * Write RRD data using the Datastore.
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
     * Normalize a device path string.
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
     */
    protected function isMissing(array $entry): bool
    {
        return ! empty($entry['missing']) || ! empty($entry['device_missing']);
    }

    /**
     * Generate a missing device path key.
     */
    protected function missingKey(string $devid): string
    {
        return '<missing disk #' . $devid . '>';
    }
}
