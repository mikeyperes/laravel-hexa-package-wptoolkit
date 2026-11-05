<?php

namespace hexa_package_wptoolkit\Console\Commands;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\WordPressDatabaseCampaignService;
use Illuminate\Console\Command;
use Throwable;

final class WordPressDatabaseCampaignCommand extends Command
{
    protected $signature = 'wordpress-database:campaign
        {action : inventory|inspect|rotate|status}
        {--server= : Exact WHM server database id}
        {--campaign= : Existing campaign id}
        {--group= : Exact database-user group id}
        {--security-evidence= : SHA-256 of the reviewed server-security evidence}
        {--authorized : Confirm the reviewed password rotation is authorized}';

    protected $description = 'Inventory WordPress databases, assess unmapped databases, and safely rotate one database-user group';

    public function handle(WordPressDatabaseCampaignService $campaigns): int
    {
        $action = strtolower(trim((string) $this->argument('action')));
        if (!in_array($action, ['inventory', 'inspect', 'rotate', 'status'], true)) {
            return $this->failResult('unsupported_action', 'Action must be inventory, inspect, rotate, or status.');
        }

        $serverId = filter_var($this->option('server'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($serverId === false) {
            return $this->failResult('server_required', 'Pass one exact positive WHM server id with --server.');
        }

        $server = WhmServer::query()->find((int) $serverId);
        if (!$server) {
            return $this->failResult('server_not_found', 'The selected WHM server does not exist.');
        }

        $reporter = function (string $event, array $details = []) use ($server): void {
            // CRITICAL — see BUGLOG.md WPDB-CAMPAIGN-002.
            if (in_array($event, ['installation_inspected', 'site_terminal_report'], true)) {
                $report = $details['report'] ?? [];
                $site = $report['site'] ?? [];
                if (trim((string) ($site['account'] ?? '')) === ''
                    || trim((string) ($site['config_path'] ?? $site['path'] ?? '')) === '') {
                    throw new \RuntimeException('Per-installation reporting requires a complete report with cPanel account and site identity.');
                }
                foreach (['before', 'change', 'new_login', 'old_login', 'after', 'recovery', 'context'] as $field) {
                    if (!array_key_exists($field, $report)) {
                        throw new \RuntimeException('The per-installation reporting guard rejected an incomplete report.');
                    }
                }
            }
            $payload = json_encode([
                'type' => 'progress',
                'event' => $event,
                'server' => (int) $server->id,
                'at' => now()->utc()->toIso8601String(),
                ...$details,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->line($payload);
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        };

        try {
            $result = match ($action) {
                'inventory' => $campaigns->inventory($server, $reporter),
                'inspect' => $campaigns->inspect(
                    $server,
                    $this->requiredOption('campaign'),
                    $reporter,
                ),
                'status' => $campaigns->status(
                    $server,
                    $this->requiredOption('campaign'),
                ),
                'rotate' => $this->rotate($campaigns, $server, $reporter),
            };
        } catch (Throwable $error) {
            return $this->failResult(
                'campaign_failed',
                mb_substr(trim($error->getMessage()) ?: 'The campaign operation failed.', 0, 1000),
            );
        }

        $this->line(json_encode([
            'type' => 'result',
            'action' => $action,
            'success' => (bool) ($result['success'] ?? true),
            'result' => $result,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return ($result['success'] ?? true) ? self::SUCCESS : self::FAILURE;
    }

    private function rotate(
        WordPressDatabaseCampaignService $campaigns,
        WhmServer $server,
        callable $reporter,
    ): array {
        if (!$this->option('authorized')) {
            throw new \RuntimeException('Rotation requires --authorized after the reviewed final-action gate is satisfied.');
        }

        $securityEvidence = strtolower(trim((string) $this->option('security-evidence')));
        if (preg_match('/\A[a-f0-9]{64}\z/D', $securityEvidence) !== 1) {
            throw new \RuntimeException('Rotation requires --security-evidence with the reviewed 64-character SHA-256.');
        }

        return $campaigns->rotate(
            $server,
            $this->requiredOption('campaign'),
            $this->requiredOption('group'),
            $securityEvidence,
            $reporter,
        );
    }

    private function requiredOption(string $name): string
    {
        $value = trim((string) $this->option($name));
        if ($value === '') {
            throw new \RuntimeException("The --{$name} option is required for this action.");
        }

        return $value;
    }

    private function failResult(string $code, string $message): int
    {
        $this->line(json_encode([
            'type' => 'result',
            'success' => false,
            'error' => ['code' => $code, 'message' => $message],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::FAILURE;
    }
}
