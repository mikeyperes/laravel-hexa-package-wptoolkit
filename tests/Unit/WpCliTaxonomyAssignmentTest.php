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
    }
}

final class WpCliTaxonomyHarness
{
    use ManagesWpCli;

    public string $evaluatedPhp = '';

    public function __construct(private readonly array $evaluation)
    {
    }

    public function commandTimeoutSeconds(): int
    {
        return 120;
    }

    public function wpCliEval(WhmServer $server, int $installId, string $php, ?int $timeout = null): array
    {
        $this->evaluatedPhp = $php;

        return $this->evaluation;
    }
}
