<?php

namespace hexa_package_wptoolkit\Tests\Unit;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\Concerns\ManagesWpToolkitOperations;
use PHPUnit\Framework\TestCase;

class WpToolkitPluginLifecycleTest extends TestCase
{
    public function test_existing_active_plugin_is_a_noop(): void
    {
        $harness = new PluginLifecycleHarness([
            'is-installed' => ['exit_code' => 0, 'clean_output' => ''],
            'is-active' => ['exit_code' => 0, 'clean_output' => ''],
            'plugin get' => ['exit_code' => 0, 'clean_output' => '{"name":"Site Kit by Google","version":"1.2.3"}'],
        ]);

        $result = $harness->ensurePluginInstalledAndActive(new WhmServer(), 42, 'google-site-kit');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['changed']);
        $this->assertTrue($result['installed']);
        $this->assertTrue($result['active']);
        $this->assertSame('1.2.3', $result['plugin']['version']);
        $this->assertFalse(collect($harness->commands)->contains(fn (string $command): bool => str_contains($command, 'plugin install')));
    }

    public function test_missing_plugin_is_installed_and_activated(): void
    {
        $harness = new PluginLifecycleHarness([
            'is-installed' => ['exit_code' => 1, 'clean_output' => ''],
            'plugin install' => ['exit_code' => 0, 'clean_output' => 'Installed.'],
            'is-active' => ['exit_code' => 1, 'clean_output' => ''],
            'plugin activate' => ['exit_code' => 0, 'clean_output' => 'Activated.'],
            'plugin get' => ['exit_code' => 0, 'clean_output' => '{"name":"Site Kit by Google","version":"1.2.3"}'],
        ]);

        $result = $harness->ensurePluginInstalledAndActive(new WhmServer(), 42, 'google-site-kit');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['changed']);
        $this->assertSame(['install', 'activate'], array_column($result['steps'], 'action'));
    }

    public function test_invalid_plugin_slug_is_rejected_before_connection(): void
    {
        $harness = new PluginLifecycleHarness([]);

        $result = $harness->ensurePluginInstalledAndActive(new WhmServer(), 42, '../bad');

        $this->assertFalse($result['success']);
        $this->assertSame([], $harness->commands);
    }
}

class PluginLifecycleHarness
{
    use ManagesWpToolkitOperations;

    /** @var array<int, string> */
    public array $commands = [];

    /** @param array<string, array<string, mixed>> $responses */
    public function __construct(private readonly array $responses)
    {
    }

    protected function getConnection(WhmServer $server): array
    {
        return ['success' => true, 'connection' => new \stdClass()];
    }

    protected function wpCliBaseCommand(WhmServer $server, object $connection, int $installId): string
    {
        return 'wp-toolkit --wp-cli -instance-id ' . $installId . ' -- --allow-root';
    }

    protected function runCommandWithExitCode(object $connection, string $command): array
    {
        $this->commands[] = $command;
        foreach ($this->responses as $needle => $response) {
            if (str_contains($command, $needle)) {
                return $response + ['raw_output' => '', 'lines' => []];
            }
        }

        return ['exit_code' => 1, 'clean_output' => 'Unexpected command.', 'raw_output' => '', 'lines' => []];
    }
}
