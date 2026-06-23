<?php

namespace LibreNMS\Agent\Module\Smart\Support;

use Illuminate\Support\Facades\DB;

/**
 * Generic upsert/prune DB helpers used by every table-sync method across the
 * SMART discovery/poll pipeline (device table, SATA, NVMe, and any future
 * SAS handler). Pure functions of their arguments, no SMART-domain state.
 */
final class DbSync
{
    /**
     * Upsert into $table, deriving the update column list from the row(s)
     * automatically: every key except the identity columns ($uniqueBy plus
     * device_id, which never changes for an existing app_id+disk_key and so
     * is intentionally excluded from every update set in this module).
     *
     * Removes the need to hand-maintain a second column list alongside the
     * insert array -- previously, adding a column to the row but forgetting
     * to add it to the update list meant the column silently stopped
     * updating after the first insert.
     *
     * @param  array<string,mixed>|list<array<string,mixed>>  $rows  one row (associative), or a list of rows sharing the same column set
     * @param  array<int,string>  $uniqueBy
     */
    public static function upsert(string $table, array $rows, array $uniqueBy): void
    {
        if ($rows === []) {
            return;
        }

        $sample = array_is_list($rows) ? $rows[0] : $rows;
        $update = array_values(array_diff(array_keys($sample), $uniqueBy, ['device_id']));

        DB::table($table)->upsert($rows, $uniqueBy, $update);
    }

    /**
     * Delete rows for this app/disk whose key is no longer present, so a table
     * sync mirrors exactly the keys just walked. $extra adds further equality
     * constraints (used for nested tables keyed by page/error entry).
     *
     * @param array<int|string> $keepKeys     keys to retain (everything else is pruned)
     * @param array<string,mixed> $extra       additional column => value where clauses
     */
    public static function pruneStaleRows(string $table, int $appId, string $diskKey, string $keyCol, array $keepKeys, array $extra = []): void
    {
        $query = DB::table($table)
            ->where('app_id', $appId)
            ->where('disk_key', $diskKey);

        foreach ($extra as $col => $val) {
            $query->where($col, $val);
        }

        $query->whereNotIn($keyCol, $keepKeys)->delete();
    }
}
