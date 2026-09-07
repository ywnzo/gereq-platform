<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Webhooks;

use Modules\Webhooks\Domain\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookMatchingTest extends TestCase
{
    public function testExactAndNestedWildcards(): void
    {
        $hook = new Webhook('Orders', 'https://example.com/hook', [
            'order.created',
            'invoice.*',
            'customer.note.*',
        ]);

        self::assertTrue($hook->matchesEvent('order.created'));
        self::assertFalse($hook->matchesEvent('order.updated'));
        self::assertTrue($hook->matchesEvent('invoice.paid'));
        self::assertTrue($hook->matchesEvent('customer.note.added'));
        self::assertFalse($hook->matchesEvent('customer.created'));
    }
}
