{{-- SATA/SAS per-disk "Statistics" view: SATA PHY Event Counters and device  --}}
{{-- statistics tables (General, Rotating Media, General Errors, Transport,    --}}
{{-- Free-Fall, FARM*). Inherits closures ($panelStart, $panelEnd, $tableRow,  --}}
{{-- $tooltipForLabel, $labelWithTooltip) and $data, $device, $selectedDisk,   --}}
{{-- $linkArray from the parent smart.blade.php.                               --}}
@php
    $disk = $data->disk($selectedDisk);
@endphp

{{-- Device Statistics + SATA PHY Event Counters --}}
<style>
    .smart-panels { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-start; margin-bottom:15px }
    .smart-panels .panel { flex:0 0 auto; margin-bottom:0 }
    .smart-panels table { white-space:nowrap }
</style>
@php
    // FARM header pages (Drive Information / Log Header) live on the Metadata view.
    $metadataPages = ['FARM Drive Information', 'FARM Log Header'];
    $devStatKnownPanels = [
        'General Statistics',
        'Free-Fall Statistics',
        'Rotating Media Statistics',
        'General Errors Statistics',
        'Transport Statistics',
        'FARM Log Header',
        'FARM Drive Information',
        'FARM Workload Statistics',
        'FARM Error Statistics',
        'FARM Environment Statistics',
        'FARM Reliability Statistics',
    ];
