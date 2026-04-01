<?php

require_once base_path('LibreNMS/Polling/Modules/BtrfsPoller.php');

/**
 * Clean btrfs database for development/testing.
 * Deletes all btrfs application data, sensors, and optionally RRD files.
 *
 * @param  int   $device_id   Device ID to clean sensors for
 * @param  int   $app_id     Application ID to clean app data for
 * @param  bool  $clean_rrd  Also delete RRD files
 */
function btrfs_clean_db(int $device_id, App\Models\Application $app): void
{
    echo "Cleaning btrfs database for device_id=$device_id, app_id={$app->app_id}\n";

    // Delete btrfs sensors (sensor_class = 'btrfs')
    $sensors = App\Models\Sensor::where('device_id', $device_id)
        ->where('sensor_class', 'btrfs')
        ->delete();
    echo "Deleted $sensors btrfs sensors\n";

    // Delete state sensors linked to btrfs state indexes
    $state_sensor_types = [
        'btrfsIoStatusState',
        'btrfsScrubStatusState',
        'btrfsBalanceStatusState',
        'btrfsIoErrors',
    ];
    $state_sensors = App\Models\Sensor::where('device_id', $device_id)
        ->whereIn('sensor_type', $state_sensor_types)
        ->delete();
    echo "Deleted $state_sensors state sensors\n";

    // Reset app data using direct DB update to bypass Eloquent casts
    Illuminate\Support\Facades\DB::table('applications')
        ->where('app_id', $app->app_id)
        ->update(['data' => null]);
    // Reload the model to get fresh state
    $app->refresh();
    echo "Cleared app data\n";

    echo "Done cleaning btrfs database\n";
}

// Development: uncomment to clean database before polling
//btrfs_clean_db($device['device_id'], $app);

btrfs_poll_app($device, $app);
