<?php

use App\Facades\Rrd;
use LibreNMS\Exceptions\RrdGraphException;

/**
 * Per-drive graph for a v3 mdadm array: one line per member device.
 *
 * Reads the per-drive RRD keyed by the stable array UUID and the member
 * dev_id: ['app', 'mdadm', <app_id>, <array uuid>, <dev_id>].
 */
[$unit_text, $ds] = match ($vars['metric'] ?? '') {
    'events' => ['Events', 'events'],
    'bad_blocks' => ['Bad Blocks', 'bad_blocks'],
    default => throw new RrdGraphException('Unknown metric: ' . ($vars['metric'] ?? '')),
};

$arrayParam = $vars['array'] ?? '';
if ($arrayParam === '') {
    throw new RrdGraphException('No array selected');
}

$dbArray = App\Models\MdadmArray::where('app_id', $app->app_id)
    ->where(function ($q) use ($arrayParam): void {
        $q->where('uuid', $arrayParam)->orWhere('array_name', $arrayParam)->orWhere('md_id', $arrayParam);
    })
    ->with('drives')
    ->first();

if ($dbArray === null) {
    throw new RrdGraphException('Unknown array: ' . $arrayParam);
}

$name = 'mdadm';
$unitlen = 12;
$bigdescrlen = 12;
$smalldescrlen = 12;
$colours = 'mixed';
$dostack = 0;
$printtotal = 0;
$addarea = 0;
$transparency = 15;
$scale_min = 0;

$rrd_list = [];
foreach ($dbArray->drives as $drive) {
    $devId = (string) ($drive->dev_id ?? '');
    if ($devId === '') {
        continue;
    }

    // Match the poll-side key: stable superblock device UUID, else dev_id.
    $driveKey = $drive->dev_uuid !== null && $drive->dev_uuid !== '' ? (string) $drive->dev_uuid : $devId;
    $rrd_filename = Rrd::name($device['hostname'], ['app', $name, $app->app_id, $dbArray->uuid, $driveKey]);
    if (! Rrd::checkRrdExists($rrd_filename)) {
        continue;
    }

    $rrd_list[] = [
        'filename' => $rrd_filename,
        'descr' => $drive->path !== null && $drive->path !== '' ? basename((string) $drive->path) : $devId,
        'ds' => $ds,
    ];
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
