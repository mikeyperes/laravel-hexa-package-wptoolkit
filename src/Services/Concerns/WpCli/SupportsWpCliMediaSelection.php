<?php

namespace hexa_package_wptoolkit\Services\Concerns\WpCli;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use Illuminate\Support\Facades\Cache;
use phpseclib3\Net\SSH2;

trait SupportsWpCliMediaSelection
{
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
            '    "google_drive_file_id"=>(string)get_post_meta($id,"_hexa_google_drive_file_id",true), "_hexa_google_drive_file_id"=>(string)get_post_meta($id,"_hexa_google_drive_file_id",true),',
            '    "source_filename"=>(string)get_post_meta($id,"_hexa_source_filename",true), "_hexa_source_filename"=>(string)get_post_meta($id,"_hexa_source_filename",true),',
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
}
