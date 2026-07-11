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
}
