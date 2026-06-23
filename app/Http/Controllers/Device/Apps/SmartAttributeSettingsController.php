<?php

namespace App\Http\Controllers\Device\Apps;

use App\Models\Application;
use App\Models\Device;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LibreNMS\Agent\Module\Smart\Support\DbSync;
use LibreNMS\Agent\Unix\Smart\HtmlData;

/**
 * Per-attribute rate-of-change warning thresholds for the SMART app's SATA
 * attributes: a global default per attribute_id, optionally overridden per
 * disk. Each row is edited inline (save-on-change, no submit button), with
 * per-row controls to mute/unmute alerting, reset a disk override back to
 * the global default, or copy the global default's values down into a disk
 * override, mirroring the device health-sensors edit page.
 */
class SmartAttributeSettingsController
{
    use AuthorizesRequests;

    private const WARN_FIELDS = ['warn_rate_8h', 'warn_rate_24h', 'warn_rate_168h', 'warn_rate_672h'];

    /** Sentinel disk_key for the synthetic "Global Defaults" tab. */
    private const GLOBAL_DISK_KEY = '';

    /** Sentinel app_id for the global naming-template default, shared across every device. */
    private const GLOBAL_SETTINGS_APP_ID = 0;

    /** Placeholder variables accepted in a naming template; anything else is rejected. */
    private const NAMING_TEMPLATE_VARS = ['device', 'model', 'serial', 'wwn', 'model_family'];

