<?php

namespace Tests\Unit;

require_once dirname(__DIR__, 2).'/src/Services/Concerns/ManagesExternalAuthentication.php';

use hexa_package_wptoolkit\Services\Concerns\ManagesExternalAuthentication;
use PHPUnit\Framework\TestCase;

class ExternalWordPressAuthenticationTest extends TestCase
{
    public function test_binding_is_authorized_idempotent_and_presence_only(): void
    {
        $credentials = new ExternalAuthenticationCredentialStore;
        $profiles = new ExternalAuthenticationProfileStore([
            [
                'profile' => 'source-wordpress',
                'site' => 'https://source.example/',
                'login_url' => 'https://source.example/wp-admin/',
                'trusted' => true,
            ],
        ]);
        $service = new ExternalAuthenticationHarness($credentials, $profiles);
        $request = [
            'canonical_origin' => 'https://source.example/',
            'wp_admin_url' => 'https://source.example/wp-admin/',
            'profile_id' => 'source-wordpress',
            'username' => 'fixture-admin-account',
            'password' => 'fixture-password-value',
            'mfa_otp_requirement' => 'unknown',
            'credential_binding_authorized' => true,
        ];

        $first = $service->bindExternalSiteAuthentication($request);
        $second = $service->bindExternalSiteAuthentication($request);

        $this->assertTrue($first['success']);
        $this->assertTrue($first['changed']);
        $this->assertFalse($second['changed']);
        $this->assertSame(1, $credentials->writes);
        $this->assertTrue($first['login_credentials']['username_present']);
        $this->assertTrue($first['login_credentials']['password_present']);
        $this->assertTrue($first['login_credentials']['protected_handoff_ready']);
        $this->assertSame('confirmed', $first['login_credentials']['credential_to_target_binding']);
        $this->assertSame('untested', $first['login_credentials']['validation_status']);
        $this->assertSame('ready_for_ordinary_login', $first['site_account_data']['authentication_disposition']);
        $this->assertSame('source-wordpress', $first['site_account_data']['protected_browser_profile']['profile_id']);

        $safeOutput = json_encode($first, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('fixture-admin-account', $safeOutput);
        $this->assertStringNotContainsString('fixture-password-value', $safeOutput);
    }

    public function test_binding_requires_explicit_authorization_and_exact_trusted_profile(): void
    {
        $service = new ExternalAuthenticationHarness(
            new ExternalAuthenticationCredentialStore,
            new ExternalAuthenticationProfileStore([
                [
                    'profile' => 'source-wordpress',
                    'site' => 'https://source.example/',
                    'login_url' => 'https://source.example/wp-admin/',
                    'trusted' => false,
                ],
            ]),
        );
        $request = [
            'canonical_origin' => 'https://source.example/',
            'wp_admin_url' => 'https://source.example/wp-admin/',
            'profile_id' => 'source-wordpress',
            'username' => 'fixture-admin-account',
            'password' => 'fixture-password-value',
        ];

        $unauthorized = $service->bindExternalSiteAuthentication($request);
        $this->assertFalse($unauthorized['success']);
        $this->assertFalse($unauthorized['login_credentials']['protected_handoff_ready']);

        $request['credential_binding_authorized'] = true;
        $untrusted = $service->bindExternalSiteAuthentication($request);
        $this->assertFalse($untrusted['success']);
        $this->assertFalse($untrusted['changed']);
        $this->assertSame('bind-external-site-authentication', $untrusted['operation']);
        $this->assertSame('untrusted', $untrusted['site_account_data']['protected_browser_profile']['status']);
        $this->assertSame('blocked', $untrusted['site_account_data']['authentication_disposition']);
    }

    public function test_disposition_blocks_unmapped_ambiguous_and_cross_origin_targets(): void
    {
        $profiles = new ExternalAuthenticationProfileStore([
            ['profile' => 'source-a', 'site' => 'https://source.example/', 'login_url' => 'https://source.example/wp-admin/', 'trusted' => true],
            ['profile' => 'source-b', 'site' => 'https://source.example/', 'login_url' => 'https://source.example/wp-admin/', 'trusted' => true],
        ]);
        $service = new ExternalAuthenticationHarness(new ExternalAuthenticationCredentialStore, $profiles);

        $ambiguous = $service->externalAuthenticationDisposition([
            'canonical_origin' => 'https://source.example/',
            'wp_admin_url' => 'https://source.example/wp-admin/',
        ]);
        $this->assertSame('ambiguous', $ambiguous['site_account_data']['protected_browser_profile']['status']);
        $this->assertSame('ambiguous', $ambiguous['login_credentials']['credential_to_target_binding']);
        $this->assertSame('blocked', $ambiguous['login_credentials']['validation_status']);

        $crossOrigin = $service->externalAuthenticationDisposition([
            'canonical_origin' => 'https://source.example/',
            'wp_admin_url' => 'https://other.example/wp-admin/',
        ]);
        $this->assertFalse($crossOrigin['success']);
        $this->assertNull($crossOrigin['login_credentials']['login_admin_url']);
        $this->assertSame('blocked', $crossOrigin['site_account_data']['authentication_disposition']);
    }
}

class ExternalAuthenticationHarness
{
    use ManagesExternalAuthentication;

    public function __construct(
        private ExternalAuthenticationCredentialStore $credentials,
        private ExternalAuthenticationProfileStore $profiles,
    ) {}

    protected function externalCredentialService(): object
    {
        return $this->credentials;
    }

    protected function externalBrowserProfileRepository(): object
    {
        return $this->profiles;
    }
}

class ExternalAuthenticationCredentialStore
{
    /** @var array<string, string> */
    public array $values = [];

    public int $writes = 0;

    public function get(string $slug, string $key): ?string
    {
        return $this->values[$slug.':'.$key] ?? null;
    }

    public function store(string $slug, string $key, string $value): void
    {
        $this->values[$slug.':'.$key] = $value;
        $this->writes++;
    }
}

class ExternalAuthenticationProfileStore
{
    /** @param array<int, array<string, mixed>> $accounts */
    public function __construct(private array $accounts) {}

    /** @return array<int, array<string, mixed>> */
    public function accounts(): array
    {
        return $this->accounts;
    }
}
