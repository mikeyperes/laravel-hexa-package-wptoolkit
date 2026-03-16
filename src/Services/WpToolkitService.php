<?php

namespace hexa_package_wptoolkit\Services;

use hexa_package_billing\Models\WhmServer;
use hexa_core\Services\GenericService;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * WpToolkitService — all WP Toolkit operations go through this service.
 *
 * Connects to WHM servers via SSH and interacts with the wp-toolkit CLI
 * to discover WordPress installs, manage credentials, and generate login URLs.
 */
class WpToolkitService
{
    protected GenericService $generic;

    /**
     * @param GenericService $generic
     */
    public function __construct(GenericService $generic)
    {
        $this->generic = $generic;
    }

    /**
     * Get all WordPress installs for a specific cPanel account.
     *
     * Connects via SSH and runs `wp-toolkit --list --user <username>` to discover
     * all WordPress installations under the given cPanel user.
     *
     * @param WhmServer $server   The WHM server to connect to
     * @param string    $username The cPanel username to scan
     * @return array{success: bool, installs?: array, raw_output?: string, error?: string}
     */
    public function getInstallsForAccount(WhmServer $server, string $username): array
    {
        $this->generic->log('info', '[WpToolkit] getInstallsForAccount starting', [
            'server'   => $server->name,
            'hostname' => $server->hostname,
            'username' => $username,
        ]);

        // Step 1: Connect via SSH
        $ssh = $this->sshConnect($server);
        if (!$ssh['success']) {
            return $ssh;
        }

        /** @var SSH2 $connection */
        $connection = $ssh['connection'];

        // Step 2: Check if wp-toolkit is available
        $checkCmd = 'which wp-toolkit 2>/dev/null && echo "WPT_FOUND" || echo "WPT_NOT_FOUND"';
        $checkOutput = trim($connection->exec($checkCmd));

        if (str_contains($checkOutput, 'WPT_NOT_FOUND')) {
            $connection->disconnect();
            $this->generic->log('error', '[WpToolkit] wp-toolkit CLI not found on server', [
                'server' => $server->name,
            ]);
            return [
                'success'    => false,
                'error'      => 'wp-toolkit CLI is not installed on ' . $server->name,
                'raw_output' => $checkOutput,
            ];
        }

        // Step 3: Run wp-toolkit --list for the user
        $escapedUser = escapeshellarg($username);
        $cmd = "wp-toolkit --list --user {$escapedUser} -format json 2>&1";

        $this->generic->log('info', '[WpToolkit] Executing command', [
            'command' => $cmd,
        ]);

        $output = $connection->exec($cmd);
        $connection->disconnect();

        $this->generic->log('info', '[WpToolkit] Raw output received', [
            'output_length' => strlen($output),
            'first_500'     => mb_substr($output, 0, 500),
        ]);

        // Step 4: Parse JSON output
        $parsed = $this->parseWpToolkitListOutput($output, $username);

        if (!$parsed['success']) {
            return array_merge($parsed, ['raw_output' => $output]);
        }

        $this->generic->log('info', '[WpToolkit] getInstallsForAccount complete', [
            'server'        => $server->name,
            'username'      => $username,
            'install_count' => count($parsed['installs']),
        ]);

        return array_merge($parsed, ['raw_output' => $output]);
    }

    /**
     * Parse the JSON output from `wp-toolkit --list -format json`.
     *
     * @param string $output   Raw CLI output
     * @param string $username cPanel username (for filtering)
     * @return array{success: bool, installs?: array, error?: string}
     */
    protected function parseWpToolkitListOutput(string $output, string $username): array
    {
        $trimmed = trim($output);

        if (empty($trimmed)) {
            return [
                'success'  => true,
                'installs' => [],
            ];
        }

        // wp-toolkit may output non-JSON warnings before the JSON block
        // Find the first '[' or '{' to locate JSON start
        $jsonStart = null;
        for ($i = 0; $i < strlen($trimmed); $i++) {
            if ($trimmed[$i] === '[' || $trimmed[$i] === '{') {
                $jsonStart = $i;
                break;
            }
        }

        if ($jsonStart === null) {
            // No JSON found — might be "No installations found" text
            if (stripos($trimmed, 'no installation') !== false || stripos($trimmed, 'not found') !== false) {
                return [
                    'success'  => true,
                    'installs' => [],
                ];
            }
            return [
                'success' => false,
                'error'   => 'wp-toolkit returned non-JSON output: ' . mb_substr($trimmed, 0, 300),
            ];
        }

        $jsonStr = substr($trimmed, $jsonStart);
        $decoded = json_decode($jsonStr, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error'   => 'Failed to parse wp-toolkit JSON: ' . json_last_error_msg(),
            ];
        }

        // Normalize: wp-toolkit may return a single object or an array
        if (isset($decoded['id'])) {
            $decoded = [$decoded];
        }

        $installs = [];
        $homeDir = '/home/' . $username;

        foreach ($decoded as $item) {
            $path = $item['fullPath'] ?? $item['path'] ?? $item['documentRoot'] ?? null;

            // Filter to only installs belonging to this cPanel user
            if ($path && !str_starts_with($path, $homeDir)) {
                continue;
            }

            $installs[] = [
                'id'         => $item['id'] ?? null,
                'name'       => $item['name'] ?? null,
                'path'       => $path,
                'url'        => $item['siteUrl'] ?? $item['url'] ?? null,
                'version'    => $item['version'] ?? $item['wpVersion'] ?? null,
                'php_version' => $item['phpVersion'] ?? null,
                'status'     => $item['status'] ?? $item['state'] ?? null,
                'auto_update' => $item['autoUpdate'] ?? null,
            ];
        }

        return [
            'success'  => true,
            'installs' => $installs,
        ];
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
        $timeout = config('wptoolkit.ssh.timeout', 30);
        $username = $server->ssh_admin_username ?: $server->username ?: 'root';

        $this->generic->log('info', '[WpToolkit] SSH connecting', [
            'hostname'    => $hostname,
            'port'        => $port,
            'username'    => $username,
            'has_ssh_key' => !empty($server->ssh_private_key),
        ]);

        try {
            $ssh = new SSH2($hostname, $port);
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
}
