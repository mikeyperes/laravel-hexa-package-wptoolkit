<?php

namespace hexa_package_wptoolkit\Providers;

use Illuminate\Support\ServiceProvider;
use hexa_package_wptoolkit\Services\WpToolkitService;
use hexa_core\Services\PackageRegistryService;
use hexa_core\Support\PackageAssetRegistry;

/**
 * Registers the WP Toolkit package: config, routes, views, services.
 */
class WpToolkitServiceProvider extends ServiceProvider
{
    /**
     * Register package services and config.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/wptoolkit.php', 'wptoolkit');

        $this->app->singleton(WpToolkitService::class);
    }

    /**
     * Bootstrap package routes, views, and migrations.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../../routes/wptoolkit.php');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'wptoolkit');
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        app(PackageAssetRegistry::class)->register('wptoolkit', dirname(__DIR__, 2) . '/resources/js', [
            'account-wordpress.js',
            'dashboard.js',
            'raw.js',
        ]);

        // Publish config
        $this->publishes([
            __DIR__ . '/../../config/wptoolkit.php' => config_path('wptoolkit.php'),
        ], 'wptoolkit-config');

        // Sidebar links — package-owned and auto-wired into the core registry.
        $registry = app(PackageRegistryService::class);
        if (method_exists($registry, 'registerPackage')) {
            $registry->registerPackage('wptoolkit', 'hexawebsystems/laravel-hexa-package-wptoolkit', [
            'title' => 'WP Toolkit',
            'color' => 'indigo',
            'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0',
            'description' => 'WordPress Toolkit automation helpers and raw diagnostics for package-level testing.',
            'settingsRoute' => 'wptoolkit.index',
            'settingsShellClass' => 'max-w-4xl',
            'docsSlug' => 'wptoolkit',
            'instructions' => [
                'Use the Labs raw page to test WordPress install operations and credentials.',
            ],
            ]);
        }
    
        // Documentation
        if (class_exists(\hexa_core\Services\DocumentationService::class)) {
            app(\hexa_core\Services\DocumentationService::class)->register('wptoolkit', 'WP Toolkit', 'hexawebsystems/laravel-hexa-package-wptoolkit', [
                ['title' => 'Overview', 'content' => '<p>WordPress Toolkit integration. Manage WP installs, credentials, auto-login, password reset via wp-toolkit CLI.</p>'],
            ]);
        }
    }
}
