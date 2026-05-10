<?php

namespace hexa_package_wptoolkit\Services\Concerns;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use Illuminate\Support\Facades\Cache;
use phpseclib3\Net\SSH2;

/**
 * ManagesWpCli — WP-CLI operations: posts, media, categories, tags.
 */
trait ManagesWpCli
{
    /**
     * Create a WordPress post via wp-cli SSH.
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
    public function wpCliCreatePost(WhmServer $server, int $installId, string $title, string $content, string $status = 'draft', array $categoryIds = [], array $tagIds = [], ?string $date = null, ?string $author = null, ?int $featuredMediaId = null): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $escapedId = escapeshellarg((string) $installId);
        $wptBin = $this->shellBinary($connection, $server);

        // Write content to temp file on server (avoids shell escaping issues with HTML)
        $tmpFile = '/tmp/hexa_wp_post_' . uniqid() . '.html';
        $this->execWithConnection($connection, 'cat > ' . escapeshellarg($tmpFile) . ' << \'HEXAEOF\'' . "\n" . $content . "\nHEXAEOF");

        // Build wp post create command
        $cmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- post create"
            . " --post_title=" . escapeshellarg($title)
            . " --post_status=" . escapeshellarg($status)
            . " --post_content=\"$(cat " . escapeshellarg($tmpFile) . ")\""
            . " --porcelain";

        if (!empty($categoryIds)) {
            $cmd .= " --post_category=" . escapeshellarg(implode(',', $categoryIds));
        }
        // Tags set after post creation via wp_set_post_tags (--tags_input expects names, not IDs)
        if ($date && $status === 'future') {
            $cmd .= " --post_date=" . escapeshellarg($date);
        }
        if ($author) {
            if (is_numeric($author)) {
                $cmd .= " --post_author=" . escapeshellarg($author);
            } else {
                $userCmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- user get " . escapeshellarg($author) . " --field=ID 2>/dev/null";
                $userLookup = $this->runCommandWithExitCode($connection, $userCmd . ' 2>&1');
                $rawId = trim((string) ($userLookup['clean_output'] ?? ''));
                $wpUserId = '';
                foreach (explode("\n", $rawId) as $ul) { $ul = trim($ul); if (is_numeric($ul)) { $wpUserId = $ul; break; } }
                if ($wpUserId) {
                    $cmd .= " --post_author=" . escapeshellarg($wpUserId);
                    $this->generic->log('info', '[WpToolkit] Resolved author', ['username' => $author, 'wp_id' => $wpUserId]);
                } else {
                    $this->generic->log('warning', '[WpToolkit] Author not found on WP', ['username' => $author]);
                }
            }
        }

        $this->generic->log('info', '[WpToolkit] wpCliCreatePost', ['install_id' => $installId, 'title' => $title, 'status' => $status, 'author' => $author]);

        $command = $this->runCommandWithExitCode($connection, $cmd . ' 2>&1');
        $output = trim((string) ($command['clean_output'] ?? ''));

        // Cleanup temp file
        $this->execWithConnection($connection, 'rm -f ' . escapeshellarg($tmpFile));

        // --porcelain returns just the post ID
        if (is_numeric($output)) {
            $postId = (int) $output;

            // Set tags via wp_set_post_tags (IDs, not names)
            if (!empty($tagIds)) {
                $tagIdsStr = implode(',', array_map('intval', $tagIds));
                $tagPhp = base64_encode('wp_set_post_tags(' . $postId . ', [' . $tagIdsStr . ']); echo "TAGS_SET";');
                $tagCmd = "CODE=\$(echo '{$tagPhp}' | base64 -d) && {$wptBin} --wp-cli -instance-id {$escapedId} -- eval \"\$CODE\" 2>&1";
                $this->execWithConnection($connection, $tagCmd);
                $this->generic->log('info', '[WpToolkit] Tags set via wp_set_post_tags', ['post_id' => $postId, 'tag_ids' => $tagIds]);
            }

            // Set featured image if provided
            if ($featuredMediaId) {
                $metaCmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- post meta update {$postId} _thumbnail_id {$featuredMediaId} 2>&1";
                $this->execWithConnection($connection, $metaCmd);
                $this->generic->log('info', '[WpToolkit] Featured image set', ['post_id' => $postId, 'media_id' => $featuredMediaId]);
            }

            // Get permalink
            $urlCmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- post get {$postId} --field=url 2>&1";
            $urlLookup = $this->runCommandWithExitCode($connection, $urlCmd);
            $postUrl = trim((string) ($urlLookup['clean_output'] ?? ''));
            if (!str_starts_with($postUrl, 'http')) $postUrl = null;

            $this->generic->log('info', '[WpToolkit] Post created', ['post_id' => $postId, 'url' => $postUrl]);
            return [
                'success' => true,
                'message' => "Post created (ID: {$postId})",
                'data'    => ['post_id' => $postId, 'post_url' => $postUrl],
            ];
        }

        $this->generic->log('error', '[WpToolkit] wpCliCreatePost failed', ['output' => $output]);
        return ['success' => false, 'message' => 'wp-cli post create failed: ' . \Illuminate\Support\Str::limit($output, 300)];
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
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $escapedId = escapeshellarg((string) $installId);
        $wptBin = $this->shellBinary($connection, $server);

        $tmpFiles = [];
        $tmpFile = null;
        if (array_key_exists('content', $postData)) {
            $tmpFile = '/tmp/hexa_wp_post_' . uniqid('', true) . '.html';
            $tmpFiles[] = $tmpFile;
            $contentBase64 = base64_encode((string) ($postData['content'] ?? ''));
            $writeCmd = 'printf %s ' . escapeshellarg($contentBase64) . ' | base64 -d > ' . escapeshellarg($tmpFile);
            $this->execWithConnection($connection, $writeCmd);
        }

        $cmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- post update " . escapeshellarg((string) $postId);
        if (array_key_exists('title', $postData)) {
            $cmd .= ' --post_title=' . escapeshellarg((string) $postData['title']);
        }
        if (array_key_exists('status', $postData)) {
            $cmd .= ' --post_status=' . escapeshellarg((string) $postData['status']);
        }
        if (array_key_exists('excerpt', $postData)) {
            $cmd .= ' --post_excerpt=' . escapeshellarg((string) ($postData['excerpt'] ?? ''));
        }
        if ($tmpFile) {
            $cmd .= ' --post_content="$(cat ' . escapeshellarg($tmpFile) . ')"';
        }
        if (!empty($postData['categories'])) {
            $cmd .= ' --post_category=' . escapeshellarg(implode(',', array_map('intval', $postData['categories'])));
        }
        if (!empty($postData['date'])) {
            $cmd .= ' --post_date=' . escapeshellarg((string) $postData['date']);
        }
        if (!empty($postData['author'])) {
            $author = (string) $postData['author'];
            if (is_numeric($author)) {
                $cmd .= ' --post_author=' . escapeshellarg($author);
            } else {
                $userCmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- user get " . escapeshellarg($author) . ' --field=ID 2>/dev/null';
                $userLookup = $this->runCommandWithExitCode($connection, $userCmd . ' 2>&1');
                $rawId = trim((string) ($userLookup['clean_output'] ?? ''));
                $wpUserId = '';
                foreach (explode("\n", $rawId) as $line) {
                    $line = trim($line);
                    if (is_numeric($line)) {
                        $wpUserId = $line;
                        break;
                    }
                }
                if ($wpUserId !== '') {
                    $cmd .= ' --post_author=' . escapeshellarg($wpUserId);
                }
            }
        }

        $command = $this->runCommandWithExitCode($connection, $cmd . ' 2>&1');
        $output = trim((string) ($command['clean_output'] ?? ''));

        try {
            $updateSucceeded = str_contains($output, 'Success:') || ((int) ($command['exit_code'] ?? 1) === 0 && !str_contains(strtolower($output), 'error:'));
            if ($updateSucceeded) {
                if (array_key_exists('tags', $postData) && is_array($postData['tags'])) {
                    $tagIds = array_values(array_filter(array_map('intval', $postData['tags'])));
                    $tagPhp = '<?php wp_set_post_tags(' . $postId . ', [' . implode(',', $tagIds) . ']);';
                    $tagTmpFile = '/tmp/hexa_wp_tags_' . uniqid('', true) . '.php';
                    $tmpFiles[] = $tagTmpFile;
                    $tagWriteCmd = 'printf %s ' . escapeshellarg(base64_encode($tagPhp)) . ' | base64 -d > ' . escapeshellarg($tagTmpFile);
                    $this->execWithConnection($connection, $tagWriteCmd);
                    $tagCmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- eval-file " . escapeshellarg($tagTmpFile) . ' 2>&1';
                    $this->runCommandWithExitCode($connection, $tagCmd);
                }

                if (array_key_exists('featured_media', $postData) && !empty($postData['featured_media'])) {
                    $metaCmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- post meta update {$postId} _thumbnail_id " . escapeshellarg((string) ((int) $postData['featured_media'])) . ' 2>&1';
                    $this->runCommandWithExitCode($connection, $metaCmd);
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
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $escapedId = escapeshellarg((string) $installId);
        $wptBin = $this->shellBinary($connection, $server);
        $cmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- post get " . escapeshellarg((string) $postId) . ' --format=json 2>&1';
        $probe = $this->runCommandWithExitCode($connection, $cmd);
        $output = trim((string) ($probe['clean_output'] ?? ''));
        $json = json_decode($output, true);

        if (!is_array($json) || empty($json['ID'])) {
            $this->generic->log('error', '[WpToolkit] wpCliGetPost failed', ['output' => $output, 'post_id' => $postId]);
            return ['success' => false, 'message' => 'wp-cli post get failed: ' . \Illuminate\Support\Str::limit($output, 300)];
        }

        return [
            'success' => true,
            'message' => "Post fetched (ID: {$postId})",
            'data' => [
                'post_id' => (int) ($json['ID'] ?? $postId),
                'post_url' => $json['url'] ?? null,
                'post_status' => $json['post_status'] ?? null,
                'post_title' => $json['post_title'] ?? '',
                'post_date' => $json['post_date'] ?? null,
            ],
        ];
    }

    /**
     * Upload media to WordPress via wp-cli SSH (downloads URL to server, imports it).
     *
     * @param WhmServer $server
     * @param int       $installId
     * @param string    $imageUrl   URL of the image to upload
     * @param string    $filename   Desired filename
     * @param string    $altText    Alt text for the image
     * @return array{success: bool, message: string, data?: array}
     */
    /**
     * Upload media to WordPress via wp-cli SSH with full SEO metadata.
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
    public function wpCliUploadMedia(WhmServer $server, int $installId, string $imageUrl, string $filename = '', string $altText = '', string $caption = '', string $description = ''): array
    {
        $imageUrl = trim(html_entity_decode($imageUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $escapedId = escapeshellarg((string) $installId);
        $wptBin = $this->shellBinary($connection, $server);
        $installPath = $this->resolveInstallPath($server, $connection, $installId);
        if (!$installPath) {
            return ['success' => false, 'message' => 'Unable to resolve WordPress install path for media import'];
        }

        // Download inside the WordPress install path so wp-toolkit/wp-cli can actually see the file.
        $parsedPath = parse_url($imageUrl, PHP_URL_PATH) ?: '';
        $ext = pathinfo($parsedPath, PATHINFO_EXTENSION) ?: 'jpg';
        $ext = preg_replace('/[^a-zA-Z0-9].*/', '', $ext);
        if (!in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'])) $ext = 'jpg';
        $targetFilename = $filename ?: ('hexa-upload-' . uniqid() . '.' . $ext);
        if (!preg_match('/\.\w{2,5}$/', $targetFilename)) $targetFilename .= '.' . $ext;
        $tmpDir = rtrim($installPath, '/') . '/.hexa-import-' . uniqid();
        $tmpFile = $tmpDir . '/' . $targetFilename;
        $this->execWithConnection($connection, "mkdir -p " . escapeshellarg($tmpDir));
        $curlCmd = "curl -fsSL --compressed --connect-timeout 8 --max-time 25 -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0.0.0' -o "
            . escapeshellarg($tmpFile)
            . " "
            . escapeshellarg($imageUrl)
            . " && chmod 644 "
            . escapeshellarg($tmpFile)
            . " 2>/dev/null";
        $this->execWithConnection($connection, $curlCmd);
        $fileSize = trim($this->execWithConnection($connection, "stat -c%s " . escapeshellarg($tmpFile) . " 2>/dev/null || echo 0"));
        if (!$fileSize || $fileSize === '0') {
            $this->execWithConnection($connection, "rm -rf " . escapeshellarg($tmpDir));
            $this->generic->log('error', '[WpToolkit] Image download failed', [
                'url' => $imageUrl,
                'filename' => $targetFilename,
                'install_path' => $installPath,
            ]);
            return ['success' => false, 'message' => 'Failed to download image from: ' . \Illuminate\Support\Str::limit($imageUrl, 100)];
        }
        $this->generic->log('info', '[WpToolkit] Image downloaded', ['url' => \Illuminate\Support\Str::limit($imageUrl, 100), 'size' => $fileSize, 'filename' => $targetFilename]);

        $titleArg = $filename ? " --title=" . escapeshellarg(pathinfo($filename, PATHINFO_FILENAME)) : '';
        $fileNameArg = $filename ? " --file_name=" . escapeshellarg(pathinfo($filename, PATHINFO_FILENAME)) : '';
        $altArg = $altText ? " --alt=" . escapeshellarg($altText) : '';
        $captionArg = $caption ? " --caption=" . escapeshellarg($caption) : '';
        $descriptionArg = $description ? " --desc=" . escapeshellarg($description) : '';
        $cmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- media import "
            . escapeshellarg($tmpFile)
            . $fileNameArg
            . $titleArg
            . $captionArg
            . $altArg
            . $descriptionArg
            . " --porcelain 2>&1";
        $import = $this->runCommandWithExitCode($connection, $cmd);
        $this->execWithConnection($connection, "rm -rf " . escapeshellarg($tmpDir));

        $output = '';
        foreach ($import['lines'] as $line) {
            if (is_numeric($line)) {
                $output = $line;
                break;
            }
        }

        if (is_numeric($output)) {
            $mediaId = (int) $output;

            // Set all metadata via single wp eval (alt, caption, description, hexa marker)
            $metaPhp = '$id=' . $mediaId . ';'
                . ($altText ? 'update_post_meta($id,"_wp_attachment_image_alt",' . json_encode($altText) . ');' : '')
                . 'update_post_meta($id,"_hexa_generated","true");'
                . 'update_post_meta($id,"_hexa_upload_time","' . date('Y-m-d H:i:s') . '");'
                . 'wp_update_post(["ID"=>$id'
                . ($caption ? ',"post_excerpt"=>' . json_encode($caption) : '')
                . ($description ? ',"post_content"=>' . json_encode($description) : '')
                . ']);'
                // Get all sizes + file path + file size
                . '$src=wp_get_attachment_url($id);'
                . '$file=get_attached_file($id);'
                . '$fsize=$file&&file_exists($file)?filesize($file):0;'
                . '$relpath=str_replace(ABSPATH,"",$file);'
                . '$sizes_list=["thumbnail","medium","medium_large","large","full"];'
                . '$all=["full"=>$src];'
                . 'foreach($sizes_list as $s){$img=wp_get_attachment_image_src($id,$s);if($img) $all[$s]=$img[0];}'
                . 'echo "HEXA_MEDIA:".json_encode(["sizes"=>$all,"file_path"=>$relpath,"file_size"=>$fsize,"media_id"=>$id]);';
            $phpCode = base64_encode($metaPhp);
            $metaCmd = "CODE=\$(echo '{$phpCode}' | base64 -d) && {$wptBin} --wp-cli -instance-id {$installId} -- eval \"\$CODE\" 2>&1";
            $metaOutput = trim($this->execWithConnection($connection, $metaCmd));

            $sizes = [];
            $mediaUrl = '';
            $filePath = '';
            $fileSize = 0;
            foreach (explode("\n", $metaOutput) as $sLine) {
                if (str_contains($sLine, 'HEXA_MEDIA:')) {
                    $json = substr($sLine, strpos($sLine, 'HEXA_MEDIA:') + 11);
                    $parsed = json_decode(trim($json), true) ?: [];
                    $sizes = $parsed['sizes'] ?? [];
                    $mediaUrl = $sizes['full'] ?? $sizes['large'] ?? '';
                    $filePath = $parsed['file_path'] ?? '';
                    $fileSize = $parsed['file_size'] ?? 0;
                    break;
                }
            }

            $this->generic->log('info', '[WpToolkit] Media uploaded', ['media_id' => $mediaId, 'url' => $mediaUrl, 'sizes' => count($sizes)]);
            return [
                'success' => true,
                'message' => "Media uploaded (ID: {$mediaId})",
                'data'    => [
                    'media_id' => $mediaId,
                    'media_url' => $mediaUrl,
                    'sizes' => $sizes,
                    'source_url' => $imageUrl,
                    'filename' => $filename,
                    'file_path' => $filePath,
                    'file_size' => $fileSize,
                    'alt_text' => $altText,
                    'caption' => $caption,
                    'description' => $description,
                ],
            ];
        }

        $cleanOutput = $import['clean_output'] ?: $import['raw_output'];
        $this->generic->log('error', '[WpToolkit] wpCliUploadMedia failed', [
            'exit_code' => $import['exit_code'],
            'output' => $cleanOutput,
            'raw_output' => $import['raw_output'],
            'install_path' => $installPath,
            'tmp_file' => $tmpFile,
        ]);
        return ['success' => false, 'message' => 'wp-cli media import failed: ' . \Illuminate\Support\Str::limit($cleanOutput ?: 'unknown error', 300)];
    }

