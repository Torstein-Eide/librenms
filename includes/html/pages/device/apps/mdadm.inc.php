<?php

/**
 * mdadm application page
 *
 * File structure
 * --------------
 * Standalone helper functions (pure / stateless)
 *   mdadm_badge()
 *   mdadm_array_health_label/class/info()
 *   mdadm_operation_label/class/info()
 *   mdadm_device_health_label/class/info()
 *
 * class MdadmAppRenderer
 *   Properties
 *     $arrayData          sensor-keyed array data
 *     $appMetrics         flat metric key=>value map from update_application()
 *     $arraysMeta         per-array metadata from $app->data
 *     $arraysDevices      per-array device metadata from $app->data
 *     $arrayUuidByName    map of array name => uuid
 *     $linkArray          base link params for generate_link()
 *     $graphs             legacy v2 graph definitions
 *     $isLegacy           true when no sensors exist (v1/v2 agent)
 *     $app                the App\Models\Application instance
 *     $device             the device array
 *     $selectedArray      the ?string array name from $vars['array']
 *     $syncData           parsed sync scalars, set by parseSyncData()
 *     $allSensors         raw Eloquent collection of app:mdadm:* sensors (for debug panel)
 *
 *   __construct($app, $device, $vars)
 *     Runs all data loading and populates properties.
 *
 *   renderOptionbar()
 *     Overview link and per-array links in the option bar.
 *
 *   renderOverview()
 *     Summary counters panel, arrays status table, then calls renderOverviewGraphs().
 *
 *   renderOverviewGraphs()
 *     V3: one mini-graph panel per array.
 *     Legacy: calls renderV2Graphs(null).
 *
 *   renderArrayView()
 *     Status panel, sync panel, disk-counts panel, devices table.
 *     Calls parseSyncData() to populate $syncData before rendering.
 *
 *   renderArrayGraphs()
 *     V3: full-size graph row per metric for the selected array.
 *     Legacy: calls renderV2Graphs($selectedArray).
 *
 *   renderV2Graphs(?string $array)
 *     Shared legacy (v1/v2) graph loop. Pass null for overview, array name for
 *     per-array view.
 *
 *   parseSyncData()
 *     Reads $app->data sync block for the selected array and stores the
 *     parsed scalars in $this->syncData so both renderArrayView() and
 *     renderArrayGraphs() can share them.
 *
 *   panelStart(string $title, string $badge = '')
 *   panelEnd()
 *   tableRow(string $label, string $value)
 *     HTML helpers for Bootstrap panel and table-row markup.
 *
 * Entry point (file scope, last lines)
 *   Instantiates HtmlData and MdadmAppRenderer, calls renderOptionbar(),
 *   mdadm_debug_render(), and renderOverview() or renderArrayView() + renderArrayGraphs().
 */

use LibreNMS\Agent\Unix\Mdadm\HtmlData;

require_once base_path('includes/html/debug-panel.inc.php');

// =============================================================================
// Standalone helper functions (pure / stateless)
// =============================================================================

function mdadm_badge(string $label, string $class, ?string $title = null): string
{
    $titleAttr = $title !== null && $title !== ''
        ? ' title="' . htmlspecialchars($title) . '"'
        : '';

    return '<span class="label label-' . $class . '"' . $titleAttr . '>' . htmlspecialchars($label) . '</span>';
}

// =============================================================================
// Renderer class
// =============================================================================

class MdadmAppRenderer
{
    /** Legacy v2 graph definitions - only used when $isLegacy is true. */
    private const LEGACY_GRAPHS = [
        'level'          => 'RAID Level',
        'size'           => 'RAID Size',
        'disc_count'     => 'Disk Count',
        'hotspare_count' => 'Hotspare Count',
        'degraded'       => 'Degraded',
        'sync_speed'     => 'Sync Speed',
        'sync_completed' => 'Sync Completed',
    ];

    /**
     * Graph specs shared by overview (mini) and per-array (full) views.
     * Keys become $graphHeaderValues keys in renderArrayGraphs().
     * 'metric' is optional - only set for mdadm_app multi-dataset graphs.
     */
    private const ARRAY_GRAPHS = [
        'disk_counts' => ['type' => 'mdadm_app',         'metric' => 'disk_counts', 'title' => 'Disk Counts (disks)'],
        'mismatch'    => ['type' => 'mdadm_mismatch',                                'title' => 'Mismatch'],
        'sync_bps'    => ['type' => 'mdadm_app',         'metric' => 'sync_bps',    'title' => 'Sync Speed (B/s)'],
        'sync_pct'    => ['type' => 'mdadm_app',         'metric' => 'sync_pct',    'title' => 'Sync Progress (%)'],
        'diskio_ops'  => ['type' => 'mdadm_diskio_ops',                              'title' => 'Disk I/O Ops'],
        'diskio_bits' => ['type' => 'mdadm_diskio_bits',                             'title' => 'Disk I/O Bytes'],
    ];

    /**
     * Overview table column headers.
     * Each entry: ['tip' => string, 'style' => string]
     * tip: tooltip text (empty = none); style: inline CSS for the <th> (empty = none).
     */
    private const OVERVIEW_HEADERS = [
        'Array'      => ['tip' => '',                                                                                              'style' => ''],
        'Level'      => ['tip' => 'RAID level (e.g. raid0, raid1, raid5). Determines redundancy and performance.',                'style' => ''],
        'Health'     => ['tip' => 'Overall array health derived from state flags and device counts.',                              'style' => ''],
        'Operation'  => ['tip' => 'Current sync operation: idle, check, resync, recover, or repair.',                             'style' => ''],
        'Disks'      => ['tip' => 'Number of member devices the array is configured to use (raid_disks).',                        'style' => ''],
        'Active'     => ['tip' => 'Number of devices currently active and contributing to the array.',                            'style' => ''],
        'Spare'      => ['tip' => 'Number of hot-spare devices ready to replace a failed member.',                                'style' => ''],
        'Size'       => ['tip' => 'Total usable size of the array after RAID overhead.',                                          'style' => 'white-space:nowrap'],
        'Errors'     => ['tip' => 'Total read errors across all member devices.',                                                  'style' => ''],
        'Mismatches' => ['tip' => 'Sectors found inconsistent during last check. Non-zero means parity or mirror data disagrees.', 'style' => ''],
    ];

