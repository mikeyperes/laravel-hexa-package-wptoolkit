<?php

namespace Tests\Unit;

use hexa_package_wptoolkit\Services\Concerns\EvaluatesWpCliCode;
use hexa_package_wptoolkit\Support\PersistentState;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for BUGLOG.md JOURNALIST-BUG-001: cached native wp-cli
 * commands are dropped only when they cannot start WordPress at all.
 */
class NativeWpCliCacheTest extends TestCase
{
    public function test_only_launch_failures_invalidate_the_cached_command(): void
    {
        $harness = new NativeWpCliCacheHarness();

        $this->assertTrue($harness->missing(127, 'sh: /usr/local/bin/wp: not found'));
        $this->assertTrue($harness->missing(126, 'permission denied'));
        $this->assertTrue($harness->missing(1, 'Error: This does not seem to be a WordPress installation.'));
        $this->assertTrue($harness->missing(1, 'sudo: unknown user zach'));

        $this->assertFalse($harness->missing(1, 'Error: Invalid user ID, email or login: 99'));
        $this->assertFalse($harness->missing(255, 'PHP Fatal error: Allowed memory size exhausted'));
        $this->assertFalse($harness->missing(0, 'Success: Updated user 7.'));
    }

    public function test_persistent_state_is_a_cache_miss_without_laravel(): void
    {
        PersistentState::put('wptoolkit:test', ['value' => 1], 60);

        $this->assertNull(PersistentState::get('wptoolkit:test'));
        PersistentState::forget('wptoolkit:test');
        $this->addToAssertionCount(1);
    }
}

class NativeWpCliCacheHarness
{
    use EvaluatesWpCliCode;

    public function missing(int $exitCode, string $output): bool
    {
        return $this->nativeWpCliTargetMissing($exitCode, $output);
    }
}
