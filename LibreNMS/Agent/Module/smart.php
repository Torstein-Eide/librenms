<?php

namespace LibreNMS\Agent\Module;

use App\Models\Application;

/**
 * SMART agent module (payload version 2+).
 *
 * Payload structure (data.tables.disks keyed by "<model>+<serial>"):
 * {
 *   "version": 2,
 *   "data": {
 *     "counters": { "devices_total": 8, "over_temp": 0, "unhealthy": 0, ... },
 *     "errors": [],
 *     "tables": {
 *       "disks": {
 *         "<model>+<serial>": {
 *           "attributes": { "ata": [ { "id": 5, "name": "...", "raw": { "value": 0 }, ... } ] },
 *           "health":      { "smart_passed": true, "command_exit": 0, ... },
 *           "identity":    { "dev_name": "sda", "device_path": "/dev/sda", ... },
 *           "power":       { "power_on_time": { "hours": 1234 }, "power_cycle_count": 5 },
 *           "selftest":    { ... },
 *           "stats":       { ... },
 *           "temperature": { "current_c": 35, "lifetime_max_c": 50, "over_limit": false, ... }
 *         }
 *       }
 *     }
 *   }
 * }
 */
class smart
{
    public function __construct(private array $device, private Application $app)
    {
    }

    public function run(array $payload): void
    {
        $data = $payload['data'];
        $this->app->data = $data;

        $counters = $data['counters'] ?? [];

        update_application($this->app, 'ok', [
            'devices_total'    => (int) ($counters['devices_total'] ?? 0),
            'over_temp'        => (int) ($counters['over_temp'] ?? 0),
            'unhealthy'        => (int) ($counters['unhealthy'] ?? 0),
            'command_failures' => (int) ($counters['command_failures'] ?? 0),
            'parse_failures'   => (int) ($counters['parse_failures'] ?? 0),
        ]);
    }
}
