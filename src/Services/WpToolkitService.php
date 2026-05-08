<?php

namespace hexa_package_wptoolkit\Services;

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
 * Connects to WHM servers via SSH and interacts with the wp-toolkit CLI
 * to discover WordPress installs, manage credentials, and generate login URLs.
 *
 * Methods are organized into domain traits:
 * - ManagesInstalls: getAllInstalls, getInstallsForAccount, parsing
 * - ManagesCredentials: getCredentials, resetWordPressPassword, stored credentials
 * - ManagesLogins: generateWordPressLoginUrl, generateCpanelLoginUrl, etc.
 * - ManagesWpCli: wpCliCreatePost, wpCliUploadMedia, categories, tags, etc.
 */
class WpToolkitService
{
    use ManagesInstalls;
    use ManagesCredentials;
    use ManagesLogins;
    use ManagesWpCli;

    protected GenericService $generic;
    protected WhmService $whm;
    protected array $sshCache = [];
    protected array $installInfoCache = [];
    protected ?array $localProbe = null;
    protected array $remoteProbeCache = [];

    /**
     * @param GenericService $generic
     * @param WhmService     $whm
     */
    public function __construct(GenericService $generic, WhmService $whm)
    {
        $this->generic = $generic;
        $this->whm = $whm;
    }

    public function commandTimeoutSeconds(): int
    {
        return max(10, (int) config('wptoolkit.ssh.timeout', 120));
    }

    protected function connectionCacheKey(WhmServer $server): string
    {
        return $this->connectionMode($server) . '_' . $server->id . '_' . $server->hostname;
    }

    public function disconnectCachedConnection(?WhmServer $server = null, SSH2|LocalShellConnection|null $connection = null): void
    {
        if ($server) {
            $key = $this->connectionCacheKey($server);
            if (!isset($this->sshCache[$key])) {
                return;
            }

            try {
                $this->sshCache[$key]->disconnect();
            } catch (\Throwable) {
                // Best effort cleanup only.
            }

            unset($this->sshCache[$key]);

            return;
        }

        foreach ($this->sshCache as $key => $cachedConnection) {
            if ($connection && $cachedConnection !== $connection) {
                continue;
            }

            try {
                $cachedConnection->disconnect();
            } catch (\Throwable) {
                // Best effort cleanup only.
            }

            unset($this->sshCache[$key]);
        }
    }

    /**
     * Get or create a cached SSH connection for a server.
     * Reuses existing connections to avoid reconnecting for every operation.
     *
     * @param WhmServer $server
     * @return array{success: bool, connection?: SSH2, error?: string}
     */
    public function getConnection(WhmServer $server): array
    {
        $key = $this->connectionCacheKey($server);

        // Try cached connection — quick liveness test to reset channel state
        if (isset($this->sshCache[$key])) {
            $conn = $this->sshCache[$key];
            if ($conn->isConnected()) {
                try {
                    $conn->setTimeout(3);
                    $conn->exec('true');
                    $conn->setTimeout($this->commandTimeoutSeconds());
                    return ['success' => true, 'connection' => $conn];
                } catch (\Throwable $e) {
                    // Stale or broken — reconnect
                }
            }
            $this->disconnectCachedConnection($server);
        }

        // Create fresh connection
        if ($this->connectionMode($server) === 'local') {
            $localProbe = $this->probeLocalRuntime();
            if (!($localProbe['usable'] ?? false)) {
                return [
                    'success' => false,
                    'error' => $localProbe['reason'] ?? 'Local WP Toolkit execution is unavailable for the current runtime user.',
                ];
            }
            $result = $this->localConnect($server);
        } else {
            $result = $this->sshConnect($server);
        }

        if ($result['success'] && isset($result['connection'])) {
            $result['connection']->setTimeout($this->commandTimeoutSeconds());
            $this->sshCache[$key] = $result['connection'];
        }
        return $result;
    }

    public function connectionMode(WhmServer $server): string
    {
        $settings = $this->runtimeSettings();
        if ($settings['mode'] === 'local') {
            return 'local';
        }
        if ($settings['mode'] === 'ssh') {
            return 'ssh';
        }

        if ($this->serverMatchesLocalHost($server) && ($this->probeLocalRuntime()['usable'] ?? false)) {
            return 'local';
        }

        return 'ssh';
    }

