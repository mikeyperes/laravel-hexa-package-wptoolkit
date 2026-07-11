<?php

namespace hexa_package_wptoolkit\Services\Concerns;

use hexa_core\Models\Setting;
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
 * Connects to WHM servers locally or remotely and interacts with the wp-toolkit CLI
 * to discover WordPress installs, manage credentials, and generate login URLs.
 *
 * Methods are organized into domain traits:
 * - ManagesInstalls: getAllInstalls, getInstallsForAccount, parsing
 * - ManagesCredentials: getCredentials, resetWordPressPassword, stored credentials
 * - ManagesLogins: generateWordPressLoginUrl, generateCpanelLoginUrl, etc.
 * - ManagesWpCli: wpCliCreatePost, wpCliUploadMedia, categories, tags, etc.
 */
trait ManagesWpToolkitConnections
{
    public function commandTimeoutSeconds(): int
    {
        return max(10, (int) config('wptoolkit.ssh.timeout', 120));
    }

    protected function connectionCacheKey(WhmServer $server): string
    {
        return $this->connectionMode($server) . '_' . $server->id . '_' . $server->hostname;
    }

    public function disconnectCachedConnection(?WhmServer $server = null, SSH2|LocalShellConnection|null $connection = null): void
    {
        if ($server) {
            $key = $this->connectionCacheKey($server);
            if (!isset($this->sshCache[$key])) {
                return;
            }

            try {
                $this->sshCache[$key]->disconnect();
            } catch (\Throwable) {
                // Best effort cleanup only.
            }

            unset($this->sshCache[$key]);

            return;
        }

        foreach ($this->sshCache as $key => $cachedConnection) {
            if ($connection && $cachedConnection !== $connection) {
                continue;
            }

            try {
                $cachedConnection->disconnect();
            } catch (\Throwable) {
                // Best effort cleanup only.
            }

            unset($this->sshCache[$key]);
        }
    }

    /**
     * Get or create a cached command connection for a server.
     * Reuses existing connections to avoid reconnecting for every operation.
     *
     * @param WhmServer $server
     * @return array{success: bool, connection?: SSH2, error?: string}
     */
    public function getConnection(WhmServer $server): array
    {
        $key = $this->connectionCacheKey($server);

        // Try cached connection — quick liveness test to reset channel state
        if (isset($this->sshCache[$key])) {
            $conn = $this->sshCache[$key];
            if ($conn instanceof LocalShellConnection) {
                $conn->setTimeout($this->commandTimeoutSeconds());
                return ['success' => true, 'connection' => $conn];
            }
            if ($conn->isConnected()) {
                try {
                    $conn->setTimeout(3);
                    $conn->exec('true');
                    $conn->setTimeout($this->commandTimeoutSeconds());
                    return ['success' => true, 'connection' => $conn];
                } catch (\Throwable $e) {
                    // Stale or broken — reconnect
                }
            }
            $this->disconnectCachedConnection($server);
        }

        // Create fresh connection
        if ($this->connectionMode($server) === 'local') {
            $localProbe = $this->probeLocalRuntime();
            if (!($localProbe['usable'] ?? false)) {
                return [
                    'success' => false,
                    'error' => $localProbe['reason'] ?? 'Local WP Toolkit execution is unavailable for the current runtime user.',
                ];
            }
            $result = $this->localConnect($server);
        } else {
            $result = $this->sshConnect($server);
        }

        if ($result['success'] && isset($result['connection'])) {
            $result['connection']->setTimeout($this->commandTimeoutSeconds());
            $this->sshCache[$key] = $result['connection'];
        }
        return $result;
    }

    public function connectionMode(WhmServer $server): string
    {
        $settings = $this->runtimeSettings();

        // Root CLI can safely use local execution. Web/PHP-FPM users on the same
        // host are usually inside CloudLinux/CageFS LVE, where nested wp-toolkit
        // calls fail; use the configured root SSH transport for those requests.
        if ($this->serverMatchesLocalHost($server)) {
            return strtolower($this->currentRuntimeUser()) === 'root' ? 'local' : 'ssh';
        }

        if ($settings['mode'] === 'local') {
            return 'local';
        }
        if ($settings['mode'] === 'ssh') {
            return 'ssh';
        }

        return 'ssh';
    }

