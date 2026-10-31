<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Gereq\Testing\TestCase;
use Modules\Cms\Application\VisualElement;
use Modules\Cms\Application\CmsSsgHtmlRenderer;

final class VisualElementTest extends TestCase
{
    public function testNormalizesPhotoShapeAndSize(): void
    {
        $p = VisualElement::normalize('avatar', ['url' => 'javascript:alert(1)', 'avatar_size' => 999, 'shape' => 'invalid']);
        self::assertSame('', $p['url']);
        self::assertSame(256, $p['avatar_size']);
        self::assertSame('circle', $p['shape']);
        self::assertSame('{{ item.photo }}', VisualElement::normalize('avatar', ['url' => '{{ item.photo }}'])['url']);
        self::assertSame('', VisualElement::normalize('avatar', ['url' => '//evil.test/photo'])['url']);
    }

    public function testRendersInitialsAndEscapesBadgeContent(): void
    {
        $renderer = new CmsSsgHtmlRenderer();
        $html = $renderer->renderFragment([
            ['type' => 'avatar', 'payload' => ['name' => 'Jiří Grygerek']],
            ['type' => 'badge', 'payload' => ['label' => '<script>alert(1)</script>', 'tone' => 'outline']],
        ]);
        self::assertStringContainsString('>JG</span>', $html);
        self::assertStringContainsString('aria-label="Jiří Grygerek"', $html);
        self::assertStringContainsString('cms-badge--outline', $html);
        self::assertStringNotContainsString('<script>', $html);
    }
}
