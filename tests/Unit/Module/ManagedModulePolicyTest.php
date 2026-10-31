<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Module;

use Gereq\Module\ManagedModulePolicy;
use Gereq\Testing\TestCase;
use Gereq\Tests\Support\PassthroughTranslator;

final class ManagedModulePolicyTest extends TestCase
{
    public function testManagedWebRequestsCannotMutateModules(): void
    {
        $policy = new ManagedModulePolicy(['cms'], true);
        $this->expectException(\RuntimeException::class);
        $policy->assertEnable('cms', new PassthroughTranslator());
    }

    public function testWorkerCannotEnableAModuleOutsideItsTrustedTarget(): void
    {
        $policy = new ManagedModulePolicy(['cms'], false);
        $policy->assertEnable('cms', new PassthroughTranslator());
        $this->expectException(\RuntimeException::class);
        $policy->assertEnable('analytics', new PassthroughTranslator());
    }

    public function testUnmanagedPlatformsAreUnrestrictedAndManagedBootFiltersDrift(): void
    {
        $policy = new ManagedModulePolicy(null, true);
        $policy->assertEnable('shop', new PassthroughTranslator());
        $policy->assertMutation(new PassthroughTranslator());
        self::assertSame(['cms', 'analytics'], $policy->filterEnabled(['cms', 'analytics']));
        self::assertSame(['cms'], (new ManagedModulePolicy(['cms'], false))->filterEnabled(['cms', 'analytics']));
    }

    public function testMalformedEnvironmentFailsClosed(): void
    {
        $previous = $_ENV['GEREQ_PACKAGE_MODULES'] ?? null;
        $_ENV['GEREQ_PACKAGE_MODULES'] = '';
        try {
            $this->expectException(\RuntimeException::class);
            ManagedModulePolicy::fromEnvironment();
        } finally {
            if ($previous === null) {
                unset($_ENV['GEREQ_PACKAGE_MODULES']);
            } else {
                $_ENV['GEREQ_PACKAGE_MODULES'] = $previous;
            }
        }
    }
}