    public function connectionLabel(WhmServer $server): string
    {
        return $this->connectionMode($server) === 'local'
            ? 'WP Toolkit (local)'
            : 'WP Toolkit (SSH)';
    }

    /**
     * Establish an SSH connection to a WHM server.
     *
     * Tries SSH key auth first, falls back to password.
     *
     * @param WhmServer $server
     * @return array{success: bool, connection?: SSH2, error?: string}
     */
    protected function sshConnect(WhmServer $server): array
    {
        $hostname = $server->hostname;
        $port = config('wptoolkit.ssh.port', 22);
        $timeout = $this->commandTimeoutSeconds();
        $connectTimeout = max(10, min($timeout, 30));
        $username = $server->ssh_admin_username ?: $server->username ?: 'root';

        $this->generic->log('info', '[WpToolkit] SSH connecting', [
            'hostname'    => $hostname,
            'port'        => $port,
            'username'    => $username,
            'has_ssh_key' => !empty($server->ssh_private_key),
        ]);

        try {
            $ssh = new SSH2($hostname, $port, $connectTimeout);
            $ssh->setTimeout($timeout);

            // Try SSH key first
            if (!empty($server->ssh_private_key)) {
                try {
                    $key = PublicKeyLoader::load(
                        $server->ssh_private_key,
                        $server->ssh_key_passphrase ?: false
                    );
                    if ($ssh->login($username, $key)) {
                        $this->generic->log('info', '[WpToolkit] SSH key auth succeeded');
                        return ['success' => true, 'connection' => $ssh];
                    }
                } catch (\Exception $e) {
                    $this->generic->log('warning', '[WpToolkit] SSH key auth failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Try password
            if (!empty($server->ssh_admin_password)) {
                if ($ssh->login($username, $server->ssh_admin_password)) {
                    $this->generic->log('info', '[WpToolkit] SSH password auth succeeded');
                    return ['success' => true, 'connection' => $ssh];
                }
            }

            return [
                'success' => false,
                'error'   => "SSH auth failed for {$username}@{$hostname}:{$port}. No valid credentials.",
            ];
        } catch (\Exception $e) {
            $this->generic->log('error', '[WpToolkit] SSH connection failed', [
                'hostname' => $hostname,
                'error'    => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error'   => 'SSH connection failed: ' . $e->getMessage(),
            ];
        }
    }

    protected function localConnect(WhmServer $server): array
    {
        $this->generic->log('info', '[WpToolkit] Local execution selected', [
            'hostname' => $server->hostname,
            'mode' => 'local',
        ]);

        $connection = new LocalShellConnection(base_path());
        $connection->setTimeout($this->commandTimeoutSeconds());

        return [
            'success' => true,
            'connection' => $connection,
        ];
    }

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

    protected function serverMatchesLocalHost(WhmServer $server): bool
    {
        $serverHost = $this->normalizeHost($server->hostname ?? '');
        if ($serverHost === '') {
            return false;
        }

        return in_array($serverHost, $this->localHosts(), true);
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

    protected function normalizeHost(string $host): string
    {
        return strtolower(trim($host));
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

        $output = trim($connection->exec($cmd . ' 2>&1'));
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
        ?string $dbPassword = null,
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

        if ($dbPassword !== null && trim((string) $dbPassword) !== '') {
            $cmd = 'DB_PASSWORD=' . escapeshellarg(trim((string) $dbPassword)) . ' ' . $cmd;
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

    public function wpCliRaw(WhmServer $server, int $installId, string $wpCliCommand): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed', 'stdout' => ''];
        }

        $connection = $ssh['connection'];
        $wptBin = $this->shellBinary($connection, $server);
        $escapedId = escapeshellarg((string) $installId);
        $cmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- {$wpCliCommand} 2>&1";
        $stdout = trim($connection->exec($cmd));

        return [
            'success' => true,
            'message' => 'wp-cli command executed.',
            'stdout' => $stdout,
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
