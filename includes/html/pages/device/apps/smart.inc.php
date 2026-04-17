<?php

use App\Facades\Rrd;
use App\Models\Sensor;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

require_once __DIR__ . '/app-page-helpers.php';

class SmartPage
{
    use AppPageHelpers;
    private array $baseLink;
    private array $disk = [];
    private ?int $powerOnHours = null;
    private string $labelMode = 'device';
    private string $diskViewMode = 'basic';
    private bool $attrScaleScriptEmitted = false;

    public function __construct(
        private array $device,
        private mixed $app,
        private array $vars
    ) {
        $this->baseLink = [
            'page'   => 'device',
            'device' => $device['device_id'],
            'tab'    => 'apps',
            'app'    => 'smart',
        ];
    }

    public function render(): void
    {
        $this->labelMode = $this->resolveLabelMode();
        $this->setLabelModeCookie($this->labelMode);
        $this->renderDebug();

        if ($this->isV2()) {
            $this->renderV2();
        } else {
            $this->renderV1();
        }
    }

    // ---------------------------------------------------------------
    // Version detection
    // ---------------------------------------------------------------

    private function isV2(): bool
    {
        return isset($this->app->data['tables']['disks']);
    }

    // ---------------------------------------------------------------
    // V1 — existing behaviour, untouched
    // ---------------------------------------------------------------

    private function renderV1(): void
    {
        print_optionbar_start();

        $disks = $this->app->data['disks'] ?? [];

        if (! empty($disks)) {
            ksort($disks);
        }

        $driveLinks = [];
        foreach ($disks as $diskName => $diskData) {
            $label = $diskName;

            if (isset($this->vars['disk']) && $this->vars['disk'] === $diskName) {
                $label = "<span class=\"pagemenu-selected\">{$label}</span>";
            }

            $healthStatus = match ($diskData['health_pass'] ?? null) {
                1 => ' (OK)',
                0 => ' (FAIL)',
                default => '',
            };

            $overheatingStatus = match ($diskData['over_temp'] ?? null) {
                1 => ' (Overheating)',
                default => '',
            };

            $pollingerrorStatus = match ($diskData['dev_error'] ?? null) {
                1 => ' (Polling Error)',
                default => '',
            };

            $readfailureStatus = '';
            if (isset($diskData['read_failure']) && $diskData['read_failure'] > 0) {
                $readfailureStatus = ' (Read Failure)';
            }

            $unknownfailureStatus = '';
            if (isset($diskData['unknown_failure']) && $diskData['unknown_failure'] > 0) {
                $unknownfailureStatus = ' (Unknown Failure)';
            }

            $driveLinks[] = generate_link($label, $this->baseLink, ['disk' => $diskName]) . $healthStatus . $overheatingStatus . $pollingerrorStatus . $readfailureStatus . $unknownfailureStatus;
        }

        echo generate_link('All Drives', $this->baseLink) . ' | drives: ' . implode(', ', $driveLinks);

        print_optionbar_end();

        if (isset($this->vars['disk'])) {
            $currentDisk = $disks[$this->vars['disk']] ?? [];

            if (! isset($this->app->data['legacy']) && ! empty($currentDisk)) {
                print_optionbar_start();

                $diskFields = [
                    'disk'         => 'Disk',
                    'serial'       => 'Serial',
                    'vendor'       => 'Vendor',
                    'product'      => 'Product',
                    'model_family' => 'Model Family',
                    'model_number' => 'Model Number',
                    'device_model' => 'Device Model',
                    'revision'     => 'Revision',
                    'fw_version'   => 'FW Version',
                    'form_factor'  => 'Form Factor',
                    'rpm'          => 'RPM',
                ];

                foreach ($diskFields as $field => $label) {
                    if (isset($currentDisk[$field])) {
                        echo "{$label}: {$currentDisk[$field]}<br>\n";
                    }
                }

                if (isset($currentDisk['selftest_log'])) {
                    echo '<pre>' . str_replace('n#', "\n#", $currentDisk['selftest_log']) . "</pre><br>\n";
                }

                print_optionbar_end();
            }

            $graphs = [
                'smart_big5'        => 'Reliability / Age',
                'smart_temp'        => 'Temperature',
                'smart_ssd'         => 'SSD-specific',
                'smart_other'       => 'Other',
                'smart_tests_status' => 'S.M.A.R.T self-tests results',
                'smart_tests_ran'   => 'S.M.A.R.T self-tests run count',
                'smart_runtime'     => 'Power On Hours',
            ];

            if (($currentDisk['is_ssd'] ?? 0) !== 1) {
                unset($graphs['smart_ssd']);
            }
        } else {
            $smartAttributes = [
                'id5'   => ['smart_id5',   'ID# 5, Reallocated Sectors Count'],
                'id9'   => ['smart_id9',   'ID# 9, Power On Hours'],
                'id10'  => ['smart_id10',  'ID# 10, Spin Retry Count'],
                'id173' => ['smart_id173', 'ID# 173, SSD Wear Leveller Worst Case Erase Count'],
                'id177' => ['smart_id177', 'ID# 177, SSD Wear Leveling Count'],
                'id183' => ['smart_id183', 'ID# 183, Detected Uncorrectable Bad Blocks'],
                'id184' => ['smart_id184', 'ID# 184, End-to-End error / IOEDC'],
                'id187' => ['smart_id187', 'ID# 187, Reported Uncorrectable Errors'],
                'id188' => ['smart_id188', 'ID# 188, Command Timeout'],
                'id190' => ['smart_id190', 'ID# 190, Airflow Temperature (C)'],
                'id194' => ['smart_id194', 'ID# 194, Temperature (C)'],
                'id196' => ['smart_id196', 'ID# 196, Reallocation Event Count'],
                'id197' => ['smart_id197', 'ID# 197, Current Pending Sector Count'],
                'id198' => ['smart_id198', 'ID# 198, Uncorrectable Sector Count / Offline Uncorrectable / Off-Line Scan Uncorrectable Sector Count'],
                'id199' => ['smart_id199', 'ID# 199, UltraDMA CRC Error Count'],
                'id231' => ['smart_id231', 'ID# 231, SSD Life Left'],
                'id232' => ['smart_id232', 'ID# 232, Available Reserved Space'],
                'id233' => ['smart_id233', 'ID# 233, Media Wearout Indicator'],
            ];

            $graphs = [];
            $hasData = $this->app->data['has'] ?? [];

            foreach ($smartAttributes as $attribute => [$graphKey, $graphLabel]) {
                if (($hasData[$attribute] ?? 0) === 1) {
                    $graphs[$graphKey] = $graphLabel;
                }
            }

            if (($hasData['id190'] ?? 0) === 1 || ($hasData['id194'] ?? 0) === 1) {
                $graphs = ['smart_maxtemp' => 'Max Temp(C), Airflow Temperature or Device'] + $graphs;
            }
        }

        foreach ($graphs as $graphKey => $graphTitle) {
            $graph_array = [
                'height'    => '100',
                'width'     => '215',
                'to'        => App\Facades\LibrenmsConfig::get('time.now'),
                'id'        => $this->app['app_id'],
                'type'      => "application_{$graphKey}",
                'scale_min' => '0',
            ];

            if (isset($this->vars['disk'])) {
                $graph_array['disk'] = $this->vars['disk'];
            }

            echo <<<HTML
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">{$graphTitle}</h3>
                </div>
                <div class="panel-body">
                    <div class="row">
            HTML;

            include 'includes/html/print-graphrow.inc.php';

            echo <<<'HTML'
                    </div>
                </div>
            </div>
            HTML;
        }
    }

    // ---------------------------------------------------------------
    // Debug
    // ---------------------------------------------------------------

