<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\CRM;

use Modules\CRM\Domain\Activity;
use Modules\CRM\Domain\Deal;
use PHPUnit\Framework\TestCase;

final class CrmDomainTest extends TestCase
{
    public function testActivityCompleteAndReopen(): void
    {
        $activity = new Activity('cust-1', 'Zavolat', 'call');
        self::assertNull($activity->completedAt());

        $activity->complete();
        self::assertNotNull($activity->completedAt());

        $activity->reopen();
        self::assertNull($activity->completedAt());
    }

    public function testDealStages(): void
    {
        $deal = new Deal('cust-1', 'Nabídka bytu');
        self::assertSame('lead', $deal->stage());

        $deal->setStage('won');
        self::assertSame('won', $deal->stage());

        $this->expectException(\InvalidArgumentException::class);
        $deal->setStage('archived');
    }
}
