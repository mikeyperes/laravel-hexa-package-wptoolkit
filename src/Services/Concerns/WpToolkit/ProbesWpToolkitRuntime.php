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

trait ProbesWpToolkitRuntime
{
    protected function probeLocalRuntime(): array
    {
        if ($this->localProbe !== null) {
            return $this->localProbe;
        }

        $settings = $this->runtimeSettings();
        $connection = new LocalShellConnection(base_path());
        $connection->setTimeout((int) $settings['probe_timeout']);

        $probe = [
            'transport' => 'local',
            'runtime_user' => $this->currentRuntimeUser(),
            'hostname' => $this->normalizeHost(gethostname() ?: php_uname('n') ?: ''),
            'usable' => false,
            'selected_binary' => null,
            'reason' => 'No executable local WP Toolkit binary was found for the current runtime user.',
            'candidates' => [],
        ];

        foreach ($settings['local_binary_candidates'] as $candidate) {
            $result = [
                'path' => $candidate,
                'exists' => null,
                'executable' => null,
                'exit_code' => null,
                'version' => null,
                'usable' => false,
            ];

            if ($candidate === 'wp-toolkit') {
                $check = $this->runCommandWithExitCode($connection, 'command -v wp-toolkit >/dev/null 2>&1 && wp-toolkit --version 2>&1 | head -n 1');
                $result['exists'] = null;
                $result['executable'] = null;
                $result['exit_code'] = $check['exit_code'];
                $result['version'] = $check['lines'][0] ?? null;
                $result['usable'] = (int) ($check['exit_code'] ?? 1) === 0;
            } else {
                $result['exists'] = file_exists($candidate);
                $result['executable'] = $result['exists'] ? is_executable($candidate) : false;
                if ($result['exists'] && $result['executable']) {
                    $check = $this->runCommandWithExitCode($connection, escapeshellarg($candidate) . ' --version 2>&1 | head -n 1');
                    $result['exit_code'] = $check['exit_code'];
                    $result['version'] = $check['lines'][0] ?? null;
                    $result['usable'] = (int) ($check['exit_code'] ?? 1) === 0;
                }
            }

            $candidateOutput = trim((string) ($result['version'] ?? ''));
            if (($result['usable'] ?? false) && $this->isCommandRefusalOutput($candidateOutput)) {
                $result['usable'] = false;
                $result['refused'] = true;
                $result['reason'] = 'Candidate command returned a refusal or error message.';
            }

            $probe['candidates'][] = $result;
            if (!$probe['usable'] && $result['usable']) {
                $probe['usable'] = true;
                $probe['selected_binary'] = $candidate;
                $probe['reason'] = 'Local WP Toolkit command is executable by the current runtime user.';
            }
        }

        $probe['privilege_bridge_usable'] = $this->localPrivilegeBridgeUsable();
        if (($probe['usable'] ?? false) && $probe['runtime_user'] !== 'root' && !($probe['privilege_bridge_usable'] ?? false)) {
            $probe['usable'] = false;
            $probe['reason'] = 'Local WP Toolkit binary exists, but the same-host privilege bridge is unavailable. Falling back to SSH to avoid broken local file operations.';
        }

        $this->localProbe = $probe;

        return $this->localProbe;
    }

    protected function probeLocalWpCliRuntime(?LocalShellConnection $connection = null): array
    {
        if ($this->localWpCliProbe !== null) {
            return $this->localWpCliProbe;
        }

        $settings = $this->runtimeSettings();
        $connection ??= new LocalShellConnection(base_path());
        $connection->setTimeout((int) $settings['probe_timeout']);

        $probe = [
            'transport' => 'local-wp',
            'runtime_user' => $this->currentRuntimeUser(),
            'hostname' => $this->normalizeHost(gethostname() ?: php_uname('n') ?: ''),
            'usable' => false,
            'selected_binary' => null,
            'reason' => 'No executable local WP-CLI binary was found for the current runtime user.',
            'candidates' => [],
        ];

        foreach ($this->localWpCliCandidates() as $candidate) {
            $result = [
                'path' => $candidate,
                'exists' => null,
                'executable' => null,
                'exit_code' => null,
                'version' => null,
                'usable' => false,
            ];

            if ($candidate === 'wp') {
                $check = $this->runCommandWithExitCode($connection, 'command -v wp >/dev/null 2>&1 && wp --info 2>&1 | head -n 1');
                $result['exists'] = null;
                $result['executable'] = null;
                $result['exit_code'] = $check['exit_code'];
                $result['version'] = $check['lines'][0] ?? null;
                $result['usable'] = (int) ($check['exit_code'] ?? 1) === 0;
            } else {
                $result['exists'] = file_exists($candidate);
                $result['executable'] = $result['exists'] ? is_executable($candidate) : false;
                if ($result['exists'] && $result['executable']) {
                    $check = $this->runCommandWithExitCode($connection, escapeshellarg($candidate) . ' --info 2>&1 | head -n 1');
                    $result['exit_code'] = $check['exit_code'];
                    $result['version'] = $check['lines'][0] ?? null;
                    $result['usable'] = (int) ($check['exit_code'] ?? 1) === 0;
                }
            }

            $probe['candidates'][] = $result;
            if (!$probe['usable'] && $result['usable']) {
                $probe['usable'] = true;
                $probe['selected_binary'] = $candidate;
                $probe['reason'] = 'Local WP-CLI is executable by the current runtime user.';
            }
        }

        return $this->localWpCliProbe = $probe;
    }

