<?php

namespace hexa_package_wptoolkit\Tests\Unit;

use hexa_package_wptoolkit\Support\WpToolkitInstallScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WpToolkitInstallScopeTest extends TestCase
{
    public function test_resolves_only_the_requested_install_inside_the_exact_account_home(): void
    {
        $install = WpToolkitInstallScope::resolve([
            ['id' => 41, 'path' => '/home/client2/public_html', 'url' => 'https://foreign.example'],
            ['id' => 42, 'path' => '/home/client/public_html/./news', 'url' => 'https://client.example'],
        ], 42, 'client');

        self::assertSame('/home/client/public_html/news', $install['path']);
        self::assertSame('https://client.example', $install['url']);
    }

    #[DataProvider('foreignOrUnsafePaths')]
    public function test_rejects_foreign_or_unsafe_install_paths(string $path): void
    {
        self::assertNull(WpToolkitInstallScope::resolve([
            ['id' => 42, 'path' => $path],
        ], 42, 'client'));
    }

    public static function foreignOrUnsafePaths(): array
    {
        return [
            'prefix collision' => ['/home/client2/public_html'],
            'parent traversal' => ['/home/client/../../root'],
            'home directory itself' => ['/home/client'],
            'relative path' => ['home/client/public_html'],
            'windows separators' => ['/home/client\\..\\root'],
            'nul byte' => ["/home/client/public_html\0/elsewhere"],
        ];
    }

    public function test_rejects_missing_duplicate_or_malformed_install_identifiers(): void
    {
        self::assertNull(WpToolkitInstallScope::resolve([], 42, 'client'));
        self::assertNull(WpToolkitInstallScope::resolve([['id' => null, 'path' => '/home/client/public_html']], 42, 'client'));
        self::assertNull(WpToolkitInstallScope::resolve([['id' => 0, 'path' => '/home/client/public_html']], 0, 'client'));
        self::assertNull(WpToolkitInstallScope::resolve([
            ['id' => 42, 'path' => '/home/client/public_html'],
            ['id' => 42, 'path' => '/home/client/other'],
        ], 42, 'client'));
        self::assertNull(WpToolkitInstallScope::resolve([['id' => 42, 'path' => '/home/client/public_html']], 42, '../client'));
    }
}
