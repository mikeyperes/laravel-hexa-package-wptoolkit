<?php

namespace hexa_package_wptoolkit\Services;

use hexa_package_whm\Models\WhmServer;
use Illuminate\Support\Str;
use RuntimeException;

final class WordPressDatabaseCampaignService
{
    private const STORE_DIRECTORY = 'app/private/wordpress-database-campaigns';

    public function __construct(
        private WordPressDatabaseInventoryService $inventoryService,
        private WordPressDatabaseRotationService $rotationService,
    ) {
    }

    public function inventory(WhmServer $server, callable $reporter): array
    {
        $campaignId = (string) Str::uuid();
        $reporter('inventory_started', [
            'emoji' => "\u{1F50E}",
            'campaign' => $campaignId,
            'server' => $server->id,
        ]);
        $record = $this->inventoryService->capture($server, $campaignId, $reporter);
        $record['status'] = 'inventory_complete';
        $record = $this->persist($record);
        $reporter('inventory_complete', [
            'emoji' => "\u{1F4CA}",
            'campaign' => $campaignId,
            'server' => $server->id,
            'summary' => $record['summary'],
            'report_artifacts' => $record['report_artifacts'],
        ]);

        return $record;
    }

    public function inspect(WhmServer $server, string $campaignId, callable $reporter): array
    {
        $stored = $this->load($server, $campaignId);
        $reporter('inspection_started', [
            'emoji' => "\u{1F50E}",
            'campaign' => $campaignId,
            'server' => $server->id,
        ]);
        $fresh = $this->inventoryService->capture($server, $campaignId, $reporter);
        $fresh['identity_matches_inventory'] = hash_equals(
            (string) ($stored['inventory_identity'] ?? ''),
            (string) ($fresh['inventory_identity'] ?? ''),
        );
        $fresh['previous_inventory_identity'] = $stored['inventory_identity'] ?? null;
        if ($fresh['identity_matches_inventory']) {
            $fresh = $this->mergeProgress($fresh, $stored);
        }
        $fresh['status'] = $fresh['identity_matches_inventory']
            ? 'inspection_complete'
            : 'inventory_changed';
        $fresh = $this->persist($fresh);
        $reporter('inspection_complete', [
            'emoji' => "\u{1F4CA}",
            'campaign' => $campaignId,
            'identity_matches_inventory' => $fresh['identity_matches_inventory'],
            'summary' => $fresh['summary'],
            'report_artifacts' => $fresh['report_artifacts'],
        ]);

        return $fresh;
    }

    public function status(WhmServer $server, string $campaignId): array
    {
        return $this->load($server, $campaignId);
    }

    public function rotate(
        WhmServer $server,
        string $campaignId,
        string $groupId,
        string $securityEvidence,
        callable $reporter,
    ): array {
        $stored = $this->load($server, $campaignId);
        if (($stored['status'] ?? '') === 'halted_rollback_failed') {
            throw new RuntimeException('The campaign is halted because an earlier rollback could not be proved.');
        }
        if (($stored['groups'][$groupId]['rotation_state'] ?? '') === 'rotated') {
            throw new RuntimeException('The selected credential group is already recorded as rotated in this campaign.');
        }

        $reporter('rotation_preflight_started', [
            'emoji' => "\u{1F510}",
            'campaign' => $campaignId,
            'group' => $groupId,
            'server' => $server->id,
        ]);
        $fresh = $this->inventoryService->capture($server, $campaignId, $reporter);
        if (!hash_equals(
            (string) ($stored['inventory_identity'] ?? ''),
            (string) ($fresh['inventory_identity'] ?? ''),
        )) {
            $fresh['status'] = 'inventory_changed';
            $this->persist($fresh);
            throw new RuntimeException('Server inventory changed; run inspect and review the new state before rotation.');
        }
        $fresh = $this->mergeProgress($fresh, $stored);

        $outcome = $this->rotationService->rotate(
            $server,
            $fresh,
            $groupId,
            $securityEvidence,
            $reporter,
        );
        $record = $this->persist($outcome['record']);
        $result = $outcome['result'];
        $result['report_artifacts'] = $record['report_artifacts'];
        $reporter('rotation_terminal', [
            'emoji' => ($result['success'] ?? false) ? "\u{2705}" : "\u{274C}",
            'campaign' => $campaignId,
            'group' => $groupId,
            'success' => (bool) ($result['success'] ?? false),
            'summary' => $record['summary'],
            'report_artifacts' => $record['report_artifacts'],
        ]);

        return $result;
    }

    private function persist(array $record): array
    {
        $paths = $this->artifactPaths((string) $record['campaign']);
        $record['report_artifacts'] = [
            'json' => $paths['json'],
            'csv' => $paths['csv'],
        ];
        $json = json_encode(
            $record,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        if (file_put_contents($paths['json'], $json."\n", LOCK_EX) === false) {
            throw new RuntimeException('Campaign JSON report could not be saved.');
        }
        chmod($paths['json'], 0600);
        $this->writeCsv($paths['csv'], $record);

        return $record;
    }

    private function load(WhmServer $server, string $campaignId): array
    {
        $path = $this->artifactPaths($campaignId)['json'];
        if (!is_file($path)) {
            throw new RuntimeException('Campaign record was not found.');
        }
        $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($record) || (int) ($record['server']['id'] ?? 0) !== (int) $server->id) {
            throw new RuntimeException('Campaign record is not bound to the selected server.');
        }

        return $record;
    }

