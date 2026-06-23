{{-- NVMe per-disk "Self-test" view: self-test log. Inherits closures --}}
{{-- ($panelStart, $panelEnd, $formatHoursAgo) and $data, $device, $selectedDisk --}}
{{-- from the parent smart.blade.php. --}}
@php
    $disk   = $data->disk($selectedDisk);
    $health = $disk['health'];

    $curOp  = (int) ($health['current_selftest_op'] ?? 0);
    $curStr = trim((string) ($health['current_selftest_str'] ?? ''));
    $curPct = $health['current_selftest_pct'] ?? null;

    $stBadge = '';
    if ($curOp !== 0) {
        $txt = $curStr !== '' ? $curStr : 'Self-test in progress';
        if (is_numeric($curPct)) { $txt .= ' ' . (int) $curPct . '%'; }
        $stBadge = '<span class="label label-info">' . htmlspecialchars($txt) . '</span>';
    } elseif (! empty($disk['selftests'])) {
        $latest = null;
        foreach ($disk['selftests'] as $st) {
            if ($latest === null || (int) ($st['power_on_hours'] ?? 0) >= (int) ($latest['power_on_hours'] ?? 0)) {
                $latest = $st;
            }
        }
        $rt = trim((string) ($latest['result_text'] ?? '')) ?: (string) ($latest['result'] ?? '');
        if ($rt !== '') {
            $ok = stripos($rt, 'without error') !== false || stripos($rt, 'success') !== false || stripos($rt, 'completed') !== false;
            $stBadge = '<span class="label label-' . ($ok ? 'default' : 'warning') . '">' . htmlspecialchars($rt) . '</span>';
        }
    }

    $panelStart('Self-test Log', $stBadge);
    if (empty($disk['selftests']) && $curOp === 0) {
        echo '<div class="small text-muted" style="padding:4px 2px">'
            . '<i class="fa fa-info-circle"></i> No self-test data reported. This drive may not support self-tests.</div>';
    } else {
        $curPoh = $health['power_on_hours'] ?? null;
        $hasLba = false;
        foreach ($disk['selftests'] as $e) {
            if (is_numeric($e['failing_lba'] ?? null) && (int) $e['failing_lba'] > 0) { $hasLba = true; break; }
        }
        echo '<div class="table-responsive"><table class="table table-condensed table-hover">';
        echo '<thead><tr><th>Hours</th><th>Type</th><th>Status</th><th>Remaining %</th>'
            . ($hasLba ? '<th>First LBA Error</th>' : '') . '</tr></thead><tbody>';
        foreach ($disk['selftests'] as $st) {
            $type   = match ((int) ($st['test_type'] ?? 0)) { 1 => 'Short', 2 => 'Extended', 255 => 'Vendor', default => '-' };
            $result = trim((string) ($st['result_text'] ?? '')) !== '' ? $st['result_text'] : (string) ($st['result'] ?? '-');
            $h      = $st['power_on_hours'] ?? null;
            $hoursCell = (string) ($h ?? '-');
            if (is_numeric($curPoh) && is_numeric($h)) {
                $delta = (int) $curPoh - (int) $h;
                $hoursCell = $delta > 0 ? $formatHoursAgo($delta) . " ({$h})" : "<0 hour ({$h})";
            }
            $lba = $st['failing_lba'] ?? null;
            echo '<tr>'
                . '<td>' . htmlspecialchars($hoursCell) . '</td>'
                . '<td>' . htmlspecialchars($type) . '</td>'
                . '<td>' . htmlspecialchars((string) $result) . '</td>'
                . '<td></td>'
                . ($hasLba ? '<td>' . (is_numeric($lba) && (int) $lba > 0 ? htmlspecialchars((string) $lba) : '') . '</td>' : '')
                . '</tr>';
        }
        echo '</tbody></table></div>';
    }
    $panelEnd();
@endphp
