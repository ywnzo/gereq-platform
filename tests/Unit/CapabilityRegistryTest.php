<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Module\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class CapabilityRegistryTest extends TestCase
{
    public function testRegistersAndResolvesCapability(): void
    {
        $registry = new CapabilityRegistry();
        $provider = new \stdClass();
        $registry->register('contacts', 'customers', $provider);

        self::assertTrue($registry->has('contacts'));
        self::assertSame($provider, $registry->get('contacts'));
        self::assertSame(['customers'], $registry->providers('contacts'));
    }

    public function testMissingCapabilityThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CapabilityRegistry())->get('payments');
    }
}
