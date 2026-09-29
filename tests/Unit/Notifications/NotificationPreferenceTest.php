<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Notifications;

use Gereq\Notifications\Domain\NotificationPreference;
use PHPUnit\Framework\TestCase;

final class NotificationPreferenceTest extends TestCase
{
    public function testSetEnabledRoundTrip(): void
    {
        $pref = new NotificationPreference('user-1', 'email', 'order.created', true);
        self::assertTrue($pref->enabled());
        self::assertSame('email', $pref->channel());
        self::assertSame('order.created', $pref->eventType());

        $pref->setEnabled(false);
        self::assertFalse($pref->enabled());
        $pref->setEnabled(true);
        self::assertTrue($pref->enabled());
    }
}