@endphp
        @php
            $fmtStatVal  = static function ($v, string $statName = ''): string {
                // Only "Date and Time TimeStamp" is a real Unix epoch value (milliseconds, per the
                // "Set Date and Time Timestamp" command spec). Other *timestamp fields (e.g.
                // lowest/highest_poh_timestamp) are POH hour bounds, not epoch time.
                $normStatName = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $statName)));
                if ($normStatName === 'date and time timestamp' && is_numeric($v)) {
                    return htmlspecialchars(\Carbon\Carbon::createFromTimestampMsUTC((int) $v)->toDateTimeString() . ' UTC');
                }
                if (str_contains($normStatName, 'humidity') && is_numeric($v)) {
                    return htmlspecialchars((string) (int) $v) . '%';
                }
                if (is_numeric($v) && abs((float) $v) >= 1000000) {
                    return \LibreNMS\Util\Number::formatSi((float) $v, 2, 0, '');
                }
                return htmlspecialchars((string) ($v ?? ''));
            };
            $fmtStatName = static function (string $s): string {
                static $exactMap = [
                    'poh'  => 'Power-on hours',
                    'spoh' => 'Spin power-on hours',
                ];
                static $wordMap = [
                    'dvga'  => 'Delta Variable Gain Amplifier',
                    'rvga'  => 'Running Average Variable Gain Amplifier',
                    'fvga'  => 'Filter Variable Gain Amplifier',
                    'dos'   => 'Directed Offline Scan',
                    'isp'   => 'Intermediate Super Parity',
                    'h2sat' => 'Head Self-Assessment Test',
                    'mr'    => 'Magneto Resistive',
                ];
                if (isset($exactMap[$s])) {
                    return htmlspecialchars($exactMap[$s]);
                }
                $words = array_map(
                    static fn ($w) => $wordMap[$w] ?? ucfirst($w),
                    explode('_', strtolower($s))
                );
                return htmlspecialchars(implode(' ', $words));
            };
            $fmtStatLabel = static function (string $s) use ($fmtStatName, $labelWithTooltip): string {
                $label = html_entity_decode($fmtStatName($s), ENT_QUOTES);

                return $labelWithTooltip($label);
            };
            $fmtFarmStatLabel = static function (string $s) use ($fmtStatName, $labelWithTooltip, $tooltipForLabel): string {
                $label = html_entity_decode($fmtStatName($s), ENT_QUOTES);

                return $labelWithTooltip($label, $tooltipForLabel($label));
            };
            $fmtMilli = static function ($v, string $unit): string {
                if ($v === null || $v === '') { return ''; }
                return htmlspecialchars(number_format((float) $v / 1000, 3)) . ' ' . $unit;
            };

            $farmSubTables = static function (string $pageName, array $rows) use ($fmtStatVal): array {
                if (! str_starts_with($pageName, 'FARM ')) {
                    return ['scalars' => $rows, 'groups' => []];
                }
                $byName   = [];
                foreach ($rows as $r) { $byName[$r['stat_name'] ?? ''] = $r; }
                $scalars  = [];
                $groups   = [];
                $extract  = [];
                $consumed = [];

                if ($pageName === 'FARM Environment Statistics') {
                    $tempMap = [
                        'curent_temp'        => ['instant', 'current'],
                        'highest_temp'       => ['instant', 'highest'],
                        'lowest_temp'        => ['instant', 'lowest'],
                        'average_temp'       => ['short',   'average'],
                        'highest_short_temp' => ['short',   'highest'],
                        'lowest_short_temp'  => ['short',   'lowest'],
                        'average_long_temp'  => ['long',    'average'],
                        'highest_long_temp'  => ['long',    'highest'],
                        'lowest_long_temp'   => ['long',    'lowest'],
                    ];
                    $tempData = [];
                    foreach ($tempMap as $stat => [$row, $col]) {
                        if (isset($byName[$stat])) {
                            $tempData[$row][$col] = $byName[$stat]['value'];
                            $consumed[$stat]      = true;
                        }
                    }
                    if ($tempData) {
                        $groups[] = ['title' => 'Temperature (°C)', 'type' => 'temp_matrix', 'data' => $tempData];
                    }

                    $limitData = [];
                    foreach ([['max_temp','over_temp_time','Maximum'],['min_temp','under_temp_time','Minimum']] as [$lStat,$tStat,$label]) {
                        if (isset($byName[$lStat], $byName[$tStat])) {
                            $limitData[] = ['label' => $label, 'limit' => $byName[$lStat]['value'], 'time' => $byName[$tStat]['value']];
                            $consumed[$lStat] = $consumed[$tStat] = true;
                        }
                    }
                    if ($limitData) {
                        $groups[] = ['title' => 'Operating Limits', 'type' => 'limits', 'data' => $limitData];
                    }

                    $voltageRails = [
                        '12V' => ['Current' => 'current_12v_in_mv', 'Minimum' => 'minimum_12v_in_mv', 'Maximum' => 'maximum_12v_in_mv'],
                        '5V'  => ['Current' => 'current_5v_in_mv',  'Minimum' => 'minimum_5v_in_mv',  'Maximum' => 'maximum_5v_in_mv'],
                    ];
                    $voltData = [];
                    foreach ($voltageRails as $label => $statCols) {
                        $row = ['label' => $label];
                        foreach ($statCols as $col => $stat) {
                            $row[$col] = isset($byName[$stat]) ? $byName[$stat]['value'] : null;
                            if (isset($byName[$stat])) { $consumed[$stat] = true; }
                        }
                        $voltData[] = $row;
                    }
                    if ($voltData) {
                        $groups[] = ['title' => 'Voltage', 'type' => 'voltage', 'data' => $voltData];
                    }

                    // Power table: Current column holds current voltage for 12V/5V rails (already
                    // consumed above), and current motor power for Motor.
                    $powerRails = [
                        '12V' => ['vcurrent' => 'current_12v_in_mv', 'Average' => 'average_12v_power', 'Minimum' => 'minimum_12v_power', 'Maximum' => 'maximum_12v_power'],
                        '5V'  => ['vcurrent' => 'current_5v_in_mv',  'Average' => 'average_5v_power',  'Minimum' => 'minimum_5v_power',  'Maximum' => 'maximum_5v_power'],
                    ];
                    $powerData = [];
                    foreach ($powerRails as $label => $statCols) {
                        $row = ['label' => $label, 'rail' => true, 'Current' => isset($byName[$statCols['vcurrent']]) ? $byName[$statCols['vcurrent']]['value'] : null];
                        foreach (['Average', 'Minimum', 'Maximum'] as $col) {
                            $stat = $statCols[$col];
                            $row[$col] = isset($byName[$stat]) ? $byName[$stat]['value'] : null;
                            if (isset($byName[$stat])) { $consumed[$stat] = true; }
                        }
                        $powerData[] = $row;
                    }
                    if (isset($byName['current_motor_power'])) {
                        $powerData[] = ['label' => 'Motor', 'rail' => false, 'Current' => $byName['current_motor_power']['value'], 'Average' => null, 'Minimum' => null, 'Maximum' => null];
                        $consumed['current_motor_power'] = true;
                    }
                    if ($powerData) {
                        $groups[] = ['title' => 'Power', 'type' => 'power', 'data' => $powerData];
                    }

                } elseif ($pageName === 'FARM Error Statistics') {
                    $flashEvents = [];
                    $cumulHead   = [];
                    foreach ($rows as $r) {
                        $stat = $r['stat_name'] ?? '';
                        if (preg_match('/^flash_led_event_(\d+)\.(.+)$/', $stat, $m)) {
                            $flashEvents[(int) $m[1]][$m[2]] = $r['value'];
                            $consumed[$stat] = true;
                        } elseif (preg_match('/^cum_lifetime_unrecoverable_by_head_(\d+)\.(.+)$/', $stat, $m)) {
                            $cumulHead[(int) $m[1]][$m[2]] = $r['value'];
                            $consumed[$stat] = true;
                        }
                    }
                    if ($flashEvents) {
                        ksort($flashEvents);
                        $extract[] = ['title' => 'Flash LED events', 'type' => 'flash_led', 'source' => $pageName,
                            'data' => ['events' => $flashEvents, 'fields' => array_keys(reset($flashEvents))]];
                    }
                    if ($cumulHead) {
                        ksort($cumulHead);
                        $extract[] = ['title' => 'Cumulative lifetime unrecoverable errors by head', 'type' => 'cum_head', 'source' => $pageName,
                            'data' => ['heads' => $cumulHead, 'fields' => array_keys(reset($cumulHead))]];
                    }

                } elseif ($pageName === 'FARM Reliability Statistics') {
                    $byHead = [];
                    foreach ($rows as $r) {
                        $stat = $r['stat_name'] ?? '';
                        if (preg_match('/^(.+)_by_head_(\d+)$/', $stat, $m) ||
                            preg_match('/^(.+)_from_head_(\d+)$/', $stat, $m)) {
                            $byHead[$m[1]][(int) $m[2]] = $r['value'];
                            $consumed[$stat] = true;
                        }
                    }
                    if ($byHead) {
                        $allHeads = [];
                        foreach ($byHead as $vals) { $allHeads = array_merge($allHeads, array_keys($vals)); }
                        $allHeads = array_values(array_unique($allHeads));
                        sort($allHeads);
                        $extract[] = ['title' => 'By head', 'type' => 'by_head', 'source' => $pageName,
                            'data' => ['metrics' => $byHead, 'heads' => $allHeads]];
                    }

                    $attrCandidates = [];
                    foreach ($rows as $r) {
                        $stat = $r['stat_name'] ?? '';
                        if (preg_match('/^attr_(.+)_raw$/', $stat, $m)) {
                            $attrCandidates[$m[1]]['raw'] = ['stat' => $stat, 'value' => $r['value']];
                        } elseif (preg_match('/^(.+)_normalized$/', $stat, $m)) {
                            $attrCandidates[$m[1]]['normalized'] = ['stat' => $stat, 'value' => $r['value']];
                        } elseif (preg_match('/^(.+)_worst$/', $stat, $m)) {
                            $attrCandidates[$m[1]]['worst'] = ['stat' => $stat, 'value' => $r['value']];
                        }
                    }
                    $attrRows = [];
                    foreach ($attrCandidates as $key => $fields) {
                        if (isset($fields['normalized']) || isset($fields['worst'])) {
                            $labelKey = $key === 'error_rate' ? 'read_error_rate' : $key;
                            $attrRows[$labelKey] = [
                                'normalized' => $fields['normalized']['value'] ?? null,
                                'worst'      => $fields['worst']['value'] ?? null,
                                'raw'        => $fields['raw']['value'] ?? null,
                            ];
                            foreach ($fields as $f) { $consumed[$f['stat']] = true; }
                        }
                    }
                    if ($attrRows) {
                        $groups[] = ['title' => '', 'type' => 'attr_table', 'data' => $attrRows];
                    }

                } elseif ($pageName === 'FARM Workload Statistics') {
                    $radRows = [];
                    foreach ($rows as $r) {
                        $stat = $r['stat_name'] ?? '';
                        if (preg_match('/^(read|write)_commands_by_radius_(\d+)_(\d+)$/', $stat, $m)) {
                            $range = $m[2] . '-' . $m[3] . '%';
                            $radRows[$range][$m[1]] = $r['value'];
                            $consumed[$stat] = true;
                        }
                    }
                    $radTimeCoverage = null;
                    foreach ($rows as $r) {
                        $stat = $r['stat_name'] ?? '';
                        if (isset($consumed[$stat])) { continue; }
                        $norm = strtolower(str_replace('_', ' ', $stat));
                        if (str_contains($norm, 'time') && str_contains($norm, 'command') && str_contains($norm, 'cover')) {
                            $radTimeCoverage = $r['value'];
                            $consumed[$stat] = true;
                            break;
                        }
                    }
                    if ($radRows) {
                        $groups[] = ['title' => 'Commands by disk radius', 'type' => 'by_radius',
                            'data' => $radRows, 'time_coverage' => $radTimeCoverage];
                    }

                    // Command count breakdown table: Total / Random / Sequential (derived)
                    // Random stats may omit "command" from their name (e.g. total_random_reads),
                    // so we only require "random"+"read/write" for those, excluding resource/sector
                    // false-positives. Total read/write keep the "command" requirement to avoid
                    // matching sector-count or bytes stats.
                    $cmdAccum = ['read' => null, 'write' => null, 'rand_read' => null, 'rand_write' => null, 'other' => null];
                    foreach ($rows as $r) {
                        $stat = $r['stat_name'] ?? '';
                        if (isset($consumed[$stat])) { continue; }
                        $norm = strtolower(str_replace('_', ' ', $stat));
                        $noisy = str_contains($norm, 'resource') || str_contains($norm, 'sector') || str_contains($norm, 'lba');
                        if (str_contains($norm, 'random') && str_contains($norm, 'read') && ! $noisy) {
                            $cmdAccum['rand_read'] = $r['value']; $consumed[$stat] = true;
                        } elseif (str_contains($norm, 'random') && str_contains($norm, 'write') && ! $noisy) {
                            $cmdAccum['rand_write'] = $r['value']; $consumed[$stat] = true;
                        } elseif (str_contains($norm, 'read') && (str_contains($norm, 'command') || str_contains($norm, 'cmd'))) {
                            $cmdAccum['read'] = $r['value']; $consumed[$stat] = true;
                        } elseif (str_contains($norm, 'write') && (str_contains($norm, 'command') || str_contains($norm, 'cmd'))) {
                            $cmdAccum['write'] = $r['value']; $consumed[$stat] = true;
                        } elseif (str_contains($norm, 'other') && ! $noisy) {
                            $cmdAccum['other'] = $r['value']; $consumed[$stat] = true;
                        }
                    }
                    $cmdData = [];
                    foreach ([
                        ['Read',  $cmdAccum['read'],  $cmdAccum['rand_read']],
                        ['Write', $cmdAccum['write'], $cmdAccum['rand_write']],
                        ['Other', $cmdAccum['other'], null],
                    ] as [$label, $total, $random]) {
                        $totalInt  = $total  !== null ? max(0, (int) $total)  : null;
                        $randomInt = $random !== null ? max(0, (int) $random) : null;
                        $seqInt    = ($totalInt !== null && $randomInt !== null) ? max(0, $totalInt - $randomInt) : null;
                        $cmdData[] = ['label' => $label, 'total' => $totalInt, 'random' => $randomInt, 'sequential' => $seqInt];
                    }
                    if (array_filter($cmdData, static fn ($r) => $r['total'] !== null)) {
                        $groups[] = ['title' => 'Command Counts', 'type' => 'commands', 'data' => $cmdData];
                    }
                }

                foreach ($rows as $r) {
                    if (! isset($consumed[$r['stat_name'] ?? ''])) {
                        $scalars[] = $r;
                    }
                }
                return ['scalars' => $scalars, 'groups' => $groups, 'extract' => $extract];
            };

            $renderSubTable = static function (array $group, bool $skipTitle = false, bool $fullWidth = false) use ($fmtStatVal, $fmtStatName, $fmtFarmStatLabel, $fmtMilli, $labelWithTooltip): void {
                $type  = $group['type'];
                $data  = $group['data'];
                $title = htmlspecialchars($group['title']);
                if (! $skipTitle && $title !== '') {
                    echo '<h5 style="margin:14px 0 6px;font-size:14px;font-weight:600">' . $title . '</h5>';
                }

                $tblStyle = ($fullWidth ? 'width:100%' : 'width:auto') . ';font-size:12px';

                if ($type === 'temp_matrix') {
                    $horizons = ['instant' => 'Instant', 'short' => 'Short-term avg', 'long' => 'Long-term avg'];
                    $cols     = ['current' => 'Current', 'average' => 'Average', 'highest' => 'Highest', 'lowest' => 'Lowest'];
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th></th>';
                    foreach ($cols as $col => $colLabel) { echo '<th>' . $colLabel . '</th>'; }
                    echo '</tr></thead><tbody>';
                    foreach ($horizons as $rowKey => $rowLabel) {
                        if (! isset($data[$rowKey])) { continue; }
                        $tooltip = match ($rowKey) {
                            'instant' => 'Current device temperature at read time.',
                            'short' => 'Average of the most recent 144 ten-minute samples over a 24-hour period.',
                            'long' => 'Average of the most recent 42 short-term daily averages; valid after about 1008 hours.',
                            default => '',
                        };
                        echo '<tr><td><strong>' . $labelWithTooltip($rowLabel, $tooltip) . '</strong></td>';
                        foreach ($cols as $col => $_) {
                            $v = $data[$rowKey][$col] ?? null;
                            echo '<td>' . ($v !== null ? $fmtStatVal($v) : '<span class="text-muted">-</span>') . '</td>';
                        }
                        echo '</tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'limits') {
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th></th><th>Limit (°C)</th><th>Time over (min)</th></tr></thead><tbody>';
                    foreach ($data as $row) {
                        $tooltipLabel = $row['label'] === 'Maximum' ? 'Specified maximum operating temperature' : 'Specified minimum operating temperature';
                        echo '<tr><td><strong>' . $labelWithTooltip($row['label'], $tooltipLabel === 'Specified maximum operating temperature'
                            ? 'Manufacturer-specified maximum operating temperature for the device.'
                            : 'Manufacturer-specified minimum operating temperature for the device.') . '</strong></td>'
                            . '<td>' . $fmtStatVal($row['limit']) . '</td>'
                            . '<td>' . $fmtStatVal($row['time']) . '</td></tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'voltage') {
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Rail</th><th>Current</th><th>Minimum</th><th>Maximum</th></tr></thead><tbody>';
                    foreach ($data as $row) {
                        $tooltip = str_starts_with($row['label'], '12V')
                            ? 'Voltage readings for the 12V power line: current, minimum observed, and maximum observed.'
                            : 'Voltage readings for the 5V power line: current, minimum observed, and maximum observed.';
                        echo '<tr><td><strong>' . $labelWithTooltip($row['label'], $tooltip) . '</strong></td>'
                            . '<td>' . $fmtMilli($row['Current'], 'V') . '</td>'
                            . '<td>' . $fmtMilli($row['Minimum'], 'V') . '</td>'
                            . '<td>' . $fmtMilli($row['Maximum'], 'V') . '</td></tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'power') {
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Rail</th><th>Current</th><th>Average</th><th>Minimum</th><th>Maximum</th></tr></thead><tbody>';
                    foreach ($data as $row) {
                        $isRail = $row['rail'] ?? false;
                        $tooltip = match ($row['label']) {
                            '12V' => '12V rail: current voltage reading plus power history (average, minimum, maximum).',
                            '5V' => '5V rail: current voltage reading plus power history (average, minimum, maximum).',
                            'Motor' => 'Current motor power scalar value used by the servo to keep the motor spinning.',
                            default => '',
                        };
                        echo '<tr><td><strong>' . $labelWithTooltip($row['label'], $tooltip) . '</strong></td>'
                            . '<td>' . ($isRail ? $fmtMilli($row['Current'] ?? null, 'W') : $fmtMilli($row['Current'] ?? null, 'W')) . '</td>'
                            . '<td>' . $fmtMilli($row['Average'] ?? null, 'W') . '</td>'
                            . '<td>' . $fmtMilli($row['Minimum'] ?? null, 'W') . '</td>'
                            . '<td>' . $fmtMilli($row['Maximum'] ?? null, 'W') . '</td></tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'flash_led') {
                    $events = $data['events'];
                    $fields = $data['fields'];
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Field</th>';
                    foreach (array_keys($events) as $ev) { echo '<th>Event ' . $ev . '</th>'; }
                    echo '</tr></thead><tbody>';
                    foreach ($fields as $field) {
                        echo '<tr><td>' . $fmtFarmStatLabel($field) . '</td>';
                        foreach ($events as $ev => $_) {
                            echo '<td>' . $fmtStatVal($events[$ev][$field] ?? null) . '</td>';
                        }
                        echo '</tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'cum_head') {
                    $heads  = $data['heads'];
                    $fields = $data['fields'];
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th></th>';
                    foreach (array_keys($heads) as $h) { echo '<th>H' . $h . '</th>'; }
                    echo '</tr></thead><tbody>';
                    foreach ($fields as $f) {
                        echo '<tr><td>' . $fmtFarmStatLabel($f) . '</td>';
                        foreach ($heads as $h => $vals) { echo '<td>' . $fmtStatVal($vals[$f] ?? null) . '</td>'; }
                        echo '</tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'by_head') {
                    $metrics = $data['metrics'];
                    $heads   = $data['heads'];
                    $avgMetrics = ['write_workload_power_on_time'];
                    echo '<table class="table table-condensed table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Metric</th>';
                    foreach ($heads as $h) { echo '<th style="text-align:right">H' . $h . '</th>'; }
                    echo '<th style="text-align:right">Total / Avg</th></tr></thead><tbody>';
                    foreach ($metrics as $metric => $headVals) {
                        $numVals = array_filter(
                            array_map(static fn ($h) => $headVals[$h] ?? null, $heads),
                            static fn ($v) => is_numeric($v)
                        );
                        $rowMax   = $numVals ? max($numVals) : 0;
                        $rowMin   = $numVals ? min($numVals) : 0;
                        $rowRange = $rowMax - $rowMin;
                        $isAvg    = in_array($metric, $avgMetrics, true);
                        $aggregate = $numVals
                            ? ($isAvg
                                ? array_sum($numVals) / count($numVals)
                                : array_sum($numVals))
                            : null;
                        echo '<tr><td>' . $fmtFarmStatLabel($metric) . '</td>';
                        foreach ($heads as $h) {
                            $v   = $headVals[$h] ?? null;
                            $pct = ($rowRange > 0 && is_numeric($v))
                                ? round(($v - $rowMin) / $rowRange * 100)
                                : 0;
                            $bg  = ($rowMax > 0 && $pct > 0)
                                ? ' style="text-align:right;background:linear-gradient(to top,rgba(70,130,180,0.22) ' . $pct . '%,transparent ' . $pct . '%)"'
                                : ' style="text-align:right"';
                            echo '<td' . $bg . '>' . $fmtStatVal($v) . '</td>';
                        }
                        $aggDisplay = $aggregate !== null ? $fmtStatVal(round($aggregate)) : '';
                        echo '<td style="text-align:right;font-weight:600">' . $aggDisplay . ($isAvg ? ' <small class="text-muted">avg</small>' : '') . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'attr_table') {
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Name</th><th>Normalized</th><th>Worst</th><th>Raw</th></tr></thead><tbody>';
                    foreach ($data as $key => $vals) {
                        echo '<tr><td>' . $fmtFarmStatLabel($key) . '</td>'
                            . '<td>' . $fmtStatVal($vals['normalized'] ?? null) . '</td>'
                            . '<td>' . $fmtStatVal($vals['worst'] ?? null) . '</td>'
                            . '<td>' . $fmtStatVal($vals['raw'] ?? null) . '</td></tr>';
                    }
                    echo '</tbody></table>';

                } elseif ($type === 'by_radius') {
                    $fmtSiR  = static fn ($v): string => $v !== null
                        ? htmlspecialchars(\LibreNMS\Util\Number::formatSi((float) $v, 2, 0, ''))
                        : '<span class="text-muted">-</span>';
                    $fmtRawR = static fn ($v): string => $v !== null ? number_format((int) $v) : '';
                    // Per-type (column) max for gradient scaling
                    $typeMax = ['read' => 0, 'write' => 0];
                    $typeTot = ['read' => 0, 'write' => 0];
                    foreach ($data as $vals) {
                        foreach (['read', 'write'] as $t) {
                            $v = $vals[$t] ?? null;
                            if ($v !== null) {
                                if ($v > $typeMax[$t]) { $typeMax[$t] = $v; }
                                $typeTot[$t] += $v;
                            }
                        }
                    }
                    $bgR = static function ($v, string $type) use ($typeMax): string {
                        if ($v !== null && $typeMax[$type] > 0) {
                            $pct = round($v / $typeMax[$type] * 100);
                            if ($pct > 0) {
                                return ' style="text-align:right;background:linear-gradient(to top,rgba(70,130,180,0.22) ' . $pct . '%,transparent ' . $pct . '%)"';
                            }
                        }
                        return ' style="text-align:right"';
                    };
                    echo '<table class="table table-condensed table-striped table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr><th>Radius</th><th style="text-align:right">Read</th><th style="text-align:right">Write</th></tr></thead><tbody>';
                    foreach ($data as $range => $vals) {
                        $r = $vals['read']  ?? null;
                        $w = $vals['write'] ?? null;
                        $radTip = 'Count of read and write commands within ' . $range . ' of user LBA space. This counter is for the time reported in Time that Commands Cover (Hours) field below.';
                        echo '<tr>'
                            . '<td>' . $labelWithTooltip((string) $range, $radTip) . '</td>'
                            . '<td' . $bgR($r, 'read')  . '><span title="' . htmlspecialchars($fmtRawR($r)) . '">' . $fmtSiR($r) . '</span></td>'
                            . '<td' . $bgR($w, 'write') . '><span title="' . htmlspecialchars($fmtRawR($w)) . '">' . $fmtSiR($w) . '</span></td>'
                            . '</tr>';
                    }
                    // Total row
                    $tr = $typeTot['read']  > 0 ? $typeTot['read']  : null;
                    $tw = $typeTot['write'] > 0 ? $typeTot['write'] : null;
                    echo '<tr style="font-weight:600;border-top:2px solid #ddd">'
                        . '<td>Total</td>'
                        . '<td style="text-align:right"><span title="' . htmlspecialchars($fmtRawR($tr)) . '">' . $fmtSiR($tr) . '</span></td>'
                        . '<td style="text-align:right"><span title="' . htmlspecialchars($fmtRawR($tw)) . '">' . $fmtSiR($tw) . '</span></td>'
                        . '</tr>';
                    echo '</tbody></table>';
                    $tc = $group['time_coverage'] ?? null;
                    if ($tc !== null) {
                        echo '<p class="text-muted" style="font-size:11px;margin:4px 0 0">'
                            . '<span title="Number of hours covered by the related read/write command statistics.">'
                            . 'Time that Commands Cover: <strong>' . htmlspecialchars(number_format((float) $tc, 1)) . ' h</strong>'
                            . '</span></p>';
                    }

                } elseif ($type === 'commands') {
                    $fmtSi  = static fn ($v): string => $v !== null
                        ? htmlspecialchars(\LibreNMS\Util\Number::formatSi((float) $v, 2, 0, ''))
                        : '<span class="text-muted">-</span>';
                    $fmtRaw = static fn ($v): string => $v !== null ? number_format((int) $v) : '';
                    $rowMeta = [
                        'Read'  => 'Total read commands since manufacture. Does not include verify commands.',
                        'Write' => 'Total write commands since manufacture. Does not include write-verify or write-same commands.',
                        'Other' => 'Total commands that are not reads or writes.',
                    ];
                    echo '<table class="table table-condensed table-hover" style="' . $tblStyle . '">';
                    echo '<thead><tr>'
                        . '<th>Type</th>'
                        . '<th style="text-align:right"><span title="Cumulative command count since manufacture (reads, writes, and other).">Total</span></th>'
                        . '<th style="text-align:right"><span title="Commands accessing non-sequential LBA space. Does not include verify, write-verify, or write-same.">Random</span></th>'
                        . '<th style="text-align:right"><span title="Derived: Total minus Random. Represents commands accessing sequential LBA space.">Sequential</span></th>'
                        . '</tr></thead><tbody>';
                    foreach ($data as $row) {
                        // Per-row gradient: scale Random and Sequential relative to whichever is larger
                        $rowMax = max($row['random'] ?? 0, $row['sequential'] ?? 0);
                        $bg = static function ($v) use ($rowMax): string {
                            if ($v !== null && $rowMax > 0) {
                                $pct = round($v / $rowMax * 100);
                                if ($pct > 0) {
                                    return ' style="text-align:right;background:linear-gradient(to top,rgba(70,130,180,0.22) ' . $pct . '%,transparent ' . $pct . '%)"';
                                }
                            }
                            return ' style="text-align:right"';
                        };
                        $siCell = static fn ($v): string =>
                            '<td' . $bg($v) . '>'
                            . ($v !== null
                                ? '<span title="' . htmlspecialchars($fmtRaw($v)) . '">' . $fmtSi($v) . '</span>'
                                : '<span class="text-muted">-</span>')
                            . '</td>';
                        echo '<tr>'
                            . '<td><strong>' . $labelWithTooltip($row['label'], $rowMeta[$row['label']] ?? '') . '</strong></td>'
                            . '<td style="text-align:right"><span title="' . htmlspecialchars($fmtRaw($row['total'])) . '">' . $fmtSi($row['total']) . '</span></td>'
                            . $siCell($row['random'])
                            . $siCell($row['sequential'])
                            . '</tr>';
                    }
                    echo '</tbody></table>';
                }
            };

            $isFarmPage   = static fn (string $pn): bool => str_starts_with($pn, 'FARM ');
            $skipRows = \LibreNMS\Agent\Unix\Smart\HtmlData::DEV_STAT_SKIP_ROWS;

            $devStatPanelPages = [];
            foreach ($disk['dev_stats'] as $page) {
                $pn = $page['page_name'] ?: $data->decode('dev_stat_page', $page['page_num']);
                if (in_array($pn, \LibreNMS\Agent\Unix\Smart\HtmlData::DEV_STAT_SKIP_PAGES, true) || in_array($pn, $metadataPages, true)) { continue; }
                if (! in_array($pn, $devStatKnownPanels, true)) { continue; }
                $isFarm = $isFarmPage($pn);
                $rows = array_filter(
                    $page['rows'],
                    static fn ($r) => ($isFarm || ($r['valid'] ?? 1) != 0)
                        && ! in_array((string) ($r['stat_name'] ?? ''), $skipRows, true)
                );
                if (! $rows) { continue; }
                $devStatPanelPages[] = ['page_name' => $pn, 'rows' => array_values($rows)];
            }
        @endphp
        @if(! empty($devStatPanelPages) || ! empty($disk['phy_events']))
        @php $devStatExtractPanels = []; @endphp
        <div class="smart-panels">
            @if(! empty($disk['phy_events']))
            <div>
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
            </div>
            @endif
            @foreach($devStatPanelPages as $devPage)
            <div>
                @php
                    $pageName = $devPage['page_name'];
                    $panelStart(htmlspecialchars($pageName));
                    if (str_starts_with($pageName, 'FARM ')) {
                        echo '<p style="font-size:11px;margin:0 0 8px">'
                            . '<a href="https://github.com/Seagate/openSeaChest/wiki/Drive-Health-and-SMART" target="_blank" rel="noopener">Seagate FARM reference</a>'
                            . '</p>';
                    }
                    $sub = $farmSubTables($pageName, $devPage['rows']);

                    if ($sub['scalars']) {
                        echo '<table class="table table-condensed table-striped table-hover" style="width:auto">';
                        echo '<thead><tr><th>Statistic</th><th>Value</th></tr></thead><tbody>';
                        foreach ($sub['scalars'] as $r) {
                            $statLabel = str_starts_with($pageName, 'FARM ')
                                ? $fmtFarmStatLabel((string) ($r['stat_name'] ?? ''))
                                : $fmtStatLabel((string) ($r['stat_name'] ?? ''));
                            echo '<tr><td>' . $statLabel . '</td>'
                                . '<td>' . $fmtStatVal($r['value'] ?? null, (string) ($r['stat_name'] ?? '')) . '</td></tr>';
                        }
                        echo '</tbody></table>';
                    }
                    foreach ($sub['groups'] as $group) {
                        $renderSubTable($group);
                    }
                    foreach ($sub['extract'] as $ep) {
                        $devStatExtractPanels[] = $ep;
                    }
                    $panelEnd();
                @endphp
            </div>
            @endforeach
            @php
                // Merge by_head + cum_head into one panel
                $byHeadIdx  = null;
                $cumHeadIdx = null;
                foreach ($devStatExtractPanels as $i => $ep) {
                    if ($ep['type'] === 'by_head')  { $byHeadIdx  = $i; }
                    if ($ep['type'] === 'cum_head') { $cumHeadIdx = $i; }
                }
                if ($byHeadIdx !== null && $cumHeadIdx !== null) {
                    $cumEp = $devStatExtractPanels[$cumHeadIdx];
                    $cumMetrics = [];
                    foreach ($cumEp['data']['fields'] as $f) {
                        foreach ($cumEp['data']['heads'] as $h => $vals) {
                            $cumMetrics[$f][$h] = $vals[$f] ?? null;
                        }
                    }
                    $devStatExtractPanels[$byHeadIdx]['data']['metrics'] = array_merge(
                        $devStatExtractPanels[$byHeadIdx]['data']['metrics'],
                        $cumMetrics
                    );
                    $devStatExtractPanels[$byHeadIdx]['source'] =
                        $devStatExtractPanels[$byHeadIdx]['source'] . ' &amp; ' . htmlspecialchars($cumEp['source']);
                    $devStatExtractPanels[$byHeadIdx]['title'] = 'Per-head statistics';
                    unset($devStatExtractPanels[$cumHeadIdx]);
                }
            @endphp
            @foreach($devStatExtractPanels as $ep)
            <div style="flex: 0 0 100%; width: 100%">
                @php
                    $panelStart(htmlspecialchars($ep['title']));
                    echo '<p style="font-size:11px;margin:0 0 8px">'
                        . 'Data from <em>' . $ep['source'] . '</em>'
                        . ' &mdash; <a href="https://github.com/Seagate/openSeaChest/wiki/Drive-Health-and-SMART" target="_blank" rel="noopener">Seagate FARM reference</a>'
                        . '</p>';
                    $renderSubTable($ep, true, true);
                    $panelEnd();
                @endphp
            </div>
            @endforeach
        </div>
        @endif
