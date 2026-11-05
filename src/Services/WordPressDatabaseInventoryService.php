<?php

namespace hexa_package_wptoolkit\Services;

use hexa_package_whm\Models\WhmServer;
use hexa_package_whm\Services\WhmService;
use hexa_package_wptoolkit\Support\WordPressConfigCodec;
use RuntimeException;
use Throwable;

final class WordPressDatabaseInventoryService
{
    public function __construct(
        private WpToolkitService $toolkit,
        private WhmService $whm,
        private WordPressConfigCodec $codec,
        private WordPressDatabaseTargetProbe $probe,
        private WordPressDatabaseOrphanInspector $orphans,
    ) {
    }

    public function capture(WhmServer $server, string $campaignId, callable $reporter): array
    {
        $reporter('server_progress', [
            'emoji' => "\u{1F5A5}",
            'campaign' => $campaignId,
            'server' => $server->id,
            'discovery_state' => 'unknown',
            'accounts' => ['checked' => 0, 'total' => null, 'attempted' => 0, 'succeeded' => 0, 'failed' => 0],
            'installations' => ['checked' => 0, 'total' => null, 'attempted' => 0, 'succeeded' => 0, 'failed' => 0],
            'databases' => ['scanned' => 0, 'total' => null, 'attempted' => 0, 'succeeded' => 0, 'failed' => 0],
            'groups' => ['rotated' => 0, 'restored' => 0, 'skipped' => 0, 'blocked' => 0, 'failed' => 0],
            'unmapped' => 0,
        ]);
        $toolkit = $this->toolkit->getAllInstalls($server);
        if (!($toolkit['success'] ?? false)) {
            throw new RuntimeException('WP Toolkit inventory failed: '.($toolkit['error'] ?? 'unknown error'));
        }
        $reporter('wp_toolkit_inventory_complete', [
            'campaign' => $campaignId,
            'installations' => count($toolkit['installs'] ?? []),
        ]);

        $accountResult = $this->whm->listAccounts($server);
        if (!($accountResult['success'] ?? false)) {
            throw new RuntimeException('WHM account inventory failed: '.($accountResult['error'] ?? 'unknown error'));
        }
        $accounts = [];
        foreach ($accountResult['accounts'] ?? [] as $account) {
            if (($account['username'] ?? '') !== '') {
                $accounts[(string) $account['username']] = $account;
            }
        }

        $listed = [];
        foreach ($toolkit['installs'] ?? [] as $install) {
            $configPath = rtrim((string) ($install['path'] ?? ''), '/').'/wp-config.php';
            if ($configPath !== '/wp-config.php') {
                $listed[$configPath] = $install;
            }
        }
        $paths = array_values(array_unique([...array_keys($listed), ...$this->discoverConfigs($server)]));
        sort($paths);
        $reporter('server_progress', [
            'emoji' => "\u{1F5A5}",
            'campaign' => $campaignId,
            'server' => $server->id,
            'discovery_state' => 'installations_total_known',
            'accounts' => ['checked' => 0, 'total' => count($accounts), 'attempted' => 0, 'succeeded' => 0, 'failed' => 0],
            'installations' => ['checked' => 0, 'total' => count($paths), 'attempted' => 0, 'succeeded' => 0, 'failed' => 0],
            'databases' => ['scanned' => 0, 'total' => null, 'attempted' => 0, 'succeeded' => 0, 'failed' => 0],
        ]);
        $installations = $this->inspectInstallations($server, $paths, $listed, $reporter);
        $provider = $this->providerInventory($server, $accounts, $reporter);
        $groups = $this->groups($server, $installations, $provider);
        $groupByConfig = [];
        foreach ($groups as $group) {
            foreach ($group['configs'] as $configPath) {
                $groupByConfig[$configPath] = $group;
            }
        }
        $siteReports = [];
        foreach ($installations as $position => &$installation) {
            $group = $groupByConfig[$installation['config_path']] ?? null;
            $installation['attached_databases'] = isset($installation['database'])
                ? [$installation['database']]
                : [];
            $installation['credential_group'] = $group['id'] ?? null;
            $installation['shared_dependents'] = $group['configs'] ?? [];
            $siteReports[$installation['config_path']] = $this->inventorySiteReport($installation, $group);
            $reporter('site_terminal_report', [
                'emoji' => "\u{1F4CB}",
                'campaign' => $campaignId,
                'server' => $server->id,
                'position' => $position + 1,
                'total' => count($installations),
                'report' => $siteReports[$installation['config_path']],
            ]);
        }
        unset($installation);
        $orphans = $this->orphans->inspect($server, $installations, $provider, $accounts, $reporter);
        $attachments = $this->attachments($installations);
        $orphanByDatabase = [];
        foreach ($orphans as $orphan) {
            $orphanByDatabase[$orphan['database']] = $orphan;
        }
        $databaseReports = [];
        foreach ($provider['databases'] as $position => $providerDatabase) {
            $database = (string) $providerDatabase['name'];
            $databaseReports[$database] = $orphanByDatabase[$database]
                ?? $this->orphans->mappedReport(
                    $server,
                    $database,
                    $providerDatabase,
                    $provider['grants'],
                    $accounts[$providerDatabase['account']] ?? [],
                    $attachments[$database] ?? [],
                );
            $reporter('database_terminal_report', [
                'emoji' => "\u{1F5C4}",
                'campaign' => $campaignId,
                'server' => $server->id,
                'position' => count($databaseReports),
                'total' => count($provider['databases']),
                'report' => $databaseReports[$database],
                'server_counters' => [
                    'databases' => [
                        'scanned' => count($databaseReports),
                        'total' => count($provider['databases']),
                        'attempted' => count($databaseReports),
                        'succeeded' => count($databaseReports),
                        'failed' => 0,
                    ],
                ],
            ]);
        }
        $identity = $this->identity($server, $installations, $provider);
        $blocked = count(array_filter($groups, fn (array $group): bool => $group['rotation_state'] !== 'ready'));
        $unlisted = count(array_filter(
            $installations,
            fn (array $install): bool => $install['inventory_source'] === 'filesystem_unlisted',
        ));
        $successfulInstallations = count(array_filter(
            $installations,
            fn (array $install): bool => (bool) ($install['database_check']['success'] ?? false)
                && (bool) ($install['http_checks']['home']['reachable'] ?? false)
                && (bool) ($install['http_checks']['login']['reachable'] ?? false),
        ));

        return [
            'success' => true,
            'campaign' => $campaignId,
            'server' => [
                'id' => (int) $server->id,
                'name' => (string) $server->name,
                'hostname' => (string) $server->hostname,
            ],
            'captured_at' => now()->utc()->toIso8601String(),
            'inventory_identity' => $identity,
            'inventory_complete' => !array_filter(
                $installations,
                fn (array $install): bool => !($install['parsed'] ?? false),
            ),
            'summary' => [
                'wp_toolkit_count' => count($listed),
                'filesystem_wordpress_count' => count($installations),
                'covered_installation_count' => count(array_filter(
                    $installations,
                    fn (array $install): bool => (bool) ($install['parsed'] ?? false),
                )),
                'excluded_installation_count' => 0,
                'unlisted_installation_count' => $unlisted,
                'credential_group_count' => count($groups),
                'blocked_group_count' => $blocked,
                'ready_group_count' => count($groups) - $blocked,
                'unmapped_database_count' => count($orphans),
                'installation_attempted_count' => count($installations),
                'installation_success_count' => $successfulInstallations,
                'installation_failed_count' => count($installations) - $successfulInstallations,
                'database_attempted_count' => count($databaseReports),
                'database_success_count' => count($databaseReports),
                'database_failed_count' => 0,
                'skipped_rotation_count' => $blocked,
                'restored_rotation_count' => 0,
            ],
            'installations' => $installations,
            'groups' => $groups,
            'databases' => $databaseReports,
            'orphan_databases' => $orphans,
            'site_reports' => $siteReports,
            'orphan_deletion_supported' => false,
            'timestamp_caveat' => 'Database creation and last-use timestamps are unavailable. Table CREATE_TIME is only an estimate; UPDATE_TIME is data-change metadata, not last use, and may be lost after cache eviction or restart.',
        ];
    }