    protected function resolveInstallPath(WhmServer $server, SSH2|LocalShellConnection $connection, int $installId): ?string
    {
        $cacheKey = (string) $installId;
        if (!empty($this->installInfoCache[$cacheKey]['fullPath'])) {
            return rtrim((string) $this->installInfoCache[$cacheKey]['fullPath'], '/');
        }

        $escapedId = escapeshellarg((string) $installId);
        $info = $this->runCommandWithExitCode($connection, $this->shellBinary($connection, $server) . " --info -instance-id {$escapedId} -format json 2>&1");
        $parsed = json_decode($info['raw_output'], true);
        $fullPath = $parsed['fullPath'] ?? null;

        if (!is_string($fullPath) || trim($fullPath) === '') {
            $this->generic->log('error', '[WpToolkit] resolveInstallPath failed', [
                'install_id' => $installId,
                'exit_code' => $info['exit_code'],
                'output' => $info['clean_output'] ?: $info['raw_output'],
            ]);
            return null;
        }

        $this->installInfoCache[$cacheKey] = $parsed;

        return rtrim($fullPath, '/');
    }

    protected function execWithConnection(SSH2|LocalShellConnection $connection, string $cmd): string
    {
        if (method_exists($connection, 'setTimeout')) {
            $connection->setTimeout($this->commandTimeoutSeconds());
        }

        try {
            $output = (string) $connection->exec($cmd);
        } catch (\RuntimeException $e) {
            if ($connection instanceof SSH2 && str_contains($e->getMessage(), 'Please close the channel')) {
                $this->disconnectCachedConnection(connection: $connection);
            }

            throw $e;
        }

        if ($connection instanceof SSH2 && $connection->isTimeout()) {
            $timeout = $this->commandTimeoutSeconds();
            $this->disconnectCachedConnection(connection: $connection);
            $this->generic->log('error', '[WpToolkit] SSH command timed out', [
                'timeout' => $timeout,
                'command' => \Illuminate\Support\Str::limit($cmd, 160),
            ]);

            throw new \RuntimeException("WP Toolkit SSH command timed out after {$timeout} seconds");
        }

        return $output;
    }

