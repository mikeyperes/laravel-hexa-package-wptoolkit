<?php

namespace hexa_package_wptoolkit\Tests\Unit;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\Concerns\ManagesWpCli;
use PHPUnit\Framework\TestCase;

class WpCliTaxonomyAssignmentTest extends TestCase
{
    public function test_empty_term_list_executes_wordpress_and_confirms_the_clear(): void
    {
        $harness = new WpCliTaxonomyHarness([
            'success' => true,
            'stdout' => 'HEXA_ASSIGN_TERMS:{"success":true,"message":"Cleared terms from publication.",'
                .'"term_ids":[],"term_taxonomy_ids":[]}',
        ]);

        $result = $harness->wpCliSetPostTerms(
            new WhmServer(),
            17,
            991,
            'publication',
            [],
        );

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['term_ids']);
        $this->assertStringContainsString('Cleared terms from publication', $result['message']);
        $this->assertStringContainsString('wp_set_object_terms($postId, $termIds, $taxonomy, false)', $harness->evaluatedPhp);
        $this->assertStringContainsString('$termIds = array (', $harness->evaluatedPhp);
        $this->assertSame(1, $harness->pluginEvaluationCalls);
        $this->assertSame(0, $harness->fallbackEvaluationCalls);
    }

    public function test_wrapper_eval_is_only_used_when_plugin_loaded_eval_cannot_run(): void
    {
        $harness = new WpCliTaxonomyHarness(
            [
                'success' => false,
                'stdout' => '',
                'message' => 'Native wp-cli is unavailable.',
            ],
            [
                'success' => true,
                'stdout' => 'HEXA_ASSIGN_TERMS:{"success":true,"message":"Assigned terms to publication.",'
                    .'"term_ids":[2891],"term_taxonomy_ids":[7123]}',
            ],
        );

        $result = $harness->wpCliSetPostTerms(
            new WhmServer(),
            17,
            991,
            'publication',
            [2891],
        );

        $this->assertTrue($result['success']);
        $this->assertSame([2891], $result['term_ids']);
        $this->assertSame(1, $harness->pluginEvaluationCalls);
        $this->assertSame(1, $harness->fallbackEvaluationCalls);
    }
}

final class WpCliTaxonomyHarness
{
    use ManagesWpCli;

    public string $evaluatedPhp = '';
    public int $pluginEvaluationCalls = 0;
    public int $fallbackEvaluationCalls = 0;

    public function __construct(
        private readonly array $pluginEvaluation,
        private readonly ?array $fallbackEvaluation = null,
    ) {}

    public function commandTimeoutSeconds(): int
    {
        return 120;
    }

    public function wpCliEval(WhmServer $server, int $installId, string $php, ?int $timeout = null): array
    {
        $this->evaluatedPhp = $php;
        $this->fallbackEvaluationCalls++;

        return $this->fallbackEvaluation ?? $this->pluginEvaluation;
    }

    public function wpCliEvalWithPlugins(WhmServer $server, int $installId, string $php, int $timeout = 120): array
    {
        $this->evaluatedPhp = $php;
        $this->pluginEvaluationCalls++;

        return $this->pluginEvaluation;
    }
}
