@php
    use App\Facades\LibrenmsConfig;
    use LibreNMS\Enum\Severity;
    use LibreNMS\Util\Number;
    use LibreNMS\Util\Url;

    /** @var \LibreNMS\Agent\Unix\Smart\HtmlData $data */

    $deviceId  = (int) $data->device['device_id'];
    $linkArray = [
        'page'   => 'device',
        'device' => $deviceId,
        'tab'    => 'apps',
        'app'    => 'smart',
    ];

    // Persisted display modes (cookie-backed, per device).
    $labelCookie = 'smart_label_mode_' . $deviceId;
    $labelModes  = $data->labelModes();
    $labelMode   = (isset($_COOKIE[$labelCookie]) && isset($labelModes[$_COOKIE[$labelCookie]]))
        ? $_COOKIE[$labelCookie] : 'device';

    $viewCookie = 'smart_disk_view_mode_' . $deviceId;
    $viewModes  = $data->diskViewModes();
    $viewMode   = (isset($_COOKIE[$viewCookie]) && isset($viewModes[$_COOKIE[$viewCookie]]))
        ? $_COOKIE[$viewCookie] : 'basic';

    // -------------------------------------------------------------------------
    // HTML helpers (closures)
    // -------------------------------------------------------------------------
    $panelStart = static function (string $title, string $badge = ''): void {
        $badgeHtml = $badge !== '' ? "<span class=\"pull-right\">{$badge}</span>" : '';
        echo "<div class=\"panel panel-default\"><div class=\"panel-heading\"><h3 class=\"panel-title\">{$title}{$badgeHtml}</h3></div><div class=\"panel-body\">";
    };
    $panelEnd = static function (): void {
        echo '</div></div>';
    };
    $tableRow = static function (string $label, string $value, string $tooltip = ''): string {
        $labelHtml = $tooltip !== ''
            ? '<abbr style="cursor:help;text-decoration:underline dotted" title="' . htmlspecialchars($tooltip) . '">' . htmlspecialchars($label) . '</abbr>'
            : htmlspecialchars($label);
        return '<tr><td style="text-align:right;padding-right:15px;white-space:nowrap"><strong>'
            . "{$labelHtml}</strong></td><td>{$value}</td></tr>\n";
    };

    // Hours-elapsed → "-3 days 4 hours" style string (matches legacy formatting).
    $formatHoursAgo = static function (int $delta): string {
        $totalDays = intdiv($delta, 24);
        $remHours  = $delta % 24;
        if ($totalDays >= 365) {
            $years = intdiv($totalDays, 365);
            $days  = $totalDays % 365;
            $out   = "-{$years} year" . ($years !== 1 ? 's' : '');
            return $days > 0 ? $out . " {$days} day" . ($days !== 1 ? 's' : '') : $out;
        }
        if ($totalDays >= 30) {
            $months = intdiv($totalDays, 30);
            $days   = $totalDays % 30;
            $out    = "-{$months} month" . ($months !== 1 ? 's' : '');
            return $days > 0 ? $out . " {$days} day" . ($days !== 1 ? 's' : '') : $out;
        }
        if ($totalDays > 0) {
            $out = "-{$totalDays} day" . ($totalDays !== 1 ? 's' : '');
            return $remHours > 0 ? $out . " {$remHours} hour" . ($remHours !== 1 ? 's' : '') : $out;
        }
        return "-{$delta} hour" . ($delta !== 1 ? 's' : '');
    };

    // State sensor → coloured Bootstrap badge using its current translation.
    $stateBadge = static function ($sensor): string {
        if (! $sensor || $sensor->sensor_current === null || (int) $sensor->sensor_current < 0) {
            return '<span class="text-muted">-</span>';
        }
        $translation = $sensor->currentTranslation();
        $descr = $translation ? htmlspecialchars($translation->state_descr) : (string) (int) $sensor->sensor_current;
        $class = match ($translation?->severity()) {
            Severity::Ok      => 'default',
            Severity::Warning => 'warning',
            Severity::Error   => 'danger',
            default           => 'default',
        };
        return '<span class="label label-' . $class . '">' . $descr . '</span>';
    };

    // Temperature sensor → "NN°C" badge, coloured by warn/crit limits.
    $tempBadge = static function ($sensor): string {
        if (! $sensor || ! is_numeric($sensor->sensor_current)) {
            return '<span class="text-muted">-</span>';
        }
        $value = (float) $sensor->sensor_current;
        $class = 'default';
        if ($sensor->sensor_limit !== null && $value >= (float) $sensor->sensor_limit) {
            $class = 'danger';
        } elseif ($sensor->sensor_limit_warn !== null && $value >= (float) $sensor->sensor_limit_warn) {
            $class = 'warning';
        }
        $text = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
        return '<span class="label label-' . $class . '">' . htmlspecialchars($text) . '°C</span>';
    };

    // Wear-remaining percentage → coloured badge.
    $wearBadge = static function (?float $wear): string {
        if ($wear === null) {
            return '<span class="text-muted">-</span>';
        }
        $rounded = (int) round(max(0.0, min(100.0, $wear)));
        $class = $rounded <= 10 ? 'danger' : ($rounded <= 20 ? 'warning' : 'default');
        return '<span class="label label-' . $class . '">' . $rounded . '%</span>';
    };

    $selftestBadge = static function (?int $ageHours) use ($formatHoursAgo): string {
        if ($ageHours === null) {
            return '<span class="text-muted">-</span>';
        }
        return '<span class="label label-default">' . htmlspecialchars(ltrim($formatHoursAgo($ageHours), '-')) . ' ago</span>';
    };
