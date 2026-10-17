<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Setup;

use Gereq\Setup\SetupFlow;
use Gereq\Testing\TestCase;

final class SetupFlowTest extends TestCase
{
    public function testNoModulesFinishesAfterFourthStep(): void
    {
        self::assertCount(4, SetupFlow::steps(false));
        self::assertSame('finish', SetupFlow::next(['welcome', 'account', 'company', 'modules'], false));
    }

    public function testSelectedModulesRequireDashboard(): void
    {
        self::assertCount(5, SetupFlow::steps(true));
        self::assertSame('dashboard', SetupFlow::next(['welcome', 'account', 'company', 'modules'], true));
        self::assertSame('finish', SetupFlow::next(['welcome', 'account', 'company', 'modules', 'dashboard'], true));
    }

    public function testMissingEarlierStepCannotBeSkipped(): void
    {
        self::assertSame('company', SetupFlow::next(['welcome', 'account', 'modules', 'dashboard'], true));
    }

    public function testChangingEarlierStepInvalidatesLaterConfirmations(): void
    {
        self::assertSame(['welcome', 'account'], SetupFlow::complete(['welcome', 'account', 'company', 'modules', 'dashboard'], 'account', true));
        self::assertSame(['welcome', 'account', 'company', 'modules'], SetupFlow::complete(['welcome', 'account', 'company', 'modules', 'dashboard'], 'modules', false));
    }
}
