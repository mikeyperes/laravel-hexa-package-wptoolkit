<?php

namespace hexa_package_wptoolkit\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WpToolkitRouteSecurityTest extends TestCase
{
    public function test_authenticated_routes_require_two_factor_verification(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2).'/routes/wptoolkit.php');

        self::assertStringContainsString(
            "Route::middleware(['web', 'auth', 'locked', 'system_lock', 'two_factor', 'role'])",
            $routes,
        );
    }
}
