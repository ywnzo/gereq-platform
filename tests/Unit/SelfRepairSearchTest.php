<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Search\TextNormalizer;
use Gereq\Search\TypoExpander;
use PHPUnit\Framework\TestCase;

final class SelfRepairSearchTest extends TestCase
{
    public function testNormalizerFoldsCzechDiacritics(): void
    {
        $n = new TextNormalizer();
        self::assertSame('plzen', $n->fold('Plzeň'));
        self::assertSame('cesky krumlov', $n->fold('Český Krumlov'));
    }

    public function testTypoExpanderIncludesTranspositionAndNeighbor(): void
    {
        $expander = new TypoExpander(new TextNormalizer(), maxVariants: 40, minTokenLength: 3);
        $variants = $expander->expand('praha');

        self::assertContains('praha', $variants);
        // adjacent transposition: rpaha / parha / prhaa etc.
        self::assertTrue(
            in_array('parha', $variants, true) || in_array('rpaha', $variants, true),
            'Expected transposition variant, got: ' . implode(', ', $variants),
        );
    }

    public function testTypoExpanderIgnoresShortTokens(): void
    {
        $expander = new TypoExpander(new TextNormalizer(), maxVariants: 14, minTokenLength: 3);
        self::assertSame(['ab'], $expander->expand('ab'));
    }

    public function testSqlFoldExpressionNestsReplace(): void
    {
        $expr = (new TextNormalizer())->sqlFoldExpression('l.title');
        self::assertStringContainsString('LOWER(l.title)', $expr);
        self::assertStringContainsString("REPLACE(", $expr);
        self::assertStringContainsString("'ě'", $expr);
    }
}
