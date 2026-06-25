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
    }
}
