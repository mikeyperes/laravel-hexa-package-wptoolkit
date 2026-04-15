<?php

namespace hexa_package_wptoolkit\Services;

use hexa_package_whm\Models\WhmServer;
use hexa_package_whm\Services\WhmService;
use hexa_core\Services\GenericService;
use hexa_package_wptoolkit\Services\Concerns\ManagesInstalls;
use hexa_package_wptoolkit\Services\Concerns\ManagesCredentials;
use hexa_package_wptoolkit\Services\Concerns\ManagesLogins;
use hexa_package_wptoolkit\Services\Concerns\ManagesWpCli;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * WpToolkitService — all WP Toolkit operations go through this service.
 *
 * Connects to WHM servers via SSH and interacts with the wp-toolkit CLI
 * to discover WordPress installs, manage credentials, and generate login URLs.
 *
 * Methods are organized into domain traits:
 * - ManagesInstalls: getAllInstalls, getInstallsForAccount, parsing
 * - ManagesCredentials: getCredentials, resetWordPressPassword, stored credentials
 * - ManagesLogins: generateWordPressLoginUrl, generateCpanelLoginUrl, etc.
 * - ManagesWpCli: wpCliCreatePost, wpCliUploadMedia, categories, tags, etc.
 */
class WpToolkitService
{
    use ManagesInstalls;
    use ManagesCredentials;
    use ManagesLogins;
    use ManagesWpCli;

    protected GenericService $generic;
    protected WhmService $whm;
    protected array $sshCache = [];
    protected array $installInfoCache = [];

    /**
     * @param GenericService $generic
     * @param WhmService     $whm
     */
    public function __construct(GenericService $generic, WhmService $whm)
    {
        $this->generic = $generic;
        $this->whm = $whm;
    }

    /**
     * Get or create a cached SSH connection for a server.
     * Reuses existing connections to avoid reconnecting for every operation.
     *
     * @param WhmServer $server
     * @return array{success: bool, connection?: SSH2, error?: string}
     */
    public function getConnection(WhmServer $server): array
    {
        $mode = $this->connectionMode($server);
        $key = $mode . '_' . $server->id . '_' . $server->hostname;

        // Try cached connection — quick liveness test to reset channel state
        if (isset($this->sshCache[$key])) {
            $conn = $this->sshCache[$key];
            if ($conn->isConnected()) {
                try {
                    $conn->setTimeout(3);
                    $test = $conn->exec('true');
                    $conn->setTimeout(30);
                    return ['success' => true, 'connection' => $conn];
                } catch (\Throwable $e) {
                    // Stale or broken — reconnect
                }
            }
            unset($this->sshCache[$key]);
        }

        // Create fresh connection
        $result = $mode === 'local'
            ? $this->localConnect($server)
            : $this->sshConnect($server);
        if ($result['success'] && isset($result['connection'])) {
            $result['connection']->setTimeout(30);
            $this->sshCache[$key] = $result['connection'];
        }
        return $result;
    }

    public function connectionMode(WhmServer $server): string
    {
        return $this->shouldUseLocalExecution($server) ? 'local' : 'ssh';
    }

    public function connectionLabel(WhmServer $server): string
    {
        return $this->connectionMode($server) === 'local'
            ? 'WP Toolkit (local)'
            : 'WP Toolkit (SSH)';
    }

    /**
     * Establish an SSH connection to a WHM server.
     *
     * Tries SSH key auth first, falls back to password.
     *
     * @param WhmServer $server
     * @return array{success: bool, connection?: SSH2, error?: string}
     */
    protected function sshConnect(WhmServer $server): array
    {
        if ($this->shouldUseLocalExecution($server)) {
            return $this->localConnect($server);
        }

        $hostname = $server->hostname;
        $port = config('wptoolkit.ssh.port', 22);
        $timeout = config('wptoolkit.ssh.timeout', 120);
        $username = $server->ssh_admin_username ?: $server->username ?: 'root';

        $this->generic->log('info', '[WpToolkit] SSH connecting', [
            'hostname'    => $hostname,
            'port'        => $port,
            'username'    => $username,
            'has_ssh_key' => !empty($server->ssh_private_key),
        ]);

        try {
            $ssh = new SSH2($hostname, $port, 15); // 15 second connect timeout
            $ssh->setTimeout(30); // cap command timeout at 30s

            // Try SSH key first
            if (!empty($server->ssh_private_key)) {
                try {
                    $key = PublicKeyLoader::load(
                        $server->ssh_private_key,
                        $server->ssh_key_passphrase ?: false
                    );
                    if ($ssh->login($username, $key)) {
                        $this->generic->log('info', '[WpToolkit] SSH key auth succeeded');
                        return ['success' => true, 'connection' => $ssh];
                    }
                } catch (\Exception $e) {
                    $this->generic->log('warning', '[WpToolkit] SSH key auth failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Try password
            if (!empty($server->ssh_admin_password)) {
                if ($ssh->login($username, $server->ssh_admin_password)) {
                    $this->generic->log('info', '[WpToolkit] SSH password auth succeeded');
                    return ['success' => true, 'connection' => $ssh];
                }
            }

            return [
                'success' => false,
                'error'   => "SSH auth failed for {$username}@{$hostname}:{$port}. No valid credentials.",
            ];
        } catch (\Exception $e) {
            $this->generic->log('error', '[WpToolkit] SSH connection failed', [
                'hostname' => $hostname,
                'error'    => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error'   => 'SSH connection failed: ' . $e->getMessage(),
            ];
        }
    }

    protected function localConnect(WhmServer $server): array
    {
        $this->generic->log('info', '[WpToolkit] Local execution selected', [
            'hostname' => $server->hostname,
            'mode' => 'local',
        ]);

        $connection = new LocalShellConnection(base_path());
        $connection->setTimeout((int) config('wptoolkit.ssh.timeout', 30));

        return [
            'success' => true,
            'connection' => $connection,
        ];
    }

    protected function shouldUseLocalExecution(WhmServer $server): bool
    {
        $configuredMode = strtolower((string) config('wptoolkit.execution.mode', 'auto'));
        if ($configuredMode === 'local') {
            return true;
        }
        if ($configuredMode === 'ssh') {
            return false;
        }

        if (app()->environment('production') && (bool) config('wptoolkit.execution.force_local_in_production', true)) {
            return true;
        }

        $serverHost = strtolower(trim((string) ($server->hostname ?? '')));
        if ($serverHost === '') {
            return false;
        }

        $configuredHosts = config('wptoolkit.execution.local_hosts', []);
        if (!is_array($configuredHosts)) {
            $configuredHosts = [];
        }

        $localHosts = array_values(array_filter(array_unique(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            array_merge(
                $configuredHosts,
                [
                    gethostname() ?: '',
                    php_uname('n') ?: '',
                    (string) parse_url((string) config('app.url'), PHP_URL_HOST),
                    '127.0.0.1',
                    'localhost',
                ]
            )
        ))));

        return in_array($serverHost, $localHosts, true);
    }
}
