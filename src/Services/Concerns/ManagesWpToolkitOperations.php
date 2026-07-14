<?php

namespace hexa_package_wptoolkit\Services\Concerns;

use hexa_package_whm\Models\WhmServer;

trait ManagesWpToolkitOperations
{
    public function getInstallInfo(WhmServer $server, int $installId): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $escapedId = escapeshellarg((string) $installId);
        $wptBin = $this->shellBinary($connection, $server);
        $cmd = "{$wptBin} --info -instance-id {$escapedId} -format json 2>&1";
        $output = trim($connection->exec($cmd));

        $jsonStart = null;
        for ($i = 0; $i < strlen($output); $i++) {
            if ($output[$i] === '{' || $output[$i] === '[') {
                $jsonStart = $i;
                break;
            }
        }

        if ($jsonStart === null) {
            return ['success' => false, 'message' => 'wp-toolkit returned non-JSON install info.', 'raw_output' => $output];
        }

        $decoded = json_decode(substr($output, $jsonStart), true);
        if (!is_array($decoded)) {
            return ['success' => false, 'message' => 'Failed to parse wp-toolkit install info JSON.', 'raw_output' => $output];
        }

        return [
            'success' => true,
            'message' => 'Install info loaded.',
            'data' => $decoded,
            'raw_output' => $output,
        ];
    }

    public function cloneInstallSameServer(
        WhmServer $server,
        int $sourceInstallId,
        string $targetDomainName,
        ?string $targetPath = null,
        ?string $targetDbName = null,
        ?string $targetDbUserLogin = null,
        bool $forceOverwrite = false
    ): array {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $wptBin = $this->shellBinary($connection, $server);
        $cmd = "{$wptBin} --clone"
            . ' -source-instance-id ' . escapeshellarg((string) $sourceInstallId)
            . ' -target-domain-name ' . escapeshellarg($targetDomainName)
            . ' -force-overwrite ' . escapeshellarg($forceOverwrite ? 'yes' : 'no');

        if ($targetPath !== null && trim($targetPath) !== '') {
            $cmd .= ' -target-path ' . escapeshellarg(trim($targetPath));
        }
        if ($targetDbName !== null && trim($targetDbName) !== '') {
            $cmd .= ' -target-db-name ' . escapeshellarg(trim($targetDbName));
        }
        if ($targetDbUserLogin !== null && trim($targetDbUserLogin) !== '') {
            $cmd .= ' -target-db-user-login ' . escapeshellarg(trim($targetDbUserLogin));
        }

        $previousTimeout = $this->commandTimeoutSeconds();
        $cloneTimeout = max($previousTimeout, (int) config('wptoolkit.clone_timeout', 900));
        $connection->setTimeout($cloneTimeout);

        try {
            $output = trim($connection->exec($cmd . ' 2>&1'));
        } finally {
            $connection->setTimeout($previousTimeout);
        }

        $success = $this->toolkitOutputLooksSuccessful($output, [
            'instance-id',
            'target-domain-name',
            'source-instance-id',
        ]);

        return [
            'success' => $success,
            'message' => $success ? 'Same-server WP Toolkit clone completed.' : 'Same-server WP Toolkit clone failed.',
            'raw_output' => $output,
        ];
    }

    public function installWordpress(
        WhmServer $server,
        string $domainName,
        ?string $username = null,
        ?string $adminEmail = null,
        ?string $protocol = null,
        ?string $path = null,
        ?string $version = null,
        ?string $language = null,
        ?string $dbName = null,
        ?string $dbUser = null,
        ?string $tablePrefix = null,
        ?string $siteTitle = null
    ): array {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $wptBin = $this->shellBinary($connection, $server);
        $cmd = "{$wptBin} --install -domain-name " . escapeshellarg($domainName);

        $optional = [
            '-username' => $username,
            '-admin-email' => $adminEmail,
            '-protocol' => $protocol,
            '-path' => $path,
            '-version' => $version,
            '-language' => $language,
            '-db-name' => $dbName,
            '-db-user' => $dbUser,
            '-table-prefix' => $tablePrefix,
            '-site-title' => $siteTitle,
        ];

        foreach ($optional as $flag => $value) {
            if ($value !== null && trim((string) $value) !== '') {
                $cmd .= ' ' . $flag . ' ' . escapeshellarg(trim((string) $value));
            }
        }

        $output = trim($connection->exec($cmd . ' 2>&1'));
        $success = $this->toolkitOutputLooksSuccessful($output, [
            'instance-id',
            'admin-email',
            'site-title',
        ]);

        return [
            'success' => $success,
            'message' => $success ? 'WordPress install created.' : 'WordPress install failed.',
            'raw_output' => $output,
        ];
    }

    public function removeInstall(WhmServer $server, int $installId): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $wptBin = $this->shellBinary($connection, $server);
        $cmd = "{$wptBin} --remove -instance-id " . escapeshellarg((string) $installId) . ' 2>&1';
        $output = trim($connection->exec($cmd));
        $success = $this->toolkitOutputLooksSuccessful($output, [
            'removed',
            'done',
        ]);

        return [
            'success' => $success,
            'message' => $success ? 'WP Toolkit install removed.' : 'WP Toolkit install remove failed.',
            'raw_output' => $output,
        ];
    }

    public function registerInstall(WhmServer $server, string $domainName, string $path): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $wptBin = $this->shellBinary($connection, $server);
        $cmd = "{$wptBin} --register -domain-name " . escapeshellarg($domainName)
            . ' -path ' . escapeshellarg($path)
            . ' 2>&1';
        $output = trim($connection->exec($cmd));
        $success = $this->toolkitOutputLooksSuccessful($output, [
            'registered',
            'instance-id',
        ]);

        return [
            'success' => $success,
            'message' => $success ? 'WP Toolkit install registered.' : 'WP Toolkit register failed.',
            'raw_output' => $output,
        ];
    }

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
}