    private function renderDebug(): void
    {
        $user = Auth::user();
        if (! $user instanceof User || ! $user->hasRole('admin')) {
            return;
        }

        $diskIdx = isset($this->vars['disk'])
            ? $this->diskIndex((string) $this->vars['disk'])
            : null;

        $appData = (array) ($this->app->data ?? []);
        $fullDataJson = htmlspecialchars(
            json_encode($appData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}'
        );

        if ($diskIdx !== null) {
            $rawKey = (string) $this->vars['disk'];
            $filteredData = $appData;
            if (isset($filteredData['tables']['disks'])) {
                $filteredData['tables']['disks'] = isset($filteredData['tables']['disks'][$rawKey])
                    ? [$rawKey => $filteredData['tables']['disks'][$rawKey]]
                    : [];
            }
            if (isset($filteredData['Discovery']['disk_list'])) {
                $filteredData['Discovery']['disk_list'] = isset($filteredData['Discovery']['disk_list'][$rawKey])
                    ? [$rawKey => $filteredData['Discovery']['disk_list'][$rawKey]]
                    : [];
            }
            $filteredDataJson = htmlspecialchars(
                json_encode($filteredData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}'
            );
        }

        $rows = Sensor::where('device_id', $this->device['device_id'])
            ->where('sensor_oid', 'like', 'app:smart:%')
            ->orderBy('sensor_descr')
            ->get()
            ->map(fn ($s) => [
                'sensor_oid' => $s->sensor_oid,
                'sensor_type' => $s->sensor_type,
                'group' => $s->group,
                'sensor_navigation' => $s->sensor_navigation,
                'sensor_index' => $s->sensor_index,
                'sensor_descr' => $s->sensor_descr,
                'current' => $s->sensor_current,
            ])->toArray();

        $rowCount = count($rows);

        $datastoreInfo = ['stores' => [], 'stats' => []];
        try {
            $datastore = app('Datastore');
            if (method_exists($datastore, 'getStores')) {
                $datastoreInfo['stores'] = array_values(array_map(
                    static fn ($store) => method_exists($store, 'getName') ? (string) $store->getName() : get_class($store),
                    (array) $datastore->getStores()
                ));
            }
            if (method_exists($datastore, 'getStats')) {
                $stats = $datastore->getStats();
                $datastoreInfo['stats'] = method_exists($stats, 'toArray') ? $stats->toArray() : (array) $stats;
            }
            $datastoreInfo['smart_stored_data'] = $this->buildDatastoreStoredData('smart', 'disk');
        } catch (Throwable) {
            $datastoreInfo['error'] = 'Datastore info unavailable';
        }

        $datastoreTablesHtml = $this->buildDatastoreTablesHtml($datastoreInfo, 'smart_stored_data', 'Disk', 'disk');

        if (empty($rows)) {
            $tableHtml = '<p class="text-muted">No sensors found.</p>';
        } else {
            $sensorRows = '';
            foreach ($rows as $r) {
                $oid = htmlspecialchars((string) $r['sensor_oid']);
                $type = htmlspecialchars((string) $r['sensor_type']);
                $group = htmlspecialchars((string) $r['group']);
                $sensorNavigation = htmlspecialchars((string) $r['sensor_navigation']);
                $index = htmlspecialchars((string) $r['sensor_index']);
                $descr = htmlspecialchars((string) $r['sensor_descr']);
                $curr = htmlspecialchars((string) $r['current']);
                $sensorRows .= <<<HTML
                    <tr data-oid="{$oid}">
                        <td>{$oid}</td><td>{$type}</td><td>{$group}</td>
                        <td>{$sensorNavigation}</td><td>{$index}</td><td>{$descr}</td><td>{$curr}</td>
                    </tr>
                    HTML;
            }
            $csvDataUri = $this->buildDebugCsvDataUri($rows);
            $tableHtml = <<<HTML
                <div class="text-right" style="margin-bottom:8px">
                    <a class="btn btn-xs btn-default" href="{$csvDataUri}" download="smart-sensors.csv">
                        <i class="fa fa-download"></i> Export CSV
                    </a>
                </div>
                <table class="table table-condensed table-hover" style="font-size:12px">
                    <thead>
                        <tr>
                            <th>sensor_oid</th><th>type</th><th>group</th>
                            <th>sensor_navigation</th><th>index</th><th>descr</th><th>current</th>
                        </tr>
                    </thead>
                    <tbody>{$sensorRows}</tbody>
                </table>
                HTML;
        }

        echo <<<'HTML'
            <div class="text-right" style="margin-bottom:6px">
                <a class="btn btn-xs btn-default" data-toggle="collapse" href="#smart-debug-panels"
                   aria-expanded="false" aria-controls="smart-debug-panels">
                    <i class="fa fa-bug"></i> Debug
                </a>
            </div>
            <div id="smart-debug-panels" class="collapse">
            HTML;

        $dbToggle = '';
        $storeToggle = '';
        $sensorToggle = '';
        if ($diskIdx !== null) {
            $diskIdxEsc = htmlspecialchars($diskIdx, ENT_QUOTES);
            $toggleLabel = '<label style="font-weight:normal;margin-left:12px;font-size:12px">';
            $toggleAttrs = 'checked data-diskidx="' . $diskIdxEsc . '"';
            $toggleSuffix = 'current drive only</label>';
            $dbToggle = $toggleLabel
                . '<input type="checkbox" id="smart-debug-db-filter" ' . $toggleAttrs
                . ' onchange="smartDebugDbFilter(this)"> ' . $toggleSuffix;
            $storeToggle = $toggleLabel
                . '<input type="checkbox" id="smart-debug-store-filter" ' . $toggleAttrs
                . ' onchange="smartDebugStoreFilter(this)"> ' . $toggleSuffix;
            $sensorToggle = $toggleLabel
                . '<input type="checkbox" id="smart-debug-disk-filter" ' . $toggleAttrs
                . ' onchange="smartDebugFilter(this)"> ' . $toggleSuffix;
        }

        $this->panelStart('Debug: Database Data' . $dbToggle);
        if ($diskIdx !== null) {
            echo '<pre id="smart-debug-db-filtered" style="max-height:260px;overflow:auto">' . $filteredDataJson . '</pre>';
            echo '<pre id="smart-debug-db-full" style="max-height:260px;overflow:auto;display:none">' . $fullDataJson . '</pre>';
        } else {
            echo '<pre style="max-height:260px;overflow:auto">' . $fullDataJson . '</pre>';
        }
        $this->panelEnd();

        $this->panelStart('Debug: Datastore (app(\'Datastore\'))' . $storeToggle);
        echo $datastoreTablesHtml;
        $this->panelEnd();

        $this->panelStart('Debug: Sensors (app:smart:*) &mdash; ' . $rowCount . ' row(s)' . $sensorToggle);
        echo $tableHtml;
        $this->panelEnd();

        echo '</div>';
        echo <<<'JS'
            <script>
            function smartDebugFilter(cb) {
                var diskIdx = cb.dataset.diskidx;
                var prefix  = 'app:smart:' + diskIdx + '_';
                document.querySelectorAll('#smart-debug-panels tbody tr[data-oid]').forEach(function(tr) {
                    tr.style.display = (!cb.checked || tr.dataset.oid.startsWith(prefix)) ? '' : 'none';
                });
            }
            function smartDebugDbFilter(cb) {
                document.getElementById('smart-debug-db-filtered').style.display = cb.checked ? '' : 'none';
                document.getElementById('smart-debug-db-full').style.display     = cb.checked ? 'none' : '';
            }
            function smartDebugStoreFilter(cb) {
                var diskIdx = cb.dataset.diskidx;
                document.querySelectorAll('#smart-debug-panels tbody tr[data-disk]').forEach(function(tr) {
                    tr.style.display = (!cb.checked || tr.dataset.disk.startsWith(diskIdx)) ? '' : 'none';
                });
            }
            (function() {
                var cb;
                cb = document.getElementById('smart-debug-db-filter');
                if (cb) smartDebugDbFilter(cb);
                cb = document.getElementById('smart-debug-store-filter');
                if (cb) smartDebugStoreFilter(cb);
                cb = document.getElementById('smart-debug-disk-filter');
                if (cb) smartDebugFilter(cb);
            })();
            </script>
            JS;
    }

    // ---------------------------------------------------------------
    // V2
    // ---------------------------------------------------------------

    private function renderV2(): void
    {
        $disks = $this->app->data['tables']['disks'] ?? [];

        if (isset($this->vars['disk'])) {
            $this->diskViewMode = $this->resolveDiskViewMode();
            $this->setDiskViewModeCookie($this->diskViewMode);
        }

        $this->renderNavigation($disks);

        if (isset($this->vars['disk'])) {
            $disk = $disks[$this->vars['disk']] ?? null;
            if ($disk !== null) {
                $this->renderDrive($this->vars['disk'], $disk);
            }
        } else {
            $this->renderOverview($disks);
        }
    }

    private function renderNavigation(array $disks): void
    {
        print_optionbar_start();

        $links = [generate_link('All Drives', $this->baseLink)];

        foreach (array_keys($disks) as $key) {
            $disk = is_array($disks[$key] ?? null) ? $disks[$key] : [];
            $label = htmlspecialchars($this->displayLabel($disk, (string) $key, $this->labelMode));
            if (isset($this->vars['disk']) && $this->vars['disk'] === $key) {
                $label = "<span class=\"pagemenu-selected\">{$label}</span>";
            }

            $links[] = generate_link($label, $this->baseLink, ['disk' => $key]);
        }

        $currentUrl = isset($this->vars['disk'])
            ? LibreNMS\Util\Url::generate($this->baseLink + ['disk' => (string) $this->vars['disk']])
            : LibreNMS\Util\Url::generate($this->baseLink);

        $cookieName = htmlspecialchars($this->labelModeCookieName(), ENT_QUOTES);
        $urlEsc = htmlspecialchars($currentUrl, ENT_QUOTES);
        $modeOptions = '';
        foreach ($this->labelModes() as $mode => $title) {
            $modeEsc = htmlspecialchars($mode, ENT_QUOTES);
            $titleEsc = htmlspecialchars($title);
            $selected = $mode === $this->labelMode ? ' selected' : '';
            $modeOptions .= "<option value=\"{$modeEsc}\"{$selected}>{$titleEsc}</option>";
        }

        echo '<div class="pull-right" style="margin-left:10px">'
            . '<label for="smart-label-mode" style="margin-right:6px">Label:</label>'
            . '<select id="smart-label-mode" class="form-control input-sm" style="display:inline-block;width:auto" '
            . 'onchange="document.cookie=\'' . $cookieName . '=\' + this.value + \'; path=/; max-age=31536000; samesite=lax\'; window.location.href=\'' . $urlEsc . '\';">'
            . $modeOptions
            . '</select>'
            . '</div>';

        echo implode(' | ', $links);

        if (isset($this->vars['disk']) && isset($disks[$this->vars['disk']])) {
            $selectedDisk = is_array($disks[$this->vars['disk']] ?? null) ? $disks[$this->vars['disk']] : [];
            if (! $this->isNvmeDisk($selectedDisk)) {
                $this->renderDiskViewNavigation();
            }
        }

        print_optionbar_end();
    }

    private function renderDiskViewNavigation(): void
    {
        //print_optionbar_start();

        $currentUrl = LibreNMS\Util\Url::generate($this->baseLink + ['disk' => (string) $this->vars['disk']]);
        $cookieName = htmlspecialchars($this->diskViewModeCookieName(), ENT_QUOTES);
        $urlEsc = htmlspecialchars($currentUrl, ENT_QUOTES);

        $links = [];
        foreach ($this->diskViewModes() as $mode => $title) {
            $label = htmlspecialchars($title);
            if ($mode === $this->diskViewMode) {
                $label = '<span class="pagemenu-selected">' . $label . '</span>';
            }

            $modeEsc = htmlspecialchars($mode, ENT_QUOTES);
            $links[] = '<a href="' . $urlEsc . '" onclick="document.cookie=\'' . $cookieName . '=' . $modeEsc . '; path=/; max-age=31536000; samesite=lax\';">' . $label . '</a>';
        }

        echo '<br>&nbsp;&nbsp; Disk: ' . implode(' | ', $links);

        //print_optionbar_end();
    }

