<?php

namespace hexa_package_wptoolkit\Services\Concerns;

use hexa_core\Services\CredentialService;
use hexa_package_browser_worker\Contracts\BrowserWorkerBridgeContract;
use hexa_package_browser_worker\Domains\Config\BrowserWorkerConfigRepository;

/**
 * Resolves and binds protected authentication for WordPress sites that are not
 * represented by a managed WP Toolkit installation.
 *
 * Credential values enter only through bindExternalSiteAuthentication(), are
 * stored through Hexa Core CredentialService, and are never returned or logged.
 * Browser profiles remain owned by the Browser Worker package.
 */
trait ManagesExternalAuthentication
{
    /**
     * Bind one exact external WordPress credential pair to an existing trusted
     * Browser Worker profile. Repeating the same binding is a no-op.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function bindExternalSiteAuthentication(array $request): array
    {
        $allowed = [
            'canonical_origin',
            'wp_admin_url',
            'profile_id',
            'username',
            'password',
            'mfa_otp_requirement',
            'credential_binding_authorized',
        ];
        if (array_diff(array_keys($request), $allowed) !== []) {
            return $this->externalAuthenticationFailure(
                'The protected binding request contains unsupported fields.',
                'bind-external-site-authentication',
            );
        }
        if (($request['credential_binding_authorized'] ?? false) !== true) {
            return $this->externalAuthenticationFailure(
                'Explicit protected credential-binding authorization is required.',
                'bind-external-site-authentication',
            );
        }

        $username = $request['username'] ?? null;
        $password = $request['password'] ?? null;
        if (! is_string($username) || trim($username) === '' || ! is_string($password) || $password === '') {
            return $this->externalAuthenticationFailure(
                'Both required credential fields must be present in the protected request.',
                'bind-external-site-authentication',
            );
        }
        if (strlen($username) > 320 || strlen($password) > 4096) {
            return $this->externalAuthenticationFailure(
                'A protected credential field exceeds its safe input limit.',
                'bind-external-site-authentication',
            );
        }

        $mfa = $request['mfa_otp_requirement'] ?? 'unknown';
        if (! in_array($mfa, ['present', 'absent', 'unknown'], true)) {
            return $this->externalAuthenticationFailure(
                'MFA/OTP requirement must be present, absent, or unknown.',
                'bind-external-site-authentication',
            );
        }

        $target = $this->normalizeExternalAuthenticationTarget($request);
        if (! ($target['success'] ?? false)) {
            return $this->externalAuthenticationFailure(
                (string) ($target['blocker'] ?? 'The external authentication target is invalid.'),
                'bind-external-site-authentication',
            );
        }
        $profileId = $request['profile_id'] ?? null;
        if (! is_string($profileId) || preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $profileId) !== 1) {
            return $this->externalAuthenticationFailure(
                'An exact canonical Browser Worker profile id is required.',
                'bind-external-site-authentication',
            );
        }

        $profile = $this->resolveExternalBrowserProfile(
            (string) $target['canonical_origin'],
            (string) $target['wp_admin_url'],
            $profileId,
        );
        if (($profile['status'] ?? '') !== 'mapped') {
            $report = $this->externalAuthenticationReport($target, $profile, null, false, false);
            $report['success'] = false;
            $report['operation'] = 'bind-external-site-authentication';

            return $report;
        }

        try {
            $payload = json_encode([
                'schema_version' => 1,
                'canonical_origin' => $target['canonical_origin'],
                'wp_admin_url' => $target['wp_admin_url'],
                'username' => $username,
                'password' => $password,
                'mfa_otp_requirement' => $mfa,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $credentials = $this->externalCredentialService();
            $slug = $this->externalCredentialSlug($profileId);
            $existing = $credentials->get($slug, 'account');
            $changed = ! is_string($existing) || ! hash_equals($existing, $payload);
            if ($changed) {
                $credentials->store($slug, 'account', $payload);
            }
        } catch (\Throwable) {
            return $this->externalAuthenticationFailure(
                'The protected credential binding could not be stored.',
                'bind-external-site-authentication',
            );
        }

        $report = $this->externalAuthenticationDisposition([
            'canonical_origin' => $target['canonical_origin'],
            'wp_admin_url' => $target['wp_admin_url'],
            'profile_id' => $profileId,
        ]);
        $report['operation'] = 'bind-external-site-authentication';
        $report['changed'] = $changed;

        return $report;
    }

    /**
     * Return the safe authentication disposition for one exact external site.
     * This method never authenticates and never returns credential values.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function externalAuthenticationDisposition(array $request): array
    {
        $allowed = ['canonical_origin', 'wp_admin_url', 'profile_id'];
        if (array_diff(array_keys($request), $allowed) !== []) {
            return $this->externalAuthenticationFailure('The authentication-disposition request contains unsupported fields.');
        }

        $target = $this->normalizeExternalAuthenticationTarget($request);
        if (! ($target['success'] ?? false)) {
            return $this->externalAuthenticationFailure((string) ($target['blocker'] ?? 'The external authentication target is invalid.'));
        }

        $profileId = $request['profile_id'] ?? null;
        if ($profileId !== null && (! is_string($profileId) || preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $profileId) !== 1)) {
            return $this->externalAuthenticationFailure('The Browser Worker profile id is invalid.');
        }
        $profile = $this->resolveExternalBrowserProfile(
            (string) $target['canonical_origin'],
            (string) $target['wp_admin_url'],
            is_string($profileId) ? $profileId : null,
        );
        if (($profile['status'] ?? '') !== 'mapped') {
            return $this->externalAuthenticationReport($target, $profile, null, false, false);
        }

        $stored = $this->externalStoredAuthentication((string) $profile['profile_id']);

        $usernamePresent = is_string($stored['username'] ?? null) && trim((string) $stored['username']) !== '';
        $passwordPresent = is_string($stored['password'] ?? null) && (string) $stored['password'] !== '';
        $bindingConfirmed = is_array($stored)
            && ($stored['schema_version'] ?? null) === 1
            && hash_equals((string) $target['canonical_origin'], (string) ($stored['canonical_origin'] ?? ''))
            && hash_equals((string) $target['wp_admin_url'], (string) ($stored['wp_admin_url'] ?? ''));

        return $this->externalAuthenticationReport(
            $target,
            $profile,
            $stored,
            $usernamePresent,
            $passwordPresent,
            $bindingConfirmed,
        );
    }

    /**
     * Authenticate one exact external WordPress site through its protected
     * credential binding without returning credential values.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function authenticateExternalSite(array $request): array
    {
        $report = $this->externalAuthenticationDisposition($request);
        $report['operation'] = 'authenticate-external-site';
        if (($report['login_credentials']['protected_handoff_ready'] ?? false) !== true) {
            $report['success'] = false;

            return $report;
        }

        $profileId = $report['site_account_data']['protected_browser_profile']['profile_id'] ?? null;
        if (! is_string($profileId) || $profileId === '') {
            return $this->externalAuthenticationFailure(
                'The protected Browser Worker profile could not be resolved.',
                'authenticate-external-site',
            );
        }
        $stored = $this->externalStoredAuthentication($profileId);
        if (! is_array($stored)
            || ! is_string($stored['username'] ?? null)
            || trim((string) $stored['username']) === ''
            || ! is_string($stored['password'] ?? null)
            || (string) $stored['password'] === '') {
            return $this->externalAuthenticationFailure(
                'The protected credential binding is no longer complete.',
                'authenticate-external-site',
            );
        }

        $bridge = $this->externalBrowserWorkerBridge();
        $status = $bridge->status($profileId);
        $state = is_array($status['data'] ?? null) ? $status['data'] : [];
        if (($status['success'] ?? false) === true
            && ($state['profile'] ?? null) === $profileId
            && ($state['auth_verified'] ?? false) === true
            && ($state['state'] ?? null) === 'AGENT_CONTROL') {
            return $this->externalSuccessfulAuthenticationReport($report, $state);
        }

        $lease = is_array($state['lease'] ?? null) ? $state['lease'] : [];
        $humanOwnerId = $lease['owner_id'] ?? null;
        if (($state['state'] ?? null) !== 'HUMAN_CONTROL'
            || ($lease['owner_type'] ?? null) !== 'human'
            || ! is_string($humanOwnerId)
            || preg_match('/^[A-Za-z0-9._:@-]{1,128}$/', $humanOwnerId) !== 1) {
            $report['success'] = false;
            $report['login_credentials']['validation_status'] = 'blocked';
            $report['login_credentials']['blocker'] = 'The exact protected browser profile is not ready for an automatic login transition.';
            $report['login_credentials']['next_action'] = 'Restore the exact profile ownership state, then retry protected authentication.';
            $report['login_credentials']['approval_boundary'] = null;

            return $report;
        }

        $result = $bridge->protectedLoginProfile(
            $profileId,
            $humanOwnerId,
            'wp-toolkit-protected-login',
            (string) $report['site_account_data']['wp_admin_url'],
            (string) $stored['username'],
            (string) $stored['password'],
            ['Username or Email Address', 'Username'],
            ['Password'],
            ['Log In', 'Log in'],
            ['Log in with username and password'],
        );
        $resultState = is_array($result['data'] ?? null) ? $result['data'] : [];
        $resultLease = is_array($resultState['lease'] ?? null) ? $resultState['lease'] : [];
        if (($result['success'] ?? false) !== true
            || ($resultState['profile'] ?? null) !== $profileId
            || ($resultState['auth_verified'] ?? false) !== true
            || ($resultState['state'] ?? null) !== 'AGENT_CONTROL'
            || ($resultLease['owner_type'] ?? null) !== 'agent'
            || ($resultLease['owner_id'] ?? null) !== 'wp-toolkit-protected-login') {
            $report['success'] = false;
            $report['login_credentials']['validation_status'] = 'blocked';
            $report['login_credentials']['blocker'] = 'Protected browser login did not produce verified authenticated evidence.';
            $report['login_credentials']['next_action'] = 'Inspect the preserved exact-profile authentication state before retrying.';
            $report['login_credentials']['approval_boundary'] = null;

            return $report;
        }

        return $this->externalSuccessfulAuthenticationReport($report, $resultState);
    }

    /** @return object */
    protected function externalCredentialService()
    {
        return app(CredentialService::class);
    }