    private function inspectInstallations(
        WhmServer $server,
        array $paths,
        array $listed,
        callable $reporter,
    ): array {
        $sftp = $this->whm->connectSftp($server);
        $installations = [];
        try {
            foreach ($paths as $position => $path) {
                $account = $this->accountFromPath($path);
                $base = [
                    'inventory_source' => isset($listed[$path]) ? 'wp_toolkit' : 'filesystem_unlisted',
                    'inventory_confidence' => isset($listed[$path]) ? 'high' : 'medium',
                    'toolkit_id' => $listed[$path]['id'] ?? null,
                    'path' => dirname($path),
                    'config_path' => $path,
                    'account' => $account,
                    'toolkit_url' => $listed[$path]['url'] ?? null,
                    'toolkit_url_source' => isset($listed[$path]) ? 'wp_toolkit' : 'unavailable',
                    'toolkit_status' => $listed[$path]['status'] ?? null,
                ];
                $reporter('site_processing_started', [
                    'emoji' => "\u{1F310}",
                    'server' => $server->id,
                    'position' => $position + 1,
                    'total' => count($paths),
                    'safe_url' => $base['toolkit_url'],
                    'account' => $account,
                    'path' => dirname($path),
                ]);
                $contents = $sftp->get($path);
                if (!is_string($contents)) {
                    $installations[] = [...$base, 'parsed' => false, 'error' => 'wp-config.php could not be read'];
                    $reporter('installation_inspected', [
                        'emoji' => "\u{1F310}",
                        'server' => $server->id,
                        'account' => $account,
                        'path' => dirname($path),
                        'position' => $position + 1,
                        'total' => count($paths),
                        'terminal' => true,
                        'report' => $this->inspectionReport($installations[array_key_last($installations)]),
                        'success' => false,
                        'server_counters' => [
                            'installations' => [
                                'checked' => $position + 1,
                                'total' => count($paths),
                                'attempted' => $position + 1,
                                'succeeded' => count(array_filter(
                                    $installations,
                                    fn (array $item): bool => (bool) ($item['database_check']['success'] ?? false),
                                )),
                                'failed' => count($installations) - count(array_filter($installations, fn (array $item): bool => (bool) ($item['database_check']['success'] ?? false))),
                            ],
                        ],
                    ]);
                    continue;
                }
                try {
                    $config = $this->codec->parse($contents);
                    $probeStarted = microtime(true);
                    $probeAt = now()->utc()->toIso8601String();
                    $probe = $this->probe->database($server, $config, $config['password']);
                    $httpChecks = ($probe['success'] ?? false)
                        ? $this->probe->routes($probe['options'])
                        : ['home' => ['reachable' => false, 'status' => null], 'login' => ['reachable' => false, 'status' => null]];
                    $durationMs = (int) round((microtime(true) - $probeStarted) * 1000);
                    $installations[] = [
                        ...$base,
                        'parsed' => true,
                        'database' => $config['database'],
                        'database_user' => $config['username'],
                        'database_host' => $config['host'],
                        'table_prefix' => $config['table_prefix'],
                        'database_check' => $this->safeProbe($probe),
                        'http_checks' => $httpChecks,
                        'baseline_checked_at' => $probeAt,
                        'baseline_duration_ms' => $durationMs,
                        'wordpress_base_url' => $probe['options']['home'] ?? $probe['options']['siteurl'] ?? null,
                        'wordpress_base_url_source' => 'database options table',
                        'wordpress_base_url_confidence' => ($probe['success'] ?? false) ? 'high' : 'unavailable',
                        'blog_name' => $probe['options']['blogname'] ?? null,
                    ];
                } catch (Throwable $error) {
                    $installations[] = [
                        ...$base,
                        'parsed' => false,
                        'error' => mb_substr($error->getMessage(), 0, 500),
                    ];
                } finally {
                    unset($contents, $config);
                }
                $successful = count(array_filter(
                    $installations,
                    fn (array $item): bool => (bool) ($item['database_check']['success'] ?? false)
                        && (bool) ($item['http_checks']['home']['reachable'] ?? false)
                        && (bool) ($item['http_checks']['login']['reachable'] ?? false),
                ));
                $reporter('installation_inspected', [
                    'emoji' => "\u{1F310}",
                    'server' => $server->id,
                    'account' => $account,
                    'path' => dirname($path),
                    'position' => $position + 1,
                    'total' => count($paths),
                    'terminal' => true,
                    'report' => $this->inspectionReport($installations[array_key_last($installations)]),
                    'success' => (bool) ($installations[array_key_last($installations)]['database_check']['success'] ?? false)
                        && (bool) ($installations[array_key_last($installations)]['http_checks']['home']['reachable'] ?? false)
                        && (bool) ($installations[array_key_last($installations)]['http_checks']['login']['reachable'] ?? false),
                    'server_counters' => [
                        'installations' => [
                            'checked' => $position + 1,
                            'total' => count($paths),
                            'attempted' => $position + 1,
                            'succeeded' => $successful,
                            'failed' => count($installations) - $successful,
                        ],
                    ],
                ]);
            }
        } finally {
            $sftp->disconnect();
        }

        return $installations;
    }

