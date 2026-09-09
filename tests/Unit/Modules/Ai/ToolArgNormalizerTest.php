<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Ai;

use Modules\Ai\Application\ToolArgNormalizer;
use PHPUnit\Framework\TestCase;

final class ToolArgNormalizerTest extends TestCase
{
    public function testToBoolAcceptsStringFalse(): void
    {
        self::assertFalse(ToolArgNormalizer::toBool('false'));
        self::assertFalse(ToolArgNormalizer::toBool('0'));
        self::assertFalse(ToolArgNormalizer::toBool('no'));
        self::assertTrue(ToolArgNormalizer::toBool('true'));
        self::assertTrue(ToolArgNormalizer::toBool('1'));
        self::assertTrue(ToolArgNormalizer::toBool(true));
        self::assertFalse(ToolArgNormalizer::toBool(false));
    }

    public function testNormalizeCoercesBooleanStrings(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'is_company' => ToolArgNormalizer::booleanProperty(),
                'name' => ['type' => 'string'],
            ],
        ];

        $out = ToolArgNormalizer::normalize([
            'is_company' => 'true',
            'name' => 'Acme',
        ], $schema);

        self::assertTrue($out['is_company']);
        self::assertSame('Acme', $out['name']);
    }
}
