<?php

use App\Facades\Rrd;
use App\Models\Sensor;
use Illuminate\Support\Facades\DB;
use LibreNMS\Agent\Unix\Smart\HtmlData;

require_once base_path('includes/html/debug-panel.inc.php');

// =============================================================================
// Debug panel (admin only)
// =============================================================================

/**
 * Build RRD debug entries for every disk: the single per-disk attribute RRD the
 * poller writes (temperature/health/wear are sensors, not app RRDs).
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
        $disk = $data->disk($diskKey);
        $label = $data->deviceLabel($disk);
        $isNvme = $data->isNvme($disk);

        // The poller only ever writes ['app','smart',...] for ATA/SATA disks and
        // ['app','smart_nvme',...] for NVMe disks — never both — so pick the kind
        // that actually matches this disk.
        foreach ([
            ($isNvme ? 'nvme_health' : 'attributes') => ['app', $isNvme ? 'smart_nvme' : 'smart', $appId, $idx],
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
 * Build debug panels for all smart_* DB tables for this app.
 *
 * Per-disk tables are filtered to $selectedDisk when one is active.
 * Returns an array of rendered panel HTML strings, one per table.
 */
function smart_debug_db_panels(HtmlData $data, ?string $selectedDisk): array
{
    $appId = (int) $data->app->app_id;
    $diskKey = $selectedDisk !== null && $data->disk($selectedDisk) !== null
        ? $selectedDisk : null;

    $panels = [];

    // Each panel is wrapped with its disk-kind ('common'|'sata'|'nvme') so the
    // "only show relevant tables" toggle in smart_debug_render() can hide the
    // ones that don't apply to the currently selected disk.
    $wrap = static fn (string $kind, string $html): string => '<div data-table-kind="' . $kind . '">' . $html . '</div>';

    // ── App-level tables ────────────────────────────────────────────────────

    $appState = DB::table('smart_app_state')->where('app_id', $appId)->get()->map(fn ($r) => (array) $r)->all();
    $panels[] = $wrap('common', debug_db_table_panel('smart_app_state', $appState, "smart_app_state-{$appId}.csv"));

    // smart_sata_change is keyed by device_idx (the smartmonDeviceTable SNMP index),
    // not disk_key — resolve it via smart_devices.snmp_index for the selected disk.
    $changeQuery = DB::table('smart_sata_change')->where('app_id', $appId);
    if ($diskKey !== null) {
        $snmpIndex = DB::table('smart_devices')->where('app_id', $appId)->where('disk_key', $diskKey)->value('snmp_index');
        $changeQuery->where('device_idx', $snmpIndex !== null ? (int) $snmpIndex : -1);
    }
    $changes = $changeQuery->orderBy('device_idx')->orderBy('table_id')->get()->map(fn ($r) => (array) $r)->all();
    $panels[] = $wrap('sata', debug_db_table_panel('smart_sata_change', $changes, "smart_sata_change-{$appId}.csv"));

    // ── Per-disk tables (shared query builder) ──────────────────────────────

    $diskTables = [
        'smart_devices'              => ['common', null],
        'smart_sata_info'            => ['sata', null],
        'smart_sata_health'          => ['sata', null],
        'smart_sata_attributes'      => ['sata', 'attribute_id'],
        'smart_sata_selftest_log'    => ['sata', 'entry_num'],
        'smart_sata_error_log'       => ['sata', 'entry_num'],
        'smart_sata_error_cmd'       => ['sata', ['error_entry_num', 'cmd_slot']],
        'smart_sata_dev_stats'       => ['sata', ['page_num', 'stat_offset']],
        'smart_sata_phy_events'      => ['sata', 'event_id'],
        'smart_sata_erc'             => ['sata', 'direction'],
        'smart_sata_pending_defects' => ['sata', 'entry_num'],
        'smart_sata_log_dir'         => ['sata', 'log_address'],
        'smart_sata_selective_test'  => ['sata', 'slot'],
        'smart_nvme_info'            => ['nvme', null],
        'smart_nvme_health'          => ['nvme', null],
        'smart_nvme_namespaces'      => ['nvme', 'ns_id'],
        'smart_nvme_power_states'    => ['nvme', 'state_id'],
        'smart_nvme_lba_formats'     => ['nvme', ['ns_id', 'format_id']],
        'smart_nvme_selftest_log'    => ['nvme', 'entry_num'],
        'smart_nvme_error_log'       => ['nvme', 'entry_num'],
        'smart_nvme_capability'      => ['nvme', null],
    ];

    foreach ($diskTables as $table => [$tableKind, $orderBy]) {
        $query = DB::table($table)->where('app_id', $appId);
        if ($diskKey !== null) {
            $query->where('disk_key', $diskKey);
        }
        foreach ((array) $orderBy as $col) {
            $query->orderBy($col);
        }
        $rows = $query->get()->map(fn ($r) => (array) $r)->all();

        $title = $diskKey !== null ? "{$table} ({$diskKey})" : $table;
        $csvFile = $diskKey !== null
            ? "{$table}-{$appId}-" . substr($diskKey, 0, 40) . '.csv'
            : "{$table}-{$appId}.csv";

        $panels[] = $wrap($tableKind, debug_db_table_panel($title, $rows, $csvFile));
    }

    return $panels;
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

    // 4. DB tables, with a toggle to hide tables that don't apply to the
    // selected disk's kind (e.g. hide smart_sata_* when viewing an NVMe drive).
    $dbPanels = smart_debug_db_panels($data, $selectedDisk);

    $diskKind = $diskKey !== null ? ($data->disk($diskKey)['kind'] ?? null) : null;
    $tableFilterHtml = '';
    if ($diskKind !== null) {
        $kindEsc = htmlspecialchars($diskKind, ENT_QUOTES);
        $tableFilterHtml = '<label style="font-weight:normal;font-size:12px">'
            . '<input type="checkbox" id="smart-debug-table-filter" data-kind="' . $kindEsc . '" checked '
            . 'onchange="smartDebugTableFilter(this)"> only show ' . $kindEsc . ' + common tables</label>'
            . '<script>
function smartDebugTableFilter(cb) {
    var kind = cb.dataset.kind;
    document.querySelectorAll("#smart-debug-panels [data-table-kind]").forEach(function (el) {
        var k = el.dataset.tableKind;
        el.style.display = (!cb.checked || k === kind || k === "common") ? "" : "none";
    });
}
(function () { var cb = document.getElementById("smart-debug-table-filter"); if (cb) smartDebugTableFilter(cb); })();
</script>';
    }

    debug_render('smart-debug-panels', $dataPanel, $rrdPanel, $sensorPanel, $tableFilterHtml, ...$dbPanels);
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

echo view('device.apps.smart.index', [
    'data'         => $htmlData,
    'app'          => $app,
    'device'       => $device,
    'selectedDisk' => $vars['disk'] ?? null,
])->render();
