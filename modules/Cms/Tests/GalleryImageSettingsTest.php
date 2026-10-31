<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Gereq\Testing\TestCase;
use Modules\Cms\Application\BlockDesignNormalizer;
use Modules\Cms\Application\GalleryImageSettingsNormalizer;
use Modules\Cms\Http\Api\BlockApiController;
use Modules\Cms\Http\BlockController;
use Modules\Cms\Http\PageController;
use ReflectionClass;

final class GalleryImageSettingsTest extends TestCase
{
    public function testSettingsSurviveEveryGallerySaveNormalizer(): void
    {
        $raw = json_encode(['media-one' => [
            'span' => 2, 'ratio' => '3/4', 'height' => 320,
            'group' => 'Interiér', 'column' => 2, 'fit' => 'cover',
        ]]);
        $payload = ['media_ids' => 'media-one', 'variant' => 'masonry', 'gallery_items' => $raw];
        foreach ([PageController::class, BlockController::class, BlockApiController::class] as $class) {
            $reflection = new ReflectionClass($class);
            $method = $reflection->getMethod($class === PageController::class ? 'normalizeBuilderPayload' : 'payloadFromData');
            $saved = $method->invoke($reflection->newInstanceWithoutConstructor(), 'gallery', $payload);
            self::assertSame(json_decode($raw, true), json_decode($saved['gallery_items'], true), $class);
        }
    }

    public function testInvalidAndOversizedSettingsAreClamped(): void
    {
        $normalized = json_decode(GalleryImageSettingsNormalizer::normalize([
            'media-one' => ['span' => 20, 'height' => -2, 'ratio' => 'invalid', 'group' => str_repeat('a', 100), 'column' => 9, 'fit' => 'script'],
            'media-two' => ['span' => 0, 'height' => 99999, 'group' => ['invalid']],
            'invalid' => 'not-an-item',
        ]), true);
        self::assertSame(['span' => 3, 'ratio' => '', 'height' => 0, 'group' => str_repeat('a', 64), 'column' => 3, 'fit' => 'contain'], $normalized['media-one']);
        self::assertSame(1, $normalized['media-two']['span']);
        self::assertSame(4096, $normalized['media-two']['height']);
        self::assertSame('', $normalized['media-two']['group']);
        self::assertArrayNotHasKey('invalid', $normalized);
        self::assertSame('{}', GalleryImageSettingsNormalizer::normalize('broken-json'));
        self::assertSame('{}', GalleryImageSettingsNormalizer::normalize(null));
    }

    public function testMediaChangesKeepExistingPhotoSettings(): void
    {
        $payload = ['media_ids' => 'media-one,media-two', 'gallery_items' => '{"media-one":{"span":2,"group":"Exterior"}}'];
        $normalized = BlockDesignNormalizer::merge($payload, ['media_ids' => 'media-two,media-one']);
        $settings = json_decode($normalized['gallery_items'], true);
        self::assertSame(2, $settings['media-one']['span']);
        self::assertSame('Exterior', $settings['media-one']['group']);
    }
}
