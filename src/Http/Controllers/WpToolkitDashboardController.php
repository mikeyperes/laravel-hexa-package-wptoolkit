<?php

namespace hexa_package_wptoolkit\Http\Controllers;

use hexa_core\Http\Controllers\Controller;
use hexa_package_whm\Models\WhmServer;
use hexa_package_hosting\Models\HostingAccount;
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
     * Show the raw dev/test view.
     *
     * @return \Illuminate\View\View
     */
    public function raw()
    {
        $servers = WhmServer::where('is_active', true)->get();

        return view('wptoolkit::raw.index', [
            'servers' => $servers,
        ]);
    }

    /**
     * AJAX: Get ALL WordPress installs on a server.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAllInstalls(Request $request): JsonResponse
    {
        $request->validate([
            'server_id' => 'required|integer|exists:whm_servers,id',
        ]);

        $server = WhmServer::findOrFail($request->input('server_id'));
        $result = $this->wpToolkit->getAllInstalls($server);

        return response()->json($result);
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

    /**
     * AJAX: Get admin credentials for a WordPress install.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getCredentials(Request $request): JsonResponse
    {
        $request->validate([
            'server_id'  => 'required|integer|exists:whm_servers,id',
            'install_id' => 'required|integer',
            'wp_path'    => 'required|string',
            'username'   => 'required|string|max:255',
            'login_url'  => 'nullable|string',
        ]);

        $server = WhmServer::findOrFail($request->input('server_id'));

        $result = $this->wpToolkit->getCredentials(
            $server,
            (int) $request->input('install_id'),
            $request->input('wp_path'),
            $request->input('username'),
            $request->input('login_url')
        );

        return response()->json($result);
    }

    /**
     * AJAX: Generate one-click WordPress login URL.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function wpLogin(Request $request): JsonResponse
    {
        $request->validate([
            'server_id' => 'required|integer|exists:whm_servers,id',
            'wp_path'   => 'required|string',
            'username'  => 'required|string|max:255',
            'wp_user'   => 'required|string|max:255',
            'site_url'  => 'required|string',
        ]);

        $server = WhmServer::findOrFail($request->input('server_id'));

        $result = $this->wpToolkit->generateWordPressLoginUrl(
            $server,
            $request->input('wp_path'),
            $request->input('username'),
            $request->input('wp_user'),
            $request->input('site_url')
        );

        return response()->json($result);
    }

    /**
     * AJAX: Reset a WordPress user's password.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'server_id'  => 'required|integer|exists:whm_servers,id',
            'install_id' => 'required|integer',
            'wp_path'    => 'required|string',
            'username'   => 'required|string|max:255',
            'wp_user'    => 'required|string|max:255',
            'password'   => 'nullable|string|min:8|max:255',
        ]);

        $server = WhmServer::findOrFail($request->input('server_id'));

        $result = $this->wpToolkit->resetWordPressPassword(
            $server,
            (int) $request->input('install_id'),
            $request->input('wp_path'),
            $request->input('username'),
            $request->input('wp_user'),
            $request->input('password')
        );

        return response()->json($result);
    }

    /**
     * AJAX: Test a WordPress user's password by verifying via wp-cli.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function testLogin(Request $request): JsonResponse
    {
        $request->validate([
            'server_id'  => 'required|integer|exists:whm_servers,id',
            'install_id' => 'required|integer',
            'wp_path'    => 'required|string',
            'username'   => 'required|string|max:255',
            'wp_user'    => 'required|string|max:255',
            'password'   => 'required|string|max:255',
        ]);

        $server = WhmServer::findOrFail($request->input('server_id'));

        $result = $this->wpToolkit->testWordPressPassword(
            $server,
            (int) $request->input('install_id'),
            $request->input('wp_path'),
            $request->input('username'),
            $request->input('wp_user'),
            $request->input('password')
        );

        return response()->json($result);
    }

    /**
     * AJAX: Generate one-click cPanel login URL.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function cpanelLogin(Request $request): JsonResponse
    {
        $request->validate([
            'server_id' => 'required|integer|exists:whm_servers,id',
            'username'  => 'required|string|max:255',
        ]);

        $server = WhmServer::findOrFail($request->input('server_id'));
        $result = $this->wpToolkit->generateCpanelLoginUrl($server, $request->input('username'));

        return response()->json($result);
    }

    /**
     * AJAX: Generate one-click WHM reseller login URL.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function whmResellerLogin(Request $request): JsonResponse
    {
        $request->validate([
            'server_id' => 'required|integer|exists:whm_servers,id',
            'username'  => 'required|string|max:255',
        ]);

        $server = WhmServer::findOrFail($request->input('server_id'));
        $result = $this->wpToolkit->generateWhmResellerLoginUrl($server, $request->input('username'));

        return response()->json($result);
    }
}
