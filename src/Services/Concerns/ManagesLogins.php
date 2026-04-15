<?php

namespace hexa_package_wptoolkit\Services\Concerns;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use phpseclib3\Net\SSH2;

/**
 * ManagesLogins — WordPress, cPanel, and WHM login URL generation.
 */
trait ManagesLogins
{
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
     * Detect if a WordPress install has a custom login URL.
     *
     * Checks for common plugins that modify the wp-admin URL:
     * - WPS Hide Login (whl_page option)
     * - iThemes Security (itsec-storage → hide-backend)
     * - All In One WP Security (aio_wp_security_configs → rename-login-page)
     *
     * @param SSH2|LocalShellConnection   $connection Active command connection
     * @param int    $installId  WP Toolkit install ID
     * @param string $wpPath     Full path to WordPress install
     * @param string $username   cPanel username
     * @return array{url: string|null, raw: string}
     */
    protected function detectCustomLoginUrl(SSH2|LocalShellConnection $connection, int $installId, string $wpPath, string $username): array
    {
        $escapedId = escapeshellarg((string) $installId);
        $escapedPath = escapeshellarg($wpPath);
        $escapedUser = escapeshellarg($username);
        $raw = '';

        // Check WPS Hide Login (most common)
        $cmd = "{$this->wptBinary()}--wp-cli -instance-id {$escapedId} -- option get whl_page 2>&1";
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

}
