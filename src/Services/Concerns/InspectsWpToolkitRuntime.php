<?php

namespace hexa_package_wptoolkit\Services\Concerns;

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

/**
 * WpToolkitService — all WP Toolkit operations go through this service.
 *
 * Connects to WHM servers locally or remotely and interacts with the wp-toolkit CLI
 * to discover WordPress installs, manage credentials, and generate login URLs.
 *
 * Methods are organized into domain traits:
 * - ManagesInstalls: getAllInstalls, getInstallsForAccount, parsing
 * - ManagesCredentials: getCredentials, resetWordPressPassword, stored credentials
 * - ManagesLogins: generateWordPressLoginUrl, generateCpanelLoginUrl, etc.
 * - ManagesWpCli: wpCliCreatePost, wpCliUploadMedia, categories, tags, etc.
 */
trait InspectsWpToolkitRuntime
{
    public function runtimeSettings(): array
    {
        $mode = strtolower(trim((string) $this->settingValue('wptoolkit_execution_mode', config('wptoolkit.execution.mode', 'auto'))));
        if (!in_array($mode, ['auto', 'local', 'ssh'], true)) {
            $mode = 'auto';
        }

        $localHostsRaw = (string) $this->settingValue(
            'wptoolkit_local_hosts',
            implode(',', (array) config('wptoolkit.execution.local_hosts', []))
        );

        return [
            'mode' => $mode,
            'local_hosts' => array_values(array_filter(array_map(
                static fn ($host) => strtolower(trim((string) $host)),
                explode(',', $localHostsRaw)
            ))),
            'local_binary_path' => $this->normalizeNullableString($this->settingValue(
                'wptoolkit_local_binary_path',
                config('wptoolkit.cli.local_binary_path') ?? config('wptoolkit.cli.binary_path')
            )),
            'remote_binary_path' => $this->normalizeNullableString($this->settingValue(
                'wptoolkit_remote_binary_path',
                config('wptoolkit.cli.remote_binary_path') ?? config('wptoolkit.cli.binary_path')
            )),
            'probe_timeout' => max(2, (int) $this->settingValue(
                'wptoolkit_probe_timeout',
                config('wptoolkit.diagnostics.probe_timeout', 8)
            )),
            'local_binary_candidates' => $this->candidatePaths('local'),
            'remote_binary_candidates' => $this->candidatePaths('remote'),
        ];
    }

