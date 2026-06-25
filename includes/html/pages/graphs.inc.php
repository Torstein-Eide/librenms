<?php

use App\Facades\LibrenmsConfig;
use LibreNMS\Util\Time;

unset($vars['page']);

// Setup here

if (session('widescreen')) {
    $graph_width = 1700;
    $thumb_width = 180;
} else {
    $graph_width = 1075;
    $thumb_width = 113;
}

// Handle time-mode toggle: URL param sets cookie, then determines parse path.
if (isset($vars['time_mode'])) {
    $cookieMode = in_array($vars['time_mode'], ['relative', 'absolute'], true) ? $vars['time_mode'] : 'absolute';
    setcookie('graph_time_mode', $cookieMode, ['expires' => time() + 365 * 24 * 3600, 'path' => '/']);
    $_COOKIE['graph_time_mode'] = $cookieMode;
    unset($vars['time_mode'], $vars['from'], $vars['to']);
}
$relativeMode = ($_COOKIE['graph_time_mode'] ?? 'absolute') === 'relative';

if ($relativeMode) {
    // Keep the raw rrdtool expression in $vars['from']; GraphParameters resolves it at render time.
    // No 'to' → GraphParameters defaults to time().
    $vars['from'] = (isset($vars['from']) && $vars['from'] !== '') ? (string) $vars['from'] : '-1d';
    unset($vars['to']);
} else {
    $vars['from'] = Time::parseAt($vars['from'] ?? '') ?: LibrenmsConfig::get('time.day');
    $vars['to'] = Time::parseAt($vars['to'] ?? '') ?: LibrenmsConfig::get('time.now');
}

preg_match('/^(?P<type>[A-Za-z0-9]+)_(?P<subtype>.+)/', (string) $vars['type'], $graphtype);

$type = basename($graphtype['type']);
$subtype = basename($graphtype['subtype']);
$id = $vars['id'] ?? null;

if (isset($vars['device'])) {
    $device = DeviceCache::get($vars['device']);
}

$auth = false;
if (is_file('includes/html/graphs/' . $type . '/auth.inc.php')) {
    require 'includes/html/graphs/' . $type . '/auth.inc.php';
}

