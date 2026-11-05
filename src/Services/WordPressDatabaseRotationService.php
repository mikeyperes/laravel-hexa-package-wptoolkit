<?php

namespace hexa_package_wptoolkit\Services;

use hexa_package_whm\Models\WhmServer;
use hexa_package_whm\Services\WhmService;
use hexa_package_wptoolkit\Support\WordPressConfigCodec;
use RuntimeException;
use Throwable;

final class WordPressDatabaseRotationService
{
    public function __construct(
        private WhmService $whm,
        private WordPressConfigCodec $codec,
        private WordPressDatabaseTargetProbe $probe,
    ) {
    }

    public function rotate(
        WhmServer $server,
        array $record,
        string $groupId,
        string $securityEvidence,
        callable $reporter,
    ): array {
        $group = $record['groups'][$groupId] ?? null;
        if (!is_array($group)) {
            throw new RuntimeException('The selected database-user group is not in this campaign.');
        }
        if (($group['rotation_state'] ?? '') !== 'ready') {
            throw new RuntimeException('The selected group is blocked: '.implode('; ', $group['blockers'] ?? []));
        }

        $targets = $this->targets($server, $record, $group);
        $passwords = array_values(array_unique(array_column($targets, 'password')));
        if (count($passwords) !== 1) {
            throw new RuntimeException('The shared database user has inconsistent passwords across mapped wp-config.php files.');
        }
        $oldPassword = $passwords[0];
        $newPassword = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
        $before = [];
        $providerChanged = false;
        $configsChanged = false;

        try {
            foreach ($targets as $position => $target) {
                $reporter('site_stage', [
                    'emoji' => "\u{1F310}",
                    'stage' => 'before',
                    'position' => $position + 1,
                    'total' => count($targets),
                    'site' => $target['facts'],
                ]);
                $before[$target['path']] = $this->checks($server, $target, $oldPassword);
                if (!$this->checksPassed($before[$target['path']])) {
                    throw new RuntimeException('A baseline database, home-page, or wp-login check failed.');
                }
            }

            $reporter('group_stage', [
                'emoji' => "\u{1F510}",
                'stage' => 'swapping',
                'group' => $groupId,
                'sites' => array_column($targets, 'path'),
                'databases' => $group['databases'],
            ]);
            $set = $this->providerPassword($server, $group, $newPassword);
            if (!($set['success'] ?? false)) {
                if (!$this->allConnectionsSucceed($server, $targets, $oldPassword)) {
                    $restore = $this->providerPassword($server, $group, $oldPassword);
                    if (!($restore['success'] ?? false) || !$this->allConnectionsSucceed($server, $targets, $oldPassword)) {
                        return $this->rollbackFailure(
                            $record,
                            $targets,
                            $groupId,
                            $before,
                            'cPanel outcome was uncertain and the original credential could not be proved restored.',
                            $reporter,
                        );
                    }
                }

                return $this->unchangedFailure(
                    $record,
                    $targets,
                    $groupId,
                    $before,
                    'cPanel rejected the password update; the original credential still works.',
                    $reporter,
                );
            }
            $providerChanged = true;

            $oldRejection = [];
            $newLogin = [];
            foreach ($targets as $target) {
                $reporter('site_stage', [
                    'emoji' => "\u{1F50E}",
                    'stage' => 'verifying_credentials',
                    'site' => $target['facts'],
                ]);
                $oldRejection[$target['path']] = $this->probe->database($server, $target, $oldPassword);
                if (($oldRejection[$target['path']]['error_code'] ?? null) !== 1045) {
                    throw new RuntimeException('Old-login rejection was not proved by MySQL error 1045 on every fresh identical connection.');
                }
                $newLogin[$target['path']] = $this->probe->database($server, $target, $newPassword);
                if (!($newLogin[$target['path']]['success'] ?? false) || !($newLogin[$target['path']]['select_1'] ?? false)) {
                    throw new RuntimeException('The new credential failed SELECT 1 or the WordPress options check.');
                }
            }

            $write = $this->writePasswords($server, $targets, $newPassword);
            $configsChanged = true;
            if (!$write['success']) {
                throw new RuntimeException($write['error']);
            }

            $after = [];
            foreach ($targets as $target) {
                $after[$target['path']] = $this->checks($server, $target, $newPassword);
                if (!$this->sameChecks($before[$target['path']], $after[$target['path']])) {
                    throw new RuntimeException('A post-rotation database/options or HTTP check differed from baseline.');
                }
            }

            $completedAt = now()->utc()->toIso8601String();
            $record['status'] = 'rotation_complete';
            $record['groups'][$groupId]['rotation_state'] = 'rotated';
            $record['groups'][$groupId]['rotation_proof'] = [
                'completed_at' => $completedAt,
                'security_evidence_sha256' => $securityEvidence,
                'old_rejected_1045' => true,
                'new_connected' => true,
                'select_1' => true,
                'wordpress_options_match' => true,
                'http_routes_match' => true,
                'configs_read_back' => count($targets),
            ];
            $record['summary']['rotated_group_count'] = (int) ($record['summary']['rotated_group_count'] ?? 0) + 1;
            foreach ($targets as $target) {
                $siteReport = $this->siteReport(
                    $target,
                    $before[$target['path']],
                    $after[$target['path']],
                    $oldRejection[$target['path']],
                    $newLogin[$target['path']],
                    'swapped',
                    'updated',
                    'not_needed',
                    null,
                );
                $record['site_reports'][$target['path']] = $siteReport;
                $reporter('site_terminal_report', [
                    'emoji' => "\u{1F4CB}",
                    'campaign' => $record['campaign'],
                    'report' => $siteReport,
                ]);
            }

            return [
                'record' => $record,
                'result' => [
                    'success' => true,
                    'campaign' => $record['campaign'],
                    'group' => $groupId,
                    'proof' => $record['groups'][$groupId]['rotation_proof'],
                    'site_reports' => array_values(array_intersect_key(
                        $record['site_reports'],
                        array_flip(array_column($targets, 'path')),
                    )),
                ],
            ];
        } catch (Throwable $error) {
            if (!$providerChanged) {
                throw $error;
            }
            $reporter('group_stage', [
                'emoji' => "\u{1F6DF}",
                'stage' => 'restoring',
                'group' => $groupId,
            ]);
            $rollback = $this->rollback(
                $server,
                $group,
                $targets,
                $oldPassword,
                $before,
                $configsChanged,
            );
            if (!$rollback['success']) {
                return $this->rollbackFailure(
                    $record,
                    $targets,
                    $groupId,
                    $before,
                    $rollback['error'],
                    $reporter,
                );
            }

            $record['status'] = 'rotation_failed_restored';
            $record['groups'][$groupId]['rotation_state'] = 'failed_restored';
            $record['summary']['restored_rotation_count'] = (int) ($record['summary']['restored_rotation_count'] ?? 0) + 1;
            $record['summary']['failed_group_count'] = (int) ($record['summary']['failed_group_count'] ?? 0) + 1;
            $message = mb_substr($error->getMessage(), 0, 1000);
            foreach ($targets as $target) {
                $siteReport = $this->siteReport(
                    $target,
                    $before[$target['path']],
                    $before[$target['path']],
                    ['success' => false, 'error_code' => null],
                    ['success' => false],
                    'restored',
                    $configsChanged ? 'restored' : 'not_changed',
                    'restored_with_proof',
                    $message,
                );
                $record['site_reports'][$target['path']] = $siteReport;
                $reporter('site_terminal_report', [
                    'emoji' => "\u{1F4CB}",
                    'campaign' => $record['campaign'],
                    'report' => $siteReport,
                ]);
            }

            return [
                'record' => $record,
                'result' => [
                    'success' => false,
                    'campaign' => $record['campaign'],
                    'group' => $groupId,
                    'error' => $message,
                    'rollback' => 'restored_with_proof',
                ],
            ];
        } finally {
            unset($oldPassword, $newPassword);
            foreach ($targets as &$target) {
                unset($target['password'], $target['contents']);
            }
            unset($target);
        }
    }

