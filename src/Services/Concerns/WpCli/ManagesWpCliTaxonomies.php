<?php

namespace hexa_package_wptoolkit\Services\Concerns\WpCli;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use Illuminate\Support\Facades\Cache;
use phpseclib3\Net\SSH2;

trait ManagesWpCliTaxonomies
{
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
}
