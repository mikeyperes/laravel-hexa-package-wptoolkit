<?php

namespace hexa_package_wptoolkit\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WpToolkitCredentialSafetyContractTest extends TestCase
{
    public function test_reading_credentials_never_invokes_a_password_reset(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Services/Concerns/ManagesCredentials.php');
        $resetStart = strpos($source, 'public function resetWordPressPassword');
        $testStart = strpos($source, 'public function testWordPressPassword');
        self::assertIsInt($resetStart);
        self::assertIsInt($testStart);
        $resetMethod = substr($source, $resetStart, $testStart - $resetStart);

        self::assertStringNotContainsString('--site-admin-reset-password', $source);
        self::assertStringNotContainsString("'command' =>", $resetMethod);
        self::assertStringNotContainsString("'raw_output' => \$output", $source);
        self::assertStringNotContainsString("'debug_stored_creds'", $source);
        self::assertStringContainsString('function testWordPressPassword', $source);
    }

    public function test_install_scoped_controller_uses_server_derived_targets(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Http/Controllers/WpToolkitDashboardController.php');

        self::assertStringContainsString('authorizeInstallRequest', $source);
        self::assertStringNotContainsString("\$request->input('wp_path')", $source);
        self::assertStringNotContainsString("\$request->input('site_url')", $source);
        self::assertStringContainsString("unset(\$result['raw_output'], \$result['debug_stored_creds'])", $source);
        self::assertStringContainsString("unset(\$result['db_credentials'])", $source);
    }
}
