<?php

use App\Models\DiskIo;

$query = DiskIo::query()->where('device_id', $device['device_id']);
if (is_numeric($vars['id'] ?? null)) {
    $query->where('diskio_id', (int) $vars['id']);
} elseif (! empty($vars['ids'])) {
    $query->whereIn('diskio_id', array_filter(array_map(intval(...), explode(',', (string) $vars['ids']))));
}

$rrd_list = [];
foreach ($query->orderBy('diskio_descr')->get() as $disk) {
    $rrd_filename = Rrd::name($disk->device->hostname ?? $device['hostname'], ['ucd_diskio', $disk['diskio_descr']]);
    if (! Rrd::checkRrdExists($rrd_filename)) {
        continue;
    }

    $rrd = [
        'filename' => $rrd_filename,
        'descr' => $disk['diskio_descr'],
    ];

    if (isset($ds_in) && $ds_in !== '') {
        $rrd['ds_in'] = $ds_in;
    }

    if (isset($ds_out) && $ds_out !== '') {
        $rrd['ds_out'] = $ds_out;
    }

    $rrd_list[] = $rrd;
}

if (count($rrd_list) === 0) {
    throw new LibreNMS\Exceptions\RrdGraphException('No matching diskio RRDs');
}
