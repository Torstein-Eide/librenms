<?php

$arrayGraphs = [
    'disk_counts' => ['type' => 'mdadm_app',        'metric' => 'disk_counts', 'title' => 'Disk Counts'],
    'mismatch'    => ['type' => 'mdadm_mismatch',                               'title' => 'Mismatch'],
    'sync_bps'    => ['type' => 'mdadm_app',        'metric' => 'sync_bps',    'title' => 'Sync Speed'],
    'sync_pct'    => ['type' => 'mdadm_app',        'metric' => 'sync_pct',    'title' => 'Sync Progress'],
    'diskio_ops'  => ['type' => 'mdadm_diskio_ops',                             'title' => 'Disk I/O Ops'],
    'diskio_bits' => ['type' => 'mdadm_diskio_bits',                            'title' => 'Disk I/O Bytes'],
];

$vars['view'] ??= 'arrays';

// =============================================================================
// Optionbar
// =============================================================================

print_optionbar_start();
echo '<span style="font-weight:bold;">mdadm RAID</span> &#187; ';

if ($vars['view'] === 'arrays') {
    echo "<span class='pagemenu-selected'>";
}
echo generate_link('Arrays', $vars, ['view' => 'arrays']);
if ($vars['view'] === 'arrays') {
    echo '</span>';
}

echo ' | Graphs: ';
$sep = '';
foreach ($arrayGraphs as $key => $spec) {
    echo $sep;
    if ($vars['view'] === $key) {
        echo "<span class='pagemenu-selected'>";
    }
    echo generate_link($spec['title'] . ' (mini)', $vars, ['view' => $key]);
    if ($vars['view'] === $key) {
        echo '</span>';
    }
    $sep = ' | ';
}
unset($sep);

print_optionbar_end();

// =============================================================================
// Views
// =============================================================================

if ($vars['view'] === 'arrays') {
    ?>
    <table id="mdadm-arrays-table" class="table table-condensed table-responsive table-striped">
        <thead>
        <tr>
            <th data-column-id="device">Device</th>
            <th data-column-id="name">Array</th>
            <th data-column-id="level">Level</th>
            <th data-column-id="state">State</th>
            <th data-column-id="sync_action">Operation</th>
            <th data-column-id="raid_disks" data-type="numeric">Disks</th>
            <th data-column-id="active_devices" data-type="numeric">Active</th>
            <th data-column-id="spare_devices" data-type="numeric">Spare</th>
            <th data-column-id="failed_devices" data-type="numeric">Failed</th>
            <th data-column-id="size">Size</th>
            <th data-column-id="mismatch_cnt" data-type="numeric">Mismatches</th>
        </tr>
        </thead>
    </table>
    <script>
        $("#mdadm-arrays-table").bootgrid({
            ajax: true,
            post: function () {
                return {
                    id: "app_mdadm",
                };
            },
            url: "ajax_table.php",
        });
    </script>
    <?php
} else {
    $spec = $arrayGraphs[$vars['view']] ?? null;
    if ($spec !== null) {
        $arrays = App\Models\MdadmArray::with(['application.device'])->get();
        echo '<div style="display:flex;flex-wrap:wrap;gap:12px;padding:8px">';
        foreach ($arrays as $arr) {
            $dev = $arr->application->device ?? null;
            if (! $dev) {
                continue;
            }
            $arrUrl = LibreNMS\Util\Url::generate([
                'page'   => 'device',
                'device' => $dev->device_id,
                'tab'    => 'apps',
                'app'    => 'mdadm',
                'array'  => $arr->name,
            ]);
            $graphArray = [
                'height' => '80',
                'width'  => '180',
                'type'   => $spec['type'],
                'id'     => $arr->app_id,
                'array'  => $arr->name ?? $arr->uuid,
                'from'   => App\Facades\LibrenmsConfig::get('time.day'),
                'to'     => App\Facades\LibrenmsConfig::get('time.now'),
                'legend' => 'no',
            ];
            if (isset($spec['metric'])) {
                $graphArray['metric'] = $spec['metric'];
            }
            $label = htmlspecialchars($dev->hostname . ' / ' . ($arr->name ?? $arr->uuid));
            $graphTag = LibreNMS\Util\Url::lazyGraphTag($graphArray);
            echo '<div class="pull-left" style="margin-right:8px;margin-bottom:8px">'
                . '<div class="text-muted" style="font-size:11px;margin-bottom:4px">' . $label . '</div>'
                . '<a href="' . htmlspecialchars($arrUrl) . '">' . $graphTag . '</a>'
                . '</div>';
        }
        echo '</div>';
    }
}
