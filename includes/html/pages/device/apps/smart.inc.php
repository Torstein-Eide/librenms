<?php

use App\Facades\Rrd;
use App\Models\Sensor;
use LibreNMS\Agent\Unix\Smart\HtmlData;

require_once base_path('includes/html/debug-panel.inc.php');

// =============================================================================
// Debug panel (admin only)
// =============================================================================

/**
 * Build RRD debug entries for every disk: the per-disk attribute RRD and the
 * shared power/temperature RRD the poller writes.
 */
function smart_debug_rrd_entries(HtmlData $data): array
{
    $hostname = (string) ($data->device['hostname'] ?? '');
    $appId = (int) $data->app->app_id;
    $entries = [];

    if ($hostname === '') {
        return $entries;
    }

    foreach ($data->diskKeys() as $diskKey) {
        $idx = $data->diskIndex($diskKey);
        $label = $data->deviceLabel($data->disk($diskKey));

        foreach ([
            'attributes' => ['app', 'smart', $appId, $idx],
            'power'      => ['app', 'smart_power', $appId, $idx],
        ] as $kind => $name) {
            $rrdFile = Rrd::name($hostname, $name);
            $entry = [
                'array'    => "{$label} ({$kind})",
                'rrd_file' => $rrdFile,
                'exists'   => Rrd::checkRrdExists($rrdFile),
            ];

            if ($entry['exists']) {
                clearstatcache(true, $rrdFile);
                $entry['file'] = [
                    'size_bytes'  => is_file($rrdFile) ? filesize($rrdFile) : null,
                    'modified_at' => is_file($rrdFile) ? date('c', (int) filemtime($rrdFile)) : null,
                ];
                $point = debug_rrd_last_point($rrdFile);
                if ($point !== null) {
                    $entry['last_update'] = [
                        'timestamp_iso' => date('c', $point->timestamp),
                        'data'          => $point->data,
                    ];
                }
            }

            $entries[] = $entry;
        }
    }

    return $entries;
}

/**
 * Render the SMART debug panels: the smart_* database rows for the selected
 * disk (or all disks), the RRD / datastore state, and the discovered
 * app:smart_mib:* sensors (with an optional current-drive filter).
 */
function smart_debug_render(HtmlData $data, ?string $selectedDisk): void
{
    $user = Auth::user();
    if (! $user || ! $user->hasRole('admin')) {
        return;
    }

    $appId = (int) $data->app->app_id;

    // 1. Selected disk (or all disks) as a pretty-printed JSON blob.
    $diskKey = $selectedDisk !== null && $data->disk($selectedDisk) !== null
        ? $selectedDisk
        : ($data->diskKeys()[0] ?? null);

    $diskJson = htmlspecialchars(json_encode(
        $diskKey !== null ? $data->disk($diskKey) : $data->disks,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) ?: '{}');
    $dataPanel = debug_panel(
        'Debug: Disk Data' . ($diskKey !== null ? ' (' . htmlspecialchars($diskKey) . ')' : ''),
        debug_pre("smart-debug-disk-{$appId}", $diskJson),
        debug_toolbar("smart-debug-disk-{$appId}", "smart-disk-{$appId}.json")
    );

    // 2. RRD / Datastore.
    $stores = [];
    try {
        $datastore = app('Datastore');
        if (method_exists($datastore, 'getStores')) {
            $stores = array_values(array_map(
                static fn ($s) => method_exists($s, 'getName') ? (string) $s->getName() : get_class($s),
                (array) $datastore->getStores()
            ));
        }
    } catch (Throwable) {
    }
    $rrdPanel = debug_panel('Debug: RRD / Datastore', debug_rrd_files_panel(smart_debug_rrd_entries($data), $stores));

    // 3. Sensors (app:smart_mib:*), with a per-disk filter when a drive is selected.
    $sensorRows = '';
    foreach ($data->allSensors->sortBy('sensor_descr') as $s) {
        $sensorRows .= '<tr data-oid="' . htmlspecialchars((string) $s->sensor_oid, ENT_QUOTES) . '">'
            . '<td>' . htmlspecialchars((string) $s->sensor_oid) . '</td>'
            . '<td>' . htmlspecialchars((string) $s->sensor_type) . '</td>'
            . '<td>' . htmlspecialchars((string) $s->group) . '</td>'
            . '<td>' . htmlspecialchars((string) $s->sensor_index) . '</td>'
            . '<td>' . htmlspecialchars((string) $s->sensor_descr) . '</td>'
            . '<td>' . htmlspecialchars((string) $s->sensor_current) . '</td>'
            . '</tr>';
    }
    $sensorCount = $data->allSensors->count();

    $filterHtml = '';
    if ($diskKey !== null) {
        $idxEsc = htmlspecialchars($data->diskIndex($diskKey), ENT_QUOTES);
        $filterHtml = '<label style="font-weight:normal;font-size:12px;margin-bottom:8px">'
            . '<input type="checkbox" id="smart-debug-sensor-filter" data-idx="' . $idxEsc . '" checked '
            . 'onchange="smartDebugSensorFilter(this)"> current drive only</label>';
    }

    $sensorBody = $filterHtml
        . '<table class="table table-condensed table-hover" style="font-size:12px"><thead><tr>'
        . '<th>sensor_oid</th><th>type</th><th>group</th><th>index</th><th>descr</th><th>current</th>'
        . '</tr></thead><tbody>'
        . ($sensorRows ?: '<tr><td colspan="6" class="text-muted">No sensors found.</td></tr>')
        . '</tbody></table>'
        . '<script>
function smartDebugSensorFilter(cb) {
    var prefix = "app:smart_mib:" + cb.dataset.idx + "_";
    document.querySelectorAll("#smart-debug-panels tbody tr[data-oid]").forEach(function (tr) {
        tr.style.display = (!cb.checked || tr.dataset.oid.indexOf(prefix) === 0) ? "" : "none";
    });
}
(function () { var cb = document.getElementById("smart-debug-sensor-filter"); if (cb) smartDebugSensorFilter(cb); })();
</script>';

    $sensorPanel = debug_panel(
        'Debug: Sensors (app:smart_mib:*) &mdash; ' . $sensorCount . ' row(s)',
        $sensorBody
    );

    debug_render('smart-debug-panels', $dataPanel, $rrdPanel, $sensorPanel);
}

// =============================================================================
// Entry point
// =============================================================================

if (! isset($app, $device, $vars)
    || ! $app instanceof App\Models\Application
    || ! is_array($device)
    || ! is_array($vars)) {
    return;
}

$htmlData = HtmlData::forDevice($app, $device);

echo view('device.apps.smart', [
    'data'         => $htmlData,
    'app'          => $app,
    'device'       => $device,
    'selectedDisk' => $vars['disk'] ?? null,
])->render();
