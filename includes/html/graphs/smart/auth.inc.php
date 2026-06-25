<?php

use App\Models\Application;
use LibreNMS\Agent\Unix\Smart\HtmlData;

if (isset($vars['id']) && is_numeric($vars['id'])) {
    // check user has access, unless allow_unauth_graphs is enabled
    $app = Application::when(! $auth, fn ($query) => $query->hasAccess(Auth::user()))->firstWhere(['app_id' => $vars['id']]);

    if ($app) {
        $device = device_by_id_cache($app->device_id);
        $title = generate_device_link($device);
        $title .= ' :: SMART';
        $graph_title = $device['hostname'] . '::smart';

        $htmlData = HtmlData::forDevice($app, $device);
        $diskKey = isset($vars['disk']) ? $htmlData->diskKeyForIndex((string) $vars['disk']) : null;
        $disk = $diskKey !== null ? $htmlData->disk($diskKey) : null;

        if ($disk !== null) {
            // Respects the same per-device label-mode cookie the SMART app pages use,
            // so a graph title names a disk the same way the app's nav does.
            $labelCookie = 'smart_label_mode_' . $device['device_id'];
            $labelModes = $htmlData->labelModes();
            $labelMode = isset($_COOKIE[$labelCookie]) && isset($labelModes[$_COOKIE[$labelCookie]]) ? $_COOKIE[$labelCookie] : 'device';
            $diskLabel = $htmlData->displayLabel($disk, $labelMode);

            $title .= ' :: ' . generate_link($diskLabel, [
                'page' => 'device', 'device' => $device['device_id'], 'tab' => 'apps', 'app' => 'smart',
                'disk' => $htmlData->diskUrlId($diskKey),
            ]);
            $graph_title .= '::' . $diskLabel;
        }

        if (isset($vars['attr_id']) && is_numeric($vars['attr_id'])) {
            $attrId = (int) $vars['attr_id'];
            $attrName = isset($vars['attr_name']) && $vars['attr_name'] !== ''
                ? str_replace('_', ' ', (string) $vars['attr_name'])
                : ($disk !== null ? ($htmlData->attributeGraphSpecs($diskKey)[$attrId]['name'] ?? null) : null);

            $title .= ' :: ID#' . $attrId . ($attrName !== null && $attrName !== '' ? ' ' . $attrName : '');
            $graph_title .= '::attr' . $attrId;
        }

        $auth = true;

        // object_array[1]: drive selector — drives on this device compatible with the current subtype.
        // Omitted for all_* subtypes which graph every disk at once.
        // Prefix determines compatible disk kind:
        //   nvme_*          → NVMe drives only
        //   sata_*/legacy_* → SATA/ATA drives only
        //   disk_*          → both (shared graphs)
        if (! str_starts_with($subtype, 'all_')) {
            $allowedKind = match (true) {
                str_starts_with($subtype, 'nvme')                                         => 'nvme',
                str_starts_with($subtype, 'sata_') || str_starts_with($subtype, 'legacy') => 'sata',
                default                                                                    => null,
            };
            $diskOptions = [];
            $labelCookieForList = $labelCookie ?? ('smart_label_mode_' . $device['device_id']);
            $labelModesForList = $htmlData->labelModes();
            $labelModeForList = isset($_COOKIE[$labelCookieForList], $labelModesForList[$_COOKIE[$labelCookieForList]])
                ? $_COOKIE[$labelCookieForList] : 'device';
            // Some disk_* subtypes only apply to SATA drives with specific attributes.
            $requiredSataAttr = match ($subtype) {
                'disk_pwr_hours'  => 9,   // ATA attribute 9  = Power_On_Hours
                'disk_pwr_cycles' => 12,  // ATA attribute 12 = Power_Cycle_Count
                default           => null,
            };
            // disk_errors/disk_unsafe_shut apply to SATA drives with matching attribute names.
            $sataNameFilter = match ($subtype) {
                'disk_errors'      => '/error/i',
                'disk_unsafe_shut' => '/shutdown/i',
                default            => null,
            };
            $firstDiskKey = null;
            foreach ($htmlData->diskKeys() as $dk) {
                $dkDisk = $htmlData->disk($dk);
                if ($allowedKind !== null && $dkDisk['kind'] !== $allowedKind) {
                    continue;
                }
                if ($dkDisk['kind'] === 'sata') {
                    if ($requiredSataAttr !== null && ! isset($htmlData->attributeGraphSpecs($dk)[$requiredSataAttr])) {
                        continue;
                    }
                    if ($sataNameFilter !== null) {
                        $hasMatch = false;
                        foreach ($htmlData->attributeGraphSpecs($dk) as $spec) {
                            if (preg_match($sataNameFilter, (string) ($spec['raw_name'] ?? ''))) {
                                $hasMatch = true;
                                break;
                            }
                        }
                        if (! $hasMatch) {
                            continue;
                        }
                    }
                }
                $firstDiskKey ??= $dk;
                $diskOptions[] = [
                    'url'      => LibreNMS\Util\Url::generate($vars, [
                        'page' => 'graphs',
                        'disk' => $htmlData->diskIndex($dk),
                        'rrd'  => $dkDisk['kind'] === 'nvme' ? 'smart_nvme' : 'smart',
                    ]),
                    'label'    => $htmlData->displayLabel($dkDisk, $labelModeForList),
                    'selected' => $dk === $diskKey,
                ];
            }
            // If the URL's disk isn't in the eligible list, fall back to the first.
            if ($diskOptions !== [] && $firstDiskKey !== null
                && ! array_filter($diskOptions, fn ($o) => $o['selected'])) {
                $diskKey = $firstDiskKey;
                $disk    = $htmlData->disk($diskKey);
                $vars['disk'] = $htmlData->diskIndex($diskKey);
                $vars['rrd']  = $disk['kind'] === 'nvme' ? 'smart_nvme' : 'smart';
                $diskOptions[0]['selected'] = true;
            }
            if ($diskOptions !== []) {
                $object_array[1] = ['options' => $diskOptions];
            }
        }

        // object_array[2]: attribute selector — only for attr graph subtypes when a disk is selected.
        if ($disk !== null && str_contains($subtype, 'attr')) {
            $attrSpecs = $htmlData->attributeGraphSpecs((string) $diskKey);
            $currentAttr = isset($vars['attr_id']) ? (int) $vars['attr_id'] : null;
            // sata_attr_div only plots attributes with id{N}Hi/Lo DS (format 12 = raw24div24, 13 = raw24div32).
            $divOnly = $subtype === 'sata_attr_div';
            $attrOptions = [];
            $firstAttrId = null;
            $firstAttrSpec = null;
            foreach ($attrSpecs as $attrId => $spec) {
                if ($divOnly && ! in_array($spec['format'] ?? null, [12, 13], true)) {
                    continue;
                }
                if ($firstAttrId === null) {
                    $firstAttrId   = $attrId;
                    $firstAttrSpec = $spec;
                }
                $attrOptions[] = [
                    'url'      => LibreNMS\Util\Url::generate($vars, [
                        'page'        => 'graphs',
                        'attr_id'     => (string) $attrId,
                        'attr_name'   => $spec['raw_name'],
                        'attr_thresh' => $spec['thresh'] !== null ? (string) $spec['thresh'] : '',
                        'rate_unit'   => $spec['rate_unit'] ?? '',
                    ]),
                    'label'    => $spec['title'],
                    'selected' => $attrId === $currentAttr,
                ];
            }
            // If the URL's attr_id isn't in the eligible list, fall back to the first.
            if ($attrOptions !== [] && $firstAttrId !== null
                && ! array_filter($attrOptions, fn ($o) => $o['selected'])) {
                $vars['attr_id']     = (string) $firstAttrId;
                $vars['attr_name']   = $firstAttrSpec['raw_name'];
                $vars['attr_thresh'] = $firstAttrSpec['thresh'] !== null ? (string) $firstAttrSpec['thresh'] : '';
                $vars['rate_unit']   = $firstAttrSpec['rate_unit'] ?? '';
                $attrOptions[0]['selected'] = true;
            }
            if ($attrOptions !== []) {
                $object_array[2] = ['options' => $attrOptions];
            }
        }
    }
}