    private function providerInventory(WhmServer $server, array $accounts, callable $reporter): array
    {
        $databases = [];
        $users = [];
        $grants = [];
        $crons = [];
        $accountChecked = 0;

        foreach ($accounts as $account => $metadata) {
            $dbResult = $this->whm->api()->cpanelApiCall($server, $account, 'Mysql', 'list_databases');
            $userResult = $this->whm->api()->cpanelApiCall($server, $account, 'Mysql', 'list_users');
            $cronResult = $this->whm->api()->cpanelApiCall($server, $account, 'Cron', 'list_cron_jobs');
            if (!($dbResult['success'] ?? false) || !($userResult['success'] ?? false) || !($cronResult['success'] ?? false)) {
                throw new RuntimeException('cPanel database or cron inventory failed for account '.$account.'.');
            }

            foreach ($this->rows($dbResult['data'] ?? [], 'database') as $row) {
                $name = (string) ($row['database'] ?? $row['name'] ?? $row['db'] ?? '');
                if ($name === '') {
                    continue;
                }
                $databases[$name] = [
                    'account' => $account,
                    'name' => $name,
                    'size_bytes' => is_numeric($row['disk_usage'] ?? null) ? (int) $row['disk_usage'] : null,
                ];
                // CRITICAL — see BUGLOG.md WPDB-CAMPAIGN-001.
                foreach ((array) ($row['users'] ?? []) as $attachedUser) {
                    $user = is_string($attachedUser)
                        ? $attachedUser
                        : (string) ($attachedUser['user'] ?? $attachedUser['username'] ?? $attachedUser['name'] ?? '');
                    if ($user === '') {
                        throw new RuntimeException('cPanel returned an unnamed database-user attachment.');
                    }
                    $privilegeResult = $this->whm->api()->cpanelApiCall(
                        $server, $account, 'Mysql', 'get_privileges_on_database',
                        ['database' => $name, 'user' => $user],
                    );
                    if (!($privilegeResult['success'] ?? false)) {
                        throw new RuntimeException('cPanel grant inventory failed for database '.$name.' and user '.$user.'.');
                    }
                    $grants[$user][$name] = [
                        'account' => $account,
                        'privileges' => array_values(array_filter(
                            (array) ($privilegeResult['data'] ?? []), 'is_string',
                        )),
                    ];
                }
            }
            foreach ($this->rows($userResult['data'] ?? [], 'user') as $row) {
                $name = (string) ($row['user'] ?? $row['username'] ?? $row['name'] ?? '');
                if ($name !== '') {
                    $users[$name] = ['account' => $account, 'name' => $name];
                }
            }
            foreach ($this->rows($cronResult['data'] ?? [], 'command') as $row) {
                if (($row['command'] ?? '') !== '') {
                    $crons[$account][] = (string) $row['command'];
                }
            }
            $accountChecked++;
            $reporter('account_inventory_complete', [
                'emoji' => "\u{1F464}",
                'account' => $account,
                'position' => $accountChecked,
                'total' => count($accounts),
                'server_counters' => [
                    'accounts' => [
                        'checked' => $accountChecked,
                        'total' => count($accounts),
                        'attempted' => $accountChecked,
                        'succeeded' => $accountChecked,
                        'failed' => 0,
                    ],
                ],
            ]);
        }
        ksort($databases);
        ksort($users);
        ksort($grants);

        return compact('databases', 'users', 'grants', 'crons');
    }

