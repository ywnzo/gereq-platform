<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Orders;

use Modules\Orders\Application\OrderTotals;
use Modules\Orders\Domain\Order;
use Modules\Orders\Domain\OrderLine;
use Modules\Orders\Domain\Invoice;
use PHPUnit\Framework\TestCase;

final class OrderTotalsTest extends TestCase
{
    public function testOrderLineCalculatesSubtotalTaxAndTotal(): void
    {
        $line = new OrderLine('ord-1', 'Produkt', '2', '100.00', '21');
        self::assertSame('200.00', $line->lineSubtotal());
        self::assertSame('42.00', $line->lineTax());
        self::assertSame('242.00', $line->lineTotal());
    }

    public function testRecalculateAppliesDiscount(): void
    {
        $order = new Order('cust-1', 'ORD-1');
        $lines = [
            new OrderLine($order->id(), 'A', '1', '100', '21'),
            new OrderLine($order->id(), 'B', '1', '50', '0'),
        ];

        OrderTotals::recalculate($order, $lines, '20');

        self::assertSame('150.00', $order->subtotal());
        self::assertSame('21.00', $order->taxTotal());
        self::assertSame('20.00', $order->discountTotal());
        self::assertSame('151.00', $order->total());
    }

    public function testOrderEditOnlyInDraft(): void
    {
        $order = new Order('cust-1', 'ORD-2');
        self::assertTrue($order->isDraft());
        self::assertTrue($order->canEdit());

        $order->setStatus('confirmed');
        self::assertFalse($order->isDraft());
        self::assertFalse($order->canEdit());

        $this->expectException(\InvalidArgumentException::class);
        $order->setStatus('nope');
    }

    public function testInvoiceIssueAndMarkPaid(): void
    {
        $invoice = new Invoice('ord-1', 'INV-1', '1000');
        self::assertSame('draft', $invoice->status());

        $invoice->issue(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-15'));
        self::assertSame('issued', $invoice->status());
        self::assertNotNull($invoice->issuedAt());

        $invoice->markPaid();
        self::assertSame('paid', $invoice->status());
    }
}
