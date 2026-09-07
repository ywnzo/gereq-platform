<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Vitality;

use Modules\Vitality\Application\VitalityMonitor;
use PHPUnit\Framework\TestCase;

final class VitalityMonitorTest extends TestCase
{
    public function testNormalizePathReplacesIds(): void
    {
        $monitor = (new \ReflectionClass(VitalityMonitor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(VitalityMonitor::class, 'normalizePath');

        self::assertSame(
            '/api/v1/customers/{id}/notes',
            $method->invoke($monitor, '/api/v1/customers/550e8400-e29b-41d4-a716-446655440000/notes'),
        );
        self::assertSame('/orders/{id}/lines', $method->invoke($monitor, '/orders/42/lines'));
    }

    public function testSumQueryMs(): void
    {
        $monitor = (new \ReflectionClass(VitalityMonitor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(VitalityMonitor::class, 'sumQueryMs');

        $sum = $method->invoke($monitor, [
            ['sql' => 'a', 'duration' => 1.5, 'origin' => 'orm'],
            ['sql' => 'b', 'duration' => 2.25, 'origin' => 'orm'],
        ]);
        self::assertSame(3.75, $sum);
    }
}