    private function groups(WhmServer $server, array $installations, array $provider): array
    {
        $groups = [];
        foreach ($installations as $install) {
            if (!($install['parsed'] ?? false)) {
                continue;
            }
            $id = substr(hash(
                'sha256',
                $server->id.'|'.$install['account'].'|'.$install['database_user'],
            ), 0, 20);
            $groups[$id] ??= [
                'id' => $id,
                'account' => $install['account'],
                'database_user' => $install['database_user'],
                'databases' => [],
                'configs' => [],
                'rotation_state' => 'ready',
                'blockers' => [],
            ];
            $groups[$id]['databases'][] = $install['database'];
            $groups[$id]['configs'][] = $install['config_path'];
            if (!($install['database_check']['success'] ?? false)) {
                $groups[$id]['blockers'][] = 'Baseline target-server database/options check failed for '.$install['config_path'];
            }
        }

        foreach ($groups as &$group) {
            $group['databases'] = array_values(array_unique($group['databases']));
            sort($group['databases']);
            $grantDatabases = array_keys($provider['grants'][$group['database_user']] ?? []);
            sort($grantDatabases);
            if (!isset($provider['users'][$group['database_user']])) {
                $group['blockers'][] = 'Database user is absent from cPanel inventory';
            }
            foreach ($group['databases'] as $database) {
                if (!isset($provider['databases'][$database])) {
                    $group['blockers'][] = 'Mapped database is absent from cPanel inventory: '.$database;
                }
            }
            if ($grantDatabases !== $group['databases']) {
                $group['blockers'][] = 'Database user has unmapped or missing grants; every dependent database must rotate atomically';
            }
            if (($provider['users'][$group['database_user']]['account'] ?? null) !== $group['account']) {
                $group['blockers'][] = 'Database-user ownership does not match the cPanel account';
            }
            $group['blockers'] = array_values(array_unique($group['blockers']));
            $group['rotation_state'] = $group['blockers'] === [] ? 'ready' : 'blocked';
        }
        unset($group);
        ksort($groups);

        return $groups;
    }