    /** Devices table column headers: label => tooltip (empty = no tooltip). */
    private const DEVICE_HEADERS = [
        'Path'   => 'Block device path (e.g. /dev/sda).',
        'Role'   => 'Role this device plays in the array: active member, spare (not yet a member), journal, or replacement.',
        'Health' => 'Device state flags from the kernel: faulty - failed; in_sync - up to date; writemostly - reads avoided unless necessary; blocked - writes blocked; spare - standby; write_error - write errors detected; want_replacement - flagged for replacement; replacement - replacing another device.',
        'Slot'   => 'Slot (raid_disk) this device occupies in the array. Determines which data stripes or mirror copy this device holds. -1 means spare.',
        'Errors' => 'Cumulative count of read errors detected on this device. Excessive errors may cause the kernel to mark the device faulty.',
        'Model'  => '',
        'Serial' => '',
        'Size'   => '',
    ];

    /** Status panel row definitions: label => [meta_key, tooltip]. */
    private const STATUS_FIELDS = [
        'RAID Level'         => ['key' => 'raid_level',         'tooltip' => 'The RAID level (e.g. raid0, raid1, raid4, raid5, raid6, raid10, linear). Determines redundancy and performance characteristics.'],
        'State'              => ['key' => 'state',              'tooltip' => 'Current array state. clean - no pending writes; active - may have pending writes; degraded - fewer devices than configured; write-pending - writes blocked; suspended - all I/O blocked; readonly/read-auto - no write access.'],
        'UUID'               => ['key' => 'uuid',               'tooltip' => 'Universally unique identifier for this array, shared across all member devices. Used to reassemble the array after reboot.'],
        'Metadata'           => ['key' => 'metadata_version',   'tooltip' => 'Superblock version. 0.90 - superblock at end of device; 1.0 - last 4KiB of device; 1.1 - first 4KiB; 1.2 - 4KiB from start. Version 1.x supports arrays > 2TiB.'],
        'Consistency Policy' => ['key' => 'consistency_policy', 'tooltip' => 'How the array maintains consistency after an unclean shutdown. none - no guarantee; resync - full resync on start; bitmap - write-intent bitmap (faster resync); journal - dedicated journal device; ppl - partial parity log.'],
    ];

    /** Disk counts panel row definitions: label => [meta_key, tooltip]. */
    private const DISK_COUNT_FIELDS = [
        'Total Disks' => ['key' => 'raid_disks',      'tooltip' => 'Number of member devices the array is configured to use (raid_disks). Does not include spares. A healthy array has active == total disks.'],
        'Active'      => ['key' => 'active_devices',  'tooltip' => 'Number of devices currently active and contributing to the array. Should equal Total Disks when healthy.'],
        'Spare'       => ['key' => 'spare_devices',   'tooltip' => 'Number of hot-spare devices standing by. A spare is automatically rebuilt and promoted when a member device fails.'],
        'Failed'      => ['key' => 'failed_devices',  'tooltip' => 'Number of devices that have been marked faulty and removed from the active set. The array will be degraded if failed > 0.'],
    ];

    /** Status panel extra-row tooltips keyed by label. */
    private const SPECIAL_ROW_TOOLTIPS = [
        'Array Size'  => 'Total usable size of the array (after RAID overhead). For raid1 this is the size of one member; for raid5 it is (N-1) × member size.',
        'Chunk Size'  => 'Stripe unit for raid0/4/5/6/10. Data is written in chunks of this size across member devices. Larger chunks improve sequential I/O; smaller chunks improve parallelism for random I/O.',
        'Mismatches'  => 'Number of sectors found to be inconsistent during the last check or repair operation. Non-zero indicates parity or mirror data disagrees. Run a repair to correct.',
    ];

    /** Sync panel row tooltips keyed by label. */
    private const SYNC_TOOLTIPS = [
        'Last operation' => 'The sync action most recently run: check - read all blocks and verify redundancy; resync - recalculate parity after unclean shutdown; recover - rebuild a spare to replace a failed device; repair - check and correct inconsistencies.',
        'Speed limits'   => 'Minimum / maximum sync speed in bytes/sec. Controlled by /proc/sys/dev/raid/speed_limit_min and speed_limit_max. A higher minimum reduces recovery time at the cost of I/O bandwidth.',
        'Speed'          => 'Current sync throughput in bytes/sec.',
        'Progress'       => 'Fraction of the current sync operation completed.',
    ];

    private array $linkArray;
    private ?string $selectedArray;

    public function __construct(
        private readonly HtmlData $data,
        array $vars,
    ) {
        $this->selectedArray = $vars['array'] ?? null;
        $this->linkArray = [
            'page'   => 'device',
            'device' => $data->device['device_id'],
            'tab'    => 'apps',
            'app'    => 'mdadm',
        ];
    }
    // -------------------------------------------------------------------------
    // HTML helpers
    // -------------------------------------------------------------------------

    private function panelStart(string $title, string $badge = ''): void
    {
        $badgeHtml = $badge !== '' ? "<span class=\"pull-right\">{$badge}</span>" : '';
        echo <<<HTML
            <div class="panel panel-default">
                <div class="panel-heading"><h3 class="panel-title">{$title}{$badgeHtml}</h3></div>
                <div class="panel-body">
            HTML;
    }

    private function panelEnd(): void
    {
        echo <<<'HTML'
                </div>
            </div>
            HTML;
    }

    /** Echoes a table row and returns void. */
    private function tableRow(string $label, string $value, string $tooltip = ''): void
    {
        echo $this->tableRowHtml($label, $value, $tooltip);
    }

