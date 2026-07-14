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

trait ResolvesWpToolkitRuntime
{
    /**
     * Resolve the wp-toolkit binary path for the current command transport.
     *
     * @return string
     */
    public function wptBinary(SSH2|LocalShellConnection|null $connection = null, ?WhmServer $server = null): string
    {
        if ($connection instanceof LocalShellConnection) {
            return (string) ($this->probeLocalRuntime()['selected_binary'] ?? 'wp-toolkit');
        }

        if ($connection instanceof SSH2 && $server) {
            return (string) ($this->probeRemoteRuntime($server, $connection)['selected_binary'] ?? 'wp-toolkit');
        }

        if ($server && $this->connectionMode($server) === 'local') {
            return (string) ($this->probeLocalRuntime()['selected_binary'] ?? 'wp-toolkit');
        }

        if ($server) {
            return (string) ($this->probeRemoteRuntime($server)['selected_binary'] ?? 'wp-toolkit');
        }

        return (string) ($this->probeLocalRuntime()['selected_binary'] ?? 'wp-toolkit');
    }

    public function shellBinary(SSH2|LocalShellConnection|null $connection = null, ?WhmServer $server = null): string
    {
        return escapeshellarg($this->wptBinary($connection, $server));
    }

    public function localWpCliBinary(?LocalShellConnection $connection = null): ?string
    {
        $probe = $this->probeLocalWpCliRuntime($connection);

        if (!($probe['usable'] ?? false)) {
            return null;
        }

        $binary = trim((string) ($probe['selected_binary'] ?? ''));

        return $binary !== '' ? $binary : null;
    }

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
        $remoteProbe = $this->probeRemoteRuntime($server);
        $sameHost = $this->serverMatchesLocalHost($server);
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
        if ($settings['mode'] === 'local') {
            return ($localProbe['usable'] ?? false)
                ? 'Forced local execution via WP Toolkit settings.'
                : 'Forced local execution via WP Toolkit settings, but the current runtime user cannot execute the local WP Toolkit binary.';
        }
        if ($settings['mode'] === 'ssh') {
            if ($this->serverMatchesLocalHost($server) && ($localProbe['usable'] ?? false)) {
                return 'Same-host target selected local execution even though WP Toolkit settings request SSH; local is faster and avoids self-SSH.';
            }

            return 'Forced SSH execution via WP Toolkit settings.';
        }

        if ($this->serverMatchesLocalHost($server)) {
            return ($localProbe['usable'] ?? false)
                ? 'Auto mode selected local execution because the target host matches this app host and the runtime user can execute WP Toolkit locally.'
                : 'Auto mode fell back to SSH because the target host matches this app host, but the runtime user cannot execute WP Toolkit locally.';
        }

        return 'Auto mode selected SSH because the target host does not match the configured local hosts.';
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

    protected function isCommandRefusalOutput(string $output): bool
    {
        $normalized = strtolower(trim($output));
        if ($normalized === '') {
            return false;
        }

        foreach ([
            'cannot be run as root',
            'permission denied',
            'not authorized',
            'you are not authorized',
            '/var/.cagefs',
            'cagefs.token',
            'fatal error',
            'parse error',
        ] as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    protected function localPrivilegeBridgeUsable(): bool
    {
        if ($this->currentRuntimeUser() === 'root') {
            return true;
        }

        $sudo = '/bin/sudo';
        $bridge = '/usr/local/bin/hexa-wp-local-fs';
        $sudoPerms = @fileperms($sudo);

        return is_executable($sudo)
            && is_int($sudoPerms)
            && (($sudoPerms & 04000) === 04000)
            && is_executable($bridge);
    }
}
