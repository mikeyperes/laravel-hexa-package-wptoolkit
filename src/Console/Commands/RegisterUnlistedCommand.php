<?php

namespace hexa_package_wptoolkit\Console\Commands;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Registers WordPress sites WP Toolkit never listed (addon and sub domains), so every tool can reach them.
 * The scheduler runs it with --if-due: a full sweep of every server's domains at most once every 14 days.
 * --domain rescans one domain at once, for a site a tool reports as not found.
 */
class RegisterUnlistedCommand extends Command
{
    protected $signature = 'wptoolkit:register-unlisted {--domain= : rescan only this domain} {--server= : one WHM server id} {--if-due : skip unless the last full sweep is 14 days old}';

    protected $description = 'Find WordPress sites on WHM domains that WP Toolkit does not list, and register them';

    private const LAST_SWEEP = 'wptoolkit:register-unlisted:last-sweep';

    public function handle(WpToolkitService $toolkit): int
    {
        $domain = $this->option('domain') ? (string) $this->option('domain') : null;
        if ($domain === null && $this->option('if-due') && Cache::get(self::LAST_SWEEP, 0) > now()->subDays(14)->timestamp) {
            $this->line('Skipped: the last full sweep was less than 14 days ago.');
            return self::SUCCESS;
        }
        $servers = $this->option('server') ? WhmServer::query()->whereKey((int) $this->option('server'))->get() : WhmServer::query()->orderBy('id')->get();
        $registered = [];
        $listed = false;
        foreach ($servers as $server) {
            $result = $toolkit->registerUnlistedSites($server, $domain);
            if (!($result['success'] ?? false)) {
                $this->warn($server->name . ': ' . ($result['error'] ?? $result['message'] ?? 'failed'));
                continue;
            }
            $listed = $listed || ($result['listed'] ?? false);
            foreach ($result['registered'] ?? [] as $site) {
                $registered[] = $site . ' (' . $server->name . ')';
            }
        }
        if ($domain === null) {
            Cache::forever(self::LAST_SWEEP, now()->timestamp);
        }
        foreach ($registered as $site) {
            $this->info('Registered: ' . $site);
        }
        if ($domain !== null && !$listed && !$registered) {
            $this->error("No WordPress site found for {$domain} on any server's domains.");
            return self::FAILURE;
        }
        $this->line($listed ? "{$domain} is already listed in WP Toolkit." : count($registered) . ' site(s) registered.');

        return self::SUCCESS;
    }
}