    /** Returns a table row as a string (for building heredoc buffers). */
    private function tableRowHtml(string $label, string $value, string $tooltip = ''): string
    {
        $labelEsc = htmlspecialchars($label);
        if ($tooltip !== '') {
            $ttAttr = ' title="' . htmlspecialchars($tooltip) . '"';
            $labelHtml = "<abbr style=\"cursor:help;text-decoration:underline dotted\"{$ttAttr}>{$labelEsc}</abbr>";
        } else {
            $labelHtml = $labelEsc;
        }

        return <<<HTML
            <tr>
                <td style="text-align:right;padding-right:15px;white-space:nowrap"><strong>{$labelHtml}</strong></td>
                <td>{$value}</td>
            </tr>
            HTML;
    }

    // -------------------------------------------------------------------------
    // Optionbar
    // -------------------------------------------------------------------------

    public function renderOptionbar(): void
    {
        print_optionbar_start();

        $ovLabel = $this->selectedArray === null
            ? '<span class="pagemenu-selected">Overview</span>'
            : 'Overview';
        echo generate_link($ovLabel, $this->linkArray);

        if (! empty($this->data->arrayData)) {
            echo ' | Arrays: ';
            $names = array_keys($this->data->arrayData);
            foreach ($names as $i => $name) {
                $label = htmlspecialchars($name);
                if ($this->selectedArray === $name) {
                    $label = "<span class=\"pagemenu-selected\">{$label}</span>";
                }
                echo generate_link($label, $this->linkArray, ['array' => $name]);
                if ($i < count($names) - 1) {
                    echo ', ';
                }
            }
        }

        if (Auth::user()->hasRole('admin')) {
            echo '<span class="pull-right">' . debug_toggle_button('mdadm-debug-panels') . '</span>';
        }

        print_optionbar_end();
    }

    // -------------------------------------------------------------------------
    // Overview
    // -------------------------------------------------------------------------

    public function renderOverview(): void
    {
        $totalArrays = (int) ($this->data->appMetrics['arrays'] ?? count($this->data->arrayData));
        $totalDevices = (int) ($this->data->appMetrics['devices_total'] ?? array_sum(array_map(fn ($d) => count($d['devices'] ?? []), $this->data->arrayData)));
        $degradedArrays = (int) ($this->data->appMetrics['degraded_arrays'] ?? 0);
        $syncingArrays = (int) ($this->data->appMetrics['arrays_syncing'] ?? 0);

        $summaryItems = [
            ['value' => $totalArrays,    'label' => 'Arrays',   'class' => ''],
            ['value' => $totalDevices,   'label' => 'Devices',  'class' => ''],
            ['value' => $degradedArrays, 'label' => 'Degraded', 'class' => $degradedArrays > 0 ? 'text-danger' : ''],
            ['value' => $syncingArrays,  'label' => 'Syncing',  'class' => $syncingArrays > 0 ? 'text-info' : 'text-muted'],
        ];

        $summaryDivs = implode('', array_map(
            static fn ($item) => '<div class="col-sm-3' . ($item['class'] !== '' ? ' ' . $item['class'] : '') . '"><h4>' . $item['value'] . '</h4><small>' . $item['label'] . '</small></div>',
            $summaryItems
        ));

        $this->panelStart('Summary');
        echo <<<HTML
            <div class="row text-center">{$summaryDivs}</div>
            HTML;
        $this->panelEnd();

        if (! empty($this->data->arrayData)) {
            $tableRows = '';
            foreach ($this->data->arrayData as $name => $data) {
                $hVal = $data['mdadm_array_health_status']['val'] ?? -1;
                $opVal = $data['mdadm_array_operation_status']['val'] ?? -1;

                $errors = 0;
                foreach (($data['devices'] ?? []) as $dev) {
                    $errors += $dev['mdadm_device_errors']['val'] ?? 0;
                }

                $mismatch = $data['mdadm_array_mismatch']['val'] ?? null;
                $meta = $this->data->arraysMeta[$name] ?? [];
                $arrLink = generate_link(htmlspecialchars($name), $this->linkArray, ['array' => $name]);

                $hEntry = $data['mdadm_array_health_status'] ?? [];
                $opEntry = $data['mdadm_array_operation_status'] ?? [];
                $hBadge = mdadm_badge($hEntry['label'] ?? 'Unknown', $hEntry['class'] ?? 'default', $hEntry['info'] ?? '');
                $opBadge = mdadm_badge($opEntry['label'] ?? 'Unknown', $opEntry['class'] ?? 'default', $opEntry['info'] ?? '');

                $sizeStr = isset($meta['size_bytes']) && $meta['size_bytes'] > 0
                    ? LibreNMS\Util\Number::formatBi($meta['size_bytes'])
                    : '&mdash;';
                $mismatchCell = $mismatch !== null
                    ? '<span class="' . ($mismatch > 0 ? 'text-warning' : '') . '">' . $mismatch . '</span>'
                    : '&mdash;';
                $errorsCell = $errors > 0
                    ? '<span class="text-warning">' . $errors . '</span>'
                    : $errors;
                $cells = [
                    $arrLink,
                    htmlspecialchars((string) ($meta['raid_level'] ?? '-')),
                    $hBadge,
                    $opBadge,
                    $meta['raid_disks'] ?? '-',
                    $meta['active_devices'] ?? '-',
                    $meta['spare_devices'] ?? '-',
                    $sizeStr,
                    $errorsCell,
                    $mismatchCell,
                ];

                $tableRows .= '<tr><td>' . implode('</td><td>', $cells) . "</td></tr>\n";
            }

            $overviewHeaders = implode('', array_map(
                static fn ($h, $spec) => '<th'
                    . ($spec['tip'] !== '' ? ' title="' . htmlspecialchars($spec['tip']) . '"' : '')
                    . ($spec['style'] !== '' ? ' style="' . $spec['style'] . '"' : '')
                    . '>' . htmlspecialchars($h) . '</th>',
                array_keys(self::OVERVIEW_HEADERS),
                array_values(self::OVERVIEW_HEADERS)
            ));
            $this->panelStart('Arrays');
            echo <<<HTML
                <table class="table table-condensed table-hover">
                    <thead>
                        <tr>{$overviewHeaders}</tr>
                    </thead>
                    <tbody>{$tableRows}</tbody>
                </table>
                HTML;
            $this->panelEnd();
        }

        $this->renderOverviewGraphs();
    }

