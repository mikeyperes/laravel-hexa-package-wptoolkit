<?php

namespace hexa_package_wptoolkit\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use hexa_package_wptoolkit\Services\WpToolkitService;

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

        // Inject sidebar into sandbox stack
        View::composer('layouts.app', function ($view) {
            $factory = $view->getFactory();
            if (auth()->check()) {
                try {
                    $factory->startPush('sidebar-sandbox',
                        view('wptoolkit::partials.sidebar-menu')->render());
                } catch (\Throwable $e) {}
            }
        });
    }
}