    private function artifactPaths(string $campaignId): array
    {
        if (preg_match('/\A[0-9a-f-]{36}\z/iD', $campaignId) !== 1) {
            throw new RuntimeException('Invalid campaign id.');
        }
        $directory = storage_path(self::STORE_DIRECTORY);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Campaign storage directory could not be created.');
        }
        chmod($directory, 0700);

        return [
            'json' => $directory.'/'.$campaignId.'.json',
            'csv' => $directory.'/'.$campaignId.'.csv',
        ];
    }

    private function writeCsv(string $path, array $record): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Campaign CSV report could not be opened.');
        }
        try {
            $header = [
                'row_type', 'server', 'account', 'identifier', 'path', 'safe_url',
                'database', 'database_user', 'inventory_source', 'source_confidence',
                'status', 'attached_sites', 'table_count', 'size_bytes',
                'created_at', 'created_source', 'created_confidence',
                'last_used_at', 'last_used_source', 'last_used_confidence',
                'context', 'assessment', 'references', 'unknowns',
            ];
            fputcsv($handle, $header);
            foreach ($record['installations'] ?? [] as $installation) {
                $this->csvRow($handle, [
                    'installation',
                    $record['server']['name'] ?? $record['server']['id'],
                    $installation['account'] ?? null,
                    $installation['toolkit_id'] ?? $installation['config_path'],
                    $installation['path'] ?? null,
                    $installation['wordpress_base_url'] ?? $installation['toolkit_url'] ?? null,
                    $installation['database'] ?? null,
                    $installation['database_user'] ?? null,
                    $installation['inventory_source'] ?? null,
                    $installation['inventory_confidence'] ?? null,
                    ($installation['database_check']['success'] ?? false) ? 'inspected' : 'failed_or_blocked',
                    $installation['shared_dependents'] ?? [],
                    null,
                    null,
                    null,
                    'unavailable',
                    'unavailable',
                    null,
                    'unavailable',
                    'unavailable',
                    $record['site_reports'][$installation['config_path']]['context'] ?? null,
                    $record['site_reports'][$installation['config_path']]['change']['outcome'] ?? null,
                    $installation['config_path'] ?? null,
                    [],
                ]);
            }
            foreach ($record['databases'] ?? [] as $database) {
                $this->csvRow($handle, [
                    'database',
                    $database['server'] ?? $record['server']['name'] ?? null,
                    $database['account'] ?? null,
                    $database['database'] ?? null,
                    null,
                    $database['account_base_url'] ?? null,
                    $database['database'] ?? null,
                    $database['users_and_grants'] ?? [],
                    ($database['unmapped'] ?? false) ? 'unmapped' : 'mapped',
                    $database['wordpress_base_urls_confidence'] ?? null,
                    ($database['unmapped'] ?? false) ? 'unmapped_inspected' : 'in_use',
                    $database['attached_installations'] ?? [],
                    $database['table_count'] ?? null,
                    $database['size_bytes'] ?? null,
                    $database['database_created_at'] ?? $database['earliest_table_created_at'] ?? null,
                    $database['database_created_at_source'] ?? $database['earliest_table_created_at_source'] ?? null,
                    $database['database_created_at_confidence'] ?? $database['earliest_table_created_at_confidence'] ?? null,
                    $database['last_used_at'] ?? null,
                    $database['last_used_at_source'] ?? null,
                    $database['last_used_at_confidence'] ?? null,
                    $database['description'] ?? null,
                    $database['safe_delete_assessment'] ?? null,
                    $database['references'] ?? [],
                    $database['unknowns'] ?? [],
                ]);
            }
        } finally {
            fclose($handle);
        }
        chmod($path, 0600);
    }

    private function csvRow($handle, array $cells): void
    {
        fputcsv($handle, array_map($this->csvCell(...), $cells));
    }

    private function csvCell(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } elseif ($value === null) {
            $value = '';
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } else {
            $value = (string) $value;
        }
        if (preg_match('/\A[\x00-\x20]*[=+\-@]/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    private function mergeProgress(array $fresh, array $stored): array
    {
        foreach ($stored['groups'] ?? [] as $groupId => $storedGroup) {
            if (!isset($fresh['groups'][$groupId])) {
                continue;
            }
            if (in_array(
                $storedGroup['rotation_state'] ?? '',
                ['rotated', 'failed_restored', 'failed_unchanged', 'halted'],
                true,
            )) {
                foreach (['rotation_state', 'rotation_proof', 'rollback_failure'] as $field) {
                    if (array_key_exists($field, $storedGroup)) {
                        $fresh['groups'][$groupId][$field] = $storedGroup[$field];
                    }
                }
            }
        }
        $fresh['site_reports'] = array_replace(
            $fresh['site_reports'] ?? [],
            $stored['site_reports'] ?? [],
        );
        foreach (['rotated_group_count', 'restored_rotation_count', 'failed_group_count'] as $counter) {
            $fresh['summary'][$counter] = max(
                (int) ($fresh['summary'][$counter] ?? 0),
                (int) ($stored['summary'][$counter] ?? 0),
            );
        }

        return $fresh;
    }
}
