<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Event\EventBus;
use PHPUnit\Framework\TestCase;

final class EventBusTest extends TestCase
{
    public function testDispatchInvokesTypedListener(): void
    {
        $bus = new EventBus();
        $seen = null;
        $event = new class ('abc') {
            public function __construct(public readonly string $id)
            {
            }
        };

        $bus->listen($event::class, static function (object $dispatched) use (&$seen): void {
            $seen = $dispatched->id;
        });

        $bus->dispatch($event);

        self::assertSame('abc', $seen);
    }

    public function testTracerReceivesEventDuration(): void
    {
        $bus = new EventBus();
        $seen = [];
        $event = new class {
        };

        $bus->setTracer(static function (string $class, float $ms) use (&$seen): void {
            $seen = ['class' => $class, 'ms' => $ms];
        });
        $bus->dispatch($event);

        self::assertSame($event::class, $seen['class']);
        self::assertIsFloat($seen['ms']);
        self::assertGreaterThanOrEqual(0, $seen['ms']);
    }
}
