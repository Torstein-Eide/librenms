<?php

require 'includes/html/graphs/common.inc.php';

$fs_param = $vars['fs'] ?? null;
if (! is_string($fs_param) || $fs_param === '') {
    throw new LibreNMS\Exceptions\RrdGraphException('No filesystem selected');
}

// $fs_param is UUID, look up mountpoint
$mountpoint_to_uuid = [];
$uuid_to_mountpoint = [];
$tables_fs = $app->data['tables']['filesystems'] ?? [];
foreach ($tables_fs as $uuid => $fs_entry) {
    $mp = $fs_entry['mountpoint'] ?? null;
    if ($mp !== null) {
        $mountpoint_to_uuid[$mp] = $uuid;
        $uuid_to_mountpoint[$uuid] = $mp;
    }
}

// Determine if fs_param is UUID or mountpoint
if (isset($uuid_to_mountpoint[$fs_param])) {
    // It's a UUID
    $fs_uuid = $fs_param;
    $fs = $uuid_to_mountpoint[$fs_param] ?? $fs_param;
} elseif (isset($mountpoint_to_uuid[$fs_param])) {
    // It's a mountpoint
    $fs = $fs_param;
    $fs_uuid = $mountpoint_to_uuid[$fs_param];
} else {
    throw new LibreNMS\Exceptions\RrdGraphException('Unknown filesystem: ' . $fs_param);
}

$discovery_fs = $app->data['discovery']['filesystems'][$fs_uuid] ?? null;
$fs_entry = $tables_fs[$fs_uuid] ?? null;
$device_map = is_array($discovery_fs) ? ($discovery_fs['devices'] ?? []) : [];
$device_metadata = $app->data['filesystems'][$fs_uuid]['device_metadata'] ?? [];

if (! is_array($device_map) || count($device_map) === 0) {
    throw new LibreNMS\Exceptions\RrdGraphException('No filesystem devices');
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
        $backing_path = trim((string) ($metadata['backing_path'] ?? ''));
        if ($backing_path !== '') {
            $priority_candidates[] = $backing_path;
            $priority_candidates[] = ltrim((string) preg_replace('#^/dev/#', '', $backing_path), '/');
            $priority_candidates[] = basename($backing_path);
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
    throw new LibreNMS\Exceptions\RrdGraphException('No matching diskio entries for filesystem');
}

$rrd_list = [];
foreach ($selected_diskio_descrs as $diskio_descr) {
    $rrd_filename = App\Facades\Rrd::name($device['hostname'], ['ucd_diskio', $diskio_descr]);
    if (! App\Facades\Rrd::checkRrdExists($rrd_filename)) {
        continue;
    }

    $rrd_list[] = [
        'filename' => $rrd_filename,
        'descr' => $diskio_descr,
    ];
}

if (count($rrd_list) === 0) {
    throw new LibreNMS\Exceptions\RrdGraphException('No matching diskio RRDs for filesystem');
}
