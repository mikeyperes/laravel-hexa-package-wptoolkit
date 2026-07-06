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
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'WP Toolkit connection failed'];
        }

        $connection = $ssh['connection'];
        $wpCliBase = $this->wpCliBaseCommand($server, $connection, $installId);
        $cmd = "{$wpCliBase} post get " . escapeshellarg((string) $postId) . ' --format=json 2>&1';
        $output = trim($this->execWithConnection($connection, $cmd));
        $jsonPayload = $output;
        $jsonStart = strpos($output, '{');
        $jsonEnd = strrpos($output, '}');
        if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd >= $jsonStart) {
            $jsonPayload = substr($output, $jsonStart, $jsonEnd - $jsonStart + 1);
        }
        $json = json_decode($jsonPayload, true);

        if (!is_array($json) || empty($json['ID'])) {
            $this->generic->log('error', '[WpToolkit] wpCliGetPost failed', ['output' => $output, 'post_id' => $postId]);
            return ['success' => false, 'message' => 'wp-cli post get failed: ' . \Illuminate\Support\Str::limit($output, 300)];
        }

        $authorId = isset($json["post_author"]) ? (int) $json["post_author"] : null;
        $authorName = "";
        if ($authorId) {
            $authorCmd = $wpCliBase . " user get " . escapeshellarg((string) $authorId) . " --field=display_name 2>/dev/null";
            $authorName = trim($this->execWithConnection($connection, $authorCmd));
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
                "author_id" => $authorId,
                "author_name" => $authorName,
                "post_content" => (string) ($json["post_content"] ?? ""),
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
    public function wpCliUploadMedia(WhmServer $server, int $installId, string $imageUrl, string $filename = '', string $altText = '', string $caption = '', string $description = ''): array
    {
        $imageUrl = trim(html_entity_decode($imageUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'WP Toolkit connection failed'];
        }

        $connection = $ssh['connection'];
        $wpCliBase = $this->wpCliBaseCommand($server, $connection, $installId);
        $installPath = $this->resolveInstallPath($server, $connection, $installId);
        if (!$installPath) {
            return ['success' => false, 'message' => 'Unable to resolve WordPress install path for media import'];
        }

        // Download inside the WordPress install path so wp-toolkit/wp-cli can actually see the file.
        $parsedPath = parse_url($imageUrl, PHP_URL_PATH) ?: '';
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];
        $ext = strtolower((string) (pathinfo($parsedPath, PATHINFO_EXTENSION) ?: 'jpg'));
        $ext = preg_replace('/[^a-zA-Z0-9].*/', '', $ext) ?: 'jpg';
        if ($ext === 'jpeg') $ext = 'jpg';
        if (!in_array($ext, $allowedExt, true)) $ext = 'jpg';

        $targetFilename = $filename ?: ('hexa-upload-' . uniqid() . '.' . $ext);
        $targetExt = strtolower((string) pathinfo($targetFilename, PATHINFO_EXTENSION));
        $targetExt = preg_replace('/[^a-zA-Z0-9].*/', '', $targetExt) ?: '';
        if ($targetExt === 'jpeg') $targetExt = 'jpg';
        if ($targetExt === '' || !in_array($targetExt, $allowedExt, true)) {
            $targetBase = pathinfo($targetFilename, PATHINFO_FILENAME) ?: ('hexa-upload-' . uniqid());
            $targetFilename = $targetBase . '.' . $ext;
        }
        $targetFilename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $targetFilename) ?: ('hexa-upload-' . uniqid() . '.' . $ext);
        if ($connection instanceof LocalShellConnection && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            $directImport = $this->wpDirectMediaSideload($connection, $installId, $imageUrl, $targetFilename, $altText, $caption, $description);
            if (($directImport["success"] ?? false) === true) {
                return $directImport;
            }
            $this->generic->log("warning", "[WpToolkit] Direct media sideload failed; falling back to media import", [
                "message" => (string) ($directImport["message"] ?? "unknown error"),
                "url" => $imageUrl,
                "filename" => $targetFilename,
            ]);
        }
        $tmpDir = rtrim($installPath, '/') . '/.hexa-import-' . uniqid();
        $tmpFile = $tmpDir . '/' . $targetFilename;
        $useDirectUrlImport = $connection instanceof LocalShellConnection && filter_var($imageUrl, FILTER_VALIDATE_URL);
        $importSource = $useDirectUrlImport ? $imageUrl : $tmpFile;
        if (!$useDirectUrlImport) {
        $this->execWithConnection($connection, "mkdir -p " . escapeshellarg($tmpDir));
        $referer = '';
        $urlHost = parse_url($imageUrl, PHP_URL_HOST);
        if ($urlHost) {
            $referer = (parse_url($imageUrl, PHP_URL_SCHEME) ?: 'https') . '://' . $urlHost . '/';
        }
        $curlCmd = "curl -fL --compressed --retry 1 --connect-timeout 8 --max-time 35 -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0.0.0'"
            . " -H " . escapeshellarg('Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8')
            . ($referer ? " -e " . escapeshellarg($referer) : '')
            . " -o " . escapeshellarg($tmpFile)
            . " " . escapeshellarg($imageUrl)
            . " && chmod 644 " . escapeshellarg($tmpFile)
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
        }

        $titleArg = $targetFilename ? " --title=" . escapeshellarg(pathinfo($targetFilename, PATHINFO_FILENAME)) : '';
        $fileNameArg = $targetFilename ? " --file_name=" . escapeshellarg(pathinfo($targetFilename, PATHINFO_FILENAME)) : '';
        $altArg = $altText ? " --alt=" . escapeshellarg($altText) : '';
        $captionArg = $caption ? " --caption=" . escapeshellarg($caption) : '';
        $descriptionArg = $description ? " --desc=" . escapeshellarg($description) : '';
        $cmd = "{$wpCliBase} media import "
            . escapeshellarg($importSource)
            . $fileNameArg
            . $titleArg
            . $captionArg
            . $altArg
            . $descriptionArg
            . " --porcelain 2>&1";
        $import = $this->runCommandWithExitCode($connection, $cmd);
        if (!$useDirectUrlImport) {
            $this->execWithConnection($connection, "rm -rf " . escapeshellarg($tmpDir));
        }

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
                    'filename' => $targetFilename,
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

    private function wpDirectMediaSideload(LocalShellConnection $connection, int $installId, string $imageUrl, string $targetFilename, string $altText = "", string $caption = "", string $description = ""): array
    {
        $wrapper = base_path("storage/app/server-tools/hexa-wp-direct-media-local.sh");
        if (!is_file($wrapper) || !is_executable($wrapper)) {
            return ["success" => false, "message" => "Direct media sideload helper is not installed or executable."];
        }

        $cmd = escapeshellarg($wrapper)
            . " --instance-id=" . escapeshellarg((string) $installId)
            . " --url=" . escapeshellarg($imageUrl)
            . " --filename=" . escapeshellarg($targetFilename)
            . " --alt=" . escapeshellarg($altText)
            . " --caption=" . escapeshellarg($caption)
            . " --description=" . escapeshellarg($description)
            . " 2>&1";

        $result = $this->runCommandWithExitCode($connection, $cmd);
        $rawOutput = (string) ($result["raw_output"] ?? implode("\n", (array) ($result["lines"] ?? [])));
        $payload = null;
        foreach (explode("\n", $rawOutput) as $line) {
            if (str_contains($line, "HEXA_MEDIA_IMPORT:")) {
                $json = substr($line, strpos($line, "HEXA_MEDIA_IMPORT:") + 18);
                $decoded = json_decode(trim($json), true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                    break;
                }
            }
        }

        if (!is_array($payload)) {
            return ["success" => false, "message" => "Direct media sideload did not return a parseable response: " . \Illuminate\Support\Str::limit(trim($rawOutput) ?: "empty output", 300)];
        }

        if (($payload["success"] ?? false) !== true) {
            return ["success" => false, "message" => (string) ($payload["message"] ?? "Direct media sideload failed.")];
        }

        $mediaId = (int) ($payload["media_id"] ?? 0);
        $mediaUrl = (string) ($payload["media_url"] ?? "");
        if ($mediaId <= 0 || $mediaUrl === "") {
            return ["success" => false, "message" => "Direct media sideload returned incomplete media data."];
        }

        $sizes = is_array($payload["sizes"] ?? null) ? $payload["sizes"] : ["full" => $mediaUrl];
        $this->generic->log("info", "[WpToolkit] Media sideloaded directly", ["media_id" => $mediaId, "url" => $mediaUrl, "sizes" => count($sizes)]);

        return [
            "success" => true,
            "message" => "Media uploaded (ID: {$mediaId})",
            "data" => [
                "media_id" => $mediaId,
                "media_url" => $mediaUrl,
                "sizes" => $sizes,
                "source_url" => $imageUrl,
                "filename" => (string) ($payload["filename"] ?? $targetFilename),
                "file_path" => (string) ($payload["file_path"] ?? ""),
                "file_size" => (int) ($payload["file_size"] ?? 0),
                "alt_text" => $altText,
                "caption" => $caption,
                "description" => $description,
            ],
        ];
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

    protected function stageWpCliTempFile(SSH2|LocalShellConnection $connection, string $path, string $contents): ?string
    {
        try {
            if ($connection instanceof LocalShellConnection) {
                $bytes = @file_put_contents($path, $contents);
                if ($bytes === false) {
                    $error = error_get_last();
                    return (string) ($error['message'] ?? 'local file write failed');
                }

                return null;
            }

            if (method_exists($connection, 'put')) {
                return $connection->put($path, $contents) ? null : 'WP Toolkit temp file write failed';
            }

            $tmpBase64 = $path . '.b64';
            $this->execWithConnection($connection, 'rm -f ' . escapeshellarg($tmpBase64) . ' ' . escapeshellarg($path));
            $encoded = base64_encode($contents);
            foreach (str_split($encoded, 24000) as $chunk) {
                $appendCmd = 'printf %s ' . escapeshellarg($chunk) . ' >> ' . escapeshellarg($tmpBase64);
                $chunkResult = $this->runCommandWithExitCode($connection, $appendCmd . ' 2>&1');
                if (((int) ($chunkResult['exit_code'] ?? 1)) !== 0) {
                    return 'chunked temp write failed: ' . \Illuminate\Support\Str::limit($chunkResult['clean_output'] ?: $chunkResult['raw_output'], 300);
                }
            }

            $decodeCmd = 'base64 -d ' . escapeshellarg($tmpBase64) . ' > ' . escapeshellarg($path);
            $decodeResult = $this->runCommandWithExitCode($connection, $decodeCmd . ' 2>&1');
            $this->execWithConnection($connection, 'rm -f ' . escapeshellarg($tmpBase64));
            if (((int) ($decodeResult['exit_code'] ?? 1)) !== 0) {
                return 'temp decode failed: ' . \Illuminate\Support\Str::limit($decodeResult['clean_output'] ?: $decodeResult['raw_output'], 300);
            }

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
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
            $this->generic->log('error', '[WpToolkit] command timed out', [
                'timeout' => $timeout,
                'command' => \Illuminate\Support\Str::limit($cmd, 160),
            ]);

            throw new \RuntimeException("WP Toolkit command timed out after {$timeout} seconds");
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
     * Create or get a WordPress category via WP Toolkit wp-cli.
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
            return ['success' => false, 'message' => $ssh['error'] ?? 'WP Toolkit connection failed'];
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
     * Create or get a WordPress tag via WP Toolkit wp-cli.
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
            return ['success' => false, 'message' => $ssh['error'] ?? 'WP Toolkit connection failed'];
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
     * Test write permissions on a WordPress install via WP Toolkit wp-cli.
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
            return ['success' => false, 'message' => $ssh['error'] ?? 'WP Toolkit connection failed'];
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
            return ['success' => false, 'authors' => [], 'message' => $ssh['error'] ?? 'WP Toolkit connection failed'];
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
            return ['success' => false, 'categories' => [], 'message' => $ssh['error'] ?? 'WP Toolkit connection failed'];
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
            return ['success' => false, 'stdout' => '', 'message' => $ssh['error'] ?? 'WP Toolkit connection failed'];
        }

        $connection = $ssh['connection'];
        $wpCliBase = $this->wpCliBaseCommand($server, $connection, $installId);
        $b64 = base64_encode($php);
        $cmd = "CODE=$(echo '" . $b64 . "' | base64 -d) && {$wpCliBase} eval \"\$CODE\" 2>&1";
        $out = trim($this->execWithConnection($connection, $cmd));
        return ['success' => true, 'stdout' => $out];
    }
    // ===== code-side unique methods (preserved during 3-way merge) =====

    // === wpCliBatchTerms ===
private function wpCliBatchTerms(WhmServer $server, int $installId, array $names, string $taxonomy): array
    {
        if (empty($names)) return ['success' => true, 'term_ids' => [], 'message' => 'No terms'];

        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'term_ids' => [], 'message' => $ssh['error'] ?? 'WP Toolkit connection failed'];
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
     * Delete a WordPress post via WP Toolkit wp-cli.
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
            return ['success' => false, 'message' => $ssh['error'] ?? 'WP Toolkit connection failed'];
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
            return ['success' => false, 'message' => $ssh['error'] ?? 'WP Toolkit connection failed'];
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
     * Delete a WordPress media attachment via WP Toolkit wp-cli.
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
            return ["success" => false, "message" => $ssh["error"] ?? "WP Toolkit connection failed"];
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

        $stageCommand = "mkdir -p " . escapeshellarg($tmpDir)
            . " && test -r " . escapeshellarg($sourcePath)
            . " && cp -f " . escapeshellarg($sourcePath) . " " . escapeshellarg($tmpFile)
            . " && chmod 644 " . escapeshellarg($tmpFile)
            . " 2>&1";
        $copy = $this->runCommandWithExitCode(
            $connection,
            $this->localFilesystemCommand($connection, $stageCommand)
        );
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

        $titleArg = $filename ? " --title=" . escapeshellarg(pathinfo($filename, PATHINFO_FILENAME)) : "";
        $fileNameArg = $filename ? " --file_name=" . escapeshellarg(pathinfo($filename, PATHINFO_FILENAME)) : "";
        $altArg = $altText ? " --alt=" . escapeshellarg($altText) : "";
        $captionArg = $caption ? " --caption=" . escapeshellarg($caption) : "";
        $descriptionArg = $description ? " --desc=" . escapeshellarg($description) : "";
        $cmd = "{$wptBin} --wp-cli -instance-id {$escapedId} -- media import "
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


    public function extractJsonObjectFromOutput(string $output): ?array
    {
        $trimmed = trim($output);
        if ($trimmed === "") {
            return null;
        }

        if (preg_match("/\{.*\}/s", $trimmed, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
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
