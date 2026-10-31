<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Gereq\Testing\TestCase;
use Modules\Cms\Application\BlockDesignNormalizer;
use Modules\Cms\Application\DocumentMetadata;
use Modules\Cms\Http\Api\BlockApiController;
use Modules\Cms\Http\BlockController;
use Modules\Cms\Http\PageController;
use ReflectionClass;

final class DocumentMetadataTest extends TestCase
{
    public function testNativeMetadataPreservesDesignButRejectsExecutableAttributes(): void
    {
        $saved = DocumentMetadata::normalize([
            'gereq_tag' => 'section',
            'gereq_attrs' => ['class' => 'wrap hero', 'aria-label' => 'Hero', 'onclick' => 'alert(1)', 'style' => 'color: red'],
            'gereq_sources' => [['srcset' => '/photo.avif 800w', 'type' => 'image/avif']],
        ]);
        self::assertSame('section', $saved['gereq_tag']);
        self::assertSame(['class' => 'wrap hero', 'aria-label' => 'Hero', 'style' => 'color: red'], $saved['gereq_attrs']);
        self::assertSame('/photo.avif 800w', $saved['gereq_sources'][0]['srcset']);
        $unsafe = DocumentMetadata::normalize(['gereq_tag' => 'script', 'gereq_attrs' => ['style' => 'background:url(https://evil.invalid)', 'srcset' => 'javascript:alert(1)']]);
        self::assertArrayNotHasKey('gereq_tag', $unsafe);
        self::assertSame([], $unsafe['gereq_attrs']);
    }

    public function testNestedNativeLinksSurviveEverySavePath(): void
    {
        $raw = ['gereq_tag' => 'a', 'url' => '/kontakt/', 'gereq_attrs' => ['class' => 'card'], 'children' => [
            ['id' => 'test-icon', 'type' => 'icon', 'payload' => ['name' => 'email', 'gereq_original_icon' => 'email']],
            ['id' => 'test-photo', 'type' => 'image', 'payload' => ['url' => '/photo.webp', 'alt' => 'Photo']],
        ]];
        foreach ([PageController::class, BlockController::class, BlockApiController::class] as $class) {
            $reflection = new ReflectionClass($class);
            $method = $reflection->getMethod($class === PageController::class ? 'normalizeBuilderPayload' : 'payloadFromData');
            $saved = $method->invoke($reflection->newInstanceWithoutConstructor(), 'cta', $raw);
            self::assertSame('a', $saved['gereq_tag'], $class);
            self::assertCount(2, $saved['children'], $class);
            self::assertSame('test-icon', $saved['children'][0]['id'], $class);
            self::assertSame('email', $saved['children'][0]['payload']['name'], $class);
            self::assertSame('/photo.webp', $saved['children'][1]['payload']['url'], $class);
        }
        self::assertSame('a', BlockDesignNormalizer::merge([], $raw)['gereq_tag']);
    }
}
