<?php

namespace HexaPackageSmokeTests\LaravelHexaPackageWpToolkit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WpToolkitRouteSecurityTest extends TestCase
{
    public function test_every_wp_toolkit_route_requires_two_factor_verification(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with((string) $route->getName(), 'wptoolkit.'));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->assertContains('two_factor', $route->gatherMiddleware(), (string) $route->getName());
        }
    }
}