    protected function probeRemoteRuntime(WhmServer $server, ?SSH2 $existingConnection = null): array
    {
        $cacheKey = $server->id . ':' . $server->hostname;
        if (isset($this->remoteProbeCache[$cacheKey])) {
            return $this->remoteProbeCache[$cacheKey];
        }

        $settings = $this->runtimeSettings();
        $probe = [
            'transport' => 'ssh',
            'connected' => false,
            'runtime_user' => null,
            'hostname' => $server->hostname,
            'usable' => false,
            'selected_binary' => null,
            'reason' => 'SSH probe did not run.',
            'candidates' => [],
            'error' => null,
        ];

        $connection = $existingConnection;
        $shouldDisconnect = false;
        if (!$connection) {
            $ssh = $this->sshConnect($server);
            if (!$ssh['success']) {
                $probe['reason'] = 'SSH connection failed.';
                $probe['error'] = $ssh['error'] ?? 'SSH connection failed';
                return $this->remoteProbeCache[$cacheKey] = $probe;
            }

            /** @var SSH2 $connection */
            $connection = $ssh['connection'];
            $shouldDisconnect = true;
        }

        $connection->setTimeout((int) $settings['probe_timeout']);

        $probe['connected'] = true;
        $probe['runtime_user'] = trim((string) $connection->exec('id -un 2>/dev/null || whoami 2>/dev/null'));
        $remoteHostname = trim((string) $connection->exec('hostname -f 2>/dev/null || hostname 2>/dev/null'));
        if ($remoteHostname !== '') {
            $probe['hostname'] = $remoteHostname;
        }

        foreach ($settings['remote_binary_candidates'] as $candidate) {
            $result = [
                'path' => $candidate,
                'exists' => null,
                'executable' => null,
                'exit_code' => null,
                'version' => null,
                'usable' => false,
            ];

            if ($candidate === 'wp-toolkit') {
                $check = $this->runCommandWithExitCode($connection, 'command -v wp-toolkit >/dev/null 2>&1 && wp-toolkit --version 2>&1 | head -n 1');
                $result['exists'] = null;
                $result['executable'] = null;
                $result['exit_code'] = $check['exit_code'];
                $result['version'] = $check['lines'][0] ?? null;
                $result['usable'] = (int) ($check['exit_code'] ?? 1) === 0;
            } else {
                $existsCheck = $this->runCommandWithExitCode(
                    $connection,
                    'test -e ' . escapeshellarg($candidate) . ' && printf EXISTS || printf MISSING'
                );
                $execCheck = $this->runCommandWithExitCode(
                    $connection,
                    'test -x ' . escapeshellarg($candidate) . ' && printf EXECUTABLE || printf NOT_EXECUTABLE'
                );
                $result['exists'] = str_contains($existsCheck['clean_output'], 'EXISTS');
                $result['executable'] = str_contains($execCheck['clean_output'], 'EXECUTABLE');
                if ($result['exists'] && $result['executable']) {
                    $check = $this->runCommandWithExitCode($connection, escapeshellarg($candidate) . ' --version 2>&1 | head -n 1');
                    $result['exit_code'] = $check['exit_code'];
                    $result['version'] = $check['lines'][0] ?? null;
                    $result['usable'] = (int) ($check['exit_code'] ?? 1) === 0;
                }
            }

            $candidateOutput = trim((string) ($result['version'] ?? ''));
            if (($result['usable'] ?? false) && $this->isCommandRefusalOutput($candidateOutput)) {
                $result['usable'] = false;
                $result['refused'] = true;
                $result['reason'] = 'Candidate command returned a refusal or error message.';
            }

            $probe['candidates'][] = $result;
            if (!$probe['usable'] && $result['usable']) {
                $probe['usable'] = true;
                $probe['selected_binary'] = $candidate;
                $probe['reason'] = 'Remote WP Toolkit command is executable over SSH.';
            }
        }

        if ($shouldDisconnect) {
            $connection->disconnect();
        }

        if (!$probe['usable'] && $probe['error'] === null) {
            $probe['reason'] = 'No usable WP Toolkit binary was found for the SSH user on the target server.';
        }

        return $this->remoteProbeCache[$cacheKey] = $probe;
    }
}
