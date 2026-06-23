{{-- SATA/SAS per-disk "Self-test" view: self-test log, selective spans, offline --}}
{{-- data collection and the related capabilities. Inherits closures ($panelStart, --}}
{{-- $panelEnd, $tableRow, $tooltipForLabel, $labelWithTooltip, $formatHoursAgo, --}}
{{-- $stateBadge) and $data, $device, $selectedDisk, $smartUrl from smart.blade.php. --}}
@php
    $disk    = $data->disk($selectedDisk);
    $idx     = $disk['idx'];
    $info    = $disk['info'];
    $health  = $disk['health'];

    $powerOnHours = $data->powerOnHours($disk);
    $healthSensor = $data->healthSensor($selectedDisk);
    $healthBadge  = $stateBadge($healthSensor, 'SMART overall-health self-assessment test result.');

    // Self-test panel badge (running / passed / failed). Mirrors the other views.
    $execRaw   = $health['selftest_exec_status_raw'] ?? null;
    $remaining = $health['selftest_remaining_pct'] ?? null;
    if ((int) $execRaw === 15 || (is_numeric($remaining) && (int) $remaining > 0)) {
        $donePct = is_numeric($remaining) ? max(0, min(100, 100 - (int) $remaining)) : null;
        $selftestPanelBadge = '<span class="label label-info">Running' . ($donePct !== null ? " {$donePct}%" : '') . '</span>';
    } elseif ($execRaw !== null) {
        $selftestPanelBadge = (int) $execRaw === 0
            ? '<span class="label label-default">Passed</span>'
            : '<span class="label label-warning">' . htmlspecialchars($data->decode('selftest_exec', (int) $execRaw)) . '</span>';
    } else {
        $selftestPanelBadge = $healthBadge;
    }
@endphp

