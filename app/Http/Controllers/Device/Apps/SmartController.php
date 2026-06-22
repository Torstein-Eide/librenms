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

    public function graphs(Device $device, Request $request): View
    {
        return $this->renderSmartPage($device, null, 'graphs', [
            'selectedGraphView' => $request->query('view'),
            'graphsDisplayMode' => $request->query('mode') === 'mini' ? 'mini' : 'normal',
        ]);
    }

    private function renderSmartPage(Device $device, ?string $selectedDisk, string $smartPage, array $extraViewData = []): View
    {
        $this->authorize('view', $device);

        $app = Application::where('device_id', $device->device_id)->where('app_type', 'smart')->firstOrFail();

        // Matches DeviceController::renderLegacyTab()'s chdir(base_path()) — the view below
        // (and its graph rows) use legacy includes with paths relative to the install dir
        // (e.g. includes/html/print-graphrow.inc.php), which silently fail to resolve
        // otherwise, since this route never goes through that legacy chdir.
        chdir(base_path());

        require_once base_path('includes/html/functions.inc.php');
        require_once base_path('includes/html/pages/device/apps/smart.inc.php');

        $data = HtmlData::forDevice($app, $device->toArray());

        // ?disk= accepts either the disk's device_name (e.g. "sda", "nvme0" — what links on
        // the page itself now use) or its disk_key (old bookmarked links).
        $resolvedDisk = $selectedDisk !== null ? $data->resolveDiskKey($selectedDisk) : null;

        $tabContent = view('device.apps.smart.index', array_merge([
            'data' => $data,
            'app' => $app,
            'device' => $device->toArray(),
            'selectedDisk' => $resolvedDisk,
            'smartPage' => $smartPage,
        ], $extraViewData))->render();

        return view('device.tabs.legacy', [
            'device' => $device,
            'tab_content' => $tabContent,
        ]);
    }
}
