<?php

use App\Facades\Rrd;
use App\Models\DiskIo;
use LibreNMS\Util\Number;

/** @var array{device_id: int|string, hostname?: string|null, os_group?: string|null, sysDescr?: string|null} $device */

/*
 * File structure:
 * 1) Resolve selected diskio view/subtype and UI option lists.
 * 2) Fetch drives, classify once per disk via DiskTypeFilter, and collect active subtypes.
 * 3) Render option bars and descriptive text for current selection.
 * 4) Render filtered drive graphs using pre-classified drive types.
 */

$diskioViews = [
    'physical' => 'Physical Drives',
    'logical' => 'Logical Drives',
    'all' => 'All Drives',
];

$selection = LibreNMS\Util\DiskTypeFilter::normalizeSelection($vars['diskio_view'] ?? null, $vars['diskio_subtype'] ?? null);
$selectedDiskioView = $selection['view'];
$selectedDiskioSubtype = $selection['subtype'];

$diskioSubtypes = [
    'physical' => [
        'all' => 'All',
        'sd_family' => 'SATA/SCSI/Virtual',
        'nvme' => 'NVMe Drives',
        'mmcblk' => 'MMC/SD Drives',
        'memory' => 'Memory',
        'other' => 'Other',
    ],
    'logical' => [
        'all' => 'All',
        'partitions' => 'Partitions',
        'dm' => 'Device Mapper',
        'sw_raid' => 'Software RAID',
        'loop' => 'Image',
        'caching' => 'Caching',
        'other' => 'Other',
    ],
];

$diskioLinkArray = [
    'page' => 'device',
    'device' => $device['device_id'],
    'tab' => 'health',
    'metric' => 'diskio',
];

// Pre-classify all drives to determine which subtypes have matching devices
$drives = DiskIo::query()->where('device_id', $device['device_id'])->orderBy('diskio_descr')->get();
$driveTypes = LibreNMS\Util\DiskTypeFilter::classify(
    $drives->pluck('diskio_descr', 'diskio_id')->all(),
    $device['os_group'] ?? null,
    ['os_or_sys_descr' => $device['sysDescr'] ?? null]
);

// Track which subtype keys exist in the current dataset to hide empty filter tabs.
$activeSubtypes = [
    'physical' => ['all' => true],
    'logical' => ['all' => true],
];

// Build active subtype map from the pre-classified drive type results.
$drives->each(function ($drive) use (&$activeSubtypes, $driveTypes): void {
    $driveType = $driveTypes[$drive['diskio_id']];
    $view = $driveType['view'];
    $subtype = $driveType['subtype'];

    if (isset($activeSubtypes[$view])) {
        $activeSubtypes[$view][$subtype] = true;
    }
});

// Filter subtypes to only show those with matching drives (keep 'all' always visible)
$viewsToFilter = ['physical', 'logical'];
array_walk($viewsToFilter, function (string $view) use (&$diskioSubtypes, $activeSubtypes): void {
    $diskioSubtypes[$view] = array_filter(
        $diskioSubtypes[$view],
        fn (string $label, string $subtype): bool => $subtype === 'all' || $subtype === 'other' || isset($activeSubtypes[$view][$subtype]),
        ARRAY_FILTER_USE_BOTH
    );
});

print_optionbar_start();
echo "<span style='font-weight: bold;'>Drives</span> &#187; ";
$sep = '';

// Render top-level drive view selector (physical/logical/all).
array_walk($diskioViews, function (string $text, string $diskioView) use (&$sep, $selectedDiskioView, $diskioLinkArray): void {
    echo $sep;
    if ($selectedDiskioView == $diskioView) {
        echo '<span class="pagemenu-selected">';
    }

    echo generate_link($text, $diskioLinkArray, ['diskio_view' => $diskioView]);
    if ($selectedDiskioView == $diskioView) {
        echo '</span>';
    }

    $sep = ' | ';
});

