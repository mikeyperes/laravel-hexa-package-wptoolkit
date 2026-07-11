<?php

namespace HexaPackageSmokeTests\LaravelHexaPackageWpToolkit;

use hexa_core\Support\PackageAssetRegistry;
use Tests\TestCase;

class FrontendArchitectureTest extends TestCase
{
    public function test_frontend_workflows_are_static_and_allowlisted(): void
    {
        $root = dirname(__DIR__, 2);
        $assets = app(PackageAssetRegistry::class)->assetsFor("wptoolkit");

        foreach (["account-wordpress.js", "dashboard.js", "raw.js"] as $asset) {
            $this->assertArrayHasKey($asset, $assets);
            $this->assertFileExists($assets[$asset]);
            $content = (string) file_get_contents($assets[$asset]);
            $this->assertDoesNotMatchRegularExpression('/@json|\{\{|@(?:if|foreach|php|route)\b/', $content);
        }
    }

    public function test_views_reference_external_workflows(): void
    {
        $root = dirname(__DIR__, 2);
        $dashboard = (string) file_get_contents($root . "/resources/views/dashboard/index.blade.php");
        $account = (string) file_get_contents($root . "/resources/views/partials/account-wordpress.blade.php");
        $raw = (string) file_get_contents($root . "/resources/views/raw/index.blade.php");

        $this->assertStringContainsString("dashboard.js", $dashboard);
        $this->assertStringContainsString("account-wordpress.js", $account);
        $this->assertStringContainsString("raw.js", $raw);
        $this->assertStringNotContainsString("function wpToolkitSettingsPage", $dashboard);
        $this->assertStringNotContainsString("Alpine.data", $account);
        $this->assertStringNotContainsString("DOMContentLoaded", $raw);
    }
}