    private function renderOverviewGraphs(): void
    {
        if ($this->data->isLegacy) {
            $this->renderV2Graphs(null);

            return;
        }

        if (empty($this->data->arrayData)) {
            return;
        }

        foreach ($this->data->arrayData as $name => $data) {
            $hEntry = $data['mdadm_array_health_status'] ?? [];
            $hBadge = mdadm_badge($hEntry['label'] ?? 'Unknown', $hEntry['class'] ?? 'default', $hEntry['info'] ?? '');
            $arrUrl = LibreNMS\Util\Url::generate($this->linkArray + ['array' => $name]);

            $graphDivs = '';
            foreach (self::ARRAY_GRAPHS as $spec) {
                $graphArray = [
                    'height' => '80',
                    'width'  => '180',
                    'type'   => $spec['type'],
                    'id'     => $this->data->app->app_id,
                    'array'  => $name,
                    'from'   => App\Facades\LibrenmsConfig::get('time.day'),
                    'to'     => App\Facades\LibrenmsConfig::get('time.now'),
                    'legend' => 'no',
                ];
                if (isset($spec['metric'])) {
                    $graphArray['metric'] = $spec['metric'];
                }
                $title = $spec['title'];
                $graphTag = LibreNMS\Util\Url::lazyGraphTag($graphArray);
                $titleEsc = htmlspecialchars($title);
                $graphDivs .= <<<HTML
                    <div class="pull-left" style="margin-right:8px;margin-bottom:8px">
                        <div class="text-muted" style="font-size:11px;margin-bottom:4px">{$titleEsc}</div>
                        <a href="{$arrUrl}">{$graphTag}</a>
                    </div>
                    HTML;
            }

            $nameEsc = htmlspecialchars($name);
            $this->panelStart("<a href=\"{$arrUrl}\">{$nameEsc}</a>", $hBadge);
            echo <<<HTML
                <div class="row">{$graphDivs}</div>
                HTML;
            $this->panelEnd();
        }
    }

    // -------------------------------------------------------------------------
    // Per-array view
    // -------------------------------------------------------------------------
    private function renderStatusPanel(): void
    {
        $name = (string) $this->selectedArray;
        $data = $this->data->arrayData[$name] ?? [];
        $meta = $this->data->arraysMeta[$name] ?? [];

        $hEntry = $data['mdadm_array_health_status'] ?? [];
        $hBadge = mdadm_badge($hEntry['label'] ?? 'Unknown', $hEntry['class'] ?? 'default', $hEntry['info'] ?? '');

        $sizeStr = isset($meta['size_bytes']) && $meta['size_bytes'] > 0
            ? LibreNMS\Util\Number::formatBi($meta['size_bytes'])
            : null;

        echo <<<'HTML'
            <div class="col-md-6" style="display:inline-block;float:none;width:auto;vertical-align:top">
            HTML;
        $this->panelStart(htmlspecialchars($name) . ' Status', $hBadge);
        echo '<table class="table table-condensed" style="width:auto">';
        foreach (self::STATUS_FIELDS as $label => $spec) {
            $this->tableRow($label, htmlspecialchars((string) ($meta[$spec['key']] ?? '-')), $spec['tooltip']);
        }
        $mismatchVal = (int) ($data['mdadm_array_mismatch']['val'] ?? 0);
        $tt = self::SPECIAL_ROW_TOOLTIPS;
        $specialRows = [
            ['Array Size',  $sizeStr ?? '-',                                                                                                           $tt['Array Size']],
            ['Chunk Size',  LibreNMS\Util\Number::formatBi((int) ($meta['chunk_size'] ?? 0)),                                                          $tt['Chunk Size']],
            ['Mismatches',  '<span' . ($mismatchVal > 0 ? ' class="text-warning"' : '') . '>' . $mismatchVal . '</span>',                             $tt['Mismatches']],
        ];
        foreach ($specialRows as [$label, $value, $tooltip]) {
            $this->tableRow($label, $value, $tooltip);
        }
        echo '</table>';
        $this->panelEnd();
        echo '</div>';
    }

    private function renderSyncPanel(): void
    {
        $name = (string) $this->selectedArray;
        $data = $this->data->arrayData[$name] ?? [];

        $opEntry = $data['mdadm_array_operation_status'] ?? [];
        $opBadge = mdadm_badge($opEntry['label'] ?? 'Unknown', $opEntry['class'] ?? 'default', $opEntry['info'] ?? '');

        $sync = $this->data->syncDataForArray($name);
        $isSyncing = $sync['is_syncing'];
        $speedBps = $sync['speed_bps'];
        $speedMin = $sync['speed_min'];
        $speedMax = $sync['speed_max'];
        $syncDone = $sync['done_bytes'];
        $syncTotal = $sync['total_bytes'];
        $syncPct = $sync['completed_pct'];
        $lastAction = $sync['last_action'];

        $lastOpStr = $lastAction !== '' ? htmlspecialchars(ucfirst($lastAction)) : '<span class="text-muted">-</span>';
        $minStr = $speedMin > 0 ? LibreNMS\Util\Number::formatBi($speedMin, suffix: 'B/s') : '<span class="text-muted">-</span>';
        $maxStr = $speedMax > 0 ? LibreNMS\Util\Number::formatBi($speedMax, suffix: 'B/s') : '<span class="text-muted">-</span>';
        $speedStr = ($isSyncing && $speedBps > 0) ? LibreNMS\Util\Number::formatBi($speedBps, suffix: 'B/s') : '-';
        $barPct = $syncTotal > 0 ? max(0, min(100, $syncPct)) : 0;
        $barPctFmt = number_format($barPct, 1);
        $doneStr = LibreNMS\Util\Number::formatBi(max(0, $syncDone));
        $totalStr = $syncTotal > 0 ? LibreNMS\Util\Number::formatBi($syncTotal) : '-';

        $tt = self::SYNC_TOOLTIPS;
        $syncRows = $this->tableRowHtml('Last operation', $lastOpStr, $tt['Last operation'])
            . $this->tableRowHtml('Speed limits', "{$minStr} / {$maxStr}", $tt['Speed limits'])
            . $this->tableRowHtml('Speed', $speedStr, $tt['Speed'])
            . $this->tableRowHtml('Progress', <<<HTML
                <div style="min-width:200px">
                    <div class="progress" style="margin-bottom:4px">
                        <div class="progress-bar progress-bar-info" style="width:{$barPct}%;color:#111">{$barPctFmt}%</div>
                    </div>
                    <small class="text-muted">{$doneStr} / {$totalStr}</small>
                </div>
                HTML);

        echo <<<'HTML'
            <div class="col-md-3" style="display:inline-block;float:none;width:auto;vertical-align:top">
            HTML;
        $this->panelStart('Sync', $opBadge);
        echo <<<HTML
            <table class="table table-condensed" style="width:auto">{$syncRows}</table>
            HTML;
        $this->panelEnd();
        echo '</div>';
    }

