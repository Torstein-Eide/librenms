<?php

// Shared view helpers for btrfs device/global pages.
// Keep display/status helpers centralized here to avoid page drift.

$btrfs_print_sticky_first_css = static function (): void {
    static $printed = false;
    if ($printed) {
        return;
    }

    $printed = true;

    echo '<style>
.btrfs-sticky-first th:first-child,
.btrfs-sticky-first td:first-child {
    position: sticky;
    left: 0;
    z-index: 2;
    background: #fff;
}
.btrfs-sticky-first thead th:first-child {
    z-index: 3;
    background: #f5f5f5;
}
</style>';
};

$btrfs_status_badge = static function (string $state): string {
    // Map logical state to neutral/alert badge styling.
    $state_lc = strtolower($state);
    if ($state_lc === 'error') {
        $badge = 'Error';
        $class = 'label-danger';
    } elseif ($state_lc === 'missing') {
        $badge = 'Missing';
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
    // Convert numeric status codes to logical state strings.
    $code = is_numeric($value) ? (int) $value : 2;

    return match ($code) {
        0 => 'ok',
        1 => 'running',
        3 => 'error',
        4 => 'missing',
        default => 'na',
    };
};

$btrfs_combine_state_code = static function (array $codes): int {
    // Collapse multiple status codes to one with precedence:
    // Missing > Error > Running > OK > N/A.
    $normalized = [];
    foreach ($codes as $code) {
        $normalized[] = is_numeric($code) ? (int) $code : 2;
    }

    if (in_array(4, $normalized, true)) {
        return 4;
    }
    if (in_array(3, $normalized, true)) {
        return 3;
    }
    if (in_array(1, $normalized, true)) {
        return 1;
    }
    if (in_array(0, $normalized, true)) {
        return 0;
    }

    return 2;
};

$btrfs_scrub_progress_text_from_status = static function (array $scrub_status): string {
    $scrub_progress = null;

    if (is_array($scrub_status['bytes_scrubbed'] ?? null)) {
        $progress = $scrub_status['bytes_scrubbed']['progress'] ?? null;
        if (is_numeric($progress)) {
            $scrub_progress = (float) $progress;
        }
    }

    if ($scrub_progress === null) {
        $bytes_scrubbed = $scrub_status['bytes_scrubbed'] ?? null;
        if (is_array($bytes_scrubbed)) {
            $bytes_scrubbed = $bytes_scrubbed['bytes'] ?? null;
        }
        $total_to_scrub = $scrub_status['total_to_scrub'] ?? null;
        if (is_numeric($bytes_scrubbed) && is_numeric($total_to_scrub) && (float) $total_to_scrub > 0) {
            $scrub_progress = ((float) $bytes_scrubbed / (float) $total_to_scrub) * 100;
        }
    }

    return $scrub_progress === null
        ? 'N/A'
        : rtrim(rtrim(number_format($scrub_progress, 2, '.', ''), '0'), '.') . '%';
};

$btrfs_total_io_errors = static function (array $device_tables): float {
    // Aggregate IO error counters for filesystem summary views.
    $total_errors = 0.0;
    foreach ($device_tables as $dev_stats) {
        $errors = is_array($dev_stats['errors'] ?? null) ? $dev_stats['errors'] : [];
        $total_errors += (float) ($errors['corruption_errs'] ?? 0)
            + (float) ($errors['flush_io_errs'] ?? 0)
            + (float) ($errors['generation_errs'] ?? 0)
            + (float) ($errors['read_io_errs'] ?? 0)
            + (float) ($errors['write_io_errs'] ?? 0);
    }

    return $total_errors;
};

$btrfs_used_percent_text = static function ($used_value, $size_value): string {
    // Return used percentage text when total size is known.
    $used = (float) ($used_value ?? 0);
    $size = (float) ($size_value ?? 0);

    return $size > 0
        ? rtrim(rtrim(number_format(($used / $size) * 100, 2, '.', ''), '0'), '.') . '%'
        : 'N/A';
};

$btrfs_format_metric = static function ($value, string $metric, string $null_text = 'N/A'): string {
    // Generic metric formatter shared by global and device pages.
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

$btrfs_flatten_assoc_rows = static function (array $data, string $prefix = '') use (&$btrfs_flatten_assoc_rows): array {
    $rows = [];
    foreach ($data as $key => $value) {
        $segment = is_int($key) ? '[' . $key . ']' : (string) $key;
        $path = $prefix === '' ? $segment : $prefix . '.' . $segment;

        if (is_array($value)) {
            $rows = array_merge($rows, $btrfs_flatten_assoc_rows($value, $path));
            continue;
        }

        if (is_bool($value)) {
            $rows[] = ['key' => $path, 'value' => $value ? 'true' : 'false'];
        } elseif ($value === null) {
            $rows[] = ['key' => $path, 'value' => 'null'];
        } else {
            $rows[] = ['key' => $path, 'value' => (string) $value];
        }
    }

    return $rows;
};