    private function discoverConfigs(WhmServer $server): array
    {
        $command = "for root in /home /home2 /home3; do [ -d \"\$root\" ] || continue; "
            ."find \"\$root\" -xdev -type f -name wp-config.php ! -path '*/vendor/*' "
            ."! -path '*/cache/*' ! -path '*/tmp/*' ! -path '*/backup*/*' "
            ."! -path '*/backups/*' 2>/dev/null; done | sort -u";
        $result = $this->whm->executeCommandWithTimeout($server, $command, 300);
        if (!($result['success'] ?? false)) {
            throw new RuntimeException('Server-wide wp-config.php discovery failed.');
        }

        return array_values(array_filter(array_map(
            'trim',
            preg_split('/\R/', (string) ($result['output'] ?? '')) ?: [],
        )));
    }

    private function identity(WhmServer $server, array $installations, array $provider): string
    {
        $safe = [
            'server' => (int) $server->id,
            'installations' => array_map(fn (array $item): array => [
                'path' => $item['config_path'],
                'account' => $item['account'],
                'database' => $item['database'] ?? null,
                'database_user' => $item['database_user'] ?? null,
                'parsed' => $item['parsed'],
            ], $installations),
            'databases' => array_keys($provider['databases']),
            'users' => array_keys($provider['users']),
            'grants' => array_map('array_keys', $provider['grants']),
        ];

        return hash('sha256', json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function rows(mixed $data, string $nameKey): array
    {
        if (!is_array($data) || $data === []) {
            return [];
        }
        foreach (['data', 'databases', 'users', 'cron'] as $wrapper) {
            if (isset($data[$wrapper]) && is_array($data[$wrapper])) {
                return $this->rows($data[$wrapper], $nameKey);
            }
        }
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        if (isset($data[$nameKey]) || isset($data['name'])) {
            return [$data];
        }

        $rows = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value[$nameKey] ??= is_string($key) ? $key : null;
                $rows[] = $value;
            }
        }

        return $rows;
    }