    private function renderDiskCountsPanel(): void
    {
        $meta = $this->data->arraysMeta[(string) $this->selectedArray] ?? [];

        $diskRows = '';
        foreach (self::DISK_COUNT_FIELDS as $label => $spec) {
            $diskRows .= $this->tableRowHtml($label, (string) (int) ($meta[$spec['key']] ?? 0), $spec['tooltip']);
        }

        $degradedVal = $meta['degraded'] ?? null;
        if ($degradedVal !== null) {
            $dgClass = (int) $degradedVal > 0 ? ' class="text-danger"' : '';
            $diskRows .= $this->tableRowHtml('Degraded', "<span{$dgClass}>" . (int) $degradedVal . '</span>',
                'Count of missing active members. A degraded array is running with fewer devices than configured (active < raid_disks). Data is still accessible but fault tolerance is reduced or lost.');
        }

        echo <<<'HTML'
            <div class="col-md-3" style="display:inline-block;float:none;width:auto;vertical-align:top">
            HTML;
        $this->panelStart('Disk Counts');
        echo <<<HTML
            <table class="table table-condensed" style="width:auto">{$diskRows}</table>
            HTML;
        $this->panelEnd();
        echo '</div>';
    }

    private function renderDevicesTable(): void
    {
        $name = (string) $this->selectedArray;
        $sensorDevices = $this->data->arrayData[$name]['devices'] ?? [];
        $metaDevices = (array) ($this->data->arraysDevices[$name] ?? []);
        $deviceKeys = array_keys($metaDevices);

        if (empty($deviceKeys)) {
            return;
        }

        $deviceRows = '';
        foreach ($deviceKeys as $devKey) {
            $dev = is_array($sensorDevices[$devKey] ?? null) ? $sensorDevices[$devKey] : [];
            $metaDev = is_array($metaDevices[$devKey] ?? null) ? $metaDevices[$devKey] : [];

            $rawPath = (string) ($metaDev['path'] ?? $metaDev['device_name'] ?? '');
            if (str_starts_with($rawPath, '/dev/')) {
                $path = $rawPath;
            } elseif (str_starts_with($rawPath, 'dev-')) {
                $path = '/dev/' . substr($rawPath, 4);
            } elseif ($rawPath !== '') {
                $path = '/dev/' . ltrim($rawPath, '/');
            } else {
                $path = $devKey;
            }

            $deviceSizeBytes = isset($metaDev['size_bytes'])
                ? (int) $metaDev['size_bytes']
                : ((int) ($metaDev['size_blocks'] ?? 0) * 1024);

            $dhEntry = $dev['mdadm_device_health_status'] ?? [];
            $errVal = (int) ($dev['mdadm_device_error']['val'] ?? $dev['mdadm_device_errors']['val'] ?? 0);

            $cells = [
                htmlspecialchars($path),
                htmlspecialchars((string) ($metaDev['device_role'] ?? '-')),
                mdadm_badge($dhEntry['label'] ?? 'Unknown', $dhEntry['class'] ?? 'default', $dhEntry['info'] ?? ''),
                htmlspecialchars((string) ($metaDev['slot'] ?? '-')),
                $errVal > 0 ? '<span class="text-warning">' . $errVal . '</span>' : (string) $errVal,
                htmlspecialchars((string) ($metaDev['id_model'] ?? '-')),
                htmlspecialchars((string) ($metaDev['id_serial_short'] ?? '-')),
                $deviceSizeBytes > 0 ? LibreNMS\Util\Number::formatBi($deviceSizeBytes) : '-',
            ];

            $deviceRows .= '<tr><td>' . implode('</td><td>', $cells) . "</td></tr>\n";
        }

        $deviceHeaders = implode('', array_map(
            static fn ($h, $tip) => '<th'
                . ($tip !== '' ? ' title="' . htmlspecialchars($tip) . '"' : '')
                . '>' . htmlspecialchars($h) . '</th>',
            array_keys(self::DEVICE_HEADERS),
            array_values(self::DEVICE_HEADERS)
        ));
        $this->panelStart('Devices');
        echo <<<HTML
            <table class="table table-condensed table-hover">
                <thead>
                    <tr>{$deviceHeaders}</tr>
                </thead>
                <tbody>{$deviceRows}</tbody>
            </table>
            HTML;
        $this->panelEnd();
    }

    public function renderArrayView(): void
    {
        echo <<<'HTML'
            <div class="row">
            HTML;
        $this->renderStatusPanel();
        $this->renderSyncPanel();
        $this->renderDiskCountsPanel();
        echo <<<'HTML'
            </div>
            HTML;
        $this->renderDevicesTable();
    }

