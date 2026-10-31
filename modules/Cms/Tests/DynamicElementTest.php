<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Gereq\Cms\Cms;
use Gereq\Module\EnabledModulesProvider;
use Gereq\Security\HtmlSanitizer;
use Modules\Cms\Application\DynamicElement;
use PHPUnit\Framework\TestCase;

final class DynamicElementTest extends TestCase
{
    private function cms(bool $enabled = true): Cms
    {
        $modules = $this->createStub(EnabledModulesProvider::class);
        $modules->method('enabledIds')->willReturn($enabled ? ['cms'] : []);
        return new Cms($modules);
    }

    public function testDisabledCmsDoesNotRegisterOrLoadSources(): void
    {
        $cms = $this->cms(false);
        $cms->register('reviews', 'Reviews', ['body'], static function (): array {
            self::fail('Disabled CMS must not load entities.');
        });
        self::assertFalse($cms->isEnabled());
        self::assertSame([], $cms->catalog());
        self::assertSame([], $cms->items('reviews', 10));
    }

    public function testRegistryBoundsQueriesAndRemovesPrivateFields(): void
    {
        $cms = $this->cms();
        $cms->register('reviews', 'Reviews', ['body'], static function (int $limit): array {
            self::assertSame(100, $limit);
            return [['body' => 'Public', 'email' => 'private@example.test']];
        });
        self::assertSame([['body' => 'Public']], $cms->items('reviews', 10000));
        self::assertSame([], $cms->items('disabled-source', 1));
    }

