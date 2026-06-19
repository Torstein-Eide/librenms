<?php

namespace App\Http\Controllers\Device\Apps;

use App\Models\Application;
use App\Models\Device;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use LibreNMS\Agent\Unix\Smart\HtmlData;

/**
 * Dedicated route for the SMART app's main page, so it no longer has to be
 * reached only through the legacy device=X/tab=apps/app=smart/ catch-all.
 * The page itself (panels, disk tabs, debug panels) is still the same
 * device.apps.smart.index view and its smart_debug_* helpers, defined in
 * includes/html/pages/device/apps/smart.inc.php — required here purely for
 * those function definitions, since that file's own entry point is a no-op
 * without the legacy $app/$device/$vars locals it checks for.
 */
class SmartController
{
    use AuthorizesRequests;

    public function index(Device $device, Request $request): View
    {
        return $this->renderSmartPage($device, $request->query('disk'), 'overview');
    }

    public function compare(Device $device, Request $request): View
    {
        return $this->renderSmartPage($device, null, 'compare');
    }

    private function renderSmartPage(Device $device, ?string $selectedDisk, string $smartPage): View
    {
        $this->authorize('view', $device);

        $app = Application::where('device_id', $device->device_id)->where('app_type', 'smart')->firstOrFail();

        require_once base_path('includes/html/functions.inc.php');
        require_once base_path('includes/html/pages/device/apps/smart.inc.php');

        $tabContent = view('device.apps.smart.index', [
            'data' => HtmlData::forDevice($app, $device->toArray()),
            'app' => $app,
            'device' => $device->toArray(),
            'selectedDisk' => $selectedDisk,
            'smartPage' => $smartPage,
        ])->render();

        return view('device.tabs.legacy', [
            'device' => $device,
            'tab_content' => $tabContent,
        ]);
    }
}
