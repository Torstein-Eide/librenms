<?php

use App\Models\DiskIo;

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
$activeSubtypes = [
    'physical' => ['all' => true],
    'logical' => ['all' => true],
];

foreach ($drives as $drive) {
    $driveType = LibreNMS\Util\DiskTypeFilter::classify($drive['diskio_descr'], $device['os'] ?? null);
    $view = $driveType['view'];
    $subtype = $driveType['subtype'];

    if (isset($activeSubtypes[$view])) {
        $activeSubtypes[$view][$subtype] = true;
    }
}

// Filter subtypes to only show those with matching drives (keep 'all' always visible)
foreach (['physical', 'logical'] as $view) {
    $filteredSubtypes = [];
    foreach ($diskioSubtypes[$view] as $subtype => $label) {
        if ($subtype === 'all' || $subtype === 'other' || isset($activeSubtypes[$view][$subtype])) {
            $filteredSubtypes[$subtype] = $label;
        }
    }
    $diskioSubtypes[$view] = $filteredSubtypes;
}

print_optionbar_start();
echo "<span style='font-weight: bold;'>Drives</span> &#187; ";
$sep = '';
foreach ($diskioViews as $diskioView => $text) {
    echo $sep;
    if ($selectedDiskioView == $diskioView) {
        echo '<span class="pagemenu-selected">';
    }

    echo generate_link($text, $diskioLinkArray, ['diskio_view' => $diskioView]);
    if ($selectedDiskioView == $diskioView) {
        echo '</span>';
    }

    $sep = ' | ';
}

if (in_array($selectedDiskioView, ['physical', 'logical'], true) && count($diskioSubtypes[$selectedDiskioView]) > 1) {
    echo '<br><span style="padding-left: 22px;"><strong>Type</strong> &#187; ';
    $sep = '';
    foreach ($diskioSubtypes[$selectedDiskioView] as $diskioSubtype => $text) {
        echo $sep;
        if ($selectedDiskioSubtype == $diskioSubtype) {
            echo '<span class="pagemenu-selected">';
        }

        echo generate_link($text, $diskioLinkArray, ['diskio_view' => $selectedDiskioView, 'diskio_subtype' => $diskioSubtype]);
        if ($selectedDiskioSubtype == $diskioSubtype) {
            echo '</span>';
        }

        $sep = ' | ';
    }
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

foreach ($drives as $drive) {
    $driveType = LibreNMS\Util\DiskTypeFilter::classify($drive['diskio_descr'], $device['os'] ?? null);
    if (! LibreNMS\Util\DiskTypeFilter::matches($driveType, $selectedDiskioView, $selectedDiskioSubtype)) {
        continue;
    }

    if (is_int($row / 2)) {
        $row_colour = App\Facades\LibrenmsConfig::get('list_colour.even');
    } else {
        $row_colour = App\Facades\LibrenmsConfig::get('list_colour.odd');
    }

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

    $types = [
        'diskio_bits',
        'diskio_ops',
    ];

    foreach ($types as $graph_type) {
        $graph_array = [];
        $graph_array['id'] = $drive['diskio_id'];
        $graph_array['type'] = $graph_type;
        if ($graph_array['type'] == 'diskio_ops') {
            $graph_type_title = 'Ops/sec';
        }
        if ($graph_array['type'] == 'diskio_bits') {
            $graph_type_title = 'bps';
        }
        echo "<div class='panel panel-default'>
                <div class='panel-heading'>
                <h3 class='panel-title'>$overlib_link - $graph_type_title</h3>
            </div>";
        echo "<div class='panel-body'>";
        include 'includes/html/print-graphrow.inc.php';
        echo '</div></div>';
    }

    $row++;
}

if (isset($vars['debug']) && $vars['debug'] == 1) {
    echo '<h4>Debug Info</h4>';
    echo '<pre>';
    echo 'Device variables:' . PHP_EOL;
    print_r($device);
    echo PHP_EOL . 'URL vars:' . PHP_EOL;
    print_r($vars);
    echo PHP_EOL . 'Selection:' . PHP_EOL;
    print_r($selection);
    echo PHP_EOL . 'diskioSubtypes (filtered):' . PHP_EOL;
    print_r($diskioSubtypes);
    echo PHP_EOL . 'viewDescriptions:' . PHP_EOL;
    print_r($viewDescriptions);
    echo PHP_EOL . 'subtypeDescriptions:' . PHP_EOL;
    print_r($subtypeDescriptions);
    echo PHP_EOL . 'activeSubtypes:' . PHP_EOL;
    print_r($activeSubtypes);
    echo '</pre>';
}
