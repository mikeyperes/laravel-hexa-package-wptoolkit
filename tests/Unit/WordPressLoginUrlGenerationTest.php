<?php

namespace Tests\Unit;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\Concerns\ManagesLogins;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use PHPUnit\Framework\TestCase;

class WordPressLoginUrlGenerationTest extends TestCase
{
    public function test_login_bootstrap_requires_an_absolute_account_path(): void
    {
        $harness = new WordPressLoginHarness();

        $result = $harness->generateWordPressLoginUrl(
            new WhmServer(['name' => 'Local']),
            'home/client/public_html',
            'client',
            'administrator',
            'https://example.com'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('absolute path', $result['error']);
        $this->assertSame([], $harness->commands);
    }

    public function test_login_bootstrap_is_verified_concurrency_safe_and_uncached(): void
    {
        $harness = new WordPressLoginHarness();

        $result = $harness->generateWordPressLoginUrl(
            new WhmServer(['name' => 'Local']),
            '/home/client/public_html',
            'client',
            'administrator',
            'https://example.com',
            'wp-admin/post.php?post=42&action=edit'
        );

        $this->assertTrue($result['success']);
        $this->assertStringStartsWith(
            'https://example.com/wp-admin/admin-ajax.php?action=hws_auto_login&hws_login_token=',
            $result['url']
        );
        $this->assertTrue($harness->filesystemCommandWrapped);
        $this->assertCount(1, $harness->commands);
        $command = $harness->commands[0];
        $this->assertStringContainsString("'/home/client/public_html/wp-content/mu-plugins'", $command);
        $this->assertMatchesRegularExpression('/hws-auto-login-[a-f0-9]{20}\.php/', $command);
        $this->assertStringNotContainsString('/hws-auto-login.php', $command);
        $this->assertStringContainsString('test -s', $command);

        preg_match("/printf %s '([^']+)' \\| base64 -d/", $command, $matches);
        $plugin = base64_decode($matches[1] ?? '', true);
        $this->assertIsString($plugin);
        $this->assertStringContainsString('hash_equals($token, $provided_token)', $plugin);
        $this->assertStringContainsString("admin_url('post.php?post=42&action=edit')", $plugin);
        $this->assertStringNotContainsString("provided_token)) {\n        @unlink", $plugin);
    }

    public function test_login_bootstrap_does_not_return_a_url_when_write_verification_fails(): void
    {
        $harness = new WordPressLoginHarness();
        $harness->writeResult = [
            'exit_code' => 1,
            'clean_output' => 'permission denied',
            'raw_output' => 'permission denied',
        ];

        $result = $harness->generateWordPressLoginUrl(
            new WhmServer(['name' => 'Local']),
            '/home/client/public_html',
            'client',
            'administrator',
            'https://example.com'
        );

        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('url', $result);
        $this->assertStringContainsString('write and verify', $result['error']);
    }
}

class WordPressLoginHarness
{
    use ManagesLogins;

    /** @var array<int, string> */
    public array $commands = [];

    public bool $filesystemCommandWrapped = false;

    /** @var array{exit_code: int, clean_output: string, raw_output: string} */
    public array $writeResult = [
        'exit_code' => 0,
        'clean_output' => 'MU_PLUGIN_OK',
        'raw_output' => 'MU_PLUGIN_OK',
    ];

    public object $generic;

    public function __construct()
    {
        $this->generic = new class {
            public function log(...$arguments): void
            {
            }
        };
    }

    public function getConnection(WhmServer $server): array
    {
        return ['success' => true, 'connection' => new LocalShellConnection()];
    }

    protected function localFilesystemCommand(LocalShellConnection $connection, string $command): string
    {
        $this->filesystemCommandWrapped = true;

        return $command;
    }

    protected function runCommandWithExitCode(LocalShellConnection $connection, string $command): array
    {
        $this->commands[] = $command;

        return $this->writeResult;
    }

    public function disconnectCachedConnection(?WhmServer $server = null, mixed $connection = null): void
    {
    }
}
