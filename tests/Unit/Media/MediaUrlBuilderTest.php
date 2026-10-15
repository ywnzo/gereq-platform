<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Media;

use Gereq\Media\Application\MediaUrlBuilder;
use Gereq\Media\Domain\MediaAsset;
use PHPUnit\Framework\TestCase;

final class MediaUrlBuilderTest extends TestCase
{
    private MediaUrlBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new MediaUrlBuilder();
    }

    public function testReturnsNullForNonImage(): void
    {
        $asset = new MediaAsset('Doc', 'seed/a.pdf', 'application/pdf', 100);

        self::assertNull($this->builder->forAsset($asset));
    }

    public function testConstrainedDescriptorShapeAndClamp(): void
    {
        $asset = new MediaAsset('Hero', 'seed/a.avif', 'image/avif', 100, 'u1', 'Alt text', 1800, 1200);
        $payload = $this->builder->forAsset($asset, MediaUrlBuilder::LAYOUT_CONSTRAINED);

        self::assertNotNull($payload);
        self::assertSame($asset->id(), $payload['id']);
        self::assertSame('Alt text', $payload['alt']);
        self::assertSame('image/avif', $payload['mime']);
        self::assertSame(1800, $payload['width']);
        self::assertSame(1200, $payload['height']);
        self::assertSame($payload['src'], $payload['url']);
        self::assertStringContainsString('/media/' . $asset->id() . '/file?w=', $payload['src']);
        self::assertStringContainsString('960w', $payload['srcset']);
        self::assertStringContainsString('1600w', $payload['srcset']);
        self::assertStringNotContainsString('1920w', $payload['srcset']);
        self::assertStringNotContainsString('2400w', $payload['srcset']);
        self::assertSame('(max-width: 768px) 100vw, 960px', $payload['sizes']);
    }

    public function testSrcsetWidthsUnknownNativeUsesLayoutCap(): void
    {
        $steps = $this->builder->srcsetWidths(null, MediaUrlBuilder::LAYOUT_CONSTRAINED);

        self::assertSame(
            [160, 320, 480, 640, 800, 960, 1200, 1600, 1920],
            $steps,
        );
    }

    public function testSrcsetWidthsTinyAssetStillHasCandidate(): void
    {
        self::assertSame([160], $this->builder->srcsetWidths(10, MediaUrlBuilder::LAYOUT_CONSTRAINED));
    }

    public function testThumbLayoutCapsAndSizes(): void
    {
        $asset = new MediaAsset('Thumb', 'seed/t.avif', 'image/avif', 50, null, null, 2400, 2400);
        $payload = $this->builder->forAsset($asset, MediaUrlBuilder::LAYOUT_THUMB);

        self::assertNotNull($payload);
        self::assertStringNotContainsString('800w', $payload['srcset']);
        self::assertStringContainsString('640w', $payload['srcset']);
        self::assertStringContainsString('50vw', $payload['sizes']);
        self::assertStringContainsString('?w=320', $payload['src']);
    }

    public function testFullLayoutSizesIsViewport(): void
    {
        $asset = new MediaAsset('Full', 'seed/f.avif', 'image/avif', 50, null, null, 3000, 2000);
        $payload = $this->builder->forAsset($asset, MediaUrlBuilder::LAYOUT_FULL);

        self::assertNotNull($payload);
        self::assertSame('100vw', $payload['sizes']);
        self::assertStringContainsString('2400w', $payload['srcset']);
        self::assertStringContainsString('?w=1600', $payload['src']);
    }

    public function testWidthStepsMatchDeliveryContract(): void
    {
        self::assertSame(
            [160, 320, 480, 640, 800, 960, 1200, 1600, 1920, 2400],
            MediaUrlBuilder::WIDTH_STEPS,
        );
    }
}
