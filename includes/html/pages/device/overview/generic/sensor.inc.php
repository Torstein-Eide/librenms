<?php

use LibreNMS\Util\Html;

$sensors = DeviceCache::getPrimary()->sensors->where('sensor_class', $sensor_class->value)->where('group', '!=', 'transceiver')->sortBy([
    ['group', 'asc'],
    ['sensor_descr', 'asc'],
]); // cache all sensors on device and exclude transceivers

if ($sensors->isNotEmpty()) {
    $sensor_fa_icon = 'fa-' . $sensor_class->icon();

    echo '
        <div class="row">
        <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
        <div class="panel-heading">';
    echo '<a href="device/device=' . $device['device_id'] . '/tab=health/metric=' . $sensor_class->value . '/"><i class="fa ' . $sensor_fa_icon . ' fa-lg icon-theme" aria-hidden="true"></i><strong> ' . $sensor_class->label() . '</strong></a>';
    echo '      </div>
        <table class="table table-hover table-condensed table-striped">';

    // Build two lookup tables used to decide whether to render or suppress a group heading.
    //
    // $groupCounts  — number of sensors whose group field matches a given path exactly.
    //                 Used to detect single-sensor groups.
    // $groupHasChildren — set of group paths that have at least one sensor in a deeper
    //                     sub-path (e.g. 'volum1' is marked when 'volum1::/dev/sdc' exists).
    //                     A parent heading must always be shown even when it has only one
    //                     directly-associated sensor, so the child headings have an anchor.
    //
    // Group paths use '::' as a level separator, e.g. 'volum1::/dev/sdc'.
    // See doc/Developing/os/Sensor-Groups.md for the full design.
    $groupCounts = [];      // exact group string → sensor count
    $groupHasChildren = []; // group string → true when a deeper path starts with it

    foreach ($sensors as $sensor) {
        $g = $sensor->group ?? '';
        $groupCounts[$g] = ($groupCounts[$g] ?? 0) + 1;
        $parts = $g !== '' ? explode('::', $g) : [];
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $ancestor = implode('::', array_slice($parts, 0, $i + 1));
            $groupHasChildren[$ancestor] = true;
        }
    }

    // A group heading is suppressed (not rendered) when all of these hold:
    //   - the group path is not empty
    //   - exactly one sensor carries this exact group path
    //   - no sensor sits in a sub-group of it (no children)
    // Suppressed groups show the sensor at the parent indentation level with
    // the full sensor_descr so the name stays self-contained.
    $isGroupSuppressed = function (string $groupPath) use ($groupCounts, $groupHasChildren): bool {
        if ($groupPath === '') {
            return true;
        }

        return ($groupCounts[$groupPath] ?? 0) === 1 && empty($groupHasChildren[$groupPath]);
    };

    // $currentPath tracks which group heading levels have already been emitted for the
    // current position in the sorted sensor list.  When the group of the next sensor
    // diverges at depth D, headings for D and all deeper levels are re-emitted.
    $currentPath = [];

    foreach ($sensors as $sensor) {
        $groupStr = $sensor->group ?? '';
        // Split '::'-separated group path into individual level names.
        $parts = $groupStr !== '' ? explode('::', $groupStr) : [];

        // Walk each path level.  The first depth where the new sensor's path differs
        // from $currentPath triggers a re-emit of all remaining heading levels.
        for ($depth = 0; $depth < count($parts); $depth++) {
            if (($currentPath[$depth] ?? null) !== $parts[$depth]) {
                // Path diverges here — truncate the tracked path and emit headings for
                // every level from $depth downward.
                $currentPath = array_slice($currentPath, 0, $depth);
                for ($d = $depth; $d < count($parts); $d++) {
                    $pathToHere = implode('::', array_slice($parts, 0, $d + 1));
                    if (! $isGroupSuppressed($pathToHere)) {
                        // Indent by depth: 4 px base + 16 px per additional level.
                        $padding = ($d * 16) + 4;
                        $headingLabel = htmlspecialchars($parts[$d], ENT_QUOTES, 'UTF-8');
                        echo "<tr><td colspan='3' style='padding-left:{$padding}px'><strong>{$headingLabel}</strong></td></tr>";
                    }
                    $currentPath[$d] = $parts[$d];
                }
                break;
            }
        }

        // FIXME - make this "four graphs in popup" a function/include and "small graph" a function.
        // FIXME - So now we need to clean this up and move it into a function. Isn't it just "print-graphrow"?
        // FIXME - DUPLICATED IN health/sensors
        $graph_array = [];
        $graph_array['height'] = '100';
        $graph_array['width'] = '210';
        $graph_array['to'] = App\Facades\LibrenmsConfig::get('time.now');
        $graph_array['id'] = $sensor->sensor_id;
        $graph_array['type'] = 'sensor_' . $sensor_class->value;
        $graph_array['from'] = App\Facades\LibrenmsConfig::get('time.day');
        $graph_array['legend'] = 'no';

        $link_array = $graph_array;
        $link_array['page'] = 'graphs';
        unset($link_array['height'], $link_array['width'], $link_array['legend']);
        $link = LibreNMS\Util\Url::generate($link_array);

        if ($sensor->poller_type == 'ipmi') {
            $sensor->sensor_descr = substr((string) ipmiSensorName($device['hardware'], $sensor->sensor_descr), 0, 48);
        } else {
            $sensor->sensor_descr = substr((string) $sensor->sensor_descr, 0, 48);
        }

        // When the group heading is visible, strip repeated group text from sensor_descr.
        // Prefix strip candidates are tried in order:
        //   1) full group path normalized as spaces, e.g. 'A::B' -> 'A B'
        //   2) first group segment
        //   3) last group segment
        //
        // This supports both nested leaf naming and patterns like:
        //   group: 'BtrFS pool1::devices'
        //   desc:  'BtrFS pool1 /dev/sda IO'
        // where stripping the first segment yields '/dev/sda IO'.
        //
        // Stripping is skipped when it would leave an empty string. Suppressed groups
        // are not stripped because no heading is shown.
        $displayDescr = $sensor->sensor_descr;
        if ($groupStr !== '' && ! $isGroupSuppressed($groupStr)) {
            $candidates = [];
            $normalizedGroup = trim(str_replace('::', ' ', $groupStr));
            if ($normalizedGroup !== '') {
                $candidates[] = $normalizedGroup;
            }

            $firstSegment = $parts[0] ?? '';
            if ($firstSegment !== '') {
                $candidates[] = $firstSegment;
            }

            $lastSegment = $parts[count($parts) - 1] ?? '';
            if ($lastSegment !== '') {
                $candidates[] = $lastSegment;
            }

            foreach (array_values(array_unique($candidates)) as $candidate) {
                if (stripos($displayDescr, $candidate) === 0) {
                    $stripped = ltrim(substr($displayDescr, strlen($candidate)), " \t-_:");
                    if ($stripped !== '') {
                        $displayDescr = $stripped;
                        break;
                    }
                }
            }
        }

        $overlib_content = '<div class=overlib><span class=overlib-text>' . $device['hostname'] . ' - ' . $sensor->sensor_descr . '</span><br />';
        foreach (['day', 'week', 'month', 'year'] as $period) {
            $graph_array['from'] = App\Facades\LibrenmsConfig::get("time.$period");
            $overlib_content .= str_replace('"', "\'", LibreNMS\Util\Url::graphTag($graph_array));
        }

        $overlib_content .= '</div>';

        $graph_array['width'] = 80;
        $graph_array['height'] = 20;
        $graph_array['bg'] = 'ffffff00';
        // the 00 at the end makes the area transparent.
        $graph_array['from'] = App\Facades\LibrenmsConfig::get('time.day');
        $sensor_minigraph = LibreNMS\Util\Url::lazyGraphTag($graph_array);

        $sensor_current = Html::severityToLabel($sensor->currentStatus(), $sensor->formatValue());

        // Indent sensor rows to match visible heading depth: same base (4 px)
        // + 16 px per shown group level. Suppressed groups do not add indentation.
        $visibleDepth = 0;
        for ($d = 0; $d < count($parts); $d++) {
            $pathToHere = implode('::', array_slice($parts, 0, $d + 1));
            if (! $isGroupSuppressed($pathToHere)) {
                $visibleDepth++;
            }
        }
        $sensorPadding = ($visibleDepth * 16) + 4;

        echo "<tr><td style='padding-left:{$sensorPadding}px'><div style=\"display: grid; grid-gap: 10px; grid-template-columns: 3fr 1fr 1fr;\">
            <div>" . LibreNMS\Util\Url::overlibLink($link, LibreNMS\Util\Rewrite::shortenIfName($displayDescr), $overlib_content, $sensor_class->value) . '</div>
            <div>' . LibreNMS\Util\Url::overlibLink($link, $sensor_minigraph, $overlib_content, $sensor_class->value) . '</div>
            <div>' . LibreNMS\Util\Url::overlibLink($link, $sensor_current, $overlib_content, $sensor_class->value) . '</div>
            </div></td></tr>';
    }//end foreach

    echo '</table>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}//end if
