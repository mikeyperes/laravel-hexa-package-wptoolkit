<?php

namespace hexa_package_wptoolkit\Tests\Unit;

use hexa_package_wptoolkit\Contracts\AccountAccessPolicy;
use hexa_package_wptoolkit\Contracts\SiteCatalog;
use hexa_package_wptoolkit\Http\Controllers\WpToolkitDashboardController;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class WpToolkitApplicationContractsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('whm_servers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('hostname');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('hosting_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('whm_server_id');
            $table->string('username');
        });
        DB::table('whm_servers')->insert(['id' => 3, 'name' => 'Fixture', 'hostname' => 'server.example']);
        DB::table('hosting_accounts')->insert(['id' => 7, 'whm_server_id' => 3, 'username' => 'fixture']);
    }

    public function test_bound_policy_controls_account_access_before_any_remote_operation(): void
    {
        $user = $this->actor(false);
        $policy = Mockery::mock(AccountAccessPolicy::class);
        $policy->shouldReceive('canAccess')->twice()->with($user, 7)->andReturn(true, false);
        $this->app->instance(AccountAccessPolicy::class, $policy);
        $controller = new WpToolkitDashboardController(Mockery::mock(WpToolkitService::class));
        $method = new ReflectionMethod($controller, 'authorizeAccountRequest');
        $request = $this->request(['server_id' => 3, 'username' => 'fixture'], $user);
        self::assertSame(7, $method->invoke($controller, $request)->id);
        $this->expectException(HttpException::class);
        try {
            $method->invoke($controller, $request);
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
            throw $exception;
        }
    }

    public function test_unbound_policy_preserves_administrator_only_fallback(): void
    {
        unset($this->app[AccountAccessPolicy::class]);
        $controller = new WpToolkitDashboardController(Mockery::mock(WpToolkitService::class));
        $method = new ReflectionMethod($controller, 'authorizeAccountRequest');
        self::assertSame(7, $method->invoke($controller, $this->request(['server_id' => 3, 'username' => 'fixture'], $this->actor(true)))->id);
        foreach ([$this->actor(false), null] as $actor) {
            try {
                $method->invoke($controller, $this->request(['server_id' => 3, 'username' => 'fixture'], $actor));
                self::fail('Non-administrator access must fail closed.');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_dashboard_preserves_public_payload_and_optional_empty_catalog(): void
    {
        $catalog = Mockery::mock(SiteCatalog::class);
        $catalog->shouldReceive('sites')->once()->andReturn([['id' => 9, 'name' => 'Saved', 'url' => 'https://site.example', 'install_id' => 21, 'status' => 'active', 'last_error' => null, 'hosting_account_id' => 7]]);
        $this->app->instance(SiteCatalog::class, $catalog);
        $service = Mockery::mock(WpToolkitService::class);
        $service->shouldReceive('runtimeSettings')->twice()->andReturn([]);
        $controller = new WpToolkitDashboardController($service);
        $data = $controller->index($this->request([], $this->actor(true)))->getData();
        self::assertSame(21, $data['publishSitePayload']->first()['install_id']);
        self::assertArrayNotHasKey('hosting_account_id', $data['publishSitePayload']->first());
        unset($this->app[SiteCatalog::class]);
        self::assertSame([], $controller->index($this->request([], $this->actor(true)))->getData()['publishSitePayload']->all());
    }

    public function test_saved_site_command_uses_exact_adapter_target_with_mocked_toolkit(): void
    {
        $catalog = Mockery::mock(SiteCatalog::class);
        $catalog->shouldReceive('findOrFail')->once()->with(9)->andReturn(['id' => 9, 'name' => 'Saved', 'url' => 'https://site.example', 'hosting_account_id' => 7, 'install_id' => 21]);
        $this->app->instance(SiteCatalog::class, $catalog);
        $service = Mockery::mock(WpToolkitService::class);
        $service->shouldReceive('wpCliListAdminUsers')->once()->with(Mockery::on(fn ($server) => $server->id === 3), 21)->andReturn(['success' => true]);
        $service->shouldReceive('inspectCommandRuntime')->once()->andReturn(['fixture' => true]);
        $response = (new WpToolkitDashboardController($service))->siteCommandTest($this->request(['site_id' => 9, 'test' => 'authors'], $this->actor(true)));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(21, $response->getData(true)['site']['install_id']);
        self::assertTrue($response->getData(true)['success']);
    }

    public function test_missing_catalog_returns_safe_error_without_remote_work(): void
    {
        unset($this->app[SiteCatalog::class]);
        $controller = new WpToolkitDashboardController(Mockery::mock(WpToolkitService::class));
        $response = $controller->siteCommandTest($this->request(['site_id' => 9, 'test' => 'write'], $this->actor(true)));
        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($response->getData(true)['success']);
    }

    private function actor(bool $administrator): object
    {
        return new class($administrator) {
            public function __construct(private bool $administrator) {}
            public function isAdmin(): bool { return $this->administrator; }
        };
    }

    private function request(array $input, mixed $actor): Request
    {
        $request = Request::create('/fixture', 'POST', $input);
        $request->setUserResolver(fn () => $actor);

        return $request;
    }
}
