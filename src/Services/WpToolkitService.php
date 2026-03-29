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
     * Get ALL WordPress installs on a WHM server (all accounts).
     *
     * Connects via SSH and runs `wp-toolkit --list -format json` without
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

        $ssh = $this->sshConnect($server);
        if (!$ssh['success']) {
            return $ssh;
        }

        /** @var SSH2 $connection */
        $connection = $ssh['connection'];

        // Check if wp-toolkit is available
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

        // Get ALL installs (no user filter)
        $cmd = "wp-toolkit --list -format json 2>&1";

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

        $userFields = 'ID,user_login,user_email,display_name,roles,user_registered';

        // Method 1: wp-toolkit --wp-cli (uses install ID)
        $cmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- user list --fields={$userFields} --format=json 2>&1";

        $this->generic->log('info', '[WpToolkit] Trying wp-toolkit --wp-cli', ['command' => $cmd]);
        $output = trim($connection->exec($cmd));

        $adminUsers = $this->parseWpCliUserList($output);

        // Method 2: Direct wp-cli as cPanel user (fallback)
        if ($adminUsers === null) {
            $cmd = "sudo -u {$escapedUser} wp user list --fields={$userFields} --format=json --path={$escapedPath} 2>&1";

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

        // Get stored credentials, DB creds, and login URL from WP Toolkit
        $storedCreds = $this->getStoredCredentials($connection, $installId, $wpPath, $username);
        $output .= "\n---STORED_CREDS---\n" . ($storedCreds['raw'] ?? '');

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

        // Merge stored password into admin_users array so the view doesn't need fragile username matching
        $storedCredentials = $storedCreds['credentials'] ?? null;
        if ($storedCredentials && $storedCredentials['username'] && $adminUsers) {
            $storedUsername = $storedCredentials['username'];
            $matched = false;
            foreach ($adminUsers as &$u) {
                $uLogin = $u['user_login'] ?? $u['username'] ?? null;
                if ($uLogin && strtolower($uLogin) === strtolower($storedUsername)) {
                    $u['stored_password'] = $storedCredentials['password'] ?? null;
                    $u['is_default_login'] = true;
                    $matched = true;
                }
            }
            unset($u);

            // If stored username wasn't found in user list, attach to first admin user as fallback
            if (!$matched && !empty($adminUsers)) {
                $adminUsers[0]['stored_password'] = $storedCredentials['password'] ?? null;
                $adminUsers[0]['stored_login_username'] = $storedUsername;
                $adminUsers[0]['is_default_login'] = true;
            }

        }

        // Sort: default login first, then admins by name, then others by name
        if ($adminUsers) {
            usort($adminUsers, function ($a, $b) {
                $aDefault = ($a['is_default_login'] ?? false) ? 1 : 0;
                $bDefault = ($b['is_default_login'] ?? false) ? 1 : 0;
                if ($aDefault !== $bDefault) return $bDefault <=> $aDefault;

                $aRole = strtolower($a['role'] ?? $a['roles'] ?? '');
                $bRole = strtolower($b['role'] ?? $b['roles'] ?? '');
                $aAdmin = str_contains($aRole, 'admin') ? 1 : 0;
                $bAdmin = str_contains($bRole, 'admin') ? 1 : 0;
                if ($aAdmin !== $bAdmin) return $bAdmin <=> $aAdmin;

                $aName = strtolower($a['user_login'] ?? $a['username'] ?? '');
                $bName = strtolower($b['user_login'] ?? $b['username'] ?? '');
                return $aName <=> $bName;
            });
        }

        $this->generic->log('info', '[WpToolkit] getCredentials complete', [
            'install_id'         => $installId,
            'admin_count'        => count($adminUsers),
            'stored_credentials' => $storedCredentials ? ['username' => $storedCredentials['username'], 'has_password' => !empty($storedCredentials['password'])] : null,
        ]);

        return [
            'success'            => true,
            'admin_users'        => $adminUsers,
            'login_url'          => $loginUrl,
            'login_info'         => $storedCreds['login_info'] ?? null,
            'stored_credentials' => $storedCredentials,
            'db_credentials'     => $storedCreds['db'] ?? null,
            'raw_output'         => $output,
            'debug_stored_creds' => $storedCreds['raw'] ?? null,
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
                'id'              => $user['ID'] ?? $user['id'] ?? null,
                'user_login'      => $user['user_login'] ?? null,
                'user_email'      => $user['user_email'] ?? null,
                'display_name'    => $user['display_name'] ?? null,
                'roles'           => $user['roles'] ?? null,
                'user_registered' => $user['user_registered'] ?? null,
            ];
        }

        return $users;
    }

    /**
     * Reset a WordPress user's password via wp-cli.
     *
     * Generates a random password, sets it via wp-toolkit --wp-cli or
     * direct wp-cli fallback, and returns the new plaintext password.
     *
     * @param WhmServer $server   The WHM server
     * @param int       $installId WP Toolkit install ID
     * @param string    $wpPath   Full path to WordPress install
     * @param string    $username cPanel username
     * @param string    $wpUser   WordPress username to reset
     * @param string|null $password User-provided password (generated server-side if null)
     * @return array{success: bool, password?: string, wp_user?: string, error?: string}
     */
    public function resetWordPressPassword(WhmServer $server, int $installId, string $wpPath, string $username, string $wpUser, ?string $password = null): array
    {
        $this->generic->log('info', '[WpToolkit] resetWordPressPassword starting', [
            'server'     => $server->name,
            'install_id' => $installId,
            'wp_path'    => $wpPath,
            'wp_user'    => $wpUser,
        ]);

        $ssh = $this->sshConnect($server);
        if (!$ssh['success']) {
            return $ssh;
        }

        /** @var SSH2 $connection */
        $connection = $ssh['connection'];

        // Use user-provided password or generate a random one
        $newPassword = $password ?: bin2hex(random_bytes(12));

        $escapedId = escapeshellarg((string) $installId);
        $escapedPath = escapeshellarg($wpPath);
        $escapedUser = escapeshellarg($username);
        $escapedWpUser = escapeshellarg($wpUser);
        $escapedPass = escapeshellarg($newPassword);

        // Method 1: wp-toolkit --wp-cli
        $cmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- user update {$escapedWpUser} --user_pass={$escapedPass} 2>&1";
        $this->generic->log('info', '[WpToolkit] Trying password reset via wp-toolkit', ['command' => $cmd]);
        $output = trim($connection->exec($cmd));
        $success = str_contains($output, 'Success');

        if (!$success) {
            // Method 2: Direct wp-cli as cPanel user
            $cmd = "sudo -u {$escapedUser} wp user update {$escapedWpUser} --user_pass={$escapedPass} --path={$escapedPath} 2>&1";
            $this->generic->log('info', '[WpToolkit] Fallback to direct wp-cli', ['command' => $cmd]);
            $fallbackOutput = trim($connection->exec($cmd));
            $output .= "\n---FALLBACK---\n" . $fallbackOutput;
            $success = str_contains($fallbackOutput, 'Success');
        }

        if ($success) {
            // Update WP Toolkit's stored credentials in SQLite so rescan shows the new password
            $sqliteDb = '/usr/local/cpanel/3rdparty/wp-toolkit/var/wp-toolkit.sqlite3';
            $escapedNewPass = escapeshellarg($newPassword);
            $connection->exec("sqlite3 {$sqliteDb} \"UPDATE InstanceProperties SET value = {$escapedNewPass} WHERE instanceId = {$escapedId} AND name = 'password'\" 2>&1");
            $connection->exec("sqlite3 {$sqliteDb} \"UPDATE InstanceProperties SET value = {$escapedWpUser} WHERE instanceId = {$escapedId} AND name = 'login'\" 2>&1");
            $this->generic->log('info', '[WpToolkit] Updated SQLite stored credentials', ['install_id' => $installId, 'wp_user' => $wpUser]);

            $connection->disconnect();
            return [
                'success'  => true,
                'password' => $newPassword,
                'wp_user'  => $wpUser,
            ];
        }

        $connection->disconnect();

        $this->generic->log('error', '[WpToolkit] Password reset failed', [
            'output' => mb_substr($output, 0, 500),
        ]);

        return [
            'success'    => false,
            'error'      => 'Password reset failed. Output: ' . mb_substr($output, 0, 300),
            'raw_output' => $output,
        ];
    }

    /**
     * Test a WordPress user's password by verifying it against the database via wp-cli.
     *
     * @param WhmServer $server    The WHM server
     * @param int       $installId WP Toolkit install ID
     * @param string    $wpPath    Full path to WordPress install
     * @param string    $username  cPanel username
     * @param string    $wpUser    WordPress username to test
     * @param string    $password  Password to verify
     * @return array{success: bool, steps: array, error?: string}
     */
    public function testWordPressPassword(WhmServer $server, int $installId, string $wpPath, string $username, string $wpUser, string $password): array
    {
        $steps = [];
        $ts = fn() => now()->format('H:i:s');

        $steps[] = ['time' => $ts(), 'message' => 'Connecting to ' . $server->name . '...', 'status' => 'info'];

        $ssh = $this->sshConnect($server);
        if (!$ssh['success']) {
            $steps[] = ['time' => $ts(), 'message' => 'SSH connection failed: ' . ($ssh['error'] ?? 'Unknown'), 'status' => 'error'];
            return ['success' => false, 'steps' => $steps, 'error' => $ssh['error'] ?? 'SSH failed'];
        }

        /** @var \phpseclib3\Net\SSH2 $connection */
        $connection = $ssh['connection'];
        $steps[] = ['time' => $ts(), 'message' => 'SSH connected', 'status' => 'ok'];

        $escapedPass = addslashes($password);
        $escapedWpUser = addslashes($wpUser);
        $phpSnippet = "echo wp_check_password('{$escapedPass}', get_user_by('login', '{$escapedWpUser}')->user_pass) ? 'WPTK_PASS_OK' : 'WPTK_PASS_FAIL';";

        $escapedId = escapeshellarg((string) $installId);
        $escapedPath = escapeshellarg($wpPath);
        $escapedUser = escapeshellarg($username);
        $escapedPhp = escapeshellarg($phpSnippet);

        // Method 1: wp-toolkit --wp-cli
        $steps[] = ['time' => $ts(), 'message' => 'Verifying credentials for ' . $wpUser . '...', 'status' => 'info'];
        $cmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- eval {$escapedPhp} 2>&1";
        $output = trim($connection->exec($cmd));

        if (str_contains($output, 'WPTK_PASS_OK')) {
            $connection->disconnect();
            $steps[] = ['time' => $ts(), 'message' => 'Password verified successfully!', 'status' => 'ok'];
            return ['success' => true, 'steps' => $steps];
        }

        if (str_contains($output, 'WPTK_PASS_FAIL')) {
            $connection->disconnect();
            $steps[] = ['time' => $ts(), 'message' => 'Password verification FAILED — wrong password', 'status' => 'error'];
            return ['success' => false, 'steps' => $steps, 'error' => 'Password does not match'];
        }

        // Method 2: Direct wp-cli fallback
        $steps[] = ['time' => $ts(), 'message' => 'wp-toolkit failed, trying direct wp-cli...', 'status' => 'info'];
        $cmd = "sudo -u {$escapedUser} wp eval {$escapedPhp} --path={$escapedPath} 2>&1";
        $fallbackOutput = trim($connection->exec($cmd));
        $connection->disconnect();

        if (str_contains($fallbackOutput, 'WPTK_PASS_OK')) {
            $steps[] = ['time' => $ts(), 'message' => 'Password verified successfully!', 'status' => 'ok'];
            return ['success' => true, 'steps' => $steps];
        }

        if (str_contains($fallbackOutput, 'WPTK_PASS_FAIL')) {
            $steps[] = ['time' => $ts(), 'message' => 'Password verification FAILED — wrong password', 'status' => 'error'];
            return ['success' => false, 'steps' => $steps, 'error' => 'Password does not match'];
        }

        $steps[] = ['time' => $ts(), 'message' => 'Could not verify: ' . mb_substr($output . ' | ' . $fallbackOutput, 0, 200), 'status' => 'error'];
        return ['success' => false, 'steps' => $steps, 'error' => 'Verification command failed'];
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

        // Use site root URL — the mu-plugin hooks into 'init' which fires on ANY page
        // Do NOT use /wp-login.php since custom login URLs (e.g. /hexa-admin/) may block it
        $loginUrl = rtrim($siteUrl, '/') . '/?hws_login_token=' . $token;

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
     * Extract a JSON object or array from a string that may contain non-JSON text.
     *
     * Finds the first { or [ and its matching closing bracket, then decodes.
     *
     * @param string $output Raw output that may contain JSON
     * @return array|null Decoded JSON as associative array, or null on failure
     */
    protected function extractJsonObject(string $output): ?array
    {
        $trimmed = trim($output);
        if (empty($trimmed)) return null;

        // Find first { or [
        $startChar = null;
        $jsonStart = null;
        for ($i = 0; $i < strlen($trimmed); $i++) {
            if ($trimmed[$i] === '{' || $trimmed[$i] === '[') {
                $startChar = $trimmed[$i];
                $jsonStart = $i;
                break;
            }
        }

        if ($jsonStart === null) return null;

        $endChar = $startChar === '{' ? '}' : ']';

        // Find matching closing bracket
        $depth = 0;
        $jsonEnd = null;
        $inString = false;
        $escape = false;
        for ($i = $jsonStart; $i < strlen($trimmed); $i++) {
            $c = $trimmed[$i];
            if ($escape) { $escape = false; continue; }
            if ($c === '\\' && $inString) { $escape = true; continue; }
            if ($c === '"') { $inString = !$inString; continue; }
            if ($inString) continue;
            if ($c === $startChar) $depth++;
            if ($c === $endChar) $depth--;
            if ($depth === 0) { $jsonEnd = $i + 1; break; }
        }

        if ($jsonEnd === null) return null;

        $jsonStr = substr($trimmed, $jsonStart, $jsonEnd - $jsonStart);
        $decoded = json_decode($jsonStr, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        // Normalize: if array of objects, return first one
        if (isset($decoded[0]) && is_array($decoded[0])) {
            return $decoded[0];
        }

        return $decoded;
    }

    /**
     * Get stored credentials from WP Toolkit for a WordPress install.
     *
     * WP Toolkit stores the raw admin username and password when it creates
     * the install. This retrieves them via `wp-toolkit --info`.
     * Also reads DB credentials from wp-config.php.
     *
     * @param SSH2   $connection Active SSH connection
     * @param int    $installId  WP Toolkit install ID
     * @param string $wpPath     Full path to WordPress install
     * @param string $username   cPanel username
     * @return array{credentials: array|null, db: array|null, raw: string}
     */
    protected function getStoredCredentials(SSH2 $connection, int $installId, string $wpPath, string $username): array
    {
        $escapedId = escapeshellarg((string) $installId);
        $escapedPath = escapeshellarg($wpPath);
        $escapedUser = escapeshellarg($username);
        $raw = '';
        $credentials = null;
        $dbCreds = null;
        $loginInfo = null;

        // Method 1: Query WP Toolkit SQLite database directly for stored credentials
        // The CLI (wp-toolkit --info) does NOT expose admin login/password — they're only in the SQLite DB
        $sqliteDb = '/usr/local/cpanel/3rdparty/wp-toolkit/var/wp-toolkit.sqlite3';
        $cmd = "sqlite3 {$sqliteDb} \"SELECT name, value FROM InstanceProperties WHERE instanceId = {$installId} AND (name = 'login' OR name = 'password' OR name = 'adminLoginLink' OR name = 'admin_email')\" 2>&1";
        $sqliteOutput = trim($connection->exec($cmd));
        $raw .= "sqlite3 InstanceProperties: " . $sqliteOutput . "\n";

        $this->generic->log('info', '[WpToolkit] SQLite InstanceProperties', [
            'install_id' => $installId,
            'output'     => $sqliteOutput,
        ]);

        $adminLogin = null;
        $adminPass = null;
        $adminEmail = null;
        $adminLoginLink = null;

        if ($sqliteOutput) {
            foreach (explode("\n", $sqliteOutput) as $line) {
                $parts = explode('|', $line, 2);
                if (count($parts) === 2) {
                    $name = trim($parts[0]);
                    $value = trim($parts[1]);
                    if ($name === 'login') $adminLogin = $value;
                    if ($name === 'password') $adminPass = $value;
                    if ($name === 'admin_email') $adminEmail = $value;
                    if ($name === 'adminLoginLink') $adminLoginLink = $value;
                }
            }
        }

        // Password is AES-256-GCM encrypted in SQLite — we cannot decrypt it
        // Use wp-toolkit --site-admin-reset-password to get a plaintext password
        $passwordEncrypted = false;
        if ($adminPass && str_starts_with($adminPass, '$aes-256-gcm$')) {
            $passwordEncrypted = true;
            $adminPass = null;

            // Reset password via WP Toolkit CLI to get plaintext
            if ($adminLogin) {
                $escapedLogin = escapeshellarg($adminLogin);
                $resetCmd = "wp-toolkit --site-admin-reset-password -instance-id {$escapedId} -admin-login {$escapedLogin} 2>&1";
                $resetOutput = trim($connection->exec($resetCmd));
                $raw .= "RESET_PASSWORD: " . $resetOutput . "\n";

                $this->generic->log('info', '[WpToolkit] Password reset via CLI', [
                    'install_id' => $installId,
                    'login'      => $adminLogin,
                    'output'     => $resetOutput,
                ]);

                // Parse: "  login     hexa-pr-wire\n  password  c7^!t!VHio3OC4_4"
                if (preg_match('/password\s+(.+)$/m', $resetOutput, $m)) {
                    $adminPass = trim($m[1]);
                    $passwordEncrypted = false;
                }
            }
        }

        $raw .= "SQLITE_CREDS: login=" . ($adminLogin ?? 'NULL') . " pass=" . ($adminPass ? 'YES(' . strlen($adminPass) . ')' : ($passwordEncrypted ? 'ENCRYPTED' : 'NULL')) . "\n";

        if ($adminLogin) {
            $credentials = [
                'username'           => $adminLogin,
                'password'           => $adminPass,
                'email'              => $adminEmail,
                'password_encrypted' => $passwordEncrypted,
            ];
        }

        // Method 2: wp-toolkit --info JSON for loginUrl and siteUrl
        $cmd = "wp-toolkit --info -instance-id {$escapedId} -format json 2>&1";
        $output = trim($connection->exec($cmd));

        if ($output) {
            $info = $this->extractJsonObject($output);
            if ($info) {
                $siteUrl = $info['siteUrl'] ?? null;
                $wptLoginUrl = $info['loginUrl'] ?? null;

                if (!$adminEmail && isset($info['admin_email'])) {
                    $adminEmail = $info['admin_email'];
                    if ($credentials) $credentials['email'] = $adminEmail;
                }

                if ($wptLoginUrl) {
                    $defaultLogin = $siteUrl ? rtrim($siteUrl, '/') . '/wp-login.php' : null;
                    $defaultAdmin = $siteUrl ? rtrim($siteUrl, '/') . '/wp-admin/' : null;

                    $isModified = true;
                    if ($defaultLogin && $wptLoginUrl === $defaultLogin) $isModified = false;
                    if ($defaultAdmin && $wptLoginUrl === $defaultAdmin) $isModified = false;

                    $loginInfo = [
                        'url'         => $wptLoginUrl,
                        'is_modified' => $isModified,
                        'default_url' => $defaultLogin,
                    ];
                }
            }
        }

        // Method 2: Read DB credentials from wp-config.php
        $cmd = "sudo -u {$escapedUser} grep -E \"^define\\(\\s*'(DB_NAME|DB_USER|DB_PASSWORD|DB_HOST)'\" {$escapedPath}/wp-config.php 2>&1";
        $dbOutput = trim($connection->exec($cmd));
        $raw .= "wp-config.php DB: " . $dbOutput . "\n";

        if ($dbOutput && !str_contains($dbOutput, 'No such file')) {
            $dbCreds = [];
            foreach (explode("\n", $dbOutput) as $line) {
                if (preg_match("/define\(\s*'(DB_NAME|DB_USER|DB_PASSWORD|DB_HOST)'\s*,\s*'([^']*)'\s*\)/", $line, $m)) {
                    $dbCreds[strtolower($m[1])] = $m[2];
                }
            }
            if (empty($dbCreds)) {
                $dbCreds = null;
            }
        }

        return [
            'credentials' => $credentials,
            'db'          => $dbCreds,
            'login_info'  => $loginInfo,
            'raw'         => $raw,
        ];
    }

    /**
     * Detect if a WordPress install has a custom login URL.
     *
     * Checks for common plugins that modify the wp-admin URL:
     * - WPS Hide Login (whl_page option)
     * - iThemes Security (itsec-storage → hide-backend)
     * - All In One WP Security (aio_wp_security_configs → rename-login-page)
     *
     * @param SSH2   $connection Active SSH connection
     * @param int    $installId  WP Toolkit install ID
     * @param string $wpPath     Full path to WordPress install
     * @param string $username   cPanel username
     * @return array{url: string|null, raw: string}
     */
    protected function detectCustomLoginUrl(SSH2 $connection, int $installId, string $wpPath, string $username): array
    {
        $escapedId = escapeshellarg((string) $installId);
        $escapedPath = escapeshellarg($wpPath);
        $escapedUser = escapeshellarg($username);
        $raw = '';

        // Check WPS Hide Login (most common)
        $cmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- option get whl_page 2>&1";
        $output = trim($connection->exec($cmd));
        $raw .= "whl_page: {$output}\n";

        if ($this->isValidLoginSlug($output)) {
            return ['url' => $output, 'raw' => $raw];
        }

        // Fallback: direct wp-cli
        $cmd = "sudo -u {$escapedUser} wp option get whl_page --path={$escapedPath} 2>&1";
        $output = trim($connection->exec($cmd));
        $raw .= "whl_page (direct): {$output}\n";

        if ($this->isValidLoginSlug($output)) {
            return ['url' => $output, 'raw' => $raw];
        }

        // Check iThemes Security
        $cmd = "sudo -u {$escapedUser} wp option get itsec-storage --format=json --path={$escapedPath} 2>&1";
        $itsecOutput = trim($connection->exec($cmd));
        $raw .= "itsec-storage: " . mb_substr($itsecOutput, 0, 300) . "\n";

        if ($itsecOutput && $this->isValidLoginSlug($itsecOutput, false)) {
            $itsec = json_decode($itsecOutput, true);
            $hideBackend = $itsec['hide-backend']['slug'] ?? null;
            if ($hideBackend && $this->isValidLoginSlug($hideBackend)) {
                return ['url' => $hideBackend, 'raw' => $raw];
            }
        }

        // Check All In One WP Security
        $cmd = "sudo -u {$escapedUser} wp option get aio_wp_security_configs --format=json --path={$escapedPath} 2>&1";
        $aioOutput = trim($connection->exec($cmd));
        $raw .= "aio_wp_security: " . mb_substr($aioOutput, 0, 300) . "\n";

        if ($aioOutput && $this->isValidLoginSlug($aioOutput, false)) {
            $aio = json_decode($aioOutput, true);
            $renamePage = $aio['aiowps_login_page_slug'] ?? null;
            if ($renamePage && $this->isValidLoginSlug($renamePage)) {
                return ['url' => $renamePage, 'raw' => $raw];
            }
        }

        return ['url' => null, 'raw' => $raw];
    }

    /**
     * Check if a string looks like a valid login URL slug (not an error message).
     *
     * A valid slug is short, alphanumeric with hyphens/underscores, and contains
     * no shell error patterns. This prevents error output like "sudo: wp: command
     * not found" from being treated as a URL slug.
     *
     * @param string $value      The value to check
     * @param bool   $checkFormat Whether to also validate slug format (default true)
     * @return bool
     */
    protected function isValidLoginSlug(string $value, bool $checkFormat = true): bool
    {
        if (empty($value)) {
            return false;
        }

        // Reject any output containing error indicators
        $errorPatterns = [
            'error', 'Error', 'ERROR',
            'not found', 'not exist', 'No such',
            'Could not', 'command not found',
            'Permission denied', 'sudo:',
            'Warning:', 'Fatal', 'fatal',
            'undefined', 'Invalid',
            'Cannot', 'unable',
        ];

        foreach ($errorPatterns as $pattern) {
            if (str_contains($value, $pattern)) {
                return false;
            }
        }

        // If checking format, slug must be a simple path segment (alphanumeric, hyphens, underscores)
        if ($checkFormat && !preg_match('/^[a-zA-Z0-9\-_]+$/', $value)) {
            return false;
        }

        return true;
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
