<?php

namespace hexa_package_wptoolkit\Services\Concerns\WpCli;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use Illuminate\Support\Facades\Cache;
use phpseclib3\Net\SSH2;

trait ManagesWpCliMedia
{
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

        $mkdir = $this->runCommandWithExitCode(
            $connection,
            $this->localFilesystemCommand(
                $connection,
                "mkdir -p " . escapeshellarg($tmpDir) . " && chmod 755 " . escapeshellarg($tmpDir) . " 2>&1",
            ),
        );
        if (($mkdir["exit_code"] ?? 1) !== 0) {
            $cleanOutput = $mkdir["clean_output"] ?: $mkdir["raw_output"];

            return [
                "success" => false,
                "message" => "Could not create the WordPress media staging directory: "
                    . \Illuminate\Support\Str::limit($cleanOutput ?: "directory creation failed", 300),
            ];
        }

        $stageError = $this->transferLocalFileToWpCliServer($server, $connection, $sourcePath, $tmpFile);
        $expectedBytes = (int) filesize($sourcePath);
        $remoteBytes = (int) trim($this->execWithConnection(
            $connection,
            $this->localFilesystemCommand(
                $connection,
                "stat -c%s " . escapeshellarg($tmpFile) . " 2>/dev/null || echo 0",
            ),
        ));

        if ($stageError !== null || $remoteBytes !== $expectedBytes) {
            $this->execWithConnection(
                $connection,
                $this->localFilesystemCommand($connection, "rm -rf " . escapeshellarg($tmpDir)),
            );
            $failure = $stageError
                ?: "Staged file size mismatch: expected {$expectedBytes}, received {$remoteBytes}.";
            $this->generic->log("error", "[WpToolkit] Local media staging failed", [
                "source_path" => $sourcePath,
                "install_path" => $installPath,
                "tmp_file" => $tmpFile,
                "expected_bytes" => $expectedBytes,
                "remote_bytes" => $remoteBytes,
                "connection_mode" => $connection instanceof LocalShellConnection ? "local" : "remote",
                "output" => $failure,
            ]);

            return [
                "success" => false,
                "message" => "Could not stage the media file for the WordPress import: "
                    . \Illuminate\Support\Str::limit($failure, 300),
            ];
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
}
