<?php

namespace Tests\Unit;

use hexa_package_wptoolkit\Services\WpToolkitService;
use Tests\TestCase;

class WpToolkitServiceArchitectureTest extends TestCase
{
    public function test_toolkit_service_facade_and_runtime_concerns_remain_bounded(): void
    {
        foreach (['getConnection', 'runtimeSettings', 'getInstallInfo', 'wpCliRaw'] as $method) {
            $this->assertTrue(method_exists(WpToolkitService::class, $method), $method);
        }

        $root = dirname(__DIR__, 2);
        foreach ([
            'src/Services/WpToolkitService.php',
            'src/Services/Concerns/ManagesWpToolkitConnections.php',
            'src/Services/Concerns/InspectsWpToolkitRuntime.php',
            'src/Services/Concerns/ManagesWpToolkitInstances.php',
        ] as $relative) {
            $path = $root . '/' . $relative;
            $this->assertFileExists($path);
            $this->assertLessThan(700, count(file($path)), $relative);
        }
    }
}
