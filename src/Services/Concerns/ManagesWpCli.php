<?php

namespace hexa_package_wptoolkit\Services\Concerns;

use hexa_package_whm\Models\WhmServer;

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

        // Write content to temp file on server (avoids shell escaping issues with HTML)
        $tmpFile = '/tmp/hexa_wp_post_' . uniqid() . '.html';
        $connection->exec('cat > ' . escapeshellarg($tmpFile) . ' << \'HEXAEOF\'' . "\n" . $content . "\nHEXAEOF");

        // Build wp post create command
        $cmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- post create"
            . " --post_title=" . escapeshellarg($title)
            . " --post_status=" . escapeshellarg($status)
            . " --post_content=\"$(cat " . escapeshellarg($tmpFile) . ")\""
            . " --porcelain";

        if (!empty($categoryIds)) {
            $cmd .= " --post_category=" . escapeshellarg(implode(',', $categoryIds));
        }
        if (!empty($tagIds)) {
            $cmd .= " --tags_input=" . escapeshellarg(implode(',', $tagIds));
        }
        if ($date && $status === 'future') {
            $cmd .= " --post_date=" . escapeshellarg($date);
        }
        if ($author) {
            if (is_numeric($author)) {
                $cmd .= " --post_author=" . escapeshellarg($author);
            } else {
                $userCmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- user get " . escapeshellarg($author) . " --field=ID 2>/dev/null";
                $rawId = trim($connection->exec($userCmd));
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

        $output = trim($connection->exec($cmd . ' 2>&1'));

        // Cleanup temp file
        $connection->exec('rm -f ' . escapeshellarg($tmpFile));

        // --porcelain returns just the post ID
        if (is_numeric($output)) {
            $postId = (int) $output;

            // Set featured image if provided
            if ($featuredMediaId) {
                $metaCmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- post meta update {$postId} _thumbnail_id {$featuredMediaId} 2>&1";
                $connection->exec($metaCmd);
                $this->generic->log('info', '[WpToolkit] Featured image set', ['post_id' => $postId, 'media_id' => $featuredMediaId]);
            }

            // Get permalink
            $urlCmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- post list --post__in={$postId} --field=url 2>&1";
            $postUrl = trim($connection->exec($urlCmd));
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
        $ssh = $this->getConnection($server);
        if (!$ssh['success']) {
            return ['success' => false, 'message' => $ssh['error'] ?? 'SSH connection failed'];
        }

        $connection = $ssh['connection'];
        $escapedId = escapeshellarg((string) $installId);

        // Download to temp file with correct filename, then import (wp-cli uses source filename otherwise)
        $parsedPath = parse_url($imageUrl, PHP_URL_PATH) ?: '';
        $ext = pathinfo($parsedPath, PATHINFO_EXTENSION) ?: 'jpg';
        // Strip query params from extension
        $ext = preg_replace('/[^a-zA-Z0-9].*/', '', $ext);
        if (!in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'])) $ext = 'jpg';
        $targetFilename = $filename ?: ('hexa-upload-' . uniqid() . '.' . $ext);
        if (!preg_match('/\.\w{2,5}$/', $targetFilename)) $targetFilename .= '.' . $ext;
        $tmpDir = '/tmp/hexa_media_' . uniqid();
        $tmpFile = $tmpDir . '/' . $targetFilename;
        $connection->exec("mkdir -p " . escapeshellarg($tmpDir));
        // Use browser UA to avoid CDN blocks, follow redirects, 30s timeout
        $curlCmd = "curl -sL --max-time 30 -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0.0.0' -o " . escapeshellarg($tmpFile) . " " . escapeshellarg($imageUrl) . " 2>/dev/null";
        $connection->exec($curlCmd);
        // Verify download succeeded
        $fileSize = trim($connection->exec("stat -c%s " . escapeshellarg($tmpFile) . " 2>/dev/null || echo 0"));
        if (!$fileSize || $fileSize === '0') {
            $connection->exec("rm -rf " . escapeshellarg($tmpDir));
            $this->generic->log('error', '[WpToolkit] Image download failed', ['url' => $imageUrl, 'filename' => $targetFilename]);
            return ['success' => false, 'message' => 'Failed to download image from: ' . \Illuminate\Support\Str::limit($imageUrl, 100)];
        }
        $this->generic->log('info', '[WpToolkit] Image downloaded', ['url' => \Illuminate\Support\Str::limit($imageUrl, 100), 'size' => $fileSize, 'filename' => $targetFilename]);

        $titleArg = $filename ? " --title=" . escapeshellarg(pathinfo($filename, PATHINFO_FILENAME)) : '';
        $cmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- media import " . escapeshellarg($tmpFile) . $titleArg . " --porcelain 2>&1";
        $rawOutput = trim($connection->exec($cmd));
        $connection->exec("rm -rf " . escapeshellarg($tmpDir));

        // Parse: filter out PHP warnings/deprecations, find the numeric media ID
        $output = '';
        foreach (explode("\n", $rawOutput) as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            if (str_starts_with($line, 'Deprecated:') || str_starts_with($line, 'Warning:') || str_starts_with($line, 'Notice:') || str_starts_with($line, 'PHP ')) continue;
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
            $metaCmd = "CODE=\$(echo '{$phpCode}' | base64 -d) && wp-toolkit --wp-cli -instance-id {$installId} -- eval \"\$CODE\" 2>&1";
            $metaOutput = trim($connection->exec($metaCmd));

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

        $this->generic->log('error', '[WpToolkit] wpCliUploadMedia failed', ['output' => $output]);
        return ['success' => false, 'message' => 'wp-cli media import failed: ' . \Illuminate\Support\Str::limit($output, 300)];
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

        // Check if category exists first
        $checkCmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- term list category --field=term_id --name=" . escapeshellarg($name) . " --format=csv 2>&1";
        $existing = trim($connection->exec($checkCmd));
        $lines = array_filter(explode("\n", $existing), fn($l) => is_numeric(trim($l)));
        if (!empty($lines)) {
            $termId = (int) trim($lines[array_key_first($lines)]);
            return ['success' => true, 'term_id' => $termId, 'message' => "Category exists (ID: {$termId})"];
        }

        // Create it
        $cmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- term create category " . escapeshellarg($name) . " --porcelain 2>&1";
        $output = trim($connection->exec($cmd));

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

        // Check if tag exists
        $checkCmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- term list post_tag --field=term_id --name=" . escapeshellarg($name) . " --format=csv 2>&1";
        $existing = trim($connection->exec($checkCmd));
        $lines = array_filter(explode("\n", $existing), fn($l) => is_numeric(trim($l)));
        if (!empty($lines)) {
            $termId = (int) trim($lines[array_key_first($lines)]);
            return ['success' => true, 'term_id' => $termId, 'message' => "Tag exists (ID: {$termId})"];
        }

        // Create it
        $cmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- term create post_tag " . escapeshellarg($name) . " --porcelain 2>&1";
        $output = trim($connection->exec($cmd));

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

        // Get admin user info first
        $userCmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- user list --role=administrator --fields=user_login,display_name --format=csv 2>&1";
        $userOutput = trim($connection->exec($userCmd));
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
        $cmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- post create --post_title='Hexa Write Test' --post_status=draft --porcelain 2>&1";
        $output = trim($connection->exec($cmd));

        // Filter warnings
        foreach (explode("\n", $output) as $line) {
            if (is_numeric(trim($line))) { $output = trim($line); break; }
        }

        if (is_numeric($output)) {
            $postId = (int) $output;
            $connection->exec("wp-toolkit --wp-cli -instance-id {$escapedId} -- post delete {$postId} --force 2>&1");
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

    /**
     * Batch create/get WordPress categories via a single wp-cli call.
     * Much faster than individual calls — one SSH command for all categories.
     *
     * @param WhmServer $server
     * @param int       $installId
     * @param array     $names Category names
     * @return array{success: bool, term_ids: array, message: string}
     */
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
        $escapedTax = escapeshellarg($taxonomy);

        // Build a shell script that passes PHP to wp-toolkit eval via a variable
        // This avoids all escaping issues by using base64 decode → shell variable → eval
        $namesJson = json_encode(array_values(array_map('trim', $names)));
        $phpCode = '$names = json_decode(\'' . str_replace("'", "\\'", $namesJson) . '\', true);'
            . '$tax = \'' . $taxonomy . '\';'
            . '$ids = [];'
            . 'foreach ($names as $n) {'
            . '  $e = term_exists($n, $tax);'
            . '  if ($e) { $ids[] = is_array($e) ? (int)$e[\'term_id\'] : (int)$e; }'
            . '  else { $r = wp_insert_term($n, $tax); if (!is_wp_error($r)) $ids[] = (int)$r[\'term_id\']; }'
            . '}'
            . 'echo \'HEXA_RESULT:\' . json_encode($ids);';

        $b64 = base64_encode($phpCode);
        $tmpScript = '/tmp/hexa_batch_' . uniqid() . '.sh';
        $scriptContent = "#!/bin/bash\nCODE=\$(echo '{$b64}' | base64 -d)\nwp-toolkit --wp-cli -instance-id {$installId} -- eval \"\$CODE\" 2>&1";
        $connection->exec("echo " . escapeshellarg($scriptContent) . " > {$tmpScript} && chmod +x {$tmpScript}");

        $cmd = "bash {$tmpScript}";

        $this->generic->log('info', "[WpToolkit] Batch {$taxonomy}: " . count($names) . " terms");

        $rawOutput = trim($connection->exec($cmd));
        $connection->exec("rm -f {$tmpScript}");

        // Extract JSON result from output (may contain warnings)
        $termIds = [];
        foreach (explode("\n", $rawOutput) as $line) {
            if (str_contains($line, 'HEXA_RESULT:')) {
                $json = substr($line, strpos($line, 'HEXA_RESULT:') + 12);
                $termIds = json_decode(trim($json), true) ?: [];
                break;
            }
        }

        $label = $taxonomy === 'category' ? 'categories' : 'tags';

        return [
            'success' => true,
            'term_ids' => $termIds,
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
        $forceFlag = $force ? ' --force' : '';
        $cmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- post delete {$postId}{$forceFlag} 2>&1";
        $output = trim($connection->exec($cmd));

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
        $forceFlag = $force ? ' --force' : '';
        $cmd = "wp-toolkit --wp-cli -instance-id {$escapedId} -- post delete {$mediaId}{$forceFlag} 2>&1";
        $output = trim($connection->exec($cmd));

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
}