    public function renderArrayGraphs(): void
    {
        $name = (string) $this->selectedArray;
        $meta = $this->data->arraysMeta[$name] ?? [];
        $data = $this->data->arrayData[$name] ?? [];
        $sync = $this->data->syncDataForArray((string) $this->selectedArray);

        if ($this->data->isLegacy) {
            $this->renderV2Graphs($name);

            return;
        }

        $graphHeaderValues = [
            'disk_counts' => 'A:' . (int) ($meta['active_devices'] ?? 0)
                . ' S:' . (int) ($meta['spare_devices'] ?? 0)
                . ' F:' . (int) ($meta['failed_devices'] ?? 0)
                . ' D:' . (int) ($meta['degraded'] ?? 0),
            'mismatch'    => (string) ((int) ($data['mdadm_array_mismatch']['val'] ?? 0)),
            'sync_bps'    => $sync['speed_bps'] > 0
                ? LibreNMS\Util\Number::formatBi($sync['speed_bps'], suffix: 'B/s')
                : '-',
            'sync_pct'    => number_format((float) $sync['completed_pct'], 1) . '%',
            'diskio_ops'  => '-',
            'diskio_bits' => '-',
        ];

        $diskioRates = $this->getDiskioRates($name);
        if (isset($diskioRates['reads'], $diskioRates['writes'])) {
            $graphHeaderValues['diskio_ops'] = 'In: ' . LibreNMS\Util\Number::formatSi($diskioRates['reads'], 2, 0, 'ops/s')
                . ' | Out: ' . LibreNMS\Util\Number::formatSi($diskioRates['writes'], 2, 0, 'ops/s');
        }
        if (isset($diskioRates['read'], $diskioRates['written'])) {
            $graphHeaderValues['diskio_bits'] = 'In: ' . LibreNMS\Util\Number::formatBi($diskioRates['read'], 2, 0, 'B/s')
                . ' | Out: ' . LibreNMS\Util\Number::formatBi($diskioRates['written'], 2, 0, 'B/s');
        }

        foreach (self::ARRAY_GRAPHS as $key => $spec) {
            $graph_array = [
                'height' => '100',
                'width'  => '215',
                'to'     => App\Facades\LibrenmsConfig::get('time.now'),
                'from'   => App\Facades\LibrenmsConfig::get('time.day'),
                'id'     => $this->data->app->app_id,
                'type'   => $spec['type'],
                'array'  => $name,
                'legend' => 'no',
            ];
            if (isset($spec['metric'])) {
                $graph_array['metric'] = $spec['metric'];
            }
            $text = $spec['title'];
            $headerValue = htmlspecialchars((string) $graphHeaderValues[$key]);

            echo <<<HTML
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">{$text}<span class="pull-right text-muted">{$headerValue}</span></h3>
                    </div>
                    <div class="panel-body">
                HTML;
            include 'includes/html/print-graphrow.inc.php';
            echo <<<'HTML'
                    </div>
                </div>
                HTML;
        }
    }

    // -------------------------------------------------------------------------
    // Diskio live rate helper
    // -------------------------------------------------------------------------

    /**
     * @return array<string, float>  Summed DS rates across all devices in the array.
     *                               Keys: 'read', 'written', 'reads', 'writes'
     */
    private function getDiskioRates(string $arrayName): array
    {
        $hostname = (string) ($this->data->device['hostname'] ?? '');
        if ($hostname === '') {
            return [];
        }

        $devices = $this->data->arraysDevices[$arrayName] ?? [];
        $datasets = ['read', 'written', 'reads', 'writes'];
        $totals = [];

        foreach ($devices as $devId => $dev) {
            $path = trim((string) (is_array($dev) ? ($dev['path'] ?? $dev['device_name'] ?? $devId) : $devId));
            if ($path === '') {
                continue;
            }

            $candidates = array_values(array_unique([
                $path,
                ltrim((string) preg_replace('#^/dev/#', '', $path), '/'),
                basename($path),
            ]));

            foreach ($candidates as $candidate) {
                $rrdFile = App\Facades\Rrd::name($hostname, ['ucd_diskio', $candidate]);
                if (! App\Facades\Rrd::checkRrdExists($rrdFile)) {
                    continue;
                }
                $point = App\Facades\Rrd::getLastRates($rrdFile, $datasets);
                if ($point === null) {
                    break;
                }
                foreach ($datasets as $ds) {
                    $v = $point->get($ds);
                    if (is_numeric($v)) {
                        $totals[$ds] = ($totals[$ds] ?? 0.0) + (float) $v;
                    }
                }
                break;
            }
        }

        return $totals;
    }

    // -------------------------------------------------------------------------
    // V2 legacy graph renderer (shared by overview and per-array)
    // -------------------------------------------------------------------------

    private function renderV2Graphs(?string $selectedArray): void
    {
        foreach (self::LEGACY_GRAPHS as $metric => $text) {
            $graph_array = [
                'height' => '100',
                'width'  => '215',
                'to'     => time(),
                'id'     => $this->data->app->app_id,
                'type'   => 'mdadm_legacy',
                'metric' => $metric,
                'legend' => 'no',
            ];

            if ($selectedArray !== null) {
                $graph_array['array'] = $selectedArray;
            }

            echo <<<HTML
                <div class="panel panel-default">
                    <div class="panel-heading"><h3 class="panel-title">{$text}</h3></div>
                    <div class="panel-body">
                HTML;
            include 'includes/html/print-graphrow.inc.php';
            echo <<<'HTML'
                    </div>
                </div>
                HTML;
        }
    }
}

// =============================================================================
// Standalone debug helpers (mdadm-specific; call debug_render() from debug-panel.inc.php)
// =============================================================================