    public function index(Device $device, Request $request): View
    {
        $this->authorize('update', $device);

        require_once base_path('includes/html/functions.inc.php');

        $app = Application::where('device_id', $device->device_id)->where('app_type', 'smart')->first();
        $appId = $app?->app_id;

        // Rate-of-change thresholds only make sense for the newly-detected
        // ("Count" in the name) COUNTER attributes. These are failure-style
        // counters (e.g. Reallocated_Event_Count). GAUGE attributes have no
        // rate semantics, and the legacy fixed-list counters (Common::
        // ATA_COUNTER_ATTRS: Total_LBAs_Written, NAND_Writes, etc.) are
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
                'has_row' => $override !== null,
                'alert_enabled' => (bool) (($effective->alert_enabled ?? null) ?? true),
                'rate_8h' => $row->rate_8h,
                'rate_24h' => $row->rate_24h,
                'rate_168h' => $row->rate_168h,
                'rate_672h' => $row->rate_672h,
                'warn_rate_8h' => $effective->warn_rate_8h ?? null,
                'warn_rate_24h' => $effective->warn_rate_24h ?? null,
                'warn_rate_168h' => $effective->warn_rate_168h ?? null,
                'warn_rate_672h' => $effective->warn_rate_672h ?? null,
                'default_warn_rate_8h' => $global->warn_rate_8h ?? null,
                'default_warn_rate_24h' => $global->warn_rate_24h ?? null,
                'default_warn_rate_168h' => $global->warn_rate_168h ?? null,
                'default_warn_rate_672h' => $global->warn_rate_672h ?? null,
            ];
        })->values();

        $itemsByDisk = $items->groupBy('disk_key')->all();

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

        // Synthetic "Global Defaults" tab: one row per attribute_id (named from
        // whichever disk row first carried that attribute), editing the
        // app_id=0/disk_key='' global-default row directly.
        $attributeNames = $rows->unique('attribute_id')->pluck('name', 'attribute_id');
        $defaultItems = $attributeNames->map(function ($name, $attributeId) use ($globals) {
            $global = $globals[$attributeId] ?? null;

            return [
                'disk_key' => self::GLOBAL_DISK_KEY,
                'attribute_id' => $attributeId,
                'name' => $name,
                'is_override' => false,
                'has_row' => $global !== null,
                'alert_enabled' => (bool) (($global->alert_enabled ?? null) ?? true),
                'rate_8h' => null,
                'rate_24h' => null,
                'rate_168h' => null,
                'rate_672h' => null,
                'warn_rate_8h' => $global->warn_rate_8h ?? null,
                'warn_rate_24h' => $global->warn_rate_24h ?? null,
                'warn_rate_168h' => $global->warn_rate_168h ?? null,
                'warn_rate_672h' => $global->warn_rate_672h ?? null,
            ];
        })->values()->sortBy('attribute_id')->values();

        if ($defaultItems->isNotEmpty()) {
            $diskKeys[] = self::GLOBAL_DISK_KEY;
            $diskLabels[self::GLOBAL_DISK_KEY] = __('Global Defaults');
            $itemsByDisk[self::GLOBAL_DISK_KEY] = $defaultItems;
        }

        return view('device.apps.smart.settings', [
            'device' => $device,
            'appId' => $appId,
            'diskKeys' => $diskKeys,
            'diskLabels' => $diskLabels,
            'itemsByDisk' => $itemsByDisk,
        ]);
    }

    /** Dedicated sub-page: device-wide + per-disk naming templates, and the default disk-view mode. */
    public function naming(Device $device, Request $request): View
    {
        $this->authorize('update', $device);

        require_once base_path('includes/html/functions.inc.php');

        $app = Application::where('device_id', $device->device_id)->where('app_type', 'smart')->first();
        $appId = $app?->app_id;

        $diskKeys = [];
        $diskLabels = [];
        $diskFields = [];
        $namingTemplate = null;
        $perDiskTemplates = [];
        $defaultViewMode = 'basic';
        $viewModes = [];

        if ($app !== null) {
            $htmlData = HtmlData::forDevice($app, $device->toArray());
            $labelCookie = 'smart_label_mode_' . $device->device_id;
            $labelModes = $htmlData->labelModes();
            $labelMode = $request->cookie($labelCookie) !== null && isset($labelModes[$request->cookie($labelCookie)])
                ? $request->cookie($labelCookie)
                : 'device';

            $diskKeys = $htmlData->diskKeys();
            foreach ($diskKeys as $diskKey) {
                $disk = $htmlData->disk($diskKey);
                $diskLabels[$diskKey] = $disk !== null ? $htmlData->displayLabel($disk, $labelMode) : $diskKey;
                $diskFields[$diskKey] = $disk !== null ? [
                    'device' => $htmlData->deviceLabel($disk),
                    'model' => $htmlData->model($disk),
                    'serial' => $htmlData->serial($disk),
                    'wwn' => trim((string) ($disk['wwn'] ?? '')),
                    'model_family' => trim((string) ($disk['model_family'] ?? '')),
                ] : [];
            }

            $namingTemplate = $htmlData->namingTemplate();
            $perDiskTemplates = $this->perDiskTemplates($appId);
            $defaultViewMode = $htmlData->defaultViewMode();
            $viewModes = $htmlData->diskViewModes();
        }

        return view('device.apps.smart.settings-naming', [
            'device' => $device,
            'appId' => $appId,
            'diskKeys' => $diskKeys,
            'diskLabels' => $diskLabels,
            'diskFields' => $diskFields,
            'namingTemplate' => $namingTemplate,
            'perDiskTemplates' => $perDiskTemplates,
            'defaultViewMode' => $defaultViewMode,
            'viewModes' => $viewModes,
        ]);
    }

    /** Save the global naming template (shared by every device), or a per-disk override on this device when disk_key is given. Blank value clears it (revert to inherited/default). */
    public function updateNamingTemplate(Device $device, Request $request): JsonResponse
    {
        $this->authorize('update', $device);

        $validated = $request->validate([
            'app_id' => 'required|integer',
            'disk_key' => 'nullable|string',
            'value' => 'nullable|string|max:120',
        ]);

        $appId = $this->ownedAppId($device, (int) $validated['app_id']);
        if ($appId === null) {
            return response()->json(['status' => 'error', 'message' => 'Unknown app'], 404);
        }

        $value = trim((string) ($validated['value'] ?? ''));
        if (($invalid = $this->invalidTemplateVar($value)) !== null) {
            return response()->json(['status' => 'error', 'message' => "Unknown variable \${$invalid}"], 422);
        }

        $diskKey = $validated['disk_key'] ?? '';

        if ($diskKey === '') {
            DB::table('smart_app_settings')->updateOrInsert(
                ['app_id' => self::GLOBAL_SETTINGS_APP_ID],
                ['naming_template' => $value !== '' ? $value : null]
            );
        } else {
            $perDisk = $this->perDiskTemplates($appId);
            if ($value === '') {
                unset($perDisk[$diskKey]);
            } else {
                $perDisk[$diskKey] = $value;
            }

            DB::table('smart_app_settings')->updateOrInsert(
                ['app_id' => $appId],
                ['disk_naming_templates' => $perDisk !== [] ? json_encode($perDisk) : null]
            );
        }

        $this->invalidateHtmlData($device, $appId);

        return response()->json(['status' => 'ok', 'message' => 'Naming template updated']);
    }

    /** Save the default disk-view mode used on the overview page when no cookie is set yet. */
    public function updateDefaultViewMode(Device $device, Request $request): JsonResponse
    {
        $this->authorize('update', $device);

        $appId = $this->ownedAppId($device, (int) $request->input('app_id'));
        if ($appId === null) {
            return response()->json(['status' => 'error', 'message' => 'Unknown app'], 404);
        }

        $validModes = array_keys(HtmlData::forDevice(Application::find($appId), $device->toArray())->diskViewModes());

        $validated = $request->validate([
            'value' => 'required|in:' . implode(',', $validModes),
        ]);

        DB::table('smart_app_settings')->updateOrInsert(
            ['app_id' => $appId],
            ['default_view_mode' => $validated['value']]
        );

        $this->invalidateHtmlData($device, $appId);

        return response()->json(['status' => 'ok', 'message' => 'Default view mode updated']);
    }

    /** @return array<string, string> Per-disk naming template overrides for this app, keyed by disk_key. */
    private function perDiskTemplates(int $appId): array
    {
        $json = DB::table('smart_app_settings')->where('app_id', $appId)->value('disk_naming_templates');

        return $json !== null ? (json_decode((string) $json, true) ?: []) : [];
    }

    /** Returns the first unknown $variable name in $template, or null if all are recognised. */
    private function invalidTemplateVar(string $template): ?string
    {
        if (preg_match_all('/\$(\w+)/', $template, $matches) === 0) {
            return null;
        }

        foreach ($matches[1] as $var) {
            if (! in_array($var, self::NAMING_TEMPLATE_VARS, true)) {
                return $var;
            }
        }

        return null;
    }

    /** Inline single-field save (one warn_rate_* window for one row), fired on blur/Enter. No submit button. */
    public function updateField(Device $device, Request $request): JsonResponse
    {
        $this->authorize('update', $device);

        $validated = $request->validate([
            'app_id' => 'required|integer',
            'scope' => 'required|in:disk,global',
            'disk_key' => 'required_if:scope,disk|string|nullable',
            'attribute_id' => 'required|integer',
            'field' => 'required|in:' . implode(',', self::WARN_FIELDS),
            'value' => 'nullable|numeric',
        ]);

        $appId = $this->ownedAppId($device, (int) $validated['app_id']);
        if ($appId === null) {
            return response()->json(['status' => 'error', 'message' => 'Unknown app'], 404);
        }

        [$rowAppId, $diskKey] = $validated['scope'] === 'global' ? [0, ''] : [$appId, (string) $validated['disk_key']];

        $this->upsertThreshold($rowAppId, $diskKey, (int) $validated['attribute_id'], [
            $validated['field'] => $validated['value'] ?? null,
        ]);

        return response()->json(['status' => 'ok', 'message' => 'Threshold updated']);
    }

    /** Per-row mute/unmute: alerting stays off even if a warn_rate_* limit is configured. */
    public function alertToggle(Device $device, Request $request): JsonResponse
    {
        $this->authorize('update', $device);

        $validated = $request->validate([
            'app_id' => 'required|integer',
            'scope' => 'required|in:disk,global',
            'disk_key' => 'required_if:scope,disk|string|nullable',
            'attribute_id' => 'required|integer',
            'state' => 'required|boolean',
        ]);

        $appId = $this->ownedAppId($device, (int) $validated['app_id']);
        if ($appId === null) {
            return response()->json(['status' => 'error', 'message' => 'Unknown app'], 404);
        }

        [$rowAppId, $diskKey] = $validated['scope'] === 'global' ? [0, ''] : [$appId, (string) $validated['disk_key']];

        $this->upsertThreshold($rowAppId, $diskKey, (int) $validated['attribute_id'], [
            'alert_enabled' => (bool) $validated['state'],
        ]);

        return response()->json([
            'status' => 'ok',
            'message' => $validated['state'] ? 'Alerting enabled' : 'Alerting muted',
        ]);
    }

    /**
     * Reset one row to "default": deletes the threshold row outright rather
     * than setting its columns to null. Setting columns to null still leaves
     * a per-disk override row in place, which keeps winning over the global
     * default (it's just an override with no active limits). Deleting the
     * row is what actually makes a disk fall back to inheriting the global
     * default again. For scope=global this deletes the global default
     * itself, so nothing is configured for that attribute at all.
     */
    public function reset(Device $device, Request $request): JsonResponse
    {
        $this->authorize('update', $device);

        $validated = $request->validate([
            'app_id' => 'required|integer',
            'scope' => 'required|in:disk,global',
            'disk_key' => 'required_if:scope,disk|string|nullable',
            'attribute_id' => 'required|integer',
        ]);

        $appId = $this->ownedAppId($device, (int) $validated['app_id']);
        if ($appId === null) {
            return response()->json(['status' => 'error', 'message' => 'Unknown app'], 404);
        }

        $deleted = $validated['scope'] === 'global'
            ? DB::table('smart_attribute_thresholds')
                ->where('app_id', 0)->where('disk_key', '')
                ->where('attribute_id', (int) $validated['attribute_id'])
                ->delete()
            : DB::table('smart_attribute_thresholds')
                ->where('app_id', $appId)
                ->where('disk_key', (string) $validated['disk_key'])
                ->where('attribute_id', (int) $validated['attribute_id'])
                ->delete();

        return response()->json(['status' => 'ok', 'message' => $deleted > 0 ? 'Reset to default' : 'Already at default']);
    }

    /**
     * Copy the global default's warn_rate_* limits and alert_enabled down
     * into a per-disk override row, as an editable starting point. Unlike
     * reset(), the row keeps these as an explicit override afterwards, so it
     * won't drift if the global default later changes.
     */
    public function copyRowToDefault(Device $device, Request $request): JsonResponse
    {
        $this->authorize('update', $device);

        $validated = $request->validate([
            'app_id' => 'required|integer',
            'disk_key' => 'required|string',
            'attribute_id' => 'required|integer',
        ]);

        $appId = $this->ownedAppId($device, (int) $validated['app_id']);
        if ($appId === null) {
            return response()->json(['status' => 'error', 'message' => 'Unknown app'], 404);
        }

        $global = DB::table('smart_attribute_thresholds')
            ->where('app_id', 0)->where('disk_key', '')
            ->where('attribute_id', (int) $validated['attribute_id'])
            ->first();

        $values = [
            'warn_rate_8h' => $global->warn_rate_8h ?? null,
            'warn_rate_24h' => $global->warn_rate_24h ?? null,
            'warn_rate_168h' => $global->warn_rate_168h ?? null,
            'warn_rate_672h' => $global->warn_rate_672h ?? null,
            'alert_enabled' => (bool) (($global->alert_enabled ?? null) ?? true),
        ];

        $this->upsertThreshold($appId, (string) $validated['disk_key'], (int) $validated['attribute_id'], $values);

        return response()->json(['status' => 'ok', 'message' => 'Copied from global default', 'values' => $values]);
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

    /** Flush the cached HtmlData for this device+app so naming/view-mode setting changes show up immediately. */
    private function invalidateHtmlData(Device $device, int $appId): void
    {
        $app = Application::find($appId);
        if ($app !== null) {
            HtmlData::forDevice($app, $device->toArray())->invalidate();
        }
    }

    private function upsertThreshold(int $appId, string $diskKey, int $attributeId, array $values): void
    {
        DbSync::upsert('smart_attribute_thresholds', [
            'app_id' => $appId,
            'disk_key' => $diskKey,
            'attribute_id' => $attributeId,
            ...$values,
        ], ['app_id', 'disk_key', 'attribute_id']);
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
