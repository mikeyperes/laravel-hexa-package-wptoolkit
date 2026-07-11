<?php

namespace hexa_package_wptoolkit\Services\Concerns;

use hexa_package_whm\Models\WhmServer;

trait ManagesWpCliContent
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
            $pluginFallback = $this->wpCliUpdatePostViaPluginEval($server, $installId, $postId, $postData);
            if (($pluginFallback['success'] ?? false)) {
                return $pluginFallback;
            }

            $fallback = $this->wpCliUpdatePostViaEval($connection, $wpCliBase, $postId, $postData);
            if (($fallback['success'] ?? false)) {
                return $fallback;
            }

            $output .= "\nPlugin-loaded eval update failed: " . (string) ($pluginFallback['message'] ?? 'unknown error');
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
}