if (in_array($selectedDiskioView, ['physical', 'logical'], true) && count($diskioSubtypes[$selectedDiskioView]) > 1) {
    echo '<br><span style="padding-left: 22px;"><strong>Type</strong> &#187; ';
    $sep = '';

    // Render subtype selector for the selected view when multiple subtype tabs are available.
    array_walk($diskioSubtypes[$selectedDiskioView], function (string $text, string $diskioSubtype) use (&$sep, $selectedDiskioSubtype, $selectedDiskioView, $diskioLinkArray): void {
        echo $sep;
        if ($selectedDiskioSubtype == $diskioSubtype) {
            echo '<span class="pagemenu-selected">';
        }

        echo generate_link($text, $diskioLinkArray, ['diskio_view' => $selectedDiskioView, 'diskio_subtype' => $diskioSubtype]);
        if ($selectedDiskioSubtype == $diskioSubtype) {
            echo '</span>';
        }

        $sep = ' | ';
    });
    echo '</span>';
}

print_optionbar_end();

$viewDescriptions = [
    'physical' => 'Physical drives are whole block devices (for example sda, nvme0n1, mmcblk0, da0, ad0).',
    'logical' => 'Logical drives are partitions and virtual devices (for example sda1, nvme0n1p1, dm-0, md0, loop0).',
    'all' => 'All drives shows both physical and logical disk I/O entries.',
];

$subtypeDescriptions = [
    'physical' => [
        'all' => 'All physical device families.',
        'sd_family' => 'Classic disk families: sd*, hd*, vd*, xvd*, da*, ad*.',
        'nvme' => 'NVMe namespaces such as nvme0n1.',
        'mmcblk' => 'MMC and SD block devices such as mmcblk0.',
        'memory' => 'Memory-backed block devices such as ram0, zram0.',
        'other' => 'Physical drives that do not match a known family.',
    ],
    'logical' => [
        'all' => 'All logical device types.',
        'partitions' => 'Disk partitions such as sda1, nvme0n1p1, and mmcblk0p1.',
        'dm' => 'Device mapper volumes named dm-*.',
        'sw_raid' => 'Software RAID devices (for example md0 on Linux, ccd* on BSD).',
        'loop' => 'Image-backed loop devices such as loop0.',
        'caching' => 'Caching layers such as bcache0.',
        'other' => 'Logical drives that do not match a known type.',
    ],
];

echo '<div style="padding: 6px 0 10px 0; color: #777;">';
echo $viewDescriptions[$selectedDiskioView];
if (isset($subtypeDescriptions[$selectedDiskioView][$selectedDiskioSubtype])) {
    echo ' ' . $subtypeDescriptions[$selectedDiskioView][$selectedDiskioSubtype];
}
echo '</div>';

$row = 1;

// Render graphs only for drives matching the selected view/subtype filters.
$filteredDrives = $drives->filter(function ($drive) use ($driveTypes, $selectedDiskioView, $selectedDiskioSubtype): bool {
    $driveType = $driveTypes[$drive['diskio_id']];

    return LibreNMS\Util\DiskTypeFilter::matches($driveType, $selectedDiskioView, $selectedDiskioSubtype);
});

// Shared graph metadata keeps DS names, label text, and value formatting in one place.
$diskioGraphMeta = [
    'diskio_bytes' => [
        'label' => 'Bytes/sec',
        'rrd_base' => 'ucd_diskio',
        'datasets' => ['read', 'written'],
        'aggregate' => true,
        'type' => 'in_out',
        'in_ds' => 'read',
        'out_ds' => 'written',
        'multiplier' => 1,
        'suffix' => 'B/s',
        'format' => 'bi',
    ],
    'diskio_ops' => [
        'label' => 'Ops/sec',
        'rrd_base' => 'ucd_diskio',
        'datasets' => ['reads', 'writes'],
        'aggregate' => true,
        'type' => 'in_out',
        'in_ds' => 'reads',
        'out_ds' => 'writes',
        'multiplier' => 1,
        'suffix' => 'ops/s',
        'format' => 'si',
    ],
    'diskio_load' => [
        'label' => 'Load Average',
        'rrd_base' => 'ucd_diskio',
        'datasets' => ['la1', 'la5', 'la15'],
        'aggregate' => false,
        'type' => 'load',
    ],
    'diskio_busy' => [
        'label' => 'Busy Time',
        'rrd_base' => 'ucd_diskio',
        'datasets' => ['busy_usec'],
        'aggregate' => false,
        'type' => 'busy',
    ],
];