    /** @return object */
    protected function externalBrowserProfileRepository()
    {
        return app(BrowserWorkerConfigRepository::class);
    }

    /** @return object */
    protected function externalBrowserWorkerBridge()
    {
        return app(BrowserWorkerBridgeContract::class);
    }

    /** @return array<string, mixed>|null */
    protected function externalStoredAuthentication(string $profileId): ?array
    {
        try {
            $raw = $this->externalCredentialService()->get(
                $this->externalCredentialSlug($profileId),
                'account',
            );
            if (! is_string($raw) || $raw === '') {
                return null;
            }
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $report @param array<string, mixed> $state @return array<string, mixed> */
    protected function externalSuccessfulAuthenticationReport(array $report, array $state): array
    {
        $checkpoint = is_array($state['checkpoint'] ?? null) ? $state['checkpoint'] : [];
        $checkedAt = $checkpoint['auth_checked_at'] ?? $state['auth_checked_at'] ?? null;
        $report['success'] = true;
        $report['login_credentials']['validation_status'] = 'valid';
        $report['login_credentials']['last_validated_utc'] = is_string($checkedAt) ? $checkedAt : now()->toIso8601String();
        $report['login_credentials']['blocker'] = null;
        $report['login_credentials']['next_action'] = 'Continue the authorized WordPress operation.';
        $report['login_credentials']['approval_boundary'] = null;
        $report['site_account_data']['authentication_disposition'] = 'authenticated';
        $report['site_account_data']['browser_worker'] = [
            'operation' => 'protected-login',
            'state' => 'AGENT_CONTROL',
            'auth_verified' => true,
            'worker_package_version' => is_string($state['worker_package_version'] ?? null)
                ? $state['worker_package_version']
                : null,
        ];

        return $report;
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    protected function normalizeExternalAuthenticationTarget(array $request): array
    {
        $origin = $this->normalizeExternalOrigin($request['canonical_origin'] ?? null);
        $admin = $this->normalizeExternalAdminUrl($request['wp_admin_url'] ?? null);
        if ($origin === null || $admin === null) {
            return ['success' => false, 'blocker' => 'Canonical origin and WP Admin URL must be safe public HTTPS URLs.'];
        }
        if (! str_starts_with($admin, rtrim($origin, '/').'/')) {
            return ['success' => false, 'blocker' => 'The WP Admin URL must use the exact canonical origin.'];
        }

        return [
            'success' => true,
            'canonical_origin' => $origin,
            'wp_admin_url' => $admin,
            'host' => (string) parse_url($origin, PHP_URL_HOST),
        ];
    }

    protected function normalizeExternalOrigin(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 2048) {
            return null;
        }
        $parts = parse_url(trim($value));
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array((string) ($parts['path'] ?? ''), ['', '/'], true)) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        if (! $this->isPublicExternalHost($host)) {
            return null;
        }
        $port = isset($parts['port']) && (int) $parts['port'] !== 443 ? ':'.(int) $parts['port'] : '';

        return 'https://'.$host.$port.'/';
    }

    protected function normalizeExternalAdminUrl(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 2048) {
            return null;
        }
        $parts = parse_url(trim($value));
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');
        if (! $this->isPublicExternalHost($host)
            || $path === '/'
            || preg_match('#/(?:token|secret|password|cookie|session|recovery-code)(?:/|$)#i', $path)) {
            return null;
        }
        $port = isset($parts['port']) && (int) $parts['port'] !== 443 ? ':'.(int) $parts['port'] : '';

        return 'https://'.$host.$port.rtrim($path, '/');
    }

