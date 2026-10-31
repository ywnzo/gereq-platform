<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Gereq\Testing\TestCase;
use Modules\Cms\Application\IntegrationWidget;
use Modules\Cms\Application\CmsSsgHtmlRenderer;

final class IntegrationWidgetTest extends TestCase
{
    public function testValidatesProviderEndpoints(): void
    {
        foreach ([
            'openstreetmap' => 'https://www.openstreetmap.org/export/embed.html?bbox=18,49,19,50',
            'google_maps' => 'https://www.google.com/maps/embed?pb=example',
            'calendly' => 'https://calendly.com/team/meeting',
            'tally' => 'https://tally.so/embed/example',
        ] as $provider => $url) {
            self::assertSame($url, IntegrationWidget::url($provider, $url));
        }
        foreach (['javascript:alert(1)', 'http://calendly.com/team', 'https://calendly.com.evil.test/team', 'https://name@calendly.com/team', 'https://calendly.com:8443/team', "https://calendly.com/te\nam", 'https://calendly.com/team\\evil'] as $url) {
            self::assertSame('', IntegrationWidget::url('calendly', $url));
        }
        self::assertSame('', IntegrationWidget::url('google_maps', 'https://www.google.com/maps/embedder'));
    }

    public function testSnapshotEscapesTitleClampsHeightAndRejectsInvalidUrls(): void
    {
        $renderer = new CmsSsgHtmlRenderer();
        $p = ['provider' => 'calendly', 'url' => 'https://calendly.com/team/meeting', 'title' => '"><script>', 'widget_height' => 9000];
        $html = $renderer->renderFragment([['type' => 'widget', 'payload' => $p]]);
        self::assertStringContainsString('height:1600px', $html);
        self::assertStringContainsString('sandbox="allow-scripts allow-same-origin allow-forms allow-popups"', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertSame('', $renderer->renderFragment([['type' => 'widget', 'payload' => ['url' => 'javascript:alert(1)']]]));
    }
}