// Format current values for panel headers by graph dataset type.
$formatPercent = static function (int|float|null $value): string {
    if (! is_numeric($value)) {
        return '--';
    }

    return number_format((float) $value, 1, '.', '') . '%';
};

$formatDiskIoCurrent = static function (array $currentValues, string $graphType) use ($diskioGraphMeta, $formatPercent): string {
    $meta = $diskioGraphMeta[$graphType];

    if ($meta['type'] === 'load') {
        return '1m: ' . $formatPercent($currentValues['la1'] ?? null)
            . ' | 5m: ' . $formatPercent($currentValues['la5'] ?? null)
            . ' | 15m: ' . $formatPercent($currentValues['la15'] ?? null);
    }

    if ($meta['type'] === 'busy') {
        $busyUsec = $currentValues['busy_usec'] ?? null;
        $busyPercent = is_numeric($busyUsec) ? (float) $busyUsec / 10000 : null;

        return 'Busy: ' . $formatPercent($busyPercent);
    }

    $inValue = $currentValues[$meta['in_ds']] ?? null;
    $outValue = $currentValues[$meta['out_ds']] ?? null;

    $inCurrent = '--';
    if (is_numeric($inValue)) {
        $inCurrent = $meta['format'] === 'bi'
            ? Number::formatBi((float) $inValue * $meta['multiplier'], 2, 0, $meta['suffix'])
            : Number::formatSi((float) $inValue * $meta['multiplier'], 2, 0, $meta['suffix']);
    }

    $outCurrent = '--';
    if (is_numeric($outValue)) {
        $outCurrent = $meta['format'] === 'bi'
            ? Number::formatBi((float) $outValue * $meta['multiplier'], 2, 0, $meta['suffix'])
            : Number::formatSi((float) $outValue * $meta['multiplier'], 2, 0, $meta['suffix']);
    }

    return "In: $inCurrent | Out: $outCurrent";
};

$driveCurrentValues = [];
$aggregateCurrentValues = [];

// Build one DS list per RRD base and reuse it for every drive lookup.
$diskioDatasetsByRrd = [];
foreach ($diskioGraphMeta as $meta) {
    $rrdBase = $meta['rrd_base'];
    $diskioDatasetsByRrd[$rrdBase] ??= [];
    $diskioDatasetsByRrd[$rrdBase] = array_values(array_unique(array_merge($diskioDatasetsByRrd[$rrdBase], $meta['datasets'])));
}

if (! empty($device['hostname'])) {
    foreach ($filteredDrives as $drive) {
        $diskId = (int) $drive['diskio_id'];
        $currentByRrd = [];

        foreach ($diskioDatasetsByRrd as $rrdBase => $datasets) {
            $rrdFilename = Rrd::name($device['hostname'], [$rrdBase, $drive['diskio_descr']]);
            if (! Rrd::checkRrdExists($rrdFilename)) {
                continue;
            }

            $existingDatasets = array_flip(Rrd::listDatasets($rrdFilename));
            $availableDatasets = array_values(array_filter($datasets, static fn (string $dataset): bool => isset($existingDatasets[$dataset])));
            if (empty($availableDatasets)) {
                continue;
            }

            // Read aligned rate values once per RRD file.
            $point = Rrd::getLastRates($rrdFilename, $availableDatasets);
            if ($point === null) {
                continue;
            }

            foreach ($availableDatasets as $dataset) {
                $value = $point->get($dataset);
                if (is_numeric($value)) {
                    $currentByRrd[$rrdBase][$dataset] = (float) $value;
                }
            }
        }

        foreach ($diskioGraphMeta as $graphType => $meta) {
            $rrdCurrent = $currentByRrd[$meta['rrd_base']] ?? [];
            $graphCurrent = [];

            foreach ($meta['datasets'] as $dataset) {
                if (isset($rrdCurrent[$dataset])) {
                    $graphCurrent[$dataset] = $rrdCurrent[$dataset];
                }
            }

            if (! empty($graphCurrent)) {
                $driveCurrentValues[$diskId][$graphType] = $graphCurrent;
            }

            if ($meta['aggregate']) {
                foreach ($graphCurrent as $dataset => $value) {
                    $aggregateCurrentValues[$graphType][$dataset] = ($aggregateCurrentValues[$graphType][$dataset] ?? 0.0) + (float) $value;
                }
            }
        }
    }
}

