<?php

namespace Tests\Unit;

use hexa_core\Services\GenericService;
use hexa_package_whm\Models\WhmServer;
use hexa_package_whm\Services\WhmService;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Tests\TestCase;

class WpToolkitConnectionModeResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->requireInstalledPackage(
            'hexawebsystems/laravel-hexa-package-wptoolkit',
            WpToolkitService::class
        );

        config()->set('wptoolkit.execution.mode', 'auto');
        config()->set('wptoolkit.execution.force_local_in_production', false);
        config()->set('wptoolkit.execution.local_hosts', ['51.81.93.236']);
    }

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(static fn () => 'testing');

        parent::tearDown();
    }

    public function test_explicit_local_mode_has_highest_precedence(): void
    {
        config()->set('wptoolkit.execution.mode', 'local');

        $this->assertSame(
            'local',
            $this->service('web-user')->connectionMode($this->server('51.81.93.236'))
        );
    }

    public function test_explicit_ssh_mode_has_highest_precedence(): void
    {
        config()->set('wptoolkit.execution.mode', 'ssh');

        $this->assertSame(
            'ssh',
            $this->service('root')->connectionMode($this->server('51.81.93.236'))
        );
    }

    public function test_auto_mode_honors_production_force_local_setting(): void
    {
        config()->set('wptoolkit.execution.force_local_in_production', true);
        $this->app->detectEnvironment(static fn () => 'production');

        $this->assertSame(
            'local',
            $this->service('web-user')->connectionMode($this->server('203.0.113.20'))
        );
    }

    public function test_auto_mode_uses_runtime_safe_same_host_transport(): void
    {
        $server = $this->server('51.81.93.236');

        $this->assertSame('local', $this->service('root')->connectionMode($server));
        $this->assertSame('ssh', $this->service('web-user')->connectionMode($server));
    }

    public function test_auto_mode_uses_ssh_for_remote_targets(): void
    {
        $this->assertSame(
            'ssh',
            $this->service('root')->connectionMode($this->server('203.0.113.20'))
        );
    }

    private function service(string $runtimeUser): WpToolkitService
    {
        return new class(
            app(GenericService::class),
            app(WhmService::class),
            $runtimeUser
        ) extends WpToolkitService {
            public function __construct(
                GenericService $generic,
                WhmService $whm,
                private readonly string $runtimeUser
            ) {
                parent::__construct($generic, $whm);
            }

            protected function probeLocalRuntime(): array
            {
                return ["usable" => $this->runtimeUser === "root"];
            }
            protected function currentRuntimeUser(): string
            {
                return $this->runtimeUser;
            }

            protected function settingValue(string $key, mixed $default = null): mixed
            {
                return $default;
            }
        };
    }

    private function server(string $hostname): WhmServer
    {
        $server = new WhmServer();
        $server->id = 236;
        $server->hostname = $hostname;

        return $server;
    }
}
