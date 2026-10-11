<?php

namespace Tests\Unit;

use hexa_package_wptoolkit\Services\Concerns\ResolvesWpToolkitRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for BUGLOG.md WPTOOLKIT-BUG-003: words inside returned
 * site content never turn a successful command into a refusal.
 */
class CommandRefusalOutputTest extends TestCase
{
    public function test_refusal_and_crash_messages_are_detected(): void
    {
        $harness = new CommandRefusalOutputHarness();

        $this->assertTrue($harness->refused('permission denied'));
        $this->assertTrue($harness->refused('sh: /home/x/wp: Permission denied'));
        $this->assertTrue($harness->refused('Error: YIKES! It looks like you\'re running this as root. WP-CLI cannot be run as root.'));
        $this->assertTrue($harness->refused("HEXA_RANKMATH_INVENTORY:{\"pages\":[]}\nPHP Fatal error:  Allowed memory size exhausted"));
        $this->assertTrue($harness->refused('Fatal error: database global is unavailable'));
    }

    public function test_content_inside_a_returned_payload_is_not_a_refusal(): void
    {
        $harness = new CommandRefusalOutputHarness();
        $payload = 'HEXA_RANKMATH_INVENTORY:{"pages":[{"content_text":"Hackers met a fatal error and permission denied screens."}]}';

        $this->assertFalse($harness->refused($payload));
        $this->assertFalse($harness->refused('{"message":"You are not authorized to read this article"}'));
        $this->assertFalse($harness->refused('Success: Updated user 7.'));
        $this->assertFalse($harness->refused(''));
    }
}

class CommandRefusalOutputHarness
{
    use ResolvesWpToolkitRuntime;

    public function refused(string $output): bool
    {
        return $this->isCommandRefusalOutput($output);
    }
}
