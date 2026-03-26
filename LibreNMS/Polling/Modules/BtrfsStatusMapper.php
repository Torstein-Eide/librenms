<?php

namespace LibreNMS\Polling\Modules;

/**
 * BtrfsStatusMapper computes IO/Scrub/Balance status codes from parsed btrfs data.
 *
 * Status code contract:
 *   0 = OK (healthy)
 *   1 = Running (normal transient state)
 *   2 = N/A (no data available)
 *   3 = Error (problem detected)
 *   4 = Missing (device absent)
 *  -1 = Unknown
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

    private array $runningFlagKeys = ['running', 'is_running', 'in_progress'];
    private array $runningStatusTokens = ['running', 'in-progress', 'in_progress'];
    private array $finishedStatusTokens = ['finished', 'done', 'idle', 'stopped', 'completed'];
    private array $errorStatusTokens = ['error', 'failed', 'failure'];

    public function getBalanceStatusCode(array $fs): int
    {
        $command = $fs['commands']['balance_status'] ?? null;
        if (! is_array($command)) {
            return self::STATUS_NA;
        }

        if (is_numeric($command['rc'] ?? null) && (int) $command['rc'] === 0) {
            return self::STATUS_NA;
        }

        $data = $command['data'] ?? [];
        if (! is_array($data)) {
            return self::STATUS_NA;
        }

        $running_flag = null;
        foreach ($this->runningFlagKeys as $running_key) {
            if (! array_key_exists($running_key, $data)) {
                continue;
            }

            $running_value = $data[$running_key];
            if (is_bool($running_value)) {
                $running_flag = $running_value;
                break;
            }
            if (is_numeric($running_value)) {
                $running_flag = ((int) $running_value) !== 0;
                break;
            }
        }
        if ($running_flag === true) {
            return self::STATUS_RUNNING;
        }

        $status = strtolower(trim((string) ($data['status'] ?? '')));
        if (in_array($status, $this->runningStatusTokens, true)) {
            return self::STATUS_RUNNING;
        }
        if (in_array($status, $this->errorStatusTokens, true)) {
            return self::STATUS_ERROR;
        }

        if ($status !== '') {
            return self::STATUS_OK;
        }

        $profiles = $data['profiles'] ?? [];

        return is_array($profiles) && count($profiles) > 0 ? self::STATUS_OK : self::STATUS_NA;
    }

    public function getIoStatusCode(bool $has_device_data, bool $has_error, bool $has_missing): int
    {
        if ($has_missing) {
            return self::STATUS_MISSING;
        }

        if (! $has_device_data) {
            return self::STATUS_NA;
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

        if (! empty($balance_status['message'])) {
            return self::STATUS_OK;
        }

        return self::STATUS_NA;
    }

    public function getStatusStates(): array
    {
        return [
            ['value' => self::STATUS_UNKNOWN, 'generic' => 3, 'graph' => 0, 'descr' => 'Unknown'],
            ['value' => self::STATUS_OK, 'generic' => 0, 'graph' => 0, 'descr' => 'OK'],
            ['value' => self::STATUS_RUNNING, 'generic' => 1, 'graph' => 0, 'descr' => 'Running'],
            ['value' => self::STATUS_NA, 'generic' => 3, 'graph' => 0, 'descr' => 'N/A'],
            ['value' => self::STATUS_ERROR, 'generic' => 2, 'graph' => 0, 'descr' => 'Error'],
            ['value' => self::STATUS_MISSING, 'generic' => 2, 'graph' => 0, 'descr' => 'Missing'],
        ];
    }
}