// Summary table: two header rows - group label + per-DS sub-headers, graph column last per group.
$tableConfig = [
    'diskio_load' => [
        'label' => 'Load Avg',
        'cols'  => [
            ['header' => '1m',  'ds' => 'la1',  'fmt' => fn ($v) => number_format((float) $v, 1) . '%'],
            ['header' => '5m',  'ds' => 'la5',  'fmt' => fn ($v) => number_format((float) $v, 1) . '%'],
            ['header' => '15m', 'ds' => 'la15', 'fmt' => fn ($v) => number_format((float) $v, 1) . '%'],
        ],
    ],
    'diskio_busy' => [
        'label' => 'Busy',
        'cols'  => [
            ['header' => '%', 'ds' => 'busy_usec', 'fmt' => fn ($v) => number_format((float) $v / 10000, 1) . '%'],
        ],
    ],
    'diskio_bytes' => [
        'label' => 'Bytes/sec',
        'cols'  => [
            ['header' => 'Read',  'ds' => 'read',    'fmt' => fn ($v) => Number::formatBi((float) $v, 2, 0, 'B/s')],
            ['header' => 'Write', 'ds' => 'written', 'fmt' => fn ($v) => Number::formatBi((float) $v, 2, 0, 'B/s')],
        ],
    ],
    'diskio_ops' => [
        'label' => 'Ops/sec',
        'cols'  => [
            ['header' => 'Read',  'ds' => 'reads',  'fmt' => fn ($v) => Number::formatSi((float) $v, 2, 0, 'ops/s')],
            ['header' => 'Write', 'ds' => 'writes', 'fmt' => fn ($v) => Number::formatSi((float) $v, 2, 0, 'ops/s')],
        ],
    ],
];

if ($filteredDrives->isNotEmpty()) {
    echo '<table class="table table-hover table-condensed">';

    // Row 1: group labels
    echo '<thead><tr><th rowspan="2">Drive</th>';
    foreach ($tableConfig as $graph_type => $group) {
        $colspan = count($group['cols']) + 1; // value cols + graph col
        echo '<th colspan="' . $colspan . '">' . $group['label'] . '</th>';
    }
    echo '</tr>';

    // Row 2: per-DS sub-headers + Graph column per group
    echo '<tr>';
    foreach ($tableConfig as $group) {
        foreach ($group['cols'] as $col) {
            echo '<th>' . $col['header'] . '</th>';
        }
        echo '<th>Graph</th>';
    }
    echo '</tr></thead><tbody>';

    $filteredDrives->each(function ($drive) use ($tableConfig, $driveCurrentValues): void {
        echo '<tr>';
        echo '<td>' . htmlentities((string) $drive['diskio_descr']) . '</td>';

        foreach ($tableConfig as $graph_type => $group) {
            $values = $driveCurrentValues[$drive['diskio_id']][$graph_type] ?? [];

            foreach ($group['cols'] as $col) {
                $raw = $values[$col['ds']] ?? null;
                $cell = is_numeric($raw) ? ($col['fmt'])($raw) : '--';
                echo '<td><small>' . $cell . '</small></td>';
            }

            $graph_array = [
                'id'     => $drive['diskio_id'],
                'type'   => $graph_type,
                'width'  => '210',
                'height' => '100',
                'to'     => App\Facades\LibrenmsConfig::get('time.now'),
                'legend' => 'no',
            ];
            $overlib_content = generate_overlib_content($graph_array, $drive['diskio_descr'] . ' - ' . $group['label']);

            $link_array = array_merge($graph_array, ['page' => 'graphs']);
            unset($link_array['width'], $link_array['height'], $link_array['legend']);
            $link = LibreNMS\Util\Url::generate($link_array);

            $graph_array['width'] = '100';
            $graph_array['height'] = '20';
            $graph_array['bg'] = 'ffffff00';
            $minigraph = LibreNMS\Util\Url::lazyGraphTag($graph_array);

            echo '<td>' . LibreNMS\Util\Url::overlibLink($link, $minigraph, $overlib_content) . '</td>';
        }

        echo '</tr>';
    });

    echo '</tbody></table>';
}

