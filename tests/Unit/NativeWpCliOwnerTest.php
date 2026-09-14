<?php

namespace Tests\Unit;

use hexa_package_wptoolkit\Services\Concerns\EvaluatesWpCliCode;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use PHPUnit\Framework\TestCase;

class NativeWpCliOwnerTest extends TestCase
{
    public function test_native_command_preserves_the_installation_owner_and_cli_interpreter(): void
    {
        $harness = new NativeWpCliOwnerHarness();
        $this->assertSame("sudo -n -u 'example' -- '/opt/php/bin/php' '/usr/local/bin/wp' --path='/home/example/public html'", $harness->command());

        $harness->uid = '1001';
        $this->assertSame("'/usr/local/bin/wp' --path='/home/example/public html'", $harness->command());

        $harness->uid = '0';
        $harness->owner = 'root';
        $this->assertStringEndsWith(' --allow-root', $harness->command());

        $harness->owner = 'UNKNOWN';
        $this->assertNull($harness->command());
        $harness->owner = 'example;touch /tmp/unsafe';
        $this->assertNull($harness->command());
        $harness->owner = 'example';
        $harness->phpBinary = '';
        $this->assertNull($harness->command());
        $harness->uid = '';
        $this->assertNull($harness->command());
    }
}

class NativeWpCliOwnerHarness
{
    use EvaluatesWpCliCode;

    public string $uid = '0';
    public string $owner = 'example';
    public string $phpBinary = '/opt/php/bin/php';

    public function command(): ?string
    {
        return $this->directWpCliCommand(new LocalShellConnection(), '/usr/local/bin/wp', '/home/example/public html');
    }

    protected function runCommandWithExitCode($connection, string $command): array
    {
        $output = match (true) {
            $command === 'id -u' => $this->uid,
            str_starts_with($command, 'stat -c %U -- ') => $this->owner,
            str_starts_with($command, 'php -r ') => $this->phpBinary,
            default => '',
        };

        return ['exit_code' => 0, 'clean_output' => $output, 'raw_output' => $output];
    }
}
