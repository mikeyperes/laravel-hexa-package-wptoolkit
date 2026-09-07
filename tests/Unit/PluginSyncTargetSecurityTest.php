<?php

namespace hexa_package_wptoolkit\Tests\Unit;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\Concerns\ManagesWpToolkitOperations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PluginSyncTargetSecurityTest extends TestCase
{
    public function test_unsafe_plugin_segments_are_rejected_before_connection(): void
    {
        foreach (['', '.', '..', '../other', '/plugin', 'plugin/', 'plugin/child', '-option', "plugin\0bad", 'plugin\\child'] as $slug) {
            $harness = new PluginSyncTargetHarness;
            $result = $harness->syncPluginFromGitHub(new WhmServer, 'fixtureacct', 'public_html', $slug, 'https://github.com/fixture/plugin');

            self::assertFalse($result['success'], $slug);
            self::assertSame(0, $harness->connections, $slug);
            self::assertSame([], $harness->commands, $slug);
        }
    }

    public function test_unsafe_account_paths_are_rejected_before_connection(): void
    {
        foreach (['', '.', '..', '../other', 'account/other', 'account\\other'] as $account) {
            $harness = new PluginSyncTargetHarness;
            $result = $harness->syncPluginFromGitHub(new WhmServer, $account, 'public_html', 'plugin', 'https://github.com/fixture/plugin');

            self::assertFalse($result['success']);
            self::assertSame(0, $harness->connections);
        }
    }

    public function test_legitimate_slug_keeps_a_single_plugin_target_and_backup(): void
    {
        $harness = new PluginSyncTargetHarness;
        $result = $harness->syncPluginFromGitHub(new WhmServer, 'fixtureacct', 'public_html', 'hexa.plugin-v2_0', 'https://github.com/fixture/plugin');

        self::assertTrue($result['success']);
        self::assertSame('/home/fixtureacct/public_html/wp-content/plugins/hexa.plugin-v2_0', $result['plugin_root']);
        self::assertStringStartsWith('/home/fixtureacct/_hexa-plugin-backups/', $result['backup_path']);
        self::assertStringContainsString('cp -a', $result['command']);
        self::assertLessThan(strpos($result['command'], 'rsync'), strpos($result['command'], 'readlink -f'));
    }

    public function test_read_only_physical_guard_rejects_symlinked_plugin_or_parent(): void
    {
        $harness = new PluginSyncTargetHarness;
        $result = $harness->syncPluginFromGitHub(new WhmServer, 'fixtureacct', 'public_html', 'plugin', 'https://github.com/fixture/plugin');
        // Execute only the read-only guard against disposable directories, never
        // the backup/clone/sync/activation command and never a real website.
        $guard = explode(' && mkdir -p ', $result['command'], 2)[0];
        $fixture = sys_get_temp_dir().'/hexa-plugin-guard-'.bin2hex(random_bytes(8));
        mkdir($fixture.'/wp-content/plugins', 0700, true);
        mkdir($fixture.'/outside', 0700);
        touch($fixture.'/wp-load.php');
        $guard = str_replace('/home/fixtureacct/public_html', $fixture, $guard);

        try {
            self::assertSame(0, (new Process(['sh', '-c', $guard]))->run());
            symlink($fixture.'/outside', $fixture.'/wp-content/plugins/plugin');
            self::assertNotSame(0, (new Process(['sh', '-c', $guard]))->run());
            unlink($fixture.'/wp-content/plugins/plugin');
            rmdir($fixture.'/wp-content/plugins');
            symlink($fixture.'/outside', $fixture.'/wp-content/plugins');
            self::assertNotSame(0, (new Process(['sh', '-c', $guard]))->run());
        } finally {
            if (is_link($fixture.'/wp-content/plugins/plugin')) {
                unlink($fixture.'/wp-content/plugins/plugin');
            }
            if (is_link($fixture.'/wp-content/plugins')) {
                unlink($fixture.'/wp-content/plugins');
            } elseif (is_dir($fixture.'/wp-content/plugins')) {
                rmdir($fixture.'/wp-content/plugins');
            }
            unlink($fixture.'/wp-load.php');
            rmdir($fixture.'/wp-content');
            rmdir($fixture.'/outside');
            rmdir($fixture);
        }
    }
}

final class PluginSyncTargetHarness
{
    use ManagesWpToolkitOperations;

    public int $connections = 0;
    public array $commands = [];

    protected function getConnection(WhmServer $server): array
    {
        $this->connections++;

        return ['success' => true, 'connection' => new \stdClass];
    }

    protected function commandTimeoutSeconds(): int { return 30; }

    protected function isCommandRefusalOutput(string $output): bool { return false; }

    protected function runCommandWithExitCode(object $connection, string $command): array
    {
        $this->commands[] = $command;

        return ['exit_code' => 0, 'clean_output' => '', 'raw_output' => ''];
    }
}
