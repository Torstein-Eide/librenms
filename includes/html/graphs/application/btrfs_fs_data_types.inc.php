<?php

require_once base_path('includes/html/pages/btrfs-common.inc.php');
require 'includes/html/graphs/common.inc.php';

$unit_text ??= '';
$graph_params->scale_min = 0;
$graph_params->base = 1024;

$iter = '1';

$safe_id = static function (string $value): string {
    $id = preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
    $id = trim((string) $id, '_');

    return $id === '' ? 'id' : $id;
};

$known_types = [
    'data_data' => 'Data Data',
    'data_linear' => 'Data Linear',
    'data_single' => 'Data Single',
    'data_raid0' => 'Data RAID0',
    'data_raid1' => 'Data RAID1',
    'data_raid1c3' => 'Data RAID1c3',
    'data_raid1c4' => 'Data RAID1c4',
    'data_raid5' => 'Data RAID5',
    'data_raid6' => 'Data RAID6',
    'data_raid10' => 'Data RAID10',
    'data_dup' => 'Data DUP',
    'metadata_data' => 'Metadata Data',
    'metadata_linear' => 'Metadata Linear',
    'metadata_single' => 'Metadata Single',
    'metadata_raid0' => 'Metadata RAID0',
    'metadata_raid1' => 'Metadata RAID1',
    'metadata_raid1c3' => 'Metadata RAID1c3',
    'metadata_raid1c4' => 'Metadata RAID1c4',
    'metadata_raid5' => 'Metadata RAID5',
    'metadata_raid6' => 'Metadata RAID6',
    'metadata_raid10' => 'Metadata RAID10',
    'metadata_dup' => 'Metadata DUP',
    'system_data' => 'System Data',
    'system_linear' => 'System Linear',
    'system_single' => 'System Single',
    'system_raid0' => 'System RAID0',
    'system_raid1' => 'System RAID1',
    'system_raid1c3' => 'System RAID1c3',
    'system_raid1c4' => 'System RAID1c4',
    'system_raid5' => 'System RAID5',
    'system_raid6' => 'System RAID6',
    'system_raid10' => 'System RAID10',
    'system_dup' => 'System DUP',
];

$format_display_name = static function (string $key): string {
    $name = preg_replace('/_/', ' ', $key);
    $name = ucwords($name);

    return $name;
};

if ($width > 500) {
    $descr_len = 13;
} else {
    $descr_len = 8;
    $descr_len += round(($width - 250) / 8);
}

$fs_uuid = is_string($vars['fs'] ?? null) ? $vars['fs'] : null;
$discovery_fs = \LibreNMS\Plugins\Btrfs\btrfs_get_discovery_by_uuid($app, $fs_uuid);
$fs_entry = $fs_uuid !== null ? ($app->data['tables']['filesystems'][$fs_uuid] ?? null) : null;

$normalize_profile_rows = static function (array $profiles): array {
    $normalized = [];

    if (! is_array($profiles)) {
        return $normalized;
    }

    $is_assoc = array_keys($profiles) !== range(0, count($profiles) - 1);
    if ($is_assoc) {
        foreach ($profiles as $profile_key => $profile_value) {
            if (! is_string($profile_key) || ! is_numeric($profile_value)) {
                continue;
            }
            $normalized[$profile_key] = (float) $profile_value;
        }

        return $normalized;
    }

    foreach ($profiles as $row) {
        if (! is_array($row)) {
            continue;
        }
        $profile_key = $row['profile'] ?? null;
        $profile_value = $row['bytes'] ?? null;
        if (! is_string($profile_key) || ! is_numeric($profile_value)) {
            continue;
        }
        $normalized[$profile_key] = (float) $profile_value;
    }

    return $normalized;
};

$fs_types = is_array($fs_entry) ? $normalize_profile_rows($fs_entry['profiles'] ?? []) : [];
if (count($fs_types) === 0 && $fs_uuid !== null) {
    $tables_profiles = $app->data['tables']['filesystem_profiles'][$fs_uuid] ?? [];
    $fs_types = $normalize_profile_rows($tables_profiles);
}

if (empty($fs_types)) {
    return;
}

$colours = 'psychedelic';
$rrd_list = [];
$colour_index = 0;
$fs_rrd_id = is_array($discovery_fs) ? ($discovery_fs['rrd_key'] ?? $vars['fs']) : $vars['fs'];

foreach ($fs_types as $type_key => $type_value) {
    $type_id = $safe_id((string) $type_key);
    $rrd_filename = App\Facades\Rrd::name($device['hostname'], ['app', 'btrfs', $app->app_id, $fs_rrd_id, 'type_' . $type_id]);
    $ds_name = 'value';

    $descr = $known_types[$type_key] ?? $format_display_name($type_key);

    $colour = App\Facades\LibrenmsConfig::get("graph_colours.$colours.$colour_index");
    $colour_index++;
    if ($colour === null) {
        $colour = '888888';
    }

    $rrd_list[] = [
        'filename' => $rrd_filename,
        'descr' => $descr,
        'ds' => $ds_name,
        'colour' => $colour,
    ];
}

if (empty($rrd_list)) {
    return;
}

if ($width > 500) {
    $rrd_options[] = 'COMMENT:' . substr(str_pad($unit_text, $descr_len + 5), 0, $descr_len + 5) . '      Now      Min      Max     Avg\l';
} else {
    $rrd_options[] = 'COMMENT:' . substr(str_pad($unit_text, $descr_len + 5), 0, $descr_len + 5) . "      Now      Min      Max     Avg\l";
}

$area_cmds = [];
$legend_cmds = [];

foreach ($rrd_list as $i => $rrd) {
    $ds = $rrd['ds'];
    $filename = $rrd['filename'];
    $descr = LibreNMS\Data\Store\Rrd::fixedSafeDescr($rrd['descr'], $descr_len);
    $colour = $rrd['colour'];

    $id = 'ds' . $i;

    $rrd_options[] = "DEF:$id=$filename:$ds:AVERAGE";
    $rrd_options[] = "DEF:{$id}min=$filename:$ds:MIN";
    $rrd_options[] = "DEF:{$id}max=$filename:$ds:MAX";

    $stack = $i > 0 ? ':STACK' : '';

    $area_cmds[] = 'AREA:' . $id . '#' . $colour . 'aa' . $stack;
    $legend_cmds[] = 'LINE1.25:' . $id . '#' . $colour . ':' . $descr . $stack;
    $legend_cmds[] = 'GPRINT:' . $id . ':LAST:%5.2lf%s';
    $legend_cmds[] = 'GPRINT:' . $id . 'min:MIN:%5.2lf%s';
    $legend_cmds[] = 'GPRINT:' . $id . 'max:MAX:%5.2lf%s';
    $legend_cmds[] = 'GPRINT:' . $id . ':AVERAGE:%5.2lf%s\\n';
}

foreach ($area_cmds as $cmd) {
    $rrd_options[] = $cmd;
}
foreach ($legend_cmds as $cmd) {
    $rrd_options[] = $cmd;
}

$rrd_options[] = 'HRULE:0#555555';
