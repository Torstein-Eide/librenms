<?php

namespace LibreNMS\Agent\Module\Smart;

use App\Models\StateTranslation;
use Illuminate\Support\Facades\DB;
use LibreNMS\Agent\Module\Smart\Helpers\DiskIdentity;
use LibreNMS\Agent\Module\Smart\Support\DbSync;
use LibreNMS\Util\SnmpDecode;
use SnmpQuery;

/**
 * Loads and caches the SMARTMON-COMMON-MIB device table for one discover()/
 * poll() run, deduping entries that describe the same physical drive, and
 * syncing them into smart_devices when the source table's LastChange stamp
 * moves. Shared by every disk-type pipeline (SATA, NVMe, future SAS).
 */
final class DeviceTable
{
    private const COMMON_MIBS = ['SMARTMON-TC-MIB', 'SMARTMON-COMMON-MIB'];

    private ?array $commonDevices = null;

    public function __construct(private readonly Context $ctx)
    {
    }

    /**
     * Load the common device table once, syncing smart_devices when the table's
     * LastChange timestamp differs from the stored one. Returns every device
     * keyed by snmp_index, regardless of type.
     */
    public function ensureCommonDevices(): array
    {
        if ($this->commonDevices !== null) {
            return $this->commonDevices;
        }

        $snmpTs = SnmpQuery::mibs(self::COMMON_MIBS)->mibDir('smart')->hideMib()
            ->get('SMARTMON-COMMON-MIB::smartmonDeviceTableLastChange.0')
            ->value('smartmonDeviceTableLastChange.0');

        $storedTs = DB::table('smart_app_state')
            ->where('app_id', $this->ctx->appId)
            ->value('device_table_last_change');

        $this->loadCommonDeviceTable();

        if ($snmpTs !== $storedTs) {
            $this->ctx->vlog("ensureCommonDevices: device table changed (snmp={$snmpTs}, stored={$storedTs}), syncing");
            $this->syncDeviceRows();
            DB::table('smart_app_state')
                ->where('app_id', $this->ctx->appId)
                ->update(['device_table_last_change' => $snmpTs]);
        } else {
            $this->ctx->vlog("ensureCommonDevices: device table unchanged (ts={$snmpTs})");
        }

        return $this->commonDevices;
    }

    /** Devices from the common device table whose device_type is in $types. */
    public function devicesOfTypes(array $types): array
    {
        $all = $this->ensureCommonDevices();

        $filtered = array_filter(
            $all,
            fn ($dev) => in_array($dev['device_type'] ?? 0, $types, true)
        );
        $this->ctx->vlog('devicesOfTypes: ' . count($filtered) . ' / ' . count($all) . ' total device(s) matched ' . json_encode($types));

        return $filtered;
    }

    /**
     * Devices of any of the given protocol types currently within their
     * missing-disk grace period, shaped like devicesOfTypes()'s per-device
     * array (so callers can merge the two device lists together) and
     * carrying missing_since -- the same field pollSkipReason() reads --
     * so callers use one consistent signal for "is this device missing"
     * whether they got the device from a live SNMP walk or from here.
     *
     * @return array<int, array<string, array<string, mixed>>> keyed by protocol_type, each a devicesOfTypes()-shaped list keyed by snmp_index
     */
    public function missingDevicesByType(array $protocolTypes): array
    {
        $rows = DB::table('smart_devices')
            ->where('app_id', $this->ctx->appId)
            ->whereIn('protocol_type', $protocolTypes)
            ->whereNotNull('missing_since')
            ->whereNotNull('snmp_index')
            ->get(['snmp_index', 'disk_key', 'device_name', 'model_name', 'serial_number', 'protocol_type', 'missing_since']);

        $devicesByType = array_fill_keys($protocolTypes, []);
        foreach ($rows as $row) {
            $devicesByType[(int) $row->protocol_type][(string) $row->snmp_index] = [
                'snmp_index'    => (string) $row->snmp_index,
                'disk_key'      => $row->disk_key,
                'device_name'   => $row->device_name,
                'model_name'    => $row->model_name,
                'serial_number' => $row->serial_number,
                'device_type'   => (int) $row->protocol_type,
                'missing_since' => $row->missing_since,
            ];
        }

        return $devicesByType;
    }

