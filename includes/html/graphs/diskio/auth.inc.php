<?php

use App\Models\DiskIo;

if (is_numeric($vars['id'])) {
    $disk = DiskIo::find($vars['id']);

    if ($disk !== null && ($auth || device_permitted($disk->device_id))) {
        $device = device_by_id_cache($disk->device_id);

        $rrd_filename = Rrd::name($disk->device->hostname ?? $device['hostname'], ['ucd_diskio', $disk->diskio_descr]);

        $title = generate_device_link($device);
        $title .= ' :: Disk :: ' . htmlentities((string) $disk->diskio_descr);
        $auth = true;
    }
}
