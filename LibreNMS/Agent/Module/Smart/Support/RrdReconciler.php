<?php

namespace LibreNMS\Agent\Module\Smart\Support;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use LibreNMS\Agent\Module\Smart\Context;
use LibreNMS\Agent\Module\Smart\Helpers\DiskIdentity;
use LibreNMS\Data\Store\Rrd;

/**
 * RRD-dataset retrofit helpers shared by every disk-type pipeline (SATA,
 * NVMe, future SAS): adding a newly introduced DS to an RRD file created by
 * an older version of this module, without ever creating new files here.
 */
final class RrdReconciler
{
    /**
     * Reconcile an existing RRD file's datasets against a statically defined
     * set, adding whatever's missing. Used at discovery time to retrofit new
     * DS onto RRD files created by an older version of this module. For
     * example, power_state didn't exist as a DS before this was added, so disks
     * polled by a prior version have files missing it.
     *
     * Skipped if the file doesn't exist yet: a brand-new device gets every
     * currently-defined DS the first time poll() writes to it, since the
     * write path always builds its RrdDefinition from the same static set.
     *
     * @param array<int, array{name:string,type:string,heartbeat:int,min?:int|float|string|null,max?:int|float|string|null}> $datasets
     */
    public static function addDatasets(string $rrdFile, array $datasets): void
    {
        $rrd = app(Rrd::class);
        if (! $rrd->checkRrdExists($rrdFile)) {
            return;
        }
        $rrd->addDatasets($rrdFile, $datasets);
    }

    /**
     * Like addDatasets(), but for a config array keyed by dataset name (see
     * Rrd::addDatasetsFromConfig()) -- used where the dataset set is built up
     * incrementally per attribute, so keying by name naturally dedupes.
     *
     * @param array<string, array{type:string,heartbeat:int,min?:int|float|string|null,max?:int|float|string|null}> $config
     */
    public static function addDatasetsFromConfig(string $rrdFile, array $config): void
    {
        $rrd = app(Rrd::class);
        if (! $rrd->checkRrdExists($rrdFile)) {
            return;
        }
        $rrd->addDatasetsFromConfig($rrdFile, $config);
    }

    /** Statically defined DS that the per-device RRD families must always carry. */
    public static function commonDeviceRrdDatasets(): array
    {
        return [
            ['name' => 'power_state', 'type' => 'GAUGE', 'heartbeat' => LibrenmsConfig::get('rrd.heartbeat'), 'min' => 0, 'max' => 8],
        ];
    }

    /**
     * Retrofit commonDeviceRrdDatasets() onto every existing SATA and NVMe
     * per-disk RRD file.
     *
     * @param  array<int|string, array{disk_key: string}>  $sataDevices
     * @param  array<int|string, array{disk_key: string}>  $nvmeDevices
     */
    public static function reconcileCommonDeviceRrds(Context $ctx, array $sataDevices, array $nvmeDevices): void
    {
        $deviceModel = Device::find($ctx->deviceId);
        if ($deviceModel === null) {
            return;
        }

        $rrd = app(Rrd::class);
        $datasets = self::commonDeviceRrdDatasets();

        foreach ($sataDevices as $dev) {
            $idx = DiskIdentity::index($dev['disk_key']);
            self::addDatasets($rrd->name($deviceModel->hostname, ['app', 'smart', $ctx->appId, $idx]), $datasets);
        }

        foreach ($nvmeDevices as $dev) {
            $idx = DiskIdentity::index($dev['disk_key']);
            self::addDatasets($rrd->name($deviceModel->hostname, ['app', 'smart_nvme', $ctx->appId, $idx]), $datasets);
        }
    }
}
