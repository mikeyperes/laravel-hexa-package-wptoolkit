<?php

namespace hexa_package_wptoolkit\Http\Controllers;

use hexa_core\Http\Controllers\Controller;
use hexa_package_billing\Models\WhmServer;
use hexa_package_billing\Models\HostingAccount;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Dashboard controller for WP Toolkit testing and management.
 */
class WpToolkitDashboardController extends Controller
{
    protected WpToolkitService $wpToolkit;

    /**
     * @param WpToolkitService $wpToolkit
     */
    public function __construct(WpToolkitService $wpToolkit)
    {
        $this->wpToolkit = $wpToolkit;
    }

    /**
     * Show the WP Toolkit test dashboard.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $servers = WhmServer::where('is_active', true)->get();

        return view('wptoolkit::dashboard.index', [
            'servers' => $servers,
        ]);
    }

    /**
     * AJAX: Get WordPress installs for a cPanel account.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getInstalls(Request $request): JsonResponse
    {
        $request->validate([
            'server_id' => 'required|integer|exists:whm_servers,id',
            'username'  => 'required|string|max:255',
        ]);

        $server = WhmServer::findOrFail($request->input('server_id'));
        $username = $request->input('username');

        $result = $this->wpToolkit->getInstallsForAccount($server, $username);

        return response()->json($result);
    }
}