    public function connectionLabel(WhmServer $server): string
    {
        return $this->connectionMode($server) === "local"
            ? "WP Toolkit (local)"
            : "WP Toolkit (remote)";
    }

    public function isSameHostServer(WhmServer $server): bool
    {
        return $this->serverMatchesLocalHost($server);
    }

    /**
     * Establish a remote command connection to a WHM server.
     *
     * Tries key auth first, falls back to password.
     *
     * @param WhmServer $server
     * @return array{success: bool, connection?: SSH2, error?: string}
     */
    protected function sshConnect(WhmServer $server): array
    {
        $hostname = $server->hostname;
        $port = config('wptoolkit.ssh.port', 22);
        $timeout = $this->commandTimeoutSeconds();
        $connectTimeout = max(10, min($timeout, 30));
        $username = $server->ssh_admin_username ?: $server->username ?: 'root';

        $this->generic->log('info', '[WpToolkit] Remote connecting', [
            'hostname'    => $hostname,
            'port'        => $port,
            'username'    => $username,
            'has_ssh_key' => !empty($server->ssh_private_key),
        ]);

        try {
            $ssh = new SSH2($hostname, $port, $connectTimeout);
            $ssh->setTimeout($timeout);

            // Try SSH key first
            if (!empty($server->ssh_private_key)) {
                try {
                    $key = PublicKeyLoader::load(
                        $server->ssh_private_key,
                        $server->ssh_key_passphrase ?: false
                    );
                    if ($ssh->login($username, $key)) {
                        $this->generic->log('info', '[WpToolkit] Remote key auth succeeded');
                        return ['success' => true, 'connection' => $ssh];
                    }
                } catch (\Exception $e) {
                    $this->generic->log('warning', '[WpToolkit] Remote key auth failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Try password
            if (!empty($server->ssh_admin_password)) {
                if ($ssh->login($username, $server->ssh_admin_password)) {
                    $this->generic->log('info', '[WpToolkit] Remote password auth succeeded');
                    return ['success' => true, 'connection' => $ssh];
                }
            }

            return [
                'success' => false,
                'error'   => "Remote auth failed for {$username}@{$hostname}:{$port}. No valid credentials.",
            ];
        } catch (\Exception $e) {
            $this->generic->log('error', '[WpToolkit] WP Toolkit connection failed', [
                'hostname' => $hostname,
                'error'    => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error'   => 'WP Toolkit connection failed: ' . $e->getMessage(),
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
        $connection->setTimeout($this->commandTimeoutSeconds());

        return [
            'success' => true,
            'connection' => $connection,
        ];
    }

    /**
     * Resolve the wp-toolkit binary path for the current command transport.
     *
     * @return string
     */
    public function wptBinary(SSH2|LocalShellConnection|null $connection = null, ?WhmServer $server = null): string
    {
        if ($connection instanceof LocalShellConnection) {
            return (string) ($this->probeLocalRuntime()['selected_binary'] ?? 'wp-toolkit');
        }

        if ($connection instanceof SSH2 && $server) {
            return (string) ($this->probeRemoteRuntime($server, $connection)['selected_binary'] ?? 'wp-toolkit');
        }

        if ($server && $this->connectionMode($server) === 'local') {
            return (string) ($this->probeLocalRuntime()['selected_binary'] ?? 'wp-toolkit');
        }

        if ($server) {
            return (string) ($this->probeRemoteRuntime($server)['selected_binary'] ?? 'wp-toolkit');
        }

        return (string) ($this->probeLocalRuntime()['selected_binary'] ?? 'wp-toolkit');
    }

    public function shellBinary(SSH2|LocalShellConnection|null $connection = null, ?WhmServer $server = null): string
    {
        return escapeshellarg($this->wptBinary($connection, $server));
    }

    public function localWpCliBinary(?LocalShellConnection $connection = null): ?string
    {
        $probe = $this->probeLocalWpCliRuntime($connection);

        if (!($probe['usable'] ?? false)) {
            return null;
        }

        $binary = trim((string) ($probe['selected_binary'] ?? ''));

        return $binary !== '' ? $binary : null;
    }

}