// Aggregate graphs: one panel per metric across all filtered drives.
$filteredIds = $filteredDrives->pluck('diskio_id')->all();
$aggregateGraphTypes = array_keys(array_filter($diskioGraphMeta, static fn (array $meta): bool => $meta['aggregate']));

if (! empty($filteredIds)) {
    $idsParam = implode(',', $filteredIds);

    foreach ($aggregateGraphTypes as $graph_type) {
        $meta = $diskioGraphMeta[$graph_type];
        $graph_array = ['type' => $graph_type, 'ids' => $idsParam];
        $currentText = $formatDiskIoCurrent($aggregateCurrentValues[$graph_type] ?? [], $graph_type);

        echo "<div class='panel panel-default'>
                <div class='panel-heading'>
                    <h3 class='panel-title'>" . $meta['label'] . " <span class='pull-right'>$currentText</span></h3>
                </div>
                <div class='panel-body'>";
        include 'includes/html/print-graphrow.inc.php';
        echo '</div></div>';
    }

    $loadBusyGraphs = [
        ['label' => 'Load Average / Busy Time', 'type' => 'diskio_load_busy', 'extra' => []],
    ];

    foreach ($loadBusyGraphs as $graphDef) {
        $graph_array = array_merge(['type' => $graphDef['type'], 'ids' => $idsParam], $graphDef['extra']);

        echo "<div class='panel panel-default'>
                <div class='panel-heading'>
                    <h3 class='panel-title'>" . $graphDef['label'] . '</h3>
                </div>
                <div class=\'panel-body\'>';
        include 'includes/html/print-graphrow.inc.php';
        echo '</div></div>';
    }
}

echo '<h2>Per Drive</h2>';

$filteredDrives->each(function ($drive) use (&$row, $selectedDiskioView, $selectedDiskioSubtype, $device, $diskioGraphMeta, $driveCurrentValues, $formatDiskIoCurrent): void {
    $fs_url = 'device/device=' . $device['device_id'] . '/tab=health/metric=diskio/';
    if ($selectedDiskioView !== 'all') {
        $fs_url .= 'diskio_view=' . $selectedDiskioView . '/';
        if ($selectedDiskioSubtype !== 'all') {
            $fs_url .= 'diskio_subtype=' . $selectedDiskioSubtype . '/';
        }
    }

    $graph_array_zoom = [
        'id' => $drive['diskio_id'],
        'type' => 'diskio_ops',
        'width' => 400,
        'height' => 125,
        'from' => App\Facades\LibrenmsConfig::get('time.twoday'),
        'to' => App\Facades\LibrenmsConfig::get('time.now'),
    ];

    $overlib_link = LibreNMS\Util\Url::overlibLink($fs_url, $drive['diskio_descr'], LibreNMS\Util\Url::graphTag($graph_array_zoom));

    echo "<div class='panel panel-default'>
            <div class='panel-heading'>
                <h3 class='panel-title'>$overlib_link</h3>
            </div>";
    echo "<div class='panel-body'>";

    foreach ($diskioGraphMeta as $graph_type => $meta) {
        $graph_array = [
            'id' => $drive['diskio_id'],
            'type' => $graph_type,
        ];
        $currentText = $formatDiskIoCurrent($driveCurrentValues[$drive['diskio_id']][$graph_type] ?? [], $graph_type);

        echo "<div class='panel panel-default'>
                <div class='panel-heading'>
                    <h3 class='panel-title'>" . $meta['label'] . " <span class='pull-right'>$currentText</span></h3>
                </div>
                <div class='panel-body'>";
        include 'includes/html/print-graphrow.inc.php';
        echo '</div></div>';
    }

    echo '</div></div>';

    $row++;
});
