<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Returns;

use Modules\Returns\Domain\ReturnRequest;
use PHPUnit\Framework\TestCase;

final class ReturnRequestTest extends TestCase
{
    public function testStatusAndResolution(): void
    {
        $request = new ReturnRequest('RMA-1', 'ord-1', 'Poškozené');
        self::assertSame('requested', $request->status());

        $request->setStatus('approved');
        $request->setResolution('refund');
        self::assertSame('approved', $request->status());
        self::assertSame('refund', $request->resolution());

        $this->expectException(\InvalidArgumentException::class);
        $request->setStatus('shipped');
    }
}
