<?php

namespace hexa_package_wptoolkit\Services;

use hexa_package_whm\Models\WhmServer;
use hexa_package_whm\Services\WhmService;
use RuntimeException;

final class WordPressDatabaseOrphanInspector
{
    public function __construct(private WhmService $whm)
    {
    }

    public function inspect(
        WhmServer $server,
        array $installations,
        array $provider,
        array $accounts,
        callable $reporter,
    ): array {
        $mapped = array_fill_keys(array_values(array_filter(
            array_column($installations, 'database'),
            'is_string',
        )), true);
        $orphans = [];

        foreach ($provider['databases'] as $database => $providerDatabase) {
            if (isset($mapped[$database])) {
                continue;
            }
            $account = (string) $providerDatabase['account'];
            $tables = $this->tableMetadata($server, $database);
            $options = $this->optionsMetadata($server, $database);
            $references = $this->references(
                $server,
                $account,
                $database,
                $provider['crons'][$account] ?? [],
            );
            $protectedAccount = in_array($account, ['hexawebsystems', 'scalemypublicati'], true);
            $hasReference = $references['config_paths'] !== [] || $references['cpanel_cron_reference_count'] > 0;
            $assessment = ($protectedAccount || $hasReference)
                ? 'not_safe_to_delete'
                : 'manual_review_required';
            $reasons = [];
            if ($protectedAccount) {
                $reasons[] = 'Database belongs to a protected application account (Billing, Code, or Publish scope).';
            }
            if ($hasReference) {
                $reasons[] = 'Application config or cPanel job references this database.';
            }
            if ($reasons === []) {
                $reasons[] = 'No mapped WP Toolkit installation was found, but absence of scanned evidence is not deletion proof.';
            }

            $accountDomain = trim((string) ($accounts[$account]['domain'] ?? ''));
            $report = [
                'server' => (string) $server->name,
                'account' => $account,
                'account_created_at' => $accounts[$account]['start_date'] ?? null,
                'account_created_at_source' => 'WHM cPanel account start date; not database creation',
                'account_created_at_confidence' => ($accounts[$account]['start_date'] ?? null) ? 'medium' : 'unavailable',
                'account_base_url' => $accountDomain !== '' ? 'https://'.$accountDomain : null,
                'account_base_url_source' => $accountDomain !== '' ? 'WHM primary domain' : 'unavailable',
                'account_base_url_confidence' => $accountDomain !== '' ? 'medium' : 'unavailable',
                'database' => $database,
                'users_and_grants' => $this->grants($provider['grants'], $database),
                'size_bytes' => $providerDatabase['size_bytes'] ?? $tables['size_bytes'],
                'table_count' => $tables['table_count'],
                'database_created_at' => null,
                'database_created_at_source' => 'unavailable',
                'database_created_at_confidence' => 'unavailable',
                'earliest_table_created_at' => $tables['earliest_table_created_at'],
                'earliest_table_created_at_source' => 'information_schema.tables.CREATE_TIME; estimate only',
                'earliest_table_created_at_confidence' => $tables['earliest_table_created_at'] ? 'low' : 'unavailable',
                'last_used_at' => null,
                'last_used_at_source' => 'unavailable',
                'last_used_at_confidence' => 'unavailable',
                'latest_table_update_at' => $tables['latest_table_update_at'],
                'latest_table_update_at_source' => 'information_schema.tables.UPDATE_TIME; data change only, not last use',
                'latest_table_update_at_confidence' => $tables['latest_table_update_at'] ? 'low' : 'unavailable',
                'current_connection_count' => $tables['current_connection_count'],
                'current_connection_count_source' => 'information_schema.processlist at inspection time',
                'wordpress_options' => $options,
                'wordpress_base_urls' => $this->optionUrls($options),
                'wordpress_base_urls_source' => 'every *_options table home/siteurl row',
                'wordpress_base_urls_confidence' => $options !== [] ? 'high' : 'unavailable',
                'description' => $this->description($options, $tables['table_count']),
                'references' => $references,
                'attached_installations' => [],
                'unmapped' => true,
                'excluded_from_rotation' => true,
                'exclusion_reason' => 'No wp-config.php mapping exists in the complete campaign inventory.',
                'safe_delete_assessment' => $assessment,
                'assessment_reasons' => $reasons,
                'unknowns' => [
                    'True database creation time is unavailable.',
                    'True last-read or last-use time is unavailable.',
                    'External consumers outside this account and cPanel jobs may exist.',
                ],
            ];
            $orphans[] = $report;
            $reporter('orphan_database_inspected', [
                'emoji' => "\u{1F5C4}",
                'server' => $server->id,
                'database' => $database,
                'report' => $report,
            ]);
        }
        usort($orphans, fn (array $a, array $b): int => [$a['account'], $a['database']] <=> [$b['account'], $b['database']]);

        return $orphans;
    }

