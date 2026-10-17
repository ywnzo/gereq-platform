<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Modules\Shop\Submodules\Orders\Application\SimpleInvoicePdf;
use PHPUnit\Framework\TestCase;

final class SimpleInvoicePdfTest extends TestCase
{
    public function testRendersPdfHeader(): void
    {
        $bin = SimpleInvoicePdf::render(['FAKTURA TEST', 'Celkem: 100 CZK'], 'INV-1');
        self::assertStringStartsWith('%PDF', $bin);
        self::assertStringContainsString('%%EOF', $bin);
    }
}
