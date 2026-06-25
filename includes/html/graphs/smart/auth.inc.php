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
            foreach ($htmlData->diskKeys() as $dk) {
                $dkDisk = $htmlData->disk($dk);
                if ($allowedKind !== null && $dkDisk['kind'] !== $allowedKind) {
                    continue;
                }
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
            if ($diskOptions !== []) {
                $object_array[1] = ['options' => $diskOptions];
            }
        }

        // object_array[2]: attribute selector — only for attr graph subtypes when a disk is selected.
        if ($disk !== null && str_contains($subtype, 'attr')) {
            $attrSpecs = $htmlData->attributeGraphSpecs((string) $diskKey);
            $currentAttr = isset($vars['attr_id']) ? (int) $vars['attr_id'] : null;
            $attrOptions = [];
            foreach ($attrSpecs as $attrId => $spec) {
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
            if ($attrOptions !== []) {
                $object_array[2] = ['options' => $attrOptions];
            }
        }
    }
}
