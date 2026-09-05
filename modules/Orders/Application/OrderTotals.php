<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use Modules\Orders\Domain\Order;
use Modules\Orders\Domain\OrderLine;

final class OrderTotals
{
    /** @param list<OrderLine> $lines */
    public static function recalculate(Order $order, array $lines, string $discountTotal = '0'): void
    {
        $subtotal = 0.0;
        $tax = 0.0;
        foreach ($lines as $line) {
            $subtotal += (float) $line->lineSubtotal();
            $tax += (float) $line->lineTax();
        }
        $discount = max(0.0, (float) $discountTotal);
        $total = max(0.0, $subtotal + $tax - $discount);
        $order->setTotals(
            number_format($subtotal, 2, '.', ''),
            number_format($tax, 2, '.', ''),
            number_format($total, 2, '.', ''),
            number_format($discount, 2, '.', ''),
        );
    }
}