    /** Flip a missing/grace-period disk's Health sensor to Unavailable at poll time (updates an already-registered sensor's value only). */
    public function markMissingHealthPolled(array $dev, int $unavailableValue): void
    {
        $idx = DiskIdentity::index($dev['disk_key']);
        $this->ctx->updateSensorValues(["{$idx}_health" => (float) $unavailableValue], "app:smart_mib:{$idx}_");
    }

    /**
     * Register/refresh a missing/grace-period disk's Health sensor as
     * Unavailable at discovery time -- self-healing, since discoverSensor()
     * (unlike updateSensorValues()) (re)creates the sensor row if it's gone.
     *
     * @param  array<int, StateTranslation>  $translations
     */
    public function markMissingHealthDiscovered(array $dev, string $sensorType, int $unavailableValue, array $translations): void
    {
        $diskKey = $dev['disk_key'];
        $devName = DiskIdentity::label($dev, $dev['snmp_index'] ?? $diskKey);
        $idx = DiskIdentity::index($diskKey);

        $this->ctx->discoverSensor(
            class: 'state',
            type: $sensorType,
            index: "{$idx}_health",
            oid: "app:smart_mib:{$idx}_health",
            descr: "SMART {$devName} Health",
            current: $unavailableValue,
            group: 'SMART',
        )->withStateTranslations($sensorType, $translations);
    }

    /** Load devices of the given protocol types from DB, keyed by snmp_index (no SNMP walk). */
    public function devicesFromDb(array $protocolTypes): array
    {
        $rows = DB::table('smart_devices')
            ->where('app_id', $this->ctx->appId)
            ->whereIn('protocol_type', $protocolTypes)
            ->whereNotNull('snmp_index')
            ->get(['snmp_index', 'disk_key', 'power_state', 'missing_since']);

        $devices = [];
        foreach ($rows as $row) {
            $devices[(string) $row->snmp_index] = [
                'disk_key'      => $row->disk_key,
                'power_state'   => $row->power_state !== null ? (int) $row->power_state : null,
                'missing_since' => $row->missing_since,
            ];
        }

        return $devices;
    }

    /**
     * Whether polling should skip this device this cycle, and why:
     *  - 'missing': gone from the SNMP device table (still within its grace
     *    period) -- caller should flip its health sensor to Unavailable.
     *  - 'idle':    present but in a low-power/sleeping tier this agent
     *    didn't wake this cycle -- caller should leave its sensors untouched.
     *  - null:      poll normally.
     */
    public function pollSkipReason(array $dev): ?string
    {
        if (! empty($dev['missing_since'])) {
            return 'missing';
        }

        $state = $dev['power_state'] ?? null;
        // idleA(2)..standby(8): anything beyond active(1)/unknown(0).
        if (is_numeric($state) && (int) $state >= 2) {
            return 'idle';
        }

        return null;
    }

    private function loadCommonDeviceTable(): void
    {
        $metaTable = SnmpQuery::mibs(self::COMMON_MIBS)->mibDir('smart')
            ->hideMib()
            ->walk('SMARTMON-COMMON-MIB::smartmonDeviceMetadataTable')
            ->table(1);
        $statusTable = SnmpQuery::mibs(self::COMMON_MIBS)->mibDir('smart')
            ->hideMib()
            ->walk('SMARTMON-COMMON-MIB::smartmonDeviceStatusTable')
            ->table(1);

        $this->commonDevices = [];
        foreach ($metaTable as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $statusRow = $statusTable[$index] ?? [];
            $this->commonDevices[(string) $index] = [
                'snmp_index'           => (string) $index,
                'disk_key'             => $this->diskKey($row, (string) $index),
                'device_name'          => $row['smartmonDeviceName'] ?? null,
                'device_path'          => $row['smartmonDevicePath'] ?? null,
                'device_type'          => (int) ($row['smartmonDeviceType'] ?? null),
                'last_poll_time'       => $statusRow['smartmonDeviceLastPollTime'] ?? null,
                'last_poll_result'     => (int) ($statusRow['smartmonDeviceLastPollResult'] ?? null),
                'last_poll_exit_status'=> (int) ($statusRow['smartmonDeviceLastPollExitStatus'] ?? null),
                'power_state'          => (int) ($statusRow['smartmonDevicePowerState'] ?? null),
                'physical_index'       => (int) ($row['smartmonDevicePhysicalIndex'] ?? null),
                'uris'                 => $row['smartmonDeviceUris'] ?? null,
                'model_family'         => $row['smartmonDeviceModelFamily'] ?? null,
                'model_name'           => $row['smartmonDeviceModelName'] ?? null,
                'serial_number'        => $row['smartmonDeviceSerialNumber'] ?? null,
                'firmware_version'     => $row['smartmonDeviceFirmwareVersion'] ?? null,
                'wwn'                  => $row['smartmonDeviceWwn'] ?? null,
            ];
        }

        $this->dedupeCommonDevices();
    }

