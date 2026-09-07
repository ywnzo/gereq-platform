<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Calendar;

use Modules\Calendar\Domain\CalendarConnection;
use PHPUnit\Framework\TestCase;

final class CalendarConnectionTest extends TestCase
{
    public function testMarkSyncedAndError(): void
    {
        $conn = new CalendarConnection('google', 'user@example.com');
        self::assertSame('pending', $conn->status());

        $conn->markSynced();
        self::assertSame('connected', $conn->status());
        self::assertNotNull($conn->lastSyncedAt());

        $conn->markError('token expired');
        self::assertSame('error', $conn->status());
        self::assertSame('token expired', $conn->lastError());
    }
}
