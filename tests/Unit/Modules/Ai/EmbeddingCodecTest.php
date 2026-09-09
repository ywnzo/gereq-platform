<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Ai;

use Modules\Ai\Application\Vector\EmbeddingCodec;
use PHPUnit\Framework\TestCase;

final class EmbeddingCodecTest extends TestCase
{
    public function testPackUnpackRoundtrip(): void
    {
        $vector = [0.1, -0.25, 0.5, 1.0];
        $binary = EmbeddingCodec::pack($vector);
        $out = EmbeddingCodec::unpack($binary);
        self::assertCount(4, $out);
        self::assertEqualsWithDelta(0.1, $out[0], 1e-6);
        self::assertEqualsWithDelta(-0.25, $out[1], 1e-6);
    }

    public function testCosineIdentical(): void
    {
        $a = [1.0, 0.0, 0.0];
        self::assertEqualsWithDelta(1.0, EmbeddingCodec::cosine($a, $a), 1e-6);
    }

    public function testCosineOrthogonal(): void
    {
        $a = [1.0, 0.0];
        $b = [0.0, 1.0];
        self::assertEqualsWithDelta(0.0, EmbeddingCodec::cosine($a, $b), 1e-6);
    }
}