    protected function runCommandWithExitCode(SSH2|LocalShellConnection $connection, string $cmd): array
    {
        $marker = '__HEXA_CMD_EXIT__';
        $raw = $this->execWithConnection($connection, $cmd . '; status=$?; printf "\\n' . $marker . ':%s\\n" "$status"');

        $exitCode = null;
        if (preg_match('/' . preg_quote($marker, '/') . ':(\d+)/', $raw, $matches)) {
            $exitCode = (int) $matches[1];
            $raw = preg_replace('/\n?' . preg_quote($marker, '/') . ':\d+\s*$/', '', $raw);
        }

        $raw = trim($raw);
        $lines = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $lower = strtolower($line);
            if (
                str_contains($lower, 'using null as an array offset is deprecated')
                || str_contains($lower, 'php-cli-tools/lib/cli/colors.php')
                || str_contains($lower, 'colors.php on line 95')
            ) {
                continue;
            }
            $lines[] = $line;
        }

        return [
            'exit_code' => $exitCode,
            'raw_output' => $raw,
            'clean_output' => implode("\n", $lines),
            'lines' => $lines,
        ];
    }

    /**
     * Create or get a WordPress category via wp-cli SSH.
     *
     * @param WhmServer $server
     * @param int       $installId
     * @param string    $name Category name
     * @return array{success: bool, term_id?: int, message: string}
     */
    public function wpCliCreateCategory(WhmServer $server, int $installId, string $name): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $escapedId = escapeshellarg((string) $installId);
        $wptBin = $this->shellBinary($connection, $server);

        // Check if category exists first
        $checkCmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- term list category --field=term_id --name=" . escapeshellarg($name) . " --format=csv 2>&1";
        $existing = trim($this->execWithConnection($connection, $checkCmd));
        $lines = array_filter(explode("\n", $existing), fn($l) => is_numeric(trim($l)));
        if (!empty($lines)) {
            $termId = (int) trim($lines[array_key_first($lines)]);
            return ['success' => true, 'term_id' => $termId, 'message' => "Category exists (ID: {$termId})"];
        }

        // Create it
        $cmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- term create category " . escapeshellarg($name) . " --porcelain 2>&1";
        $output = trim($this->execWithConnection($connection, $cmd));

        if (is_numeric($output)) {
            return ['success' => true, 'term_id' => (int) $output, 'message' => "Category created (ID: {$output})"];
        }

        return ['success' => false, 'message' => 'Failed to create category: ' . \Illuminate\Support\Str::limit($output, 200)];
    }

    /**
     * Create or get a WordPress tag via wp-cli SSH.
     *
     * @param WhmServer $server
     * @param int       $installId
     * @param string    $name Tag name
     * @return array{success: bool, term_id?: int, message: string}
     */
    public function wpCliCreateTag(WhmServer $server, int $installId, string $name): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $escapedId = escapeshellarg((string) $installId);
        $wptBin = $this->shellBinary($connection, $server);

        // Check if tag exists
        $checkCmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- term list post_tag --field=term_id --name=" . escapeshellarg($name) . " --format=csv 2>&1";
        $existing = trim($this->execWithConnection($connection, $checkCmd));
        $lines = array_filter(explode("\n", $existing), fn($l) => is_numeric(trim($l)));
        if (!empty($lines)) {
            $termId = (int) trim($lines[array_key_first($lines)]);
            return ['success' => true, 'term_id' => $termId, 'message' => "Tag exists (ID: {$termId})"];
        }

        // Create it
        $cmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- term create post_tag " . escapeshellarg($name) . " --porcelain 2>&1";
        $output = trim($this->execWithConnection($connection, $cmd));

        if (is_numeric($output)) {
            return ['success' => true, 'term_id' => (int) $output, 'message' => "Tag created (ID: {$output})"];
        }

        return ['success' => false, 'message' => 'Failed to create tag: ' . \Illuminate\Support\Str::limit($output, 200)];
    }

    /**
     * Test write permissions on a WordPress install via wp-cli SSH.
     * Creates and immediately deletes a test post.
     *
     * @param WhmServer $server
     * @param int       $installId
     * @return array{success: bool, message: string}
     */
    public function wpCliTestWriteAccess(WhmServer $server, int $installId): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $escapedId = escapeshellarg((string) $installId);
        $wptBin = $this->shellBinary($connection, $server);

        // Get admin user info first
        $userCmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- user list --role=administrator --fields=user_login,display_name --format=csv 2>&1";
        $userOutput = trim($this->execWithConnection($connection, $userCmd));
        $adminUser = '';
        $adminDisplay = '';
        foreach (explode("\n", $userOutput) as $line) {
            $line = trim($line);
            if (empty($line) || $line === 'user_login,display_name' || str_starts_with($line, 'Deprecated:')) continue;
            $parts = str_getcsv($line, ',', '"', '');
            if (!empty($parts[0]) && $parts[0] !== 'user_login') {
                $adminUser = $parts[0];
                $adminDisplay = $parts[1] ?? $parts[0];
                break;
            }
        }

        // Create a test post
        $cmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- post create --post_title='Hexa Write Test' --post_status=draft --porcelain 2>&1";
        $output = trim($this->execWithConnection($connection, $cmd));

        // Filter warnings
        foreach (explode("\n", $output) as $line) {
            if (is_numeric(trim($line))) { $output = trim($line); break; }
        }

        if (is_numeric($output)) {
            $postId = (int) $output;
            $this->execWithConnection($connection, "{$wptBin} --wp-cli -instance-id {$escapedId} -- post delete {$postId} --force 2>&1");
            return [
                'success' => true,
                'message' => "WordPress connection established — write access confirmed as {$adminDisplay} ({$adminUser}), administrator",
                'admin_user' => $adminUser,
                'admin_display' => $adminDisplay,
                'admin_role' => 'administrator',
            ];
        }

        return ['success' => false, 'message' => 'Write test failed: ' . \Illuminate\Support\Str::limit($output, 200)];
    }

    public function wpCliListAdminUsers(WhmServer $server, int $installId, bool $forceRefresh = false): array
    {
        $cacheKey = 'wptoolkit:publish-authors:' . $server->id . ':' . $installId;
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        $cached = Cache::get($cacheKey);
        if (!$forceRefresh && is_array($cached)) {
            $cached['cache_hit'] = true;
            $cached['cached_at'] = $cached['cached_at'] ?? null;
            $cached['expires_at'] = $cached['expires_at'] ?? null;
            return $cached;
        }

        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'authors' => [], 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $escapedId = escapeshellarg((string) $installId);
        $wptBin = $this->shellBinary($connection, $server);
        $php = <<<'PHP'
$users = get_users([
    'orderby' => 'display_name',
    'order' => 'ASC',
]);

$publishers = [];

foreach ($users as $user) {
    if (!user_can($user, 'edit_posts') && !user_can($user, 'publish_posts')) {
        continue;
    }

    $publishers[] = [
        'id' => (int) $user->ID,
        'user_login' => (string) $user->user_login,
        'display_name' => (string) ($user->display_name ?: $user->user_login),
        'roles' => array_values((array) $user->roles),
    ];
}

echo wp_json_encode($publishers);
PHP;

        $encoded = base64_encode($php);
        $output = trim($this->execWithConnection(
            $connection,
            "CODE=\$(echo " . escapeshellarg($encoded) . " | base64 -d) && {$wptBin} --wp-cli -instance-id {$escapedId} -- eval \"\$CODE\" 2>/dev/null"
        ));

        $authors = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '[') || str_starts_with($line, '{')) {
                $authors = json_decode($line, true) ?: [];
                break;
            }
        }

        if (!is_array($authors)) {
            return ['success' => false, 'authors' => [], 'message' => 'Failed to parse WP users.'];
        }

        $now = now();
        $expiresAt = $now->copy()->addDays(30);
        $result = [
            'success' => true,
            'authors' => $authors,
            'message' => count($authors) . ' publish-capable users loaded.',
            'cache_hit' => false,
            'cached_at' => $now->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
        Cache::put($cacheKey, $result, $expiresAt);

        return $result;
    }

    public function wpCliListCategories(WhmServer $server, int $installId): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'categories' => [], 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $escapedId = escapeshellarg((string) $installId);
        $wptBin = $this->shellBinary($connection, $server);
        $output = trim($this->execWithConnection($connection, "{$wptBin} --wp-cli -instance-id {$escapedId} -- term list category --fields=term_id,name,slug,count --format=json 2>/dev/null"));

        $categories = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '[') || str_starts_with($line, '{')) {
                $categories = json_decode($line, true) ?: [];
                break;
            }
        }

        if (!is_array($categories)) {
            return ['success' => false, 'categories' => [], 'message' => 'Failed to parse categories.'];
        }

        $result = array_map(static fn ($category) => [
            'id' => (int) ($category['term_id'] ?? 0),
            'name' => $category['name'] ?? '',
            'slug' => $category['slug'] ?? '',
            'count' => (int) ($category['count'] ?? 0),
        ], $categories);

        return ['success' => true, 'categories' => $result, 'message' => count($result) . ' categories loaded.'];
    }

    /**
     * Batch create/get WordPress categories via a single wp-cli call.
     * Much faster than individual calls — one SSH command for all categories.
     *
     * @param WhmServer $server
     * @param int       $installId
     * @param array     $names Category names
     * @return array{success: bool, term_ids: array, message: string}
     */

    public function wpCliResolvePreferredTaxonomy(WhmServer $server, int $installId, array $candidates = ['publication', 'category']): array
    {
        $candidates = array_values(array_unique(array_filter(array_map(static fn ($candidate) => trim((string) $candidate), $candidates))));
        if (empty($candidates)) {
            $candidates = ['publication', 'category'];
        }

        $php = '$candidates = ' . var_export($candidates, true) . ';'
            . '$payload = ["success" => false, "message" => "No matching taxonomy found.", "taxonomy" => "", "label" => "", "hierarchical" => true];'
            . 'foreach ($candidates as $tax) {'
            . '  if (!taxonomy_exists($tax)) { continue; }'
            . '  $obj = get_taxonomy($tax);'
            . '  $payload = ['
            . '      "success" => true,'
            . '      "message" => "Resolved taxonomy: " . $tax,'
            . '      "taxonomy" => $tax,'
            . '      "label" => (string) (($obj->labels->name ?? $obj->label ?? $tax)),'
            . '      "hierarchical" => (bool) ($obj->hierarchical ?? true),'
            . '  ];'
            . '  break;'
            . '}'
            . 'echo "HEXA_TAXONOMY:" . wp_json_encode($payload);';

        $result = $this->wpCliEval($server, $installId, $php);
        if (!($result['success'] ?? false)) {
            return [
                'success' => false,
                'message' => $result['message'] ?? 'Failed to evaluate taxonomy resolution.',
            ];
        }

        foreach (explode("
", (string) ($result['stdout'] ?? '')) as $line) {
            $line = trim($line);
            if (!str_contains($line, 'HEXA_TAXONOMY:')) {
                continue;
            }
            $json = substr($line, strpos($line, 'HEXA_TAXONOMY:') + 14);
            $payload = json_decode(trim($json), true);
            if (is_array($payload)) {
                return [
                    'success' => (bool) ($payload['success'] ?? false),
                    'message' => (string) ($payload['message'] ?? 'Resolved taxonomy.'),
                    'taxonomy' => (string) ($payload['taxonomy'] ?? ''),
                    'label' => (string) ($payload['label'] ?? ''),
                    'hierarchical' => (bool) ($payload['hierarchical'] ?? true),
                ];
            }
        }

        return [
            'success' => false,
            'message' => 'Failed to parse taxonomy resolution output.',
        ];
    }

    public function wpCliListTaxonomyTerms(WhmServer $server, int $installId, string $taxonomy): array
    {
        $taxonomy = trim($taxonomy);
        if ($taxonomy === '') {
            return ['success' => false, 'terms' => [], 'message' => 'Taxonomy is required.'];
        }

        $php = '$taxonomy = ' . var_export($taxonomy, true) . ';'
            . 'if (!taxonomy_exists($taxonomy)) {'
            . '  echo "HEXA_TERMS:" . wp_json_encode(["success" => false, "message" => "Taxonomy not found: " . $taxonomy, "terms" => []]);'
            . '  return;'
            . '}'
            . '$terms = get_terms(["taxonomy" => $taxonomy, "hide_empty" => false, "orderby" => "name", "order" => "ASC"]);'
            . 'if (is_wp_error($terms)) {'
            . '  echo "HEXA_TERMS:" . wp_json_encode(["success" => false, "message" => $terms->get_error_message(), "terms" => []]);'
            . '  return;'
            . '}'
            . '$rows = [];'
            . 'foreach ((array) $terms as $term) {'
            . '  $rows[] = ['
            . '      "id" => (int) ($term->term_id ?? 0),'
            . '      "term_id" => (int) ($term->term_id ?? 0),'
            . '      "parent" => (int) ($term->parent ?? 0),'
            . '      "name" => (string) ($term->name ?? ""),'
            . '      "slug" => (string) ($term->slug ?? ""),'
            . '      "count" => (int) ($term->count ?? 0),'
            . '  ];'
            . '}'
            . 'echo "HEXA_TERMS:" . wp_json_encode(["success" => true, "message" => count($rows) . " taxonomy terms loaded.", "terms" => $rows]);';

        $result = $this->wpCliEval($server, $installId, $php);
        if (!($result['success'] ?? false)) {
            return [
                'success' => false,
                'terms' => [],
                'message' => $result['message'] ?? 'Failed to evaluate taxonomy terms.',
            ];
        }

        foreach (explode("
", (string) ($result['stdout'] ?? '')) as $line) {
            $line = trim($line);
            if (!str_contains($line, 'HEXA_TERMS:')) {
                continue;
            }
            $json = substr($line, strpos($line, 'HEXA_TERMS:') + 11);
            $payload = json_decode(trim($json), true);
            if (is_array($payload)) {
                return [
                    'success' => (bool) ($payload['success'] ?? false),
                    'terms' => is_array($payload['terms'] ?? null) ? $payload['terms'] : [],
                    'message' => (string) ($payload['message'] ?? 'Taxonomy terms loaded.'),
                ];
            }
        }

        return [
            'success' => false,
            'terms' => [],
            'message' => 'Failed to parse taxonomy terms output.',
        ];
    }

    public function wpCliBatchCategories(WhmServer $server, int $installId, array $names): array
    {
        return $this->wpCliBatchTerms($server, $installId, $names, 'category');
    }

    /**
     * Batch create/get WordPress tags via single WP bootstrap.
     *
     * @param WhmServer $server
     * @param int       $installId
     * @param array     $names Tag names
     * @return array{success: bool, term_ids: array, message: string}
     */
    public function wpCliBatchTags(WhmServer $server, int $installId, array $names): array
    {
        return $this->wpCliBatchTerms($server, $installId, $names, 'post_tag');
    }

    /**
     * Batch create/get WordPress terms using a single `wp eval` call.
     * Bootstraps WordPress ONCE and processes all terms in one PHP execution.
     * 15x faster than individual wp-cli calls.
     *
     * @param WhmServer $server
     * @param int       $installId
     * @param array     $names Term names
     * @param string    $taxonomy 'category' or 'post_tag'
     * @return array{success: bool, term_ids: array, message: string}
     */
    private function wpCliBatchTerms(WhmServer $server, int $installId, array $names, string $taxonomy): array
    {
        if (empty($names)) return ['success' => true, 'term_ids' => [], 'message' => 'No terms'];

        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'term_ids' => [], 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $escapedId = escapeshellarg((string) $installId);
        $wptBin = $this->shellBinary($connection, $server);
        $escapedTax = escapeshellarg($taxonomy);

        // Build a shell script that passes PHP to wp-toolkit eval via a variable
        // This avoids all escaping issues by using base64 decode → shell variable → eval
        $namesJson = json_encode(array_values(array_map('trim', $names)));
        $phpCode = '$names = json_decode(\'' . str_replace("'", "\\'", $namesJson) . '\', true);'
            . '$tax = \'' . $taxonomy . '\';'
            . '$results = [];'
            . 'foreach ($names as $n) {'
            . '  $e = term_exists($n, $tax);'
            . '  if ($e) { $tid = is_array($e) ? (int)$e[\'term_id\'] : (int)$e; $results[] = ["id"=>$tid,"name"=>$n,"existed"=>true]; }'
            . '  else { $r = wp_insert_term($n, $tax); if (!is_wp_error($r)) { $results[] = ["id"=>(int)$r[\'term_id\'],"name"=>$n,"existed"=>false]; } else { $results[] = ["id"=>0,"name"=>$n,"error"=>$r->get_error_message()]; } }'
            . '}'
            . 'echo \'HEXA_RESULT:\' . json_encode($results);';

        $b64 = base64_encode($phpCode);
        $tmpScript = '/tmp/hexa_batch_' . uniqid() . '.sh';
        $scriptContent = "#!/bin/bash\nCODE=\$(echo '{$b64}' | base64 -d)\n{$wptBin} --wp-cli -instance-id {$installId} -- eval \"\$CODE\" 2>&1";
        $this->execWithConnection($connection, "echo " . escapeshellarg($scriptContent) . " > {$tmpScript} && chmod +x {$tmpScript}");

        $cmd = "bash {$tmpScript}";

        $this->generic->log('info', "[WpToolkit] Batch {$taxonomy}: " . count($names) . " terms");

        $rawOutput = trim($this->execWithConnection($connection, $cmd));
        $this->execWithConnection($connection, "rm -f {$tmpScript}");

        // Extract JSON result from output (may contain warnings)
        $results = [];
        foreach (explode("\n", $rawOutput) as $line) {
            if (str_contains($line, 'HEXA_RESULT:')) {
                $json = substr($line, strpos($line, 'HEXA_RESULT:') + 12);
                $results = json_decode(trim($json), true) ?: [];
                break;
            }
        }

        // Extract term IDs (backward compat) and detailed results
        $termIds = array_filter(array_column($results, 'id'));
        $label = $taxonomy === 'category' ? 'categories' : 'tags';

        return [
            'success' => true,
            'term_ids' => $termIds,
            'term_details' => $results,
            'message' => count($termIds) . '/' . count($names) . " {$label} ready",
        ];
    }

    /**
     * Delete a WordPress post via wp-cli SSH.
     *
     * @param WhmServer $server
     * @param int       $installId
     * @param int       $postId WP post ID
     * @param bool      $force  Skip trash, delete permanently
     * @return array{success: bool, message: string}
     */
    public function wpCliDeletePost(WhmServer $server, int $installId, int $postId, bool $force = true): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $escapedId = escapeshellarg((string) $installId);
        $wptBin = $this->shellBinary($connection, $server);
        $forceFlag = $force ? ' --force' : '';
        $cmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- post delete {$postId}{$forceFlag} 2>&1";
        $output = trim($this->execWithConnection($connection, $cmd));

        // Filter warnings
        $clean = '';
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'Deprecated:') || str_starts_with($line, 'Warning:') || str_starts_with($line, 'Notice:') || str_starts_with($line, 'PHP ')) continue;
            $clean .= $line . ' ';
        }
        $clean = trim($clean);

        $success = str_contains(strtolower($clean), 'success') || str_contains(strtolower($clean), 'deleted');
        $this->generic->log($success ? 'info' : 'error', '[WpToolkit] deletePost', ['post_id' => $postId, 'output' => $clean]);

        return ['success' => $success, 'message' => $success ? "Post {$postId} deleted." : "Delete failed: {$clean}"];
    }

    /**
     * Delete a WordPress media attachment via wp-cli SSH.
     *
     * @param WhmServer $server
     * @param int       $installId
     * @param int       $mediaId WP media attachment ID
     * @param bool      $force   Skip trash, delete permanently
     * @return array{success: bool, message: string}
     */
    public function wpCliDeleteMedia(WhmServer $server, int $installId, int $mediaId, bool $force = true): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $escapedId = escapeshellarg((string) $installId);
        $wptBin = $this->shellBinary($connection, $server);
        $forceFlag = $force ? ' --force' : '';
        $cmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- post delete {$mediaId}{$forceFlag} 2>&1";
        $output = trim($this->execWithConnection($connection, $cmd));

        $clean = '';
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'Deprecated:') || str_starts_with($line, 'Warning:') || str_starts_with($line, 'Notice:') || str_starts_with($line, 'PHP ')) continue;
            $clean .= $line . ' ';
        }
        $clean = trim($clean);

        $success = str_contains(strtolower($clean), 'success') || str_contains(strtolower($clean), 'deleted');
        $this->generic->log($success ? 'info' : 'error', '[WpToolkit] deleteMedia', ['media_id' => $mediaId, 'output' => $clean]);

        return ['success' => $success, 'message' => $success ? "Media {$mediaId} deleted." : "Delete failed: {$clean}"];
    }

    /**
     * Run arbitrary PHP through wp-cli eval inside a WP install context.
     * The PHP body is base64-encoded to avoid shell escaping issues.
     *
     * @return array{success: bool, stdout: string, message?: string}
     */
    public function wpCliEval(\hexa_package_whm\Models\WhmServer $server, int $installId, string $php): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'stdout' => '', 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }
        $connection = $ssh['connection'];
        $wptBin = $this->shellBinary($connection, $server);
        $escapedId = escapeshellarg((string) $installId);
        $b64 = base64_encode($php);
        $cmd = "CODE=$(echo '" . $b64 . "' | base64 -d) && " . $wptBin . " --wp-cli -instance-id " . $escapedId . " -- eval \"\$CODE\" 2>&1";
        $out = trim($this->execWithConnection($connection, $cmd));
        return ['success' => true, 'stdout' => $out];
    }

}
