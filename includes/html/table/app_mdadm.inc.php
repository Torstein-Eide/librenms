<?php

$first = ($vars['current'] - 1) * $vars['rowCount'];
$showAll = $vars['rowCount'] == -1;
$search = (string) ($vars['searchPhrase'] ?? '');

$query = App\Models\MdadmArray::with(['application.device']);

if ($search !== '') {
    $query->where(function ($q) use ($search) {
        $q->where('name', 'like', "%{$search}%")
            ->orWhere('level', 'like', "%{$search}%")
            ->orWhere('state', 'like', "%{$search}%")
            ->orWhere('sync_action', 'like', "%{$search}%")
            ->orWhereHas('application.device', fn ($d) => $d->where('hostname', 'like', "%{$search}%"));
    });
}

$total = $query->count();
$rows = $showAll ? $query->get() : $query->skip($first)->take($vars['rowCount'])->get();
$response = [];

foreach ($rows as $arr) {
    $dev = $arr->application->device ?? null;
    $deviceLink = $dev
        ? generate_device_link($dev->toArray(), null, ['tab' => 'apps', 'app' => 'mdadm'])
        : '?';
    $arrUrl = $dev ? LibreNMS\Util\Url::generate([
        'page'   => 'device',
        'device' => $dev->device_id,
        'tab'    => 'apps',
        'app'    => 'mdadm',
        'array'  => $arr->name,
    ]) : '#';
    $sizeStr = ($arr->size_bytes ?? 0) > 0
        ? LibreNMS\Util\Number::formatBi((int) $arr->size_bytes)
        : '&mdash;';

    $response[] = [
        'device'         => $deviceLink,
        'name'           => '<a href="' . htmlspecialchars($arrUrl) . '">' . htmlspecialchars((string) ($arr->name ?? $arr->uuid)) . '</a>',
        'level'          => htmlspecialchars((string) ($arr->level ?? '')),
        'state'          => htmlspecialchars((string) ($arr->state ?? '')),
        'sync_action'    => htmlspecialchars((string) ($arr->sync_action ?? 'idle')),
        'raid_disks'     => $arr->raid_disks,
        'active_devices' => $arr->active_devices,
        'spare_devices'  => $arr->spare_devices,
        'failed_devices' => $arr->failed_devices,
        'size'           => $sizeStr,
        'mismatch_cnt'   => $arr->mismatch_cnt,
    ];
}

echo json_encode([
    'current'  => $vars['current'],
    'rowCount' => $vars['rowCount'],
    'rows'     => $response,
    'total'    => $total,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
