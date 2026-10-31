<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

use Gereq\Cms\Cms;
use Gereq\Security\HtmlSanitizer;

final class DynamicElement
{
    public function __construct(private readonly Cms $cms, private readonly HtmlSanitizer $html)
    {
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function normalize(array $payload): array
    {
        return [
            'source' => substr(trim((string) ($payload['source'] ?? '')), 0, 120),
            'limit' => max(1, min(100, (int) ($payload['limit'] ?? 12))),
            'variant' => in_array($payload['variant'] ?? '', ['grid', 'list', 'carousel'], true) ? $payload['variant'] : 'grid',
            'item_template' => substr((string) ($payload['item_template'] ?? ''), 0, 50000),
            'empty_text' => substr((string) ($payload['empty_text'] ?? ''), 0, 500),
            'item_design' => self::itemDesign($payload['item_design'] ?? []),
        ];
    }

    /** @return array<string, mixed> */
    public static function itemDesign(mixed $incoming): array
    {
        $normalized = BlockDesignNormalizer::merge([], is_array($incoming) ? $incoming : []);
        $normalized['max_height'] = max(0, min(4096, (int) (is_array($incoming) ? ($incoming['max_height'] ?? 0) : 0)));
        $normalized['scroll_enabled'] = 1;
        return array_intersect_key($normalized, array_flip([
            'padding', 'radius', 'bg_color', 'text_color', 'border_w', 'border_style', 'border_color', 'shadow', 'max_height', 'scroll_enabled',
        ]));
    }

    /** @param array<string, mixed> $payload
     * @param array<string, string> $variables
     * @return array<string, mixed>
     */
    public function resolve(array $payload, array $variables = []): array
    {
        $config = self::normalize($payload);
        $items = $this->cms->items($config['source'], $config['limit']);
        $payload = array_merge($payload, $config);
        unset($payload['editing_template'], $payload['source_label'], $payload['children']);
        // Stored or client-supplied rendered data must never override the registered source.
        $blocks = is_array($payload['item_blocks'] ?? null) ? $payload['item_blocks'] : [];
        $payload['resolved_items'] = $blocks === [] ? [] : array_map(fn (array $item): array => $this->bindBlocks($blocks, $item, $variables), $items);
        $payload['rendered_items'] = array_map(fn (array $item): string => $this->renderItem($config['item_template'], $item, $variables), $items);
        return $payload;
    }

    /**
     * @param mixed $incoming
     * @param callable(string, array<string, mixed>): array<string, mixed> $normalize
     * @return list<array<string, mixed>>
     */
    public static function blocks(mixed $incoming, callable $normalize, int $depth = 0): array
    {
        if (!is_array($incoming) || $depth >= 12) {
            return [];
        }
        $blocks = [];
        foreach (array_slice($incoming, 0, 100) as $block) {
            if (!is_array($block) || !in_array($block['type'] ?? '', \Modules\Cms\Domain\PageBlock::TYPES, true)) {
                continue;
            }
            $blocks[] = [
                'id' => substr((string) ($block['id'] ?? ''), 0, 120),
                'type' => $block['type'],
                'payload' => $normalize($block['type'], is_array($block['payload'] ?? null) ? $block['payload'] : []),
            ];
        }
        return $blocks;
    }

    /**
     * Normalize supported item blocks without restricting ordinary page layouts.
     * @param mixed $incoming
     * @param callable(string, array<string, mixed>): array<string, mixed> $normalize
     * @return list<array<string, mixed>>
     */
    public static function templateBlocks(mixed $incoming, callable $normalize, int $depth = 0): array
    {
        return self::supportedBlocks(self::blocks($incoming, $normalize, $depth));
    }

    /** @param list<array<string, mixed>> $blocks @return list<array<string, mixed>> */
    private static function supportedBlocks(array $blocks): array
    {
        $supported = ['text', 'heading', 'html', 'layout', 'image', 'icon', 'avatar', 'badge', 'rating',
            'cta', 'quote', 'spacer', 'divider', 'table', 'gallery', 'video', 'columns', 'widget'];
        $out = [];
        foreach ($blocks as $block) {
            if (!in_array($block['type'] ?? '', $supported, true)) {
                continue;
            }
            if (is_array($block['payload']['children'] ?? null)) {
                $block['payload']['children'] = self::supportedBlocks($block['payload']['children']);
            }
            $out[] = $block;
        }
        return $out;
    }

    /** @param array<mixed> $blocks
     * @param array<string, mixed> $item
     * @param array<string, string> $variables
     * @return list<array<string, mixed>>
     */
    private function bindBlocks(array $blocks, array $item, array $variables = [], int $depth = 0): array
    {
        if ($depth >= 12) {
            return [];
        }
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block) || ($block['type'] ?? '') === 'dynamic') {
                continue;
            }
            $payload = is_array($block['payload'] ?? null) ? $block['payload'] : [];
            foreach ($payload as $key => $value) {
                if (is_string($value)) {
                    $payload[$key] = in_array($key, ['text', 'body', 'html'], true)
                        ? $this->renderItem($value, $item, $variables)
                        : $this->interpolate($value, $item, false, $variables);
                }
            }
            if (is_array($payload['children'] ?? null)) {
                $payload['children'] = $this->bindBlocks($payload['children'], $item, $variables, $depth + 1);
            }
            $out[] = ['id' => (string) ($block['id'] ?? ''), 'type' => (string) ($block['type'] ?? ''), 'payload' => $payload];
        }
        return $out;
    }

    /** @param array<string, mixed> $item
     * @param array<string, string> $variables
     */
    private function interpolate(string $template, array $item, bool $escape, array $variables = []): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', static function (array $match) use ($item, $escape, $variables): string {
            $path = $match[1];
            if (str_starts_with($path, 'item.')) {
                $value = $item;
                foreach (explode('.', substr($path, 5)) as $key) {
                    $value = is_array($value) ? ($value[$key] ?? null) : null;
                }
            } elseif (array_key_exists($path, $variables)) {
                $value = $variables[$path];
            } else {
                return $match[0];
            }
            if (!is_scalar($value)) {
                return '';
            }
            return $escape ? htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : (string) $value;
        }, $template) ?? '';
    }

    /** @param array<string, mixed> $item
     * @param array<string, string> $variables
     */
    public function renderItem(string $template, array $item, array $variables = []): string
    {
        $rendered = $this->interpolate($template, $item, true, $variables);
        // Sanitize after interpolation, including URLs and styles containing variables.
        return $this->html->sanitize($rendered);
    }
}