    public function mappedReport(
        WhmServer $server,
        string $database,
        array $providerDatabase,
        array $grants,
        array $account,
        array $attachments,
    ): array {
        $tables = $this->tableMetadata($server, $database);
        $options = $this->optionsMetadata($server, $database);
        $domain = trim((string) ($account['domain'] ?? ''));

        return [
            'server' => (string) $server->name,
            'account' => (string) $providerDatabase['account'],
            'account_created_at' => $account['start_date'] ?? null,
            'account_created_at_source' => 'WHM cPanel account start date; not database creation',
            'account_created_at_confidence' => ($account['start_date'] ?? null) ? 'medium' : 'unavailable',
            'account_base_url' => $domain !== '' ? 'https://'.$domain : null,
            'account_base_url_source' => $domain !== '' ? 'WHM primary domain' : 'unavailable',
            'account_base_url_confidence' => $domain !== '' ? 'medium' : 'unavailable',
            'database' => $database,
            'users_and_grants' => $this->grants($grants, $database),
            'size_bytes' => $providerDatabase['size_bytes'] ?? $tables['size_bytes'],
            'table_count' => $tables['table_count'],
            'database_created_at' => null,
            'database_created_at_source' => 'unavailable',
            'database_created_at_confidence' => 'unavailable',
            'earliest_table_created_at' => $tables['earliest_table_created_at'],
            'earliest_table_created_at_source' => 'information_schema.tables.CREATE_TIME; estimate only',
            'earliest_table_created_at_confidence' => $tables['earliest_table_created_at'] ? 'low' : 'unavailable',
            'last_used_at' => null,
            'last_used_at_source' => 'unavailable',
            'last_used_at_confidence' => 'unavailable',
            'latest_table_update_at' => $tables['latest_table_update_at'],
            'latest_table_update_at_source' => 'information_schema.tables.UPDATE_TIME; data change only, not last use',
            'latest_table_update_at_confidence' => $tables['latest_table_update_at'] ? 'low' : 'unavailable',
            'current_connection_count' => $tables['current_connection_count'],
            'current_connection_count_source' => 'information_schema.processlist at inspection time',
            'wordpress_options' => $options,
            'wordpress_base_urls' => $this->optionUrls($options),
            'wordpress_base_urls_source' => 'every *_options table home/siteurl row',
            'wordpress_base_urls_confidence' => $options !== [] ? 'high' : 'unavailable',
            'description' => 'Mapped WordPress database used by '.count($attachments).' installation(s).',
            'references' => [
                'config_paths' => array_column($attachments, 'config_path'),
                'cpanel_cron_reference_count' => null,
                'config_scan_complete' => true,
            ],
            'attached_installations' => $attachments,
            'unmapped' => false,
            'excluded_from_rotation' => false,
            'exclusion_reason' => null,
            'safe_delete_assessment' => 'in_use_keep',
            'assessment_reasons' => ['One or more inventoried WordPress installations use this database.'],
            'unknowns' => [
                'True database creation time is unavailable.',
                'True last-read or last-use time is unavailable.',
            ],
        ];
    }

    private function tableMetadata(WhmServer $server, string $database): array
    {
        $literal = $this->sqlLiteral($database);
        $sql = "SELECT COUNT(*),COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0),"
            ."COALESCE(DATE_FORMAT(MIN(CREATE_TIME),'%Y-%m-%dT%H:%i:%sZ'),''),"
            ."COALESCE(DATE_FORMAT(MAX(UPDATE_TIME),'%Y-%m-%dT%H:%i:%sZ'),'') "
            ."FROM information_schema.tables WHERE table_schema={$literal}";
        $columns = explode("\t", trim($this->mysql($server, $sql)));
        $connections = trim($this->mysql(
            $server,
            "SELECT COUNT(*) FROM information_schema.processlist WHERE DB={$literal}",
        ));

        return [
            'table_count' => isset($columns[0]) ? (int) $columns[0] : null,
            'size_bytes' => isset($columns[1]) ? (int) $columns[1] : null,
            'earliest_table_created_at' => ($columns[2] ?? '') !== '' ? $columns[2] : null,
            'latest_table_update_at' => ($columns[3] ?? '') !== '' ? $columns[3] : null,
            'current_connection_count' => is_numeric($connections) ? (int) $connections : null,
        ];
    }

