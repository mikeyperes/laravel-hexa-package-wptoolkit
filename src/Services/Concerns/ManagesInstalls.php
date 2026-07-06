<?php

namespace hexa_package_wptoolkit\Services\Concerns;

use hexa_package_whm\Models\WhmServer;

/**
 * ManagesInstalls — WordPress install discovery and parsing.
 */
trait ManagesInstalls
{
    /**
     * Get ALL WordPress installs on a WHM server (all accounts).
     *
     * Connects through the selected WP Toolkit transport and runs `wp-toolkit --list -format json` without
     * a user filter to discover every WordPress installation on the server.
     *
     * @param WhmServer $server The WHM server to scan
     * @return array{success: bool, installs?: array, raw_output?: string, error?: string}
     */
    public function getAllInstalls(WhmServer $server): array
    {
        $this->generic->log('info', '[WpToolkit] getAllInstalls starting', [
            'server'   => $server->name,
            'hostname' => $server->hostname,
        ]);

        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return $ssh;
        }

        $connection = $ssh['connection'];
        $wptBin = $this->shellBinary($connection, $server);

        // Check if wp-toolkit is available
        $checkCmd = "which {$wptBin} 2>/dev/null && echo \"WPT_FOUND\" || echo \"WPT_NOT_FOUND\"";
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

        // Get ALL installs (no user filter)
        $cmd = "{$wptBin} --list -format json 2>&1";

        $this->generic->log('info', '[WpToolkit] Executing command', ['command' => $cmd]);
        $output = $connection->exec($cmd);
        $connection->disconnect();

        $this->generic->log('info', '[WpToolkit] Raw output received', [
            'output_length' => strlen($output),
            'first_500'     => mb_substr($output, 0, 500),
        ]);

        $parsed = $this->parseWpToolkitAllOutput($output);

        if (!$parsed['success']) {
            return array_merge($parsed, ['raw_output' => $output]);
        }

        $this->generic->log('info', '[WpToolkit] getAllInstalls complete', [
            'server'        => $server->name,
            'install_count' => count($parsed['installs']),
        ]);

        return array_merge($parsed, ['raw_output' => $output]);
    }

    /**
     * Parse the JSON output from `wp-toolkit --list -format json` (all installs).
     *
     * Extracts the cPanel username from the install path (/home/<username>/...).
     *
     * @param string $output Raw CLI output
     * @return array{success: bool, installs?: array, error?: string}
     */
    protected function parseWpToolkitAllOutput(string $output): array
    {
        $trimmed = trim($output);

        if (empty($trimmed)) {
            return ['success' => true, 'installs' => []];
        }

        // Find JSON start
        $jsonStart = null;
        for ($i = 0; $i < strlen($trimmed); $i++) {
            if ($trimmed[$i] === '[' || $trimmed[$i] === '{') {
                $jsonStart = $i;
                break;
            }
        }

        if ($jsonStart === null) {
            if (stripos($trimmed, 'no installation') !== false || stripos($trimmed, 'not found') !== false) {
                return ['success' => true, 'installs' => []];
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

        // Normalize single object to array
        if (isset($decoded['id'])) {
            $decoded = [$decoded];
        }

        $installs = [];
        foreach ($decoded as $item) {
            $path = $item['fullPath'] ?? $item['path'] ?? $item['documentRoot'] ?? null;

            // Extract cPanel username from path: /home/<username>/...
            $cpanelUser = null;
            if ($path && preg_match('#^/home/([^/]+)/#', $path, $m)) {
                $cpanelUser = $m[1];
            }

            $installs[] = [
                'id'          => $item['id'] ?? null,
                'name'        => $item['name'] ?? null,
                'path'        => $path,
                'url'         => $item['siteUrl'] ?? $item['url'] ?? null,
                'login_url'   => $item['loginUrl'] ?? null,
                'version'     => $item['version'] ?? $item['wpVersion'] ?? null,
                'php_version' => $item['phpVersion'] ?? null,
                'status'      => $item['status'] ?? $item['state'] ?? null,
                'auto_update' => $item['autoUpdate'] ?? null,
                'admin_user'  => $item['adminLogin'] ?? $item['admin_login'] ?? $item['adminUser'] ?? $item['admin_user'] ?? null,
                'cpanel_user' => $cpanelUser,
            ];
        }

        return ['success' => true, 'installs' => $installs];
    }

    /**
     * Get all WordPress installs for a specific cPanel account.
     *
     * Connects through the selected WP Toolkit transport and runs `wp-toolkit --list --user <username>` to discover
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

        // Step 1: Connect through WP Toolkit transport
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return $ssh;
        }

        $connection = $ssh['connection'];
        $wptBin = $this->shellBinary($connection, $server);

        // Step 2: Check if wp-toolkit is available
        $checkCmd = "which {$wptBin} 2>/dev/null && echo \"WPT_FOUND\" || echo \"WPT_NOT_FOUND\"";
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
        $cmd = "{$wptBin} --list --user {$escapedUser} -format json 2>&1";

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
                'id'          => $item['id'] ?? null,
                'name'        => $item['name'] ?? null,
                'path'        => $path,
                'url'         => $item['siteUrl'] ?? $item['url'] ?? null,
                'login_url'   => $item['loginUrl'] ?? null,
                'version'     => $item['version'] ?? $item['wpVersion'] ?? null,
                'php_version' => $item['phpVersion'] ?? null,
                'status'      => $item['status'] ?? $item['state'] ?? null,
                'auto_update' => $item['autoUpdate'] ?? null,
                'admin_user'  => $item['adminLogin'] ?? $item['admin_login'] ?? $item['adminUser'] ?? $item['admin_user'] ?? null,
            ];
        }

        return [
            'success'  => true,
            'installs' => $installs,
        ];
    }

}
