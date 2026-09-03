<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Http\Exception\HttpException;
use PHPUnit\Framework\TestCase;

final class AbortHelperTest extends TestCase
{
    public function testAbortThrowsHttpException(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Zákazník neexistuje.');

        try {
            abort(404, 'Zákazník neexistuje.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status());
            throw $exception;
        }
    }
}
