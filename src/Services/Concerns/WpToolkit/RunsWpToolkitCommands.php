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

trait RunsWpToolkitCommands
{
    public function syncPluginFromGitHub(WhmServer $server, string $cpanelUsername, string $wordpressPath, string $pluginDirectory, string $githubUrl, string $bootstrap = 'initialization.php'): array
    {
        $cpanelUsername = trim($cpanelUsername);
        $wordpressPath = trim($wordpressPath, "/ \t\n\r\0\x0B");
        $pluginDirectory = trim($pluginDirectory, "/ \t\n\r\0\x0B");
        $githubUrl = rtrim(trim($githubUrl), "/");
        $bootstrap = trim($bootstrap, "/ \t\n\r\0\x0B");

        if ($wordpressPath === '') {
            $wordpressPath = 'public_html';
        }

        if ($cpanelUsername === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $cpanelUsername)) {
            return ['success' => false, 'message' => 'A valid cPanel username is required for plugin sync.'];
        }
        if ($wordpressPath === '' || str_contains($wordpressPath, '..')) {
            return ['success' => false, 'message' => 'A valid WordPress path is required for plugin sync.'];
        }
        if ($pluginDirectory === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $pluginDirectory)) {
            return ['success' => false, 'message' => 'A valid plugin directory is required for plugin sync.'];
        }
        if ($githubUrl === '' || !preg_match('#^https://github\.com/[^/]+/[^/]+/?$#i', $githubUrl)) {
            return ['success' => false, 'message' => 'A valid GitHub repository URL is required for plugin sync.'];
        }
        if ($bootstrap === '' || str_contains($bootstrap, '..') || !preg_match('/^[A-Za-z0-9._\/-]+$/', $bootstrap)) {
            return ['success' => false, 'message' => 'A valid plugin bootstrap file is required for plugin sync.'];
        }

        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $wpRoot = "/home/{$cpanelUsername}/{$wordpressPath}";
        $pluginRoot = $wpRoot . "/wp-content/plugins/" . $pluginDirectory;
        $backupBase = "/home/{$cpanelUsername}/_hexa-plugin-backups";
        $backupDir = $backupBase . "/" . date("YmdHis") . "-" . $pluginDirectory;
        $tmp = "/tmp/hexa-plugin-" . $pluginDirectory . "-" . uniqid();
        $pluginBasename = $pluginDirectory . "/" . $bootstrap;
        $activateCommand = 'cd ' . escapeshellarg($wpRoot)
            . ' && WPCLI=$(command -v wp 2>/dev/null || true)'
            . ' && if [ -z "$WPCLI" ] && [ -x /usr/local/bin/wp ]; then WPCLI=/usr/local/bin/wp; fi'
            . ' && if [ -n "$WPCLI" ]; then ("$WPCLI" plugin activate ' . escapeshellarg($pluginDirectory) . ' --allow-root 2>&1 || "$WPCLI" plugin activate ' . escapeshellarg($pluginBasename) . ' --allow-root 2>&1) && "$WPCLI" rewrite flush --hard --allow-root 2>&1; else echo "wp-cli not found; activation skipped"; fi';
        $command = "mkdir -p " . escapeshellarg($backupBase)
            . " && if [ -d " . escapeshellarg($pluginRoot) . " ]; then cp -a " . escapeshellarg($pluginRoot) . " " . escapeshellarg($backupDir) . "; fi"
            . " && rm -rf " . escapeshellarg($tmp)
            . " && git clone --depth=1 " . escapeshellarg($githubUrl) . " " . escapeshellarg($tmp) . " 2>&1"
            . " && mkdir -p " . escapeshellarg($pluginRoot)
            . " && rsync -a --delete --exclude=.git " . escapeshellarg($tmp . "/") . " " . escapeshellarg($pluginRoot . "/")
            . " && chown -R " . escapeshellarg($cpanelUsername . ":" . $cpanelUsername) . " " . escapeshellarg($pluginRoot)
            . " && " . $activateCommand
            . " && rm -rf " . escapeshellarg($tmp);

        $previousTimeout = $this->commandTimeoutSeconds();
        if (method_exists($connection, 'setTimeout')) {
            $connection->setTimeout(max(120, $previousTimeout));
        }

        try {
            $result = $this->runCommandWithExitCode($connection, $command);
        } finally {
            if (method_exists($connection, 'setTimeout')) {
                $connection->setTimeout($previousTimeout);
            }
        }

        $output = trim((string) (($result['clean_output'] ?? '') ?: ($result['raw_output'] ?? '')));
        $exitCode = (int) ($result['exit_code'] ?? 1);
        $success = $exitCode === 0 && !$this->isCommandRefusalOutput($output);

        return [
            'success' => $success,
            'message' => $success ? 'Plugin files synced, activated, and rewrite rules flushed.' : 'Plugin sync, activation, or rewrite flush failed.',
            'output' => $output,
            'raw_output' => (string) ($result['raw_output'] ?? ''),
            'exit_code' => $exitCode,
            'backup_path' => $backupDir,
            'plugin_directory' => $pluginDirectory,
            'plugin_root' => $pluginRoot,
            'wordpress_path' => $wpRoot,
            'command' => $command,
        ];
    }

    public function wpCliRaw(WhmServer $server, int $installId, string $wpCliCommand, ?int $timeout = null): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed', 'stdout' => ''];
        }

        $connection = $ssh['connection'];
        $wptBin = $this->shellBinary($connection, $server);
        $escapedId = escapeshellarg((string) $installId);
        $cmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- {$wpCliCommand} 2>&1";
        $previousTimeout = $this->commandTimeoutSeconds();
        if ($timeout !== null && method_exists($connection, 'setTimeout')) {
            $connection->setTimeout(max(10, $timeout));
        }

        try {
            $result = $this->runCommandWithExitCode($connection, $cmd);
        } finally {
            if ($timeout !== null && method_exists($connection, 'setTimeout')) {
                $connection->setTimeout($previousTimeout);
            }
        }

        $stdout = trim((string) ($result['clean_output'] ?: $result['raw_output']));
        $exitCode = (int) ($result['exit_code'] ?? 1);
        $success = $exitCode === 0 && !$this->isCommandRefusalOutput($stdout);

        return [
            'success' => $success,
            'message' => $success
                ? 'wp-cli command executed.'
                : 'wp-cli command failed: ' . \Illuminate\Support\Str::limit($stdout !== '' ? $stdout : 'unknown error', 300),
            'stdout' => $stdout,
            'exit_code' => $exitCode,
        ];
    }

    private function toolkitOutputLooksSuccessful(string $output, array $successMarkers = []): bool
    {
        $normalized = strtolower($output);
        if ($normalized === '') {
            return false;
        }

        $failureMarkers = [
            'error:',
            'exception',
            'fatal',
            'failed',
            'unable to',
            'not found',
        ];

        foreach ($failureMarkers as $marker) {
            if (str_contains($normalized, $marker)) {
                return false;
            }
        }

        $defaultSuccessMarkers = ['success', 'completed'];
        foreach (array_merge($defaultSuccessMarkers, array_map('strtolower', $successMarkers)) as $marker) {
            if ($marker !== '' && str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }
}
