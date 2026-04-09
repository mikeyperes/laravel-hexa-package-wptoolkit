<?php

namespace hexa_package_wptoolkit\Providers;

use Illuminate\Support\ServiceProvider;
use hexa_package_wptoolkit\Services\WpToolkitService;
use hexa_core\Services\PackageRegistryService;

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

        // Publish config
        $this->publishes([
            __DIR__ . '/../../config/wptoolkit.php' => config_path('wptoolkit.php'),
        ], 'wptoolkit-config');

        // Sidebar links — package-owned and auto-wired into the core registry.
        $registry = app(PackageRegistryService::class);
        $registry->registerSidebarLink('wptoolkit.index', 'WP Toolkit', 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z', 'Sandbox', 'wptoolkit', 88);
        $registry->registerPackage('wptoolkit', 'hexawebsystems/laravel-hexa-package-wptoolkit', [
            'title' => 'WP Toolkit',
            'description' => 'WordPress Toolkit sandbox and automation helpers for package-level testing.',
            'settingsRoute' => 'wptoolkit.index',
            'docsSlug' => 'wptoolkit',
            'instructions' => [
                'Use the WP Toolkit package page to test WordPress install operations and credentials.',
            ],
        ]);
    
        // Documentation
        if (class_exists(\hexa_core\Services\DocumentationService::class)) {
            app(\hexa_core\Services\DocumentationService::class)->register('wptoolkit', 'WP Toolkit', 'hexawebsystems/laravel-hexa-package-wptoolkit', [
                ['title' => 'Overview', 'content' => '<p>WordPress Toolkit integration. Manage WP installs, credentials, auto-login, password reset via wp-toolkit CLI.</p>'],
            ]);
        }
    }
}
