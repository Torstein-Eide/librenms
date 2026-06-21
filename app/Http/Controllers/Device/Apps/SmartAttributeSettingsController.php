<?php

namespace App\Http\Controllers\Device\Apps;

use App\Models\Application;
use App\Models\Device;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LibreNMS\Agent\Unix\Smart\HtmlData;

/**
 * Per-attribute rate-of-change warning thresholds for the SMART app's SATA
 * attributes: a global default per attribute_id, optionally overridden per
 * disk, editable here with filtering, mass-update of selected rows, and
 * copying one disk's thresholds to every other disk on the device.
 */
class SmartAttributeSettingsController
{
    use AuthorizesRequests;

    public function index(Device $device, Request $request): View
    {
        $this->authorize('update', $device);

        require_once base_path('includes/html/functions.inc.php');

        $app = Application::where('device_id', $device->device_id)->where('app_type', 'smart')->first();
        $appId = $app?->app_id;

        // Rate-of-change thresholds only make sense for the newly-detected
        // ("Count" in the name) COUNTER attributes — these are failure-style
        // counters (e.g. Reallocated_Event_Count). GAUGE attributes have no
        // rate semantics, and the legacy fixed-list counters (Common::
        // ATA_COUNTER_ATTRS — Total_LBAs_Written, NAND_Writes, etc.) are
        // workload/wear statistics that grow during normal use, not failure
        // indicators, so both are excluded from this settings page.
        $rows = $appId === null ? collect() : DB::table('smart_sata_attributes')
            ->where('app_id', $appId)
            ->where('rrd_type', 'COUNTER')
            ->whereRaw('LOWER(name) LIKE ?', ['%count%'])
            ->select('disk_key', 'attribute_id', 'name', 'rate_8h', 'rate_24h', 'rate_168h', 'rate_672h')
            ->distinct()
            ->orderBy('disk_key')
            ->orderBy('attribute_id')
            ->get();

        $rowDiskKeys = $rows->pluck('disk_key')->unique()->values()->all();

        [$overrides, $globals] = $this->loadThresholds($appId, $rowDiskKeys);

        $items = $rows->map(function ($row) use ($overrides, $globals) {
            $override = $overrides[$row->disk_key][$row->attribute_id] ?? null;
            $global = $globals[$row->attribute_id] ?? null;
            $effective = $override ?? $global;

            return [
                'disk_key' => $row->disk_key,
                'attribute_id' => $row->attribute_id,
                'name' => $row->name,
                'is_override' => $override !== null,
                'rate_8h' => $row->rate_8h,
                'rate_24h' => $row->rate_24h,
                'rate_168h' => $row->rate_168h,
                'rate_672h' => $row->rate_672h,
                'warn_rate_8h' => $effective->warn_rate_8h ?? null,
                'warn_rate_24h' => $effective->warn_rate_24h ?? null,
                'warn_rate_168h' => $effective->warn_rate_168h ?? null,
                'warn_rate_672h' => $effective->warn_rate_672h ?? null,
            ];
        })->values();

        $itemsByDisk = $items->groupBy('disk_key');

        // Disk order + display label match the same tabs used in the SMART
        // app's own heading (smart.blade.php), including the label-mode cookie.
        $diskKeys = $rowDiskKeys;
        $diskLabels = array_combine($rowDiskKeys, $rowDiskKeys);
        if ($app !== null) {
            $htmlData = HtmlData::forDevice($app, $device->toArray());
            $labelCookie = 'smart_label_mode_' . $device->device_id;
            $labelModes = $htmlData->labelModes();
            $labelMode = $request->cookie($labelCookie) !== null && isset($labelModes[$request->cookie($labelCookie)])
                ? $request->cookie($labelCookie)
                : 'device';

            $diskKeys = array_values(array_intersect($htmlData->diskKeys(), $rowDiskKeys));
            $diskLabels = [];
            foreach ($diskKeys as $diskKey) {
                $disk = $htmlData->disk($diskKey);
                $diskLabels[$diskKey] = $disk !== null ? $htmlData->displayLabel($disk, $labelMode) : $diskKey;
            }
        }

        return view('device.apps.smart.settings', [
            'device' => $device,
            'appId' => $appId,
            'diskKeys' => $diskKeys,
            'diskLabels' => $diskLabels,
            'itemsByDisk' => $itemsByDisk,
        ]);
    }

    public function update(Device $device, Request $request): JsonResponse
    {
        $this->authorize('update', $device);

        $validated = $request->validate([
            'app_id' => 'required|integer',
            'scope' => 'required|in:disk,global',
            'rows' => 'required|array|min:1',
            'rows.*.disk_key' => 'required|string',
            'rows.*.attribute_id' => 'required|integer',
            'warn_rate_8h' => 'nullable|numeric',
            'warn_rate_24h' => 'nullable|numeric',
            'warn_rate_168h' => 'nullable|numeric',
            'warn_rate_672h' => 'nullable|numeric',
        ]);

        $appId = $this->ownedAppId($device, (int) $validated['app_id']);
        if ($appId === null) {
            return response()->json(['status' => 'error', 'message' => 'Unknown app'], 404);
        }

        $values = [
            'warn_rate_8h' => $validated['warn_rate_8h'] ?? null,
            'warn_rate_24h' => $validated['warn_rate_24h'] ?? null,
            'warn_rate_168h' => $validated['warn_rate_168h'] ?? null,
            'warn_rate_672h' => $validated['warn_rate_672h'] ?? null,
        ];

        // Global scope: one row per distinct attribute_id, not tied to a disk.
        $attributeIds = $validated['scope'] === 'global'
            ? array_unique(array_column($validated['rows'], 'attribute_id'))
            : null;

        if ($attributeIds !== null) {
            foreach ($attributeIds as $attributeId) {
                $this->upsertThreshold(0, '', (int) $attributeId, $values);
            }
        } else {
            foreach ($validated['rows'] as $row) {
                $this->upsertThreshold($appId, (string) $row['disk_key'], (int) $row['attribute_id'], $values);
            }
        }

        return response()->json(['status' => 'ok', 'message' => 'Thresholds updated']);
    }

