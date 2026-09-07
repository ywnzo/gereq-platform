<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Payments;

use Modules\Payments\Application\SpaydBuilder;
use PHPUnit\Framework\TestCase;

final class SpaydBuilderTest extends TestCase
{
    public function testBuildsSpaydWithIban(): void
    {
        $spd = SpaydBuilder::build([
            'iban' => 'CZ65 0800 0000 1920 0014 5399',
            'amount' => 1234.5,
            'currency' => 'czk',
            'vs' => '2026001',
            'message' => 'Faktura * 1',
            'recipient' => 'GEREQ s.r.o.',
        ]);

        self::assertStringStartsWith('SPD*1.0*', $spd);
        self::assertStringContainsString('ACC:CZ6508000000192000145399', $spd);
        self::assertStringContainsString('AM:1234.50', $spd);
        self::assertStringContainsString('CC:CZK', $spd);
        self::assertStringContainsString('X-VS:2026001', $spd);
        self::assertStringContainsString('MSG:Faktura  1', $spd);
        self::assertStringContainsString('RN:GEREQ s.r.o.', $spd);
    }

    public function testCzechAccountToIbanIsValidChecksum(): void
    {
        $iban = SpaydBuilder::czechAccountToIban('19-2000145399', '0800');
        self::assertSame('CZ6508000000192000145399', $iban);

        $spd = SpaydBuilder::build([
            'account' => '19-2000145399',
            'bank_code' => '800',
            'amount' => '10',
        ]);
        self::assertStringContainsString('ACC:CZ6508000000192000145399', $spd);
    }
}
