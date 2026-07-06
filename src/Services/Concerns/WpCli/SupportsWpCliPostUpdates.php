<?php

namespace hexa_package_wptoolkit\Services\Concerns\WpCli;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use Illuminate\Support\Facades\Cache;
use phpseclib3\Net\SSH2;

trait SupportsWpCliPostUpdates
{
    protected function wpCliUpdatePostEvalPhp(int $postId, array $postData): string
    {
        $payload = [];
        foreach (['title', 'content', 'status', 'excerpt', 'date', 'author', 'slug', 'post_name', 'featured_media', 'categories', 'tags'] as $field) {
            if (array_key_exists($field, $postData)) {
                $payload[$field] = $postData[$field];
            }
        }

        return '$postId=' . $postId . ';'
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
    }

    protected function wpCliUpdatePostViaPluginEval(WhmServer $server, int $installId, int $postId, array $postData): array
    {
        $result = $this->wpCliEvalWithPlugins($server, $installId, $this->wpCliUpdatePostEvalPhp($postId, $postData), 120);
        if (!($result['success'] ?? false)) {
            return ['success' => false, 'message' => (string) ($result['message'] ?? 'Plugin-loaded eval update failed.')];
        }

        $decoded = $this->decodeWpCliPostUpdateMarker((string) ($result['stdout'] ?? ''));
        if (!($decoded['success'] ?? false)) {
            $decoded['message'] = 'Plugin-loaded eval update failed: ' . (string) ($decoded['message'] ?? 'unknown error');
        }

        return $decoded;
    }

    protected function wpCliUpdatePostViaEval(SSH2|LocalShellConnection $connection, string $wpCliBase, int $postId, array $postData): array
    {
        $encoded = base64_encode($this->wpCliUpdatePostEvalPhp($postId, $postData));
        $cmd = "CODE=$(printf %s " . escapeshellarg($encoded) . " | base64 -d) && {$wpCliBase} eval \"\$CODE\" 2>&1";
        $result = $this->runCommandWithExitCode($connection, $cmd);

        return $this->decodeWpCliPostUpdateMarker((string) ($result['raw_output'] ?? ''));
    }

    protected function decodeWpCliPostUpdateMarker(string $raw): array
    {
        $marker = 'HEXA_POST_UPDATE:';
        $position = strrpos($raw, $marker);
        if ($position === false) {
            return ['success' => false, 'message' => 'Post update eval returned no marker: ' . \Illuminate\Support\Str::limit($raw ?: 'no output', 300)];
        }

        $decoded = json_decode(trim(substr($raw, $position + strlen($marker))), true);
        if (!is_array($decoded)) {
            return ['success' => false, 'message' => 'Post update eval returned invalid JSON.'];
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
        if ($connection instanceof LocalShellConnection && $this->currentRuntimeUser() !== "root" && $this->localPrivilegeBridgeUsable()) {
            return "sudo -n /usr/local/bin/hexa-wp-local-fs " . escapeshellarg(base64_encode($command));
        }

        return $command;
    }
}