@endphp

{{-- Optionbar --}}
@php
    print_optionbar_start();

    // Label-mode selector (right side).
    $currentUrl = $selectedDisk !== null
        ? Url::generate($linkArray + ['disk' => (string) $selectedDisk])
        : Url::generate($linkArray);
    $modeOptions = '';
    foreach ($labelModes as $mode => $title) {
        $sel = $mode === $labelMode ? ' selected' : '';
        $modeOptions .= '<option value="' . htmlspecialchars($mode, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars($title) . '</option>';
    }
    echo '<div class="pull-right" style="margin-left:10px">'
        . '<label for="smart-label-mode" style="margin-right:6px">Label:</label>'
        . '<select id="smart-label-mode" class="form-control input-sm" style="display:inline-block;width:auto" '
        . 'onchange="document.cookie=\'' . htmlspecialchars($labelCookie, ENT_QUOTES) . '=\' + this.value + \'; path=/; max-age=31536000; samesite=lax\'; window.location.href=\'' . htmlspecialchars($currentUrl, ENT_QUOTES) . '\';">'
        . $modeOptions . '</select></div>';

    if (Auth::user()?->hasRole('admin')) {
        echo '<span class="pull-right">' . debug_toggle_button('smart-debug-panels') . '</span>';
    }

    $ovLabel = $selectedDisk === null ? '<span class="pagemenu-selected">All Drives</span>' : 'All Drives';
    $links = [generate_link($ovLabel, $linkArray)];
    foreach ($data->diskKeys() as $key) {
        $disk  = $data->disk($key);
        $label = htmlspecialchars($data->displayLabel($disk, $labelMode));
        if ($selectedDisk === $key) {
            $label = "<span class=\"pagemenu-selected\">{$label}</span>";
        }
        $links[] = generate_link($label, $linkArray, ['disk' => $key]);
    }
    echo implode(' | ', $links);

    // Per-disk view-mode sub-nav.
    if ($selectedDisk !== null && $data->disk($selectedDisk) !== null) {
        $viewLinks = [];
        foreach ($viewModes as $mode => $title) {
            $lbl = htmlspecialchars($title);
            if ($mode === $viewMode) {
                $lbl = '<span class="pagemenu-selected">' . $lbl . '</span>';
            }
            $viewLinks[] = '<a href="' . htmlspecialchars($currentUrl, ENT_QUOTES) . '" onclick="document.cookie=\''
                . htmlspecialchars($viewCookie, ENT_QUOTES) . '=' . htmlspecialchars($mode, ENT_QUOTES)
                . '; path=/; max-age=31536000; samesite=lax\';">' . $lbl . '</a>';
        }
        echo '<br>&nbsp;&nbsp; Disk: ' . implode(' | ', $viewLinks);
    }

    print_optionbar_end();
@endphp

{{-- Debug panels (admin only) --}}
@php
    smart_debug_render($data, $selectedDisk);
@endphp

@if($selectedDisk === null || $data->disk($selectedDisk) === null)
    {{-- ================================================================== --}}
    {{-- Overview                                                            --}}
    {{-- ================================================================== --}}
    @if(! $data->hasDisks())
        <div class="alert alert-info">No SMART devices have been discovered for this application yet.</div>
    @else
        @php $panelStart('Drives'); @endphp
        <div class="table-responsive">
            <table class="table table-condensed table-striped table-hover">
                <thead><tr>
                    <th>Device</th><th>Model</th><th>Serial</th><th>Type</th>
                    <th>Temp</th><th>Health</th><th>Self-test Status</th><th>Wear</th>
                    <th>Last Short Self-test</th><th>Last Long Self-test</th>
                </tr></thead>
                <tbody>
                @foreach($data->diskKeys() as $key)
                    @php
                        $disk    = $data->disk($key);
                        $devName = htmlspecialchars($data->deviceLabel($disk));
                        $serial  = $data->serial($disk);
                        $deviceLink = generate_link($devName, $linkArray, ['disk' => $key]);
                        $modelLink  = generate_link(htmlspecialchars($data->model($disk)), $linkArray, ['disk' => $key]);
                        $serialCell = $serial !== ''
                            ? generate_link(htmlspecialchars($serial), $linkArray, ['disk' => $key])
                            : '-';
                    @endphp
                    <tr>
                        <td>{!! $deviceLink !!}</td>
                        <td>{!! $modelLink !!}</td>
                        <td>{!! $serialCell !!}</td>
                        <td>{{ $data->typeLabel($disk) }}</td>
                        <td>{!! $tempBadge($data->temperatureSensor($key)) !!}</td>
                        <td>{!! $stateBadge($data->healthSensor($key)) !!}</td>
                        <td>{!! $stateBadge($data->selftestStatusSensor($key)) !!}</td>
                        <td>{!! $wearBadge($data->wearRemaining($disk)) !!}</td>
                        <td>{!! $selftestBadge($data->selftestAgeHours($disk, 1)) !!}</td>
                        <td>{!! $selftestBadge($data->selftestAgeHours($disk, 2)) !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @php $panelEnd(); @endphp

        {{-- Overview graphs + jump nav --}}
        @php
            $now    = LibrenmsConfig::get('time.now');
            $from   = LibrenmsConfig::get('time.day');
            $appId  = $data->app->app_id;
            $ovBase = Url::generate($linkArray);

            $sections = [
                ['id' => 'smart-overview-all-temp', 'title' => 'All Temperatures', 'type' => 'smart_v2_all_temp'],
            ];
            foreach ($data->overviewAttributeIds() as $id => $aname) {
                $sections[] = [
                    'id'    => 'smart-overview-attr-' . $id,
                    'title' => 'ID# ' . $id . ', ' . $aname,
                    'type'  => 'smart_v2_attr_multi',
                    'attr_id' => $id,
                ];
            }

            // Jump-to-graph nav.
            $jumpItems = '';
            foreach ($sections as $s) {
                $jumpItems .= '<div style="break-inside:avoid-column;-webkit-column-break-inside:avoid;padding:1px 0">'
                    . '<a href="' . htmlspecialchars($ovBase . '#' . $s['id'], ENT_QUOTES) . '">' . htmlspecialchars($s['title']) . '</a></div>';
            }
            echo '<div class="panel panel-default"><div class="panel-body" style="padding:10px 15px">'
                . '<strong>Jump to graph:</strong><div style="column-width:260px;column-gap:18px;margin-top:6px">'
                . $jumpItems . '</div></div></div>';

            foreach ($sections as $s) {
                $graph_array = [
                    'height' => '100', 'width' => '215', 'from' => $from, 'to' => $now,
                    'id'     => $appId, 'type' => 'application_' . $s['type'],
                    'page_title' => 'All Drives — ' . $s['title'],
                ];
                if (isset($s['attr_id'])) { $graph_array['attr_id'] = $s['attr_id']; }
                echo '<a id="' . htmlspecialchars($s['id']) . '" style="position:relative;top:-70px;display:block;visibility:hidden"></a>';
                $panelStart(htmlspecialchars($s['title']));
                echo '<div class="row">';
                include 'includes/html/print-graphrow.inc.php';
                echo '</div>';
                $panelEnd();
            }
        @endphp
    @endif

@else
    {{-- ================================================================== --}}
    {{-- Per-disk detail                                                     --}}
    {{-- ================================================================== --}}
    @php
        $disk    = $data->disk($selectedDisk);
        $idx     = $disk['idx'];
        $info    = $disk['info'];
        $health  = $disk['health'];
        $powerOnHours = $data->powerOnHours($disk);
        $passed  = $health['sct_smart_status_passed'] ?? $health['overall_status'] ?? null;
        $healthBadge = match (true) {
            (int) $passed === 1 => '<span class="label label-success">Passed</span>',
            $passed !== null    => '<span class="label label-danger">Failed</span>',
            default             => '',
        };

        // Self-test panel badge (running / passed / failed).
        $execRaw   = $health['selftest_exec_status_raw'] ?? null;
        $remaining = $health['selftest_remaining_pct'] ?? null;
        if ((int) $execRaw === 15 || (is_numeric($remaining) && (int) $remaining > 0)) {
            $donePct = is_numeric($remaining) ? max(0, min(100, 100 - (int) $remaining)) : null;
            $selftestPanelBadge = '<span class="label label-info">Running' . ($donePct !== null ? " {$donePct}%" : '') . '</span>';
        } elseif ($execRaw !== null) {
            $selftestPanelBadge = (int) $execRaw === 0
                ? '<span class="label label-success">Passed</span>'
                : '<span class="label label-warning">' . htmlspecialchars($data->decode('selftest_exec', (int) $execRaw)) . '</span>';
        } else {
            $selftestPanelBadge = $healthBadge;
        }

        $showDetailed = $viewMode === 'detailed';
        $showPanels   = $viewMode !== 'graphs';
    @endphp

    @if($showPanels)
    <div class="row">
        {{-- Identity --}}
        <div class="col-md-6" style="display:inline-block;float:none;width:auto;vertical-align:top">
            @php
                $panelStart(htmlspecialchars($data->deviceLabel($disk)), $healthBadge);
                echo '<table class="table table-condensed table-hover" style="width:auto">';
                $cap = $info['user_capacity_bytes'] ?? null;
                $rot = $info['rotation_rate'] ?? null;
                $rows = [
                    'Model'           => $disk['model_name']     ?? null,
                    'Model Family'    => $disk['model_family']   ?? null,
                    'Serial'          => $disk['serial_number']  ?? null,
                    'Firmware'        => $disk['firmware_version'] ?? null,
                    'WWN'             => $disk['wwn']            ?? null,
                    'Device'          => $disk['device_name']   ?? null,
                    'Path'            => $disk['device_path']   ?? null,
                    'Capacity'        => is_numeric($cap) ? Number::formatBi((int) $cap) : null,
                    'Rotation Rate'   => is_numeric($rot) ? ((int) $rot === 0 ? 'Solid State Device' : ((int) $rot) . ' RPM') : null,
                    'Form Factor'     => isset($info['form_factor']) ? $data->decode('form_factor', $info['form_factor']) : null,
                    'ATA Version'     => isset($info['ata_version']) ? $data->decode('ata_version', $info['ata_version']) : null,
                    'SATA Version'    => isset($info['sata_version']) ? $data->decode('sata_version', $info['sata_version']) : null,
                    'Interface Speed' => $data->interfaceSpeed($info),
                    'Logical Block'   => isset($info['logical_block_size']) && is_numeric($info['logical_block_size']) ? Number::formatSi((int) $info['logical_block_size'], 0, 0, 'B') : null,
                    'Physical Block'  => isset($info['physical_block_size']) && is_numeric($info['physical_block_size']) ? Number::formatSi((int) $info['physical_block_size'], 0, 0, 'B') : null,
                    'SMART'           => ($info['smart_available'] ?? null) !== null ? (((int) $info['smart_available']) ? 'Available' : 'Not available') : null,
                    'SMART Enabled'   => ($info['smart_enabled'] ?? null) !== null ? (((int) $info['smart_enabled']) ? 'Yes' : 'No') : null,
                    'Write Cache'     => ($info['write_cache_enabled'] ?? null) !== null ? (((int) $info['write_cache_enabled']) ? 'Enabled' : 'Disabled') : null,
                    'Read Look-ahead' => ($info['read_lookahead_enabled'] ?? null) !== null ? (((int) $info['read_lookahead_enabled']) ? 'Enabled' : 'Disabled') : null,
                    'TRIM'            => ($info['trim_supported'] ?? null) !== null ? (((int) $info['trim_supported']) ? 'Supported' : 'Not supported') : null,
                    'APM'             => $data->apmLabel($info) !== '-' ? $data->apmLabel($info) : null,
                    'Security'        => $data->securityLabel($info) !== '-' ? $data->securityLabel($info) : null,
                    'In smartctl DB'  => ($info['in_smartctl_database'] ?? null) !== null ? (((int) $info['in_smartctl_database']) ? 'Yes' : 'No') : null,
                    'Power On Hours'  => $powerOnHours !== null ? number_format($powerOnHours, 0, '.', ' ') : null,
                    'Power Cycles'    => isset($health['power_cycles']) && is_numeric($health['power_cycles']) ? number_format((int) $health['power_cycles'], 0, '.', ' ') : null,
                    'Last Poll'       => $disk['last_poll_time'] ?? null,
                    'Last Poll Result' => $disk['last_poll_result'] !== null ? $data->decode('poll_result', $disk['last_poll_result']) : null,
                ];
                foreach ($rows as $label => $value) {
                    if ($value !== null && $value !== '') {
                        echo $tableRow($label, htmlspecialchars((string) $value));
                    }
                }
                echo '</table>';
                $panelEnd();
            @endphp
        </div>

        {{-- Health / SCT (detailed only) --}}
        @if($showDetailed)
        <div class="col-md-6" style="display:inline-block;float:none;width:auto;vertical-align:top">
            @php
                $panelStart('Health &amp; SCT', $healthBadge);
                echo '<table class="table table-condensed table-hover" style="width:auto">';
                $sctTemps = [];
                foreach (['sct_temp_lifetime_min' => 'Lifetime Min', 'sct_temp_lifetime_max' => 'Lifetime Max'] as $col => $lbl) {
                    if (isset($health[$col]) && is_numeric($health[$col])) {
                        $sctTemps[] = $lbl . ': ' . (int) $health[$col] . '°C';
                    }
                }
                $hrows = [
                    'SMART Status'         => isset($health['overall_status']) ? ((int) $health['overall_status'] === 1 ? 'Passed' : 'Not passed') : null,
                    'Self-test Status'     => $execRaw !== null ? $data->decode('selftest_exec', (int) $execRaw) : null,
                    'Self-test Remaining'  => is_numeric($remaining) ? ((int) $remaining) . '%' : null,
                    'Error Log Entries'    => $health['error_log_count'] ?? null,
                    'Self-test Log Count'  => $health['selftest_log_count'] ?? null,
                    'Pending Defects'      => $health['pending_defects_count'] ?? null,
                    'SCT Lifetime Temp'    => $sctTemps !== [] ? implode(' / ', $sctTemps) : null,
                    'SCT Over-limit Count' => $health['sct_temp_over_limit_count'] ?? null,
                    'SCT Under-limit Count' => $health['sct_temp_under_limit_count'] ?? null,
                ];
                foreach ($hrows as $label => $value) {
                    if ($value !== null && $value !== '') {
                        echo $tableRow($label, htmlspecialchars((string) $value));
                    }
                }
                echo '</table>';
                $panelEnd();
            @endphp
        </div>
        @endif
    </div>

    {{-- Attributes --}}
    @if(! empty($disk['attributes']))
        @php
            $panelStart('SMART Attributes');
            echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover">';
            echo '<thead><tr><th>ID</th><th>Name</th><th>Flags</th><th>Value</th><th>Worst</th><th>Thresh</th><th>Raw</th><th>When Failed</th></tr></thead><tbody>';
            foreach ($disk['attributes'] as $attr) {
                $status = $attr['status'] ?? null;
                $whenFailed = match ((int) $status) {
                    2 => 'now',
                    3 => 'past',
                    default => '',
                };
                $rowClass = match ($whenFailed) {
                    'now'  => ' class="danger"',
                    'past' => ' class="warning"',
                    default => '',
                };
                $thresh = $attr['value_threshold'] ?? null;
                $value  = $attr['value_norm'] ?? null;
                $worst  = $attr['value_worst'] ?? null;
                $raw    = $attr['value_raw_string'] ?? $attr['value_raw'] ?? '';

                $flagLines = $data->attributeFlagLines($attr);
                $flagsTip  = htmlspecialchars(implode("\n", $flagLines), ENT_QUOTES);
                $flagsShort = htmlspecialchars($data->attributeFlagsShort($attr));
                $flagsCell = $flagLines !== []
                    ? '<span data-toggle="tooltip" data-placement="top" title="' . $flagsTip . '" style="cursor:default;border-bottom:1px dotted">' . $flagsShort . '</span>'
                    : $flagsShort;

                $valueTip = 'Normalized value (1–253, higher is better)';
                if (is_numeric($thresh) && is_numeric($value)) {
                    $valueTip .= (float) $value < (float) $thresh ? "\nFAIL: below threshold " . $thresh : "\nOK: above threshold " . $thresh;
                }
                $valueCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars($valueTip, ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . htmlspecialchars((string) ($value ?? '')) . '</span>';

                $worstTip = 'Worst normalized value ever recorded';
                if (is_numeric($thresh) && is_numeric($worst)) {
                    $worstTip .= (float) $worst < (float) $thresh ? "\nFAIL: below threshold " . $thresh : "\nOK: above threshold " . $thresh;
                }
                $worstCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars($worstTip, ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . htmlspecialchars((string) ($worst ?? '')) . '</span>';

                $threshCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars('Failure threshold - attribute fails when Value drops below this', ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . htmlspecialchars((string) ($thresh ?? '')) . '</span>';
                $rawCell = '<span data-toggle="tooltip" data-placement="top" title="' . htmlspecialchars('Raw hardware reading - vendor-specific meaning', ENT_QUOTES) . '" style="cursor:default;border-bottom:1px dotted">' . htmlspecialchars((string) $raw) . '</span>';

                echo '<tr' . $rowClass . '>'
                    . '<td>' . htmlspecialchars((string) ($attr['attribute_id'] ?? '')) . '</td>'
                    . '<td>' . htmlspecialchars(str_replace('_', ' ', (string) ($attr['name'] ?? ''))) . '</td>'
                    . '<td>' . $flagsCell . '</td>'
                    . '<td>' . $valueCell . '</td>'
                    . '<td>' . $worstCell . '</td>'
                    . '<td>' . $threshCell . '</td>'
                    . '<td>' . $rawCell . '</td>'
                    . '<td>' . htmlspecialchars($whenFailed) . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table></div>';
            $panelEnd();
        @endphp
    @endif

    {{-- Self-test log --}}
    @if(! empty($disk['selftests']) || isset($info['selftest_polling_short_minutes']) || isset($info['offline_collection_completion_secs']))
        @php
            $panelStart('Self-test Log', $selftestPanelBadge);
            // Polling minutes summary and offline collection status
            $pollingRows = [];
            if (isset($info['selftest_polling_short_minutes']) && is_numeric($info['selftest_polling_short_minutes'])) {
                $pollingRows[] = 'Short: ' . (int) $info['selftest_polling_short_minutes'] . ' min';
            }
            if (isset($info['selftest_polling_extended_minutes']) && is_numeric($info['selftest_polling_extended_minutes'])) {
                $pollingRows[] = 'Extended: ' . (int) $info['selftest_polling_extended_minutes'] . ' min';
            }
            if (isset($info['selftest_polling_conveyance_minutes']) && is_numeric($info['selftest_polling_conveyance_minutes'])) {
                $pollingRows[] = 'Conveyance: ' . (int) $info['selftest_polling_conveyance_minutes'] . ' min';
            }
            if ($pollingRows !== []) {
                echo '<p style="margin-bottom:6px"><strong>Est. polling minutes:</strong> ' . htmlspecialchars(implode(' / ', $pollingRows)) . '</p>';
            }
            $offlineSecs = $info['offline_collection_completion_secs'] ?? null;
            $offlineStatus = $health['offline_collection_status'] ?? null;
            if ($offlineSecs !== null && is_numeric($offlineSecs)) {
                echo '<p style="margin-bottom:6px"><strong>Offline collection:</strong> ' . htmlspecialchars((int) $offlineSecs . ' s') . ($offlineStatus !== null ? ' — ' . htmlspecialchars($data->decode('offline_status', $offlineStatus)) : '') . '</p>';
            }
            if (! empty($disk['selftests'])) {
                echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover">';
                echo '<thead><tr><th>#</th><th>Type</th><th>Result</th><th>Hours</th><th>Remaining</th><th>First LBA Error</th></tr></thead><tbody>';
                foreach ($disk['selftests'] as $entry) {
                    $h = $entry['power_on_hours'] ?? null;
                    $hoursCell = (string) ($h ?? '');
                    if ($powerOnHours !== null && is_numeric($h)) {
                        $delta = $powerOnHours - (int) $h;
                        $hoursCell = $delta > 0 ? $formatHoursAgo($delta) . " ({$h})" : "<0 hour ({$h})";
                    }
                    $rem = $entry['remaining_pct'] ?? null;
                    $lba = $entry['lba_first_error'] ?? null;
                    echo '<tr>'
                        . '<td>' . htmlspecialchars((string) ($entry['entry_num'] ?? '')) . '</td>'
                        . '<td>' . htmlspecialchars($data->decode('selftest_type', $entry['test_type'] ?? null)) . '</td>'
                        . '<td>' . htmlspecialchars($data->decode('selftest_result', $entry['result'] ?? null)) . '</td>'
                        . '<td>' . htmlspecialchars($hoursCell) . '</td>'
                        . '<td>' . ($rem !== null && is_numeric($rem) ? htmlspecialchars(((int) $rem) . '%') : '') . '</td>'
                        . '<td>' . ($lba !== null ? htmlspecialchars((string) $lba) : '') . '</td>'
                        . '</tr>';
                }
                echo '</tbody></table></div>';
            }
            $panelEnd();
        @endphp
    @endif

    @if($showDetailed)
        {{-- Error log --}}
        @if(! empty($disk['errors']))
            @php
                $panelStart('SMART Error Log', (string) count($disk['errors']));
                echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover">';
                echo '<thead><tr><th>#</th><th>Hours</th><th>Type</th><th>Device State</th><th>Previous Commands</th></tr></thead><tbody>';
                foreach ($disk['errors'] as $entry) {
                    $entryNum = (int) ($entry['entry_num'] ?? 0);
                    $h = $entry['lifetime_hours'] ?? null;
                    $hoursCell = (string) ($h ?? '');
                    if ($powerOnHours !== null && is_numeric($h)) {
                        $delta = $powerOnHours - (int) $h;
                        $hoursCell = $delta > 0 ? $formatHoursAgo($delta) . " ({$h})" : "<0 hour ({$h})";
                    }
                    $cmds = $disk['error_cmds'][$entryNum] ?? [];
                    $cmdHtml = '';
                    if ($cmds !== []) {
                        $cmdHtml = '<table class="table table-condensed" style="margin:0;background:transparent">'
                            . '<thead><tr><th>Cmd</th><th>LBA</th><th>Count</th><th>Feature</th><th>Uptime (ms)</th></tr></thead><tbody>';
                        foreach ($cmds as $cmd) {
                            $cmdHtml .= '<tr>'
                                . '<td>' . htmlspecialchars((string) ($cmd['description'] ?? $cmd['reg_command'] ?? '')) . '</td>'
                                . '<td>' . htmlspecialchars((string) ($cmd['reg_lba'] ?? '')) . '</td>'
                                . '<td>' . htmlspecialchars((string) ($cmd['reg_count'] ?? '')) . '</td>'
                                . '<td>' . htmlspecialchars((string) ($cmd['reg_feature'] ?? '')) . '</td>'
                                . '<td>' . htmlspecialchars((string) ($cmd['powerup_ms'] ?? '')) . '</td>'
                                . '</tr>';
                        }
                        $cmdHtml .= '</tbody></table>';
                    }
                    echo '<tr>'
                        . '<td>' . htmlspecialchars((string) ($entry['error_count'] ?? $entryNum)) . '</td>'
                        . '<td>' . htmlspecialchars($hoursCell) . '</td>'
                        . '<td>' . htmlspecialchars((string) ($entry['error_type'] ?? '')) . '</td>'
                        . '<td>' . htmlspecialchars($data->decode('device_state', $entry['device_state'] ?? null)) . '</td>'
                        . '<td>' . $cmdHtml . '</td>'
                        . '</tr>';
                }
                echo '</tbody></table></div>';
                $panelEnd();
            @endphp
        @endif

        {{-- Device statistics --}}
        @foreach($disk['dev_stats'] as $page)
            @php
                $pageName = $page['page_name'] ?: $data->decode('dev_stat_page', $page['page_num']);
                if (in_array($pageName, \LibreNMS\Agent\Unix\Smart\HtmlData::DEV_STAT_SKIP_PAGES, true)) { continue; }
                $rows = array_filter(
                    $page['rows'],
                    static fn ($r) => ($r['valid'] ?? 1) != 0
                        && ! in_array((string) ($r['stat_name'] ?? ''), \LibreNMS\Agent\Unix\Smart\HtmlData::DEV_STAT_SKIP_ROWS, true)
                );
                if ($rows === []) { continue; }
                $panelStart(htmlspecialchars((string) $pageName));
                echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover" style="width:auto">';
                echo '<thead><tr><th>Statistic</th><th>Value</th></tr></thead><tbody>';
                foreach ($rows as $r) {
                    $v = $r['value'] ?? null;
                    if (is_numeric($v) && abs((int) $v) >= 1000000) {
                        $v = Number::formatSi((float) $v, 2, 0, '');
                    }
                    echo '<tr><td>' . htmlspecialchars((string) ($r['stat_name'] ?? '')) . '</td>'
                        . '<td>' . htmlspecialchars((string) ($v ?? '')) . '</td></tr>';
                }
                echo '</tbody></table></div>';
                $panelEnd();
            @endphp
        @endforeach

        {{-- SATA PHY event counters --}}
        @if(! empty($disk['phy_events']))
            @php
                $panelStart('SATA PHY Event Counters');
                echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover" style="width:auto">';
                echo '<thead><tr><th>ID</th><th>Name</th><th>Value</th></tr></thead><tbody>';
                foreach ($disk['phy_events'] as $ev) {
                    $val = (string) ($ev['value'] ?? '');
                    if (($ev['overflow'] ?? 0)) { $val .= ' <span class="text-warning">(overflow)</span>'; }
                    echo '<tr><td>' . htmlspecialchars((string) ($ev['event_id'] ?? '')) . '</td>'
                        . '<td>' . htmlspecialchars((string) ($ev['name'] ?? '')) . '</td>'
                        . '<td>' . $val . '</td></tr>';
                }
                echo '</tbody></table></div>';
                $panelEnd();
            @endphp
        @endif

        {{-- Error Recovery Control + pending defects --}}
        @if(! empty($disk['erc']) || ! empty($disk['pending_defects']))
            <div class="row">
                @if(! empty($disk['erc']))
                    <div class="col-md-4" style="display:inline-block;float:none;width:auto;vertical-align:top">
                        @php
                            $panelStart('Error Recovery Control (SCT ERC)');
                            echo '<table class="table table-condensed table-hover" style="width:auto">';
                            foreach ($disk['erc'] as $direction => $row) {
                                $label = $data->decode('erc_direction', $direction);
                                $ds = $row['deciseconds'] ?? null;
                                $val = ($row['enabled'] ?? 0)
                                    ? (is_numeric($ds) ? number_format($ds / 10, 1) . ' s' : 'Enabled')
                                    : 'Disabled';
                                echo $tableRow($label, htmlspecialchars($val));
                            }
                            echo '</table>';
                            $panelEnd();
                        @endphp
                    </div>
                @endif
                @if(! empty($disk['pending_defects']))
                    <div class="col-md-4" style="display:inline-block;float:none;width:auto;vertical-align:top">
                        @php
                            $panelStart('Pending Defects', (string) count($disk['pending_defects']));
                            echo '<table class="table table-condensed table-hover" style="width:auto">';
                            echo '<thead><tr><th>#</th><th>LBA</th></tr></thead><tbody>';
                            foreach ($disk['pending_defects'] as $pd) {
                                echo '<tr><td>' . htmlspecialchars((string) ($pd['entry_num'] ?? ''))
                                    . '</td><td>' . htmlspecialchars((string) ($pd['lba'] ?? '')) . '</td></tr>';
                            }
                            echo '</tbody></table>';
                            $panelEnd();
                        @endphp
                    </div>
                @endif
            </div>
        @endif

        {{-- Capabilities --}}
        @php
            $capFields = [
                'capability_selftests_supported'     => 'Self-tests supported',
                'capability_conveyance_supported'    => 'Conveyance self-test',
                'capability_selective_supported'     => 'Selective self-test',
                'capability_error_logging_supported' => 'Error logging',
                'capability_gp_logging_supported'    => 'GP logging',
                'capability_exec_offline_immediate'  => 'Exec offline immediate',
                'capability_offline_aborted_on_cmd'  => 'Offline aborted on command',
                'capability_offline_surface_scan'    => 'Offline surface scan',
                'capability_attr_autosave'           => 'Attribute autosave',
                'sct_error_recovery_supported'       => 'SCT error recovery control',
                'sct_feature_control_supported'      => 'SCT feature control',
                'sct_data_table_supported'           => 'SCT data table',
            ];
            $capRows = array_filter($capFields, fn ($col) => isset($info[$col]), ARRAY_FILTER_USE_KEY);
            if ($capRows !== []) {
                $panelStart('Capabilities');
                echo '<table class="table table-condensed table-hover" style="width:auto">';
                foreach ($capRows as $col => $label) {
                    $val = (int) $info[$col];
                    $icon = $val ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>';
                    echo $tableRow(htmlspecialchars($label), $icon);
                }
                echo '</table>';
                $panelEnd();
            }
        @endphp

        {{-- Log Directory --}}
        @if(! empty($disk['log_dir']))
            @php
                $panelStart('Log Directory', (string) count($disk['log_dir']));
                echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover" style="width:auto">';
                echo '<thead><tr><th>Address</th><th>Name</th><th>Readable</th><th>Writable</th><th>GP Sectors</th><th>SMART Sectors</th></tr></thead><tbody>';
                foreach ($disk['log_dir'] as $entry) {
                    $rd = $entry['readable'] ?? null;
                    $wr = $entry['writable'] ?? null;
                    echo '<tr>'
                        . '<td>0x' . sprintf('%02X', (int) ($entry['log_address'] ?? 0)) . '</td>'
                        . '<td>' . htmlspecialchars((string) ($entry['name'] ?? '')) . '</td>'
                        . '<td>' . ($rd !== null ? ((int) $rd ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>') : '') . '</td>'
                        . '<td>' . ($wr !== null ? ((int) $wr ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>') : '') . '</td>'
                        . '<td>' . htmlspecialchars((string) ($entry['gp_sectors'] ?? '')) . '</td>'
                        . '<td>' . htmlspecialchars((string) ($entry['smart_sectors'] ?? '')) . '</td>'
                        . '</tr>';
                }
                echo '</tbody></table></div>';
                $panelEnd();
            @endphp
        @endif

        {{-- Selective Self-test --}}
        @if(! empty($disk['selective_test']))
            @php
                $panelStart('Selective Self-test Spans');
                echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover" style="width:auto">';
                echo '<thead><tr><th>Slot</th><th>LBA Min</th><th>LBA Max</th><th>Status</th></tr></thead><tbody>';
                foreach ($disk['selective_test'] as $entry) {
                    echo '<tr>'
                        . '<td>' . htmlspecialchars((string) ($entry['slot'] ?? '')) . '</td>'
                        . '<td>' . htmlspecialchars((string) ($entry['lba_min'] ?? '')) . '</td>'
                        . '<td>' . htmlspecialchars((string) ($entry['lba_max'] ?? '')) . '</td>'
                        . '<td>' . htmlspecialchars((string) ($entry['status_value'] ?? '')) . '</td>'
                        . '</tr>';
                }
                echo '</tbody></table></div>';
                $panelEnd();
            @endphp
        @endif
    @endif
    @endif

    {{-- Graphs --}}
    @php
        $now      = LibrenmsConfig::get('time.now');
        $appId    = $data->app->app_id;
        $anchorPrefix = 'smart-device-' . $idx . '-graph-';
        $tempSensor   = $data->temperatureSensor($selectedDisk);
        $healthSensor = $data->healthSensor($selectedDisk);
        $specs        = $data->attributeGraphSpecs($selectedDisk);
        $hasBig5      = $data->hasBig5Rrd($selectedDisk);
        $hasOther     = $data->hasOtherRrd($selectedDisk);
        $graphBase    = Url::generate($linkArray + ['disk' => (string) $selectedDisk]);

        // Build jump-nav section list.
        $sections = [];
        if ($tempSensor)   { $sections[] = [$anchorPrefix . 'temperature', 'Temperature']; }
        if ($healthSensor) { $sections[] = [$anchorPrefix . 'health', 'Health']; }
        $sections[] = [$anchorPrefix . 'power', 'Power-on Hours'];
        if ($hasBig5)  { $sections[] = [$anchorPrefix . 'big5', 'Reliability / Age (Big 5 ATA Attributes)']; }
        if ($hasOther) { $sections[] = [$anchorPrefix . 'other', 'Other']; }
        foreach ($specs as $spec) {
            $sections[] = [$anchorPrefix . 'attr-' . $spec['id'], $spec['title']];
        }

        $jumpItems = '';
        foreach ($sections as [$sid, $stitle]) {
            $jumpItems .= '<div style="break-inside:avoid-column;-webkit-column-break-inside:avoid;padding:1px 0">'
                . '<a href="' . htmlspecialchars($graphBase . '#' . $sid, ENT_QUOTES) . '">' . htmlspecialchars($stitle) . '</a></div>';
        }
        if ($jumpItems !== '') {
            echo '<div class="panel panel-default"><div class="panel-body" style="padding:10px 15px">'
                . '<strong>Jump to graph:</strong><div style="column-width:260px;column-gap:18px;margin-top:6px">'
                . $jumpItems . '</div></div></div>';
        }

        $anchor = static function (string $id): void {
            echo '<a id="' . htmlspecialchars($id) . '" style="position:relative;top:-70px;display:block;visibility:hidden"></a>';
        };

        $appGraph = static function (string $type, string $title, string $anchorId, string $headerBadge = '', array $extra = []) use ($appId, $idx, $now, $panelStart, $panelEnd, $anchor) {
            $graph_array = array_merge([
                'height' => '100', 'width' => '215', 'to' => $now,
                'id'     => $appId, 'type' => "application_{$type}",
                'disk'   => $idx, 'scale_min' => '0',
            ], $extra);
            $badge = $headerBadge !== '' ? '<span class="text-muted">' . htmlspecialchars($headerBadge) . '</span>' : '';
            $anchor($anchorId);
            $panelStart(htmlspecialchars($title), $badge);
            echo '<div class="row">';
            include 'includes/html/print-graphrow.inc.php';
            echo '</div>';
            $panelEnd();
        };

        $sensorGraph = static function ($sensor, string $title, string $anchorId, string $badge = '') use ($now, $panelStart, $panelEnd, $anchor) {
            $graph_array = [
                'height' => '100', 'width' => '215', 'to' => $now,
                'id'     => $sensor->sensor_id, 'type' => 'sensor_' . $sensor->sensor_class, 'legend' => 'no',
            ];
            $anchor($anchorId);
            $panelStart(htmlspecialchars($title), $badge);
            echo '<div class="row">';
            include 'includes/html/print-graphrow.inc.php';
            echo '</div>';
            $panelEnd();
        };

        if ($tempSensor) {
            $sensorGraph($tempSensor, 'Temperature', $anchorPrefix . 'temperature');
        }
        if ($healthSensor) {
            $sensorGraph($healthSensor, 'Health', $anchorPrefix . 'health', $healthBadge);
        }
        $appGraph('smart_v2_power', 'Power-on Hours', $anchorPrefix . 'power', $data->powerHeader($disk));
        if ($hasBig5) {
            $appGraph('smart_v2_big5', 'Reliability / Age (Big 5 ATA Attributes)', $anchorPrefix . 'big5', $data->reliabilityHeader($disk));
        }
        if ($hasOther) {
            $appGraph('smart_v2_other', 'Other', $anchorPrefix . 'other');
        }

        // Per-attribute graphs with a "Scale from zero" toggle.
        if ($specs !== []) {
            $wrapperId = 'smart-attr-graphs-' . htmlspecialchars($idx);
            $toggleId  = 'smart-attr-scale-' . htmlspecialchars($idx);
            echo '<script>
function smartAttrScaleToggle(cb, wrapperId) {
    var w = document.getElementById(wrapperId); if (!w) return;
    w.querySelectorAll("img.graph-image").forEach(function(img) {
        if (cb.checked) {
            if (img.src.indexOf("scale_min=") === -1) { img.src += (img.src.indexOf("?") !== -1 ? "&" : "?") + "scale_min=0"; }
        } else { img.src = img.src.replace(/[&?]scale_min=[^&]*/g, ""); }
    });
}
</script>';
            echo '<h4 style="margin:20px 0 8px;border-bottom:1px solid #ddd;padding-bottom:6px">Attributes'
                . '<label style="float:right;font-size:13px;font-weight:normal;margin-bottom:0;cursor:pointer">'
                . '<input type="checkbox" id="' . $toggleId . '" checked onchange="smartAttrScaleToggle(this,\'' . $wrapperId . '\')"> Scale from zero</label></h4>';
            echo '<div id="' . $wrapperId . '">';
            foreach ($specs as $spec) {
                $appGraph('smart_v2_attributes', $spec['title'], $anchorPrefix . 'attr-' . $spec['id'], $spec['header'], [
                    'attr_id'     => (string) $spec['id'],
                    'attr_thresh' => $spec['thresh'] !== null ? (string) $spec['thresh'] : '',
                    'has_raw'     => $spec['has_raw'] ? '1' : '0',
                    'has_norm'    => $spec['has_norm'] ? '1' : '0',
                ]);
            }
            echo '</div>';
        }
    @endphp
@endif
