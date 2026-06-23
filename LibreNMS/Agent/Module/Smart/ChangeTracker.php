<?php

namespace LibreNMS\Agent\Module\Smart;

use Illuminate\Support\Facades\DB;
use LibreNMS\Agent\Module\Smart\Support\DbSync;
use SnmpQuery;

/**
 * SATA change-detection: smartSATAChangeByDeviceTable/smartSATAChangeBySubindexTable
 * exist only in SMARTMON-SATA-MIB (NVMe has no equivalent change table, so its
 * pipeline walks every table unconditionally instead). Kept as its own
 * injectable collaborator -- owned by SataHandler, not hard-wired into a
 * shared base -- so a hypothetical future SAS change-table could reuse the
 * same shape by composition without subclassing.
 */
final class ChangeTracker
{
    private const SATA_MIBS = ['SMARTMON-TC-MIB', 'SMARTMON-COMMON-MIB', 'SMARTMON-SATA-MIB'];

    // smartSATAChangeByDeviceTable IDs used for change-detection guards (matches
    // sata_table_meta_for() in the agentx). The full ID space, including the
    // tables that aren't change-gated (info=1, attr=3, errorCmd=5), is in
    // TID_NAMES below; only the IDs referenced as change guards are named here.
    public const TID_HEALTH = 2;
    public const TID_ERROR_LOG = 4;
    public const TID_SELFTEST = 6;
    public const TID_ERC = 7;
    public const TID_PHY_EVENT = 8;
    public const TID_SELECTIVE_TEST = 9;
    public const TID_LOG_DIR = 10;
    public const TID_DEV_STAT = 11;
    public const TID_PENDING_DEFECTS = 12;

    // SNMP returns enumerated table IDs as named strings (e.g. "sataInfo") when MIBs are loaded.
    // Map them to the integer constants so change-detection lookups work correctly.
    private const TID_NAMES = [
        'sataInfo'           => 1,
        'sataHealth'         => 2,
        'sataAttr'           => 3,
        'sataErrorLog'       => 4,
        'sataErrorCmd'       => 5,
        'sataSelfTest'       => 6,
        'sataErc'            => 7,
        'sataPhyEvent'       => 8,
        'sataSelectiveTest'  => 9,
        'sataLogDir'         => 10,
        'sataDevStat'        => 11,
        'sataPendingDefects' => 12,
    ];

    private ?array $changeRows = null;
    private ?array $subindexChangeRows = null;
    private ?array $prevSnapshot = null;

    public function __construct(private readonly Context $ctx)
    {
    }

    /** Load (and memoize) the current change-table state plus the previously persisted snapshot. */
    public function load(): void
    {
        if ($this->changeRows !== null) {
            return;
        }
        $this->prevSnapshot = $this->loadStoredSnapshot();

        // table(2) puts the column name at depth 3 ([devIdx][tableId][colName]),
        // so walk the full table and extract the lastChange column explicitly.
        $this->changeRows = [];
        foreach ($this->walkSataTable('smartSATAChangeByDeviceTable', 2) as $devIdx => $tableRows) {
            foreach ($tableRows as $tableId => $row) {
                if (! is_array($row)) {
                    continue;
                }
                // SNMP returns named enum strings ("sataInfo") when MIBs are loaded; normalize to int.
                $tid = self::TID_NAMES[(string) $tableId] ?? (is_numeric($tableId) ? (int) $tableId : null);
                if ($tid === null) {
                    continue;
                }
                $this->changeRows[(string) $devIdx][(string) $tid] =
                    $row['smartSATAChangeByDeviceLastChange'] ?? null;
            }
        }

        $this->subindexChangeRows = [];
        foreach ($this->walkSataTable('smartSATAChangeBySubindexTable', 3) as $devIdx => $tableRows) {
            if (! is_array($tableRows)) {
                continue;
            }
            foreach ($tableRows as $tableId => $subindexes) {
                if (! is_array($subindexes)) {
                    continue;
                }
                $tid = self::TID_NAMES[(string) $tableId] ?? (is_numeric($tableId) ? (int) $tableId : null);
                if ($tid === null) {
                    continue;
                }
                foreach ($subindexes as $subindex => $row) {
                    if (is_array($row)) {
                        $this->subindexChangeRows[(string) $devIdx][(string) $tid][(string) $subindex] =
                            $row['smartSATAChangeBySubindexLastChange'] ?? null;
                    }
                }
            }
        }

        $this->ctx->vlog('ChangeTracker::load: loaded ' . count($this->changeRows) . ' device change row(s), prev snapshot ' . ($this->prevSnapshot !== null ? 'present' : 'absent'));
    }

