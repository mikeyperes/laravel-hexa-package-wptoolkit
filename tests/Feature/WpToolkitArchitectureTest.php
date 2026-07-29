<?php

namespace Tests\Feature;

use hexa_core\Support\PackageAssetRegistry;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Tests\TestCase;

class WpToolkitArchitectureTest extends TestCase
{
    public function test_service_api_survives_focused_concern_split(): void
    {
        foreach ([
            "getConnection",
            "connectionMode",
            "runtimeSettings",
            "inspectCommandRuntime",
            "getInstallInfo",
            "cloneInstallSameServer",
            "installWordpress",
            "removeInstall",
            "registerInstall",
            "getPluginStatus",
            "ensurePluginInstalledAndActive",
            "syncPluginFromGitHub",
            "wpCliRaw",
        ] as $method) {
            $this->assertTrue(method_exists(WpToolkitService::class, $method), $method);
        }

        $root = dirname(__DIR__, 2);
        foreach ([
            "src/Services/WpToolkitService.php",
            "src/Services/Concerns/ManagesWpToolkitConnections.php",
            "src/Services/Concerns/ResolvesWpToolkitRuntime.php",
            "src/Services/Concerns/ProbesWpToolkitRuntime.php",
            "src/Services/Concerns/ManagesWpToolkitOperations.php",
            "src/Services/Concerns/RunsWpToolkitCommands.php",
        ] as $path) {
            $this->assertLessThan(700, count(file($root . "/" . $path, FILE_IGNORE_NEW_LINES)), $path);
        }
    }

    public function test_wp_cli_api_survives_focused_concern_split(): void
    {
        foreach ([
            "wpCliCreatePost",
            "wpCliUpdatePost",
            "wpCliGetPost",
            "wpCliUploadMedia",
            "wpCliCreateCategory",
            "wpCliCreateTag",
            "wpCliListAdminUsers",
            "wpCliListCategories",
            "wpCliResolvePreferredTaxonomy",
            "wpCliListTaxonomyTerms",
            "wpCliSetPostTerms",
            "wpCliEval",
            "wpCliEvalWithPlugins",
        ] as $method) {
            $this->assertTrue(method_exists(WpToolkitService::class, $method), $method);
        }

        $root = dirname(__DIR__, 2);
        foreach ([
            "src/Services/Concerns/ManagesWpCli.php",
            "src/Services/Concerns/ManagesWpCliContent.php",
            "src/Services/Concerns/RunsWpCliCommands.php",
            "src/Services/Concerns/ManagesWpCliTaxonomies.php",
            "src/Services/Concerns/EvaluatesWpCliCode.php",
        ] as $path) {
            $this->assertLessThan(700, count(file($root . "/" . $path, FILE_IGNORE_NEW_LINES)), $path);
        }
    }

    public function test_frontend_assets_are_static_and_registered(): void
    {
        $assets = app(PackageAssetRegistry::class)->assetsFor("wptoolkit");

        foreach (["account-wordpress.js", "dashboard.js", "raw.js"] as $asset) {
            $this->assertArrayHasKey($asset, $assets);
            $this->assertFileExists($assets[$asset]);
            $this->assertDoesNotMatchRegularExpression(
                "/@json|@js|\{\{\s*(?:[A-Za-z_]|\x24)|\{!!|@(?:if|foreach|php|route)\b/",
                (string) file_get_contents($assets[$asset])
            );
        }
    }

    public function test_views_delegate_behavior_to_static_assets(): void
    {
        $root = dirname(__DIR__, 2);
        $dashboard = (string) file_get_contents($root . "/resources/views/dashboard/index.blade.php");
        $account = (string) file_get_contents($root . "/resources/views/partials/account-wordpress.blade.php");
        $raw = (string) file_get_contents($root . "/resources/views/raw/index.blade.php");

        $this->assertStringNotContainsString("@php", $dashboard);
        $this->assertStringNotContainsString("function wpToolkitSettingsPage", $dashboard);
        $this->assertStringNotContainsString("Alpine.data", $account);
        $this->assertStringNotContainsString("DOMContentLoaded", $raw);
        $this->assertStringContainsString("dashboard.js", $dashboard);
        $this->assertStringContainsString("account-wordpress.js", $account);
        $this->assertStringContainsString("raw.js", $raw);
    }

    public function test_account_partial_renders_safe_json_config(): void
    {
        $server = new \hexa_package_whm\Models\WhmServer();
        $server->forceFill(["id" => 101]);
        $account = new \hexa_package_whm\Models\HostingAccount();
        $account->forceFill(["id" => 202, "username" => "o'brien"]);

        $html = view("wptoolkit::partials.account-wordpress", compact("server", "account"))->render();

        $this->assertStringContainsString("wptoolkit-account-config-101-202", $html);
        $this->assertStringContainsString("wpToolkitConfig", $html);
        $this->assertStringContainsString("\\u0027", $html);
    }
}
