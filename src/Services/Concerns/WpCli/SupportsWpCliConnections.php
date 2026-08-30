<?php

namespace hexa_package_wptoolkit\Services\Concerns\WpCli;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use Illuminate\Support\Facades\Cache;
use phpseclib3\Net\SFTP;
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
            . ' --';
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
        $parsed = json_decode($info['raw_output'], true);
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

    protected function transferLocalFileToWpCliServer(
        WhmServer $server,
        SSH2|LocalShellConnection $connection,
        string $sourcePath,
        string $targetPath,
    ): ?string {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            return 'Local source file does not exist or is not readable.';
        }

        $expectedBytes = (int) filesize($sourcePath);
        if ($expectedBytes <= 0) {
            return 'Local source file is empty.';
        }

        if ($connection instanceof LocalShellConnection) {
            $targetDirectory = dirname($targetPath);
            if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0755, true) && !is_dir($targetDirectory)) {
                return 'Could not create the local WordPress staging directory.';
            }
            if (!@copy($sourcePath, $targetPath)) {
                $error = error_get_last();

                return 'Local file copy failed: ' . (string) ($error['message'] ?? 'unknown error');
            }
            @chmod($targetPath, 0644);
            $actualBytes = is_file($targetPath) ? (int) filesize($targetPath) : 0;

            return $actualBytes === $expectedBytes
                ? null
                : "Local staged file size mismatch: expected {$expectedBytes}, received {$actualBytes}.";
        }

        $sftpResult = $this->sftpConnect($server);
        if (!($sftpResult['success'] ?? false) || !($sftpResult['connection'] ?? null) instanceof SFTP) {
            return (string) ($sftpResult['error'] ?? 'SFTP connection failed.');
        }

        /** @var SFTP $sftp */
        $sftp = $sftpResult['connection'];
        try {
            if (!$sftp->put($targetPath, $sourcePath, SFTP::SOURCE_LOCAL_FILE)) {
                $errors = method_exists($sftp, 'getSFTPErrors') ? (array) $sftp->getSFTPErrors() : [];
                $details = trim(implode('; ', array_map('strval', $errors)));

                return 'SFTP upload failed' . ($details !== '' ? ': ' . $details : '.');
            }

            $actualBytes = (int) $sftp->filesize($targetPath);
            if ($actualBytes !== $expectedBytes) {
                $sftp->delete($targetPath);

                return "SFTP staged file size mismatch: expected {$expectedBytes}, received {$actualBytes}.";
            }

            if (!$sftp->chmod(0644, $targetPath)) {
                $sftp->delete($targetPath);

                return 'SFTP upload completed, but file permissions could not be applied.';
            }

            return null;
        } catch (\Throwable $exception) {
            try {
                $sftp->delete($targetPath);
            } catch (\Throwable) {
                // Best effort cleanup only.
            }

            return 'SFTP upload failed: ' . $exception->getMessage();
        } finally {
            try {
                $sftp->disconnect();
            } catch (\Throwable) {
                // Best effort cleanup only.
            }
        }
    }

    protected function stageWpCliTempFile(SSH2|LocalShellConnection $connection, string $path, string $contents): ?string
    {
        try {
            if ($connection instanceof LocalShellConnection) {
                $bytes = @file_put_contents($path, $contents);
                if ($bytes === false) {
                    $error = error_get_last();
                    return (string) ($error['message'] ?? 'local file write failed');
                }

                return null;
            }

            if (method_exists($connection, 'put')) {
                return $connection->put($path, $contents) ? null : 'WP Toolkit temp file write failed';
            }

            $tmpBase64 = $path . '.b64';
            $this->execWithConnection($connection, 'rm -f ' . escapeshellarg($tmpBase64) . ' ' . escapeshellarg($path));
            $encoded = base64_encode($contents);
            foreach (str_split($encoded, 24000) as $chunk) {
                $appendCmd = 'printf %s ' . escapeshellarg($chunk) . ' >> ' . escapeshellarg($tmpBase64);
                $chunkResult = $this->runCommandWithExitCode($connection, $appendCmd . ' 2>&1');
                if (((int) ($chunkResult['exit_code'] ?? 1)) !== 0) {
                    return 'chunked temp write failed: ' . \Illuminate\Support\Str::limit($chunkResult['clean_output'] ?: $chunkResult['raw_output'], 300);
                }
            }

            $decodeCmd = 'base64 -d ' . escapeshellarg($tmpBase64) . ' > ' . escapeshellarg($path);
            $decodeResult = $this->runCommandWithExitCode($connection, $decodeCmd . ' 2>&1');
            $this->execWithConnection($connection, 'rm -f ' . escapeshellarg($tmpBase64));
            if (((int) ($decodeResult['exit_code'] ?? 1)) !== 0) {
                return 'temp decode failed: ' . \Illuminate\Support\Str::limit($decodeResult['clean_output'] ?: $decodeResult['raw_output'], 300);
            }

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
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
            $this->generic->log('error', '[WpToolkit] command timed out', [
                'timeout' => $timeout,
                'command' => \Illuminate\Support\Str::limit($cmd, 160),
            ]);

            throw new \RuntimeException("WP Toolkit command timed out after {$timeout} seconds");
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
     * Run PHP through the site's native wp binary so active plugin hooks load.
     */
    public function wpCliEvalWithPlugins(
        \hexa_package_whm\Models\WhmServer $server,
        int $installId,
        string $php,
        int $timeout = 120,
    ): array {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'stdout' => '', 'message' => $ssh['error'] ?? 'WP Toolkit connection failed'];
        }

        $connection = $ssh['connection'];
        $installPath = $this->resolveInstallPath($server, $connection, $installId);
        if (!$installPath) {
            return ['success' => false, 'stdout' => '', 'message' => 'Unable to resolve WordPress install path for direct wp-cli eval.'];
        }

        $wpBinary = $this->resolveDirectWpCliBinary($connection);
        if ($wpBinary === '') {
            return ['success' => false, 'stdout' => '', 'message' => 'Unable to locate a native wp-cli binary on the target server.'];
        }

        $previousTimeout = $this->commandTimeoutSeconds();
        if (method_exists($connection, 'setTimeout')) {
            $connection->setTimeout(max(10, $timeout));
        }

        try {
            $encoded = base64_encode($php);
            $cmd = 'CODE=$(printf %s ' . escapeshellarg($encoded) . ' | base64 -d) && '
                . escapeshellarg($wpBinary)
                . ' --path=' . escapeshellarg($installPath)
                . ' --allow-root eval "$CODE" 2>&1';
            $result = $this->runCommandWithExitCode($connection, $cmd);
        } finally {
            if (method_exists($connection, 'setTimeout')) {
                $connection->setTimeout($previousTimeout);
            }
        }

        $stdout = trim((string) ($result['clean_output'] ?: $result['raw_output']));
        $success = (int) ($result['exit_code'] ?? 1) === 0;

        return [
            'success' => $success,
            'stdout' => $stdout,
            'message' => $success
                ? 'Direct wp-cli eval completed with active plugins.'
                : 'Direct wp-cli eval failed: ' . \Illuminate\Support\Str::limit($stdout ?: 'unknown error', 300),
            'exit_code' => $result['exit_code'] ?? null,
            'wp_binary' => $wpBinary,
            'install_path' => $installPath,
        ];
    }

    protected function resolveDirectWpCliBinary(SSH2|LocalShellConnection $connection): string
    {
        foreach (['wp', '/usr/local/bin/wp', '/usr/bin/wp', '/opt/cpanel/composer/bin/wp'] as $candidate) {
            $check = $candidate === 'wp'
                ? 'command -v wp 2>/dev/null'
                : 'test -x ' . escapeshellarg($candidate) . ' && printf %s ' . escapeshellarg($candidate);
            $result = $this->runCommandWithExitCode($connection, $check);
            if ((int) ($result['exit_code'] ?? 1) !== 0) {
                continue;
            }

            $path = strtok(trim((string) ($result['clean_output'] ?: $result['raw_output'])), "\r\n") ?: '';
            if ($path !== '') {
                return $path;
            }
        }

        return '';
    }

    public function wpCliEval(\hexa_package_whm\Models\WhmServer $server, int $installId, string $php): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'stdout' => '', 'message' => $ssh['error'] ?? 'WP Toolkit connection failed'];
        }

        $connection = $ssh['connection'];
        $wpCliBase = $this->wpCliBaseCommand($server, $connection, $installId);
        $b64 = base64_encode($php);
        $cmd = 'CODE=$(printf %s ' . escapeshellarg($b64) . ' | base64 -d) && '
            . $wpCliBase . ' eval "$CODE" 2>&1';
        $result = $this->runCommandWithExitCode($connection, $cmd);
        $out = trim((string) ($result['clean_output'] ?: $result['raw_output']));
        $exitCode = $result['exit_code'] ?? null;
        $success = $exitCode === 0;

        return [
            'success' => $success,
            'stdout' => $out,
            'message' => $success
                ? 'WP-CLI eval completed.'
                : 'WP-CLI eval failed: ' . \Illuminate\Support\Str::limit($out ?: 'unknown error', 300),
            'exit_code' => $exitCode,
        ];
    }
    // ===== code-side unique methods (preserved during 3-way merge) =====

    // === wpCliBatchTerms ===

    public function extractJsonObjectFromOutput(string $output): ?array
    {
        $trimmed = trim($output);
        if ($trimmed === "") {
            return null;
        }

        if (preg_match("/\{.*\}/s", $trimmed, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }


    // === localFilesystemCommand ===
protected function localFilesystemCommand(SSH2|LocalShellConnection $connection, string $command): string
    {
        if ($connection instanceof LocalShellConnection && $this->currentRuntimeUser() !== "root") {
            return "sudo -n /usr/local/bin/hexa-wp-local-fs " . escapeshellarg(base64_encode($command));
        }

        return $command;
    }
}