if (! $auth) {
    require 'includes/html/error-no-perm.inc.php';
} else {
    if (! empty($vars['page_title'])) {
        $title .= ' :: ' . $vars['page_title'];
    } elseif (! empty($vars['title']) && $vars['title'] !== 'no') {
        $title .= ' :: ' . $vars['title'];
    } elseif (LibrenmsConfig::has("graph_types.$type.$subtype.descr")) {
        $title .= ' :: ' . LibrenmsConfig::get("graph_types.$type.$subtype.descr");
    } elseif ($type == 'device' && $subtype == 'collectd') {
        $title .= ' :: ' . LibreNMS\Util\StringHelpers::niceCase($subtype) . ' :: ' . $vars['c_plugin'];
        if (isset($vars['c_plugin_instance'])) {
            $title .= ' - ' . $vars['c_plugin_instance'];
        }
        $title .= ' - ' . $vars['c_type'];
        if (isset($vars['c_type_instance'])) {
            $title .= ' - ' . $vars['c_type_instance'];
        }
    } else {
        $title .= ' :: ' . LibreNMS\Util\StringHelpers::niceCase($subtype);
    }

    $graph_array = $vars;
    $graph_array['height'] = '60';
    $graph_array['width'] = $thumb_width;
    $graph_array['legend'] = 'no';
    $graph_array['to'] = LibrenmsConfig::get('time.now');

    print_optionbar_start();
    if (! isset($vars['show_title']) || $vars['show_title'] !== 'no') {
        echo $title;
    }

    // FIXME allow switching between types for sensor and wireless also restrict types to ones that have data
    if (! in_array($type, ['sensor', 'wireless'])) {
        $graph_subtypes = get_graph_subtypes($type);
        if (count($graph_subtypes) > 1) {
            echo '<div style="float: right;"><form action="">';
            echo csrf_field();
            echo "<select name='type' id='type' onchange=\"window.open(this.options[this.selectedIndex].value,'_top')\" class='devices-graphs-select'>";

            foreach ($graph_subtypes as $avail_type) {
                echo "<option value='" . LibreNMS\Util\Url::generate($vars, ['type' => $type . '_' . $avail_type, 'page' => 'graphs']) . "'";
                if ($avail_type == $subtype) {
                    echo ' selected';
                }
                $display_type = LibreNMS\Util\StringHelpers::niceCase($avail_type);
                echo ">$display_type</option>";
            }
            echo '</select></form></div>';
        }
    }

    // object_array: optional ordered dropdowns defined by each graph type's auth.inc.php.
    // Rendered in reverse key order so float:right stacks them left-to-right (1, 2, ...).
    if (isset($object_array) && $object_array !== []) {
        foreach (array_reverse($object_array, true) as $obj) {
            if (empty($obj['options'])) {
                continue;
            }
            echo '<div style="float: right; margin-left: 4px;"><form action="">';
            echo csrf_field();
            echo "<select onchange=\"window.open(this.options[this.selectedIndex].value,'_top')\" class='devices-graphs-select'>";
            foreach ($obj['options'] as $option) {
                echo "<option value='" . $option['url'] . "'" . ($option['selected'] ? ' selected' : '') . '>';
                echo htmlspecialchars($option['label']);
                echo '</option>';
            }
            echo '</select></form></div>';
        }
    }

    print_optionbar_end();

    $show_command = isset($vars['showcommand']) && $vars['showcommand'] == 'yes';
    if (! $show_command) {
        $thumb_array = LibrenmsConfig::get('graphs.row.normal');
        $relPeriodMap = ['sixhour' => '-6h', 'day' => '-1d', 'week' => '-1w', 'month' => '-1mo', 'year' => '-1y'];

        echo '<table width=100% class="thumbnail_graph_table"><tr>';

        foreach ($thumb_array as $period => $text) {
            $graph_array['from'] = LibrenmsConfig::get("time.$period");

            $link_array = $vars;
            if ($relativeMode) {
                $link_array['from'] = $relPeriodMap[$period] ?? $vars['from'];
                unset($link_array['to']);
            } else {
                $link_array['from'] = $graph_array['from'];
                $link_array['to'] = $graph_array['to'];
            }
            $link_array['page'] = 'graphs';
            $link = LibreNMS\Util\Url::generate($link_array);

            echo '<td style="text-align: center;">';
            echo '<b>' . $text . '</b>';
            echo '<a href="' . $link . '">';
            echo LibreNMS\Util\Url::lazyGraphTag($graph_array);
            echo '</a>';
            echo '</td>';
        }

        echo '</tr></table>';
        echo '<hr />';
    }

    $graph_array = $vars;
    $graph_array['height'] = LibrenmsConfig::get('webui.min_graph_height');
    $graph_array['width'] = $graph_width;

    if ($screen_width = Session::get('screen_width')) {
        if ($screen_width > 800) {
            $graph_array['width'] = ($screen_width - ($screen_width / 10));
        } else {
            $graph_array['width'] = ($screen_width - ($screen_width / 4));
        }
    }

    if ($screen_height = Session::get('screen_height')) {
        if ($screen_height > 960) {
            $graph_array['height'] = ($screen_height - ($screen_height / 2));
        } else {
            $graph_array['height'] = max($graph_array['height'], $screen_height - ($screen_height / 1.5));
        }
    }

    include_once 'includes/html/print-date-selector.inc.php';

    echo '<div style="padding-top: 5px";></div>';
    echo '<center>';
    if (isset($vars['legend']) && $vars['legend'] == 'no') {
        echo generate_link('Show Legend', $vars, ['page' => 'graphs', 'legend' => null]);
    } else {
        echo generate_link('Hide Legend', $vars, ['page' => 'graphs', 'legend' => 'no']);
    }

    // FIXME : do this properly
    //  if ($type == "port" && $subtype == "bits")
    //  {
    echo ' | ';
    if (isset($vars['previous']) && $vars['previous'] == 'yes') {
        echo generate_link('Hide Previous', $vars, ['page' => 'graphs', 'previous' => null]);
    } else {
        echo generate_link('Show Previous', $vars, ['page' => 'graphs', 'previous' => 'yes']);
    }
    //  }

    echo ' | ';
    if ($show_command) {
        echo generate_link('Hide RRD Command', $vars, ['page' => 'graphs', 'showcommand' => null]);
    } else {
        echo generate_link('Show RRD Command', $vars, ['page' => 'graphs', 'showcommand' => 'yes']);
    }

    echo ' | ';
    if (isset($vars['show_title']) && $vars['show_title'] === 'no') {
        echo generate_link('Show Title', $vars, ['page' => 'graphs', 'show_title' => null]);
    } else {
        echo generate_link('Hide Title', $vars, ['page' => 'graphs', 'show_title' => 'no']);
    }

    echo ' | ';
    if ($relativeMode) {
        echo generate_link('Absolute Time', $vars, ['page' => 'graphs', 'time_mode' => 'absolute']);
    } else {
        echo generate_link('Relative Time', $vars, ['page' => 'graphs', 'time_mode' => 'relative']);
    }

    if ($vars['type'] == 'port_bits') {
        echo ' | ';
        if ($vars['port_speed_zoom'] ?? LibrenmsConfig::get('graphs.port_speed_zoom')) {
            echo generate_link('Zoom to Traffic', $vars, ['page' => 'graphs', 'port_speed_zoom' => 0]);
        } else {
            echo generate_link('Zoom to Port Speed', $vars, ['page' => 'graphs', 'port_speed_zoom' => 1]);
        }
        echo ' | To show trend, set to future date';
    }

    if (str_contains((string) $vars['type'], 'sensor_')) {
        echo ' | To show trend, set to future date';
    }

    echo '</center>';

    echo generate_graph_js_state($graph_array);

    echo '<div style="width: ' . $graph_array['width'] . '; margin: auto;"><center>';
    if (LibrenmsConfig::get('webui.dynamic_graphs', false) === true) {
        echo generate_dynamic_graph_js($graph_array);
        echo generate_dynamic_graph_tag($graph_array);
    } else {
        echo LibreNMS\Util\Url::lazyGraphTag($graph_array);
    }
    echo '</center></div>';

    if (LibrenmsConfig::has('graph_descr.' . $vars['type'])) {
        print_optionbar_start();
        echo '<div style="float: left; width: 30px;">
            <div style="margin: auto auto;">
            <i class="fa-solid fa-circle-info fa-lg icon-theme" aria-hidden="true"></i>
            </div>
            </div>';
        echo e(LibrenmsConfig::get('graph_descr.' . $vars['type']));
        print_optionbar_end();
    }

    if ($show_command) {
        $vars = $graph_array;
        $_GET = $graph_array;
        $command_only = 1;

        require 'includes/html/graphs/graph.inc.php';
    }
}
