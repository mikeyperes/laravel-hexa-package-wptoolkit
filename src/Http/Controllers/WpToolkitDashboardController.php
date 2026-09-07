<?php

namespace hexa_package_wptoolkit\Http\Controllers;

use hexa_core\Http\Controllers\Controller;
use hexa_core\Models\Setting;
use hexa_package_whm\Models\HostingAccount;
use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\WpToolkitService;
use hexa_package_wptoolkit\Support\WpToolkitInstallScope;
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
     * Resolve and authorize the cPanel account attached to a terminal-scoped request.
     *
     * @param Request $request
     * @return HostingAccount
     */
    protected function authorizeAccountRequest(Request $request): HostingAccount
    {
        $account = HostingAccount::with('whmServer')
            ->whereHas('whmServer')
            ->where('whm_server_id', (int) $request->input('server_id'))
            ->where('username', (string) $request->input('username'))
            ->firstOrFail();

        $accessService = 'hexa_app_code_portal\\Portal\\Terminal\\Support\\TerminalAccountAccessService';
        if (class_exists($accessService)) {
            abort_unless(app($accessService)->canAccess($request->user(), (int) $account->id), 403);
        } else {
            $this->authorizeAdministratorRequest($request);
        }

        return $account;
    }

    /**
     * Resolve an installation only from the authorized account's live inventory.
     * Client-provided paths and URLs are never trusted as operation targets.
     *
     * @return array{0: HostingAccount, 1: array<string, mixed>}
     */
    protected function authorizeInstallRequest(Request $request): array
    {
        $account = $this->authorizeAccountRequest($request);
        $inventory = $this->wpToolkit->getInstallsForAccount($account->whmServer, $account->username);

        abort_unless((bool) ($inventory['success'] ?? false), 502, 'Unable to verify the WordPress installation inventory.');

        $install = WpToolkitInstallScope::resolve(
            (array) ($inventory['installs'] ?? []),
            (int) $request->input('install_id'),
            (string) $account->username,
        );

        // A 404 does not reveal whether a foreign installation ID exists.
        abort_unless($install !== null, 404, 'WordPress installation not found.');

        return [$account, $install];
    }

    protected function authorizeAdministratorRequest(Request $request): void
    {
        $user = $request->user();

        abort_unless($user && method_exists($user, 'isAdmin') && $user->isAdmin(), 403);
    }

    /**
     * Show the WP Toolkit test dashboard.
     *
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $this->authorizeAdministratorRequest($request);
        $servers = WhmServer::where('is_active', true)->get();
        $publishSites = collect();

        $publishSiteModel = 'hexa_app_publish\\Publishing\\Sites\\Models\\PublishSite';
        if (class_exists($publishSiteModel)) {
            $publishSites = $publishSiteModel::query()
                ->where('connection_type', 'wptoolkit')
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'url',
                    'hosting_account_id',
                    'wordpress_install_id',
                    'status',
                    'last_error',
                ]);
        }

        $serverPayload = $servers->map(static fn (WhmServer $server): array => [
            'id' => $server->id,
            'name' => $server->name,
            'hostname' => $server->hostname,
        ])->values();

        $publishSitePayload = $publishSites->map(static fn ($site): array => [
            'id' => $site->id,
            'name' => $site->name,
            'url' => $site->url,
            'install_id' => $site->wordpress_install_id,
            'status' => $site->status,
            'last_error' => $site->last_error,
        ])->values();

        return view('wptoolkit::dashboard.index', [
            'servers' => $servers,
            'serverPayload' => $serverPayload,
            'publishSitePayload' => $publishSitePayload,
            'publishSites' => $publishSites,
            'settings' => $this->wpToolkit->runtimeSettings(),
        ]);
    }

    /**
     * Show the raw dev/test view.
     *
     * @return \Illuminate\View\View
     */
    public function raw(Request $request)
    {
        $this->authorizeAdministratorRequest($request);
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
        $this->authorizeAdministratorRequest($request);

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

        $account = $this->authorizeAccountRequest($request);
        $server = $account->whmServer;
        $username = $account->username;

        $result = $this->wpToolkit->getInstallsForAccount($server, $username);
        // Account-scoped UI must not expose raw server-wide wp-toolkit output.
        unset($result["raw_output"]);

        return response()->json($result);
    }

    /**
     * AJAX: Shared WordPress media selector.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function mediaSelector(Request $request): JsonResponse
    {
        $request->validate([
            'server_id'  => 'required|integer|exists:whm_servers,id',
            'install_id' => 'required|integer|min:1',
            'username'   => 'required|string|max:255',
            'search'     => 'nullable|string|max:255',
            'mime_type'  => 'nullable|string|max:80',
            'page'       => 'nullable|integer|min:1',
            'per_page'   => 'nullable|integer|min:1|max:100',
        ]);

        [$account, $install] = $this->authorizeInstallRequest($request);

        $result = $this->wpToolkit->wpCliMediaSelector(
            $account->whmServer,
            (int) $install['id'],
            [
                'search' => (string) $request->input('search', ''),
                'mime_type' => (string) $request->input('mime_type', 'image'),
                'page' => (int) $request->input('page', 1),
                'per_page' => (int) $request->input('per_page', 60),
            ]
        );

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
            'install_id' => 'required|integer|min:1',
            'username'   => 'required|string|max:255',
        ]);

        [$account, $install] = $this->authorizeInstallRequest($request);

        $result = $this->wpToolkit->getCredentials(
            $account->whmServer,
            (int) $install['id'],
            (string) $install['path'],
            $account->username,
            isset($install['login_url']) ? (string) $install['login_url'] : null,
        );

        unset($result['raw_output'], $result['debug_stored_creds']);
        if (! ($request->user() && method_exists($request->user(), 'isAdmin') && $request->user()->isAdmin())) {
            unset($result['db_credentials']);
        }

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
            'install_id' => 'required|integer|min:1',
            'username'  => 'required|string|max:255',
            'wp_user'   => 'required|string|max:255',
            'redirect'  => 'nullable|string|max:255',
        ]);

        [$account, $install] = $this->authorizeInstallRequest($request);

        $result = $this->wpToolkit->generateWordPressLoginUrl(
            $account->whmServer,
            (string) $install['path'],
            $account->username,
            $request->input('wp_user'),
            (string) ($install['url'] ?? ''),
            (string) $request->input('redirect', '')
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
            'install_id' => 'required|integer|min:1',
            'username'   => 'required|string|max:255',
            'wp_user'    => 'required|string|max:255',
            'password'   => 'nullable|string|min:8|max:255',
        ]);

        [$account, $install] = $this->authorizeInstallRequest($request);

        $result = $this->wpToolkit->resetWordPressPassword(
            $account->whmServer,
            (int) $install['id'],
            (string) $install['path'],
            $account->username,
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
            'install_id' => 'required|integer|min:1',
            'username'   => 'required|string|max:255',
            'wp_user'    => 'required|string|max:255',
            'password'   => 'required|string|max:255',
        ]);

        [$account, $install] = $this->authorizeInstallRequest($request);

        $result = $this->wpToolkit->testWordPressPassword(
            $account->whmServer,
            (int) $install['id'],
            (string) $install['path'],
            $account->username,
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

        $account = $this->authorizeAccountRequest($request);
        $server = $account->whmServer;
        $result = $this->wpToolkit->generateCpanelLoginUrl($server, $account->username);

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

        $account = $this->authorizeAccountRequest($request);
        $server = $account->whmServer;
        $result = $this->wpToolkit->generateWhmResellerLoginUrl($server, $account->username);

        return response()->json($result);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->authorizeAdministratorRequest($request);

        $validated = $request->validate([
            'mode' => 'required|string|in:auto,ssh,local',
            'local_hosts' => 'nullable|string|max:5000',
            'local_binary_path' => 'nullable|string|max:1000',
            'remote_binary_path' => 'nullable|string|max:1000',
            'probe_timeout' => 'required|integer|min:2|max:60',
        ]);

        Setting::setValue('wptoolkit_execution_mode', $validated['mode'], 'wptoolkit');
        Setting::setValue('wptoolkit_local_hosts', trim((string) ($validated['local_hosts'] ?? '')), 'wptoolkit');
        Setting::setValue('wptoolkit_local_binary_path', trim((string) ($validated['local_binary_path'] ?? '')), 'wptoolkit');
        Setting::setValue('wptoolkit_remote_binary_path', trim((string) ($validated['remote_binary_path'] ?? '')), 'wptoolkit');
        Setting::setValue('wptoolkit_probe_timeout', (string) $validated['probe_timeout'], 'wptoolkit');

        return response()->json([
            'success' => true,
            'message' => 'WP Toolkit command settings saved.',
            'settings' => $this->wpToolkit->runtimeSettings(),
        ]);
    }

    public function serverDiagnostics(Request $request): JsonResponse
    {
        $this->authorizeAdministratorRequest($request);

        $request->validate([
            'server_id' => 'required|integer|exists:whm_servers,id',
        ]);

        $server = WhmServer::findOrFail((int) $request->input('server_id'));

        return response()->json($this->wpToolkit->inspectCommandRuntime($server));
    }

    public function siteCommandTest(Request $request): JsonResponse
    {
        $this->authorizeAdministratorRequest($request);

        $validated = $request->validate([
            'site_id' => 'required|integer',
            'test' => 'required|string|in:write,authors,categories',
        ]);

        $publishSiteModel = 'hexa_app_publish\\Publishing\\Sites\\Models\\PublishSite';
        if (!class_exists($publishSiteModel)) {
            return response()->json([
                'success' => false,
                'message' => 'Publish app is not installed, so site command tests are unavailable.',
            ], 422);
        }

        $site = $publishSiteModel::query()->findOrFail((int) $validated['site_id']);
        $account = HostingAccount::find($site->hosting_account_id);
        $server = $account ? WhmServer::find($account->whm_server_id) : null;

        if (!$server || !$site->wordpress_install_id) {
            return response()->json([
                'success' => false,
                'message' => 'Site is missing a server or WordPress install ID.',
            ], 422);
        }

        $result = match ($validated['test']) {
            'write' => $this->wpToolkit->wpCliTestWriteAccess($server, (int) $site->wordpress_install_id),
            'authors' => $this->wpToolkit->wpCliListAdminUsers($server, (int) $site->wordpress_install_id),
            'categories' => $this->wpToolkit->wpCliListCategories($server, (int) $site->wordpress_install_id),
        };

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'test' => $validated['test'],
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'url' => $site->url,
                'install_id' => $site->wordpress_install_id,
            ],
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'hostname' => $server->hostname,
            ],
            'runtime' => $this->wpToolkit->inspectCommandRuntime($server),
            'result' => $result,
        ]);
    }
}