    private function targets(WhmServer $server, array $record, array $group): array
    {
        $facts = [];
        foreach ($record['installations'] as $installation) {
            $facts[$installation['config_path']] = $installation;
        }
        $targets = [];
        $sftp = $this->whm->connectSftp($server);
        try {
            foreach ($group['configs'] as $path) {
                $contents = $sftp->get($path);
                $fileStat = $sftp->stat($path);
                $directoryStat = $sftp->stat(dirname($path));
                if (!is_string($contents) || !is_array($fileStat) || !is_array($directoryStat)) {
                    throw new RuntimeException('A campaign wp-config.php or its metadata could not be read.');
                }
                $config = $this->codec->parse($contents);
                if ($config['username'] !== $group['database_user'] || !in_array($config['database'], $group['databases'], true)) {
                    throw new RuntimeException('A wp-config.php identity changed after inventory.');
                }
                $targets[] = [
                    ...$config,
                    'path' => $path,
                    'contents' => $contents,
                    'mode' => ((int) $fileStat['mode']) & 0777,
                    'directory_mode' => ((int) $directoryStat['mode']) & 0777,
                    'owner' => $group['account'],
                    'facts' => [
                        ...($facts[$path] ?? ['config_path' => $path, 'account' => $group['account']]),
                        'credential_group' => $group['id'],
                        'shared_databases' => $group['databases'],
                        'shared_dependents' => $group['configs'],
                    ],
                ];
            }
        } finally {
            $sftp->disconnect();
        }

        return $targets;
    }