    private function optionsMetadata(WhmServer $server, string $database): array
    {
        if (preg_match('/\A[A-Za-z0-9_$-]+\z/D', $database) !== 1) {
            return [];
        }
        $tables = $this->mysql(
            $server,
            "SELECT table_name FROM information_schema.tables WHERE table_schema="
                .$this->sqlLiteral($database)." AND table_name LIKE '%\\_options' ESCAPE '\\\\'",
        );
        $options = [];
        foreach (array_filter(array_map('trim', preg_split('/\R/', $tables) ?: [])) as $table) {
            if (preg_match('/\A[A-Za-z0-9_$-]+\z/D', $table) !== 1) {
                continue;
            }
            $rows = $this->mysql(
                $server,
                "SELECT option_name,REPLACE(REPLACE(option_value,'\\t',' '),'\\n',' ') "
                    ."FROM \x60{$database}\x60.\x60{$table}\x60 WHERE option_name IN ('home','siteurl','blogname')",
            );
            $values = [];
            foreach (array_filter(preg_split('/\R/', $rows) ?: []) as $row) {
                [$key, $value] = array_pad(explode("\t", $row, 2), 2, '');
                $values[$key] = mb_substr($value, 0, 500);
            }
            if ($values !== []) {
                ksort($values);
                $options[] = ['table' => $table, ...$values];
            }
        }

        return $options;
    }

    private function references(
        WhmServer $server,
        string $account,
        string $database,
        array $cronCommands,
    ): array {
        $cronCount = count(array_filter(
            $cronCommands,
            fn (string $command): bool => str_contains($command, $database),
        ));
        $command = 'rg -l --fixed-strings --max-filesize 2M '
            ."--glob '*.php' --glob '*.env' --glob '*.ini' --glob '*.json' --glob '*.yml' --glob '*.yaml' "
            ."--glob '!vendor/**' --glob '!cache/**' --glob '!tmp/**' --glob '!backup*/**' "
            .escapeshellarg($database).' '.escapeshellarg('/home/'.$account)
            .' 2>/dev/null | head -200';
        $result = $this->whm->executeCommandWithTimeout($server, $command, 120);
        $paths = ($result['success'] ?? false)
            ? array_values(array_filter(array_map(
                'trim',
                preg_split('/\R/', (string) ($result['output'] ?? '')) ?: [],
            )))
            : [];

        return [
            'config_paths' => $paths,
            'cpanel_cron_reference_count' => $cronCount,
            'config_scan_complete' => (bool) ($result['success'] ?? false),
        ];
    }

    private function mysql(WhmServer $server, string $sql): string
    {
        $result = $this->whm->executeCommandWithTimeout(
            $server,
            'mysql --batch --raw --skip-column-names -e '.escapeshellarg($sql),
            120,
        );
        if (!($result['success'] ?? false)) {
            throw new RuntimeException('Read-only MySQL metadata query failed.');
        }

        return (string) ($result['output'] ?? '');
    }

    private function grants(array $grants, string $database): array
    {
        $matches = [];
        foreach ($grants as $user => $databases) {
            if (isset($databases[$database])) {
                $matches[] = [
                    'user' => $user,
                    'privileges' => $databases[$database]['privileges'] ?? [],
                ];
            }
        }

        return $matches;
    }

    private function optionUrls(array $options): array
    {
        $urls = [];
        foreach ($options as $row) {
            foreach (['home', 'siteurl'] as $key) {
                if (($row[$key] ?? '') !== '') {
                    $urls[] = ['table' => $row['table'], 'type' => $key, 'url' => $row[$key]];
                }
            }
        }

        return $urls;
    }

    private function description(array $options, ?int $tableCount): string
    {
        if ($options !== []) {
            $url = $options[0]['siteurl'] ?? $options[0]['home'] ?? null;
            return 'Unmapped WordPress-shaped database'.($url ? ' for '.$url : '').'.';
        }

        return ($tableCount ?? 0) > 0
            ? 'Unmapped non-WordPress or unrecognized application database with tables.'
            : 'Unmapped empty database.';
    }

    private function sqlLiteral(string $value): string
    {
        return "'".str_replace(["\\", "'"], ["\\\\", "\\'"], $value)."'";
    }
}
