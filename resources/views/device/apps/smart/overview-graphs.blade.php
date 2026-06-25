{{-- "Graphs" page: a plain text list of available graph types (temperature/wear/ --}}
{{-- spare/per-attribute). For whichever one is selected, it also shows its all-disk --}}
{{-- aggregate graph plus a per-device breakdown. Only one type is shown at a --}}
{{-- time. Inherits $data, $panelStart, $panelEnd, $smartGraphsUrl, $labelMode --}}
{{-- from the parent smart/index.blade.php. --}}
@php
    /** @var string $graphsDisplayMode */
    /** @var string|null $selectedGraphView */
    use LibreNMS\Util\Url;

    $now   = \App\Facades\LibrenmsConfig::get('time.now');
    $from  = \App\Facades\LibrenmsConfig::get('time.day');
    $appId = $data->app->app_id;

    // Combined sections first (already first by construction order), then per-attribute.
    $sections = [
        ['id' => 'smart-overview-all-temp', 'title' => 'All Temperatures', 'type' => 'all_temp'],
        ['id' => 'smart-overview-all-wear', 'title' => 'Wear Used', 'type' => 'all_wear'],
        ['id' => 'smart-overview-all-spare', 'title' => 'Available Spare', 'type' => 'all_spare'],
        ['id' => 'smart-overview-all-health', 'title' => 'Health', 'type' => 'all_health'],
        ['id' => 'smart-overview-all-selftest-status', 'title' => 'Self-test Status', 'type' => 'all_selftest_status'],
        ['id' => 'smart-overview-all-selftest-short', 'title' => 'Self-test Age (Short)', 'type' => 'all_selftest_short'],
        ['id' => 'smart-overview-all-selftest-long', 'title' => 'Self-test Age (Extended)', 'type' => 'all_selftest_long'],
        ['id' => 'smart-overview-all-power-state', 'title' => 'Power State', 'type' => 'all_power_state'],
    ];
    foreach ($data->overviewAttributeIds() as $attr) {
        // Vendor-defined attribute IDs can mean different things on different
        // disks, so the section id includes a name slug. The same numeric ID with
        // a different name becomes its own separate entry/graph, never merged.
        $nameSlug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($attr['raw_name'])), '-');
        $sections[] = [
            'id'        => 'smart-overview-attr-' . $attr['id'] . '-' . $nameSlug,
            'title'     => 'ID# ' . $attr['id'] . ', ' . $attr['name'],
            'type'      => 'attr_multi',
            'attr_id'   => $attr['id'],
            'attr_name' => $attr['raw_name'],
        ];
    }
@endphp

@if($sections === [])
    <div class="alert alert-info">No graphs available.</div>
