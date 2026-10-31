<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Modules\Cms\Application\BlockDesignNormalizer;
use Modules\Cms\Application\CmsSsgHtmlRenderer;
use Modules\Cms\Application\FormSchema;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

final class PaletteColorSelectorsTest extends TestCase
{
    public function testSelectorsOfferPaletteRolesAndKeepLegacyValuesHidden(): void
    {
        $root = dirname(__DIR__, 3);
        $loader = new FilesystemLoader($root . '/resources/views');
        $loader->addPath(dirname(__DIR__) . '/Resources/views', 'cms');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $html = $twig->render('@cms/pages/_palette_color_options.html.twig');
        foreach (['brand', 'secondary', 'accent', 'background', 'surface', 'header', 'custom'] as $value) {
            self::assertStringContainsString('value="' . $value . '"', $html);
            self::assertStringNotContainsString('value="' . $value . '" hidden', $html);
        }
        foreach (['ink', 'muted', 'paper', 'brand-soft'] as $legacy) {
            self::assertStringContainsString('value="' . $legacy . '" hidden', $html);
        }
    }

    public function testPaletteRolesSurviveNormalizationAndStaticRendering(): void
    {
        foreach (['brand', 'secondary', 'accent', 'background', 'surface', 'header'] as $color) {
            $design = BlockDesignNormalizer::merge([], ['bg_color' => $color, 'text_color' => $color, 'border_color' => $color]);
            self::assertSame($color, $design['bg_color']);
            self::assertSame($color, $design['text_color']);
            self::assertSame($color, $design['border_color']);
            $form = FormSchema::normalize(['input_bg_color' => $color, 'input_text_color' => $color, 'submit_bg_color' => $color], static fn (): array => []);
            self::assertSame($color, $form['input_bg_color']);
            self::assertSame($color, $form['input_text_color']);
            self::assertSame($color, $form['submit_bg_color']);
            $html = (new CmsSsgHtmlRenderer())->renderFragment([['type' => 'text', 'payload' => [
                'body' => '<p>Example</p>', 'bg_color' => $color, 'text_color' => $color, 'border_color' => $color,
            ]]]);
            self::assertStringContainsString('cms-bg-' . $color, $html);
            self::assertStringContainsString('cms-text-' . $color, $html);
            self::assertStringContainsString('cms-border-' . $color, $html);
        }
    }
    public function testQuoteBorderColorSurvivesNormalization(): void
    {
        foreach (['brand', 'accent', 'none', '#123456'] as $color) {
            self::assertSame($color, BlockDesignNormalizer::merge([], ['quote_border_color' => $color])['quote_border_color']);
        }
        self::assertSame('accent', BlockDesignNormalizer::merge([], ['quote_border_color' => 'unsafe'])['quote_border_color']);
    }
}
