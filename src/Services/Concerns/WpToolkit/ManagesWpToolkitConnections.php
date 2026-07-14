<?php

namespace hexa_package_wptoolkit\Services\Concerns\WpToolkit;

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
     * Get or create a cached SSH connection for a server.
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
        $localProbe = $this->probeLocalRuntime();

        if ($settings['mode'] === 'local') {
            return 'local';
        }

        if ($this->serverMatchesLocalHost($server) && ($localProbe['usable'] ?? false)) {
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
            : "WP Toolkit (SSH)";
    }

    public function isSameHostServer(WhmServer $server): bool
    {
        return $this->serverMatchesLocalHost($server);
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
        $hostname = $server->hostname;
        $port = config('wptoolkit.ssh.port', 22);
        $timeout = $this->commandTimeoutSeconds();
        $connectTimeout = max(10, min($timeout, 30));
        $username = $server->ssh_admin_username ?: $server->username ?: 'root';

        $this->generic->log('info', '[WpToolkit] SSH connecting', [
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
        $connection->setTimeout($this->commandTimeoutSeconds());

        return [
            'success' => true,
            'connection' => $connection,
        ];
    }
}
