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
     * @return array{success: bool, password?: string, wp_user?: string, error?: string}
     */
    public function resetWordPressPassword(WhmServer $server, int $installId, string $wpPath, string $username, string $wpUser): array
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

        // Generate a random password
        $newPassword = bin2hex(random_bytes(12));

        $escapedId = escapeshellarg((string) $installId);
        $escapedPath = escapeshellarg($wpPath);
        $escapedUser = escapeshellarg($username);
        $escapedWpUser = escapeshellarg($wpUser);
        $escapedPass = escapeshellarg($newPassword);

        // Method 1: wp-toolkit --wp-cli
        $cmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- user update {$escapedWpUser} --user_pass={$escapedPass} 2>&1";
        $this->generic->log('info', '[WpToolkit] Trying password reset via wp-toolkit', ['command' => $cmd]);
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
        $this->generic->log('info', '[WpToolkit] Fallback to direct wp-cli', ['command' => $cmd]);
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
            'output' => mb_substr($output, 0, 500),
        ]);

        return [
            'success'    => false,
            'error'      => 'Password reset failed. Output: ' . mb_substr($output, 0, 300),
            'raw_output' => $output,
        ];
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
     * @return array{credentials: array|null, db: array|null, raw: string}
     */
    protected function getStoredCredentials(SSH2|LocalShellConnection $connection, int $installId, string $wpPath, string $username): array
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

}