    private function renderOverview(array $disks): void
    {
        $sensors = Sensor::where('device_id', $this->device['device_id'])
            ->where('sensor_oid', 'like', 'app:smart:%')
            ->get()
            ->keyBy('sensor_index');

        $this->panelStart('Drives');
        echo <<<'HTML'
            <div class="table-responsive">
                <table class="table table-condensed table-striped table-hover">
                    <thead><tr>
                        <th>Device</th>
                        <th>Model</th>
                        <th>Serial</th>
                        <th>Type</th>
                        <th>Temp (°C)</th>
                        <th>Health</th>
                        <th>Wear</th>
                        <th>Last Short Self-test</th>
                        <th>Last Long Self-test</th>
                    </tr></thead>
                    <tbody>
        HTML;

        foreach ($disks as $key => $disk) {
            $deviceLabel = htmlspecialchars($this->overviewDeviceName($disk, (string) $key));
            $diskLink = generate_link($deviceLabel, $this->baseLink, ['disk' => $key]);
            $modelText = htmlspecialchars($this->overviewModel($disk));
            $model = generate_link($modelText, $this->baseLink, ['disk' => $key]);
            $serialValue = $this->overviewSerial($disk);
            $serialText = htmlspecialchars($serialValue !== '' ? $serialValue : '—');
            $serial = $serialValue !== '' ? generate_link($serialText, $this->baseLink, ['disk' => $key]) : $serialText;
            $type = htmlspecialchars($this->overviewType($disk));
            $temp = $this->overviewTempBadge($disk, (string) $key, $sensors);
            $health = $this->overviewHealthBadge($disk, (string) $key, $sensors);
            $wear = $this->overviewWearBadge($disk, (string) $key, $sensors);
            $short = $this->overviewSelftestBadge($disk, (string) $key, 'short', $sensors);
            $long = $this->overviewSelftestBadge($disk, (string) $key, 'extended', $sensors);

            echo "<tr><td>{$diskLink}</td><td>{$model}</td><td>{$serial}</td><td>{$type}</td><td>{$temp}</td><td>{$health}</td><td>{$wear}</td><td>{$short}</td><td>{$long}</td></tr>\n";
        }

        echo <<<'HTML'
                    </tbody>
                </table>
            </div>
        HTML;
        $this->panelEnd();

        $this->renderOverviewGraphs($disks);
    }

    private function renderOverviewGraphs(array $disks): void
    {
        $appId = $this->app->app_id;
        $baseGraph = [
            'id'     => $appId,
            'from'   => App\Facades\LibrenmsConfig::get('time.day'),
            'to'     => App\Facades\LibrenmsConfig::get('time.now'),
            'legend' => 'no',
        ];

        // ── All temperatures ──────────────────────────────────────────────────
        $graph_array = $baseGraph + ['height' => '100', 'width' => '215', 'type' => 'application_smart_v2_all_temp'];
        $this->panelStart('All Temperatures');
        echo '<div class="row">';
        include 'includes/html/print-graphrow.inc.php';
        echo '</div>';
        $this->panelEnd();

        // ── All wear ──────────────────────────────────────────────────────────
        $graph_array = $baseGraph + ['height' => '100', 'width' => '215', 'type' => 'application_smart_v2_all_wear'];
        $this->panelStart('Wear Remaining');
        echo '<div class="row">';
        include 'includes/html/print-graphrow.inc.php';
        echo '</div>';
        $this->panelEnd();

        // ── Per-attribute-ID multiline graphs ─────────────────────────────────
        $attrIds = $this->collectOverviewAttrIds($disks);
        foreach ($attrIds as $id => $name) {
            $graph_array = $baseGraph + [
                'height'  => '100',
                'width'   => '215',
                'type'    => 'application_smart_v2_attr_multi',
                'attr_id' => $id,
            ];
            $title = 'ID# ' . $id . ', ' . $name;
            $this->panelStart(htmlspecialchars($title));
            echo '<div class="row">';
            include 'includes/html/print-graphrow.inc.php';
            echo '</div>';
            $this->panelEnd();
        }
    }

    private function collectOverviewAttrIds(array $disks): array
    {
        $attrIds = [];
        foreach ($disks as $disk) {
            foreach ($disk['attributes']['ata'] ?? [] as $attr) {
                $id = isset($attr['id']) ? (int) $attr['id'] : 0;
                if ($id <= 0 || isset($attrIds[$id])) {
                    continue;
                }
                $attrIds[$id] = trim((string) ($attr['name'] ?? 'Attribute ' . $id));
            }
        }
        ksort($attrIds);

        return $attrIds;
    }

    private function resolveLabelMode(): string
    {
        $modes = $this->labelModes();
        $cookie = $_COOKIE[$this->labelModeCookieName()] ?? null;
        if (is_string($cookie) && array_key_exists($cookie, $modes)) {
            return $cookie;
        }

        return 'device';
    }

    private function setLabelModeCookie(string $mode): void
    {
        if (headers_sent() || (($_COOKIE[$this->labelModeCookieName()] ?? null) === $mode)) {
            return;
        }

        setcookie($this->labelModeCookieName(), $mode, [
            'expires' => time() + 60 * 60 * 24 * 365,
            'path' => '/',
            'samesite' => 'Lax',
        ]);
    }

    private function labelModeCookieName(): string
    {
        return 'smart_label_mode_' . (string) ($this->device['device_id'] ?? '0');
    }

    private function labelModes(): array
    {
        return [
            'device' => 'Device',
            'serial' => 'Serial',
            'device_serial' => 'Device (Serial)',
            'model_serial' => 'Model (Serial)',
        ];
    }

    private function displayLabel(array $disk, string $diskKey, string $mode): string
    {
        $device = $this->overviewDeviceName($disk, $diskKey);
        $serial = $this->overviewSerial($disk);
        $model = $this->overviewModel($disk);

        return match ($mode) {
            'serial' => $serial !== '' ? $serial : $device,
            'device_serial' => $serial !== '' ? "{$device} ({$serial})" : $device,
            'model_serial' => $serial !== '' ? "{$model} ({$serial})" : $model,
            default => $device,
        };
    }

    private function overviewDeviceName(array $disk, string $diskKey): string
    {
        $devName = $disk['identity']['dev_name'] ?? null;
        if (is_string($devName) && trim($devName) !== '') {
            return $devName;
        }

        return $diskKey;
    }

    private function overviewSerial(array $disk): string
    {
        $serial = $disk['identity']['serial_number'] ?? null;

        return is_string($serial) ? trim($serial) : '';
    }

