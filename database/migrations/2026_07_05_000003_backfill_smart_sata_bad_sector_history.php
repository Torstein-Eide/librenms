<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time seed of smart_sata_bad_sector_history from whatever's currently
 * in smart_sata_pending_defects. Needed because, going forward, history rows
 * are only written by SataHandler::recordBadSectorHistory() when the SMART
 * agent's own change-counter says the pending-defects table changed since
 * the last poll (see SataHandler::walkAndSyncSataTable()) -- so pre-existing
 * pending-defect rows from before this table existed would otherwise never
 * appear in history until their underlying LBA set happens to change again.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        DB::table('smart_sata_pending_defects')
            ->whereNotNull('lba')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($now): void {
                $insert = [];
                foreach ($rows as $row) {
                    $insert[] = [
                        'app_id'     => $row->app_id,
                        'device_id'  => $row->device_id,
                        'disk_key'   => $row->disk_key,
                        'lba'        => $row->lba,
                        'first_seen' => $now,
                        'last_seen'  => $now,
                        'cleared_at' => null,
                    ];
                }
                if ($insert !== []) {
                    DB::table('smart_sata_bad_sector_history')->insertOrIgnore($insert);
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * No-op: this migration only copies data that already exists elsewhere
     * (smart_sata_pending_defects); rolling it back would discard bad-sector
     * history that may have accumulated its own state since the backfill ran.
     */
    public function down(): void
    {
    }
};