    private function checks(WhmServer $server, array $target, string $password): array
    {
        $started = microtime(true);
        $at = now()->utc()->toIso8601String();
        $database = $this->probe->database($server, $target, $password);
        $routes = $database['success'] ? $this->probe->routes($database['options']) : [
            'home' => ['reachable' => false, 'status' => null],
            'login' => ['reachable' => false, 'status' => null],
        ];

        return [
            'checked_at' => $at,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'database' => $database,
            ...$routes,
        ];
    }

    private function checksPassed(array $check): bool
    {
        return (bool) ($check['database']['success'] ?? false)
            && (bool) ($check['database']['select_1'] ?? false)
            && (bool) ($check['home']['reachable'] ?? false)
            && (bool) ($check['login']['reachable'] ?? false);
    }

    private function sameChecks(array $before, array $after): bool
    {
        $shape = fn (array $check): array => [
            'database_success' => (bool) ($check['database']['success'] ?? false),
            'select_1' => (bool) ($check['database']['select_1'] ?? false),
            'options' => $check['database']['options'] ?? [],
            'home_url' => $check['home']['url'] ?? null,
            'home_status' => $check['home']['status'] ?? null,
            'home_reachable' => (bool) ($check['home']['reachable'] ?? false),
            'login_url' => $check['login']['url'] ?? null,
            'login_status' => $check['login']['status'] ?? null,
            'login_reachable' => (bool) ($check['login']['reachable'] ?? false),
        ];

        return $shape($before) === $shape($after);
    }

    private function writePasswords(WhmServer $server, array $targets, string $password): array
    {
        $files = [];
        foreach ($targets as $target) {
            $files[] = [
                'path' => $target['path'],
                'contents' => $this->codec->withPassword($target['contents'], $target['password_span'], $password),
                'owner' => $target['owner'],
                'mode' => $target['mode'],
                'directory_mode' => $target['directory_mode'],
            ];
        }
        $results = $this->whm->remoteFiles()->writeMany($server, $files);
        foreach ($results as $result) {
            if (!($result['success'] ?? false)) {
                return ['success' => false, 'error' => 'Atomic wp-config.php publication failed: '.($result['error'] ?? 'unknown error')];
            }
        }
        foreach ($targets as $target) {
            $readback = $this->whm->remoteFiles()->read($server, $target['path']);
            $parsed = $this->codec->parse($readback);
            if (!hash_equals($password, $parsed['password'])) {
                return ['success' => false, 'error' => 'wp-config.php credential readback did not match the in-memory value.'];
            }
        }

        return ['success' => true];
    }

