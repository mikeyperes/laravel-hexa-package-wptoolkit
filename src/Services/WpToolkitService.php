<?php

namespace hexa_package_wptoolkit\Services;

use hexa_package_whm\Models\WhmServer;
use hexa_package_whm\Services\WhmService;
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
    protected WhmService $whm;

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
     * Get stored login credentials (admin users) for a WordPress install.
     *
     * Runs wp-cli via wp-toolkit to retrieve administrator usernames, emails,
     * and display names. Also returns the loginUrl from WP Toolkit.
     *
     * Note: WordPress hashes passwords — plaintext passwords cannot be retrieved.
     * WP Toolkit's one-click login uses internal session tokens, not stored passwords.
     *
     * @param WhmServer $server    The WHM server to connect to
     * @param int       $installId The WP Toolkit install ID
     * @param string    $wpPath    Full path to the WordPress install
     * @param string    $username  The cPanel username (for fallback wp-cli)
     * @param string|null $loginUrl The loginUrl from WP Toolkit list output
     * @return array{success: bool, admin_users?: array, login_url?: string, raw_output?: string, error?: string}
     */
    public function getCredentials(WhmServer $server, int $installId, string $wpPath, string $username, ?string $loginUrl = null): array
    {
        $this->generic->log('info', '[WpToolkit] getCredentials starting', [
            'server'     => $server->name,
            'install_id' => $installId,
            'wp_path'    => $wpPath,
            'username'   => $username,
        ]);

        $ssh = $this->sshConnect($server);
        if (!$ssh['success']) {
            return $ssh;
        }

        /** @var SSH2 $connection */
        $connection = $ssh['connection'];

        // Try wp-toolkit --wp-cli first, fall back to direct wp-cli
        $escapedId = escapeshellarg((string) $installId);
        $escapedPath = escapeshellarg($wpPath);
        $escapedUser = escapeshellarg($username);

        // Method 1: wp-toolkit --wp-cli (uses install ID)
        $cmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- user list --role=administrator --fields=ID,user_login,user_email,display_name --format=json 2>&1";

        $this->generic->log('info', '[WpToolkit] Trying wp-toolkit --wp-cli', ['command' => $cmd]);
        $output = trim($connection->exec($cmd));

        $adminUsers = $this->parseWpCliUserList($output);

        // Method 2: Direct wp-cli as cPanel user (fallback)
        if ($adminUsers === null) {
            $cmd = "sudo -u {$escapedUser} wp user list --role=administrator --fields=ID,user_login,user_email,display_name --format=json --path={$escapedPath} 2>&1";

            $this->generic->log('info', '[WpToolkit] Fallback to direct wp-cli', ['command' => $cmd]);
            $fallbackOutput = trim($connection->exec($cmd));
            $output .= "\n---FALLBACK---\n" . $fallbackOutput;

            $adminUsers = $this->parseWpCliUserList($fallbackOutput);
        }

        // Method 3: grep wp-config.php for DB creds + query DB directly (last resort)
        if ($adminUsers === null) {
            $cmd = "sudo -u {$escapedUser} php -r \"
                define('ABSPATH', {$escapedPath} . '/');
                require({$escapedPath} . '/wp-config.php');
                \\\$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD);
                \\\$prefix = isset(\\\$table_prefix) ? \\\$table_prefix : 'wp_';
                \\\$stmt = \\\$pdo->query(\\\"SELECT ID, user_login, user_email, display_name FROM \\\" . \\\$prefix . \\\"users u JOIN \\\" . \\\$prefix . \\\"usermeta m ON u.ID = m.user_id WHERE m.meta_key = '\\\" . \\\$prefix . \\\"capabilities' AND m.meta_value LIKE '%administrator%'\\\");
                echo json_encode(\\\$stmt->fetchAll(PDO::FETCH_ASSOC));
            \" 2>&1";

            $this->generic->log('info', '[WpToolkit] Fallback to direct DB query', ['command' => mb_substr($cmd, 0, 200)]);
            $dbOutput = trim($connection->exec($cmd));
            $output .= "\n---DB_FALLBACK---\n" . $dbOutput;

            $adminUsers = $this->parseWpCliUserList($dbOutput);
        }

        $connection->disconnect();

        if ($adminUsers === null) {
            $this->generic->log('error', '[WpToolkit] All methods failed to get admin users', [
                'install_id' => $installId,
                'output'     => mb_substr($output, 0, 500),
            ]);
            return [
                'success'    => false,
                'error'      => 'Could not retrieve admin users. All methods failed.',
                'raw_output' => $output,
            ];
        }

        $this->generic->log('info', '[WpToolkit] getCredentials complete', [
            'install_id'  => $installId,
            'admin_count' => count($adminUsers),
        ]);

        return [
            'success'     => true,
            'admin_users' => $adminUsers,
            'login_url'   => $loginUrl,
            'raw_output'  => $output,
        ];
    }

    /**
     * Parse JSON output from wp-cli `user list --format=json`.
     *
     * @param string $output Raw command output
     * @return array|null Array of admin users or null if parsing failed
     */
    protected function parseWpCliUserList(string $output): ?array
    {
        if (empty($output)) {
            return null;
        }

        // Find JSON array in output
        $jsonStart = strpos($output, '[');
        if ($jsonStart === false) {
            return null;
        }

        $jsonStr = substr($output, $jsonStart);

        // Find matching closing bracket
        $depth = 0;
        $jsonEnd = null;
        for ($i = 0; $i < strlen($jsonStr); $i++) {
            if ($jsonStr[$i] === '[') $depth++;
            if ($jsonStr[$i] === ']') $depth--;
            if ($depth === 0) {
                $jsonEnd = $i + 1;
                break;
            }
        }

        if ($jsonEnd === null) {
            return null;
        }

        $jsonStr = substr($jsonStr, 0, $jsonEnd);
        $decoded = json_decode($jsonStr, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        $users = [];
        foreach ($decoded as $user) {
            $users[] = [
                'id'           => $user['ID'] ?? $user['id'] ?? null,
                'user_login'   => $user['user_login'] ?? null,
                'user_email'   => $user['user_email'] ?? null,
                'display_name' => $user['display_name'] ?? null,
            ];
        }

        return $users;
    }

    /**
     * Generate a one-click WordPress admin login URL.
     *
     * Deploys a temporary mu-plugin that auto-authenticates and self-deletes
     * after use or TTL expiry.
     *
     * @param WhmServer $server    The WHM server
     * @param string    $wpPath    Full path to WordPress install
     * @param string    $username  cPanel username (file owner)
     * @param string    $wpUser    WordPress username to log in as
     * @param string    $siteUrl   The site URL for building the login link
     * @return array{success: bool, url?: string, expires_in?: int, error?: string}
     */
    public function generateWordPressLoginUrl(WhmServer $server, string $wpPath, string $username, string $wpUser, string $siteUrl): array
    {
        $this->generic->log('info', '[WpToolkit] generateWordPressLoginUrl', [
            'server'  => $server->name,
            'wp_path' => $wpPath,
            'wp_user' => $wpUser,
        ]);

        $ssh = $this->sshConnect($server);
        if (!$ssh['success']) {
            return $ssh;
        }

        /** @var SSH2 $connection */
        $connection = $ssh['connection'];

        $token = bin2hex(random_bytes(32));
        $ttl = 300; // 5 minutes
        $expiry = time() + $ttl;

        $muPlugin = <<<'PHP'
<?php
/**
 * HWS Auto-Login — temporary mu-plugin, self-deletes after use or expiry.
 */
if (!defined('ABSPATH')) exit;
add_action('init', function() {
    if (empty($_GET['hws_login_token'])) return;
    $token = '__TOKEN__';
    $expiry = __EXPIRY__;
    $wp_user = '__WP_USER__';
    if ($_GET['hws_login_token'] !== $token || time() > $expiry) {
        @unlink(__FILE__);
        wp_die('Login link expired or invalid.');
    }
    $user = get_user_by('login', $wp_user);
    if (!$user) { @unlink(__FILE__); wp_die('User not found.'); }
    wp_set_auth_cookie($user->ID, true);
    wp_set_current_user($user->ID);
    @unlink(__FILE__);
    wp_safe_redirect(admin_url());
    exit;
}, 1);
if (time() > __EXPIRY__) { @unlink(__FILE__); }
PHP;

        $muPlugin = str_replace('__TOKEN__', $token, $muPlugin);
        $muPlugin = str_replace('__EXPIRY__', (string) $expiry, $muPlugin);
        $muPlugin = str_replace('__WP_USER__', addslashes($wpUser), $muPlugin);

        $b64 = base64_encode($muPlugin);
        $escapedPath = escapeshellarg($wpPath);
        $escapedUser = escapeshellarg($username);
        $muDir = $wpPath . '/wp-content/mu-plugins';
        $filePath = $muDir . '/hws-auto-login.php';
        $escapedFile = escapeshellarg($filePath);

        $cmd = "mkdir -p " . escapeshellarg($muDir) . " && echo '{$b64}' | base64 -d > {$escapedFile} && chown {$escapedUser}:{$escapedUser} {$escapedFile} && chmod 644 {$escapedFile} && echo 'MU_PLUGIN_OK'";

        $output = trim($connection->exec($cmd));
        $connection->disconnect();

        if (!str_contains($output, 'MU_PLUGIN_OK')) {
            return [
                'success' => false,
                'error'   => 'Failed to write mu-plugin: ' . mb_substr($output, 0, 300),
            ];
        }

        $loginUrl = rtrim($siteUrl, '/') . '/wp-login.php?hws_login_token=' . $token;

        $this->generic->log('info', '[WpToolkit] WordPress login URL generated', [
            'site' => $siteUrl, 'wp_user' => $wpUser, 'ttl' => $ttl,
        ]);

        return [
            'success'    => true,
            'url'        => $loginUrl,
            'expires_in' => $ttl,
        ];
    }

    /**
     * Generate a one-click cPanel login URL for a cPanel account.
     *
     * Uses the WHM API create_user_session via the billing WhmService.
     *
     * @param WhmServer $server   The WHM server
     * @param string    $username The cPanel username
     * @return array{success: bool, url?: string, error?: string}
     */
    public function generateCpanelLoginUrl(WhmServer $server, string $username): array
    {
        $this->generic->log('info', '[WpToolkit] generateCpanelLoginUrl', [
            'server' => $server->name, 'username' => $username,
        ]);

        return $this->whm->createCpanelSession($server, $username);
    }

    /**
     * Generate a one-click WHM reseller login URL.
     *
     * Uses the WHM API create_user_session with service=whostmgrd
     * via the billing WhmService.
     *
     * @param WhmServer $server   The WHM server
     * @param string    $username The reseller username
     * @return array{success: bool, url?: string, error?: string}
     */
    public function generateWhmResellerLoginUrl(WhmServer $server, string $username): array
    {
        $this->generic->log('info', '[WpToolkit] generateWhmResellerLoginUrl', [
            'server' => $server->name, 'username' => $username,
        ]);

        return $this->whm->createWhmSession($server, $username);
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
