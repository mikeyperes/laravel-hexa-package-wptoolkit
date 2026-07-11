<?php

namespace Tests\Unit;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use phpseclib3\Net\SSH2;
use hexa_package_wptoolkit\Services\Concerns\ManagesWpCli;
use PHPUnit\Framework\TestCase;

class WpCliCommandEscapingTest extends TestCase
{
    public function test_wp_cli_eval_escapes_shell_variable_reference(): void
    {
        $harness = new WpCliHarness();
        $server = new WhmServer();

        $result = $harness->wpCliEval($server, 44, 'echo "hello";');

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($harness->commands);
        $this->assertStringContainsString('-- eval "$CODE" 2>&1', $harness->commands[0]);
    }

    public function test_wp_cli_create_post_uses_a_staged_tag_eval_file(): void
    {
        $harness = new WpCliHarness();
        $server = new WhmServer();

        $result = $harness->wpCliCreatePost(
            $server,
            44,
            'Audit Post',
            '<p>Hello</p>',
            'draft',
            [],
            [10, 11],
            null,
            null,
            null
        );

        $this->assertTrue($result['success']);
        $tagCommand = collect($harness->commands)->first(fn (string $command) => str_contains($command, '-- eval-file'));
        $this->assertNotNull($tagCommand);
        $this->assertStringContainsString("-- eval-file '/tmp/.hexa_wp_tags_", $tagCommand);
        $this->assertStringNotContainsString('$CODE', $tagCommand);
    }
}

class WpCliHarness
{
    use ManagesWpCli;

    /**
     * @var array<int, string>
     */
    public array $commands = [];

    public object $generic;

    public function __construct()
    {
        $this->generic = new class {
            public function log(...$args): void
            {
            }
        };
    }

    public function commandTimeoutSeconds(): int
    {
        return 120;
    }

    protected function getConnection($server): array
    {
        return ['success' => true, 'connection' => new LocalShellConnection()];
    }

    protected function shellBinary($connection, $server): string
    {
        return '/usr/local/bin/wp-toolkit';
    }

    protected function wpCliBaseCommand(WhmServer $server, SSH2|LocalShellConnection $connection, int $installId): string
    {
        return '/usr/local/bin/wp-toolkit --wp-cli -instance-id '.escapeshellarg((string) $installId).' --';
    }

    protected function execWithConnection($connection, string $command): string
    {
        $this->commands[] = $command;

        if (str_contains($command, '__HEXA_CMD_EXIT__')) {
            return "Success: ok\n__HEXA_CMD_EXIT__:0";
        }

        if (str_contains($command, '-- post create')) {
            return '321';
        }

        if (str_contains($command, '-- post get')) {
            return 'https://example.com/posts/audit-post';
        }

        if (str_contains($command, 'wp_set_post_tags')) {
            return 'TAGS_SET';
        }

        return 'Success: ok';
    }
}