    private function rollback(
        WhmServer $server,
        array $group,
        array $targets,
        string $oldPassword,
        array $baseline,
        bool $configsChanged,
    ): array {
        $provider = $this->providerPassword($server, $group, $oldPassword);
        $configs = ['success' => true];
        if ($configsChanged) {
            $files = array_map(fn (array $target): array => [
                'path' => $target['path'],
                'contents' => $target['contents'],
                'owner' => $target['owner'],
                'mode' => $target['mode'],
                'directory_mode' => $target['directory_mode'],
            ], $targets);
            $results = $this->whm->remoteFiles()->writeMany($server, $files);
            $configs['success'] = !array_filter($results, fn (array $result): bool => !($result['success'] ?? false));
        }
        try {
            $restored = ($provider['success'] ?? false) && $configs['success'];
            foreach ($targets as $target) {
                $current = $this->checks($server, $target, $oldPassword);
                $restored = $restored && $this->sameChecks($baseline[$target['path']], $current);
            }
        } catch (Throwable) {
            $restored = false;
        }

        return $restored
            ? ['success' => true]
            : ['success' => false, 'error' => 'Original provider password, canonical configs, and baseline behavior were not all proved restored.'];
    }

    private function providerPassword(WhmServer $server, array $group, string $password): array
    {
        return $this->whm->api()->cpanelApiCall(
            $server,
            (string) $group['account'],
            'Mysql',
            'set_password',
            ['name' => (string) $group['database_user'], 'password' => $password],
        );
    }

    private function allConnectionsSucceed(WhmServer $server, array $targets, string $password): bool
    {
        foreach ($targets as $target) {
            if (!($this->probe->database($server, $target, $password)['success'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    private function siteReport(
        array $target,
        array $before,
        array $after,
        array $oldLogin,
        array $newLogin,
        string $change,
        string $configuration,
        string $recovery,
        ?string $error,
    ): array {
        return [
            'site' => $target['facts'],
            'before' => $before,
            'change' => [
                'outcome' => $change,
                'configuration' => $configuration,
                'scope' => [
                    'credential_group' => $target['facts']['credential_group'],
                    'databases' => $target['facts']['shared_databases'],
                    'dependent_configs' => $target['facts']['shared_dependents'],
                ],
            ],
            'new_login' => [
                'success' => (bool) ($newLogin['success'] ?? false),
                'select_1' => (bool) ($newLogin['select_1'] ?? false),
            ],
            'old_login' => [
                'rejected' => ($oldLogin['error_code'] ?? null) === 1045,
                'mysql_error_code' => $oldLogin['error_code'] ?? null,
            ],
            'after' => $after,
            'baseline_match' => $this->sameChecks($before, $after),
            'recovery' => ['outcome' => $recovery, 'error' => $error],
            'context' => $error ?? 'Database credential rotated and every required proof matched.',
        ];
    }

    private function unchangedFailure(
        array $record,
        array $targets,
        string $groupId,
        array $before,
        string $message,
        callable $reporter,
    ): array {
        $record['status'] = 'rotation_failed_unchanged';
        $record['groups'][$groupId]['rotation_state'] = 'failed_unchanged';
        $record['summary']['failed_group_count'] = (int) ($record['summary']['failed_group_count'] ?? 0) + 1;
        foreach ($targets as $target) {
            $report = $this->siteReport(
                $target, $before[$target['path']], $before[$target['path']],
                ['error_code' => null], ['success' => false],
                'not_changed', 'not_changed', 'not_needed', $message,
            );
            $record['site_reports'][$target['path']] = $report;
            $reporter('site_terminal_report', ['emoji' => "\u{1F4CB}", 'report' => $report]);
        }

        return ['record' => $record, 'result' => ['success' => false, 'error' => $message, 'rollback' => 'not_needed']];
    }

    private function rollbackFailure(
        array $record,
        array $targets,
        string $groupId,
        array $before,
        string $message,
        callable $reporter,
    ): array {
        $record['status'] = 'halted_rollback_failed';
        $record['groups'][$groupId]['rotation_state'] = 'halted';
        $record['summary']['failed_group_count'] = (int) ($record['summary']['failed_group_count'] ?? 0) + 1;
        foreach ($targets as $target) {
            $report = $this->siteReport(
                $target, $before[$target['path']], [],
                ['error_code' => null], ['success' => false],
                'uncertain', 'uncertain', 'recovery_required', $message,
            );
            $record['site_reports'][$target['path']] = $report;
            $reporter('site_terminal_report', ['emoji' => "\u{1F4CB}", 'report' => $report]);
        }

        return ['record' => $record, 'result' => [
            'success' => false,
            'error' => 'ROLLBACK_FAILED: '.$message.' The entire campaign is halted.',
            'rollback' => 'recovery_required',
        ]];
    }
}
