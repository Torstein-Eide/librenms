<?php

use LibreNMS\Exceptions\RrdGraphException;

/**
 * Return [unit_text, unitlen, addarea, datasets, ?scale_min, ?scale_max] for a v3 metric key.
 */
function mdadm_app_metric_config(string $metric): array
{
    return match ($metric) {
        'disk_counts' => ['Disk Count', 12, 0, [
            ['ds' => 'active',   'descr' => 'Active'],
            ['ds' => 'spare',    'descr' => 'Spare'],
            ['ds' => 'failed',   'descr' => 'Failed'],
            ['ds' => 'degraded', 'descr' => 'Degraded'],
        ], null, null],
        'sync_bps' => ['Bytes/s', 12, 1, [
            ['ds' => 'speed_bps', 'descr' => 'Bytes/s'],
        ], 0, null],
        'sync_pct' => ['Sync %', 8, 1, [
            ['ds' => 'completed_pct', 'descr' => 'Completed %'],
        ], 0, 100],
        default => throw new RrdGraphException('Unknown metric: ' . $metric),
    };
}

[$unit_text, $unitlen, $addarea, $datasets, $scale_min, $scale_max] = mdadm_app_metric_config($vars['metric'] ?? '');

$name = 'mdadm';
$bigdescrlen = 10;
$smalldescrlen = 10;
$colours = 'mixed';
$dostack = 0;
$printtotal = 0;
$transparency = 15;

if ($scale_min === null) {
    unset($scale_min);
}

if ($scale_max === null) {
    unset($scale_max);
}

$arrayParam = $vars['array'] ?? '';
$dbArray = $arrayParam !== ''
    ? App\Models\MdadmArray::where('app_id', $app->app_id)
        ->where(function ($q) use ($arrayParam): void {
            $q->where('uuid', $arrayParam)->orWhere('array_name', $arrayParam)->orWhere('md_id', $arrayParam);
        })
        ->first()
    : null;

// RRD files are keyed by the stable array UUID.
$rrd_filename = $dbArray !== null ? Rrd::name($device['hostname'], ['app', $name, $app->app_id, $dbArray->uuid]) : '';

$rrd_list = [];
if ($rrd_filename !== '' && Rrd::checkRrdExists($rrd_filename)) {
    foreach ($datasets as $spec) {
        $rrd_list[] = [
            'filename' => $rrd_filename,
            'descr' => $spec['descr'],
            'ds' => $spec['ds'],
        ];
    }
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
