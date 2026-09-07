<?php

namespace hexa_package_wptoolkit\Tests\Unit;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\Concerns\ManagesCredentials;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use PHPUnit\Framework\TestCase;

final class WpToolkitCredentialOperationsTest extends TestCase
{
    public function test_explicit_reset_honors_the_requested_password_without_logging_it(): void
    {
        $connection = new CredentialFakeConnection(['Success: Updated user.']);
        $service = new CredentialOperationsHarness($connection);
        $server = $this->server();

        $result = $service->resetWordPressPassword(
            $server,
            42,
            '/home/client/public_html',
            'client',
            'editor',
            'user supplied secret',
        );

        self::assertTrue($result['success']);
        self::assertSame('user supplied secret', $result['password']);
        self::assertStringContainsString("--user_pass='user supplied secret'", $connection->commands[0]);
        self::assertStringNotContainsString('user supplied secret', json_encode($service->logger->entries, JSON_THROW_ON_ERROR));
    }

    public function test_password_check_returns_only_a_bounded_result(): void
    {
        $connection = new CredentialFakeConnection();
        $service = new CredentialOperationsHarness($connection, 1);

        $result = $service->testWordPressPassword(
            $this->server(),
            42,
            '/home/client/public_html',
            'client',
            'editor',
            'wrong secret',
        );

        self::assertFalse($result['success']);
        self::assertSame('WordPress rejected the password.', $result['error']);
        self::assertSame('error', $result['steps'][0]['status']);
        self::assertArrayNotHasKey('raw_output', $result);
        self::assertSame([], $service->logger->entries);
    }

    public function test_reading_an_encrypted_stored_password_has_no_reset_side_effect(): void
    {
        $connection = new CredentialFakeConnection([
            "login|administrator\npassword|\$aes-256-gcm\$encrypted\nadmin_email|admin@example.test",
            '{"siteUrl":"https://example.test","loginUrl":"https://example.test/wp-login.php"}',
            "define('DB_NAME', 'wordpress');",
        ]);
        $service = new CredentialOperationsHarness($connection);

        $result = $service->storedCredentials(
            $this->server(),
            $connection,
            42,
            '/home/client/public_html',
            'client',
        );

        self::assertSame('administrator', $result['credentials']['username']);
        self::assertNull($result['credentials']['password']);
        self::assertTrue($result['credentials']['password_encrypted']);
        self::assertFalse(collect($connection->commands)->contains(
            static fn (string $command): bool => str_contains($command, '--site-admin-reset-password'),
        ));
        self::assertArrayNotHasKey('raw', $result);
    }

    private function server(): WhmServer
    {
        $server = new WhmServer();
        $server->forceFill(['id' => 7, 'name' => 'Test server']);

        return $server;
    }
}

final class CredentialOperationsHarness
{
    use ManagesCredentials;

    public CredentialFakeLogger $logger;

    protected CredentialFakeLogger $generic;

    public function __construct(
        private readonly CredentialFakeConnection $connection,
        private readonly int $exitCode = 0,
    ) {
        $this->logger = $this->generic = new CredentialFakeLogger();
    }

    public function storedCredentials(
        WhmServer $server,
        LocalShellConnection $connection,
        int $installId,
        string $wpPath,
        string $username,
    ): array {
        return $this->getStoredCredentials($server, $connection, $installId, $wpPath, $username);
    }

    protected function getConnection(WhmServer $server): array
    {
        return ['success' => true, 'connection' => $this->connection];
    }

    public function shellBinary(LocalShellConnection|null $connection = null, ?WhmServer $server = null): string
    {
        return '/usr/local/bin/wp-toolkit';
    }

    protected function runCommandWithExitCode(LocalShellConnection $connection, string $command): array
    {
        $connection->commands[] = $command;

        return ['exit_code' => $this->exitCode, 'clean_output' => ''];
    }

    protected function extractJsonObject(string $output): ?array
    {
        $decoded = json_decode($output, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function disconnectCachedConnection(?WhmServer $server = null, LocalShellConnection|null $connection = null): void
    {
        $connection?->disconnect();
    }
}

final class CredentialFakeConnection extends LocalShellConnection
{
    /** @var list<string> */
    public array $commands = [];

    /** @param list<string> $outputs */
    public function __construct(private array $outputs = [])
    {
        parent::__construct();
    }

    public function exec(string $command): string
    {
        $this->commands[] = $command;

        return array_shift($this->outputs) ?? '';
    }
}

final class CredentialFakeLogger
{
    /** @var list<array{level:string,message:string,context:array}> */
    public array $entries = [];

    public function log(string $level, string $message, array $context = []): void
    {
        $this->entries[] = compact('level', 'message', 'context');
    }
}
