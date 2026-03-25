<?php

require 'includes/html/graphs/common.inc.php';

$fs = $vars['fs'] ?? null;
if (! is_string($fs) || $fs === '') {
    throw new \LibreNMS\Exceptions\RrdGraphException('No filesystem selected');
}

$device_map = $app->data['device_map'][$fs] ?? [];
$device_metadata = $app->data['device_metadata'][$fs] ?? [];

if (! is_array($device_map) || count($device_map) === 0) {
    throw new \LibreNMS\Exceptions\RrdGraphException('No filesystem devices');
}

$diskio_rows = dbFetchRows('SELECT `diskio_id`, `diskio_descr` FROM `ucd_diskio` WHERE `device_id` = ? ORDER BY `diskio_descr`', [$device['device_id']]);
$diskio_by_descr = [];
foreach ($diskio_rows as $diskio_row) {
    $diskio_descr = trim((string) ($diskio_row['diskio_descr'] ?? ''));
    if ($diskio_descr !== '') {
        $diskio_by_descr[$diskio_descr] = true;
    }
}

$selected_diskio_descrs = [];
foreach ($device_map as $dev_id => $dev_path) {
    $path = trim((string) $dev_path);
    if ($path === '') {
        continue;
    }

    $priority_candidates = [];
    $fallback_candidates = [];
    $metadata = $device_metadata[$dev_id] ?? [];
    if (is_array($metadata)) {
        $backing = is_array($metadata['backing'] ?? null) ? $metadata['backing'] : [];
        $primary = is_array($metadata['primary'] ?? null) ? $metadata['primary'] : [];

        $backing_devnode = trim((string) ($backing['devnode'] ?? ''));
        if ($backing_devnode !== '') {
            $priority_candidates[] = $backing_devnode;
            $priority_candidates[] = ltrim((string) preg_replace('#^/dev/#', '', $backing_devnode), '/');
            $priority_candidates[] = basename($backing_devnode);
        }

        $backing_name = trim((string) ($backing['name'] ?? ''));
        if ($backing_name !== '') {
            $priority_candidates[] = $backing_name;
            $priority_candidates[] = '/dev/' . $backing_name;
        }

        $primary_devnode = trim((string) ($primary['devnode'] ?? ''));
        if ($primary_devnode !== '') {
            $fallback_candidates[] = $primary_devnode;
            $fallback_candidates[] = ltrim((string) preg_replace('#^/dev/#', '', $primary_devnode), '/');
            $fallback_candidates[] = basename($primary_devnode);
        }

        $primary_name = trim((string) ($primary['name'] ?? ''));
        if ($primary_name !== '') {
            $fallback_candidates[] = $primary_name;
            $fallback_candidates[] = '/dev/' . $primary_name;
        }
    }

    $fallback_candidates[] = $path;
    $fallback_candidates[] = ltrim((string) preg_replace('#^/dev/#', '', $path), '/');
    $fallback_candidates[] = basename($path);

    $ordered_candidates = array_values(array_unique(array_merge($priority_candidates, $fallback_candidates)));
    foreach ($ordered_candidates as $candidate) {
        if (isset($diskio_by_descr[$candidate])) {
            $selected_diskio_descrs[] = $candidate;

            break;
        }
    }
}

$selected_diskio_descrs = array_values(array_unique($selected_diskio_descrs));
if (count($selected_diskio_descrs) === 0) {
    throw new \LibreNMS\Exceptions\RrdGraphException('No matching diskio entries for filesystem');
}

$rrd_list = [];
foreach ($selected_diskio_descrs as $diskio_descr) {
    $rrd_filename = \App\Facades\Rrd::name($device['hostname'], ['ucd_diskio', $diskio_descr]);
    if (! \App\Facades\Rrd::checkRrdExists($rrd_filename)) {
        continue;
    }

    $rrd_list[] = [
        'filename' => $rrd_filename,
        'descr' => $diskio_descr,
    ];
}

if (count($rrd_list) === 0) {
    throw new \LibreNMS\Exceptions\RrdGraphException('No matching diskio RRDs for filesystem');
}