    protected function isPublicExternalHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.local') || ! str_contains($host, '.')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/', $host) === 1;
    }

    /** @return array<string, mixed> */
    protected function resolveExternalBrowserProfile(string $origin, string $adminUrl, ?string $profileId): array
    {
        try {
            $accounts = $this->externalBrowserProfileRepository()->accounts();
        } catch (\Throwable) {
            return ['status' => 'unmapped', 'blocker' => 'Protected Browser Worker profile metadata is unavailable.'];
        }
        if (! is_array($accounts)) {
            return ['status' => 'unmapped', 'blocker' => 'Protected Browser Worker profile metadata is unavailable.'];
        }

        $matches = [];
        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }
            $candidateId = (string) ($account['profile'] ?? $account['profile_id'] ?? '');
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $candidateId) !== 1) {
                continue;
            }
            if ($profileId !== null && ! hash_equals($profileId, $candidateId)) {
                continue;
            }
            $candidateOrigin = $this->normalizeExternalOrigin($account['site'] ?? null);
            $candidateAdmin = $this->normalizeExternalAdminUrl($account['login_url'] ?? null);
            if ($candidateOrigin === $origin && $candidateAdmin === $adminUrl) {
                $matches[] = [
                    'profile_id' => $candidateId,
                    'trusted' => ($account['trusted'] ?? false) === true,
                ];
            }
        }

        if ($matches === []) {
            return [
                'status' => $profileId === null ? 'unmapped' : 'mismatch',
                'blocker' => $profileId === null
                    ? 'No exact protected browser profile is mapped to the canonical origin and WP Admin URL.'
                    : 'The requested protected browser profile is not mapped to the canonical origin and WP Admin URL.',
            ];
        }
        if (count($matches) !== 1) {
            return ['status' => 'ambiguous', 'blocker' => 'Multiple protected browser profiles match the canonical origin and WP Admin URL.'];
        }
        if (! $matches[0]['trusted']) {
            return ['status' => 'untrusted', 'blocker' => 'The exact protected browser profile is not trusted for automation.'];
        }

        return ['status' => 'mapped', 'profile_id' => $matches[0]['profile_id'], 'trusted' => true];
    }

    protected function externalCredentialSlug(string $profileId): string
    {
        return 'browser-worker-login-'.$profileId;
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    protected function externalAuthenticationReport(
        array $target,
        array $profile,
        ?array $stored,
        bool $usernamePresent,
        bool $passwordPresent,
        bool $bindingConfirmed = false,
    ): array {
        $mapped = ($profile['status'] ?? '') === 'mapped';
        $ready = $mapped && $bindingConfirmed && $usernamePresent && $passwordPresent;
        $status = $ready ? 'untested' : 'blocked';
        $binding = ($profile['status'] ?? '') === 'ambiguous'
            ? 'ambiguous'
            : ($bindingConfirmed ? 'confirmed' : 'unconfirmed');
        $blocker = null;
        if (! $ready) {
            $blocker = (string) ($profile['blocker'] ?? '');
            if ($blocker === '' && (! $usernamePresent || ! $passwordPresent)) {
                $blocker = 'The protected credential binding does not contain both required fields.';
            }
            if ($blocker === '' && ! $bindingConfirmed) {
                $blocker = 'The protected credential binding does not match the canonical origin and WP Admin URL.';
            }
        }
        $profileId = $mapped ? (string) $profile['profile_id'] : null;
        $mfa = is_array($stored) && in_array($stored['mfa_otp_requirement'] ?? null, ['present', 'absent', 'unknown'], true)
            ? (string) $stored['mfa_otp_requirement']
            : 'unknown';

        return [
            'login_credentials' => [
                'schema_version' => 1,
                'system_account_label' => 'WordPress · '.(string) $target['host'],
                'login_admin_url' => (string) $target['wp_admin_url'],
                'username_present' => $usernamePresent,
                'password_present' => $passwordPresent,
                'mfa_otp_requirement' => $mfa,
                'other_required_fields' => [],
                'credential_to_target_binding' => $binding,
                'validation_status' => $status,
                'source' => [
                    'type' => 'other',
                    'reference' => $profileId === null
                        ? 'code-wp-toolkit:external-authentication'
                        : 'code-wp-toolkit:browser-profile:'.$profileId,
                ],
                'last_validated_utc' => null,
                'protected_handoff_ready' => $ready,
                'blocker' => $blocker,
                'next_action' => $ready
                    ? 'Attempt ordinary authentication automatically through the exact protected browser profile.'
                    : 'Resolve the reported profile or credential binding blocker before authentication.',
                'approval_boundary' => null,
            ],
            'site_account_data' => [
                'source_mode' => 'external',
                'canonical_site_url' => (string) $target['canonical_origin'],
                'wp_admin_url' => (string) $target['wp_admin_url'],
                'protected_browser_profile' => [
                    'status' => (string) ($profile['status'] ?? 'unmapped'),
                    'profile_id' => $profileId,
                    'trusted' => $mapped ? true : null,
                ],
                'authentication_disposition' => $ready ? 'ready_for_ordinary_login' : 'blocked',
            ],
            'success' => true,
            'operation' => 'external-authentication-disposition',
            'changed' => false,
        ];
    }

    /** @return array<string, mixed> */
    protected function externalAuthenticationFailure(
        string $blocker,
        string $operation = 'external-authentication-disposition',
    ): array {
        return [
            'login_credentials' => [
                'schema_version' => 1,
                'system_account_label' => 'WordPress external site',
                'login_admin_url' => null,
                'username_present' => false,
                'password_present' => false,
                'mfa_otp_requirement' => 'unknown',
                'other_required_fields' => [],
                'credential_to_target_binding' => 'unconfirmed',
                'validation_status' => 'blocked',
                'source' => ['type' => 'other', 'reference' => 'code-wp-toolkit:external-authentication'],
                'last_validated_utc' => null,
                'protected_handoff_ready' => false,
                'blocker' => $blocker,
                'next_action' => 'Correct the protected request before authentication.',
                'approval_boundary' => null,
            ],
            'site_account_data' => [
                'source_mode' => 'external',
                'canonical_site_url' => null,
                'wp_admin_url' => null,
                'protected_browser_profile' => ['status' => 'unmapped', 'profile_id' => null, 'trusted' => null],
                'authentication_disposition' => 'blocked',
            ],
            'success' => false,
            'operation' => $operation,
            'changed' => false,
        ];
    }
}
