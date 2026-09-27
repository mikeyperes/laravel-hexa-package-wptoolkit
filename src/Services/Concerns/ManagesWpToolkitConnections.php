<?php

namespace hexa_package_wptoolkit\Services\Concerns;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use hexa_package_wptoolkit\Support\PersistentState;
use Illuminate\Support\Facades\Crypt;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

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
                    $probe = $conn->exec('true');
                    $probeTimedOut = $conn->isTimeout();
                    $conn->setTimeout($this->commandTimeoutSeconds());
                    // CRITICAL — see BUGLOG.md CAMPAIGN-BUG-114. phpseclib can
                    // remain connected with its exec channel open after a
                    // timeout. Never return that poisoned cached connection.
                    if ($probe !== false && !$probeTimedOut) {
                        return ['success' => true, 'connection' => $conn];
                    }
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

        if ($settings['mode'] === 'auto' && $this->serverMatchesLocalHost($server) && ($localProbe['usable'] ?? false)) {
            return 'local';
        }

        if ($settings['mode'] === 'ssh') {
            return 'ssh';
        }

        if (
            ($settings['force_local_in_production'] ?? false)
            && app()->environment('production')
        ) {
            return 'local';
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
                    $key = $this->loadServerPrivateKey($server);
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

    /**
     * Unlock the server's SSH key once and reuse it across requests.
     *
     * The stored OpenSSH key is passphrase-protected with bcrypt-pbkdf, which
     * phpseclib unlocks in pure PHP in about 6.4 seconds on every request. The
     * unlocked key is cached only as a Crypt-encrypted PKCS8 string, protected
     * by the same application key that already encrypts the stored key and its
     * passphrase. The cache key is derived from the stored material, so a new
     * key or passphrase is picked up immediately. See BUGLOG.md JOURNALIST-BUG-001.
     */
    protected function loadServerPrivateKey(WhmServer $server): PrivateKey
    {
        $material = (string) $server->ssh_private_key;
        $passphrase = (string) ($server->ssh_key_passphrase ?? '');
        $cacheKey = 'wptoolkit:ssh-key:' . $server->id . ':' . hash('sha256', $material . "\0" . $passphrase);

        try {
            $cached = PersistentState::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                $key = PublicKeyLoader::load(Crypt::decryptString($cached));
                if ($key instanceof PrivateKey) {
                    return $key;
                }
            }
        } catch (\Throwable) {
            PersistentState::forget($cacheKey);
        }

        $key = PublicKeyLoader::load($material, $passphrase !== '' ? $passphrase : false);
        if (!$key instanceof PrivateKey) {
            throw new \RuntimeException('The stored SSH key is not a private key.');
        }

        try {
            // phpseclib keeps the passphrase on the key and would re-encrypt the
            // export with it; the cached copy is protected by Crypt instead.
            PersistentState::put($cacheKey, Crypt::encryptString($key->withPassword(false)->toString('PKCS8')), 86400);
        } catch (\Throwable) {
            // The unlocked key is still usable for this request.
        }

        return $key;
    }
}