    private function accountFromPath(string $path): string
    {
        if (preg_match('#\A/home\d*/([^/]+)/#', $path, $match) !== 1) {
            throw new RuntimeException('WordPress config path is outside a recognized account home.');
        }

        return $match[1];
    }

    private function attachments(array $installations): array
    {
        $attachments = [];
        foreach ($installations as $installation) {
            if (!isset($installation['database'])) {
                continue;
            }
            $attachments[$installation['database']][] = [
                'installation_id' => $installation['toolkit_id'],
                'safe_url' => $installation['wordpress_base_url'] ?? $installation['toolkit_url'],
                'url_source' => isset($installation['wordpress_base_url']) ? 'database options table' : 'WP Toolkit',
                'path' => $installation['path'],
                'config_path' => $installation['config_path'],
                'account' => $installation['account'],
                'inventory_source' => $installation['inventory_source'],
            ];
        }

        return $attachments;
    }

    private function inventorySiteReport(array $installation, ?array $group): array
    {
        $before = [
            'checked_at' => $installation['baseline_checked_at'] ?? null,
            'duration_ms' => $installation['baseline_duration_ms'] ?? null,
            'database' => $installation['database_check'] ?? [
                'success' => false,
                'error' => $installation['error'] ?? 'Not performed.',
            ],
            'home' => $installation['http_checks']['home'] ?? [
                'reachable' => false,
                'status' => null,
                'error' => 'Not performed.',
            ],
            'login' => $installation['http_checks']['login'] ?? [
                'reachable' => false,
                'status' => null,
                'error' => 'Not performed.',
            ],
        ];

        return [
            'site' => $installation,
            'before' => $before,
            'change' => [
                'outcome' => $group === null
                    ? 'blocked'
                    : (($group['rotation_state'] ?? '') === 'ready' ? 'not_requested' : 'blocked'),
                'configuration' => 'not_changed',
                'scope' => [
                    'credential_group' => $group['id'] ?? null,
                    'databases' => $group['databases'] ?? ($installation['attached_databases'] ?? []),
                    'dependent_configs' => $group['configs'] ?? [],
                ],
                'reason' => $group === null
                    ? ($installation['error'] ?? 'No safe credential group could be resolved.')
                    : implode('; ', $group['blockers'] ?? []),
            ],
            'new_login' => ['success' => null, 'outcome' => 'not_performed'],
            'old_login' => ['rejected' => null, 'outcome' => 'not_performed'],
            'after' => ['outcome' => 'not_performed'],
            'baseline_match' => null,
            'recovery' => ['outcome' => 'not_needed', 'error' => null],
            'context' => 'Inventory and baseline only; no password rotation was requested.',
        ];
    }

    /** CRITICAL — see BUGLOG.md WPDB-CAMPAIGN-002. */
    private function inspectionReport(array $installation): array
    {
        if (trim((string) ($installation['account'] ?? '')) === ''
            || trim((string) ($installation['config_path'] ?? '')) === '') {
            throw new RuntimeException('Individual reporting requires a cPanel account and configuration identity.');
        }
        $report = $this->inventorySiteReport($installation, null);
        $report['change']['outcome'] = 'inventory_in_progress';
        $report['change']['reason'] = 'Dependency grouping follows these checks; no password changed.';
        $report['context'] = 'Individual installation inspection; ownership and shared dependents are still being reconciled.';

        return $report;
    }

    private function safeProbe(array $probe): array
    {
        return [
            'success' => (bool) ($probe['success'] ?? false),
            'select_1' => (bool) ($probe['select_1'] ?? false),
            'options' => $probe['options'] ?? [],
            'error_code' => $probe['error_code'] ?? null,
            'error' => ($probe['success'] ?? false) ? null : ($probe['error'] ?? 'Database probe failed.'),
        ];
    }
}
