<?php

namespace hexa_package_wptoolkit\Tests\Unit;

use hexa_package_wptoolkit\Services\Concerns\ManagesWpCli;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ManagesWpCliContractTest extends TestCase
{
    public function test_timeout_dependency_is_an_explicit_trait_contract(): void
    {
        $method = new ReflectionMethod(ManagesWpCli::class, 'commandTimeoutSeconds');

        $this->assertTrue($method->isAbstract());
        $this->assertTrue($method->isPublic());
        $this->assertSame('int', (string) $method->getReturnType());
    }

    public function test_plugin_loaded_eval_is_part_of_the_public_wp_cli_contract(): void
    {
        $method = new ReflectionMethod(ManagesWpCli::class, 'wpCliEvalWithPlugins');

        $this->assertTrue($method->isPublic());
        $this->assertSame(4, $method->getNumberOfParameters());

        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Services/Concerns/WpCli/SupportsWpCliConnections.php');
        $this->assertIsString($source);
        $this->assertStringContainsString(' --allow-root eval "$CODE" 2>&1', $source);
        $this->assertStringContainsString('resolveDirectWpCliBinary', $source);
    }
}
