<?php

$btrfs_status_badge = static function (string $state): string {
    $state_lc = strtolower($state);
    if ($state_lc === 'error') {
        $badge = 'Error';
        $class = 'label-danger';
    } elseif ($state_lc === 'running') {
        $badge = 'Running';
        $class = 'label-default';
    } elseif ($state_lc === 'warning') {
        $badge = 'Warning';
        $class = 'label-warning';
    } elseif ($state_lc === 'na') {
        $badge = 'N/A';
        $class = 'label-default';
    } else {
        $badge = 'OK';
        $class = 'label-default';
    }

    return '<span class="label ' . $class . '">' . htmlspecialchars($badge) . '</span>';
};

$btrfs_status_from_code = static function ($value): string {
    $code = is_numeric($value) ? (int) $value : 2;

    return match ($code) {
        0 => 'ok',
        1 => 'running',
        3 => 'error',
        default => 'na',
    };
};

$btrfs_get_row_value = static function (array $rows, string $wanted_key): ?string {
    foreach ($rows as $row) {
        if (($row['key'] ?? null) === $wanted_key) {
            return (string) ($row['value'] ?? '');
        }
    }

    return null;
};

$btrfs_display_fs_name = static function (string $fs, array $show_rows) use ($btrfs_get_row_value): string {
    $label = $btrfs_get_row_value($show_rows, 'label');

    return ! empty($label) ? $label . ' (' . $fs . ')' : $fs;
};

$btrfs_scrub_progress_text = static function (array $scrub_rows) use ($btrfs_get_row_value): string {
    $scrub_progress = null;
    foreach ($scrub_rows as $scrub_row) {
        if (($scrub_row['key'] ?? null) === 'bytes_scrubbed.progress' && is_numeric($scrub_row['value'] ?? null)) {
            $scrub_progress = (float) $scrub_row['value'];
            break;
        }
    }

    if ($scrub_progress === null) {
        $bytes_scrubbed = $btrfs_get_row_value($scrub_rows, 'bytes_scrubbed.bytes');
        $total_to_scrub = $btrfs_get_row_value($scrub_rows, 'total_to_scrub');
        if (is_numeric($bytes_scrubbed) && is_numeric($total_to_scrub) && (float) $total_to_scrub > 0) {
            $scrub_progress = ((float) $bytes_scrubbed / (float) $total_to_scrub) * 100;
        }
    }

    return $scrub_progress === null
        ? 'N/A'
        : rtrim(rtrim(number_format($scrub_progress, 2, '.', ''), '0'), '.') . '%';
};

$btrfs_total_errors = static function (array $device_tables, array $scrub_tables): float {
    $total_errors = 0.0;
    foreach ($device_tables as $dev_stats) {
        $total_errors += (float) ($dev_stats['corruption_errs'] ?? 0)
            + (float) ($dev_stats['flush_io_errs'] ?? 0)
            + (float) ($dev_stats['generation_errs'] ?? 0)
            + (float) ($dev_stats['read_io_errs'] ?? 0)
            + (float) ($dev_stats['write_io_errs'] ?? 0);
    }
    foreach ($scrub_tables as $scrub_stats) {
        $total_errors += (float) ($scrub_stats['read_errors'] ?? 0)
            + (float) ($scrub_stats['csum_errors'] ?? 0)
            + (float) ($scrub_stats['verify_errors'] ?? 0)
            + (float) ($scrub_stats['uncorrectable_errors'] ?? 0)
            + (float) ($scrub_stats['unverified_errors'] ?? 0)
            + (float) ($scrub_stats['missing'] ?? 0)
            + (float) ($scrub_stats['device_missing'] ?? 0);
    }

    return $total_errors;
};

$btrfs_used_percent_text = static function ($used_value, $size_value): string {
    $used = (float) ($used_value ?? 0);
    $size = (float) ($size_value ?? 0);

    return $size > 0
        ? rtrim(rtrim(number_format(($used / $size) * 100, 2, '.', ''), '0'), '.') . '%'
        : 'N/A';
};

$btrfs_format_metric = static function ($value, string $metric, string $null_text = 'N/A'): string {
    if ($value === null || $value === '') {
        return $null_text;
    }

    if (str_contains($metric, 'ratio')) {
        return number_format((float) $value, 2);
    }

    if (str_contains($metric, 'size') || str_contains($metric, 'used') || str_contains($metric, 'free')) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $v = (float) $value;
        $i = 0;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return round($v, 2) . ' ' . $units[$i];
    }

    return is_numeric($value) ? number_format((float) $value) : (string) $value;
};
