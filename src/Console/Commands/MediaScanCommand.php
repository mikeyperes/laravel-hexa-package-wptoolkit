<?php

namespace hexa_package_wptoolkit\Console\Commands;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Console\Command;

/**
 * Read-only media library search for one WordPress site: resolves the
 * installation through WP Toolkit, runs the existing media selector once per
 * search term, and returns one merged list with thumbnail, medium and full URLs.
 */
class MediaScanCommand extends Command
{
    protected $signature = 'wptoolkit:media-scan
        {domain : Site domain, e.g. jpnmiami.com}
        {--search=* : Search term (repeatable); none lists the newest media}
        {--mime=image : MIME filter (image, image/png, application/pdf, video; "all" for every type)}
        {--limit=100 : Most items per search term (max 500)}
        {--server= : WHM server id (default: every server until the site is found)}
        {--json : Output JSON}';

    protected $description = 'Search one WordPress site\'s media library through WP Toolkit (read-only) and return merged findings with thumbnail, medium and full URLs.';

    public function handle(WpToolkitService $toolkit): int
    {
        $domain = strtolower((string) preg_replace('~^(https?://)?(www\.)?~i', '', rtrim((string) $this->argument('domain'), '/')));
        [$server, $install] = $this->resolveInstall($toolkit, $domain);
        if ($install === null) {
            return $this->finish(['success' => false, 'error' => 'No WP Toolkit installation found for '.$domain.'.']);
        }

        $terms = array_values(array_filter(array_map('trim', (array) $this->option('search')), 'strlen')) ?: [''];
        $mime = (string) $this->option('mime');
        $mime = $mime === 'all' ? '' : $mime;
        $limit = max(1, min(500, (int) $this->option('limit')));

        $items = [];
        $searches = [];
        foreach ($terms as $term) {
            $found = 0;
            $page = 1;
            do {
                $result = $toolkit->wpCliMediaSelector($server, (int) $install['id'], [
                    'search' => $term, 'mime_type' => $mime, 'page' => $page, 'per_page' => min(100, $limit),
                ]);
                if (! ($result['success'] ?? false)) {
                    return $this->finish(['success' => false, 'error' => (string) ($result['message'] ?? 'Media search failed.')]);
                }
                foreach ((array) ($result['items'] ?? []) as $item) {
                    $id = (int) $item['id'];
                    $items[$id] ??= [
                        'id' => $id,
                        'title' => (string) ($item['title'] ?? ''),
                        'filename' => (string) ($item['filename'] ?? ''),
                        'mime_type' => (string) ($item['mime_type'] ?? ''),
                        'width' => (int) ($item['width'] ?? 0),
                        'height' => (int) ($item['height'] ?? 0),
                        'date' => (string) ($item['date'] ?? ''),
                        'alt_text' => (string) ($item['alt_text'] ?? ''),
                        'thumbnail_url' => (string) ($item['thumbnail_url'] ?? ''),
                        'medium_url' => (string) ($item['medium_url'] ?? ''),
                        'full_url' => (string) ($item['full_url'] ?? ''),
                        'edit_url' => rtrim((string) $install['url'], '/').'/wp-admin/post.php?post='.$id.'&action=edit',
                        'matched' => [],
                    ];
                    if ($term !== '' && ! in_array($term, $items[$id]['matched'], true)) {
                        $items[$id]['matched'][] = $term;
                    }
                    $found++;
                }
                $page++;
            } while (($result['has_more'] ?? false) && $found < $limit);
            $searches[] = ['term' => $term === '' ? '(all)' : $term, 'found' => $found, 'total' => (int) ($result['total'] ?? $found)];
        }

        usort($items, static fn ($a, $b) => [count($b['matched']), $b['date']] <=> [count($a['matched']), $a['date']]);

        return $this->finish([
            'success' => true,
            'site' => ['domain' => $domain, 'name' => $install['name'] ?? '', 'url' => $install['url'] ?? '', 'install_id' => (int) $install['id'], 'server' => $server->name],
            'mime' => $mime === '' ? 'all' : $mime,
            'searches' => $searches,
            'count' => count($items),
            'items' => array_values($items),
        ]);
    }

    /**
     * @return array{0: WhmServer|null, 1: array<string, mixed>|null}
     */
    private function resolveInstall(WpToolkitService $toolkit, string $domain): array
    {
        $servers = $this->option('server')
            ? WhmServer::query()->whereKey((int) $this->option('server'))->get()
            : WhmServer::query()->orderBy('id')->get();
        foreach ($servers as $server) {
            $listing = $toolkit->getAllInstalls($server);
            foreach ((array) ($listing['installs'] ?? []) as $install) {
                $host = strtolower((string) preg_replace('~^www\.~', '', (string) parse_url((string) ($install['url'] ?? ''), PHP_URL_HOST)));
                if ($host === $domain) {
                    return [$server, $install];
                }
            }
        }

        return [null, null];
    }

    /** @param array<string, mixed> $result */
    private function finish(array $result): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif ($result['success']) {
            $this->info($result['site']['name'].' ('.$result['site']['url'].') — '.$result['count'].' item(s)');
            foreach ($result['items'] as $item) {
                $this->line($item['id'].'  '.$item['filename'].'  '.$item['width'].'x'.$item['height'].'  '.$item['full_url']);
            }
        } else {
            $this->error($result['error']);
        }

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }
}
