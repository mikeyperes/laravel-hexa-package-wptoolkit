<?php

namespace hexa_package_wptoolkit\Console\Commands;

use hexa_package_whm\Models\WhmServer;
use hexa_package_whm\Services\WhmService;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Console\Command;

class LoginCommand extends Command
{
    protected $signature = 'wptoolkit:login
        {domain : Exact site domain}
        {--system=wordpress : wordpress, cpanel, both, or whm}
        {--binding= : Previously resolved domain/server/account/install binding as JSON}
        {--wp-user= : Explicit WordPress administrator}
        {--reseller= : Explicit WHM reseller}';

    protected $description = 'Generate 60-minute single-use login links using the existing WP Toolkit and WHM services.';

    public function handle(WpToolkitService $toolkit, WhmService $whm): int
    {
        $domain = $this->host((string) $this->argument('domain'));
        $system = strtolower((string) $this->option('system'));
        if ($domain === '' || !in_array($system, ['wordpress', 'cpanel', 'both', 'whm'], true)) {
            return $this->finish(['success' => false, 'error' => 'Supply an exact domain and a supported system.']);
        }

        try {
            $binding = $this->option('binding')
                ? json_decode((string) $this->option('binding'), true, 32, JSON_THROW_ON_ERROR) : [];
            if (!is_array($binding) || ($binding && $this->host($binding['domain'] ?? '') !== $domain)) {
                return $this->finish(['success' => false, 'error' => 'The binding must belong to the requested domain.']);
            }
            if (empty($binding['server_id']) || empty($binding['username'])) {
                $resolved = $whm->resolveCachedDomain($domain, ['refresh_on_miss' => true]);
                if (!($resolved['success'] ?? false)) {
                    return $this->finish(['success' => false, 'error' => $resolved['message'] ?? 'Hosting account not found.']);
                }
                $account = $resolved['account'];
                $username = $system === 'whm'
                    ? ((string) $this->option('reseller') ?: ($account['owner'] ?? '')) : $account['username'];
                if ($system === 'whm' && ($username === '' || $username === 'root')) {
                    return $this->finish(['success' => false, 'error' => 'Specify the intended WHM reseller.']);
                }
                $binding = ['domain' => $domain, 'server_id' => $account['server_id'], 'username' => $username];
            }
            $server = WhmServer::query()->find($binding['server_id']);
            if (!$server) {
                return $this->finish(['success' => false, 'error' => 'Configured hosting server not found.']);
            }
            if ($system === 'whm' && $this->option('reseller')) {
                $binding['username'] = (string) $this->option('reseller');
            }

            $links = [];
            if (in_array($system, ['wordpress', 'both'], true)) {
                if (empty($binding['install_id']) || empty($binding['wp_path']) || empty($binding['site_url'])) {
                    $inventory = $toolkit->getInstallsForAccount($server, $binding['username']);
                    $matches = array_values(array_filter($inventory['installs'] ?? [],
                        fn (array $install): bool => $this->host($install['url'] ?? '') === $domain));
                    if (($inventory['success'] ?? false) && count($matches) === 1) {
                        $install = $matches[0];
                        $binding = array_replace($binding, ['install_id' => $install['id'],
                            'wp_path' => $install['path'], 'site_url' => $install['url']]);
                    } else {
                        $links['wordpress'] = ['success' => false, 'error' => 'No unique WordPress installation found.'];
                    }
                }
                if (!isset($links['wordpress'])) {
                    if ($this->host($binding['site_url']) !== $domain) {
                        $links['wordpress'] = ['success' => false, 'error' => 'WordPress URL does not match the requested domain.'];
                    } else {
                        $links['wordpress'] = $toolkit->generateWordPressLoginUrlForInstall($server,
                            (int) $binding['install_id'], $binding['wp_path'], $binding['username'],
                            $binding['site_url'], (string) $this->option('wp-user'));
                    }
                }
            }
            if (in_array($system, ['cpanel', 'both', 'whm'], true)) {
                $panel = $system === 'whm' ? 'whm' : 'cpanel';
                $links[$panel] = $whm->createLoginLink($server, $binding['username'],
                    $panel === 'whm' ? 'whostmgrd' : 'cpaneld');
            }
            return $this->finish(['success' => collect($links)->every(fn (array $link): bool => (bool) ($link['success'] ?? false)),
                'binding' => $binding, 'links' => $links]);
        } catch (\JsonException) {
            return $this->finish(['success' => false, 'error' => 'Invalid binding JSON.']);
        } catch (\Throwable $exception) {
            report($exception);
            return $this->finish(['success' => false, 'error' => 'Login generation failed; see the protected application log.']);
        }
    }

    private function host(string $value): string
    {
        $value = trim($value);
        $host = strtolower((string) parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST));
        return preg_replace('/^www\./', '', $host) ?? '';
    }

    private function finish(array $result): int
    {
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return ($result['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
