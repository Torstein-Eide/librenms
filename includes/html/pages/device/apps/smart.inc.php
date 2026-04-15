<?php

class SmartPage
{
    private array $baseLink;
    private array $disk = [];
    private ?int $powerOnHours = null;

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
    // V2
    // ---------------------------------------------------------------

    private function renderV2(): void
    {
        $disks = $this->app->data['tables']['disks'] ?? [];
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
            $label = htmlspecialchars((string) $key);
            if (isset($this->vars['disk']) && $this->vars['disk'] === $key) {
                $label = "<span class=\"pagemenu-selected\">{$label}</span>";
            }

            $links[] = generate_link($label, $this->baseLink, ['disk' => $key]);
        }

        echo implode(' | ', $links);

        print_optionbar_end();
    }

    private function renderOverview(array $disks): void
    {
        echo <<<'HTML'
        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title">Drives</h3></div>
            <div class="panel-body">
                <div class="table-responsive">
                    <table class="table table-condensed table-striped table-hover">
                        <thead><tr>
                            <th>Disk</th>
                            <th>Device</th>
                            <th>Health</th>
                            <th>Temp (°C)</th>
                        </tr></thead>
                        <tbody>
        HTML;

        foreach ($disks as $key => $disk) {
            $diskLink = generate_link(htmlspecialchars((string) $key), $this->baseLink, ['disk' => $key]);
            $devName = htmlspecialchars((string) ($disk['identity']['dev_name'] ?? ''));
            $passed = $disk['health']['smart_passed'] ?? null;
            $health = $passed === true
                ? '<span class="label label-success">OK</span>'
                : ($passed === false ? '<span class="label label-danger">FAIL</span>' : '');
            $temp = htmlspecialchars((string) ($disk['temperature']['current_c'] ?? ''));

            echo "<tr><td>{$diskLink}</td><td>{$devName}</td><td>{$health}</td><td>{$temp}</td></tr>\n";
        }

        echo <<<'HTML'
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        HTML;
    }

    private function renderDrive(string $key, array $disk): void
    {
        $this->disk = $disk;
        $this->powerOnHours = isset($disk['power']['power_on_time']['hours'])
            ? (int) $disk['power']['power_on_time']['hours']
            : null;

        echo '<style>
            .smart-panels{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start}
            .smart-panels .panel{flex:0 0 auto;margin-bottom:0}
            .smart-panels table{white-space:nowrap}
        </style>';

        // Row 1: Identity + Self-test + any other small sections
        $skipInRow1 = ['source', 'temperature', 'identity', 'attributes', 'power', 'selftest', 'stats'];
        echo '<div class="smart-panels">';
        if (isset($this->disk['identity'])) {
            $this->renderIdentity();
        }

        if (isset($this->disk['selftest'])) {
            $this->renderSelftest();
        }

        foreach ($this->disk as $section => $data) {
            if (! in_array($section, $skipInRow1, true)) {
                $this->renderSection(ucfirst($section), $data);
            }
        }

        echo '</div>';

        // Row 2: Attributes — full width
        if (isset($this->disk['attributes'])) {
            $this->renderAttributes();
        }

        // Row 3: Stats panels — flex
        if (isset($this->disk['stats'])) {
            echo '<div class="smart-panels">';
            $this->renderStats();
            echo '</div>';

            // Row 4: Extended Error Log — full width
            $this->renderExtendedErrorLog();
        }
    }

    // ---------------------------------------------------------------
    // Section-specific renderers
    // ---------------------------------------------------------------

    private function renderAttributes(): void
    {
        $rows = $this->disk['attributes']['ata'] ?? [];
        if (empty($rows)) {
            return;
        }

        $mapped = array_map(fn ($r) => [
            'id'          => $r['id'],
            'name'        => str_replace('_', ' ', (string) $r['name']),
            'flags'       => $r['flags']['string'] ?? '',
            'value'       => $r['value'],
            'worst'       => $r['worst'],
            'thresh'      => $r['thresh'],
            'raw'         => $r['raw']['string'] ?? '',
            'when_failed' => $r['when_failed'],
        ], $rows);

        $this->renderPanel('Attributes', fn () => $this->renderArrayTable($mapped));
    }

    private function renderIdentity(): void
    {
        $data = $this->disk['identity'];
        $skip = ['wwn', 'interface_speed', 'device_model', 'model_number'];
        $flat = [];
        foreach ($data as $k => $v) {
            if (in_array($k, $skip, true)) {
                continue;
            }

            if ($v === '' || $v === null) {
                continue;
            }

            if ($k === 'capacity_bytes' && is_int($v)) {
                $flat[$k] = LibreNMS\Util\Number::formatBi($v);
            } elseif (in_array($k, ['logical_block_size', 'physical_block_size'], true) && is_int($v)) {
                $flat[$k] = LibreNMS\Util\Number::formatSi($v, 0, 0, 'B');
            } elseif ($k === 'rotation_rate' && is_int($v)) {
                $flat[$k] = $v . ' RPM';
            } else {
                $flat[$k] = $v;
            }
        }

        $ifSpeed = $data['interface_speed'] ?? null;
        if (is_array($ifSpeed)) {
            $curStr = $ifSpeed['current']['string'] ?? null;
            $maxStr = $ifSpeed['max']['string'] ?? null;
            if ($curStr !== null || $maxStr !== null) {
                $speed = (string) $curStr;
                if ($maxStr !== null && $maxStr !== $curStr) {
                    $speed .= " ({$maxStr})";
                } elseif ($maxStr !== null) {
                    $speed .= " ({$maxStr})";
                }

                $flat['interface_speed'] = $speed;
            }
        }

        $this->renderPanel('Identity', fn () => $this->renderFlatTable($flat));
    }

    private function renderSelftest(): void
    {
        $data = $this->disk['selftest'];
        $extTable = $data['ata_smart_self_test_log']['extended']['table'] ?? [];
        if (! empty($extTable)) {
            $rows = array_map(function ($r) {
                $h = $r['lifetime_hours'] ?? null;
                $hoursCell = (string) ($h ?? '');
                if ($this->powerOnHours !== null && is_int($h) && $this->powerOnHours > $h) {
                    $hoursCell = $this->formatHoursAgo($this->powerOnHours - $h) . " ({$h})";
                }

                return [
                    'lifetime_hours' => $hoursCell,
                    'type'           => $r['type']['string'] ?? '',
                    'status'         => $r['status']['string'] ?? '',
                ];
            }, $extTable);
            $this->renderPanel('Self-test Log', fn () => $this->renderArrayTable($rows));
        }

        if (isset($data['nvme_self_test_log']) && is_array($data['nvme_self_test_log'])) {
            $this->renderSection('NVMe Self-test Log', $data['nvme_self_test_log']);
        }
    }

    private function renderStats(): void
    {
        $data = $this->disk['stats'];
        $skipPages = ['Temperature Statistics', 'Vendor Specific Statistics', 'Solid State Device Statistics'];
        $skipRows = ['Lifetime Power-On Resets', 'Power-on Hours'];

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
        if (isset($data['ata_sct_status'])) {
            $merged = [];
            foreach ($data['ata_sct_status'] as $k => $v) {
                if ($k === 'temperature') {
                    continue;
                }

                if (in_array($k, ['device_state', 'smart_status'], true) && is_array($v)) {
                    foreach ($v as $sk => $sv) {
                        if ($k === 'device_state' && $sk === 'value') {
                            continue; // redundant when string is present
                        }

                        $merged[$k . '_' . $sk] = $sv;
                    }
                } else {
                    $merged[$k] = $v;
                }
            }

            if ($merged !== []) {
                $this->renderPanel('SCT Status', fn () => $this->renderFlatTable($merged));
            }
        }

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

        if (isset($data['sata_phy_event_counters'])) {
            $this->renderSection('SATA PHY Event Counters', $data['sata_phy_event_counters']);
        }

        $this->renderNvmeStats();
    }

    private function renderNvmeStats(): void
    {
        $data = $this->disk['stats'];
        $nvmeInfo = [];
        foreach (['nvme_controller_id', 'nvme_ieee_oui_identifier', 'nvme_number_of_namespaces'] as $k) {
            if (isset($data[$k])) {
                $nvmeInfo[$k] = $data[$k];
            }
        }

        foreach (['nvme_total_capacity', 'nvme_unallocated_capacity'] as $k) {
            if (isset($data[$k]) && is_int($data[$k]) && $data[$k] > 0) {
                $nvmeInfo[$k] = LibreNMS\Util\Number::formatBi($data[$k]);
            }
        }

        if (isset($data['nvme_version']['string'])) {
            $nvmeInfo['nvme_version'] = $data['nvme_version']['string'];
        }

        if (isset($data['nvme_pci_vendor']['id'])) {
            $nvmeInfo['nvme_pci_vendor_id'] = $data['nvme_pci_vendor']['id'];
        }

        if ($nvmeInfo !== []) {
            $this->renderPanel('NVMe Info', fn () => $this->renderFlatTable($nvmeInfo));
        }

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

            if ($meta !== []) {
                $this->renderPanel('NVMe Error Log', fn () => $this->renderFlatTable($meta));
            }

            if ($table !== []) {
                $rows = array_map(fn ($r) => [
                    'error_count' => $r['error_count'],
                    'command_id'  => $r['command_id'],
                    'status'      => $r['status_field']['string'] ?? '',
                    'nsid'        => $r['nsid'],
                    'lba'         => $r['lba']['value'] ?? '',
                ], $table);
                $this->renderPanel('NVMe Error Log / Entries', fn () => $this->renderArrayTable($rows));
            }
        }

        if (! empty($data['nvme_namespaces']) && is_array($data['nvme_namespaces'])) {
            $rows = array_map(fn ($ns) => [
                'id'          => $ns['id'],
                'size'        => LibreNMS\Util\Number::formatBi($ns['size']['bytes'] ?? 0),
                'capacity'    => LibreNMS\Util\Number::formatBi($ns['capacity']['bytes'] ?? 0),
                'utilization' => LibreNMS\Util\Number::formatBi($ns['utilization']['bytes'] ?? 0),
                'lba_size'    => $ns['formatted_lba_size'] ?? '',
            ], $data['nvme_namespaces']);
            $this->renderPanel('NVMe Namespaces', fn () => $this->renderArrayTable($rows));
        }
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
            if ($this->powerOnHours !== null && is_int($lifetimeHours) && $this->powerOnHours > $lifetimeHours) {
                $ago = htmlspecialchars($this->formatHoursAgo($this->powerOnHours - $lifetimeHours));
                $hoursCell = "{$ago} ({$lifetimeHours})";
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
        $safeTitle = htmlspecialchars($title);
        $badgeHtml = $badge !== ''
            ? '<span class="badge pull-right">' . htmlspecialchars($badge) . '</span>'
            : '';
        echo <<<HTML
        <div class="panel panel-default">
            <div class="panel-heading">{$badgeHtml}<h3 class="panel-title">{$safeTitle}</h3></div>
            <div class="panel-body">
                <div class="table-responsive">
        HTML;

        $body();

        echo <<<'HTML'
                </div>
            </div>
        </div>
        HTML;
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
        echo '<table class="table table-condensed table-striped table-hover">';
        echo '<tbody>';
        foreach ($data as $key => $value) {
            $k = htmlspecialchars($this->keyLabel((string) $key));
            $v = htmlspecialchars($this->scalarToString($value));
            echo "<tr><th>{$k}</th><td>{$v}</td></tr>\n";
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
