<?php

use App\Models\EntPhysical;
use Illuminate\Database\Eloquent\Builder;
use LibreNMS\Util\Html;

function printEntPhysical($device, $ent, $level, $class)
{
    $ents = dbFetchRows('SELECT * FROM `entPhysical` WHERE device_id = ? AND entPhysicalContainedIn = ? ORDER BY entPhysicalContainedIn,entPhysicalIndex', [$device['device_id'], $ent]);

    foreach ($ents as $ent) {
        //Let's find if we have any sensors attached to the current entity;
        //We hit this code for every type of entity because not all vendors have 1 'sensor' entity per sensor
        // The sensor_index fallback excludes poller_type='ipmi': IPMI sensors come from
        // ipmitool (no ENTITY-MIB entity) and use a sequential sensor_index that would
        // otherwise mismatch onto unrelated low-index entities. TODO: attach IPMI sensors
        // to their real hardware entity. See LibreNMS\OS\Linux::discoverEntityPhysical.
        $sensors = DeviceCache::getPrimary()->sensors()->where(fn (Builder $query) => $query->where('entPhysicalIndex', $ent['entPhysicalIndex'])
            ->orWhere(fn (Builder $q) => $q->whereNull('entPhysicalIndex')->where('poller_type', '!=', 'ipmi')->where('sensor_index', $ent['entPhysicalIndex'])))->get();
        echo "
 <li class='$class'>";

        if ($ent['entPhysicalClass'] == 'chassis') {
            echo '<i class="fa fa-server fa-lg icon-theme" aria-hidden="true"></i> ';
        } elseif ($ent['entPhysicalClass'] == 'module') {
            echo '<i class="fa fa-database fa-lg icon-theme" aria-hidden="true"></i> ';
        } elseif ($ent['entPhysicalClass'] == 'port') {
            echo '<i class="fa fa-link fa-lg icon-theme" aria-hidden="true"></i> ';
        } elseif ($ent['entPhysicalClass'] == 'container') {
            echo '<i class="fa fa-square fa-lg icon-theme" aria-hidden="true"></i> ';
        } elseif ($ent['entPhysicalClass'] == 'sensor') {
            echo '<i class="fa fa-heartbeat fa-lg icon-theme" aria-hidden="true"></i> ';
        } elseif ($ent['entPhysicalClass'] == 'backplane') {
            echo '<i class="fa fa-bars fa-lg icon-theme" aria-hidden="true"></i> ';
        } elseif ($ent['entPhysicalClass'] == 'stack') {
            echo '<i class="fa fa-list-ol fa-lg icon-theme" aria-hidden="true"></i> ';
        } elseif ($ent['entPhysicalClass'] == 'powerSupply') {
            echo '<i class="fa fa-bolt fa-lg icon-theme" aria-hidden="true"></i> ';
        }

        if ($ent['entPhysicalParentRelPos'] > '-1') {
            echo '<strong>' . e($ent['entPhysicalParentRelPos']) . '.</strong> ';
        }

        $display_entPhysicalName = e($ent['entPhysicalName']);
        if ($ent['ifIndex']) {
            $port = PortCache::getByIfIndex($ent['ifIndex'], $device['device_id']);
            $display_entPhysicalName = \LibreNMS\Util\Url::modernPortLink($port);
        }

        if ($ent['entPhysicalModelName'] && $display_entPhysicalName) {
            echo '<strong>' . e($ent['entPhysicalModelName']) . '</strong> (' . $display_entPhysicalName . ')';
        } elseif ($ent['entPhysicalModelName']) {
            echo '<strong>' . e($ent['entPhysicalModelName']) . '</strong>';
        } elseif (is_numeric($ent['entPhysicalName']) && $ent['entPhysicalVendorType']) {
            echo '<strong>' . e($ent['entPhysicalName']) . ' ' . e($ent['entPhysicalVendorType']) . '</strong>';
        } elseif ($display_entPhysicalName) {
            echo '<strong>' . $display_entPhysicalName . '</strong>';
        } elseif ($ent['entPhysicalDescr']) {
            echo '<strong>' . e($ent['entPhysicalDescr']) . '</strong>';
        } elseif ($ent['entPhysicalClass']) {
            echo '<strong>' . e($ent['entPhysicalClass']) . '</strong>';
        }

        // Display matching sensor value (without descr, as we have only one)
        if ($sensors->count() == 1) {
            foreach ($sensors as $sensor) {
                echo "<a href='graphs/id=" . $sensor->sensor_id . '/type=sensor_' . $sensor->sensor_class . "/' onmouseover=\"return overlib('<img src=\'graph.php?id=" . $sensor->sensor_id . '&amp;type=sensor_' . $sensor->sensor_class . '&amp;from=-2d&amp;to=now&amp;width=400&amp;height=150&amp;a=' . $ent['entPhysical_id'] . "\'><img src=\'graph.php?id=" . $sensor->sensor_id . '&amp;type=sensor_' . $sensor->sensor_class . '&amp;from=-2w&amp;to=now&amp;width=400&amp;height=150&amp;a=' . $ent['entPhysical_id'] . "\'>', LEFT,FGCOLOR,'#e5e5e5', BGCOLOR, '#c0c0c0', BORDER, 5, CELLPAD, 4, CAPCOLOR, '#050505');\" onmouseout=\"return nd();\">";
                //echo "<span style='color: #000099;'>" . $sensor->sensor_class . ': ' . $sensor->sensor_descr . '</span>';
                echo ' ';
                echo Html::severityToLabel($sensor->currentStatus(), $sensor->formatValue());
                echo '</a>';
            }
        }

        // display entity state
        $entState = dbFetchRow(
            'SELECT * FROM `entityState` WHERE `device_id`=? && `entPhysical_id`=?',
            [$device['device_id'], $ent['entPhysical_id']]
        );

        if (! empty($entState)) {
            $display_states = [
                //                'entStateAdmin',
                'entStateOper',
                'entStateUsage',
                'entStateStandby',
            ];
            foreach ($display_states as $state_name) {
                $value = $entState[$state_name];
                $display = parse_entity_state($state_name, $value);
                echo " <span class='label label-{$display['color']}' data-toggle='tooltip' title='$state_name ($value)'>";
                echo $display['text'];
                echo '</span> ';
            }

            // ignore none and unavailable alarms
            if ($entState['entStateAlarm'] != '00' && $entState['entStateAlarm'] != '80') {
                $alarms = parse_entity_state_alarm($entState['entStateAlarm']);
                echo '<br />';
                echo "<span style='margin-left: 20px;'>Alarms: ";
                foreach ($alarms as $alarm) {
                    echo " <span class='label label-{$alarm['color']}'>{$alarm['text']}</span>";
                }
                echo '</span>';
            }
        }

        echo "<br /><div class='interface-desc' style='margin-left: 20px;'>" . e($ent['entPhysicalDescr']);

        if ($ent['entPhysicalAlias'] && $ent['entPhysicalAssetID']) {
            echo ' <br />Alias: ' . e($ent['entPhysicalAlias']) . ' - AssetID: ' . e($ent['entPhysicalAssetID']);
        } elseif ($ent['entPhysicalAlias']) {
            echo ' <br />Alias: ' . e($ent['entPhysicalAlias']);
        } elseif ($ent['entPhysicalAssetID']) {
            echo ' <br />AssetID: ' . e($ent['entPhysicalAssetID']);
        }

        if ($ent['entPhysicalSerialNum']) {
            echo " <br /><span class='text-info'>Serial No. " . e($ent['entPhysicalSerialNum']) . '</span> ';
        }

        // Display sensors values with their descr, as we have more than one attached to this entPhysical
        if ($sensors->count() > 1) {
            echo "<br>Sensors:<div class='interface-desc' style='margin-left: 20px;'>";
            foreach ($sensors as $sensor) {
                $disp_name = str_replace([$ent['entPhysicalDescr'], $ent['entPhysicalName']], ['', ''], $sensor->sensor_descr);
                echo "<a href='graphs/id=" . $sensor->sensor_id . '/type=sensor_' . $sensor->sensor_class . "/' onmouseover=\"return overlib('<img src=\'graph.php?id=" . $sensor->sensor_id . '&amp;type=sensor_' . $sensor->sensor_class . '&amp;from=-2d&amp;to=now&amp;width=400&amp;height=150&amp;a=' . $ent['entPhysical_id'] . "\'><img src=\'graph.php?id=" . $sensor->sensor_id . '&amp;type=sensor_' . $sensor->sensor_class . '&amp;from=-2w&amp;to=now&amp;width=400&amp;height=150&amp;a=' . $ent['entPhysical_id'] . "\'>', LEFT,FGCOLOR,'#e5e5e5', BGCOLOR, '#c0c0c0', BORDER, 5, CELLPAD, 4, CAPCOLOR, '#050505');\" onmouseout=\"return nd();\">";
                echo "<span class='text-info'>" . e($disp_name) . ' ' . e($sensor->sensor_class) . '</span>';
                echo ' ';
                echo Html::severityToLabel($sensor->currentStatus(), $sensor->formatValue());
                echo '</a><br>';
            }
            echo '</div>';
        }
        echo '</div>';

        if (EntPhysical::where('device_id', $device['device_id'])->where('entPhysicalContainedIn', $ent['entPhysicalIndex'])->exists()) {
            echo '<ul>';
            printEntPhysical($device, $ent['entPhysicalIndex'], $level + 1, 'liClosed');
            echo '</ul>';
        }

        echo '</li>';
    }//end foreach
}//end printEntPhysical()

