<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Ai;

use Modules\Ai\Application\Rag\TextChunker;
use PHPUnit\Framework\TestCase;

final class TextChunkerTest extends TestCase
{
    public function testChunksLongText(): void
    {
        $text = str_repeat("Odstavec o nemovitosti.\n\n", 80);
        $chunker = new TextChunker(100, 20);
        $parts = $chunker->chunk($text);
        self::assertNotEmpty($parts);
        self::assertSame(0, $parts[0]['ordinal']);
        self::assertNotSame('', $parts[0]['hash']);
    }
}
