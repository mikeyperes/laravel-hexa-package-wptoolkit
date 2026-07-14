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

trait OperatesWpToolkitInstalls
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
}
