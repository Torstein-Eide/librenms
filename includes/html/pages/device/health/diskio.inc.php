<?php

use App\Models\DiskIo;

/** @var array{device_id: int|string, os_group?: string|null, sysDescr?: string|null} $device */

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

// Aggregate panels: one chart per graph type combining all filtered drives.
$filteredIds = $filteredDrives->pluck('diskio_id')->all();

if (! empty($filteredIds)) {
    $idsParam = implode(',', $filteredIds);
    $aggregateGraphTypes = [
        'diskio_bits' => 'bps',
        'diskio_ops'  => 'Ops/sec',
    ];

    array_walk($aggregateGraphTypes, function (string $unitLabel, string $graph_type) use ($idsParam): void {
        $graph_array = [
            'type' => $graph_type,
            'ids'  => $idsParam,
        ];
        $graph_title = "All Drives - $unitLabel";

        echo "<div class='panel panel-default'>
                <div class='panel-heading'>
                <h3 class='panel-title'>$graph_title</h3>
            </div>";
        echo "<div class='panel-body'>";
        include 'includes/html/print-graphrow.inc.php';
        echo '</div></div>';
    });
}

echo '<h2>Per Drive</h2>';

$filteredDrives->each(function ($drive) use (&$row, $selectedDiskioView, $selectedDiskioSubtype, $device): void {
    if (is_int($row / 2)) {
        $row_colour = App\Facades\LibrenmsConfig::get('list_colour.even');
    } else {
        $row_colour = App\Facades\LibrenmsConfig::get('list_colour.odd');
    }
    unset($row_colour);

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

    // Each matching drive renders throughput and operations graph panels.
    $graphTypes = ['diskio_bits', 'diskio_ops'];
    array_walk($graphTypes, function (string $graph_type) use ($drive, $overlib_link): void {
        $graph_array = [];
        $graph_array['id'] = $drive['diskio_id'];
        $graph_array['type'] = $graph_type;

        echo "<div class='panel panel-default'>
                <div class='panel-heading'>
                <h3 class='panel-title'>$overlib_link - " . ($graph_type === 'diskio_ops' ? 'Ops/sec' : 'bps') . '</h3>
            </div>';
        echo "<div class='panel-body'>";
        include 'includes/html/print-graphrow.inc.php';
        echo '</div></div>';
    });

    $row++;
});