/** Discover mdadm RRD files and return metadata + last data point for each. */
function mdadm_debug_stored_data(int $appId, string $appName, string $hostname): array
{
    $stored = ['rrd' => []];

    if ($hostname === '' || $appId <= 0) {
        return $stored;
    }

    $baseRrdFile = App\Facades\Rrd::name($hostname, ['app', $appName, $appId]);
    $rrdDir = dirname($baseRrdFile);
    $basePrefix = pathinfo($baseRrdFile, PATHINFO_FILENAME);
    $matchingFiles = glob($rrdDir . '/' . $basePrefix . '*.rrd') ?: [];

    foreach ($matchingFiles as $rrdFile) {
        $filename = pathinfo($rrdFile, PATHINFO_FILENAME);
        $arrayName = '(app)';
        if (str_starts_with($filename, $basePrefix . '-')) {
            $arrayName = substr($filename, strlen($basePrefix) + 1) ?: '(app)';
        }

        $entry = [
            'array'            => $arrayName,
            'rrd_file'         => $rrdFile,
            'exists'           => App\Facades\Rrd::checkRrdExists($rrdFile),
            'expected_datasets' => ['active', 'spare', 'failed', 'degraded', 'mismatch', 'done_sectors', 'completed_pct', 'speed_bps'],
        ];

        if ($entry['exists']) {
            clearstatcache(true, $rrdFile);
            $entry['file'] = [
                'size_bytes' => is_file($rrdFile) ? filesize($rrdFile) : null,
                'modified_at' => is_file($rrdFile) ? date('c', (int) filemtime($rrdFile)) : null,
                'age_seconds' => is_file($rrdFile) ? max(0, time() - (int) filemtime($rrdFile)) : null,
            ];

            $point = debug_rrd_last_point($rrdFile);
            $entry['last_update_ok'] = $point !== null;
            if ($point !== null) {
                $entry['last_update'] = [
                    'timestamp'     => $point->timestamp,
                    'timestamp_iso' => date('c', $point->timestamp),
                    'age_seconds'   => max(0, time() - $point->timestamp),
                    'data'          => $point->data,
                ];
            } else {
                $entry['last_update'] = null;
                $entry['last_update_note'] = 'RRD exists, but lastUpdate() returned null (unexpected rrdtool output format or no parseable datapoint yet).';
            }
        }

        $stored['rrd'][] = $entry;
    }

    return $stored;
}

/** Render the "App RRD Files" table and "Current DS Values" cards. */
function mdadm_debug_datastore_tables_html(array $datastoreInfo): string
{
    static $fileHeaders = ['Array', 'Exists', 'Last update', 'File mtime', 'Size (SI)', 'RRD file'];

    $stores = (array) ($datastoreInfo['stores'] ?? []);
    $rrdEntries = (array) ($datastoreInfo['mdadm_stored_data']['rrd'] ?? []);

    $filesRows = '';
    foreach ($rrdEntries as $entry) {
        $array = htmlspecialchars((string) ($entry['array'] ?? ''));
        $rrdFile = htmlspecialchars((string) ($entry['rrd_file'] ?? ''));
        $exists = ! empty($entry['exists']) ? 'yes' : 'no';
        $existsClass = $exists === 'yes' ? 'text-success' : 'text-danger';
        $modifiedAt = htmlspecialchars((string) ($entry['file']['modified_at'] ?? '-'));
        $size = isset($entry['file']['size_bytes'])
            ? LibreNMS\Util\Number::formatSi((float) $entry['file']['size_bytes'], 2, 0, 'B')
            : '-';
        $lastUpdateTs = htmlspecialchars((string) ($entry['last_update']['timestamp_iso'] ?? '-'));

        $filesRows .= <<<HTML
            <tr>
                <td>{$array}</td>
                <td><span class="{$existsClass}">{$exists}</span></td>
                <td style="white-space:nowrap">{$lastUpdateTs}</td>
                <td style="white-space:nowrap">{$modifiedAt}</td>
                <td>{$size}</td>
                <td style="font-family:monospace;word-break:break-all">{$rrdFile}</td>
            </tr>
            HTML;
    }

    $theadHtml = implode('', array_map(static fn ($h) => '<th>' . htmlspecialchars($h) . '</th>', $fileHeaders));
    $colspan = count($fileHeaders);

    if ($filesRows === '') {
        $filesRows = "<tr><td colspan=\"{$colspan}\" class=\"text-muted\">No mdadm RRD files discovered.</td></tr>";
    }

    $dsCards = '';
    foreach ($rrdEntries as $entry) {
        $array = htmlspecialchars((string) ($entry['array'] ?? ''));
        $data = (array) ($entry['last_update']['data'] ?? []);

        if (empty($data)) {
            continue;
        }

        $dsRows = '';
        foreach ($data as $dataset => $value) {
            $dsEsc = htmlspecialchars((string) $dataset);
            $valEsc = $value === null ? '<span class="text-muted">null</span>' : htmlspecialchars((string) $value);
            $dsRows .= <<<HTML
                <tr>
                    <td style="padding:2px 8px 2px 0">{$dsEsc}</td>
                    <td style="padding:2px 0">{$valEsc}</td>
                </tr>
                HTML;
        }

        $dsCards .= <<<HTML
            <div style="margin-right:16px;margin-bottom:12px;min-width:180px">
                <div style="font-size:11px;font-weight:bold;margin-bottom:4px;color:#555">{$array}</div>
                <table style="font-size:12px;border-collapse:collapse">{$dsRows}</table>
            </div>
            HTML;
    }

    $currentHtml = $dsCards !== ''
        ? "<div style=\"display:flex;flex-wrap:wrap;align-items:flex-start\">{$dsCards}</div>"
        : '<p class="text-muted" style="font-size:12px">No current DS values available.</p>';
    $datastoreList = debug_format_datastore_list($stores);

    return <<<HTML
        <div class="text-muted" style="margin-bottom:8px;font-size:12px">Active datastores: {$datastoreList}</div>
        <h4 style="margin-top:0">App RRD Files</h4>
        <table class="table table-condensed table-hover" style="font-size:12px">
            <thead><tr>{$theadHtml}</tr></thead>
            <tbody>{$filesRows}</tbody>
        </table>
        <h4>Current DS Values</h4>
        {$currentHtml}
        HTML;
}

