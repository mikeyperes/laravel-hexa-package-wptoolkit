<?php

/**
 * WP Toolkit Package Configuration
 * =================================
 * All WP Toolkit settings and defaults.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Version
    |--------------------------------------------------------------------------
    */
    'version' => '3.0.8',

    /*
    |--------------------------------------------------------------------------
    | SSH Settings
    |--------------------------------------------------------------------------
    */
    'ssh' => [
        'timeout' => env('WPTOOLKIT_SSH_TIMEOUT', 30),
        'port' => env('WPTOOLKIT_SSH_PORT', 22),
    ],

    /*
    |--------------------------------------------------------------------------
    | Execution Mode
    |--------------------------------------------------------------------------
    |
    | auto  = use SSH by default, but switch to local execution when the app
    |         runs in production or the WHM hostname matches a declared local
    |         host.
    | ssh   = always use SSH.
    | local = always execute wp-toolkit locally.
    |
    */
    'execution' => [
        'mode' => env('WPTOOLKIT_EXECUTION_MODE', 'auto'),
        'force_local_in_production' => env('WPTOOLKIT_FORCE_LOCAL_IN_PRODUCTION', true),
        'local_hosts' => array_values(array_filter(array_map(
            static fn ($host) => trim((string) $host),
            explode(',', (string) env('WPTOOLKIT_LOCAL_HOSTS', ''))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | WP Toolkit CLI
    |--------------------------------------------------------------------------
    */
    'cli' => [
        // Path to wp-toolkit binary on server (auto-detected if null)
        'binary_path' => env('WPTOOLKIT_BINARY_PATH', null),
    ],

];