<div class="tw:grid tw:grid-cols-1 tw:md:grid-cols-2 tw:gap-4">
    {{-- ============================== Left column ============================== --}}
    <div class="tw:min-w-0">
        {{-- Self-test Log --}}
        @if(! empty($disk['selftests']) || isset($info['selftest_polling_short_minutes']))
        @php
            $panelStart('Self-test Log', $selftestPanelBadge);
            $pollingRows = [];
            foreach (['short' => 'Short', 'extended' => 'Extended', 'conveyance' => 'Conveyance'] as $k => $kLabel) {
                $col = "selftest_polling_{$k}_minutes";
                if (isset($info[$col]) && is_numeric($info[$col])) {
                    $pollingRows[] = "{$kLabel}: " . (int) $info[$col] . ' min';
                }
            }
            if ($pollingRows !== []) {
                echo '<p style="margin-bottom:6px"><strong>Est. polling minutes:</strong> ' . htmlspecialchars(implode(' / ', $pollingRows)) . '</p>';
            }
            if (! empty($disk['selftests'])) {
                $hasLba = false;
                foreach ($disk['selftests'] as $e) {
                    if (is_numeric($e['lba_first_error'] ?? null) && (int) $e['lba_first_error'] > 0) { $hasLba = true; break; }
                }
                echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover">';
                echo '<thead><tr><th>Hours</th><th>Type</th><th>Status</th><th>Remaining</th>'
                    . ($hasLba ? '<th>First LBA Error</th>' : '') . '</tr></thead><tbody>';
                foreach ($disk['selftests'] as $entry) {
                    $h = $entry['power_on_hours'] ?? null;
                    $hoursCell = (string) ($h ?? '');
                    if ($powerOnHours !== null && is_numeric($h)) {
                        $delta = $powerOnHours - (int) $h;
                        $hoursCell = $delta > 0 ? $formatHoursAgo($delta) . " ({$h})" : "<0 hour ({$h})";
                    }
                    $rem = $entry['remaining_pct'] ?? null;
                    $remCell = (is_numeric($rem) && (int) $rem > 0) ? ((int) $rem) . '%' : '';
                    $lba = $entry['lba_first_error'] ?? null;
                    echo '<tr>'
                        . '<td>' . htmlspecialchars($hoursCell) . '</td>'
                        . '<td>' . htmlspecialchars($data->decode('selftest_type', $entry['test_type'] ?? null)) . '</td>'
                        . '<td>' . htmlspecialchars($data->decode('selftest_result', $entry['result'] ?? null)) . '</td>'
                        . '<td>' . htmlspecialchars($remCell) . '</td>'
                        . ($hasLba ? '<td>' . (is_numeric($lba) && (int) $lba > 0 ? htmlspecialchars((string) $lba) : '') . '</td>' : '')
                        . '</tr>';
                }
                echo '</tbody></table></div>';
            }
            $panelEnd();
        @endphp
        @endif

        {{-- Selective Self-test Spans --}}
        @if(! empty($disk['selective_test']))
        @php
            $panelStart('Selective Self-test Spans', (string) count($disk['selective_test']));
            echo '<div class="table-responsive"><table class="table table-condensed table-striped table-hover" style="width:auto">';
            echo '<thead><tr><th>Slot</th><th>LBA Min</th><th>LBA Max</th><th>Status</th></tr></thead><tbody>';
            foreach ($disk['selective_test'] as $entry) {
                echo '<tr><td>' . htmlspecialchars((string) ($entry['slot'] ?? '')) . '</td>'
                    . '<td>' . htmlspecialchars((string) ($entry['lba_min'] ?? '')) . '</td>'
                    . '<td>' . htmlspecialchars((string) ($entry['lba_max'] ?? '')) . '</td>'
                    . '<td>' . htmlspecialchars((string) ($entry['status_value'] ?? '')) . '</td></tr>';
            }
            echo '</tbody></table></div>';
            $panelEnd();
        @endphp
        @endif
    </div>

    {{-- ============================= Right column ============================= --}}
    <div class="tw:min-w-0">
        {{-- Offline Data Collection --}}
        @php
            $offlineSecs   = $info['offline_collection_completion_secs'] ?? null;
            $offlineStatus = $health['offline_collection_status'] ?? null;
        @endphp
        @if($offlineSecs !== null || $offlineStatus !== null)
        @php
            $panelStart('Offline Data Collection');
            echo '<table class="table table-condensed table-hover" style="width:100%">';
            if ($offlineStatus !== null && is_numeric($offlineStatus)) {
                $autoEnabled = ((int) $offlineStatus & 0x80) !== 0;
                $statusText  = $data->decode('offline_status', (int) $offlineStatus & 0x7f);
                echo $tableRow('Status', htmlspecialchars($statusText), $tooltipForLabel('Status'));
                echo $tableRow('Auto Offline Data Collection',
                    $autoEnabled ? '<span class="text-success">Enabled</span>' : '<span class="text-muted">Disabled</span>',
                    $tooltipForLabel('Auto Offline Data Collection'));
            }
            if ($offlineSecs !== null && is_numeric($offlineSecs)) {
                echo $tableRow('Total Time to Complete', htmlspecialchars((int) $offlineSecs . ' s'), $tooltipForLabel('Total Time to Complete'));
            }
            echo '</table>';
            $panelEnd();
        @endphp
        @endif

        {{-- Capabilities (Self-test + Offline Data Collection) --}}
        @php
            $capGroups = [
                'Self-test' => [
                    'capability_selftests_supported'  => 'Self-tests supported',
                    'capability_conveyance_supported' => 'Conveyance self-test',
                    'capability_selective_supported'  => 'Selective self-test',
                ],
                'Offline Data Collection' => [
                    'capability_exec_offline_immediate' => 'Exec offline immediate',
                    'capability_offline_aborted_on_cmd' => 'Offline aborted on command',
                    'capability_offline_surface_scan'   => 'Offline surface scan',
                ],
            ];
            $hasCaps = false;
            foreach ($capGroups as $cols) {
                foreach ($cols as $col => $label) { if (isset($info[$col])) { $hasCaps = true; break 2; } }
            }
        @endphp
        @if($hasCaps)
        @php
            $panelStart('Capabilities');
            echo '<table class="table table-condensed table-hover" style="width:100%">';
            foreach ($capGroups as $heading => $cols) {
                $groupRows = '';
                foreach ($cols as $col => $label) {
                    if (! isset($info[$col])) { continue; }
                    $icon = (int) $info[$col] ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>';
                    $groupRows .= $tableRow($label, $icon, $tooltipForLabel($label));
                }
                if ($groupRows !== '') {
                    echo '<tr><td colspan="2" style="padding-top:10px;font-weight:bold;border-bottom:1px solid #ddd">' . htmlspecialchars($heading) . '</td></tr>';
                    echo $groupRows;
                }
            }
            echo '</table>';
            $panelEnd();
        @endphp
        @endif
    </div>
</div>
