<?php

// Panel renderers for the btrfs device app page.
// Kept in a dedicated include to keep the main page file smaller and easier to scan.

$render_scrub_overview_panel = static function (array $split, array $rows, string $badge, string $panel_col_class) use ($format_display_name, $format_display_value): void {
    echo '<div class="' . $panel_col_class . '">';
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Scrub Overview<div class="pull-right">' . $badge . '</div></h3></div>';
    echo '<div class="panel-body">';

    if (count($rows) === 0) {
        echo '<em>No data returned</em>';
    } else {
        $overview_map = [];
        foreach ($split['overview'] as $overview_row) {
            $overview_map[$overview_row['key']] = $overview_row['value'];
        }

        echo '<div class="table-responsive">';
        echo '<table class="table table-condensed table-striped btrfs-sticky-first">';
        echo '<thead><tr><th>Key</th><th>Value</th></tr></thead>';
        echo '<tbody>';
        foreach ($split['overview'] as $overview_row) {
            $key = $overview_row['key'];
            $value = $overview_row['value'];

            if ($key === 'status' || $key === 'bytes_scrubbed.progress') {
                continue;
            }

            if ($key === 'bytes_scrubbed.bytes') {
                $progress = $overview_map['bytes_scrubbed.progress'] ?? null;
                $formatted = $format_display_value($value, 'bytes');
                if (is_numeric($progress)) {
                    $progress_text = rtrim(rtrim(number_format((float) $progress, 2, '.', ''), '0'), '.');
                    $value = "$formatted (" . $progress_text . '%)';
                } else {
                    $value = $formatted;
                }
                $key = 'bytes_scrubbed';
            } elseif ($key === 'total_to_scrub') {
                $value = $format_display_value($value, 'bytes');
            } elseif ($key === 'duration') {
                if (! isset($overview_map['bytes_scrubbed.bytes'])) {
                    $bytes_scrubbed = $overview_map['bytes_scrubbed'] ?? null;
                    $total_to_scrub = $overview_map['total_to_scrub'] ?? null;
                    $bytes_scrubbed_num = LibreNMS\Util\Number::toBytes((string) ($bytes_scrubbed ?? ''));
                    $total_to_scrub_num = LibreNMS\Util\Number::toBytes((string) ($total_to_scrub ?? ''));
                    if (! is_nan($bytes_scrubbed_num) && ! is_nan($total_to_scrub_num) && $total_to_scrub_num > 0) {
                        $progress = ($bytes_scrubbed_num / $total_to_scrub_num) * 100;
                        $value = rtrim(rtrim(number_format($progress, 2, '.', ''), '0'), '.') . '%';
                    }
                }
            }

            echo '<tr>';
            echo '<td>' . htmlspecialchars($format_display_name($key)) . '</td>';
            echo '<td>' . htmlspecialchars($value) . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '</div>';
    echo '</div>';
    echo '</div>';
};

$render_scrub_per_device_fs_panel = static function (
    array $split,
    ?string $selected_dev_path,
    array $path_to_dev_id,
    array $link_array,
    string $selected_fs
) use ($to_bool, $scrub_counter_columns, $format_display_name, $format_display_value, $status_badge): void {
    $devices = $split['devices'];
    $hidden_columns = ['path', 'id', 'section', 'has_status_suffix', 'has_stats', 'no_stats_available', 'last_physical'];

    echo '<div class="col-md-12">';
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Scrub Per Device</h3></div>';
    echo '<div class="panel-body">';

    if (isset($selected_dev_path) && $selected_dev_path !== '' && isset($devices[$selected_dev_path])) {
        $devices = [$selected_dev_path => $devices[$selected_dev_path]];
    }

    if (count($devices) > 0) {
        echo '<div class="table-responsive">';
        echo '<table class="table table-condensed table-striped btrfs-sticky-first">';
        echo '<thead><tr><th>Device</th>';
        foreach ($split['device_columns'] as $column) {
            if (in_array($column, $hidden_columns, true)) {
                continue;
            }
            echo '<th>' . htmlspecialchars($format_display_name($column)) . '</th>';
        }
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($devices as $device_name => $metrics) {
            echo '<tr>';
            if (isset($path_to_dev_id[$device_name])) {
                echo '<td>' . generate_link(htmlspecialchars($device_name), $link_array, ['fs' => $selected_fs, 'dev' => $path_to_dev_id[$device_name]]) . '</td>';
            } else {
                echo '<td>' . htmlspecialchars($device_name) . '</td>';
            }

            $has_stats = $to_bool($metrics['has_stats'] ?? null);
            $no_stats_available = $to_bool($metrics['no_stats_available'] ?? null);
            $hide_counters = $has_stats === false || $no_stats_available === true;
            foreach ($split['device_columns'] as $column) {
                if (in_array($column, $hidden_columns, true)) {
                    continue;
                }
                $value = $metrics[$column] ?? '';
                $display_value = $format_display_value($value, $column);
                if ($hide_counters && in_array($column, $scrub_counter_columns, true)) {
                    $display_value = '';
                }
                if ($column === 'status') {
                    echo '<td>' . $status_badge((string) $value) . '</td>';
                } else {
                    echo '<td>' . htmlspecialchars($display_value) . '</td>';
                }
            }
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    } else {
        echo '<p class="text-muted">No per-device scrub details were reported.</p>';
    }

    echo '</div>';
    echo '</div>';
    echo '</div>';
};

$render_scrub_per_device_panel = static function (
    array $split,
    string $panel_col_class,
    ?string $selected_dev_path
) use ($to_bool, $scrub_counter_columns, $format_display_name, $format_display_value, $status_badge): void {
    $devices = $split['devices'];
    $hidden_columns = ['path', 'id', 'section', 'has_status_suffix', 'has_stats', 'no_stats_available', 'last_physical'];

    echo '<div class="' . $panel_col_class . '">';
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Scrub Per Device</h3></div>';
    echo '<div class="panel-body">';

    if (isset($selected_dev_path) && $selected_dev_path !== '' && isset($devices[$selected_dev_path])) {
        $devices = [$selected_dev_path => $devices[$selected_dev_path]];
    }

    if (count($devices) > 0) {
        $single_metrics = reset($devices);
        echo '<div class="table-responsive">';
        echo '<table class="table table-condensed table-striped btrfs-sticky-first">';
        echo '<thead><tr><th>Metric</th><th>Value</th></tr></thead>';
        echo '<tbody>';

        foreach ($split['device_columns'] as $column) {
            if (in_array($column, $hidden_columns, true)) {
                continue;
            }
            $has_stats = $to_bool($single_metrics['has_stats'] ?? null);
            $no_stats_available = $to_bool($single_metrics['no_stats_available'] ?? null);
            $hide_counters = $has_stats === false || $no_stats_available === true;
            $value = $single_metrics[$column] ?? '';
            $display_value = $format_display_value($value, $column);
            if ($hide_counters && in_array($column, $scrub_counter_columns, true)) {
                $display_value = '';
            }
            echo '<tr>';
            echo '<td>' . htmlspecialchars($format_display_name($column)) . '</td>';
            if ($column === 'status') {
                echo '<td>' . $status_badge((string) $value) . '</td>';
            } else {
                echo '<td>' . htmlspecialchars($display_value) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    } else {
        echo '<p class="text-muted">No per-device scrub details were reported.</p>';
    }

    echo '</div>';
    echo '</div>';
    echo '</div>';
};

$render_generic_panel = static function (
    string $command_name,
    string $panel_title,
    ?string $panel_badge,
    string $panel_col_class,
    array $split,
    ?string $selected_dev,
    ?string $selected_dev_path,
    array $path_to_dev_id,
    array $link_array,
    string $selected_fs
) use ($format_display_name, $format_display_value): void {
    echo '<div class="' . $panel_col_class . '">';
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">' . $panel_title;
    if (! empty($panel_badge)) {
        echo '<div class="pull-right">' . $panel_badge . '</div>';
    }
    echo '</h3></div>';
    echo '<div class="panel-body">';

    if (count($split['overview']) === 0 && count($split['devices']) === 0) {
        echo '<em>No data returned</em>';
    } else {
        $has_overview = count($split['overview']) > 0;

        if ($has_overview) {
            echo '<h4>Overview</h4>';
            echo '<div class="table-responsive">';
            echo '<table class="table table-condensed table-striped btrfs-sticky-first">';
            echo '<thead><tr><th>Key</th><th>Value</th></tr></thead>';
            echo '<tbody>';
            foreach ($split['overview'] as $overview_row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($format_display_name($overview_row['key'])) . '</td>';
                echo '<td>' . htmlspecialchars($format_display_value($overview_row['value'], $overview_row['key'])) . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }

        if (count($split['devices']) > 0) {
            if ($has_overview) {
                echo '<h4>Per Device</h4>';
            }

            $devices = $split['devices'];
            if (isset($selected_dev_path) && $selected_dev_path !== '' && isset($devices[$selected_dev_path])) {
                $devices = [$selected_dev_path => $devices[$selected_dev_path]];
            }

            if (isset($selected_dev) && count($devices) === 1) {
                $single_metrics = reset($devices);
                echo '<div class="table-responsive">';
                echo '<table class="table table-condensed table-striped btrfs-sticky-first">';
                echo '<thead><tr><th>Metric</th><th>Value</th></tr></thead>';
                echo '<tbody>';

                foreach ($split['device_columns'] as $column) {
                    $value = $single_metrics[$column] ?? '';
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($format_display_name($column)) . '</td>';
                    echo '<td>' . htmlspecialchars($format_display_value($value, $column)) . '</td>';
                    echo '</tr>';
                }

                echo '</tbody>';
                echo '</table>';
                echo '</div>';
            } else {
                echo '<div class="table-responsive">';
                echo '<table class="table table-condensed table-striped btrfs-sticky-first">';
                echo '<thead><tr><th>Device</th>';
                foreach ($split['device_columns'] as $column) {
                    echo '<th>' . htmlspecialchars($format_display_name($column)) . '</th>';
                }
                echo '</tr></thead>';
                echo '<tbody>';

                foreach ($devices as $device_name => $metrics) {
                    echo '<tr>';
                    if (isset($path_to_dev_id[$device_name])) {
                        echo '<td>' . generate_link(htmlspecialchars($device_name), $link_array, ['fs' => $selected_fs, 'dev' => $path_to_dev_id[$device_name]]) . '</td>';
                    } else {
                        echo '<td>' . htmlspecialchars($device_name) . '</td>';
                    }

                    foreach ($split['device_columns'] as $column) {
                        $value = $metrics[$column] ?? '';
                        echo '<td>' . htmlspecialchars($format_display_value($value, $column)) . '</td>';
                    }
                    echo '</tr>';
                }

                echo '</tbody>';
                echo '</table>';
                echo '</div>';
            }
        }
    }

    echo '</div>';
    echo '</div>';
    echo '</div>';
};

$render_balance_panel = static function (array $split, array $rows, string $panel_col_class, ?string $selected_dev, int $balance_status_code) use ($status_badge, $format_display_name, $format_display_value): void {
    $state = match ($balance_status_code) {
        0 => 'ok',
        1 => 'running',
        2 => 'na',
        3 => 'error',
        4 => 'missing',
        default => 'na',
    };
    $badge = $status_badge($state);

    $overview_rows = [];
    foreach ($split['overview'] as $row) {
        $key = $row['key'];
        if ($key !== 'path' && $key !== 'status') {
            $overview_rows[] = $row;
        }
    }

    $profiles = $split['devices'];
    $has_profiles = count($profiles) > 0;
    $has_overview = count($overview_rows) > 0 || $has_profiles;
    $show_overview = ! isset($selected_dev) && ($has_overview || count($rows) === 0);
    $is_idle = $balance_status_code !== 1;

    echo '<div class="' . $panel_col_class . '">';
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Balance<div class="pull-right">' . $badge . '</div></h3></div>';
    echo '<div class="panel-body">';

    if ($is_idle && ! $has_profiles) {
        echo '<p class="text-muted">No balance operation running.</p>';
    }

    if ($show_overview) {
        if (count($overview_rows) > 0) {
            echo '<div class="table-responsive">';
            echo '<table class="table table-condensed table-striped btrfs-sticky-first">';
            echo '<thead><tr><th>Key</th><th>Value</th></tr></thead>';
            echo '<tbody>';
            foreach ($overview_rows as $row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($format_display_name($row['key'])) . '</td>';
                echo '<td>' . htmlspecialchars($format_display_value($row['value'], $row['key'])) . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }

        if ($has_profiles) {
            echo '<h4>Profiles</h4>';
            echo '<div class="table-responsive">';
            echo '<table class="table table-condensed table-striped btrfs-sticky-first">';
            echo '<thead><tr>';
            foreach ($split['device_columns'] as $column) {
                echo '<th>' . htmlspecialchars($format_display_name($column)) . '</th>';
            }
            echo '</tr></thead>';
            echo '<tbody>';
            foreach ($profiles as $profile) {
                echo '<tr>';
                foreach ($split['device_columns'] as $column) {
                    $value = $profile[$column] ?? '';
                    echo '<td>' . htmlspecialchars($format_display_value($value, $column)) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }
    }

    echo '</div>';
    echo '</div>';
    echo '</div>';
};