    private function overviewModel(array $disk): string
    {
        foreach (['model_name', 'device_model', 'model_number', 'product'] as $field) {
            $value = $disk['identity'][$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return '—';
    }

    private function overviewType(array $disk): string
    {
        $protocol = (string) ($disk['identity']['protocol'] ?? $disk['identity']['device_type'] ?? '');
        $protocol = trim($protocol);

        $media = null;
        $rotationRate = $disk['identity']['rotation_rate'] ?? null;
        if (is_numeric($rotationRate)) {
            $media = (int) $rotationRate === 0 ? 'SSD' : 'HDD';
        } elseif (strtolower($protocol) === 'nvme') {
            $media = 'SSD';
        }

        if ($protocol !== '' && $media !== null) {
            return $protocol . ' ' . $media;
        }

        if ($protocol !== '') {
            return $protocol;
        }

        return $media ?? '—';
    }

    private function overviewTemperature(array $disk): string
    {
        $temp = $disk['temperature']['current_c'] ?? $disk['temperature'] ?? null;
        if (! is_numeric($temp)) {
            return '—';
        }

        return rtrim(rtrim(number_format((float) $temp, 1, '.', ''), '0'), '.');
    }

    private function overviewHealthBadge(array $disk, string $diskKey, mixed $sensors): string
    {
        $diskIdx = $this->diskIndex($diskKey);

        $sensor = $sensors->get("{$diskIdx}_nvme_crit_warn") ?? $sensors->get("{$diskIdx}_health");
        if ($sensor) {
            $value = (int) $sensor->sensor_current;
            if ($value === 0) {
                return '<span class="text-muted">OK</span>';
            }

            if ($sensor->sensor_type === 'smart_nvme_crit_warn') {
                $class = in_array($value, [4, 8], true) ? 'danger' : 'warning';

                return '<span class="label label-' . $class . '">Warn</span>';
            }

            return '<span class="label label-danger">FAIL</span>';
        }

        $passed = $disk['health']['smart_passed'] ?? null;
        if ($passed === true) {
            return '<span class="text-muted">OK</span>';
        }

        if ($passed === false) {
            return '<span class="label label-danger">FAIL</span>';
        }

        return '<span class="text-muted">—</span>';
    }

    private function overviewWearBadge(array $disk, string $diskKey, mixed $sensors): string
    {
        $diskIdx = $this->diskIndex($diskKey);
        $sensor = $sensors->get("{$diskIdx}_wear");

        if ($sensor && $sensor->sensor_current !== null) {
            $value = (float) $sensor->sensor_current;
            $rounded = (int) round(max(0.0, min(100.0, $value)));
            $warnLow = $sensor->sensor_limit_low_warn !== null ? (float) $sensor->sensor_limit_low_warn : 20.0;
            $hardLow = $sensor->sensor_limit_low !== null ? (float) $sensor->sensor_limit_low : 10.0;

            if ($value <= $hardLow) {
                return '<span class="label label-danger">' . $rounded . '%</span>';
            }

            if ($value <= $warnLow) {
                return '<span class="label label-warning">' . $rounded . '%</span>';
            }

            return '<span class="text-muted">' . $rounded . '%</span>';
        }

        $wearRemaining = $this->overviewWearRemaining($disk);
        if ($wearRemaining === null) {
            return '<span class="text-muted">—</span>';
        }

        $rounded = (int) round(max(0.0, min(100.0, $wearRemaining)));
        if ($rounded <= 10) {
            return '<span class="label label-danger">' . $rounded . '%</span>';
        }

        if ($rounded <= 20) {
            return '<span class="label label-warning">' . $rounded . '%</span>';
        }

        return '<span class="text-muted">' . $rounded . '%</span>';
    }

    private function overviewWearRemaining(array $disk): ?float
    {
        $nvmeUsed = $disk['nvme_smart_health_information_log']['percentage_used']
            ?? $disk['health']['nvme_smart_health_information_log']['percentage_used']
            ?? $disk['stats']['nvme_smart_health_information_log']['percentage_used']
            ?? null;
        if (is_numeric($nvmeUsed)) {
            return 100.0 - (float) $nvmeUsed;
        }

        foreach ($disk['attributes']['ata'] ?? [] as $attr) {
            if (in_array($attr['id'] ?? -1, [173, 177, 202, 231, 233], true) && isset($attr['value']) && is_numeric($attr['value'])) {
                return (float) $attr['value'];
            }
        }

        return null;
    }

    private function overviewSelftestAge(array $disk, string $typePattern): string
    {
        $currentHours = $disk['power']['power_on_time']['hours'] ?? null;
        if (! is_numeric($currentHours)) {
            return '—';
        }

        $table = $disk['selftest']['ata_smart_self_test_log']['extended']['table'] ?? [];

        foreach ($table as $entry) {
            $typeStr = strtolower((string) ($entry['type']['string'] ?? ''));
            $lifetimeHours = $entry['lifetime_hours'] ?? null;
            if (! str_contains($typeStr, $typePattern) || ! is_numeric($lifetimeHours)) {
                continue;
            }

            $delta = max(0, (int) $currentHours - (int) $lifetimeHours);

            return ltrim($this->formatHoursAgo($delta), '-') . ' ago';
        }

        return '—';
    }

    private function overviewTempBadge(array $disk, string $diskKey, mixed $sensors): string
    {
        $text = $this->overviewTemperature($disk);
        if ($text === '—') {
            return '<span class="text-muted">—</span>';
        }

        $diskIdx = $this->diskIndex($diskKey);
        $sensor = $sensors->get("{$diskIdx}_temp");
        $class = 'default';

        if ($sensor) {
            // updateNumericSensor() applies the multiplier before saving, so sensor_current is already in final units (°C)
            $value = (float) $sensor->sensor_current;
            $warnLimit = $sensor->sensor_limit_warn !== null ? (float) $sensor->sensor_limit_warn : null;
            $hardLimit = $sensor->sensor_limit !== null ? (float) $sensor->sensor_limit : null;

            if ($hardLimit !== null && $value >= $hardLimit) {
                $class = 'danger';
            } elseif ($warnLimit !== null && $value >= $warnLimit) {
                $class = 'warning';
            }
        }

        return '<span class="label label-' . $class . '">' . htmlspecialchars($text) . '°C</span>';
    }

    private function overviewSelftestBadge(array $disk, string $diskKey, string $type, mixed $sensors): string
    {
        $text = $this->overviewSelftestAge($disk, $type);
        if ($text === '—') {
            return '<span class="text-muted">—</span>';
        }

        $diskIdx = $this->diskIndex($diskKey);
        $sensorKey = $type === 'short' ? "{$diskIdx}_selftest_short" : "{$diskIdx}_selftest_long";
        $sensor = $sensors->get($sensorKey);
        $class = 'default';

        if ($sensor) {
            // updateNumericSensor() applies the multiplier before saving, so sensor_current is in minutes
            $value = (float) $sensor->sensor_current;
            $warnLimit = $sensor->sensor_limit_warn !== null ? (float) $sensor->sensor_limit_warn : null;
            $hardLimit = $sensor->sensor_limit !== null
                ? (float) $sensor->sensor_limit
                : ($sensor->sensor_max !== null ? (float) $sensor->sensor_max : null);

            if ($hardLimit !== null && $value >= $hardLimit) {
                $class = 'danger';
            } elseif ($warnLimit !== null && $value >= $warnLimit) {
                $class = 'warning';
            }
        }

        return '<span class="label label-' . $class . '">' . htmlspecialchars($text) . '</span>';
    }

    private function renderDrive(string $key, array $disk): void
    {
        $this->disk = $disk;
        $this->powerOnHours = isset($disk['power']['power_on_time']['hours'])
            ? (int) $disk['power']['power_on_time']['hours']
            : null;

        echo '<style>
            .smart-panels{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start;margin-bottom:15px}
            .smart-panels .panel{flex:0 0 auto;margin-bottom:0}
            .smart-panels table{white-space:nowrap}
        </style>';

        if ($this->diskIsNvme()) {
            $this->renderNvmeBasic($key);

            return;
        }

        match ($this->diskViewMode) {
            'graphs'   => $this->renderDrivePageGraphs($key),
            'basic'    => $this->renderDrivePageBasic($key),
            default    => $this->renderDrivePageDetailed($key),
        };
    }

    private function renderNvmeBasic(string $key): void
    {
        $this->panelsRowStart();
        if (isset($this->disk['identity'])) {
            $this->renderNvmeIdentity();
        }

        if (isset($this->disk['selftest']['nvme_self_test_log']) && is_array($this->disk['selftest']['nvme_self_test_log'])) {
            $this->renderNvmeSelftest($this->disk['selftest']['nvme_self_test_log']);
        }

        if (isset($this->disk['stats']) && is_array($this->disk['stats'])) {
            $this->renderNvmeStats();
        }
        $this->panelsRowEnd();

        $this->renderNvmeGraphs($key);
    }

    private function renderNvmeGraphs(string $key): void
    {
        $diskIdx = $this->diskIndex($key);
        $sensors = Sensor::where('device_id', $this->device['device_id'])
            ->where('sensor_oid', 'like', "app:smart:{$diskIdx}_%")
            ->get()
            ->keyBy('sensor_index');

        $nvmeLog = $this->disk['health']['nvme_smart_health_information_log'] ?? [];
        $wearHeader = [];
        if (is_numeric($nvmeLog['available_spare_threshold'] ?? null)) {
            $wearHeader[] = 'Limit: ' . (int) $nvmeLog['available_spare_threshold'] . '%';
        }
        if (is_numeric($nvmeLog['available_spare'] ?? null)) {
            $wearHeader[] = 'Spare: ' . (int) $nvmeLog['available_spare'] . '%';
        }
        if (is_numeric($nvmeLog['percentage_used'] ?? null)) {
            $wearHeader[] = 'Used: ' . (int) $nvmeLog['percentage_used'] . '%';
        }

        $this->renderAppRrdGraph(
            'smart_v2_nvme_wear',
            'Wear',
            $diskIdx,
            $wearHeader !== [] ? implode(' | ', $wearHeader) : null,
            [
                'avail_spare_threshold' => is_numeric($nvmeLog['available_spare_threshold'] ?? null)
                    ? (string) (int) $nvmeLog['available_spare_threshold']
                    : '',
            ]
        );

        $tempSensor = $sensors->get("{$diskIdx}_temp");
        if ($tempSensor) {
            $this->renderSensorGraph($tempSensor, 'Sensor Temperature');
        }

        $this->renderAppRrdGraph('smart_v2_nvme', 'Media Errors', $diskIdx, null, ['metric' => 'media_errors']);
        $this->renderAppRrdGraph('smart_v2_nvme', 'Data Units Read/Write', $diskIdx, null, ['metric' => 'data_units']);
        $this->renderAppRrdGraph('smart_v2_nvme', 'Host Reads/Writes', $diskIdx, null, ['metric' => 'host_io']);
        $this->renderAppRrdGraph('smart_v2_nvme', 'Controller Busy Time', $diskIdx, null, ['metric' => 'controller_busy']);

        $wearSensor = $sensors->get("{$diskIdx}_wear");
        if ($wearSensor) {
            $this->renderSensorGraph($wearSensor, 'Remaining');
        }
    }

    private function renderNvmeIdentity(): void
    {
        $data = $this->disk['identity'];

        $title = htmlspecialchars(
            (string) ($data['dev_name'] ?? $data['device_path'] ?? 'NVMe Identity')
        );

        $passed = $this->disk['health']['smart_passed'] ?? null;
        $badge = match ($passed) {
            true  => '<span class="label label-success">Passed</span>',
            false => '<span class="label label-danger">Failed</span>',
            default => '',
        };

        $flat = [];
        $seen = [];

        $add = function (string $key, mixed $value) use (&$flat, &$seen): void {
            if (isset($seen[$key]) || $value === '' || $value === null) {
                return;
            }

            $seen[$key] = true;
            if ($key === 'capacity_bytes' && is_int($value)) {
                $flat[$key] = LibreNMS\Util\Number::formatBi($value);
            } elseif (in_array($key, ['logical_block_size', 'physical_block_size'], true) && is_int($value)) {
                $flat[$key] = LibreNMS\Util\Number::formatSi($value, 0, 0, 'B');
            } else {
                $flat[$key] = $value;
            }
        };

        foreach (['model_name', 'device_model', 'model_number'] as $alias) {
            if (isset($data[$alias]) && $data[$alias] !== '') {
                $add('model_name', $data[$alias]);
                foreach (['model_name', 'device_model', 'model_number'] as $nameKey) {
                    $seen[$nameKey] = true;
                }
                break;
            }
        }

        $add('serial_number', $data['serial_number'] ?? null);
        $add('dev_name', $data['dev_name'] ?? null);
        $add('device_path', $data['device_path'] ?? null);
        $add('capacity_bytes', $data['capacity_bytes'] ?? null);
        $add('firmware_version', $data['firmware_version'] ?? null);
        $add('protocol', $data['protocol'] ?? null);
        $add('logical_block_size', $data['logical_block_size'] ?? null);
        $add('physical_block_size', $data['physical_block_size'] ?? null);
        $add('form_factor', $data['form_factor'] ?? null);

        $skip = [
            'ata_version',
            'device_model',
            'interface_speed',
            'model_family',
            'model_number',
            'rotation_rate',
            'sata_version',
            'wwn',
        ];
        foreach ($data as $k => $v) {
            if (in_array($k, $skip, true) || isset($seen[$k])) {
                continue;
            }
            $add($k, $v);
        }

        $this->panelStart($title, $badge);
        echo '<div class="table-responsive">';
        $this->renderFlatTable($flat);
        echo '</div>';
        $this->panelEnd();
    }

    private function renderDrivePageGraphs(string $key): void
    {
        $this->renderDriveGraphs($key, $this->disk);
    }

    private function renderDrivePageBasic(string $key): void
    {
        $this->panelsRowStart();
        if (isset($this->disk['identity'])) {
            $this->renderIdentity();
        }
        if (isset($this->disk['selftest'])) {
            $this->renderSelftest();
        }
        $this->panelsRowEnd();
        if (isset($this->disk['attributes'])) {
            $this->renderAttributes();
        }
        $this->renderDriveGraphs($key, $this->disk);
    }

    private function panelsRowStart(): void
    {
        echo '<div class="smart-panels">';
    }

    private function panelsRowEnd(): void
    {
        echo '</div>';
    }

    private function renderDrivePageDetailed(string $key): void
    {
        // Row 1: Identity + Self-test + any other small sections (flex)
        $skipInRow1 = ['source', 'temperature', 'identity', 'attributes', 'power', 'selftest', 'stats'];
        $this->panelsRowStart();
        if (isset($this->disk['identity'])) {
            $this->renderIdentity();
        }

        if (isset($this->disk['selftest'])) {
            $this->renderSelftest();
        }

        if (isset($this->disk['stats'])) {
            $this->renderStats();
        }

        foreach ($this->disk as $section => $data) {
            if (! in_array($section, $skipInRow1, true)) {
                $this->renderSection(ucfirst($section), $data);
            }
        }

        $this->panelsRowEnd();

        // Row 2: Attributes — full width
        if (isset($this->disk['attributes'])) {
            $this->renderAttributes();
        }

        // Row 3: Stats panels — flex
        if (isset($this->disk['stats'])) {
            // Row 4: Extended Error Log — full width
            $this->renderExtendedErrorLog();
        }

        // Row 5: RRD graphs
        $this->renderDriveGraphs($key, $this->disk);
    }

    // ---------------------------------------------------------------
    // Section-specific renderers
    // ---------------------------------------------------------------

    /** Same transform as smart.php diskIndex() — must stay in sync. */
    private function diskIndex(string $key): string
    {
        return substr((string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key), 0, 80);
    }

    private function diskIsNvme(): bool
    {
        return $this->isNvmeDisk($this->disk);
    }

    private function isNvmeDisk(array $disk): bool
    {
        $protocol = strtolower(trim((string) ($disk['identity']['protocol'] ?? $disk['identity']['device_type'] ?? '')));

        return $protocol === 'nvme';
    }

    private function diskIsAta(): bool
    {
        $protocol = strtolower(trim((string) ($this->disk['identity']['protocol'] ?? '')));
        $deviceType = strtolower(trim((string) ($this->disk['identity']['device_type'] ?? '')));

        return in_array($protocol, ['ata', 'sata'], true) || $deviceType === 'sat';
    }

    private function renderSensorGraph(mixed $sensor, string $title, string $badge = ''): void
    {
        $graph_array = [
            'height' => '100',
            'width'  => '215',
            'to'     => App\Facades\LibrenmsConfig::get('time.now'),
            'id'     => $sensor->sensor_id,
            'type'   => 'sensor_' . $sensor->sensor_class,
            'legend' => 'no',
        ];

        if ($badge === '') {
            $value = method_exists($sensor, 'formatValue')
                ? htmlspecialchars((string) $sensor->formatValue())
                : htmlspecialchars((string) $sensor->sensor_current);
            $badge = '<span class="text-muted">' . $value . '</span>';
        }

        $this->panelStart(htmlspecialchars($title), $badge);
        echo '<div class="row">';

        include 'includes/html/print-graphrow.inc.php';

        echo '</div>';
        $this->panelEnd();
    }

    private function renderAppRrdGraph(string $graphType, string $graphTitle, string $diskIdx, ?string $headerValue = null, array $extraVars = []): void
    {
        $graph_array = [
            'height'    => '100',
            'width'     => '215',
            'to'        => App\Facades\LibrenmsConfig::get('time.now'),
            'id'        => $this->app['app_id'],
            'type'      => "application_{$graphType}",
            'disk'      => $diskIdx,
            'scale_min' => '0',
        ];
        if ($extraVars !== []) {
            $graph_array = array_merge($graph_array, $extraVars);
        }

        $badge = $headerValue !== null && $headerValue !== ''
            ? '<span class="text-muted">' . htmlspecialchars($headerValue) . '</span>'
            : '';

        $this->panelStart(htmlspecialchars($graphTitle), $badge);
        echo '<div class="row">';

        include 'includes/html/print-graphrow.inc.php';

        echo '</div>';
        $this->panelEnd();
    }

    private function emitAttrScaleScript(): void
    {
        if ($this->attrScaleScriptEmitted) {
            return;
        }
        $this->attrScaleScriptEmitted = true;
        echo <<<'JS'
<script>
function smartAttrScaleToggle(cb, wrapperId) {
    var w = document.getElementById(wrapperId);
    if (!w) return;
    w.querySelectorAll('img.graph-image').forEach(function(img) {
        if (cb.checked) {
            if (img.src.indexOf('scale_min=') === -1) {
                img.src += (img.src.indexOf('?') !== -1 ? '&' : '?') + 'scale_min=0';
            }
        } else {
            img.src = img.src.replace(/[&?]scale_min=[^&]*/g, '');
        }
    });
}
</script>
JS;
    }

    private function renderDriveGraphs(string $diskKey, array $disk): void
    {
        $diskIdx = $this->diskIndex($diskKey);
        $isAta = $this->diskIsAta();
        $attributeSpecs = $this->ataAttributeGraphSpecs($diskIdx, $disk);

        $sensors = Sensor::where('device_id', $this->device['device_id'])
            ->where('sensor_oid', 'like', "app:smart:{$diskIdx}_%")
            ->get()
            ->keyBy('sensor_index');

        // ── Basic graphs ─────────────────────────────────────────────

        // Temperature
        $tempSensor = $sensors->get("{$diskIdx}_temp");
        if ($tempSensor) {
            $this->renderSensorGraph($tempSensor, 'Temperature');
        }

        // Health — colored Passed/Failed badge
        $healthSensor = $sensors->get("{$diskIdx}_health");
        if ($healthSensor) {
            $passed = $disk['health']['smart_passed'] ?? null;
            $healthBadge = match ($passed) {
                true    => '<span class="label label-success">Passed</span>',
                false   => '<span class="label label-danger">Failed</span>',
                default => '',
            };
            $this->renderSensorGraph($healthSensor, 'Health', $healthBadge);
        }

        // Wear
        $wearSensor = $sensors->get("{$diskIdx}_wear");
        if ($wearSensor) {
            $this->renderSensorGraph($wearSensor, 'Wear Remaining');
        }

        // Self-test age
        if ($sensors->has("{$diskIdx}_selftest_short") || $sensors->has("{$diskIdx}_selftest_long")) {
            $this->renderAppRrdGraph('smart_v2_selftest', 'Self-test Age', $diskIdx, $this->selftestHeaderValue($sensors, $diskIdx));
        }

        // ATA-only RRD graphs
        if ($isAta) {
            if ($this->hasBig5DatasetInRrd($diskIdx)) {
                $this->renderAppRrdGraph('smart_v2_big5', 'Reliability / Age (Big 5 ATA Attributes)', $diskIdx, $this->reliabilityHeaderValue($disk));
            }
            if ($this->hasOtherDatasetInRrd($diskIdx)) {
                $this->renderAppRrdGraph('smart_v2_other', 'Other', $diskIdx);
            }
            $this->renderAppRrdGraph('smart_v2_power', 'Power-on Hours', $diskIdx, $this->powerHeaderValue($disk));
        }

        // ── Attribute graphs ─────────────────────────────────────────

        if ($attributeSpecs !== []) {
            $this->emitAttrScaleScript();
            $wrapperId = 'smart-attr-graphs-' . htmlspecialchars($diskIdx);
            $toggleId  = 'smart-attr-scale-' . htmlspecialchars($diskIdx);
            echo '<h4 style="margin:20px 0 8px;border-bottom:1px solid #ddd;padding-bottom:6px">'
                . 'Attributes'
                . '<label style="float:right;font-size:13px;font-weight:normal;margin-bottom:0;cursor:pointer">'
                . '<input type="checkbox" id="' . $toggleId . '" checked'
                . ' onchange="smartAttrScaleToggle(this,\'' . $wrapperId . '\')">'
                . ' Scale from zero</label></h4>';
            echo '<div id="' . $wrapperId . '">';
            foreach ($attributeSpecs as $attr) {
                $this->renderAppRrdGraph(
                    'smart_v2_attributes',
                    $attr['title'],
                    $diskIdx,
                    $attr['header'],
                    [
                        'attr_id'     => (string) $attr['id'],
                        'attr_thresh' => $attr['thresh'] !== null ? (string) $attr['thresh'] : '',
                        'has_raw'     => $attr['has_raw'] ? '1' : '0',
                        'has_norm'    => $attr['has_norm'] ? '1' : '0',
                        'page_title'  => ($disk['identity']['dev_name'] ?? $diskKey) . ' ' . ($disk['identity']['model_name'] ?? '') . ' ' . ($disk['identity']['serial_number'] ?? '') . ' - ' . $attr['title'],
                    ]
                );
            }
            echo '</div>';
        }
    }

    private function selftestHeaderValue(mixed $sensors, string $diskIdx): string
    {
        $parts = [];
        $short = $sensors->get("{$diskIdx}_selftest_short");
        if ($short) {
            $parts[] = 'Short: ' . (string) ($short->formatValue() ?? $short->sensor_current ?? '-');
        }

        $long = $sensors->get("{$diskIdx}_selftest_long");
        if ($long) {
            $parts[] = 'Long: ' . (string) ($long->formatValue() ?? $long->sensor_current ?? '-');
        }

        return $parts !== [] ? implode(' | ', $parts) : '-';
    }

    private function hasBig5DatasetInRrd(string $diskIdx): bool
    {
        $rrdFile = Rrd::name((string) $this->device['hostname'], ['app', 'smart', $this->app->app_id, $diskIdx]);
        if (! Rrd::checkRrdExists($rrdFile)) {
            return false;
        }

        $point = $this->readRrdCurrentPoint($rrdFile);
        if ($point === null || ! is_array($point->data ?? null)) {
            return false;
        }

        $big5 = ['id5', 'id187', 'id188', 'id197', 'id198'];

        return array_intersect(array_keys($point->data), $big5) !== [];
    }

    private function hasOtherDatasetInRrd(string $diskIdx): bool
    {
        $rrdFile = Rrd::name((string) $this->device['hostname'], ['app', 'smart', $this->app->app_id, $diskIdx]);
        if (! Rrd::checkRrdExists($rrdFile)) {
            return false;
        }

        $point = $this->readRrdCurrentPoint($rrdFile);
        if ($point === null || ! is_array($point->data ?? null)) {
            return false;
        }

        $other = ['id10', 'id183', 'id184', 'id196', 'id199'];

        return array_intersect(array_keys($point->data), $other) !== [];
    }

    private function ataAttributeGraphSpecs(string $diskIdx, array $disk): array
    {
        $rrdFile = Rrd::name((string) $this->device['hostname'], ['app', 'smart', $this->app->app_id, $diskIdx]);
        if (! Rrd::checkRrdExists($rrdFile)) {
            return [];
        }

        $point = $this->readRrdCurrentPoint($rrdFile);
        if ($point === null || ! is_array($point->data ?? null)) {
            return [];
        }

        $availableDs = array_keys($point->data);
        $specs = [];
        $seenIds = [];

        foreach ($disk['attributes']['ata'] ?? [] as $attr) {
            $id = isset($attr['id']) ? (int) $attr['id'] : 0;
            if ($id <= 0 || isset($seenIds[$id])) {
                continue;
            }
            $seenIds[$id] = true;

            $dsRaw = 'id' . $id;
            $dsNorm = $dsRaw . 'Normalized';
            $hasRaw = in_array($dsRaw, $availableDs, true);
            $hasNorm = in_array($dsNorm, $availableDs, true);
            if (! $hasRaw && ! $hasNorm) {
                continue;
            }

            $name = str_replace('_', ' ', trim((string) ($attr['name'] ?? 'Attribute')));
            $title = 'ID# ' . $id . ', ' . $name;
            $rawValue = $attr['raw']['string'] ?? $attr['raw']['value'] ?? null;
            $normValue = $attr['value'] ?? null;
            $header = 'Normalized:' . ($hasNorm ? $this->formatAttributeValue($normValue) : '-')
                . ' Raw:' . ($hasRaw ? $this->formatAttributeValue($rawValue) : '-');

            $specs[] = [
                'id' => $id,
                'title' => $title,
                'header' => $header,
                'thresh' => is_numeric($attr['thresh'] ?? null) ? (float) $attr['thresh'] : null,
                'has_raw' => $hasRaw,
                'has_norm' => $hasNorm,
            ];
        }

        return $specs;
    }

    private function formatAttributeValue(mixed $value): string
    {
        if (! is_numeric($value)) {
            if (is_string($value) && preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*\/\s*(-?\d+(?:\.\d+)?)\s*$/', $value, $fraction) === 1) {
                $numerator = (float) $fraction[1];
                $denominator = (float) $fraction[2];
                if ($numerator == 0.0) {
                    $value = 0.0;
                } elseif ($denominator != 0.0) {
                    $value = $numerator / $denominator;
                }
            }

            if (! is_numeric($value) && is_string($value) && preg_match('/-?\d+(?:\.\d+)?/', $value, $matches) === 1) {
                $value = (float) $matches[0];
            } else {
                if (! is_numeric($value)) {
                    return '-';
                }
            }
        }

        return trim(LibreNMS\Util\Number::formatSi((float) $value, 2, 0, ''));
    }

    private function resolveDiskViewMode(): string
    {
        $modes = $this->diskViewModes();
        $cookie = $_COOKIE[$this->diskViewModeCookieName()] ?? null;
        if (is_string($cookie) && array_key_exists($cookie, $modes)) {
            return $cookie;
        }

        return 'basic';
    }

    private function setDiskViewModeCookie(string $mode): void
    {
        if (headers_sent() || (($_COOKIE[$this->diskViewModeCookieName()] ?? null) === $mode)) {
            return;
        }

        setcookie($this->diskViewModeCookieName(), $mode, [
            'expires' => time() + 60 * 60 * 24 * 365,
            'path' => '/',
            'samesite' => 'Lax',
        ]);
    }

    private function diskViewModeCookieName(): string
    {
        return 'smart_disk_view_mode_' . (string) ($this->device['device_id'] ?? '0');
    }

    private function diskViewModes(): array
    {
        return [
            'basic' => 'Basic',
            'detailed' => 'Detailed',
            'graphs' => 'Graphs',
        ];
    }

    private function reliabilityHeaderValue(array $disk): string
    {
        $parts = [];
        $passed = $disk['health']['smart_passed'] ?? null;
        if ($passed === true) {
            $parts[] = 'OK';
        } elseif ($passed === false) {
            $parts[] = 'FAIL';
        }

        $wear = $this->overviewWearRemaining($disk);
        if ($wear !== null) {
            $parts[] = (string) (int) round(max(0.0, min(100.0, $wear))) . '% wear';
        }

        return $parts !== [] ? implode(' | ', $parts) : '-';
    }

    private function powerHeaderValue(array $disk): string
    {
        $hours = $disk['power']['power_on_time']['hours'] ?? null;

        return is_numeric($hours) ? 'Hours: ' . (int) $hours : '-';
    }

    private function nvmeHeaderValue(array $disk): string
    {
        $used = $disk['nvme_smart_health_information_log']['percentage_used']
            ?? $disk['health']['nvme_smart_health_information_log']['percentage_used']
            ?? $disk['stats']['nvme_smart_health_information_log']['percentage_used']
            ?? null;
        if (! is_numeric($used)) {
            return '-';
        }

        $remaining = (int) round(max(0.0, min(100.0, 100.0 - (float) $used)));

        return $remaining . '% wear';
    }

    private function renderAttributes(): void
    {
        $rows = $this->disk['attributes']['ata'] ?? [];
        if (empty($rows)) {
            return;
        }

        $this->renderPanel('Attributes', function () use ($rows) {
            echo '<table class="table table-condensed table-striped table-hover">';
            echo '<thead><tr>'
                . '<th>' . $this->keyLabel('id') . '</th>'
                . '<th>Name</th>'
                . '<th>Flags</th>'
                . '<th>Value</th>'
                . '<th>Worst</th>'
                . '<th>Thresh</th>'
                . '<th>Raw</th>'
                . '<th>' . $this->keyLabel('when_failed') . '</th>'
                . '</tr></thead><tbody>';

            foreach ($rows as $r) {
                $whenFailed = (string) ($r['when_failed'] ?? '');
                $rowClass = match ($whenFailed) {
                    'now'  => ' class="danger"',
                    'past' => ' class="warning"',
                    default => '',
                };

                $thresh = $r['thresh'] ?? null;
                $value = $r['value'] ?? null;
                $worst = $r['worst'] ?? null;

                // Flags tooltip
                $flagsStr = htmlspecialchars((string) ($r['flags']['string'] ?? ''));
                $flagMap = [
                    'prefailure'     => 'Pre-failure',
                    'updated_online' => 'Online updated',
                    'performance'    => 'Performance',
                    'error_rate'     => 'Error rate',
                    'event_count'    => 'Event count',
                    'auto_keep'      => 'Auto-keep',
                ];
                $flagLines = [];
                foreach ($flagMap as $key => $label) {
                    if (isset($r['flags'][$key])) {
                        $flagLines[] = $label . ': ' . ($r['flags'][$key] ? 'yes' : 'no');
                    }
                }
                $flagsTip = htmlspecialchars(implode("\n", $flagLines), ENT_QUOTES);
                $flagsCell = $flagLines !== []
                    ? '<span data-toggle="tooltip" data-placement="top" title="' . $flagsTip . '" style="cursor:default;border-bottom:1px dotted">' . $flagsStr . '</span>'
                    : $flagsStr;

                // Value tooltip
                $valueStr = htmlspecialchars((string) ($value ?? ''));
                $valueTip = 'Normalized value (1–253, higher is better)';
                if (is_numeric($thresh) && is_numeric($value)) {
                    $valueTip .= (float) $value < (float) $thresh ? "\nFAIL: below threshold " . $thresh : "\nOK: above threshold " . $thresh;
                }
                $valueCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars($valueTip, ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . $valueStr . '</span>';

                // Worst tooltip
                $worstStr = htmlspecialchars((string) ($worst ?? ''));
                $worstTip = 'Worst normalized value ever recorded';
                if (is_numeric($thresh) && is_numeric($worst)) {
                    $worstTip .= (float) $worst < (float) $thresh ? "\nFAIL: below threshold " . $thresh : "\nOK: above threshold " . $thresh;
                }
                $worstCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars($worstTip, ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . $worstStr . '</span>';

                // Thresh tooltip
                $threshStr = htmlspecialchars((string) ($thresh ?? ''));
                $threshTip = 'Failure threshold — attribute fails when Value drops below this';
                $threshCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars($threshTip, ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . $threshStr . '</span>';

                // Raw tooltip
                $rawStr = htmlspecialchars((string) ($r['raw']['string'] ?? ''));
                $rawTip = 'Raw hardware reading — vendor-specific meaning';
                $rawCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars($rawTip, ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . $rawStr . '</span>';

                echo '<tr' . $rowClass . '>'
                    . '<td>' . htmlspecialchars((string) ($r['id'] ?? '')) . '</td>'
                    . '<td>' . htmlspecialchars(str_replace('_', ' ', (string) ($r['name'] ?? ''))) . '</td>'
                    . '<td>' . $flagsCell . '</td>'
                    . '<td>' . $valueCell . '</td>'
                    . '<td>' . $worstCell . '</td>'
                    . '<td>' . $threshCell . '</td>'
                    . '<td>' . $rawCell . '</td>'
                    . '<td>' . htmlspecialchars($whenFailed) . '</td>'
                    . '</tr>';
            }

            echo '</tbody></table>';
        });
    }

    private function renderIdentity(): void
    {
        $data = $this->disk['identity'];

        // Panel title = device name
        $title = htmlspecialchars(
            (string) ($data['dev_name'] ?? $data['device_path'] ?? 'Identity')
        );

        // Health badge
        $passed = $this->disk['health']['smart_passed'] ?? null;
        $badge = match ($passed) {
            true  => '<span class="label label-success">Passed</span>',
            false => '<span class="label label-danger">Failed</span>',
            default => '',
        };

        // Build flat table in priority order
        $flat = [];
        $seen = [];

        $add = function (string $key, mixed $value) use (&$flat, &$seen, $data): void {
            if (isset($seen[$key]) || $value === '' || $value === null) {
                return;
            }
            $seen[$key] = true;
            if ($key === 'capacity_bytes' && is_int($value)) {
                $flat[$key] = LibreNMS\Util\Number::formatBi($value);
            } elseif (in_array($key, ['logical_block_size', 'physical_block_size'], true) && is_int($value)) {
                $flat[$key] = LibreNMS\Util\Number::formatSi($value, 0, 0, 'B');
            } elseif ($key === 'rotation_rate' && is_int($value)) {
                $flat[$key] = $value . ' RPM';
            } else {
                $flat[$key] = $value;
            }
        };

        // 1. Model Family
        $add('model_family', $data['model_family'] ?? null);

        // 2. Model — first available alias
        foreach (['model_name', 'device_model', 'model_number'] as $alias) {
            if (isset($data[$alias]) && $data[$alias] !== '') {
                $add('model_name', $data[$alias]);
                foreach (['model_name', 'device_model', 'model_number'] as $a) {
                    $seen[$a] = true;
                }
                break;
            }
        }

        // 3. Serial
        $add('serial_number', $data['serial_number'] ?? null);

        // 4. Path — dev_name or device_path (whichever was used as title still appears)
        foreach (['dev_name', 'device_path'] as $k) {
            if (isset($data[$k]) && $data[$k] !== '') {
                $add($k, $data[$k]);
                $seen['dev_name'] = true;
                $seen['device_path'] = true;
                break;
            }
        }

        // 5. Size
        $add('capacity_bytes', $data['capacity_bytes'] ?? null);

        // 6. Firmware
        $add('firmware_version', $data['firmware_version'] ?? null);

        // 7. Interface speed (formatted)
        $ifSpeed = $data['interface_speed'] ?? null;
        if (is_array($ifSpeed)) {
            $curStr = $ifSpeed['current']['string'] ?? null;
            $maxStr = $ifSpeed['max']['string'] ?? null;
            if ($curStr !== null || $maxStr !== null) {
                $speed = (string) $curStr;
                if ($maxStr !== null && $maxStr !== $curStr) {
                    $speed .= " ({$maxStr})";
                }
                $flat['interface_speed'] = $speed;
            }
            $seen['interface_speed'] = true;
        }

        // 8. Remaining fields — skip noisy/duplicate ones
        $skip = ['wwn', 'interface_speed', 'device_model', 'model_number'];
        foreach ($data as $k => $v) {
            if (in_array($k, $skip, true) || isset($seen[$k])) {
                continue;
            }
            $add($k, $v);
        }

        $this->panelStart($title, $badge);
        echo '<div class="table-responsive">';
        $this->renderFlatTable($flat);
        echo '</div>';
        $this->panelEnd();
    }

    private function renderSelftest(): void
    {
        $data = $this->disk['selftest'];
        $dst = isset($data['ata_smart_data_self_test']) && is_array($data['ata_smart_data_self_test'])
            ? $data['ata_smart_data_self_test']
            : null;
        $extTable = $data['ata_smart_self_test_log']['extended']['table'] ?? [];

        if (! empty($extTable) || $dst !== null) {
            $passed = $dst['status']['passed'] ?? null;
            $remaining = isset($dst['status']['remaining_percent']) && is_numeric($dst['status']['remaining_percent'])
                ? (int) $dst['status']['remaining_percent']
                : null;

            if ($remaining !== null) {
                $done = max(0, min(100, 100 - $remaining));
                $badge = '<span class="label label-info">Running ' . $done . '%</span>';
            } else {
                $badge = match ($passed) {
                    true  => '<span class="label label-success">Passed</span>',
                    false => '<span class="label label-danger">Failed</span>',
                    default => '',
                };
            }

            $this->panelStart('Self-test', $badge);
            echo '<div class="table-responsive">';

            if ($dst !== null) {
                $pollingLabels = [
                    'short'      => 'Est. Short Test',
                    'extended'   => 'Est. Extended Test',
                    'conveyance' => 'Est. Conveyance Test',
                ];
                $flat = [];
                $statusStr = $dst['status']['string'] ?? null;
                if ($statusStr !== null) {
                    $flat['Status'] = $statusStr;
                }

                foreach ($dst['polling_minutes'] ?? [] as $k => $v) {
                    $label = $pollingLabels[$k] ?? ('Est. ' . ucfirst((string) $k) . ' Test');
                    $flat[$label] = $v . ' min';
                }

                if ($flat !== []) {
                    $this->renderFlatTable($flat);
                }
            }

            if (! empty($extTable)) {
                $rows = array_map(function ($r) {
                    $h = $r['lifetime_hours'] ?? null;
                    $hoursCell = (string) ($h ?? '');
                    if ($this->powerOnHours !== null && is_numeric($h)) {
                        $delta = $this->powerOnHours - (int) $h;
                        $hoursCell = $delta > 0
                            ? $this->formatHoursAgo($delta) . " ({$h})"
                            : "<0 hour ({$h})";
                    }

                    $remaining = $r['status']['remaining_percent'] ?? null;

                    return [
                        'lifetime_hours'    => $hoursCell,
                        'type'              => $r['type']['string'] ?? '',
                        'status'            => $r['status']['string'] ?? '',
                        'remaining_percent' => $remaining !== null ? $remaining . '%' : '',
                    ];
                }, $extTable);
                $this->renderArrayTable($rows);
            }

            echo '</div>';
            $this->panelEnd();
        }
    }

    private function renderStats(): void
    {
        $data = $this->disk['stats'];
        $skipPages = ['Temperature Statistics', 'Vendor Specific Statistics', 'Solid State Device Statistics'];
        $skipRows = ['Lifetime Power-On Resets', 'Power-on Hours'];
        if (isset($data['sata_phy_event_counters'])) {
            $this->renderSection('SATA PHY Event Counters', $data['sata_phy_event_counters']);
        }
        foreach ($data['ata_device_statistics']['pages'] ?? [] as $page) {
            $pageName = (string) ($page['name'] ?? 'Unknown');
            if (in_array($pageName, $skipPages, true)) {
                continue;
            }

            $rows = [];
            foreach ($page['table'] ?? [] as $e) {
                if (in_array($e['name'], $skipRows, true)) {
                    continue;
                }

                $v = $e['value'];
                if (is_int($v) && abs($v) >= 1000000) {
                    $v = LibreNMS\Util\Number::formatSi($v, 2, 0, '');
                }

                $rows[] = ['name' => $e['name'], 'value' => $v];
            }

            if ($rows !== []) {
                $this->renderPanel($pageName, fn () => $this->renderArrayTable($rows));
            }
        }

        // Merge device_state + smart_status into one flat panel; skip temperature
        // if (isset($data['ata_sct_status'])) {
        //     $merged = [];
        //     foreach ($data['ata_sct_status'] as $k => $v) {
        //         if ($k === 'temperature') {
        //             continue;
        //         }

        //         if (in_array($k, ['device_state', 'smart_status'], true) && is_array($v)) {
        //             foreach ($v as $sk => $sv) {
        //                 if ($k === 'device_state' && $sk === 'value') {
        //                     continue; // redundant when string is present
        //                 }

        //                 $merged[$k . '_' . $sk] = $sv;
        //             }
        //         } else {
        //             $merged[$k] = $v;
        //         }
        //     }

        //     if ($merged !== []) {
        //         $this->renderPanel('SCT Status', fn () => $this->renderFlatTable($merged));
        //     }
        // }

        // Non-extended error log types
        if (isset($data['ata_smart_error_log'])) {
            foreach ($data['ata_smart_error_log'] as $type => $logData) {
                if (! is_array($logData) || $type === 'extended') {
                    continue;
                }

                $table = $logData['table'] ?? [];
                $meta = array_filter($logData, fn ($k) => $k !== 'table', ARRAY_FILTER_USE_KEY);
                $label = 'SMART Error Log / ' . ucfirst((string) $type);

                if ($meta !== []) {
                    $this->renderPanel($label, fn () => $this->renderFlatTable($meta));
                }

                if ($table !== []) {
                    $this->renderPanel($label . ' / Entries', fn () => $this->renderArrayTable($table));
                }
            }
        }
    }

    private function renderNvmeSelftest(array $log): void
    {
        $operation = $log['current_self_test_operation'] ?? [];
        $operationValue = isset($operation['value']) && is_numeric($operation['value'])
            ? (int) $operation['value']
            : null;
        $operationString = trim((string) ($operation['string'] ?? ''));
        $completion = isset($log['current_self_test_completion_percent']) && is_numeric($log['current_self_test_completion_percent'])
            ? (int) $log['current_self_test_completion_percent']
            : null;

        $isRunning = $operationValue !== null && in_array($operationValue, [1, 2], true);
        $statusLabel = match (true) {
            $operationValue === 0 => 'Idle',
            $isRunning => 'Running',
            default => 'Unknown',
        };
        $statusClass = match (true) {
            $operationValue === 0 => 'label-default',
            $isRunning => 'label-info',
            default => 'label-warning',
        };

        $badge = '<span class="label ' . $statusClass . '">' . htmlspecialchars($statusLabel) . '</span>';
        if ($isRunning && $completion !== null) {
            $badge .= ' <span class="text-muted">' . htmlspecialchars((string) $completion) . '%</span>';
        }

        $this->panelStart('NVMe Self-test Log', $badge);
        if ($operationString !== '') {
            echo '<p class="text-muted" style="margin:8px 15px 4px">' . htmlspecialchars($operationString) . '</p>';
        }
        echo '<div class="table-responsive">';
        echo '<table class="table table-condensed table-striped table-hover">';
        echo '<thead><tr><th>Num</th><th>Test_Description</th><th>Status</th><th>Power_on_Hours</th><th>Failing_LBA</th><th>NSID</th><th>Seg</th><th>SCT Code</th></tr></thead>';
        echo '<tbody>';

        $rows = $log['table'] ?? [];
        if (! is_array($rows) || $rows === []) {
            echo '<tr><td colspan="8" class="text-muted">No entries.</td></tr>';
        } else {
            foreach ($rows as $index => $row) {
                $code = $row['self_test_code'] ?? [];
                $result = $row['self_test_result'] ?? [];

                $failingLba = $row['failing_lba']
                    ?? ($row['lba']['value'] ?? null)
                    ?? ($row['lba'] ?? null);
                $nsid = $row['nsid'] ?? ($log['nsid'] ?? null);
                $segment = $row['segment'] ?? ($row['seg'] ?? null);

                $h = $row['power_on_hours'] ?? null;
                $hoursCell = $h !== null ? (string) $h : '-';
                if ($this->powerOnHours !== null && is_numeric($h)) {
                    $delta = $this->powerOnHours - (int) $h;
                    $hoursCell = $delta > 0
                        ? $this->formatHoursAgo($delta) . " ({$h})"
                        : "<0 hour ({$h})";
                }

                $cells = [
                    (string) ($index + 1),
                    (string) ($code['string'] ?? '-'),
                    (string) ($result['string'] ?? '-'),
                    $hoursCell,
                    $failingLba !== null ? (string) $failingLba : '-',
                    $nsid !== null ? (string) $nsid : '-',
                    $segment !== null ? (string) $segment : '-',
                    isset($code['value']) ? (string) $code['value'] : '-',
                ];

                echo '<tr>';
                foreach ($cells as $cell) {
                    echo '<td>' . htmlspecialchars($cell) . '</td>';
                }
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
        $this->panelEnd();
    }

    private function renderNvmeStats(): void
    {
        $data = $this->disk['stats'];

        if (isset($data['nvme_smart_health_information_log']) && is_array($data['nvme_smart_health_information_log'])) {
            $flat = [];
            foreach ($data['nvme_smart_health_information_log'] as $k => $v) {
                $flat[$k] = (is_array($v) && array_is_list($v))
                    ? implode(', ', $v)
                    : $v;
            }

            $this->renderPanel('NVMe Health Log', fn () => $this->renderFlatTable($flat));
        }

        if (isset($data['nvme_error_information_log']) && is_array($data['nvme_error_information_log'])) {
            $eil = $data['nvme_error_information_log'];
            $meta = array_filter($eil, fn ($k) => $k !== 'table', ARRAY_FILTER_USE_KEY);
            $table = $eil['table'] ?? [];

            if ($meta !== [] || $table !== []) {
                $rows = $table !== [] ? array_map(fn ($r) => [
                    'error_count' => $r['error_count'],
                    'command_id'  => $r['command_id'],
                    'status'      => $r['status_field']['string'] ?? '',
                    'nsid'        => $r['nsid'],
                    'lba'         => $r['lba']['value'] ?? '',
                ], $table) : [];
                $this->renderPanel('NVMe Error Log / Entries', function () use ($meta, $rows) {
                    if ($meta !== []) {
                        $this->renderFlatTable($meta);
                    }
                    if ($rows !== []) {
                        $this->renderArrayTable($rows);
                    }
                });
            }
        }

        // if (! empty($data['nvme_namespaces']) && is_array($data['nvme_namespaces'])) {
        //     $rows = array_map(fn ($ns) => [
        //         'id'          => $ns['id'],
        //         'size'        => LibreNMS\Util\Number::formatBi($ns['size']['bytes'] ?? 0),
        //         'capacity'    => LibreNMS\Util\Number::formatBi($ns['capacity']['bytes'] ?? 0),
        //         'utilization' => LibreNMS\Util\Number::formatBi($ns['utilization']['bytes'] ?? 0),
        //         'lba_size'    => $ns['formatted_lba_size'] ?? '',
        //     ], $data['nvme_namespaces']);
        //     $this->renderPanel('NVMe Namespaces', fn () => $this->renderArrayTable($rows));
        // }
    }

    private function renderExtendedErrorLog(): void
    {
        $logData = $this->disk['stats']['ata_smart_error_log']['extended'] ?? null;
        if (! is_array($logData)) {
            return;
        }

        $table = $logData['table'] ?? [];
        $count = isset($logData['count']) ? (int) $logData['count'] : count($table);
        $this->renderPanel(
            'SMART Error Log / Extended / Entries',
            fn () => $this->renderErrorLogEntries($table),
            (string) $count
        );
    }

    private function formatHoursAgo(int $delta): string
    {
        $totalDays = (int) floor($delta / 24);
        $remHours = $delta % 24;

        if ($totalDays >= 365) {
            $years = (int) floor($totalDays / 365);
            $days = $totalDays % 365;
            $s = $years !== 1 ? 's' : '';
            $out = "-{$years} year{$s}";
            if ($days > 0) {
                $ds = $days !== 1 ? 's' : '';
                $out .= " {$days} day{$ds}";
            }

            return $out;
        }

        if ($totalDays >= 30) {
            $months = (int) floor($totalDays / 30);
            $days = $totalDays % 30;
            $ms = $months !== 1 ? 's' : '';
            $out = "-{$months} month{$ms}";
            if ($days > 0) {
                $ds = $days !== 1 ? 's' : '';
                $out .= " {$days} day{$ds}";
            }

            return $out;
        }

        if ($totalDays > 0) {
            $ds = $totalDays !== 1 ? 's' : '';
            $out = "-{$totalDays} day{$ds}";
            if ($remHours > 0) {
                $hs = $remHours !== 1 ? 's' : '';
                $out .= " {$remHours} hour{$hs}";
            }

            return $out;
        }

        $hs = $delta !== 1 ? 's' : '';

        return "-{$delta} hour{$hs}";
    }

    private function renderErrorLogEntries(array $entries): void
    {
        echo '<table class="table table-condensed table-striped table-hover">';
        echo '<thead><tr><th>#</th><th>Hours</th><th>Device State</th><th>Error</th><th>Previous Commands</th></tr></thead>';
        echo '<tbody>';

        foreach ($entries as $i => $entry) {
            $num = htmlspecialchars((string) ($entry['error_number'] ?? $i + 1));
            $lifetimeHours = $entry['lifetime_hours'] ?? null;
            $hoursCell = htmlspecialchars((string) ($lifetimeHours ?? ''));
            if ($this->powerOnHours !== null && is_numeric($lifetimeHours)) {
                $delta = $this->powerOnHours - (int) $lifetimeHours;
                if ($delta > 0) {
                    $ago = htmlspecialchars($this->formatHoursAgo($delta));
                    $hoursCell = "{$ago} ({$lifetimeHours})";
                } else {
                    $hoursCell = htmlspecialchars('<0 hour (' . $lifetimeHours . ')');
                }
            }

            $state = htmlspecialchars((string) ($entry['device_state']['string'] ?? ''));
            $desc = htmlspecialchars((string) ($entry['error_description'] ?? ''));

            $cmds = $entry['previous_commands'] ?? [];
            $cmdHtml = '';
            if (! empty($cmds)) {
                $cmdHtml .= '<table class="table table-condensed" style="margin:0;background:transparent">';
                $cmdHtml .= '<thead><tr><th>Command</th><th>LBA</th><th>Count</th><th>Features</th><th>Uptime (ms)</th></tr></thead><tbody>';
                foreach ($cmds as $cmd) {
                    $name = htmlspecialchars((string) ($cmd['command_name'] ?? ''));
                    $lba = htmlspecialchars((string) ($cmd['registers']['lba'] ?? ''));
                    $cnt = htmlspecialchars((string) ($cmd['registers']['count'] ?? ''));
                    $feat = htmlspecialchars((string) ($cmd['registers']['features'] ?? ''));
                    $ms = htmlspecialchars((string) ($cmd['powerup_milliseconds'] ?? ''));
                    $cmdHtml .= "<tr><td>{$name}</td><td>{$lba}</td><td>{$cnt}</td><td>{$feat}</td><td>{$ms}</td></tr>";
                }

                $cmdHtml .= '</tbody></table>';
            }

            echo "<tr><td>{$num}</td><td>{$hoursCell}</td><td>{$state}</td><td><code>{$desc}</code></td><td>{$cmdHtml}</td></tr>";
        }

        echo '</tbody></table>';
    }

    // ---------------------------------------------------------------
    // Generic renderer
    // ---------------------------------------------------------------

    private function renderSection(string $title, mixed $data): void
    {
        if ($data === null) {
            return;
        }

        if (is_array($data) && array_is_list($data) && count($data) > 0 && is_array($data[0])) {
            // Array of objects → multi-column table
            $this->renderPanel($title, fn () => $this->renderArrayTable($data));

            return;
        }

        if (is_array($data) && ! array_is_list($data)) {
            // Check if any value is itself a non-trivial array (nested section)
            $hasNested = false;
            foreach ($data as $v) {
                if (is_array($v)) {
                    $hasNested = true;
                    break;
                }
            }

            if ($hasNested) {
                // Render each sub-key as its own section
                foreach ($data as $subKey => $subData) {
                    $this->renderSection($title . ' / ' . ucfirst((string) $subKey), $subData);
                }

                return;
            }

            // Flat dict → two-column table
            $this->renderPanel($title, fn () => $this->renderFlatTable($data));

            return;
        }

        // Scalar or empty — skip
    }

    private function renderPanel(string $title, callable $body, string $badge = ''): void
    {
        $badgeHtml = $badge !== '' ? '<span class="badge">' . htmlspecialchars($badge) . '</span>' : '';
        $this->panelStart(htmlspecialchars($title), $badgeHtml);
        echo '<div class="table-responsive">';

        $body();

        echo '</div>';
        $this->panelEnd();
    }

    private function keyLabel(string $key): string
    {
        static $map = [
            'ata_version'              => 'ATA Version',
            'capacity_bytes'           => 'Capacity',
            'dev_name'                 => 'Device',
            'device_path'              => 'Path',
            'device_type'              => 'Type',
            'firmware_version'         => 'Firmware',
            'form_factor'              => 'Form Factor',
            'logical_block_size'       => 'Logical Block',
            'model_family'             => 'Model Family',
            'model_name'               => 'Model',
            'physical_block_size'      => 'Physical Block',
            'protocol'                 => 'Protocol',
            'rotation_rate'            => 'Rotation Rate',
            'sata_version'             => 'SATA Version',
            'serial_number'            => 'Serial Number',
            'device_state_string'      => 'Device State',
            'smart_status_passed'      => 'SMART Passed',
            'format_version'           => 'Format Version',
            'sct_version'              => 'SCT Version',
            'power_cycle_count'        => 'Power Cycles',
            'power_on_hours'           => 'Power On Hours',
            'nvme_controller_id'       => 'Controller ID',
            'nvme_ieee_oui_identifier' => 'IEEE OUI',
            'nvme_number_of_namespaces' => 'Namespaces',
            'nvme_total_capacity'      => 'Total Capacity',
            'nvme_unallocated_capacity' => 'Unallocated Capacity',
            'nvme_version'             => 'NVMe Version',
            'nvme_pci_vendor_id'       => 'PCI Vendor ID',
            'id'                       => 'ID',
            'thresh'                   => 'Threshold',
            'when_failed'              => 'When Failed',
            'lifetime_hours'           => 'Hours',
        ];

        return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
    }

    private function renderFlatTable(array $data): void
    {
        echo '<table class="table table-condensed table-striped table-hover" style="width:auto">';
        echo '<tbody>';
        foreach ($data as $key => $value) {
            $k = $this->keyLabel((string) $key);
            $v = htmlspecialchars($this->scalarToString($value));
            $this->tableRow($k, $v);
        }

        echo '</tbody></table>';
    }

    private function renderArrayTable(array $rows): void
    {
        if (count($rows) === 0) {
            echo '<p>No entries.</p>';

            return;
        }

        $headers = array_keys($rows[0]);

        echo '<table class="table table-condensed table-striped table-hover">';
        echo '<thead><tr>';
        foreach ($headers as $h) {
            echo '<th>' . htmlspecialchars($this->keyLabel((string) $h)) . '</th>';
        }

        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($headers as $h) {
                $v = htmlspecialchars($this->scalarToString($row[$h] ?? null));
                echo "<td>{$v}</td>";
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function scalarToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        return (string) $value;
    }
}

(new SmartPage($device, $app, $vars))->render();
