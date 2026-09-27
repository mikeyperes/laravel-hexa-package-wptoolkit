<?php

namespace hexa_package_wptoolkit\Support;

use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Cross-request state for values that are expensive to rediscover on every
 * request (runtime probes, installation paths, resolved wp-cli commands).
 *
 * Without a Laravel cache (isolated unit tests, early boot) every call behaves
 * as a cache miss, so callers fall back to their normal resolution path.
 */
final class PersistentState
{
    public static function get(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function put(string $key, mixed $value, DateTimeInterface|int $ttl): void
    {
        try {
            Cache::put($key, $value, $ttl);
        } catch (\Throwable) {
            // Cross-request reuse is an optimisation only.
        }
    }

    public static function forget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (\Throwable) {
            // Nothing cached to forget.
        }
    }
}
