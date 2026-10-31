<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Gereq\Testing\TestCase;
use Modules\Cms\Application\BlockDesignNormalizer;

final class BrandedSlotTest extends TestCase
{
    public function testStableSlotSurvivesNormalization(): void
    {
        $payload = BlockDesignNormalizer::merge(['text' => 'Updated title'], ['gereq_slot' => 'HomePage-2', 'admin_label' => 'Hero heading']);
        self::assertSame('HomePage-2', $payload['gereq_slot']);
        self::assertSame('Updated title', $payload['text']);
        self::assertSame('Hero heading', $payload['admin_label']);
    }

    public function testInvalidSlotIsNotPersisted(): void
    {
        $payload = BlockDesignNormalizer::merge([], ['gereq_slot' => '<script>']);
        self::assertArrayNotHasKey('gereq_slot', $payload);
    }
}
