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
            // [L1/L2 sidebar hierarchy — auto-registered]
            $registry->registerDomainGroup('AI', 'M13 10V3L4 14h7v7l9-11h-7z', 50);
            $registry->registerSectionGroup('Sandbox', 'AI', 'M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 9h-6L8 4z', 53);
            // [/L1/L2]
        // HWS-SIDEBAR-MENU-3L-BEGIN
        $registry->registerDomainGroup('Discovery', 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z', 20);
        $registry->registerSectionGroup('Sandbox', 'Discovery', '', 20);
        // HWS-SIDEBAR-MENU-3L-END

        $registry->registerSidebarLink('wptoolkit.index', 'WP Toolkit', 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z', 'Sandbox', 'wptoolkit', 88);
        if (method_exists($registry, 'registerPackage')) {
            $registry->registerPackage('wptoolkit', 'hexawebsystems/laravel-hexa-package-wptoolkit', [
            'title' => 'WP Toolkit',
            'color' => 'indigo',
            'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0',
            'description' => 'WordPress Toolkit sandbox and automation helpers for package-level testing.',
            'settingsRoute' => 'wptoolkit.index',
            'settingsShellClass' => 'max-w-4xl',
            'docsSlug' => 'wptoolkit',
            'instructions' => [
                'Use the WP Toolkit package page to test WordPress install operations and credentials.',
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