/** Encode sensor rows as a CSV data URI for a download link. */
function mdadm_debug_sensor_csv_uri(array $rows): string
{
    $headers = ['sensor_oid', 'type', 'group', 'sensor_navigation', 'index', 'descr', 'current'];
    $csvRows = array_map(fn ($row) => [
        (string) ($row['sensor_oid'] ?? ''),
        (string) ($row['sensor_type'] ?? ''),
        (string) ($row['group'] ?? ''),
        (string) ($row['sensor_navigation'] ?? ''),
        (string) ($row['sensor_index'] ?? ''),
        (string) ($row['sensor_descr'] ?? ''),
        (string) ($row['current'] ?? ''),
    ], $rows);

    return debug_csv_data_uri($headers, $csvRows);
}

/**
 * Build and render the mdadm debug panels by calling the generic debug_render().
 *
 * @param  int     $appId    app_id of the mdadm Application record
 * @param  array   $appData  app->data payload (the JSON blob)
 * @param  object  $allSensors  Eloquent Collection of app:mdadm:* Sensor models
 * @param  string  $appName  app name used in RRD path (normally 'mdadm')
 * @param  string  $hostname device hostname
 */
function mdadm_debug_render(int $appId, array $appData, object $allSensors, string $appName, string $hostname): void
{
    static $sensorColumns = ['sensor_oid', 'sensor_type', 'group', 'sensor_navigation', 'sensor_index', 'sensor_descr', 'current'];

    // --- Database Data panel ------------------------------------------
    $dataJson = htmlspecialchars(
        json_encode($appData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}'
    );
    $dbPreId = "mdadm-debug-db-{$appId}";
    $dbPanel = debug_panel(
        'Debug: Database Data',
        debug_pre($dbPreId, $dataJson),
        debug_toolbar($dbPreId, "mdadm-app-data-{$appId}.json")
    );

    // --- Datastore panel ----------------------------------------------
    $datastoreInfo = ['stores' => [], 'stats' => []];
    try {
        $datastore = app('Datastore');
        if (method_exists($datastore, 'getStores')) {
            $datastoreInfo['stores'] = array_values(array_map(
                static fn ($store) => (string) $store->getName(),
                (array) $datastore->getStores()
            ));
        }
        if (method_exists($datastore, 'getStats')) {
            $stats = $datastore->getStats();
            $datastoreInfo['stats'] = method_exists($stats, 'toArray') ? $stats->toArray() : (array) $stats;
        }
        $datastoreInfo['mdadm_stored_data'] = mdadm_debug_stored_data($appId, $appName, $hostname);
    } catch (Throwable) {
        $datastoreInfo['error'] = 'Datastore info unavailable';
    }

    $datastoreJson = htmlspecialchars(json_encode($datastoreInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
    $datastorePreId = "mdadm-debug-ds-{$appId}";
    $datastorePreHtml = debug_pre($datastorePreId, $datastoreJson);
    $datastorePanel = debug_panel(
        "Debug: Datastore (app('Datastore'))",
        mdadm_debug_datastore_tables_html($datastoreInfo)
            . '<details style="margin-top:8px"><summary class="text-muted" style="cursor:pointer;font-size:12px">Raw JSON</summary>'
            . $datastorePreHtml . '</details>',
        debug_toolbar($datastorePreId, "mdadm-datastore-{$appId}.json")
    );

    // --- Sensors panel ------------------------------------------------
    $rows = $allSensors->map(fn ($s) => [
        'sensor_oid'        => $s->sensor_oid,
        'sensor_type'       => $s->sensor_type,
        'group'             => $s->group,
        'sensor_navigation' => $s->sensor_navigation,
        'sensor_index'      => $s->sensor_index,
        'sensor_descr'      => $s->sensor_descr,
        'current'           => $s->sensor_current,
    ])->toArray();
    $rowCount = count($rows);

    if (empty($rows)) {
        $sensorBody = '<p class="text-muted">No sensors found.</p>';
        $sensorToolbar = '';
    } else {
        $sensorRows = '';
        foreach ($rows as $r) {
            $cells = implode('', array_map(
                static fn ($col) => '<td>' . htmlspecialchars((string) ($r[$col] ?? '')) . '</td>',
                $sensorColumns
            ));
            $sensorRows .= "<tr>{$cells}</tr>\n";
        }
        $sensorHeaders = implode('', array_map(
            static fn ($col) => '<th>' . htmlspecialchars($col) . '</th>',
            $sensorColumns
        ));
        $sensorBody = <<<HTML
            <table class="table table-condensed table-hover" style="font-size:12px">
                <thead><tr>{$sensorHeaders}</tr></thead>
                <tbody>{$sensorRows}</tbody>
            </table>
            HTML;
        $csvDataUri = mdadm_debug_sensor_csv_uri($rows);
        $sensorToolbar = <<<HTML
            <a class="btn btn-xs btn-default" href="{$csvDataUri}" download="mdadm-sensors-{$appId}.csv">
                <i class="fa fa-download"></i> Export CSV
            </a>
            HTML;
    }

    $sensorPanel = debug_panel(
        "Debug: Sensors (app:mdadm:*) &mdash; {$rowCount} row(s)",
        $sensorBody,
        $sensorToolbar
    );

    debug_render('mdadm-debug-panels', $dbPanel, $datastorePanel, $sensorPanel);
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
$renderer = new MdadmAppRenderer($htmlData, $vars);
$renderer->renderOptionbar();
mdadm_debug_render(
    $htmlData->app->app_id,
    (array) ($htmlData->app->data ?? []),
    $htmlData->allSensors,
    'mdadm',
    (string) ($htmlData->device['hostname'] ?? '')
);

if (($vars['array'] ?? null) === null) {
    $renderer->renderOverview();
} else {
    $renderer->renderArrayView();
    $renderer->renderArrayGraphs();
}
