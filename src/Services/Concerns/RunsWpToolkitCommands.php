<?php

namespace hexa_package_wptoolkit\Services\Concerns;

use hexa_package_whm\Models\WhmServer;

trait RunsWpToolkitCommands
{
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
