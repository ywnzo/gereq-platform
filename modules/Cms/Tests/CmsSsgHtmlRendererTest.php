<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Modules\Cms\Application\CmsSsgHtmlRenderer;
use PHPUnit\Framework\TestCase;

final class CmsSsgHtmlRendererTest extends TestCase
{
    public function testRendersHeadingImageAndGallery(): void
    {
        $renderer = new CmsSsgHtmlRenderer();
        $html = $renderer->render([
            'title' => 'Domů',
            'slug' => 'home',
            'content_mode' => 'blocks',
            'ssg' => true,
            'blocks' => [
                [
                    'type' => 'heading',
                    'payload' => ['text' => 'Ahoj <em>světe</em>', 'level' => 1],
                ],
                [
                    'type' => 'image',
                    'payload' => [
                        'src' => '/media/1/file?w=960',
                        'srcset' => '/media/1/file?w=480 480w',
                        'alt' => 'Hero',
                        'width' => 1600,
                        'height' => 900,
                    ],
                ],
                [
                    'type' => 'gallery',
                    'payload' => [
                        'images' => [
                            ['src' => '/media/2/file?w=640', 'alt' => 'A'],
                            ['url' => '/media/3/file', 'alt' => 'B'],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertStringContainsString('data-gereq-ssg="1"', $html);
        self::assertStringContainsString('data-cms-slug="home"', $html);
        self::assertStringContainsString('<h1 class="gereq-ssg__heading">Ahoj <em>světe</em></h1>', $html);
        self::assertStringContainsString('src="/media/1/file?w=960"', $html);
        self::assertStringContainsString('srcset="/media/1/file?w=480 480w"', $html);
        self::assertStringContainsString('width="1600"', $html);
        self::assertStringContainsString('gereq-ssg__gallery', $html);
        self::assertStringContainsString('fetchpriority="high"', $html);
    }

    public function testFormattedHeadingRespectsItsAuthoredBlockType(): void
    {
        $renderer = new CmsSsgHtmlRenderer();
        $html = $renderer->renderFragment([
            ['type' => 'heading', 'payload' => ['level' => 2, 'text' => '<p style="font-size: 18px;">Paragraph</p>']],
            ['type' => 'heading', 'payload' => ['level' => 2, 'text' => '<h1>Heading one</h1>']],
        ]);

        self::assertStringContainsString('<p style="font-size: 18px">Paragraph</p>', $html);
        self::assertStringContainsString('<h1>Heading one</h1>', $html);
        self::assertStringNotContainsString('<h2', $html);
    }

    public function testRawModeUsesBodyHtml(): void
    {
        $renderer = new CmsSsgHtmlRenderer();
        $html = $renderer->render([
            'title' => 'Raw',
            'slug' => 'raw',
            'content_mode' => 'raw',
            'body' => '<p>Hello</p>',
            'blocks' => [],
        ]);

        self::assertStringContainsString('<div class="gereq-ssg__raw"><p>Hello</p></div>', $html);
        self::assertStringContainsString('<h1 class="gereq-ssg__title">Raw</h1>', $html);
    }

    public function testEscapesPlainQuoteAndCta(): void
    {
        $renderer = new CmsSsgHtmlRenderer();
        $html = $renderer->render([
            'title' => 'T',
            'slug' => 't',
            'content_mode' => 'blocks',
            'blocks' => [
                ['type' => 'quote', 'payload' => ['text' => 'A & B', 'cite' => 'Jim']],
                ['type' => 'cta', 'payload' => ['label' => 'Go', 'url' => '/kontakt']],
            ],
        ]);

        self::assertStringContainsString('A &amp; B', $html);
        self::assertStringContainsString('href="/kontakt"', $html);
        self::assertStringContainsString('>Go</a>', $html);
    }

    public function testQuotePreservesLineBreaksAndEscapesContent(): void
    {
        $html = (new CmsSsgHtmlRenderer())->renderFragment([
            ['type' => 'quote', 'payload' => ['quote' => "First & line\nSecond line"]],
        ]);

        self::assertStringContainsString('First &amp; line<br>Second line', $html);
    }

    public function testQuoteAttributionSupportsEitherFieldWithoutGaps(): void
    {
        $renderer = new CmsSsgHtmlRenderer();
        foreach ([
            [['author' => '', 'role' => 'Editor'], '<cite>Editor</cite>'],
            [['author' => 'Jan', 'role' => ''], '<cite>Jan</cite>'],
            [['author' => '', 'role' => ''], '</p></blockquote>'],
        ] as [$attribution, $expected]) {
            $html = $renderer->renderFragment([
                ['type' => 'quote', 'payload' => ['quote' => 'Hello'] + $attribution],
            ]);
            self::assertStringContainsString($expected, $html);
            self::assertStringNotContainsString(' · ', $html);
        }
    }

    public function testRendersBuilderShapedQuoteColumnsTableIconAndImageDims(): void
    {
        $renderer = new CmsSsgHtmlRenderer();
        $html = $renderer->render([
            'title' => 'T',
            'slug' => 't',
            'content_mode' => 'blocks',
            'blocks' => [
                ['type' => 'quote', 'payload' => ['quote' => 'Citace & tak', 'author' => 'Jan', 'role' => 'Editor']],
                ['type' => 'columns', 'payload' => ['left' => '<p>Lévo</p>', 'right' => '<p>Právo</p>']],
                ['type' => 'table', 'payload' => [
                    'html' => '<table><tbody><tr><td>A</td></tr></tbody></table>',
                    'caption' => 'Tabulka 1',
                    'caption_visible' => '1',
                ]],
                ['type' => 'icon', 'payload' => ['name' => 'local_shipping', 'title' => 'Doprava']],
                ['type' => 'image', 'payload' => ['src' => '/media/9/file', 'image_width' => 1200, 'image_height' => 800]],
            ],
        ]);

        self::assertStringContainsString(
            '<blockquote class="gereq-ssg__quote"><p>Citace &amp; tak</p><cite>Jan · Editor</cite></blockquote>',
            $html,
        );
        self::assertStringContainsString('<div class="gereq-ssg__columns">', $html);
        self::assertStringContainsString('<div class="gereq-ssg__col"><p>Lévo</p></div>', $html);
        self::assertStringContainsString('<p class="gereq-ssg__caption">Tabulka 1</p>', $html);
        self::assertStringContainsString('<td>A</td>', $html);
        self::assertStringContainsString('data-icon="local_shipping"', $html);
        self::assertStringContainsString('>Doprava</p>', $html);
        self::assertStringContainsString('width="1200"', $html);
        self::assertStringContainsString('height="800"', $html);
    }

    public function testDecorativeIconWithoutTitleRendersNothing(): void
    {
        $renderer = new CmsSsgHtmlRenderer();
        $html = $renderer->render([
            'title' => 'T',
            'slug' => 't',
            'content_mode' => 'blocks',
            'blocks' => [
                ['type' => 'icon', 'payload' => ['name' => 'local_shipping']],
                ['type' => 'divider', 'payload' => []],
            ],
        ]);

        self::assertStringNotContainsString('local_shipping', $html);
        self::assertStringContainsString('<hr class="gereq-ssg__divider">', $html);
    }
    public function testDynamicItemStylingAndOverflowSurviveStaticRendering(): void
    {
        $html = (new CmsSsgHtmlRenderer())->renderFragment([[
            'type' => 'dynamic',
            'payload' => [
                'variant' => 'carousel',
                'item_design' => [
                    'max_height' => 180, 'padding' => 12, 'radius' => 8,
                    'bg_color' => '#123456', 'border_w' => 2, 'border_color' => '#abcdef',
                ],
                'resolved_items' => [[[
                    'type' => 'text',
                    'payload' => ['body' => '<p>Review</p>', 'text_overflow' => 'ellipsis'],
                ]]],
            ],
        ]]);
        self::assertStringContainsString('cms-dynamic__item', $html);
        self::assertStringContainsString('max-height:180px;overflow-y:auto', $html);
        self::assertStringContainsString('padding:12px', $html);
        self::assertStringContainsString('background-color:#123456', $html);
        self::assertStringContainsString('border-color:#abcdef', $html);
        self::assertStringContainsString('cms-text-overflow-ellipsis', $html);
        self::assertStringContainsString('<p>Review</p>', $html);
    }
}
