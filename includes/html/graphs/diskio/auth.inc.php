<?php

use App\Models\DiskIo;

if (is_numeric($vars['id'])) {
    $disk = DiskIo::find($vars['id']);

    if ($disk !== null && ($auth || device_permitted($disk->device_id))) {
        $device = device_by_id_cache($disk->device_id);

        $title = generate_device_link($device);
        $title .= ' :: Disk :: ' . htmlentities((string) $disk->diskio_descr);
        $auth = true;
    }
} elseif (! empty($vars['ids'])) {
    $ids = array_filter(array_map(intval(...), explode(',', (string) $vars['ids'])));
    foreach (DiskIo::whereIn('diskio_id', $ids)->get() as $disk) {
        if ($auth || device_permitted($disk->device_id)) {
            $device ??= device_by_id_cache($disk->device_id);
            $auth = true;
            break;
        }
    }

    if ($auth && isset($device)) {
        $driveCount = count($ids);
        $title = generate_device_link($device) . ' :: Disk :: Aggregate of ' . $driveCount . ($driveCount === 1 ? ' drive' : ' drives');
    }
}