    /**
     * Collapse device-table entries that describe the same physical drive.
     *
     * A drive enumerated via two transports can appear twice. For example, one
     * path reports a WWN and the other only a serial, yielding two different
     * disk_keys. Entries sharing any non-empty WWN or serial are treated as one
     * logical drive; the most complete entry (WWN-bearing, then lowest
     * snmp_index) is kept as canonical and the rest are dropped so only a single
     * row is discovered, stored, and shown.
     */
    private function dedupeCommonDevices(): void
    {
        if ($this->commonDevices === null || count($this->commonDevices) < 2) {
            return;
        }

        // Order so the most complete identity wins as canonical: WWN-bearing
        // first, then by numeric snmp_index for stable, deterministic results.
        $ordered = $this->commonDevices;
        uksort($ordered, function ($a, $b) use ($ordered) {
            $aw = trim((string) ($ordered[$a]['wwn'] ?? '')) !== '' ? 0 : 1;
            $bw = trim((string) ($ordered[$b]['wwn'] ?? '')) !== '' ? 0 : 1;

            return $aw <=> $bw ?: (int) $a <=> (int) $b;
        });

        $seen = [];   // identity value => canonical snmp_index
        $kept = [];
        foreach ($ordered as $idx => $dev) {
            $identities = array_filter([
                trim((string) ($dev['wwn'] ?? '')),
                trim((string) ($dev['serial_number'] ?? '')),
            ], static fn ($v) => $v !== '');

            $canonical = null;
            foreach ($identities as $id) {
                if (isset($seen[$id])) {
                    $canonical = $seen[$id];
                    break;
                }
            }
            if ($canonical !== null) {
                $this->ctx->vlog("dedupeCommonDevices: snmp_index={$idx} (disk_key={$dev['disk_key']}) is a duplicate of snmp_index={$canonical}, dropped");
                continue;
            }
            foreach ($identities as $id) {
                $seen[$id] = $idx;
            }
            $kept[(string) $idx] = $dev;
        }

        $dropped = count($this->commonDevices) - count($kept);
        if ($dropped > 0) {
            $this->ctx->vlog("dedupeCommonDevices: collapsed {$dropped} duplicate device entry/entries");
        }
        $this->commonDevices = $kept;
    }

    /** Upsert all discovered devices into smart_devices. */
    private function syncDeviceRows(): void
    {
        $this->ctx->vlog('syncDeviceRows: upserting ' . count($this->commonDevices) . ' device(s)');
        foreach ($this->commonDevices as $snmpIndex => $dev) {
            DbSync::upsert('smart_devices', [
                'app_id'           => $this->ctx->appId,
                'device_id'        => $this->ctx->deviceId,
                'disk_key'         => $dev['disk_key'],
                'snmp_index'       => (int) $snmpIndex,
                'device_name'      => $dev['device_name'],
                'device_path'      => $dev['device_path'],
                'protocol_type'    => $dev['device_type'],
                'model_family'     => $dev['model_family'],
                'model_name'       => $dev['model_name'],
                'serial_number'    => $dev['serial_number'],
                'firmware_version' => $dev['firmware_version'],
                'wwn'              => $dev['wwn'],
                'last_poll_time'   => SnmpDecode::parseDateAndTime($dev['last_poll_time']),
                'last_poll_result' => $dev['last_poll_result'],
                'last_poll_exit'   => $dev['last_poll_exit_status'],
                'power_state'      => $dev['power_state'],
                'physical_index'   => $dev['physical_index'] ?? 0,
                'uris'             => $dev['uris'],
            ], ['app_id', 'disk_key']);
        }
    }

    private function diskKey(array $row, string $fallback): string
    {
        $wwn = trim((string) ($row['smartmonDeviceWwn'] ?? ''));
        if ($wwn !== '') {
            return $wwn;
        }

        $model = trim((string) ($row['smartmonDeviceModelName'] ?? ''));
        $serial = trim((string) ($row['smartmonDeviceSerialNumber'] ?? ''));
        if ($model !== '' || $serial !== '') {
            return $model . '+' . $serial;
        }

        return $fallback;
    }
}
