<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Config\Repository;
use PHPUnit\Framework\TestCase;

final class ConfigRepositoryTest extends TestCase
{
    public function testNestedGetAndSet(): void
    {
        $config = new Repository(['app' => ['name' => 'GEREQ Platform']]);

        self::assertSame('GEREQ Platform', $config->get('app.name'));
        $config->set('app.debug', true);
        self::assertTrue($config->get('app.debug'));
        self::assertSame('fallback', $config->get('missing', 'fallback'));
    }
}
