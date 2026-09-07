<?php

namespace hexa_package_wptoolkit\Services\Concerns;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use phpseclib3\Net\SSH2;

/**
 * ManagesCredentials — WordPress credential retrieval and password management.
 */
trait ManagesCredentials
{
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
     * @return array{success: bool, admin_users?: array, login_url?: string, error?: string}
     */
    public function getCredentials(WhmServer $server, int $installId, string $wpPath, string $username, ?string $loginUrl = null): array
    {
        $this->generic->log('info', '[WpToolkit] getCredentials starting', [
            'server'     => $server->name,
            'install_id' => $installId,
            'wp_path'    => $wpPath,
            'username'   => $username,
        ]);

        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return $ssh;
        }

        $connection = $ssh['connection'];
        $wptBin = $this->shellBinary($connection, $server);

        // Try wp-toolkit --wp-cli first, fall back to direct wp-cli
        $escapedId = escapeshellarg((string) $installId);
        $escapedPath = escapeshellarg($wpPath);
        $escapedUser = escapeshellarg($username);

        $adminUserEval = $this->buildAdministratorUserEval();
        $encodedEval = base64_encode($adminUserEval);
        $decodeEval = "CODE=\$(echo " . escapeshellarg($encodedEval) . " | base64 -d)";

        // Method 1: wp-toolkit --wp-cli (uses install ID) with cache_results=false to bypass
        // poisoned persistent object-cache entries for WP_User_Query.
        $cmd = "{$decodeEval} && {$wptBin} --wp-cli -instance-id {$escapedId} -- eval \"\$CODE\" 2>&1";

        $this->generic->log('info', '[WpToolkit] Trying wp-toolkit --wp-cli', ['command' => $cmd]);
        $output = trim($connection->exec($cmd));

        $adminUsers = $this->parseWpCliUserList($output);

