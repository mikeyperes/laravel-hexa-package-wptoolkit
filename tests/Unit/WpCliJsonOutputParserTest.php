<?php

namespace hexa_package_wptoolkit\Tests\Unit;

use hexa_package_wptoolkit\Services\Concerns\RunsWpCliCommands;
use PHPUnit\Framework\TestCase;

class WpCliJsonOutputParserTest extends TestCase
{
    public function test_it_extracts_a_nested_json_object_from_noisy_wp_cli_output(): void
    {
        $output = 'Deprecated notice {not-json}'."\n"
            .'HEXA_ASSIGN_TERMS:{"success":true,"message":"Assigned {terms}",'
            .'"meta":{"term_ids":[2891]}} trailing output';

        $this->assertSame([
            'success' => true,
            'message' => 'Assigned {terms}',
            'meta' => ['term_ids' => [2891]],
        ], $this->parser()->parseJsonObject($output));
    }

    public function test_it_rejects_output_without_a_complete_json_object(): void
    {
        $this->assertNull($this->parser()->parseJsonObject('notice {"success":'));
        $this->assertNull($this->parser()->parseJsonObject('plain command output'));
    }

    private function parser(): object
    {
        return new class
        {
            use RunsWpCliCommands;

            public function parseJsonObject(string $output): ?array
            {
                return $this->extractJsonObjectFromOutput($output);
            }
        };
    }
}
