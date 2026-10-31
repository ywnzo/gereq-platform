<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Gereq\Media\Application\MediaUrlBuilder;
use Gereq\Media\Domain\MediaAsset;
use Gereq\Testing\TestCase;
use Modules\Cms\Application\CmsProvider;
use ReflectionClass;

final class PublicMediaPayloadTest extends TestCase
{
    public function testImagePayloadIncludesResponsiveFieldsFromCache(): void
    {
        $asset = new MediaAsset('Hero', 'seed/a.avif', 'image/avif', 100, null, 'Hero alt', 1600, 900);
        $builder = new MediaUrlBuilder();
        $descriptor = $builder->forAsset($asset);
        self::assertNotNull($descriptor);

        $reflection = new ReflectionClass(CmsProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('mediaUrls')->setValue($provider, $builder);
        $reflection->getProperty('mediaUrlCache')->setValue($provider, [$asset->id() => $descriptor]);

        $method = $reflection->getMethod('publicPayload');
        /** @var array<string, mixed> $payload */
        $payload = $method->invoke($provider, 'image', ['media_id' => $asset->id()]);

        self::assertSame($descriptor['url'], $payload['url']);
        self::assertSame($descriptor['src'], $payload['src']);
        self::assertSame($descriptor['srcset'], $payload['srcset']);
        self::assertSame($descriptor['sizes'], $payload['sizes']);
        self::assertSame(1600, $payload['width']);
        self::assertSame(900, $payload['height']);
        self::assertSame('Hero alt', $payload['alt']);
        self::assertSame('image/avif', $payload['mime']);
    }

    public function testGalleryImagesIncludeSrcset(): void
    {
        $asset = new MediaAsset('G', 'seed/g.avif', 'image/avif', 40, null, null, 800, 600);
        $builder = new MediaUrlBuilder();
        $descriptor = $builder->forAsset($asset);
        self::assertNotNull($descriptor);

        $reflection = new ReflectionClass(CmsProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('mediaUrls')->setValue($provider, $builder);
        $reflection->getProperty('mediaUrlCache')->setValue($provider, [$asset->id() => $descriptor]);

        $method = $reflection->getMethod('publicPayload');
        /** @var array<string, mixed> $payload */
        $payload = $method->invoke($provider, 'gallery', ['media_ids' => $asset->id()]);

        self::assertCount(1, $payload['images']);
        self::assertSame($asset->id(), $payload['images'][0]['media_id']);
        self::assertStringContainsString('800w', $payload['images'][0]['srcset']);
        self::assertArrayHasKey('width', $payload['images'][0]);
    }

    public function testImageKeepsExplicitAltOverMediaAlt(): void
    {
        $asset = new MediaAsset('Hero', 'seed/a.avif', 'image/avif', 100, null, 'From media', 1200, 800);
        $builder = new MediaUrlBuilder();
        $descriptor = $builder->forAsset($asset);
        self::assertNotNull($descriptor);

        $reflection = new ReflectionClass(CmsProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('mediaUrls')->setValue($provider, $builder);
        $reflection->getProperty('mediaUrlCache')->setValue($provider, [$asset->id() => $descriptor]);

        $method = $reflection->getMethod('publicPayload');
        /** @var array<string, mixed> $payload */
        $payload = $method->invoke($provider, 'image', [
            'media_id' => $asset->id(),
            'alt' => 'From block',
        ]);

        self::assertSame('From block', $payload['alt']);
    }
}