        // Method 2: Direct wp-cli as cPanel user (fallback)
        if ($adminUsers === null) {
            $cmd = "{$decodeEval} && sudo -u {$escapedUser} wp eval \"\$CODE\" --path={$escapedPath} 2>&1";

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
        $storedCreds = $this->getStoredCredentials($server, $connection, $installId, $wpPath, $username);

        $connection->disconnect();

        if ($adminUsers === null) {
            $this->generic->log('error', '[WpToolkit] All methods failed to get admin users', [
                'install_id' => $installId,
                'output_length' => strlen($output),
            ]);
            return [
                'success' => false,
                'error' => 'Could not retrieve administrator users.',
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

            // Sort: default login user always first
            usort($adminUsers, function ($a, $b) {
                return ($b['is_default_login'] ?? false) <=> ($a['is_default_login'] ?? false);
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

    protected function buildAdministratorUserEval(): string
    {
        return <<<'PHP'
if (function_exists('wp_suspend_cache_addition')) {
    wp_suspend_cache_addition(true);
}

$users = get_users([
    'role' => 'administrator',
    'orderby' => 'ID',
    'order' => 'ASC',
    'count_total' => false,
    'cache_results' => false,
    'fields' => 'all_with_meta',
]);

$payload = [];
foreach ($users as $user) {
    if (!$user instanceof WP_User) {
        continue;
    }

    $payload[] = [
        'ID' => (int) $user->ID,
        'user_login' => (string) $user->user_login,
        'user_email' => (string) $user->user_email,
        'display_name' => (string) $user->display_name,
        'roles' => array_values((array) $user->roles),
        'user_registered' => (string) $user->user_registered,
    ];
}

if (function_exists('wp_suspend_cache_addition')) {
    wp_suspend_cache_addition(false);
}

echo wp_json_encode($payload);
PHP;
    }

    /**
     * Reset a WordPress user's password via wp-cli.
     *
     * Uses the requested password when provided, otherwise generates one, sets
     * it via wp-toolkit --wp-cli or direct wp-cli, and returns it once.
     *
     * @param WhmServer $server   The WHM server
     * @param int       $installId WP Toolkit install ID
     * @param string    $wpPath   Full path to WordPress install
     * @param string    $username cPanel username
     * @param string    $wpUser   WordPress username to reset
     * @return array{success: bool, password?: string, wp_user?: string, error?: string}
     */
    public function resetWordPressPassword(WhmServer $server, int $installId, string $wpPath, string $username, string $wpUser, ?string $requestedPassword = null): array
    {
        $this->generic->log('info', '[WpToolkit] resetWordPressPassword starting', [
            'server'     => $server->name,
            'install_id' => $installId,
            'wp_path'    => $wpPath,
            'wp_user'    => $wpUser,
        ]);

        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return $ssh;
        }

        $connection = $ssh['connection'];
        $wptBin = $this->shellBinary($connection, $server);

        $newPassword = $requestedPassword !== null && $requestedPassword !== ''
            ? $requestedPassword
            : bin2hex(random_bytes(12));

        $escapedId = escapeshellarg((string) $installId);
        $escapedPath = escapeshellarg($wpPath);
        $escapedUser = escapeshellarg($username);
        $escapedWpUser = escapeshellarg($wpUser);
        $escapedPass = escapeshellarg($newPassword);

        // Method 1: wp-toolkit --wp-cli
        $cmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- user update {$escapedWpUser} --user_pass={$escapedPass} 2>&1";
        $this->generic->log('info', '[WpToolkit] Trying password reset via wp-toolkit', [
            'install_id' => $installId,
            'wp_user' => $wpUser,
        ]);
        $output = trim($connection->exec($cmd));

        if (str_contains($output, 'Success')) {
            $connection->disconnect();
            $this->generic->log('info', '[WpToolkit] Password reset success via wp-toolkit');
            return [
                'success'  => true,
                'password' => $newPassword,
                'wp_user'  => $wpUser,
            ];
        }

        // Method 2: Direct wp-cli as cPanel user
        $cmd = "sudo -u {$escapedUser} wp user update {$escapedWpUser} --user_pass={$escapedPass} --path={$escapedPath} 2>&1";
        $this->generic->log('info', '[WpToolkit] Fallback to direct wp-cli', [
            'install_id' => $installId,
            'wp_user' => $wpUser,
        ]);
        $fallbackOutput = trim($connection->exec($cmd));
        $output .= "\n---FALLBACK---\n" . $fallbackOutput;

        $connection->disconnect();

        if (str_contains($fallbackOutput, 'Success')) {
            $this->generic->log('info', '[WpToolkit] Password reset success via direct wp-cli');
            return [
                'success'  => true,
                'password' => $newPassword,
                'wp_user'  => $wpUser,
            ];
        }

        $this->generic->log('error', '[WpToolkit] Password reset failed', [
            'install_id' => $installId,
            'output_length' => strlen($output),
        ]);

        return [
            'success'    => false,
            'error'      => 'Password reset failed.',
        ];
    }

    /**
     * Verify a WordPress password without returning or logging command output.
     */
    public function testWordPressPassword(WhmServer $server, int $installId, string $wpPath, string $username, string $wpUser, string $password): array
    {
        $ssh = $this->getConnection($server);
        if (! ($ssh['success'] ?? false)) {
            return ['success' => false, 'error' => 'Unable to connect to the WordPress host.'];
        }

        $connection = $ssh['connection'];
        $command = $this->shellBinary($connection, $server)
            .' --wp-cli -instance-id '.escapeshellarg((string) $installId)
            .' -- user check-password '.escapeshellarg($wpUser).' '.escapeshellarg($password).' 2>&1';

        try {
            $result = $this->runCommandWithExitCode($connection, $command);
        } finally {
            $this->disconnectCachedConnection($server, $connection);
        }

        $valid = ($result['exit_code'] ?? 1) === 0;

        return [
            'success' => $valid,
            'steps' => [[
                'time' => now()->toIso8601String(),
                'message' => $valid ? 'WordPress accepted the password.' : 'WordPress rejected the password.',
                'status' => $valid ? 'ok' : 'error',
            ]],
            ...($valid ? [] : ['error' => 'WordPress rejected the password.']),
        ];
    }

    /**
     * Get stored credentials from WP Toolkit for a WordPress install.
     *
     * WP Toolkit stores the raw admin username and password when it creates
     * the install. This retrieves them via `wp-toolkit --info`.
     * Also reads DB credentials from wp-config.php.
     *
     * @param SSH2|LocalShellConnection   $connection Active command connection
     * @param int    $installId  WP Toolkit install ID
     * @param string $wpPath     Full path to WordPress install
     * @param string $username   cPanel username
     * @return array{credentials: array|null, db: array|null, login_info: array|null}
     */
    protected function getStoredCredentials(WhmServer $server, SSH2|LocalShellConnection $connection, int $installId, string $wpPath, string $username): array
    {
        $escapedId = escapeshellarg((string) $installId);
        $escapedPath = escapeshellarg($wpPath);
        $escapedUser = escapeshellarg($username);
        $wptBin = $this->shellBinary($connection, $server);
        $credentials = null;
        $dbCreds = null;
        $loginInfo = null;

        // Method 1: Query WP Toolkit SQLite database directly for stored credentials
        // The CLI (wp-toolkit --info) does NOT expose admin login/password — they're only in the SQLite DB
        $sqliteDb = '/usr/local/cpanel/3rdparty/wp-toolkit/var/wp-toolkit.sqlite3';
        $cmd = "sqlite3 {$sqliteDb} \"SELECT name, value FROM InstanceProperties WHERE instanceId = {$installId} AND (name = 'login' OR name = 'password' OR name = 'adminLoginLink' OR name = 'admin_email')\" 2>&1";
        $sqliteOutput = trim($connection->exec($cmd));
        $this->generic->log('info', '[WpToolkit] SQLite InstanceProperties', [
            'install_id' => $installId,
            'properties_found' => $sqliteOutput !== '',
        ]);

        $adminLogin = null;
        $adminPass = null;
        $adminEmail = null;

        if ($sqliteOutput) {
            foreach (explode("\n", $sqliteOutput) as $line) {
                $parts = explode('|', $line, 2);
                if (count($parts) === 2) {
                    $name = trim($parts[0]);
                    $value = trim($parts[1]);
                    if ($name === 'login') $adminLogin = $value;
                    if ($name === 'password') $adminPass = $value;
                    if ($name === 'admin_email') $adminEmail = $value;
                }
            }
        }

        // Encrypted WP Toolkit passwords are presence-only. Reading credentials
        // must never reset a live WordPress administrator's password.
        $passwordEncrypted = false;
        if ($adminPass && str_starts_with($adminPass, '$aes-256-gcm$')) {
            $passwordEncrypted = true;
            $adminPass = null;
        }

        if ($adminLogin) {
            $credentials = [
                'username'           => $adminLogin,
                'password'           => $adminPass,
                'email'              => $adminEmail,
                'password_encrypted' => $passwordEncrypted,
            ];
        }

        // Method 2: wp-toolkit --info JSON for loginUrl and siteUrl
        $cmd = "{$wptBin} --info -instance-id {$escapedId} -format json 2>&1";
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
        ];
    }

}