    public function inspectCommandRuntime(WhmServer $server): array
    {
        $localProbe = $this->probeLocalRuntime();
        $sameHost = $this->serverMatchesLocalHost($server);
        $remoteProbe = $sameHost
            ? [
                'transport' => 'local-only',
                'connected' => false,
                'runtime_user' => null,
                'hostname' => $server->hostname,
                'usable' => false,
                'selected_binary' => null,
                'reason' => 'Same-server target: remote probe skipped because local WP Toolkit execution is required.',
                'candidates' => [],
                'error' => null,
            ]
            : $this->probeRemoteRuntime($server);
        $transport = $this->connectionMode($server);

        return [
            'success' => true,
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'hostname' => $server->hostname,
                'ssh_user' => $server->ssh_admin_username ?: $server->username ?: 'root',
                'same_host' => $sameHost,
            ],
            'settings' => $this->runtimeSettings(),
            'resolution' => [
                'transport' => $transport,
                'label' => $this->connectionLabel($server),
                'reason' => $this->connectionResolutionReason($server, $localProbe),
                'selected_binary' => $transport === 'local'
                    ? ($localProbe['selected_binary'] ?? null)
                    : ($remoteProbe['selected_binary'] ?? null),
            ],
            'local_probe' => $localProbe,
            'remote_probe' => $remoteProbe,
        ];
    }

    protected function settingValue(string $key, mixed $default = null): mixed
    {
        if (class_exists(Setting::class)) {
            return Setting::getValue($key, $default);
        }

        return $default;
    }

    protected function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    protected function candidatePaths(string $transport): array
    {
        $configKey = $transport === 'local'
            ? 'wptoolkit.cli.local_binary_candidates'
            : 'wptoolkit.cli.remote_binary_candidates';

        $configuredPath = $transport === 'local'
            ? $this->normalizeNullableString($this->settingValue(
                'wptoolkit_local_binary_path',
                config('wptoolkit.cli.local_binary_path') ?? config('wptoolkit.cli.binary_path')
            ))
            : $this->normalizeNullableString($this->settingValue(
                'wptoolkit_remote_binary_path',
                config('wptoolkit.cli.remote_binary_path') ?? config('wptoolkit.cli.binary_path')
            ));

        $sharedPath = $this->normalizeNullableString(config('wptoolkit.cli.binary_path'));
        $candidates = array_merge(
            $configuredPath ? [$configuredPath] : [],
            $sharedPath ? [$sharedPath] : [],
            (array) config($configKey, []),
            ['wp-toolkit']
        );

        $normalized = [];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || in_array($candidate, $normalized, true)) {
                continue;
            }
            $normalized[] = $candidate;
        }

        return $normalized;
    }

    protected function localWpCliCandidates(): array
    {
        $configuredPath = $this->normalizeNullableString($this->settingValue(
            'wptoolkit_local_wp_binary_path',
            config('wptoolkit.cli.local_wp_binary_path')
        ));

        $candidates = array_merge(
            $configuredPath ? [$configuredPath] : [],
            (array) config('wptoolkit.cli.local_wp_binary_candidates', []),
            ['wp', '/usr/local/bin/wp', '/usr/bin/wp', '/opt/cpanel/composer/bin/wp']
        );

        $normalized = [];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || in_array($candidate, $normalized, true)) {
                continue;
            }
            $normalized[] = $candidate;
        }

        return $normalized;
    }

    protected function serverMatchesLocalHost(WhmServer $server): bool
    {
        $serverAliases = $this->hostAliases((string) ($server->hostname ?? ''));
        if ($serverAliases === []) {
            return false;
        }

        return count(array_intersect($serverAliases, $this->localHostAliases())) > 0;
    }

    protected function localHosts(): array
    {
        $settings = $this->runtimeSettings();

        $merged = array_merge(
            $settings['local_hosts'],
            [
                $this->normalizeHost(gethostname() ?: ''),
                $this->normalizeHost(php_uname('n') ?: ''),
                $this->normalizeHost((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
                '127.0.0.1',
                'localhost',
            ]
        );

        $hosts = [];
        foreach ($merged as $host) {
            $host = $this->normalizeHost($host);
            if ($host === '' || in_array($host, $hosts, true)) {
                continue;
            }
            $hosts[] = $host;
        }

        return $hosts;
    }

    protected function localHostAliases(): array
    {
        if ($this->localHostAliasesCache !== null) {
            return $this->localHostAliasesCache;
        }

        $aliases = [];
        foreach ($this->localHosts() as $host) {
            foreach ($this->hostAliases($host) as $alias) {
                if ($alias === '' || in_array($alias, $aliases, true)) {
                    continue;
                }
                $aliases[] = $alias;
            }
        }

        return $this->localHostAliasesCache = $aliases;
    }

    protected function normalizeHost(string $host): string
    {
        return strtolower(trim($host));
    }

    protected function hostAliases(string $host): array
    {
        $normalized = $this->normalizeHost($host);
        if ($normalized === '') {
            return [];
        }

        if (array_key_exists($normalized, $this->hostAliasCache)) {
            return $this->hostAliasCache[$normalized];
        }

        $aliases = [$normalized];

        if (filter_var($normalized, FILTER_VALIDATE_IP)) {
            $reverse = @gethostbyaddr($normalized);
            $reverse = $this->normalizeHost((string) $reverse);
            if ($reverse !== '' && $reverse !== $normalized) {
                $aliases[] = $reverse;
            }
        } else {
            $resolvedIp = @gethostbyname($normalized);
            if (is_string($resolvedIp) && $resolvedIp !== '' && $resolvedIp !== $normalized) {
                $aliases[] = $this->normalizeHost($resolvedIp);
            }
        }

        $aliases = array_values(array_unique(array_filter($aliases, fn ($alias) => $alias !== '')));

        return $this->hostAliasCache[$normalized] = $aliases;
    }

    protected function connectionResolutionReason(WhmServer $server, array $localProbe): string
    {
        $settings = $this->runtimeSettings();

        if ($this->serverMatchesLocalHost($server)) {
            return ($localProbe['usable'] ?? false)
                ? 'Same-server target: local WP Toolkit execution is required and available.'
                : 'Same-server target: local WP Toolkit execution is required, but the current runtime user cannot execute the local WP Toolkit binary. Remote fallback is disabled.';
        }

        if ($settings['mode'] === 'local') {
            return ($localProbe['usable'] ?? false)
                ? 'Forced local execution via WP Toolkit settings.'
                : 'Forced local execution via WP Toolkit settings, but the current runtime user cannot execute the local WP Toolkit binary.';
        }
        if ($settings['mode'] === 'ssh') {
            return 'Forced remote execution via WP Toolkit settings.';
        }

        return 'Auto mode selected remote execution because the target host does not match the configured local hosts.';
    }

    protected function currentRuntimeUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info) && !empty($info['name'])) {
                return (string) $info['name'];
            }
        }

        return get_current_user() ?: 'unknown';
    }

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
                    $result['usable'] = true;
                }
            }

            $probe['candidates'][] = $result;
            if (!$probe['usable'] && $result['usable']) {
                $probe['usable'] = true;
                $probe['selected_binary'] = $candidate;
                $probe['reason'] = 'Local WP Toolkit command is executable by the current runtime user.';
            }
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
        if ($this->serverMatchesLocalHost($server)) {
            return [
                'transport' => 'local-only',
                'connected' => false,
                'runtime_user' => null,
                'hostname' => $server->hostname,
                'usable' => false,
                'selected_binary' => null,
                'reason' => 'Same-server target: remote probe skipped because local WP Toolkit execution is required.',
                'candidates' => [],
                'error' => null,
            ];
        }

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
            'reason' => 'Remote probe did not run.',
            'candidates' => [],
            'error' => null,
        ];

        $connection = $existingConnection;
        $shouldDisconnect = false;
        if (!$connection) {
            $ssh = $this->sshConnect($server);
            if (!$ssh['success']) {
                $probe['reason'] = 'WP Toolkit connection failed.';
                $probe['error'] = $ssh['error'] ?? 'WP Toolkit connection failed';
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
                    $result['usable'] = true;
                }
            }

            $probe['candidates'][] = $result;
            if (!$probe['usable'] && $result['usable']) {
                $probe['usable'] = true;
                $probe['selected_binary'] = $candidate;
                $probe['reason'] = 'Remote WP Toolkit command is executable.';
            }
        }

        if ($shouldDisconnect) {
            $connection->disconnect();
        }

        if (!$probe['usable'] && $probe['error'] === null) {
            $probe['reason'] = 'No usable WP Toolkit binary was found for the remote command user on the target server.';
        }

        return $this->remoteProbeCache[$cacheKey] = $probe;
    }

}