$show_debug = \Illuminate\Support\Facades\Auth::user()->hasRole('admin');

echo "<div style='float: right;'>";
if ($show_debug) {
    echo "<button type='button' class='btn btn-default btn-sm' data-toggle='collapse' data-target='#entphysical-debug'><i class='fa fa-bug icon-theme' aria-hidden='true'></i> Debug</button> ";
}
echo "<a href='#' class='button' onClick=\"expandTree('enttree');return false;\"><i class='fa fa-plus fa-lg icon-theme'  aria-hidden='true'></i>Expand All Nodes</a>
       <a href='#' class='button' onClick=\"collapseTree('enttree');return false;\"><i class='fa fa-minus fa-lg icon-theme'  aria-hidden='true'></i>Collapse All Nodes</a>
     </div>";

if ($show_debug) {
    $debug_sensors = DeviceCache::getPrimary()->sensors()
        ->orderBy('sensor_class')
        ->orderBy('sensor_index')
        ->get();

    $debug_lmsensors_count = $debug_sensors->where('sensor_type', 'lmsensors')->count();

    $debug_ent = dbFetchRows(
        'SELECT entPhysical_id, entPhysicalIndex, entPhysicalContainedIn, entPhysicalParentRelPos, entPhysicalDescr, entPhysicalClass, entPhysicalName, entPhysicalHardwareRev, entPhysicalFirmwareRev, entPhysicalSoftwareRev, entPhysicalSerialNum, entPhysicalMfgName, entPhysicalModelName, entPhysicalAlias, entPhysicalAssetID, entPhysicalIsFRU, entPhysicalVendorType, ifIndex FROM `entPhysical` WHERE device_id = ? ORDER BY entPhysicalIndex',
        [$device['device_id']]
    );
    $debug_ent_map = array_column($debug_ent, null, 'entPhysicalIndex');

    $debug_entity_states = dbFetchRows(
        'SELECT * FROM `entityState` WHERE device_id = ? ORDER BY entPhysical_id',
        [$device['device_id']]
    );

    $debug_ports = dbFetchRows(
        'SELECT port_id, ifIndex, ifName, ifDescr, ifAlias FROM `ports` WHERE device_id = ? AND ifIndex IS NOT NULL ORDER BY ifIndex',
        [$device['device_id']]
    );
    $debug_port_map = array_column($debug_ports, null, 'ifIndex');

    echo "
<div style='clear: both;'>
  <div id='entphysical-debug' class='collapse' style='margin-top: 10px;'>
    <div class='panel panel-default'>
      <div class='panel-heading'><strong>Debug: entPhysical page data</strong> (" . $debug_sensors->count() . " sensors, $debug_lmsensors_count lmSensors, " . count($debug_ent) . " entities, " . count($debug_entity_states) . " states)</div>
      <div class='panel-body' style='overflow-x:auto;'>";

    if ($debug_sensors->isEmpty()) {
        echo "<p class='text-muted'>No sensors found. Run discovery to populate.</p>";
    } else {
        echo "<h4>Sensor linkage</h4>
        <table class='table table-condensed table-bordered table-hover' style='font-size:12px;'>
          <thead class='thead-default'><tr>
            <th>sensor_id</th>
            <th>sensor_class</th>
            <th>sensor_index</th>
            <th>sensor_type</th>
            <th>sensor_descr</th>
            <th>sensor_oid</th>
            <th>sensor_current</th>
            <th>divisor</th>
            <th>multiplier</th>
            <th>limit_low</th>
            <th>limit_low_warn</th>
            <th>limit_warn</th>
            <th>limit_high</th>
            <th>poller_type</th>
            <th>rrd_type</th>
            <th>group</th>
            <th>user_func</th>
            <th>entPhysicalIndex</th>
            <th>entPhysicalIndex_measured</th>
            <th>match</th>
            <th>entPhysicalDescr</th>
            <th>entPhysicalClass</th>
          </tr></thead><tbody>";
        foreach ($debug_sensors as $s) {
            $ent = $debug_ent_map[$s->entPhysicalIndex] ?? $debug_ent_map[$s->sensor_index] ?? null;
            $linked = $s->entPhysicalIndex !== null;
            $match = $linked ? 'entPhysicalIndex' : (isset($debug_ent_map[$s->sensor_index]) ? 'sensor_index fallback' : 'none');
            echo '<tr' . ($linked ? '' : " class='warning'") . '>'
                . '<td>' . e($s->sensor_id) . '</td>'
                . '<td>' . e($s->sensor_class) . '</td>'
                . '<td>' . e($s->sensor_index) . '</td>'
                . '<td>' . e($s->sensor_type) . '</td>'
                . '<td>' . e($s->sensor_descr) . '</td>'
                . '<td><code>' . e($s->sensor_oid) . '</code></td>'
                . '<td>' . e($s->sensor_current) . '</td>'
                . '<td>' . e($s->sensor_divisor) . '</td>'
                . '<td>' . e($s->sensor_multiplier) . '</td>'
                . '<td>' . e($s->sensor_limit_low ?? '-') . '</td>'
                . '<td>' . e($s->sensor_limit_low_warn ?? '-') . '</td>'
                . '<td>' . e($s->sensor_limit_warn ?? '-') . '</td>'
                . '<td>' . e($s->sensor_limit ?? '-') . '</td>'
                . '<td>' . e($s->poller_type) . '</td>'
                . '<td>' . e($s->rrd_type) . '</td>'
                . '<td>' . e($s->group ?? '-') . '</td>'
                . '<td>' . e($s->user_func ?? '-') . '</td>'
                . '<td>' . ($linked ? e($s->entPhysicalIndex) : "<span class='text-danger'>NULL</span>") . '</td>'
                . '<td>' . e($s->entPhysicalIndex_measured ?? '-') . '</td>'
                . '<td>' . e($match) . '</td>'
                . '<td>' . e($ent['entPhysicalDescr'] ?? '-') . '</td>'
                . '<td>' . e($ent['entPhysicalClass'] ?? '-') . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
        $unlinked = $debug_sensors->whereNull('entPhysicalIndex')->count();
        if ($unlinked > 0) {
            echo "<p class='text-warning'><i class='fa fa-warning'></i> $unlinked sensor(s) highlighted in yellow have no entPhysicalIndex. They can only display through the legacy sensor_index fallback.</p>";
        }
    }

    echo "<h4>entPhysical rows</h4>
      <table class='table table-condensed table-bordered table-hover' style='font-size:12px;'>
        <thead class='thead-default'><tr>
          <th>id</th>
          <th>index</th>
          <th>contained_in</th>
          <th>parent_rel_pos</th>
          <th>class</th>
          <th>name</th>
          <th>descr</th>
          <th>model</th>
          <th>vendor_type</th>
          <th>serial</th>
          <th>alias</th>
          <th>asset_id</th>
          <th>ifIndex</th>
          <th>port</th>
        </tr></thead><tbody>";
    foreach ($debug_ent as $ent) {
        $port = $debug_port_map[$ent['ifIndex']] ?? null;
        echo '<tr>'
            . '<td>' . e($ent['entPhysical_id']) . '</td>'
            . '<td>' . e($ent['entPhysicalIndex']) . '</td>'
            . '<td>' . e($ent['entPhysicalContainedIn']) . '</td>'
            . '<td>' . e($ent['entPhysicalParentRelPos']) . '</td>'
            . '<td>' . e($ent['entPhysicalClass']) . '</td>'
            . '<td>' . e($ent['entPhysicalName']) . '</td>'
            . '<td>' . e($ent['entPhysicalDescr']) . '</td>'
            . '<td>' . e($ent['entPhysicalModelName']) . '</td>'
            . '<td>' . e($ent['entPhysicalVendorType']) . '</td>'
            . '<td>' . e($ent['entPhysicalSerialNum']) . '</td>'
            . '<td>' . e($ent['entPhysicalAlias']) . '</td>'
            . '<td>' . e($ent['entPhysicalAssetID']) . '</td>'
            . '<td>' . e($ent['ifIndex'] ?? '-') . '</td>'
            . '<td>' . e($port['ifName'] ?? $port['ifDescr'] ?? '-') . '</td>'
            . '</tr>';
    }
    echo '</tbody></table>';

    echo "<h4>entityState rows</h4>";
    if (empty($debug_entity_states)) {
        echo "<p class='text-muted'>No entityState rows found.</p>";
    } else {
        echo "<table class='table table-condensed table-bordered table-hover' style='font-size:12px;'>
          <thead class='thead-default'><tr>
            <th>entPhysical_id</th>
            <th>entStateOper</th>
            <th>oper_display</th>
            <th>entStateUsage</th>
            <th>usage_display</th>
            <th>entStateStandby</th>
            <th>standby_display</th>
            <th>entStateAlarm</th>
            <th>alarm_display</th>
          </tr></thead><tbody>";
        foreach ($debug_entity_states as $state) {
            $oper = parse_entity_state('entStateOper', $state['entStateOper']);
            $usage = parse_entity_state('entStateUsage', $state['entStateUsage']);
            $standby = parse_entity_state('entStateStandby', $state['entStateStandby']);
            $alarms = parse_entity_state_alarm($state['entStateAlarm']);
            $alarm_display = implode(', ', array_column($alarms, 'text'));
            echo '<tr>'
                . '<td>' . e($state['entPhysical_id']) . '</td>'
                . '<td>' . e($state['entStateOper']) . '</td>'
                . '<td>' . e($oper['text']) . ' (' . e($oper['color']) . ')</td>'
                . '<td>' . e($state['entStateUsage']) . '</td>'
                . '<td>' . e($usage['text']) . ' (' . e($usage['color']) . ')</td>'
                . '<td>' . e($state['entStateStandby']) . '</td>'
                . '<td>' . e($standby['text']) . ' (' . e($standby['color']) . ')</td>'
                . '<td>' . e($state['entStateAlarm']) . '</td>'
                . '<td>' . e($alarm_display ?: '-') . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
    }

    echo "      </div>
    </div>
  </div>
</div>";
}

echo "<div style='clear: both;'><UL CLASS='mktree' id='enttree'>";
$level = '0';
$ent['entPhysicalIndex'] = '0';
printEntPhysical($device, $ent['entPhysicalIndex'], $level, 'liOpen');
echo '</ul></div>';

$pagetitle = 'Inventory';