    /**
     * Reset selected rows to "default": deletes the threshold row outright
     * rather than setting its columns to null. Setting columns to null via
     * update() still leaves a per-disk override row in place, which keeps
     * winning over the global default (it's just an override with no active
     * limits) — deleting the row is what actually makes a disk fall back to
     * inheriting the global default again. For scope=global this deletes the
     * global default itself, so nothing is configured for that attribute at all.
     */
    public function reset(Device $device, Request $request): JsonResponse
    {
        $this->authorize('update', $device);

        $validated = $request->validate([
            'app_id' => 'required|integer',
            'scope' => 'required|in:disk,global',
            'rows' => 'required|array|min:1',
            'rows.*.disk_key' => 'required|string',
            'rows.*.attribute_id' => 'required|integer',
        ]);

        $appId = $this->ownedAppId($device, (int) $validated['app_id']);
        if ($appId === null) {
            return response()->json(['status' => 'error', 'message' => 'Unknown app'], 404);
        }

        $deleted = 0;
        if ($validated['scope'] === 'global') {
            $attributeIds = array_unique(array_column($validated['rows'], 'attribute_id'));
            $deleted = DB::table('smart_attribute_thresholds')
                ->where('app_id', 0)->where('disk_key', '')
                ->whereIn('attribute_id', $attributeIds)
                ->delete();
        } else {
            foreach ($validated['rows'] as $row) {
                $deleted += DB::table('smart_attribute_thresholds')
                    ->where('app_id', $appId)
                    ->where('disk_key', (string) $row['disk_key'])
                    ->where('attribute_id', (int) $row['attribute_id'])
                    ->delete();
            }
        }

        return response()->json(['status' => 'ok', 'message' => "Reset {$deleted} row(s) to default"]);
    }

    public function copyToAllDisks(Device $device, Request $request): JsonResponse
    {
        $this->authorize('update', $device);

        $validated = $request->validate([
            'app_id' => 'required|integer',
            'source_disk_key' => 'required|string',
        ]);

        $appId = $this->ownedAppId($device, (int) $validated['app_id']);
        if ($appId === null) {
            return response()->json(['status' => 'error', 'message' => 'Unknown app'], 404);
        }

        $sourceDiskKey = $validated['source_disk_key'];

        $sourceRows = DB::table('smart_attribute_thresholds')
            ->where('app_id', $appId)
            ->where('disk_key', $sourceDiskKey)
            ->get();

        if ($sourceRows->isEmpty()) {
            return response()->json(['status' => 'info', 'message' => 'Source disk has no configured thresholds']);
        }

        $diskKeys = DB::table('smart_sata_attributes')
            ->where('app_id', $appId)
            ->where('disk_key', '!=', $sourceDiskKey)
            ->distinct()
            ->pluck('disk_key');

        foreach ($diskKeys as $diskKey) {
            foreach ($sourceRows as $sourceRow) {
                $this->upsertThreshold($appId, $diskKey, (int) $sourceRow->attribute_id, [
                    'warn_rate_8h' => $sourceRow->warn_rate_8h,
                    'warn_rate_24h' => $sourceRow->warn_rate_24h,
                    'warn_rate_168h' => $sourceRow->warn_rate_168h,
                    'warn_rate_672h' => $sourceRow->warn_rate_672h,
                ]);
            }
        }

        return response()->json(['status' => 'ok', 'message' => 'Copied to ' . $diskKeys->count() . ' disk(s)']);
    }

    /** Confirm $requestedAppId is actually this device's smart app, returning it or null. */
    private function ownedAppId(Device $device, int $requestedAppId): ?int
    {
        $exists = Application::where('app_id', $requestedAppId)
            ->where('device_id', $device->device_id)
            ->where('app_type', 'smart')
            ->exists();

        return $exists ? $requestedAppId : null;
    }

    private function upsertThreshold(int $appId, string $diskKey, int $attributeId, array $values): void
    {
        DB::table('smart_attribute_thresholds')->upsert([
            'app_id' => $appId,
            'disk_key' => $diskKey,
            'attribute_id' => $attributeId,
            ...$values,
        ], ['app_id', 'disk_key', 'attribute_id'], array_keys($values));
    }

    /**
     * @param  array<string>  $diskKeys
     * @return array{0: array<string, array<int, object>>, 1: array<int, object>}
     */
    private function loadThresholds(?int $appId, array $diskKeys): array
    {
        if ($appId === null) {
            return [[], []];
        }

        $rows = DB::table('smart_attribute_thresholds')
            ->where(function ($q) use ($appId, $diskKeys) {
                $q->where('app_id', $appId)->whereIn('disk_key', $diskKeys);
            })
            ->orWhere(function ($q) {
                $q->where('app_id', 0)->where('disk_key', '');
            })
            ->get();

        $overrides = [];
        $globals = [];
        foreach ($rows as $row) {
            if ((int) $row->app_id === 0 && (string) $row->disk_key === '') {
                $globals[(int) $row->attribute_id] = $row;
            } else {
                $overrides[$row->disk_key][(int) $row->attribute_id] = $row;
            }
        }

        return [$overrides, $globals];
    }
}
