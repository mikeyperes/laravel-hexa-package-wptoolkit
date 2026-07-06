<?php

namespace hexa_package_wptoolkit\Services\Concerns\WpCli;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use Illuminate\Support\Facades\Cache;
use phpseclib3\Net\SSH2;

trait SupportsWpCliConnections
{
    protected function wpCliBaseCommand(WhmServer $server, SSH2|LocalShellConnection $connection, int $installId): string
    {
        if ($connection instanceof LocalShellConnection) {
            $localProbe = $this->probeLocalRuntime();
            $runtimeUser = strtolower(trim((string) ($localProbe['runtime_user'] ?? '')));
            $localWpBinary = $this->localWpCliBinary($connection);
            $installPath = $this->resolveInstallPath($server, $connection, $installId);

            if ($localWpBinary && $installPath) {
                $command = escapeshellarg($localWpBinary) . " --path=" . escapeshellarg($installPath);
                if ($runtimeUser === "root") {
                    $command .= " --allow-root";
                }
                return $command;
            }
        }

        return $this->shellBinary($connection, $server)
            . ' --wp-cli -instance-id '
            . escapeshellarg((string) $installId)
            . ' -- --allow-root';
    }

    protected function resolveWpAuthorId(WhmServer $server, SSH2|LocalShellConnection $connection, int $installId, string $author): ?string
    {
        $author = trim($author);
        if ($author === '') {
            return null;
        }

        if (is_numeric($author)) {
            return $author;
        }

        $cacheKey = $server->id . ':' . $installId . ':' . strtolower($author);
        if (array_key_exists($cacheKey, $this->wpAuthorIdCache)) {
            return $this->wpAuthorIdCache[$cacheKey];
        }

        $userCmd = $this->wpCliBaseCommand($server, $connection, $installId)
            . ' user get '
            . escapeshellarg($author)
            . ' --field=ID 2>/dev/null';
        $rawId = trim($this->execWithConnection($connection, $userCmd));

        foreach (explode("\n", $rawId) as $line) {
            $line = trim($line);
            if (!is_numeric($line)) {
                continue;
            }

            return $this->wpAuthorIdCache[$cacheKey] = $line;
        }

        $this->wpAuthorIdCache[$cacheKey] = null;

        return null;
    }

    protected function resolveInstallPath(WhmServer $server, SSH2|LocalShellConnection $connection, int $installId): ?string
    {
        $cacheKey = $server->id . ':' . $installId;
        if (!empty($this->installInfoCache[$cacheKey]['fullPath'])) {
            return rtrim((string) $this->installInfoCache[$cacheKey]['fullPath'], '/');
        }

        $escapedId = escapeshellarg((string) $installId);
        $info = $this->runCommandWithExitCode($connection, $this->shellBinary($connection, $server) . " --info -instance-id {$escapedId} -format json 2>&1");
        $parsed = json_decode((string) ($info['clean_output'] ?: $info['raw_output']), true);
        $fullPath = $parsed['fullPath'] ?? null;

        if (!is_string($fullPath) || trim($fullPath) === '') {
            $this->generic->log('error', '[WpToolkit] resolveInstallPath failed', [
                'install_id' => $installId,
                'exit_code' => $info['exit_code'],
                'output' => $info['clean_output'] ?: $info['raw_output'],
            ]);
            return null;
        }

        $this->installInfoCache[$cacheKey] = $parsed;

        return rtrim($fullPath, '/');
    }

    protected function execWithConnection(SSH2|LocalShellConnection $connection, string $cmd): string
    {
        if (method_exists($connection, 'setTimeout')) {
            $connection->setTimeout($this->commandTimeoutSeconds());
        }

        try {
            $output = (string) $connection->exec($cmd);
        } catch (\RuntimeException $e) {
            if ($connection instanceof SSH2 && str_contains($e->getMessage(), 'Please close the channel')) {
                $this->disconnectCachedConnection(connection: $connection);
            }

            throw $e;
        }

        if ($connection instanceof SSH2 && $connection->isTimeout()) {
            $timeout = $this->commandTimeoutSeconds();
            $this->disconnectCachedConnection(connection: $connection);
            $this->generic->log('error', '[WpToolkit] SSH command timed out', [
                'timeout' => $timeout,
                'command' => \Illuminate\Support\Str::limit($cmd, 160),
            ]);

            throw new \RuntimeException("WP Toolkit SSH command timed out after {$timeout} seconds");
        }

        return $output;
    }

    protected function runCommandWithExitCode(SSH2|LocalShellConnection $connection, string $cmd): array
    {
        $marker = '__HEXA_CMD_EXIT__';
        $raw = $this->execWithConnection($connection, $cmd . '; status=$?; printf "\\n' . $marker . ':%s\\n" "$status"');

        $exitCode = null;
        if (preg_match('/' . preg_quote($marker, '/') . ':(\d+)/', $raw, $matches)) {
            $exitCode = (int) $matches[1];
            $raw = preg_replace('/\n?' . preg_quote($marker, '/') . ':\d+\s*$/', '', $raw);
        }

        $raw = trim($raw);
        $lines = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, 'Deprecated:') && str_contains($line, 'Colors.php on line 95')) {
                continue;
            }
            if (str_starts_with($line, 'PHP Deprecated:') && str_contains($line, 'Colors.php on line 95')) {
                continue;
            }
            $lines[] = $line;
        }

        return [
            'exit_code' => $exitCode,
            'raw_output' => $raw,
            'clean_output' => implode("\n", $lines),
            'lines' => $lines,
        ];
    }

    /**
     * Create or get a WordPress category via wp-cli SSH.
     *
     * @param WhmServer $server
     * @param int       $installId
     * @param string    $name Category name
     * @return array{success: bool, term_id?: int, message: string}
     */
}
