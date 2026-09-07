<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Viewings;

use Modules\Viewings\Domain\Viewing;
use PHPUnit\Framework\TestCase;

final class ViewingTest extends TestCase
{
    public function testStatusTransitions(): void
    {
        $viewing = new Viewing('VW-1', 'listing-1', 'Pan Novák');
        self::assertSame('requested', $viewing->status());

        $viewing->setStatus('scheduled');
        $viewing->setStatus('confirmed');
        $viewing->setStatus('completed');
        self::assertSame('completed', $viewing->status());

        $this->expectException(\InvalidArgumentException::class);
        $viewing->setStatus('maybe');
    }
}
