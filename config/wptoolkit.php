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
    'version' => '3.0.16',

    /*
    |--------------------------------------------------------------------------
    | SSH Settings
    |--------------------------------------------------------------------------
    */
    'ssh' => [
        'timeout' => env('WPTOOLKIT_SSH_TIMEOUT', 120),
        'port' => env('WPTOOLKIT_SSH_PORT', 22),
    ],

    /*
    |--------------------------------------------------------------------------
    | Execution Mode
    |--------------------------------------------------------------------------
    |
    | auto  = prefer local execution only when the target server matches a
    |         declared local host AND the current runtime user can actually
    |         execute the WP Toolkit binary. Otherwise fall back to SSH.
    | ssh   = always use SSH.
    | local = always execute wp-toolkit locally.
    |
    */
    'execution' => [
        'mode' => env('WPTOOLKIT_EXECUTION_MODE', 'auto'),
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
        // Shared fallback path to wp-toolkit (used if local/remote-specific paths are empty)
        'binary_path' => env('WPTOOLKIT_BINARY_PATH', null),
        'local_binary_path' => env('WPTOOLKIT_LOCAL_BINARY_PATH', null),
        'remote_binary_path' => env('WPTOOLKIT_REMOTE_BINARY_PATH', null),
        'local_binary_candidates' => array_values(array_filter(array_map(
            static fn ($path) => trim((string) $path),
            explode(',', (string) env('WPTOOLKIT_LOCAL_BINARY_CANDIDATES', '/usr/local/bin/wp-toolkit,/usr/sbin/wp-toolkit,/opt/cpanel/wp-toolkit/bin/wp-toolkit'))
        ))),
        'remote_binary_candidates' => array_values(array_filter(array_map(
            static fn ($path) => trim((string) $path),
            explode(',', (string) env('WPTOOLKIT_REMOTE_BINARY_CANDIDATES', '/usr/local/bin/wp-toolkit,/usr/sbin/wp-toolkit,/opt/cpanel/wp-toolkit/bin/wp-toolkit'))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Diagnostics
    |--------------------------------------------------------------------------
    */
    'diagnostics' => [
        'probe_timeout' => env('WPTOOLKIT_PROBE_TIMEOUT', 8),
    ],

];
