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
    public function wpCliCreatePost(WhmServer $server, int $installId, string $title, string $content, string $status = 'draft', array $categoryIds = [], array $tagIds = [], ?string $date = null, ?string $author = null, ?int $featuredMediaId = null, string $postType = 'post'): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $wpCliBase = $this->wpCliBaseCommand($server, $connection, $installId);

        // Write content to temp file on server (avoids shell escaping issues with HTML)
        $tmpFile = '/tmp/hexa_wp_post_' . uniqid() . '.html';
        $this->execWithConnection($connection, 'cat > ' . escapeshellarg($tmpFile) . ' << \'HEXAEOF\'' . "\n" . $content . "\nHEXAEOF");

        // Build wp post create command
        $cmd = "{$wpCliBase} post create"
            . " --post_title=" . escapeshellarg($title)
            . " --post_status=" . escapeshellarg($status)
            . " --post_type=" . escapeshellarg($postType)
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
            $wpUserId = $this->resolveWpAuthorId($server, $connection, $installId, (string) $author);
            if ($wpUserId !== null) {
                $cmd .= " --post_author=" . escapeshellarg($wpUserId);
                if (!is_numeric((string) $author)) {
                    $this->generic->log('info', '[WpToolkit] Resolved author', ['username' => $author, 'wp_id' => $wpUserId]);
                }
            } elseif (!is_numeric((string) $author)) {
                $this->generic->log('warning', '[WpToolkit] Author not found on WP', ['username' => $author]);
            }
        }

        $this->generic->log('info', '[WpToolkit] wpCliCreatePost', ['install_id' => $installId, 'title' => $title, 'status' => $status, 'author' => $author]);

        $output = trim($this->execWithConnection($connection, $cmd . ' 2>&1'));

        // Cleanup temp file
        $this->execWithConnection($connection, 'rm -f ' . escapeshellarg($tmpFile));

        // --porcelain returns just the post ID
        if (is_numeric($output)) {
            $postId = (int) $output;

            // Set tags via wp_set_post_tags (IDs, not names)
            if (!empty($tagIds)) {
                $tagIdsStr = implode(',', array_map('intval', $tagIds));
                $tagPhp = base64_encode('wp_set_post_tags(' . $postId . ', [' . $tagIdsStr . ']); echo "TAGS_SET";');
                $tagCmd = "CODE=\$(echo '{$tagPhp}' | base64 -d) && {$wpCliBase} eval \"\$CODE\" 2>&1";
                $this->execWithConnection($connection, $tagCmd);
                $this->generic->log('info', '[WpToolkit] Tags set via wp_set_post_tags', ['post_id' => $postId, 'tag_ids' => $tagIds]);
            }

            // Set featured image if provided
            if ($featuredMediaId) {
                $metaCmd = "{$wpCliBase} post meta update {$postId} _thumbnail_id {$featuredMediaId} 2>&1";
                $this->execWithConnection($connection, $metaCmd);
                $this->generic->log('info', '[WpToolkit] Featured image set', ['post_id' => $postId, 'media_id' => $featuredMediaId]);
            }

            // Get permalink
            $urlCmd = "{$wpCliBase} post get {$postId} --field=url 2>&1";
            $postUrl = trim($this->execWithConnection($connection, $urlCmd));
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
        $wpCliBase = $this->wpCliBaseCommand($server, $connection, $installId);

        $tmpFiles = [];
        $tmpFile = null;
        if (array_key_exists('content', $postData)) {
            $tmpFile = '/tmp/hexa_wp_post_' . uniqid('', true) . '.html';
            $tmpFiles[] = $tmpFile;
            $contentBase64 = base64_encode((string) ($postData['content'] ?? ''));
            $writeCmd = 'printf %s ' . escapeshellarg($contentBase64) . ' | base64 -d > ' . escapeshellarg($tmpFile);
            $this->execWithConnection($connection, $writeCmd);
        }

        $cmd = "{$wpCliBase} post update " . escapeshellarg((string) $postId);
        if (array_key_exists('title', $postData)) {
            $cmd .= ' --post_title=' . escapeshellarg((string) $postData['title']);
        }
        if (array_key_exists('slug', $postData) || array_key_exists('post_name', $postData)) {
            $slug = trim((string) ($postData['slug'] ?? $postData['post_name'] ?? ''));
            if ($slug !== '') {
                $cmd .= ' --post_name=' . escapeshellarg($slug);
            }
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
            $wpUserId = $this->resolveWpAuthorId($server, $connection, $installId, $author);
            if ($wpUserId !== null) {
                $cmd .= ' --post_author=' . escapeshellarg($wpUserId);
            }
        }

        $hasFeaturedOnlyUpdate = array_key_exists('featured_media', $postData)
            && !array_key_exists('title', $postData)
            && !array_key_exists('slug', $postData)
            && !array_key_exists('post_name', $postData)
            && !array_key_exists('status', $postData)
            && !array_key_exists('excerpt', $postData)
            && !$tmpFile
            && empty($postData['categories'])
            && empty($postData['date'])
            && empty($postData['author']);

        if ($hasFeaturedOnlyUpdate) {
            try {
                $featuredMediaId = (int) $postData['featured_media'];
                if ($featuredMediaId > 0) {
                    $metaCmd = "{$wpCliBase} post meta update {$postId} _thumbnail_id " . escapeshellarg((string) $featuredMediaId) . ' 2>&1';
                } else {
                    $metaCmd = "{$wpCliBase} post meta delete {$postId} _thumbnail_id 2>&1";
                }
                $metaOutput = trim($this->execWithConnection($connection, $metaCmd));
                if (!str_contains($metaOutput, 'Success:') && !($featuredMediaId === 0 && str_contains($metaOutput, 'Could not delete meta value'))) {
                    $this->generic->log('error', '[WpToolkit] wpCliUpdatePost featured media update failed', ['output' => $metaOutput, 'post_id' => $postId]);
                    return ['success' => false, 'message' => 'wp-cli featured media update failed: ' . \Illuminate\Support\Str::limit($metaOutput, 300)];
                }
                $details = $this->wpCliGetPost($server, $installId, $postId);
                return [
                    'success' => true,
                    'message' => $featuredMediaId > 0 ? "Featured image updated (ID: {$postId})" : "Featured image cleared (ID: {$postId})",
                    'data' => $details['data'] ?? ['post_id' => $postId],
                ];
            } finally {
                foreach ($tmpFiles as $file) {
                    $this->execWithConnection($connection, 'rm -f ' . escapeshellarg($file));
                }
            }
        }

        $output = trim($this->execWithConnection($connection, $cmd . ' 2>&1'));

        try {
            if (str_contains($output, 'Success:')) {
                if (array_key_exists('tags', $postData) && is_array($postData['tags'])) {
                    $tagIds = array_values(array_filter(array_map('intval', $postData['tags'])));
                    $tagPhp = '<?php wp_set_post_tags(' . $postId . ', [' . implode(',', $tagIds) . ']);';
                    $tagTmpFile = '/tmp/hexa_wp_tags_' . uniqid('', true) . '.php';
                    $tmpFiles[] = $tagTmpFile;
                    $tagWriteCmd = 'printf %s ' . escapeshellarg(base64_encode($tagPhp)) . ' | base64 -d > ' . escapeshellarg($tagTmpFile);
                    $this->execWithConnection($connection, $tagWriteCmd);
                    $tagCmd = "{$wpCliBase} eval-file " . escapeshellarg($tagTmpFile) . ' 2>&1';
                    $this->execWithConnection($connection, $tagCmd);
                }

                if (array_key_exists('featured_media', $postData)) {
                    $featuredMediaId = (int) $postData['featured_media'];
                    if ($featuredMediaId > 0) {
                        $metaCmd = "{$wpCliBase} post meta update {$postId} _thumbnail_id " . escapeshellarg((string) $featuredMediaId) . ' 2>&1';
                    } else {
                        $metaCmd = "{$wpCliBase} post meta delete {$postId} _thumbnail_id 2>&1";
                    }
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

        if (stripos($output, 'Invalid page template') !== false) {
            $fallback = $this->wpCliUpdatePostViaEval($connection, $wpCliBase, $postId, $postData);
            if (($fallback['success'] ?? false)) {
                return $fallback;
            }
            $output .= "\nFallback eval update failed: " . (string) ($fallback['message'] ?? 'unknown error');
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
        $wpCliBase = $this->wpCliBaseCommand($server, $connection, $installId);
        $cmd = "{$wpCliBase} post get " . escapeshellarg((string) $postId) . ' --format=json 2>&1';
        $output = trim($this->execWithConnection($connection, $cmd));
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
        if (!filter_var($imageUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $imageUrl)) {
            return ['success' => false, 'message' => 'Image URL must be a direct http(s) URL for WordPress media import.'];
        }

        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $wpCliBase = $this->wpCliBaseCommand($server, $connection, $installId);
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
        $gdRequireFile = $this->stageGdImageEditorRequire($connection, $tmpDir);
        $gdRequireArg = $gdRequireFile !== "" ? " --require=" . escapeshellarg($gdRequireFile) : "";
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

        $mediaProbe = $this->normalizeStagedImageForWpImport($connection, $tmpDir, $tmpFile, $targetFilename);
        if (!($mediaProbe['success'] ?? false)) {
            $this->execWithConnection($connection, "rm -rf " . escapeshellarg($tmpDir));
            return ['success' => false, 'message' => (string) ($mediaProbe['message'] ?? 'Downloaded file is not an allowed WordPress image.')];
        }
        $tmpFile = (string) $mediaProbe['path'];
        $targetFilename = (string) $mediaProbe['filename'];

        $titleArg = $filename ? " --title=" . escapeshellarg(pathinfo($filename, PATHINFO_FILENAME)) : '';
        $fileNameArg = $filename ? " --file_name=" . escapeshellarg(pathinfo($filename, PATHINFO_FILENAME)) : '';
        $altArg = $altText ? " --alt=" . escapeshellarg($altText) : '';
        $captionArg = $caption ? " --caption=" . escapeshellarg($caption) : '';
        $descriptionArg = $description ? " --desc=" . escapeshellarg($description) : '';
        $cmd = "{$wpCliBase}{$gdRequireArg} media import "
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
            $metaCmd = "CODE=\$(echo '{$phpCode}' | base64 -d) && {$wpCliBase} eval \"\$CODE\" 2>&1";
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

    protected function wpCliBaseCommand(WhmServer $server, SSH2|LocalShellConnection $connection, int $installId): string
    {
        if ($connection instanceof LocalShellConnection) {
            $localProbe = $this->probeLocalRuntime();
            $runtimeUser = strtolower(trim((string) ($localProbe['runtime_user'] ?? '')));
            $localWpBinary = $this->localWpCliBinary($connection);
            $installPath = $this->resolveInstallPath($server, $connection, $installId);

            if ($localWpBinary && $installPath) {
                $command = escapeshellarg($localWpBinary) . " --path=" . escapeshellarg($installPath);
                if ($runtimeUser === "root") {
                    $command .= " --allow-root";
                }
                return $command;
            }
        }

        return $this->shellBinary($connection, $server)
            . ' --wp-cli -instance-id '
            . escapeshellarg((string) $installId)
            . ' --';
    }

    protected function resolveWpAuthorId(WhmServer $server, SSH2|LocalShellConnection $connection, int $installId, string $author): ?string
    {
        $author = trim($author);
        if ($author === '') {
            return null;
        }

        if (is_numeric($author)) {
            return $author;
        }

        $cacheKey = $server->id . ':' . $installId . ':' . strtolower($author);
        if (array_key_exists($cacheKey, $this->wpAuthorIdCache)) {
            return $this->wpAuthorIdCache[$cacheKey];
        }

        $userCmd = $this->wpCliBaseCommand($server, $connection, $installId)
            . ' user get '
            . escapeshellarg($author)
            . ' --field=ID 2>/dev/null';
        $rawId = trim($this->execWithConnection($connection, $userCmd));

        foreach (explode("\n", $rawId) as $line) {
            $line = trim($line);
            if (!is_numeric($line)) {
                continue;
            }

            return $this->wpAuthorIdCache[$cacheKey] = $line;
        }

        $this->wpAuthorIdCache[$cacheKey] = null;

        return null;
    }

    protected function resolveInstallPath(WhmServer $server, SSH2|LocalShellConnection $connection, int $installId): ?string
    {
        $cacheKey = $server->id . ':' . $installId;
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
            if (str_starts_with($line, 'Deprecated:') && str_contains($line, 'Colors.php on line 95')) {
                continue;
            }
            if (str_starts_with($line, 'PHP Deprecated:') && str_contains($line, 'Colors.php on line 95')) {
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
        $wpCliBase = $this->wpCliBaseCommand($server, $connection, $installId);

        // Check if category exists first
        $checkCmd = "{$wpCliBase} term list category --field=term_id --name=" . escapeshellarg($name) . " --format=csv 2>&1";
        $existing = trim($this->execWithConnection($connection, $checkCmd));
        $lines = array_filter(explode("\n", $existing), fn($l) => is_numeric(trim($l)));
        if (!empty($lines)) {
            $termId = (int) trim($lines[array_key_first($lines)]);
            return ['success' => true, 'term_id' => $termId, 'message' => "Category exists (ID: {$termId})"];
        }

        // Create it
        $cmd = "{$wpCliBase} term create category " . escapeshellarg($name) . " --porcelain 2>&1";
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
        $wpCliBase = $this->wpCliBaseCommand($server, $connection, $installId);

        // Check if tag exists
        $checkCmd = "{$wpCliBase} term list post_tag --field=term_id --name=" . escapeshellarg($name) . " --format=csv 2>&1";
        $existing = trim($this->execWithConnection($connection, $checkCmd));
        $lines = array_filter(explode("\n", $existing), fn($l) => is_numeric(trim($l)));
        if (!empty($lines)) {
            $termId = (int) trim($lines[array_key_first($lines)]);
            return ['success' => true, 'term_id' => $termId, 'message' => "Tag exists (ID: {$termId})"];
        }

        // Create it
        $cmd = "{$wpCliBase} term create post_tag " . escapeshellarg($name) . " --porcelain 2>&1";
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
        $wpCliBase = $this->wpCliBaseCommand($server, $connection, $installId);

        // Get admin user info first
        $userCmd = "{$wpCliBase} user list --role=administrator --fields=user_login,display_name --format=csv 2>&1";
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
        $cmd = "{$wpCliBase} post create --post_title='Hexa Write Test' --post_status=draft --porcelain 2>&1";
        $output = trim($this->execWithConnection($connection, $cmd));

        // Filter warnings
        foreach (explode("\n", $output) as $line) {
            if (is_numeric(trim($line))) { $output = trim($line); break; }
        }

        if (is_numeric($output)) {
            $postId = (int) $output;
            $this->execWithConnection($connection, "{$wpCliBase} post delete {$postId} --force 2>&1");
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
        $wpCliBase = $this->wpCliBaseCommand($server, $connection, $installId);
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
            "CODE=\$(echo " . escapeshellarg($encoded) . " | base64 -d) && {$wpCliBase} eval \"\$CODE\" 2>/dev/null"
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

        $result = ['success' => true, 'authors' => $authors, 'message' => count($authors) . ' publish-capable users loaded.'];
        Cache::put($cacheKey, $result, now()->addMinutes(10));

        return $result;
    }

    public function wpCliListCategories(WhmServer $server, int $installId): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'categories' => [], 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $wpCliBase = $this->wpCliBaseCommand($server, $connection, $installId);
        $output = trim($this->execWithConnection($connection, "{$wpCliBase} term list category --fields=term_id,name,slug,count --format=json 2>/dev/null"));

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

    public function wpCliSetPostTerms(WhmServer $server, int $installId, int $postId, string $taxonomy, array $termIds): array
    {
        $taxonomy = trim($taxonomy);
        $termIds = array_values(array_unique(array_filter(array_map('intval', $termIds))));

        if ($taxonomy === '') {
            return ['success' => false, 'message' => 'Taxonomy is required.', 'term_ids' => [], 'term_taxonomy_ids' => []];
        }

        if ($postId <= 0) {
            return ['success' => false, 'message' => 'Post ID is required.', 'term_ids' => [], 'term_taxonomy_ids' => []];
        }

        if ($termIds === []) {
            return ['success' => true, 'message' => 'No terms to assign.', 'term_ids' => [], 'term_taxonomy_ids' => []];
        }

        $php = '$postId = ' . (int) $postId . ';'
            . '$taxonomy = ' . var_export($taxonomy, true) . ';'
            . '$termIds = ' . var_export($termIds, true) . ';'
            . 'if (!taxonomy_exists($taxonomy)) {'
            . '  echo "HEXA_ASSIGN_TERMS:" . wp_json_encode(["success" => false, "message" => "Taxonomy not found: " . $taxonomy, "term_ids" => [], "term_taxonomy_ids" => []]);'
            . '  return;'
            . '}'
            . '$assigned = wp_set_object_terms($postId, $termIds, $taxonomy, false);'
            . 'if (is_wp_error($assigned)) {'
            . '  echo "HEXA_ASSIGN_TERMS:" . wp_json_encode(["success" => false, "message" => $assigned->get_error_message(), "term_ids" => [], "term_taxonomy_ids" => []]);'
            . '  return;'
            . '}'
            . '$confirmed = wp_get_object_terms($postId, $taxonomy, ["fields" => "ids"]);'
            . 'if (is_wp_error($confirmed)) { $confirmed = []; }'
            . '$termTaxonomyIds = array_values(array_map("intval", is_array($assigned) ? $assigned : []));'
            . '$confirmedIds = array_values(array_map("intval", is_array($confirmed) ? $confirmed : []));'
            . 'echo "HEXA_ASSIGN_TERMS:" . wp_json_encode(["success" => true, "message" => "Assigned terms to " . $taxonomy . ".", "term_ids" => $confirmedIds, "term_taxonomy_ids" => $termTaxonomyIds]);';

        $result = $this->wpCliEval($server, $installId, $php);
        if (!($result['success'] ?? false)) {
            return [
                'success' => false,
                'message' => $result['message'] ?? 'Failed to assign taxonomy terms.',
                'term_ids' => [],
                'term_taxonomy_ids' => [],
            ];
        }

        foreach (explode("\n", (string) ($result['stdout'] ?? '')) as $line) {
            $line = trim($line);
            if (!str_contains($line, 'HEXA_ASSIGN_TERMS:')) {
                continue;
            }
            $json = substr($line, strpos($line, "HEXA_ASSIGN_TERMS:") + 18);
            $payload = $this->extractJsonObjectFromOutput(trim($json));
            if (is_array($payload)) {
                return [
                    'success' => (bool) ($payload['success'] ?? false),
                    'message' => (string) ($payload['message'] ?? 'Assigned taxonomy terms.'),
                    'term_ids' => array_values(array_map('intval', (array) ($payload['term_ids'] ?? []))),
                    'term_taxonomy_ids' => array_values(array_map('intval', (array) ($payload['term_taxonomy_ids'] ?? []))),
                ];
            }
        }

        return [
            'success' => false,
            'message' => 'Failed to parse term assignment output.',
            'term_ids' => [],
            'term_taxonomy_ids' => [],
        ];
    }

    public function wpCliEval(\hexa_package_whm\Models\WhmServer $server, int $installId, string $php): array
    {
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'stdout' => '', 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $wpCliBase = $this->wpCliBaseCommand($server, $connection, $installId);
        $b64 = base64_encode($php);
        $cmd = "CODE=$(echo '" . $b64 . "' | base64 -d) && {$wpCliBase} eval \"\$CODE\" 2>&1";
        $out = trim($this->execWithConnection($connection, $cmd));
        return ['success' => true, 'stdout' => $out];
    }

    /**
     * Return normalized WordPress media-library rows for picker UIs.
     *
     * This is the shared abstraction for app/package media selectors. It runs
     * one WP bootstrap, returns thumbnails first, and includes pagination.
     */
    public function wpCliMediaSelector(WhmServer $server, int $installId, array $query = []): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 60)));
        $search = trim((string) ($query['search'] ?? ''));
        $mimeType = trim((string) ($query['mime_type'] ?? 'image'));
        $includeIds = array_values(array_unique(array_filter(array_map('intval', (array) ($query['include_ids'] ?? [])))));

        $parts = [
            '$page=' . $page . ';',
            '$perPage=' . $perPage . ';',
            '$search=' . var_export($search, true) . ';',
            '$mimeType=' . var_export($mimeType, true) . ';',
            '$includeIds=' . var_export($includeIds, true) . ';',
            '$args=["post_type"=>"attachment","post_status"=>"inherit","posts_per_page"=>$perPage,"paged"=>$page,"orderby"=>"date","order"=>"DESC"];',
            'if ($mimeType !== "") { $args["post_mime_type"]=$mimeType; }',
            'if ($search !== "") { $args["s"]=$search; }',
            'if (!empty($includeIds)) { $args["post__in"]=array_values(array_map("intval",$includeIds)); $args["orderby"]="post__in"; }',
            '$q=new WP_Query($args);',
            '$items=[];',
            'foreach ((array) $q->posts as $post) {',
            '  $id=(int) $post->ID;',
            '  $full=(string) wp_get_attachment_url($id);',
            '  $file=(string) get_attached_file($id);',
            '  $meta=(array) wp_get_attachment_metadata($id);',
            '  $sizes=[];',
            '  foreach (["thumbnail","medium","medium_large","large","full"] as $size) {',
            '    $img=wp_get_attachment_image_src($id,$size);',
            '    if ($img) { $sizes[$size]=["url"=>(string)$img[0],"source_url"=>(string)$img[0],"width"=>(int)$img[1],"height"=>(int)$img[2]]; }',
            '  }',
            '  $fileName=basename($file ?: parse_url($full, PHP_URL_PATH));',
            '  $items[]=[',
            '    "ID"=>$id, "id"=>$id, "media_id"=>$id, "attachment_id"=>$id,',
            '    "post_title"=>(string)$post->post_title, "title"=>(string)get_the_title($id),',
            '    "filename"=>$fileName, "file_name"=>$fileName,',
            '    "guid"=>$full, "url"=>$full, "media_url"=>$full, "source_url"=>$full, "full_url"=>$full,',
            '    "thumbnail_url"=>(string)($sizes["thumbnail"]["url"] ?? $full),',
            '    "medium_url"=>(string)($sizes["medium"]["url"] ?? ($sizes["thumbnail"]["url"] ?? $full)),',
            '    "large_url"=>(string)($sizes["large"]["url"] ?? ($sizes["medium"]["url"] ?? $full)),',
            '    "post_mime_type"=>(string)$post->post_mime_type, "mime_type"=>(string)$post->post_mime_type,',
            '    "date"=>(string)$post->post_date, "modified"=>(string)$post->post_modified,',
            '    "alt_text"=>(string)get_post_meta($id,"_wp_attachment_image_alt",true),',
            '    "caption"=>(string)$post->post_excerpt, "description"=>(string)$post->post_content,',
            '    "width"=>(int)($meta["width"] ?? 0), "height"=>(int)($meta["height"] ?? 0),',
            '    "sizes"=>$sizes,',
            '  ];',
            '}',
            '$payload=["success"=>true,"message"=>count($items)." media item(s) loaded via WP Toolkit selector.","items"=>$items,"total"=>(int)$q->found_posts,"page"=>$page,"per_page"=>$perPage,"total_pages"=>(int)max(1,ceil(((int)$q->found_posts)/max(1,$perPage))),"has_more"=>(bool)($page < max(1,ceil(((int)$q->found_posts)/max(1,$perPage)))),"source"=>"wptoolkit.media_selector"];',
            'echo "HEXA_WPTK_MEDIA_SELECTOR:" . wp_json_encode($payload);',
        ];

        $result = $this->wpCliEval($server, $installId, implode('', $parts));
        if (!($result['success'] ?? false)) {
            return ['success' => false, 'message' => (string) ($result['message'] ?? 'WP Toolkit media selector failed.'), 'items' => []];
        }

        $stdout = (string) ($result['stdout'] ?? '');
        $marker = 'HEXA_WPTK_MEDIA_SELECTOR:';
        $pos = strpos($stdout, $marker);
        if ($pos === false) {
            return ['success' => false, 'message' => 'Failed to parse WP Toolkit media selector output.', 'items' => [], 'raw_output' => $stdout];
        }
        $json = trim(substr($stdout, $pos + strlen($marker)));
        $start = strpos($json, '{');
        $end = strrpos($json, '}');
        if ($start === false || $end === false || $end < $start) {
            return ['success' => false, 'message' => 'WP Toolkit media selector returned malformed JSON.', 'items' => [], 'raw_output' => $stdout];
        }
        $payload = json_decode(substr($json, $start, $end - $start + 1), true);
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'WP Toolkit media selector JSON decode failed.', 'items' => [], 'raw_output' => $stdout];
        }

        $payload['items'] = array_values(array_filter((array) ($payload['items'] ?? []), 'is_array'));
        $payload['success'] = (bool) ($payload['success'] ?? true);
        $payload['message'] = (string) ($payload['message'] ?? (count($payload['items']) . ' media item(s) loaded via WP Toolkit selector.'));
        $payload['source'] = 'wptoolkit.media_selector';

        return $payload;
    }

    // ===== code-side unique methods (preserved during 3-way merge) =====

    // === wpCliBatchTerms ===
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


    // === wpCliBatchCategories ===
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


    // === wpCliBatchTags ===
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


    // === wpCliDeleteMedia ===
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


    // === wpCliDeletePost ===
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


    // === wpCliImportLocalMediaFile ===
public function wpCliImportLocalMediaFile(WhmServer $server, int $installId, string $sourcePath, string $filename = "", string $altText = "", string $caption = "", string $description = ""): array
    {
        $sourcePath = trim($sourcePath);
        if ($sourcePath === "") {
            return ["success" => false, "message" => "Local media source path is missing."];
        }

        $ssh = $this->getConnection($server);
        if (!$ssh["success"]) {
            return ["success" => false, "message" => $ssh["error"] ?? "SSH connection failed"];
        }

        $connection = $ssh["connection"];
        $escapedId = escapeshellarg((string) $installId);
        $wptBin = $this->shellBinary($connection, $server);
        $installPath = $this->resolveInstallPath($server, $connection, $installId);
        if (!$installPath) {
            return ["success" => false, "message" => "Unable to resolve WordPress install path for local media import"];
        }

        $ext = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: "jpg";
        $ext = preg_replace("/[^a-zA-Z0-9].*/", "", $ext);
        if (!in_array(strtolower($ext), ["jpg", "jpeg", "png", "gif", "webp", "svg", "avif"])) $ext = "jpg";
        $targetFilename = $filename ? basename($filename) : basename($sourcePath);
        if ($targetFilename === "") $targetFilename = "hexa-upload-" . uniqid() . "." . $ext;
        if (!preg_match("/\\.\\w{2,5}$/", $targetFilename)) $targetFilename .= "." . $ext;
        $tmpDir = rtrim($installPath, "/") . "/.hexa-import-" . uniqid();
        $tmpFile = $tmpDir . "/" . $targetFilename;

        if ($connection instanceof LocalShellConnection) {
            $stageCommand = "mkdir -p " . escapeshellarg($tmpDir)
                . " && test -r " . escapeshellarg($sourcePath)
                . " && cp -f " . escapeshellarg($sourcePath) . " " . escapeshellarg($tmpFile)
                . " && chmod 644 " . escapeshellarg($tmpFile)
                . " 2>&1";
            $copy = $this->runCommandWithExitCode(
                $connection,
                $this->localFilesystemCommand($connection, $stageCommand)
            );
        } else {
            $mkdir = $this->runCommandWithExitCode($connection, "mkdir -p " . escapeshellarg($tmpDir) . " 2>&1");
            $put = false;
            $putMessage = "";
            if (($mkdir["exit_code"] ?? 1) === 0 && is_file($sourcePath) && is_readable($sourcePath)) {
                $contents = file_get_contents($sourcePath);
                if ($contents !== false) {
                    $encoded = base64_encode($contents);
                    $base64Path = $tmpFile . ".b64";
                    $reset = $this->runCommandWithExitCode($connection, ": > " . escapeshellarg($base64Path) . " 2>&1");
                    if (($reset["exit_code"] ?? 1) === 0) {
                        $put = true;
                        foreach (str_split($encoded, 60000) as $chunk) {
                            $chunkWrite = $this->runCommandWithExitCode($connection, "printf %s " . escapeshellarg($chunk) . " >> " . escapeshellarg($base64Path) . " 2>&1");
                            if (($chunkWrite["exit_code"] ?? 1) !== 0) {
                                $put = false;
                                $putMessage = $chunkWrite["clean_output"] ?: ($chunkWrite["raw_output"] ?: "remote base64 chunk write failed");
                                break;
                            }
                        }
                        if ($put) {
                            $decode = $this->runCommandWithExitCode(
                                $connection,
                                "base64 -d " . escapeshellarg($base64Path) . " > " . escapeshellarg($tmpFile)
                                    . " && rm -f " . escapeshellarg($base64Path)
                                    . " && chmod 644 " . escapeshellarg($tmpFile)
                                    . " 2>&1"
                            );
                            $put = (($decode["exit_code"] ?? 1) === 0);
                            if (!$put) {
                                $putMessage = $decode["clean_output"] ?: ($decode["raw_output"] ?: "remote base64 decode failed");
                            }
                        }
                    } else {
                        $putMessage = $reset["clean_output"] ?: ($reset["raw_output"] ?: "remote base64 stage file could not be created");
                    }
                } else {
                    $putMessage = "source file could not be read";
                }
            } else {
                $putMessage = $mkdir["clean_output"] ?: ($mkdir["raw_output"] ?: "remote staging directory could not be created");
            }
            $copy = [
                "exit_code" => $put ? 0 : 1,
                "clean_output" => $put ? "" : ($putMessage ?: "remote file staging failed"),
                "raw_output" => $put ? "" : ($putMessage ?: "remote file staging failed"),
            ];
        }
        $fileSize = trim($this->execWithConnection(
            $connection,
            $this->localFilesystemCommand($connection, "stat -c%s " . escapeshellarg($tmpFile) . " 2>/dev/null || echo 0")
        ));

        if (($copy["exit_code"] ?? 1) !== 0 || !$fileSize || $fileSize === "0") {
            $this->execWithConnection($connection, $this->localFilesystemCommand($connection, "rm -rf " . escapeshellarg($tmpDir)));
            $cleanOutput = $copy["clean_output"] ?: $copy["raw_output"];
            $this->generic->log("error", "[WpToolkit] Local media staging failed", [
                "source_path" => $sourcePath,
                "install_path" => $installPath,
                "tmp_file" => $tmpFile,
                "exit_code" => $copy["exit_code"] ?? null,
                "output" => $cleanOutput,
            ]);
            return ["success" => false, "message" => "Could not stage the media file for the local WordPress import: " . \Illuminate\Support\Str::limit($cleanOutput ?: "copy failed", 300)];
        }

        $mediaProbe = $this->normalizeStagedImageForWpImport($connection, $tmpDir, $tmpFile, $targetFilename, true);
        if (!($mediaProbe['success'] ?? false)) {
            $this->execWithConnection($connection, $this->localFilesystemCommand($connection, "rm -rf " . escapeshellarg($tmpDir)));
            return ['success' => false, 'message' => (string) ($mediaProbe['message'] ?? 'Staged file is not an allowed WordPress image.')];
        }
        $tmpFile = (string) $mediaProbe['path'];
        $targetFilename = (string) $mediaProbe['filename'];

        $gdRequireFile = $this->stageGdImageEditorRequire($connection, $tmpDir);
        $gdRequireArg = $gdRequireFile !== "" ? " --require=" . escapeshellarg($gdRequireFile) : "";

        $titleArg = $filename ? " --title=" . escapeshellarg(pathinfo($filename, PATHINFO_FILENAME)) : "";
        $fileNameArg = $filename ? " --file_name=" . escapeshellarg(pathinfo($filename, PATHINFO_FILENAME)) : "";
        $altArg = $altText ? " --alt=" . escapeshellarg($altText) : "";
        $captionArg = $caption ? " --caption=" . escapeshellarg($caption) : "";
        $descriptionArg = $description ? " --desc=" . escapeshellarg($description) : "";
        $cmd = "{$wptBin} --wp-cli -instance-id {$escapedId} --{$gdRequireArg} media import "
            . escapeshellarg($tmpFile)
            . $fileNameArg
            . $titleArg
            . $captionArg
            . $altArg
            . $descriptionArg
            . " --porcelain 2>&1";
        $import = $this->runCommandWithExitCode($connection, $cmd);
        $this->execWithConnection($connection, $this->localFilesystemCommand($connection, "rm -rf " . escapeshellarg($tmpDir)));

        $output = "";
        foreach ($import["lines"] as $line) {
            if (is_numeric($line)) {
                $output = $line;
                break;
            }
        }

        if (is_numeric($output)) {
            $mediaId = (int) $output;
            $metaPhp = '$id=' . $mediaId . ';'
                . ($altText ? 'update_post_meta($id,"_wp_attachment_image_alt",' . json_encode($altText) . ');' : '')
                . 'update_post_meta($id,"_hexa_generated","true");'
                . 'update_post_meta($id,"_hexa_upload_time","' . date('Y-m-d H:i:s') . '");'
                . 'wp_update_post(["ID"=>$id'
                . ($caption ? ',"post_excerpt"=>' . json_encode($caption) : '')
                . ($description ? ',"post_content"=>' . json_encode($description) : '')
                . ']);'
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
            $mediaUrl = "";
            $filePath = "";
            $uploadedFileSize = 0;
            foreach (explode("\n", $metaOutput) as $sLine) {
                if (str_contains($sLine, "HEXA_MEDIA:")) {
                    $json = substr($sLine, strpos($sLine, "HEXA_MEDIA:") + 11);
                    $parsed = json_decode(trim($json), true) ?: [];
                    $sizes = $parsed["sizes"] ?? [];
                    $mediaUrl = $sizes["full"] ?? $sizes["large"] ?? "";
                    $filePath = $parsed["file_path"] ?? "";
                    $uploadedFileSize = $parsed["file_size"] ?? 0;
                    break;
                }
            }

            $this->generic->log("info", "[WpToolkit] Local media imported", ["media_id" => $mediaId, "url" => $mediaUrl, "sizes" => count($sizes)]);
            return [
                "success" => true,
                "message" => "Media uploaded (ID: {$mediaId})",
                "data"    => [
                    "media_id" => $mediaId,
                    "media_url" => $mediaUrl,
                    "sizes" => $sizes,
                    "source_url" => $sourcePath,
                    "filename" => $filename,
                    "file_path" => $filePath,
                    "file_size" => $uploadedFileSize,
                    "alt_text" => $altText,
                    "caption" => $caption,
                    "description" => $description,
                ],
            ];
        }

        $cleanOutput = $import["clean_output"] ?: $import["raw_output"];
        $this->generic->log("error", "[WpToolkit] wpCliImportLocalMediaFile failed", [
            "exit_code" => $import["exit_code"],
            "output" => $cleanOutput,
            "raw_output" => $import["raw_output"],
            "install_path" => $installPath,
            "tmp_file" => $tmpFile,
            "source_path" => $sourcePath,
        ]);
        return ["success" => false, "message" => "wp-cli media import failed: " . \Illuminate\Support\Str::limit($cleanOutput ?: "unknown error", 300)];
    }



    protected function wpCliUpdatePostViaEval(SSH2|LocalShellConnection $connection, string $wpCliBase, int $postId, array $postData): array
    {
        $payload = [];
        foreach (['title', 'content', 'status', 'excerpt', 'date', 'author', 'slug', 'post_name', 'featured_media', 'categories', 'tags'] as $field) {
            if (array_key_exists($field, $postData)) {
                $payload[$field] = $postData[$field];
            }
        }

        $php = '$postId=' . $postId . ';'
            . '$payload=' . var_export($payload, true) . ';'
            . '$post=["ID"=>$postId];'
            . 'foreach (["title"=>"post_title","content"=>"post_content","status"=>"post_status","excerpt"=>"post_excerpt","date"=>"post_date","author"=>"post_author"] as $src=>$dest) { if (array_key_exists($src,$payload) && $payload[$src] !== null && $payload[$src] !== "") { $post[$dest]=(string) $payload[$src]; } }'
            . 'if (array_key_exists("slug",$payload) && trim((string)$payload["slug"]) !== "") { $post["post_name"]=(string) $payload["slug"]; } elseif (array_key_exists("post_name",$payload) && trim((string)$payload["post_name"]) !== "") { $post["post_name"]=(string) $payload["post_name"]; }'
            . '$result=wp_update_post($post,true);'
            . 'if (is_wp_error($result)) { echo "HEXA_POST_UPDATE:" . wp_json_encode(["success"=>false,"message"=>$result->get_error_message()]); return; }'
            . 'if (!empty($payload["categories"]) && is_array($payload["categories"])) { wp_set_post_terms($postId, array_values(array_filter(array_map("intval", $payload["categories"]))), "category", false); }'
            . 'if (!empty($payload["tags"]) && is_array($payload["tags"])) { wp_set_post_terms($postId, array_values(array_filter(array_map("intval", $payload["tags"]))), "post_tag", false); }'
            . 'if (array_key_exists("featured_media",$payload)) { $featured=(int)$payload["featured_media"]; if ($featured > 0) { update_post_meta($postId,"_thumbnail_id",$featured); } else { delete_post_meta($postId,"_thumbnail_id"); } }'
            . 'clean_post_cache($postId);'
            . '$postObj=get_post($postId);'
            . 'echo "HEXA_POST_UPDATE:" . wp_json_encode(["success"=>true,"message"=>"Post updated (ID: " . $postId . ")","data"=>["post_id"=>$postId,"post_url"=>get_permalink($postId),"post_status"=>get_post_status($postId),"post_title"=>$postObj ? (string) $postObj->post_title : "","post_date"=>$postObj ? (string) $postObj->post_date : null]]);';
        $encoded = base64_encode($php);
        $cmd = "CODE=$(printf %s " . escapeshellarg($encoded) . " | base64 -d) && {$wpCliBase} eval \"\$CODE\" 2>&1";
        $result = $this->runCommandWithExitCode($connection, $cmd);
        $raw = (string) ($result['raw_output'] ?? '');
        $marker = 'HEXA_POST_UPDATE:';
        $position = strrpos($raw, $marker);
        if ($position === false) {
            $cleanOutput = (string) (($result['clean_output'] ?? '') ?: $raw);
            return ['success' => false, 'message' => 'Fallback eval update failed: ' . \Illuminate\Support\Str::limit($cleanOutput ?: 'no marker returned', 300)];
        }

        $decoded = json_decode(trim(substr($raw, $position + strlen($marker))), true);
        if (!is_array($decoded)) {
            return ['success' => false, 'message' => 'Fallback eval update returned invalid JSON.'];
        }

        return $decoded;
    }

    protected function normalizeStagedImageForWpImport(SSH2|LocalShellConnection $connection, string $tmpDir, string $tmpFile, string $targetFilename, bool $useLocalFilesystem = false): array
    {
        $command = "file -b --mime-type " . escapeshellarg($tmpFile) . " 2>/dev/null || true";
        $mimeOutput = trim($this->execWithConnection($connection, $useLocalFilesystem ? $this->localFilesystemCommand($connection, $command) : $command));
        $mime = strtolower(trim((string) (preg_split('/\R/', $mimeOutput)[0] ?? '')));
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/pjpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/avif' => 'avif',
        ];

        if (!isset($allowed[$mime])) {
            $detected = $mime !== '' ? $mime : 'unknown';
            return ['success' => false, 'message' => 'Downloaded file is not an allowed WordPress image (detected ' . $detected . '). Use a direct image URL, not a preview page URL.'];
        }

        $extension = $allowed[$mime];
        $currentExtension = strtolower((string) pathinfo($targetFilename, PATHINFO_EXTENSION));
        $equivalentJpeg = in_array($currentExtension, ['jpg', 'jpeg'], true) && in_array($extension, ['jpg', 'jpeg'], true);
        if ($currentExtension === $extension || $equivalentJpeg) {
            return ['success' => true, 'path' => $tmpFile, 'filename' => $targetFilename, 'mime' => $mime, 'extension' => $extension];
        }

        $base = (string) pathinfo($targetFilename, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $base), '.-_');
        if ($base === '') {
            $base = 'hexa-upload-' . uniqid();
        }

        $normalizedFilename = $base . '.' . $extension;
        $normalizedPath = rtrim($tmpDir, '/') . '/' . $normalizedFilename;
        $moveCommand = 'mv -f ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($normalizedPath) . ' && chmod 644 ' . escapeshellarg($normalizedPath) . ' 2>&1';
        $move = $this->runCommandWithExitCode($connection, $useLocalFilesystem ? $this->localFilesystemCommand($connection, $moveCommand) : $moveCommand);
        if (($move['exit_code'] ?? 1) !== 0) {
            $cleanOutput = (string) (($move['clean_output'] ?? '') ?: ($move['raw_output'] ?? ''));
            return ['success' => false, 'message' => 'Could not normalize imported image extension: ' . \Illuminate\Support\Str::limit($cleanOutput ?: 'move failed', 300)];
        }

        return ['success' => true, 'path' => $normalizedPath, 'filename' => $normalizedFilename, 'mime' => $mime, 'extension' => $extension];
    }

    protected function stageGdImageEditorRequire(SSH2|LocalShellConnection $connection, string $tmpDir): string
    {
        $requireFile = rtrim($tmpDir, "/") . "/hexa-force-gd-editor.php";
        $php = "<?php if (class_exists(\"WP_CLI\")) { WP_CLI::add_hook(\"after_wp_load\", static function () { add_filter(\"wp_image_editors\", static function () { return [\"WP_Image_Editor_GD\"]; }, 999); }); }";
        $command = "printf %s " . escapeshellarg($php)
            . " > " . escapeshellarg($requireFile)
            . " && chmod 644 " . escapeshellarg($requireFile)
            . " 2>&1";
        $result = $this->runCommandWithExitCode($connection, $this->localFilesystemCommand($connection, $command));

        return (($result["exit_code"] ?? 1) === 0) ? $requireFile : "";
    }


    // === localFilesystemCommand ===
protected function localFilesystemCommand(SSH2|LocalShellConnection $connection, string $command): string
    {
        if ($connection instanceof LocalShellConnection && $this->currentRuntimeUser() !== "root") {
            return "sudo -n /usr/local/bin/hexa-wp-local-fs " . escapeshellarg(base64_encode($command));
        }

        return $command;
    }




}
