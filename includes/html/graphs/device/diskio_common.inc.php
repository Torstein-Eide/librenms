<?php

use App\Models\DiskIo;

$i = 1;

foreach (DiskIo::query()->where('device_id', $device['device_id'])->get() as $disk) {
    $rrd_filename = Rrd::name($disk->device->hostname ?? $device['hostname'], ['ucd_diskio', $disk['diskio_descr']]);
    if (Rrd::checkRrdExists($rrd_filename)) {
        $rrd_list[$i]['filename'] = $rrd_filename;
        $rrd_list[$i]['descr'] = $disk['diskio_descr'];
        $rrd_list[$i]['ds_in'] = $ds_in;
        $rrd_list[$i]['ds_out'] = $ds_out;
        $i++;
    }
}