    public function testInterpolationEscapesValuesAndSanitizesFinalUrls(): void
    {
        $element = new DynamicElement($this->cms(), new HtmlSanitizer());
        $html = $element->renderItem('<p>{{ item.author.name }}: {{ item.body }}</p><a href="{{ item.url }}">Link</a>{{ item.missing }}', [
            'author' => ['name' => 'Alice'],
            'body' => '<script>alert(1)</script>',
            'url' => 'javascript:alert(1)',
        ]);
        self::assertStringContainsString('Alice:', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('{{ item.missing }}', $html);
    }

    public function testResolveLoadsFreshDataAndRejectsForgedRenderedItems(): void
    {
        $cms = $this->cms();
        $cms->register('reviews', 'Reviews', ['body'], static fn (int $limit): array => [['body' => 'Approved']]);
        $element = new DynamicElement($cms, new HtmlSanitizer());
        $payload = $element->resolve(['source' => 'reviews', 'item_template' => '<p>{{ item.body }}</p>', 'variant' => 'carousel', 'rendered_items' => ['forged']]);
        self::assertSame(['<p>Approved</p>'], $payload['rendered_items']);
        self::assertSame('carousel', $payload['variant']);
        self::assertSame([], $element->resolve(['source' => 'unknown'])['rendered_items']);
    }

    public function testPublicSerializationResolvesSourcesInsideLayouts(): void
    {
        $cms = $this->cms();
        $cms->register('reviews', 'Reviews', ['body'], static fn (int $limit): array => [['body' => '{{ page.title }}']]);
        $reflection = new \ReflectionClass(\Modules\Cms\Application\CmsProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('dynamicElements')->setValue($provider, new DynamicElement($cms, new HtmlSanitizer()));
        $payload = $reflection->getMethod('publicPayload')->invoke($provider, 'layout', [
            'children' => [['type' => 'dynamic', 'payload' => [
                'source' => 'reviews', 'item_template' => '<p>{{ item.body }}</p>',
            ]]],
        ]);
        self::assertSame(['<p>{{ page.title }}</p>'], $payload['children'][0]['payload']['rendered_items']);
        $expanded = $reflection->getMethod('expandPayloadVariables')->invoke($provider, $payload, ['page.title' => '<script>bad</script>']);
        self::assertSame($payload, $expanded);
    }

    public function testStructuredTemplateBindsNestedBlocksAndRatingWithoutChangingOriginal(): void
    {
        $cms = $this->cms();
        $cms->register('reviews', 'Reviews', ['body', 'rating'], static fn (int $limit): array => [['body' => '<script>bad</script>', 'rating' => 4]]);
        $element = new DynamicElement($cms, new HtmlSanitizer());
        $blocks = [['id' => 'layout', 'type' => 'layout', 'payload' => ['children' => [
            ['id' => 'rating', 'type' => 'rating', 'payload' => ['value' => '{{ item.rating }}']],
            ['id' => 'body', 'type' => 'text', 'payload' => ['body' => '<p>{{ item.body }}</p>']],
        ]]]];
        $resolved = $element->resolve(['source' => 'reviews', 'item_blocks' => $blocks, 'resolved_items' => ['forged']]);
        self::assertSame($blocks, $resolved['item_blocks']);
        self::assertSame('4', $resolved['resolved_items'][0][0]['payload']['children'][0]['payload']['value']);
        self::assertStringNotContainsString('<script>', $resolved['resolved_items'][0][0]['payload']['children'][1]['payload']['body']);
    }

    public function testReusableTemplateKeepsLayoutsAndBindingsWhenSaved(): void
    {
        $reflection = new \ReflectionClass(\Modules\Cms\Http\PartialController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('html')->setValue($controller, new HtmlSanitizer());
        $saved = $reflection->getMethod('normalizePayload')->invoke($controller, 'dynamic', [
            'source' => 'reviews',
            'item_blocks' => [['id' => 'layout', 'type' => 'layout', 'payload' => [
                'display' => 'grid', 'grid_cols' => '2', 'gap' => 24,
                'children' => [
                    ['id' => 'stars', 'type' => 'rating', 'payload' => ['value' => '{{ item.rating }}']],
                    ['id' => 'text', 'type' => 'text', 'payload' => ['body' => '<p>{{ item.body }}</p><script>bad</script>']],
                ],
            ]]],
        ]);
        self::assertSame('grid', $saved['item_blocks'][0]['payload']['display']);
        self::assertSame('2', $saved['item_blocks'][0]['payload']['grid_cols']);
        self::assertSame('{{ item.rating }}', $saved['item_blocks'][0]['payload']['children'][0]['payload']['value']);
        self::assertStringNotContainsString('<script>', $saved['item_blocks'][0]['payload']['children'][1]['payload']['body']);
    }

    public function testPublicOutputNeverEnablesTemplateEditing(): void
    {
        $element = new DynamicElement($this->cms(), new HtmlSanitizer());
        $payload = $element->resolve(['source' => 'reviews', 'editing_template' => 1, 'children' => [['type' => 'html']]]);
        self::assertArrayNotHasKey('editing_template', $payload);
        self::assertArrayNotHasKey('children', $payload);
    }
    public function testItemContainersRejectUnsupportedBlocksAtEveryDepth(): void
    {
        $normalize = static fn (string $type, array $payload): array => $payload;
        $incoming = [
            ['id' => 'card', 'type' => 'layout', 'payload' => ['children' => [
                ['id' => 'body', 'type' => 'text', 'payload' => ['body' => '{{ item.body }}']],
                ['id' => 'form', 'type' => 'form_field', 'payload' => []],
                ['id' => 'products', 'type' => 'products', 'payload' => []],
            ]]],
            ['id' => 'nested', 'type' => 'dynamic', 'payload' => []],
        ];
        $saved = DynamicElement::templateBlocks($incoming, $normalize);
        self::assertCount(1, $saved);
        self::assertSame('card', $saved[0]['id']);
        self::assertCount(1, $saved[0]['payload']['children']);
        self::assertSame('{{ item.body }}', $saved[0]['payload']['children'][0]['payload']['body']);
        $ordinary = DynamicElement::blocks($incoming, $normalize);
        self::assertCount(3, $ordinary[0]['payload']['children']);
        self::assertCount(2, $ordinary);
        self::assertSame('dynamic', $ordinary[1]['type']);
    }
    public function testItemStylingIsNormalizedAndKeptOutsideTheOuterDesign(): void
    {
        $payload = DynamicElement::normalize(['item_design' => [
            'padding' => 999, 'radius' => -3, 'bg_color' => '#123456', 'max_height' => 99999,
            'border_w' => 2, 'border_color' => 'javascript:alert(1)', 'unsafe' => '<script>',
        ]]);
        self::assertSame(64, $payload['item_design']['padding']);
        self::assertSame(4096, $payload['item_design']['max_height']);
        self::assertSame(0, $payload['item_design']['radius']);
        self::assertSame('#123456', $payload['item_design']['bg_color']);
        self::assertSame('ink', $payload['item_design']['border_color']);
        self::assertArrayNotHasKey('unsafe', $payload['item_design']);
        $renderer = new \Modules\Cms\Application\CmsSsgHtmlRenderer();
        $html = $renderer->renderFragment([['type' => 'dynamic', 'payload' => [
            'item_design' => ['padding' => 24, 'bg_color' => '#123456', 'max_height' => 200],
            'rendered_items' => ['<p>First</p>', '<p>Second</p>'],
        ]]]);
        self::assertSame(2, substr_count($html, 'padding:24px'));
        self::assertSame(2, substr_count($html, 'max-height:200px'));
        self::assertSame(2, substr_count($html, 'overflow-y:auto'));
        self::assertSame(2, substr_count($html, 'background-color:#123456'));
    }
    public function testItemOverflowChoicesAreValidatedAndRendered(): void
    {
        self::assertSame(1, DynamicElement::itemDesign([])['scroll_enabled']);
        self::assertSame('wrap', \Modules\Cms\Application\BlockDesignNormalizer::textOverflow(null));
        self::assertSame('wrap', \Modules\Cms\Application\BlockDesignNormalizer::textOverflow('unsafe'));
        self::assertSame(1, DynamicElement::itemDesign(['scroll_enabled' => '0'])['scroll_enabled']);
        $html = (new \Modules\Cms\Application\CmsSsgHtmlRenderer())->renderFragment([
            ['type' => 'dynamic', 'payload' => [
                'item_design' => ['max_height' => 160, 'scroll_enabled' => 0],
                'resolved_items' => [[['type' => 'text', 'payload' => ['body' => '<p>A long review</p>', 'text_overflow' => 'ellipsis']]]],
            ]],
        ]);
        self::assertStringContainsString('cms-text-overflow-ellipsis', $html);
        self::assertStringContainsString('max-height:160px', $html);
        self::assertStringContainsString('overflow-y:auto', $html);
        self::assertStringNotContainsString('overflow-y:hidden', $html);
    }
    public function testMixedPageAndItemVariablesResolveWithoutExpandingEntityDataAgain(): void
    {
        $cms = $this->cms();
        $cms->register('reviews', 'Reviews', ['body'], static fn (int $limit): array => [['body' => '{{ page.slug }} <b>data</b>']]);
        $element = new DynamicElement($cms, new HtmlSanitizer());
        $payload = $element->resolve([
            'source' => 'reviews',
            'item_template' => '<p>{{ page.slug }}: {{ item.body }}</p>',
            'item_blocks' => [['id' => 'text', 'type' => 'text', 'payload' => ['body' => '<p>{{page.slug}}: {{ item.body }}</p>']]],
        ], ['page.slug' => 'test']);
        self::assertSame('<p>test: {{ page.slug }} &lt;b&gt;data&lt;/b&gt;</p>', $payload['rendered_items'][0]);
        self::assertSame('<p>test: {{ page.slug }} &lt;b&gt;data&lt;/b&gt;</p>', $payload['resolved_items'][0][0]['payload']['body']);
    }

    public function testPageVariablesReachDynamicSourcesNestedInsideLayouts(): void
    {
        $cms = $this->cms();
        $cms->register('reviews', 'Reviews', ['body'], static fn (int $limit): array => [['body' => 'Review']]);
        $reflection = new \ReflectionClass(\Modules\Cms\Application\CmsProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('dynamicElements')->setValue($provider, new DynamicElement($cms, new HtmlSanitizer()));
        $reflection->getProperty('html')->setValue($provider, new HtmlSanitizer());
        $payload = $reflection->getMethod('publicPayload')->invoke($provider, 'layout', [
            'children' => [['type' => 'dynamic', 'payload' => [
                'source' => 'reviews', 'item_blocks' => [['id' => 'text', 'type' => 'text', 'payload' => ['body' => '<p>{{page.slug}}: {{item.body}}</p>']]],
            ]]],
        ], ['page.slug' => 'test']);
        self::assertSame('<p>test: Review</p>', $payload['children'][0]['payload']['resolved_items'][0][0]['payload']['body']);
    }
}