    public function tableChangedForDevice(string $devIdx, int $tableId): bool
    {
        $current = $this->changeRows[$devIdx][$tableId] ?? null;
        $prev = $this->prevSnapshot !== null ? ($this->prevSnapshot[$devIdx][$tableId][0] ?? null) : null;

        return $current !== $prev;
    }

    public function tableChangedForDevicePage(string $devIdx, int $tableId, int $subindex): bool
    {
        $current = $this->subindexChangeRows[$devIdx][$tableId][$subindex] ?? null;
        $prev = $this->prevSnapshot !== null ? ($this->prevSnapshot[$devIdx][$tableId][$subindex] ?? null) : null;

        return $current !== $prev;
    }

    public function anyDeviceChangedForTable(int $tableId): bool
    {
        foreach (array_keys($this->changeRows ?? []) as $devIdx) {
            if ($this->tableChangedForDevice((string) $devIdx, $tableId)) {
                return true;
            }
        }

        return false;
    }

    /** Persist the currently loaded change snapshot for the next cycle's change detection. */
    public function persist(): void
    {
        $this->load();
        $upsertRows = [];

        foreach ($this->changeRows as $devIdx => $tables) {
            foreach ($tables as $tableId => $ts) {
                if ($ts !== null) {
                    $upsertRows[] = [
                        'app_id'      => $this->ctx->appId,
                        'device_idx'  => (int) $devIdx,
                        'table_id'    => (int) $tableId,
                        'subindex'    => 0,
                        'last_change' => $ts,
                    ];
                }
            }
        }

        foreach ($this->subindexChangeRows ?? [] as $devIdx => $tables) {
            foreach ($tables as $tableId => $subindexes) {
                foreach ($subindexes as $subindex => $ts) {
                    if ($ts !== null) {
                        $upsertRows[] = [
                            'app_id'      => $this->ctx->appId,
                            'device_idx'  => (int) $devIdx,
                            'table_id'    => (int) $tableId,
                            'subindex'    => (int) $subindex,
                            'last_change' => $ts,
                        ];
                    }
                }
            }
        }

        $this->ctx->vlog('ChangeTracker::persist: upserting ' . count($upsertRows) . ' change row(s)');
        if (! empty($upsertRows)) {
            DbSync::upsert('smart_sata_change', $upsertRows, ['app_id', 'device_idx', 'table_id', 'subindex']);
        }
    }

    private function loadStoredSnapshot(): ?array
    {
        $rows = DB::table('smart_sata_change')
            ->where('app_id', $this->ctx->appId)
            ->get(['device_idx', 'table_id', 'subindex', 'last_change']);

        if ($rows->isEmpty()) {
            return null;
        }

        // Structure: [devIdx][tableId][subindex] => last_change
        // subindex = 0 for device-level rows; subindex = pageNum/errorIdx for subindex rows.
        $snapshot = [];
        foreach ($rows as $row) {
            $snapshot[$row->device_idx][$row->table_id][$row->subindex] = $row->last_change;
        }

        return $snapshot;
    }

    private function walkSataTable(string $table, int $group): array
    {
        return SnmpQuery::mibs(self::SATA_MIBS)->hideMib()->walk("SMARTMON-SATA-MIB::$table")->table($group);
    }
}