@else
    @php
        $activeIndex = 0;
        foreach ($sections as $i => $s) {
            if ($s['id'] === $selectedGraphView) {
                $activeIndex = $i;
                break;
            }
        }
        $active = $sections[$activeIndex];

        // Plain text list of available graph types, in columns. Each entry has
        // its own "(mini)" link so the per-device breakdown's display mode is
        // chosen per graph type, instead of a single page-wide toggle.
        $viewItems = '';
        foreach ($sections as $s) {
            $isActive = $s['id'] === $active['id'];
            $titleLabel = htmlspecialchars($s['title']);
            if ($isActive && $graphsDisplayMode !== 'mini') {
                $titleLabel = '<span class="pagemenu-selected">' . $titleLabel . '</span>';
            }
            $miniLabel = 'mini';
            if ($isActive && $graphsDisplayMode === 'mini') {
                $miniLabel = '<span class="pagemenu-selected">' . $miniLabel . '</span>';
            }
            $viewItems .= '<div style="break-inside:avoid-column;-webkit-column-break-inside:avoid;padding:1px 0">'
                . '<a href="' . htmlspecialchars($smartGraphsUrl($s['id']), ENT_QUOTES) . '">' . $titleLabel . '</a>'
                . ' (<a href="' . htmlspecialchars($smartGraphsUrl($s['id'], 'mini'), ENT_QUOTES) . '">' . $miniLabel . '</a>)'
                . '</div>';
        }
        echo '<div class="panel panel-default"><div class="panel-body" style="padding:10px 15px">'
            . '<strong>Graph type:</strong><div style="column-width:260px;column-gap:18px;margin-top:6px">'
            . $viewItems . '</div></div></div>';

        // Per-device graph_array for the active section: sensor-based for the 3
        // combined types (same sensor accessors the Overview Drives table uses,
        // so SATA vs NVMe is handled transparently), attribute-based for
        // per-attribute sections (only disks that actually report that
        // attribute; others are simply omitted).
        $devices = [];
        foreach ($data->diskKeys() as $key) {
            $disk = $data->disk($key);

            if (isset($active['attr_id'])) {
                $spec = $data->attributeGraphSpecs($key)[$active['attr_id']] ?? null;
                // Same numeric ID can be a different vendor-defined counter on
                // a different disk. Only include disks whose attribute name
                // actually matches this section's, not just the numeric ID.
                if ($spec === null || $spec['raw_name'] !== $active['attr_name']) {
                    continue;
                }
                $devices[] = [
                    'key' => $key, 'disk' => $disk,
                    'badge' => isset($spec['header']) ? '<span class="text-muted" style="font-size:12px">' . htmlspecialchars($spec['header']) . '</span>' : '',
                    'graph_array' => [
                        'type'        => 'smart_attr_value',
                        'id'          => $data->app->app_id,
                        'disk'        => $disk['idx'],
                        'scale_min'   => '0',
                        'attr_id'     => (string) $spec['id'],
                        'attr_thresh' => $spec['thresh'] !== null ? (string) $spec['thresh'] : '',
                        'rate_unit'   => $spec['rate_unit'] ?? '',
                    ],
                ];
                continue;
            }

            // Power State has no SENSOR-MIB sensor. It's an app-level RRD
            // dataset (same RRD the per-disk attributes live in), so it's
            // handled separately from the sensor-based types below.
            if ($active['type'] === 'all_power_state') {
                if (! $data->hasPowerStateRrd($key)) {
                    continue;
                }
                $powerState = $disk['power_state'] ?? null;
                $badge = $powerState !== null
                    ? '<span class="label label-default">' . htmlspecialchars($data->decode('power_state', $powerState)) . '</span>'
                    : '';
                $devices[] = [
                    'key' => $key, 'disk' => $disk,
                    'badge' => $badge,
                    'graph_array' => [
                        'type' => 'smart_powerState',
                        'id'   => $data->app->app_id,
                        'disk' => $disk['idx'],
                        'rrd'  => $data->isNvme($disk) ? 'smart_nvme' : 'smart',
                    ],
                ];
                continue;
            }

            $sensor = match ($active['type']) {
                'all_temp'            => $data->temperatureSensor($key),
                'all_wear'            => $data->percentageUsedSensor($key),
                'all_spare'           => $data->availableSpareSensor($key),
                'all_health'          => $data->healthSensor($key),
                'all_selftest_status' => $data->selftestStatusSensor($key),
                'all_selftest_short'  => $data->selftestAgeSensor($key, 'short'),
                'all_selftest_long'   => $data->selftestAgeSensor($key, 'long'),
                default                        => null,
            };
            if ($sensor === null) {
                continue;
            }
            $badge = match ($active['type']) {
                'all_temp'  => $tempBadge($sensor),
                'all_wear', 'all_spare' => $percentBadge($sensor),
                'all_health', 'all_selftest_status' => $stateBadge($sensor),
                'all_selftest_short', 'all_selftest_long' => $selftestBadge($sensor),
                default              => '',
            };
            $devices[] = [
                'key' => $key, 'disk' => $disk,
                'badge' => $badge,
                'graph_array' => [
                    'type' => 'sensor_' . $sensor->sensor_class,
                    'id'   => $sensor->sensor_id,
                ],
            ];
        }

        // Aggregate (all-disk) graph for the active type.
        $graph_array = [
            'height' => '100', 'width' => '215', 'from' => $from, 'to' => $now,
            'id'     => $appId, 'type' => 'smart_' . $active['type'],
            'page_title' => 'All Drives: ' . $active['title'],
        ];
        if (isset($active['attr_id'])) {
            $graph_array['attr_id'] = $active['attr_id'];
            $graph_array['attr_name'] = $active['attr_name'];
        }

        $panelStart(htmlspecialchars($active['title']));
        echo '<div class="row">';
        include 'includes/html/print-graphrow.inc.php';
        echo '</div>';
        $panelEnd();
    @endphp

    @if($devices !== [])
        @if($graphsDisplayMode === 'mini')
            @php $panelStart('Per-device'); @endphp
            @php
                echo '<div style="overflow:hidden">';
                foreach ($devices as $entry) {
                    $deviceUrl = htmlspecialchars($smartUrl($entry['key']), ENT_QUOTES);
                    $label = '<a href="' . $deviceUrl . '">' . htmlspecialchars($data->displayLabel($entry['disk'], $labelMode)) . '</a>';
                    $thumbArgs = array_merge($entry['graph_array'], [
                        'height' => '80', 'width' => '180', 'to' => $now, 'legend' => 'no',
                    ]);
                    $linkArgs = array_merge($entry['graph_array'], [
                        'page' => 'graphs', 'to' => $now,
                        'page_title' => $active['title'] . ': ' . $data->displayLabel($entry['disk'], $labelMode),
                    ]);
                    $linkUrl = Url::generate($linkArgs);
                    $badge = $entry['badge'] !== '' ? '<span class="pull-right">' . $entry['badge'] . '</span>' : '';
                    echo '<div class="pull-left" style="margin-right:8px;margin-bottom:8px">'
                        . '<div style="font-size:11px;margin-bottom:4px">' . $label . $badge . '</div>'
                        . '<a href="' . htmlspecialchars($linkUrl, ENT_QUOTES) . '">' . Url::lazyGraphTag($thumbArgs) . '</a>'
                        . '</div>';
                }
                echo '</div>';
            @endphp
            @php $panelEnd(); @endphp
        @else
            @foreach($devices as $entry)
                @php
                    $deviceUrl = htmlspecialchars($smartUrl($entry['key']), ENT_QUOTES);
                    $label = '<a href="' . $deviceUrl . '">' . htmlspecialchars($data->displayLabel($entry['disk'], $labelMode)) . '</a>';
                    $graph_array = array_merge($entry['graph_array'], ['height' => '100', 'width' => '215', 'to' => $now]);
                    $panelStart($label, $entry['badge']);
                    echo '<div class="row">';
                    include 'includes/html/print-graphrow.inc.php';
                    echo '</div>';
                    $panelEnd();
                @endphp
            @endforeach
        @endif
    @endif
@endif
