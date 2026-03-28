<?php

namespace LibreNMS\Polling\Modules;

/**
 * BtrfsStatusMapper computes IO/Scrub/Balance status codes from parsed btrfs data.
 *
 * Status code contract:
 *   0 = OK (healthy)
 *   1 = Running (normal transient state)
 *  -1 = N/A (not running, no errors)
 *   2 = N/A (no data available)
 *   3 = Error (problem detected)
 *   4 = Missing (device absent)
 *
 * LibreNMS generic mapping:
 *   generic 0 = OK
 *   generic 1 = Warning (Running maps here)
 *   generic 2 = Critical (Error, Missing map here)
 *   generic 3 = Unknown (N/A maps here)
 */
class BtrfsStatusMapper
{
    public const STATUS_OK = 0;
    public const STATUS_RUNNING = 1;
    public const STATUS_NA = 2;
    public const STATUS_ERROR = 3;
    public const STATUS_MISSING = 4;
    public const STATUS_UNKNOWN = -1;

    public function getIoStatusCode(bool $has_device_data, bool $has_error, bool $has_missing): int
    {
        if (! $has_device_data) {
            return self::STATUS_NA;
        }

        if ($has_missing) {
            return self::STATUS_MISSING;
        }

        return $has_error ? self::STATUS_ERROR : self::STATUS_OK;
    }

    public function getScrubStatusCode(bool $has_scrub_data, bool $has_error, bool $is_running): int
    {
        if (! $has_scrub_data) {
            return self::STATUS_NA;
        }

        if ($has_error) {
            return self::STATUS_ERROR;
        }

        if ($is_running) {
            return self::STATUS_RUNNING;
        }

        return self::STATUS_OK;
    }

    public function getDevIoStatusCode(bool $has_data, bool $has_error, bool $is_missing): int
    {
        if ($is_missing) {
            return self::STATUS_MISSING;
        }

        if (! $has_data) {
            return self::STATUS_NA;
        }

        return $has_error ? self::STATUS_ERROR : self::STATUS_OK;
    }

    public function getDevScrubStatusCode(bool $has_data, bool $has_error, bool $is_running): int
    {
        if (! $has_data) {
            return self::STATUS_NA;
        }

        if ($has_error) {
            return self::STATUS_ERROR;
        }

        if ($is_running) {
            return self::STATUS_RUNNING;
        }

        return self::STATUS_OK;
    }

    public function getStatusText(int $status_code): string
    {
        return match ($status_code) {
            self::STATUS_RUNNING => 'Running',
            self::STATUS_NA => 'N/A',
            self::STATUS_ERROR => 'Error',
            self::STATUS_MISSING => 'Missing',
            self::STATUS_UNKNOWN => 'Unknown',
            default => 'OK',
        };
    }

    public function deriveAppStatusCode(bool $has_missing, bool $has_error, bool $has_running, bool $has_data): int
    {
        if ($has_missing) {
            return self::STATUS_MISSING;
        }
        if ($has_error) {
            return self::STATUS_ERROR;
        }
        if ($has_running) {
            return self::STATUS_RUNNING;
        }
        if ($has_data) {
            return self::STATUS_OK;
        }

        return self::STATUS_NA;
    }

    public function getGenericValue(int $status_code): int
    {
        return match ($status_code) {
            self::STATUS_OK => 0,
            self::STATUS_RUNNING => 1,
            self::STATUS_NA => 3,
            self::STATUS_ERROR, self::STATUS_MISSING => 2,
            default => 3,
        };
    }

    public function getBalanceStatusCodeFromFlat(array $balance_status): int
    {
        if (empty($balance_status)) {
            return self::STATUS_NA;
        }

        if (! empty($balance_status['is_running'])) {
            return self::STATUS_RUNNING;
        }

        $message = $balance_status['message'] ?? '';
        if ($message !== '' && ! str_contains(strtolower($message), 'no balance found')) {
            return self::STATUS_ERROR;
        }

        return self::STATUS_NA;
    }

    public function getStatusStates(): array
    {
        return [
            ['value' => self::STATUS_UNKNOWN, 'generic' => 3, 'graph' => 0, 'descr' => 'Unknown'],
            ['value' => self::STATUS_OK, 'generic' => 0, 'graph' => 0, 'descr' => 'OK'],
            ['value' => self::STATUS_RUNNING, 'generic' => 1, 'graph' => 0, 'descr' => 'Running'],
            ['value' => -1, 'generic' => 3, 'graph' => 0, 'descr' => 'N/A'],
            ['value' => self::STATUS_NA, 'generic' => 3, 'graph' => 0, 'descr' => 'N/A'],
            ['value' => self::STATUS_ERROR, 'generic' => 2, 'graph' => 0, 'descr' => 'Error'],
            ['value' => self::STATUS_MISSING, 'generic' => 2, 'graph' => 0, 'descr' => 'Missing'],
        ];
    }
}
