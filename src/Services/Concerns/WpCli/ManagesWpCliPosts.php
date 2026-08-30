<?php

namespace hexa_package_wptoolkit\Services\Concerns\WpCli;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use Illuminate\Support\Facades\Cache;
use phpseclib3\Net\SSH2;

trait ManagesWpCliPosts
{
    /**
     * Create a WordPress post via WP Toolkit wp-cli.
     *
     * @param WhmServer $server
     * @param int       $installId  WP Toolkit install ID
     * @param string    $title      Post title
     * @param string    $content    Post HTML content
     * @param string    $status     publish|draft|future
     * @param array     $categoryIds WP category IDs
     * @param array     $tagIds     WP tag IDs
     * @param string|null $date     ISO date for scheduled posts
     * @return array{success: bool, message: string, data?: array}
     */
    public function wpCliCreatePost(WhmServer $server, int $installId, string $title, string $content, string $status = 'draft', array $categoryIds = [], array $tagIds = [], ?string $date = null, ?string $author = null, ?int $featuredMediaId = null, string $postType = 'post'): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'WP Toolkit connection failed'];
        }

        $connection = $ssh['connection'];
        $wpCliBase = $this->wpCliBaseCommand($server, $connection, $installId);
        $installPath = $this->resolveInstallPath($server, $connection, $installId);
        $tmpDirectory = $installPath ? rtrim($installPath, '/') : '/tmp';

        $tmpFiles = [];
        $tmpFile = $tmpDirectory . '/.hexa_wp_post_' . uniqid('', true) . '.html';
        $tmpFiles[] = $tmpFile;
        $output = '';

        try {
            $stageError = $this->stageWpCliTempFile($connection, $tmpFile, $content);
            if ($stageError !== null) {
                return ['success' => false, 'message' => 'Failed to stage post content for wp-cli: ' . $stageError];
            }

            $cmd = $wpCliBase . ' post create ' . escapeshellarg($tmpFile)
                . ' --post_title=' . escapeshellarg($title)
                . ' --post_status=' . escapeshellarg($status)
                . ' --post_type=' . escapeshellarg($postType)
                . ' --porcelain';

            if (!empty($categoryIds)) {
                $cmd .= ' --post_category=' . escapeshellarg(implode(',', $categoryIds));
            }
            if ($date && $status === 'future') {
                $cmd .= ' --post_date=' . escapeshellarg($date);
            }
            if ($author) {
                $wpUserId = $this->resolveWpAuthorId($server, $connection, $installId, (string) $author);
                if ($wpUserId !== null) {
                    $cmd .= ' --post_author=' . escapeshellarg($wpUserId);
                    if (!is_numeric((string) $author)) {
                        $this->generic->log('info', '[WpToolkit] Resolved author', ['username' => $author, 'wp_id' => $wpUserId]);
                    }
                } elseif (!is_numeric((string) $author)) {
                    $this->generic->log('warning', '[WpToolkit] Author not found on WP', ['username' => $author]);
                }
            }

            $this->generic->log('info', '[WpToolkit] wpCliCreatePost', ['install_id' => $installId, 'title' => $title, 'status' => $status, 'author' => $author]);
            $output = trim($this->execWithConnection($connection, $cmd . ' 2>&1'));
        } finally {
            foreach ($tmpFiles as $file) {
                $this->execWithConnection($connection, 'rm -f ' . escapeshellarg($file));
            }
        }

        $postId = null;
        $blockingLines = [];
        $insidePhpDiagnostic = false;
        foreach (preg_split('/\R/', $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (is_numeric($line)) {
                $postId = (int) $line;
                $insidePhpDiagnostic = false;
                continue;
            }
            if (str_starts_with($line, 'Deprecated:') || str_starts_with($line, 'Warning:') || str_starts_with($line, 'Notice:') || str_starts_with($line, 'PHP ') || str_starts_with($line, 'PHP:')) {
                $insidePhpDiagnostic = true;
                continue;
            }
            if ($insidePhpDiagnostic && ($line === "array (" || str_starts_with($line, '\'') || str_starts_with($line, "#") || $line === ")" || $line === ")]") ) {
                continue;
            }
            $insidePhpDiagnostic = false;
            $blockingLines[] = $line;
        }

        if ($postId !== null && empty($blockingLines)) {
            if (!empty($tagIds)) {
                $tagIdsStr = implode(',', array_map('intval', $tagIds));
                $tagPhp = '<?php wp_set_post_tags(' . $postId . ', [' . $tagIdsStr . ']);';
                $tagTmpFile = $tmpDirectory . '/.hexa_wp_tags_' . uniqid('', true) . '.php';
                $tmpFiles[] = $tagTmpFile;
                $this->stageWpCliTempFile($connection, $tagTmpFile, $tagPhp);
                $tagCmd = $wpCliBase . ' eval-file ' . escapeshellarg($tagTmpFile) . ' 2>&1';
                $this->execWithConnection($connection, $tagCmd);
                $this->execWithConnection($connection, 'rm -f ' . escapeshellarg($tagTmpFile));
                $this->generic->log('info', '[WpToolkit] Tags set via wp_set_post_tags', ['post_id' => $postId, 'tag_ids' => $tagIds]);
            }

            if ($featuredMediaId) {
                $metaCmd = $wpCliBase . ' post meta update ' . escapeshellarg((string) $postId) . ' _thumbnail_id ' . escapeshellarg((string) $featuredMediaId) . ' 2>&1';
                $this->execWithConnection($connection, $metaCmd);
                $this->generic->log('info', '[WpToolkit] Featured image set', ['post_id' => $postId, 'media_id' => $featuredMediaId]);
            }

            $urlCmd = $wpCliBase . ' post get ' . escapeshellarg((string) $postId) . ' --field=url 2>&1';
            $postUrl = trim($this->execWithConnection($connection, $urlCmd));
            if (!str_starts_with($postUrl, 'http')) {
                $postUrl = null;
            }

            $this->generic->log('info', '[WpToolkit] Post created', ['post_id' => $postId, 'url' => $postUrl]);
            return [
                'success' => true,
                'message' => 'Post created (ID: ' . $postId . ')',
                'data'    => ['post_id' => $postId, 'post_url' => $postUrl],
            ];
        }

        if ($postId !== null) {
            $this->execWithConnection($connection, $wpCliBase . ' post delete ' . escapeshellarg((string) $postId) . ' --force 2>&1');
            $this->generic->log('error', '[WpToolkit] wpCliCreatePost produced post id with blocking output', ['post_id' => $postId, 'blocking_lines' => $blockingLines]);
        }

        $errorOutput = !empty($blockingLines) ? implode(PHP_EOL, $blockingLines) : $output;
        $this->generic->log('error', '[WpToolkit] wpCliCreatePost failed', ['output' => $output, 'blocking_lines' => $blockingLines]);
        return ['success' => false, 'message' => 'wp-cli post create failed: ' . \Illuminate\Support\Str::limit($errorOutput, 300)];
    }
    /**
     * Update an existing WordPress post via wp-cli.
     *
     * @param WhmServer $server
     * @param int $installId
     * @param int $postId
     * @param array $postData
     * @return array{success: bool, message: string, data?: array}
     */
    public function wpCliUpdatePost(WhmServer $server, int $installId, int $postId, array $postData): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'WP Toolkit connection failed'];
        }

        $connection = $ssh['connection'];
        $wpCliBase = $this->wpCliBaseCommand($server, $connection, $installId);
        $installPath = $this->resolveInstallPath($server, $connection, $installId);
        $tmpDirectory = $installPath ? rtrim($installPath, '/') : '/tmp';

        $tmpFiles = [];
        $tmpFile = null;
        if (array_key_exists('content', $postData)) {
            $tmpFile = $tmpDirectory . '/.hexa_wp_post_' . uniqid('', true) . '.html';
            $tmpFiles[] = $tmpFile;
            $stageError = $this->stageWpCliTempFile($connection, $tmpFile, (string) ($postData['content'] ?? ''));
            if ($stageError !== null) {
                foreach ($tmpFiles as $file) {
                    $this->execWithConnection($connection, 'rm -f ' . escapeshellarg($file));
                }
                return ['success' => false, 'message' => 'Failed to stage post content for wp-cli: ' . $stageError];
            }
        }

        $cmd = "{$wpCliBase} post update " . escapeshellarg((string) $postId);
        if ($tmpFile) {
            $cmd .= ' ' . escapeshellarg($tmpFile);
        }
        if (array_key_exists('title', $postData)) {
            $cmd .= ' --post_title=' . escapeshellarg((string) $postData['title']);
        }
        if (array_key_exists('status', $postData)) {
            $cmd .= ' --post_status=' . escapeshellarg((string) $postData['status']);
        }
        if (array_key_exists('excerpt', $postData)) {
            $cmd .= ' --post_excerpt=' . escapeshellarg((string) ($postData['excerpt'] ?? ''));
        }
        if (!empty($postData['categories'])) {
            $cmd .= ' --post_category=' . escapeshellarg(implode(',', array_map('intval', $postData['categories'])));
        }
        if (!empty($postData['date'])) {
            $cmd .= ' --post_date=' . escapeshellarg((string) $postData['date']);
        }
        if (!empty($postData['author'])) {
            $author = (string) $postData['author'];
            $wpUserId = $this->resolveWpAuthorId($server, $connection, $installId, $author);
            if ($wpUserId !== null) {
                $cmd .= ' --post_author=' . escapeshellarg($wpUserId);
            }
        }

        $output = trim($this->execWithConnection($connection, $cmd . ' 2>&1'));

        try {
            if (str_contains($output, 'Success:')) {
                if (array_key_exists('tags', $postData) && is_array($postData['tags'])) {
                    $tagIds = array_values(array_filter(array_map('intval', $postData['tags'])));
                    $tagPhp = '<?php wp_set_post_tags(' . $postId . ', [' . implode(',', $tagIds) . ']);';
                    $tagTmpFile = $tmpDirectory . '/.hexa_wp_tags_' . uniqid('', true) . '.php';
                    $tmpFiles[] = $tagTmpFile;
                    $this->stageWpCliTempFile($connection, $tagTmpFile, $tagPhp);
                    $tagCmd = "{$wpCliBase} eval-file " . escapeshellarg($tagTmpFile) . ' 2>&1';
                    $this->execWithConnection($connection, $tagCmd);
                }

                if (array_key_exists('featured_media', $postData) && !empty($postData['featured_media'])) {
                    $metaCmd = "{$wpCliBase} post meta update {$postId} _thumbnail_id " . escapeshellarg((string) ((int) $postData['featured_media'])) . ' 2>&1';
                    $this->execWithConnection($connection, $metaCmd);
                }

                $details = $this->wpCliGetPost($server, $installId, $postId);
                if ($details['success']) {
                    return [
                        'success' => true,
                        'message' => "Post updated (ID: {$postId})",
                        'data' => $details['data'],
                    ];
                }

                return [
                    'success' => true,
                    'message' => "Post updated (ID: {$postId})",
                    'data' => [
                        'post_id' => $postId,
                        'post_url' => null,
                        'post_status' => $postData['status'] ?? null,
                        'post_date' => $postData['date'] ?? null,
                    ],
                ];
            }
        } finally {
            foreach ($tmpFiles as $file) {
                $this->execWithConnection($connection, 'rm -f ' . escapeshellarg($file));
            }
        }

        $this->generic->log('error', '[WpToolkit] wpCliUpdatePost failed', ['output' => $output, 'post_id' => $postId]);
        return ['success' => false, 'message' => 'wp-cli post update failed: ' . \Illuminate\Support\Str::limit($output, 300)];
    }

    /**
     * Fetch an existing WordPress post via wp-cli.
     *
     * @param WhmServer $server
     * @param int $installId
     * @param int $postId
     * @return array{success: bool, message: string, data?: array}
     */
    public function wpCliGetPost(WhmServer $server, int $installId, int $postId): array
    {
        $php = '$post = get_post(' . $postId . '); '
            . 'if (!$post) { echo "HEXA_POST_DETAILS:" . base64_encode("null"); return; } '
            . '$author = get_userdata((int) $post->post_author); '
            . '$payload = ['
            . '"post_id" => (int) $post->ID, '
            . '"post_url" => get_permalink($post), '
            . '"post_status" => (string) $post->post_status, '
            . '"post_title" => (string) $post->post_title, '
            . '"post_date" => (string) $post->post_date, '
            . '"author_id" => (int) $post->post_author, '
            . '"author_name" => $author ? (string) $author->display_name : "", '
            . '"post_content" => (string) $post->post_content, '
            . ']; '
            . 'echo "HEXA_POST_DETAILS:" . base64_encode(wp_json_encode($payload));';

        $result = $this->wpCliEval($server, $installId, $php);
        $output = trim((string) ($result['stdout'] ?? ''));

        if (!preg_match('~HEXA_POST_DETAILS:([A-Za-z0-9+/=]+)~', $output, $matches)) {
            $this->generic->log('error', '[WpToolkit] wpCliGetPost failed', ['output' => $output, 'post_id' => $postId]);
            return ['success' => false, 'message' => 'wp-cli post get failed: ' . \Illuminate\Support\Str::limit($output, 300)];
        }

        $decoded = base64_decode($matches[1], true);
        $json = is_string($decoded) ? json_decode($decoded, true) : null;

        if (!is_array($json) || empty($json['post_id'])) {
            $this->generic->log('error', '[WpToolkit] wpCliGetPost returned invalid data', ['output' => $output, 'post_id' => $postId]);
            return ['success' => false, 'message' => 'wp-cli post get returned invalid data.'];
        }

        return [
            'success' => true,
            'message' => "Post fetched (ID: {$postId})",
            'data' => [
                'post_id' => (int) ($json['post_id'] ?? $postId),
                'post_url' => $json['post_url'] ?? null,
                'post_status' => $json['post_status'] ?? null,
                'post_title' => $json['post_title'] ?? '',
                'post_date' => $json['post_date'] ?? null,
                'author_id' => isset($json['author_id']) ? (int) $json['author_id'] : null,
                'author_name' => (string) ($json['author_name'] ?? ''),
                'post_content' => (string) ($json['post_content'] ?? ''),
            ],
        ];
    }

    /**
     * Upload media to WordPress via WP Toolkit wp-cli (downloads URL to server, imports it).
     *
     * @param WhmServer $server
     * @param int       $installId
     * @param string    $imageUrl   URL of the image to upload
     * @param string    $filename   Desired filename
     * @param string    $altText    Alt text for the image
     * @return array{success: bool, message: string, data?: array}
     */
    /**
     * Upload media to WordPress via WP Toolkit wp-cli with full SEO metadata.
     *
     * @param WhmServer $server
     * @param int       $installId
     * @param string    $imageUrl     Source image URL
     * @param string    $filename     Desired filename (e.g. hexa_29_energy-crisis.jpg)
     * @param string    $altText      Alt text for SEO
     * @param string    $caption      Image caption
     * @param string    $description  Image description
     * @return array
     */
}
