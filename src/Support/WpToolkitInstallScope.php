<?php

namespace hexa_package_wptoolkit\Support;

final class WpToolkitInstallScope
{
    /**
     * Resolve an installation from an account-scoped WP Toolkit inventory.
     *
     * @param array<int, array<string, mixed>> $installs
     * @return array<string, mixed>|null
     */
    public static function resolve(array $installs, int $installId, string $username): ?array
    {
        if ($installId < 1) {
            return null;
        }

        $matches = array_values(array_filter(
            $installs,
            static fn (mixed $install): bool => is_array($install)
                && filter_var($install['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === $installId,
        ));

        if (count($matches) !== 1) {
            return null;
        }

        $install = $matches[0];
        $path = self::normalizePathForAccount($install['path'] ?? null, $username);
        if ($path === null) {
            return null;
        }

        $install['id'] = $installId;
        $install['path'] = $path;

        return $install;
    }

    public static function normalizePathForAccount(mixed $path, string $username): ?string
    {
        $path = is_string($path) ? trim($path) : '';
        $username = strtolower(trim($username));

        if (
            $path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $username) !== 1
            || ! str_starts_with($path, '/')
        ) {
            return null;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $segments[] = $segment;
        }

        $normalized = '/'.implode('/', $segments);
        $home = '/home/'.$username;

        return str_starts_with($normalized.'/', $home.'/') && $normalized !== $home
            ? $normalized
            : null;
    }
}
