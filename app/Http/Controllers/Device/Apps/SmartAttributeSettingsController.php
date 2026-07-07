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
use LibreNMS\Agent\Module\Smart\Support\ExcludedAttributesSetting;
use LibreNMS\Agent\Module\Smart\Support\ExtraDevStatSetting;
use LibreNMS\Agent\Module\Smart\Support\HwForecastSetting;
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
    private const NAMING_TEMPLATE_VARS = ['device', 'model', 'serial', 'short_serial', 'model_family', 'type', 'size', 'wwn'];

    /**
     * Common::ATA_COUNTER_ATTRS entries that are genuine failure indicators
     * (not workload/wear stats like Total_LBAs_Written/NAND_Writes), so they
     * belong on this rate-of-change settings page despite smartctl reporting
     * them with the "_Ct"/"_Cnt" abbreviation rather than "_Count" in their
     * name -- the LOWER(name) LIKE '%count%' heuristic below would otherwise
     * silently drop them.
     */
    private const FAILURE_INDICATOR_COUNTER_IDS = [5]; // Reallocated_Sector_Ct

    public function index(Device $device, Request $request): View
    {
        $this->authorize('update', $device);

        require_once base_path('includes/html/functions.inc.php');

        $app = Application::where('device_id', $device->device_id)->where('app_type', 'smart')->first();
        $appId = $app?->app_id;

        // Rate-of-change thresholds only make sense for the newly-detected
        // ("Count" in the name) COUNTER attributes, plus the handful of
        // FAILURE_INDICATOR_COUNTER_IDS above whose name doesn't literally say
        // "Count". These are failure-style counters (e.g. Reallocated_Event_Count,
        // Reallocated_Sector_Ct). GAUGE attributes have no rate semantics, and
        // the rest of the legacy fixed-list counters (Common::ATA_COUNTER_ATTRS:
        // Total_LBAs_Written, NAND_Writes, etc.) are workload/wear statistics
        // that grow during normal use, not failure indicators, so both are
        // excluded from this settings page.
        $rows = $appId === null ? collect() : DB::table('smart_sata_attributes')
            ->where('app_id', $appId)
            ->where('rrd_type', 'COUNTER')
            ->where(function ($query) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%count%'])
                    ->orWhereIn('attribute_id', self::FAILURE_INDICATOR_COUNTER_IDS);
            })
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

            $diskKeys = array_values(array_intersect($htmlData->diskKeys(), $rowDiskKeys));
            $diskLabels = [];
            foreach ($diskKeys as $diskKey) {
                $disk = $htmlData->disk($diskKey);
                $diskLabels[$diskKey] = $disk !== null ? $htmlData->displayLabel($disk, $labelMode) : $diskKey;
            }

            // Naming/view-mode partials use the full disk set (including disks
            // with no thresholds yet), not just $diskKeys above.
            foreach ($htmlData->diskKeys() as $diskKey) {
                $disk = $htmlData->disk($diskKey);
                $diskFields[$diskKey] = $disk !== null ? [
                    'device' => $htmlData->deviceLabel($disk),
                    'model' => $htmlData->model($disk),
                    'serial' => $htmlData->serial($disk),
                    'short_serial' => $htmlData->shortSerial($disk),
                    'model_family' => trim((string) ($disk['model_family'] ?? '')),
                    'type' => $htmlData->typeLabel($disk),
                    'size' => $htmlData->sizeLabel($disk),
                    'wwn' => trim((string) ($disk['wwn'] ?? '')),
                ] : [];
                if (! isset($diskLabels[$diskKey])) {
                    $diskLabels[$diskKey] = $disk !== null ? $htmlData->displayLabel($disk, $labelMode) : $diskKey;
                }
            }

            $namingTemplate = $htmlData->namingTemplate();
            $perDiskTemplates = $this->perDiskTemplates($appId);
            $defaultViewMode = $htmlData->defaultViewMode();
            $viewModes = $htmlData->diskViewModes();
        }

        // Global tab's attribute-defaults table lists every attribute_id ever
        // discovered on ANY device (not just this one), since the global default
        // it edits applies install-wide -- an attribute this device has never
        // seen can still have (or need) a global default. "Max" is the worst-case
        // measured rate across every disk on every device, so the tab can show
        // what the noisiest disk anywhere is doing instead of hiding its rate
        // columns outright.
        $globalAttributeRows = DB::table('smart_sata_attributes')
            ->where('rrd_type', 'COUNTER')
            ->where(function ($query) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%count%'])
                    ->orWhereIn('attribute_id', self::FAILURE_INDICATOR_COUNTER_IDS);
            })
            ->select('attribute_id', 'name', 'rate_8h', 'rate_24h', 'rate_168h', 'rate_672h')
            ->get();

        $maxRatesByAttribute = [];
        foreach ($globalAttributeRows as $row) {
            $attributeId = (int) $row->attribute_id;
            foreach (['rate_8h', 'rate_24h', 'rate_168h', 'rate_672h'] as $window) {
                if (! is_numeric($row->$window)) {
                    continue;
                }
                $value = (float) $row->$window;
                if (! isset($maxRatesByAttribute[$attributeId][$window]) || $value > $maxRatesByAttribute[$attributeId][$window]) {
                    $maxRatesByAttribute[$attributeId][$window] = $value;
                }
            }
        }

        // One row per attribute_id (named from whichever row first carried that
        // attribute), editing the app_id=0/disk_key='' global-default row directly.
        $attributeNames = $globalAttributeRows->unique('attribute_id')->pluck('name', 'attribute_id');
        $globalDefaultItems = $attributeNames->map(function ($name, $attributeId) use ($globals, $maxRatesByAttribute) {
            $global = $globals[$attributeId] ?? null;

            return [
                'disk_key' => self::GLOBAL_DISK_KEY,
                'attribute_id' => $attributeId,
                'name' => $name,
                'is_override' => false,
                'has_row' => $global !== null,
                'alert_enabled' => (bool) (($global->alert_enabled ?? null) ?? true),
                'rate_8h' => $maxRatesByAttribute[$attributeId]['rate_8h'] ?? null,
                'rate_24h' => $maxRatesByAttribute[$attributeId]['rate_24h'] ?? null,
                'rate_168h' => $maxRatesByAttribute[$attributeId]['rate_168h'] ?? null,
                'rate_672h' => $maxRatesByAttribute[$attributeId]['rate_672h'] ?? null,
                'warn_rate_8h' => $global->warn_rate_8h ?? null,
                'warn_rate_24h' => $global->warn_rate_24h ?? null,
                'warn_rate_168h' => $global->warn_rate_168h ?? null,
                'warn_rate_672h' => $global->warn_rate_672h ?? null,
            ];
        })->values()->sortBy('attribute_id')->values();

        $logExtraDevStatsGlobal = ExtraDevStatSetting::resolve(self::GLOBAL_SETTINGS_APP_ID);
        $logExtraDevStatsOverride = null;
        $enableHwForecastGlobal = HwForecastSetting::resolve(self::GLOBAL_SETTINGS_APP_ID);
        $enableHwForecastOverride = null;

        if ($appId !== null) {
            $rawOverride = DB::table('smart_app_settings')->where('app_id', $appId)->value('log_extra_dev_stats');
            $logExtraDevStatsOverride = $rawOverride === null ? null : (bool) $rawOverride;
            $rawHwOverride = DB::table('smart_app_settings')->where('app_id', $appId)->value('enable_hw_forecast');
            $enableHwForecastOverride = $rawHwOverride === null ? null : (bool) $rawHwOverride;
        }

        // Rotating Wear sensor's excluded attributes: global default list
        // (falls back to the built-in defaults until customized) plus this
        // device's per-disk overrides, if any. Affects only which attributes
        // feed that sensor's calculation -- see ExcludedAttributesSetting.
        $excludedAttributesGlobal = ExcludedAttributesSetting::resolve(ExcludedAttributesSetting::GLOBAL_APP_ID, '');
        $excludedAttributesGlobalCustomized = DB::table('smart_app_settings')
            ->where('app_id', ExcludedAttributesSetting::GLOBAL_APP_ID)
            ->value('wear_excluded_attributes') !== null;

        $perDiskExcludes = $appId !== null ? $this->perDiskExcludedAttributes($appId) : [];
        $excludedAttributesByDisk = [];
        $excludedAttributesHasOverride = [];
        foreach ($diskKeys as $diskKey) {
            $excludedAttributesHasOverride[$diskKey] = array_key_exists($diskKey, $perDiskExcludes);
            $excludedAttributesByDisk[$diskKey] = $excludedAttributesHasOverride[$diskKey]
                ? $perDiskExcludes[$diskKey]
                : $excludedAttributesGlobal;
        }

        // Distinct attribute names ever discovered on any device, for the
        // "add a pattern" datalist autocomplete (unlike $attributeNames above,
        // not limited to the COUNTER/"count" subset used for rate thresholds).
        $knownAttributeNames = DB::table('smart_sata_attributes')
            ->whereNotNull('name')
            ->distinct()
            ->orderBy('name')
            ->pluck('name')
            ->filter(fn ($name) => trim((string) $name) !== '')
            ->values()
            ->all();

        return view('device.apps.smart.settings', [
            'device' => $device,
            'appId' => $appId,
            'diskKeys' => $diskKeys,
            'diskLabels' => $diskLabels,
            'itemsByDisk' => $itemsByDisk,
            'globalDefaultItems' => $globalDefaultItems,
            'diskFields' => $diskFields,
            'namingTemplate' => $namingTemplate,
            'perDiskTemplates' => $perDiskTemplates,
            'defaultViewMode' => $defaultViewMode,
            'viewModes' => $viewModes,
            'logExtraDevStatsGlobal' => $logExtraDevStatsGlobal,
            'logExtraDevStatsOverride' => $logExtraDevStatsOverride,
            'enableHwForecastGlobal' => $enableHwForecastGlobal,
            'enableHwForecastOverride' => $enableHwForecastOverride,
            'excludedAttributesGlobal' => $excludedAttributesGlobal,
            'excludedAttributesGlobalCustomized' => $excludedAttributesGlobalCustomized,
            'excludedAttributesByDisk' => $excludedAttributesByDisk,
            'excludedAttributesHasOverride' => $excludedAttributesHasOverride,
            'knownAttributeNames' => $knownAttributeNames,
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

    /**
     * Save the global default for "log extra Device Statistics to RRD", or a
     * per-device override when scope=disk. A null value on scope=disk clears
     * the override so the device goes back to inheriting the global default.
     */
    public function updateLogExtraDevStats(Device $device, Request $request): JsonResponse
    {
        $this->authorize('update', $device);

        $validated = $request->validate([
            'app_id' => 'required|integer',
            'scope' => 'required|in:disk,global',
            'value' => 'nullable|boolean',
        ]);

        $appId = $this->ownedAppId($device, (int) $validated['app_id']);
        if ($appId === null) {
            return response()->json(['status' => 'error', 'message' => 'Unknown app'], 404);
        }

        $targetAppId = $validated['scope'] === 'global' ? ExtraDevStatSetting::GLOBAL_APP_ID : $appId;
        $value = $validated['value'] ?? null;

        DB::table('smart_app_settings')->updateOrInsert(
            ['app_id' => $targetAppId],
            ['log_extra_dev_stats' => $value === null ? null : (bool) $value]
        );

        $this->invalidateHtmlData($device, $appId);

        return response()->json(['status' => 'ok', 'message' => 'Setting updated']);
    }

    /**
     * Save the global default for "Enable Holt-Winters forecasting", or a
     * per-device override when scope=disk. A null value on scope=disk clears
     * the override so the device goes back to inheriting the global default.
     */
    public function updateHwForecast(Device $device, Request $request): JsonResponse
    {
        $this->authorize('update', $device);

        $validated = $request->validate([
            'app_id' => 'required|integer',
            'scope' => 'required|in:disk,global',
            'value' => 'nullable|boolean',
        ]);

        $appId = $this->ownedAppId($device, (int) $validated['app_id']);
        if ($appId === null) {
            return response()->json(['status' => 'error', 'message' => 'Unknown app'], 404);
        }

        $targetAppId = $validated['scope'] === 'global' ? HwForecastSetting::GLOBAL_APP_ID : $appId;
        $value = $validated['value'] ?? null;

        DB::table('smart_app_settings')->updateOrInsert(
            ['app_id' => $targetAppId],
            ['enable_hw_forecast' => $value === null ? null : (bool) $value]
        );

        $this->invalidateHtmlData($device, $appId);

        return response()->json(['status' => 'ok', 'message' => 'Setting updated']);
    }

    /** @return array<string, string> Per-disk naming template overrides for this app, keyed by disk_key. */
    private function perDiskTemplates(int $appId): array
    {
        $json = DB::table('smart_app_settings')->where('app_id', $appId)->value('disk_naming_templates');

        return $json !== null ? (json_decode((string) $json, true) ?: []) : [];
    }

    /**
     * Save the global default excluded-attributes list, or a per-disk override
     * on this device when scope=disk. reset=true clears the target (global
     * reverts to the built-in defaults; disk reverts to inheriting the global
     * list) instead of saving an explicit (possibly empty) entries array.
     */
    public function updateExcludedAttributes(Device $device, Request $request): JsonResponse
    {
        $this->authorize('update', $device);

        $validated = $request->validate([
            'app_id' => 'required|integer',
            'scope' => 'required|in:disk,global',
            'disk_key' => 'required_if:scope,disk|string|nullable',
            'reset' => 'nullable|boolean',
            'entries' => 'nullable|array',
            'entries.*.type' => 'required|in:name,regex,id',
            'entries.*.pattern' => 'required|string|max:200',
            'entries.*.comment' => 'nullable|string|max:255',
        ]);

        $appId = $this->ownedAppId($device, (int) $validated['app_id']);
        if ($appId === null) {
            return response()->json(['status' => 'error', 'message' => 'Unknown app'], 404);
        }

        // jQuery's default $.param() serialization drops an "entries" key
        // entirely when the JS array is empty (e.g. Reset, or the last row
        // removed), so it's routinely absent from the request rather than an
        // empty array -- treat "not sent" the same as "sent empty".
        $entries = array_map(static fn (array $e) => [
            'type' => $e['type'],
            'pattern' => $e['pattern'],
            'comment' => trim((string) ($e['comment'] ?? '')) ?: null,
        ], $validated['entries'] ?? []);

        foreach ($entries as $entry) {
            if ($entry['type'] === 'regex' && @preg_match($entry['pattern'], '') === false) {
                return response()->json(['status' => 'error', 'message' => "Invalid regex: {$entry['pattern']}"], 422);
            }
            if ($entry['type'] === 'id' && ! ctype_digit($entry['pattern'])) {
                return response()->json(['status' => 'error', 'message' => "Invalid attribute ID: {$entry['pattern']}"], 422);
            }
        }

        $reset = (bool) ($validated['reset'] ?? false);

        if ($validated['scope'] === 'global') {
            DB::table('smart_app_settings')->updateOrInsert(
                ['app_id' => ExcludedAttributesSetting::GLOBAL_APP_ID],
                ['wear_excluded_attributes' => $reset ? null : json_encode($entries)]
            );
        } else {
            $diskKey = (string) $validated['disk_key'];
            $perDisk = $this->perDiskExcludedAttributes($appId);
            if ($reset) {
                unset($perDisk[$diskKey]);
            } else {
                $perDisk[$diskKey] = $entries;
            }

            DB::table('smart_app_settings')->updateOrInsert(
                ['app_id' => $appId],
                ['disk_wear_excluded_attributes' => $perDisk !== [] ? json_encode($perDisk) : null]
            );
        }

        $this->invalidateHtmlData($device, $appId);

        $diskKeyForResolve = $validated['scope'] === 'global' ? '' : (string) $validated['disk_key'];
        $resolveAppId = $validated['scope'] === 'global' ? ExcludedAttributesSetting::GLOBAL_APP_ID : $appId;

        return response()->json([
            'status' => 'ok',
            'message' => 'Excluded attributes updated',
            'entries' => ExcludedAttributesSetting::resolve($resolveAppId, $diskKeyForResolve),
        ]);
    }

    /** @return array<string, array<int, array<string, mixed>>> Per-disk excluded-attribute overrides for this app, keyed by disk_key. */
    private function perDiskExcludedAttributes(int $appId): array
    {
        $json = DB::table('smart_app_settings')->where('app_id', $appId)->value('disk_wear_excluded_attributes');

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

        // No override row is left after a reset, so the warn_rate_* fields
        // always go back to blank (letting the placeholder show the inherited
        // global default) regardless of what that default's value is. Only
        // alert_enabled needs the actual inherited value reported back, since
        // the checkbox has no "blank/inherited" state of its own.
        $global = $validated['scope'] === 'global' ? null : DB::table('smart_attribute_thresholds')
            ->where('app_id', 0)->where('disk_key', '')
            ->where('attribute_id', (int) $validated['attribute_id'])
            ->first();

        return response()->json([
            'status' => 'ok',
            'message' => $deleted > 0 ? 'Reset to default' : 'Already at default',
            'values' => [
                'warn_rate_8h' => null,
                'warn_rate_24h' => null,
                'warn_rate_168h' => null,
                'warn_rate_672h' => null,
                'alert_enabled' => (bool) (($global->alert_enabled ?? null) ?? true),
            ],
        ]);
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

        if ($global === null) {
            return response()->json(['status' => 'error', 'message' => 'No global default configured for this attribute yet'], 404);
        }

        $values = [
            'warn_rate_8h' => $global->warn_rate_8h,
            'warn_rate_24h' => $global->warn_rate_24h,
            'warn_rate_168h' => $global->warn_rate_168h,
            'warn_rate_672h' => $global->warn_rate_672h,
            'alert_enabled' => (bool) ($global->alert_enabled ?? true),
        ];

        $this->upsertThreshold($appId, (string) $validated['disk_key'], (int) $validated['attribute_id'], $values);

        return response()->json(['status' => 'ok', 'message' => 'Copied from global default', 'values' => $values]);
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
