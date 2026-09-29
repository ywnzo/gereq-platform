<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Currency;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Currency\Application\CurrencyConverter;
use Gereq\Currency\Domain\Currency;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    public function testCodeMustBeThreeLetters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Currency('EURO', 'Euro', '€');
    }

    public function testCannotDeactivateDefaultCurrency(): void
    {
        $czk = new Currency('CZK', 'Koruna', 'Kč');
        $czk->markDefault();
        self::assertTrue($czk->isDefault());
        self::assertTrue($czk->isActive());

        $this->expectException(\LogicException::class);
        $czk->deactivate();
    }

    public function testActivateDeactivateNonDefault(): void
    {
        $eur = new Currency('EUR', 'Euro', '€');
        $eur->deactivate();
        self::assertFalse($eur->isActive());
        $eur->activate();
        self::assertTrue($eur->isActive());
    }

    public function testIdentityConversion(): void
    {
        $czk = new Currency('CZK', 'Koruna', 'Kč', 2);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($czk);

        $converter = new CurrencyConverter($em);
        $result = $converter->convert('1 234,50', 'czk', 'CZK');

        self::assertSame('identity', $result['provider']);
        self::assertSame('CZK', $result['source']['currency']);
        self::assertSame('CZK', $result['target']['currency']);
        self::assertSame('1.000000', $result['rate']);
        self::assertSame(1234.5, $result['target']['amount']);
    }

    public function testInvalidAmountThrows(): void
    {
        $czk = new Currency('CZK', 'Koruna', 'Kč');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($czk);

        $this->expectException(\InvalidArgumentException::class);
        new CurrencyConverter($em)->convert('abc', 'CZK', 'CZK');
    }
}
