<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

/**
 * Builds a semantic HTML snapshot from a serialized CMS page for storefront SSG inject.
 * Not a full Vue clone — enough for crawlers / first paint text + images.
 */
final class CmsSsgHtmlRenderer
{
    /** @param list<array<string, mixed>> $blocks */
    public function renderFragment(array $blocks): string
    {
        return implode('', array_map($this->renderBlock(...), $blocks));
    }

    /**
     * @param array<string, mixed> $page serializePage(..., withBlocks: true) shape
     */
    public function render(array $page): string
    {
        $title = trim((string) ($page['title'] ?? ''));
        $slug = trim((string) ($page['slug'] ?? ''));
        $parts = [];
        $parts[] = '<article class="gereq-ssg" data-gereq-ssg="1" data-cms-slug="'
            . $this->escAttr($slug) . '">';
        if ($title !== '') {
            $parts[] = '<h1 class="gereq-ssg__title">' . $this->esc($title) . '</h1>';
        }

        $mode = (string) ($page['content_mode'] ?? 'blocks');
        if ($mode === 'raw') {
            $body = trim((string) ($page['body'] ?? ''));
            if ($body !== '') {
                $parts[] = '<div class="gereq-ssg__raw">' . (new \Gereq\Security\HtmlSanitizer())->sanitize($body) . '</div>';
            }
        } else {
            $blocks = is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $html = $this->renderBlock($block);
                if ($html !== '') {
                    $parts[] = $html;
                }
            }
        }

        $parts[] = '</article>';

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderBlock(array $block): string
    {
        $type = (string) ($block['type'] ?? '');
        $payload = TextLimits::apply($type, is_array($block['payload'] ?? null) ? $block['payload'] : []);
        // The static renderer is a final output boundary, including legacy snapshots.
        $sanitizer = new \Gereq\Security\HtmlSanitizer();
        $htmlFields = match ($type) {
            'heading' => ['text'],
            'text' => ['body'],
            'html' => ['html'],
            'columns' => ['left', 'right'],
            'table' => ['html', 'caption'],
            default => [],
        };
        foreach ($htmlFields as $field) {
            if (isset($payload[$field]) && is_string($payload[$field])) {
                $payload[$field] = $sanitizer->sanitize($payload[$field]);
            }
        }


        $html = match ($type) {
            'dynamic' => $this->renderDynamic($payload),
            'widget' => $this->renderWidget($payload),
            'avatar', 'badge' => $this->renderVisual($type, $payload),
            'rating' => $this->renderRating($payload),
            'heading' => $this->renderHeading($payload),
            'text', 'html' => $this->renderProse($payload, $type),
            'image' => $this->renderImage($payload),
            'gallery' => $this->renderGallery($payload),
            'cta' => $this->renderCta($payload),
            'quote' => $this->renderQuote($payload),
            'video' => $this->renderVideo($payload),
            'icon' => $this->renderIcon($payload),
            'table' => $this->renderTable($payload),
            'divider' => '<hr class="gereq-ssg__divider">',
            'layout' => $this->renderChildren($payload),
            'columns' => $this->renderColumns($payload),
            default => '',
        };
        $overflow = BlockDesignNormalizer::textOverflow($payload['text_overflow'] ?? 'wrap');
        if ($overflow !== 'wrap' && in_array($type, ['heading', 'text', 'html', 'badge', 'cta', 'quote', 'image', 'video', 'table', 'columns'], true)) {
            $html = '<div class="cms-text-overflow-' . $overflow . '">' . $html . '</div>';
        }
        $styles = [];
        $hasSpacingOverride = false;
        foreach (['margin', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left'] as $key) {
            $hasSpacingOverride = $hasSpacingOverride || (isset($payload[$key]) && $payload[$key] !== '' && is_numeric($payload[$key]));
        }
        $classes = [];
        $design = BlockDesignNormalizer::merge([], $payload);
        foreach (['bg_color' => ['background-color', 'cms-bg-'], 'text_color' => ['color', 'cms-text-'], 'border_color' => ['border-color', 'cms-border-']] as $key => [$property, $prefix]) {
            if (!isset($payload[$key])) {
                continue;
            }
            $color = $design[$key];
            if (str_starts_with($color, '#')) {
                $styles[] = $property . ':' . $color;
            } elseif (!in_array($color, ['none', 'inherit'], true)) {
                $classes[] = $prefix . $color;
            }
        }
        if (!$hasSpacingOverride && $classes === [] && $styles === []) {
            return $html;
        }
        foreach (['margin' => 256, 'padding' => 64] as $kind => $maximum) {
            foreach ([$kind, $kind . '_top', $kind . '_right', $kind . '_bottom', $kind . '_left'] as $key) {
                if (isset($payload[$key]) && $payload[$key] !== '' && is_numeric($payload[$key])) {
                    $limit = $kind === 'padding' && $key !== 'padding' ? 512 : $maximum;
                    $styles[] = str_replace('_', '-', $key) . ':' . max(0, min($limit, (int) $payload[$key])) . 'px';
                }
            }
        }
        if ($html !== '' && ($styles !== [] || $classes !== [])) {
            $attributes = $classes === [] ? '' : ' class="' . $this->escAttr(implode(' ', $classes)) . '"';
            $attributes .= $styles === [] ? '' : ' style="' . $this->escAttr(implode(';', $styles)) . '"';
            return '<div' . $attributes . '>' . $html . '</div>';
        }
        return $html;
    }

    /** @param array<string, mixed> $payload */
    private function renderWidget(array $payload): string
    {
        $p = IntegrationWidget::normalize($payload);
        if ($p['url'] === '') {
            return '';
        }
        return '<iframe class="cms-integration-widget" src="' . $this->escAttr($p['url']) . '" title="' . $this->escAttr($p['title'] ?: $p['provider']) . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" sandbox="allow-scripts allow-same-origin allow-forms allow-popups" style="display:block;width:100%;height:' . $p['widget_height'] . 'px;border:0"></iframe>';
    }

    /** @param array<string, mixed> $payload */
    private function renderVisual(string $type, array $payload): string
    {
        $p = VisualElement::normalize($type, $payload);
        if ($type === 'badge') {
            return '<span class="cms-badge cms-badge--' . $p['tone'] . '">' . $this->esc($p['label']) . '</span>';
        }
        $words = preg_split('/\s+/u', trim($p['name']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = $p['initials'] ?: mb_strtoupper(implode('', array_map(static fn (string $word): string => mb_substr($word, 0, 1), array_slice($words, 0, 2))));
        $size = $p['avatar_size'];
        $radius = $p['shape'] === 'square' ? '20%' : '50%';
        $content = $p['url'] !== '' && !str_contains($p['url'], '{{')
            ? '<img src="' . $this->escAttr($p['url']) . '" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover">'
            : $this->esc($initials ?: '?');
        return '<span class="cms-avatar" role="img" aria-label="' . $this->escAttr($p['name']) . '" style="display:inline-grid;place-items:center;overflow:hidden;width:' . $size . 'px;height:' . $size . 'px;border-radius:' . $radius . '">' . $content . '</span>';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderRating(array $payload): string
    {
        $rating = max(0, min(5, (int) round((float) ($payload['value'] ?? 0))));
        return '<span role="img" aria-label="' . $rating . ' / 5">' . str_repeat('★', $rating) . str_repeat('☆', 5 - $rating) . '</span>';
    }

    /** @param array<string, mixed> $payload */
    private function renderDynamic(array $payload): string
    {
        $resolved = is_array($payload['resolved_items'] ?? null) ? $payload['resolved_items'] : [];
        $items = $resolved !== [] ? array_map($this->renderFragment(...), $resolved) : (is_array($payload['rendered_items'] ?? null) ? $payload['rendered_items'] : []);
        if ($items === []) {
            return '<p>' . $this->esc((string) ($payload['empty_text'] ?? '')) . '</p>';
        }
        $variant = $payload['variant'] ?? 'grid';
        $style = $variant === 'carousel'
            ? 'display:flex;overflow-x:auto;scroll-snap-type:x mandatory;gap:1.5rem'
            : 'display:grid;gap:1.5rem;grid-template-columns:' . ($variant === 'list' ? '1fr' : 'repeat(auto-fit,minmax(min(100%,260px),1fr))');
        $html = '<div class="cms-dynamic" tabindex="0" style="' . $style . '">';
        $itemAttributes = $this->dynamicItemAttributes($payload['item_design'] ?? [], $variant === 'carousel');
        foreach ($items as $item) {
            if (is_string($item)) {
                $html .= '<div' . $itemAttributes . '>' . ($resolved !== [] ? $item : (new \Gereq\Security\HtmlSanitizer())->sanitize($item)) . '</div>';
            }
        }
        return $html . '</div>';
    }

    private function dynamicItemAttributes(mixed $incoming, bool $carousel): string
    {
        $design = DynamicElement::itemDesign($incoming);
        $classes = ['cms-dynamic__item'];
        $styles = $carousel ? ['flex:0 0 min(85%,360px)', 'scroll-snap-align:start'] : [];
        if ($design['max_height'] > 0) {
            $styles[] = 'max-height:' . $design['max_height'] . 'px';
            $styles[] = 'overflow-y:auto';
            $styles[] = 'box-sizing:border-box';
        }
        foreach (['padding' => 'padding', 'radius' => 'border-radius', 'border_w' => 'border-width'] as $key => $property) {
            if ($design[$key] > 0) {
                $styles[] = $property . ':' . $design[$key] . ($key === 'radius' ? '%' : 'px');
            }
        }
        foreach (['bg_color' => ['background-color', 'cms-bg-'], 'text_color' => ['color', 'cms-text-'], 'border_color' => ['border-color', 'cms-border-']] as $key => [$property, $prefix]) {
            $color = $design[$key];
            if (str_starts_with($color, '#')) {
                $styles[] = $property . ':' . $color;
            } elseif (!in_array($color, ['none', 'inherit'], true)) {
                $classes[] = $prefix . $color;
            }
        }
        if ($design['border_w'] > 0) {
            $styles[] = 'border-style:' . $design['border_style'];
        }
        if ($design['shadow'] !== 'none') {
            $classes[] = 'cms-shadow-' . $design['shadow'];
        }
        return ' class="' . $this->escAttr(implode(' ', $classes)) . '" style="' . $this->escAttr(implode(';', $styles)) . '"';
    }

    private function renderHeading(array $payload): string
    {
        $text = trim((string) ($payload['text'] ?? ''));
        if ($text === '') {
            return '';
        }
        if (preg_match('/<(?:p|div|h[1-6]|blockquote|ul|ol)\b/i', $text) === 1) {
            return '<div class="gereq-ssg__heading">' . $text . '</div>';
        }
        $level = (int) ($payload['level'] ?? 2);
        $tag = $level <= 1 ? 'h1' : ($level === 2 ? 'h2' : 'h3');

        return '<' . $tag . ' class="gereq-ssg__heading">' . $text . '</' . $tag . '>';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderProse(array $payload, string $type): string
    {
        $html = trim((string) ($type === 'html' ? ($payload['html'] ?? '') : ($payload['body'] ?? '')));
        if ($html === '') {
            return '';
        }

        return '<div class="gereq-ssg__prose">' . (new \Gereq\Security\HtmlSanitizer())->sanitize($html) . '</div>';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderImage(array $payload): string
    {
        $src = trim((string) ($payload['src'] ?? $payload['url'] ?? ''));
        if ($src === '') {
            return '';
        }
        $alt = trim((string) ($payload['alt'] ?? ''));
        $srcset = trim((string) ($payload['srcset'] ?? ''));
        $sizes = trim((string) ($payload['sizes'] ?? ''));
        // Builder stores intrinsic pixels in image_width/image_height; older payloads use width/height.
        $w = $this->dimension($payload['image_width'] ?? $payload['width'] ?? null);
        $h = $this->dimension($payload['image_height'] ?? $payload['height'] ?? null);

        $attrs = 'src="' . $this->escAttr($src) . '" alt="' . $this->escAttr($alt) . '" loading="eager" decoding="async"';
        if ($srcset !== '') {
            $attrs .= ' srcset="' . $this->escAttr($srcset) . '"';
        }
        if ($sizes !== '') {
            $attrs .= ' sizes="' . $this->escAttr($sizes) . '"';
        }
        if ($w !== null && $w > 0) {
            $attrs .= ' width="' . $w . '"';
        }
        if ($h !== null && $h > 0) {
            $attrs .= ' height="' . $h . '"';
        }

        $caption = trim((string) ($payload['caption'] ?? ''));
        $img = '<img ' . $attrs . '>';
        if ($caption === '') {
            return '<figure class="gereq-ssg__figure">' . $img . '</figure>';
        }

        return '<figure class="gereq-ssg__figure">' . $img
            . '<figcaption>' . $this->esc($caption) . '</figcaption></figure>';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderGallery(array $payload): string
    {
        $images = is_array($payload['images'] ?? null) ? $payload['images'] : [];
        if ($images === []) {
            return '';
        }
        $items = [];
        foreach ($images as $i => $image) {
            if (!is_array($image)) {
                continue;
            }
            $src = trim((string) ($image['src'] ?? $image['url'] ?? ''));
            if ($src === '') {
                continue;
            }
            $alt = trim((string) ($image['alt'] ?? ''));
            $srcset = trim((string) ($image['srcset'] ?? ''));
            $sizes = trim((string) ($image['sizes'] ?? ''));
            $attrs = 'src="' . $this->escAttr($src) . '" alt="' . $this->escAttr($alt) . '"'
                . ($i === 0 ? ' loading="eager" fetchpriority="high"' : ' loading="lazy"')
                . ' decoding="async"';
            if ($srcset !== '') {
                $attrs .= ' srcset="' . $this->escAttr($srcset) . '"';
            }
            if ($sizes !== '') {
                $attrs .= ' sizes="' . $this->escAttr($sizes) . '"';
            }
            $items[] = '<li><img ' . $attrs . '></li>';
        }
        if ($items === []) {
            return '';
        }

        return '<ul class="gereq-ssg__gallery">' . implode('', $items) . '</ul>';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderCta(array $payload): string
    {
        $label = trim((string) ($payload['label'] ?? $payload['text'] ?? ''));
        $href = ContentSecurity::link((string) ($payload['url'] ?? $payload['href'] ?? ''));
        if ($label === '') {
            return '';
        }
        if ($href === '') {
            return '<p class="gereq-ssg__cta">' . $this->esc($label) . '</p>';
        }

        return '<p class="gereq-ssg__cta"><a href="' . $this->escAttr($href) . '">'
            . $this->esc($label) . '</a></p>';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderQuote(array $payload): string
    {
        // Builder + storefront renderer use `quote` (see CmsBlocks.ts / cmsBuilder.ts).
        $text = trim((string) ($payload['quote'] ?? $payload['text'] ?? $payload['body'] ?? ''));
        if ($text === '') {
            return '';
        }
        $cite = trim((string) ($payload['author'] ?? $payload['cite'] ?? ''));
        $role = trim((string) ($payload['role'] ?? ''));
        $cite = implode(' · ', array_filter([$cite, $role], static fn (string $part): bool => $part !== ''));
        $html = '<blockquote class="gereq-ssg__quote"><p>' . str_replace(["\r\n", "\r", "\n"], '<br>', $this->esc($text)) . '</p>';
        if ($cite !== '') {
            $html .= '<cite>' . $this->esc($cite) . '</cite>';
        }

        return $html . '</blockquote>';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderVideo(array $payload): string
    {
        $url = ContentSecurity::link((string) ($payload['url'] ?? $payload['src'] ?? ''));
        if ($url === '') {
            return '';
        }

        return '<p class="gereq-ssg__video"><a href="' . $this->escAttr($url) . '">'
            . $this->esc($url) . '</a></p>';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderIcon(array $payload): string
    {
        $name = trim((string) ($payload['name'] ?? ''));
        // Icons are decorative: only a human title is content, the glyph name is not.
        $label = trim((string) ($payload['title'] ?? $payload['label'] ?? $payload['text'] ?? ''));
        if ($label === '') {
            return '';
        }

        return '<p class="gereq-ssg__icon" data-icon="' . $this->escAttr($name) . '">'
            . $this->esc($label) . '</p>';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderChildren(array $payload): string
    {
        $children = is_array($payload['children'] ?? null) ? $payload['children'] : [];
        if ($children === []) {
            return '';
        }
        $parts = ['<div class="gereq-ssg__layout">'];
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            // Nested blocks may be full block shapes or bare payloads with type.
            if (!isset($child['type']) && isset($child['payload'])) {
                continue;
            }
            if (!isset($child['payload']) && isset($child['type'])) {
                $child = ['type' => $child['type'], 'payload' => $child];
            }
            $html = $this->renderBlock($child);
            if ($html !== '') {
                $parts[] = $html;
            }
        }
        $parts[] = '</div>';

        return count($parts) > 2 ? implode("\n", $parts) : '';
    }

    /** @param array<string, mixed> $payload */
    private function renderColumns(array $payload): string
    {
        $left = trim((string) ($payload['left'] ?? ''));
        $right = trim((string) ($payload['right'] ?? ''));
        if ($left === '' && $right === '') {
            return $this->renderChildren($payload);
        }

        $parts = ['<div class="gereq-ssg__columns">'];
        if ($left !== '') {
            $parts[] = '<div class="gereq-ssg__col">' . $left . '</div>';
        }
        if ($right !== '') {
            $parts[] = '<div class="gereq-ssg__col">' . $right . '</div>';
        }
        $parts[] = '</div>';

        return implode("\n", $parts);
    }

    /** @param array<string, mixed> $payload */
    private function renderTable(array $payload): string
    {
        $html = trim((string) ($payload['html'] ?? ''));
        if ($html === '') {
            return '';
        }

        $out = '';
        $caption = trim((string) ($payload['caption'] ?? ''));
        if ($caption !== '' && (string) ($payload['caption_visible'] ?? '1') !== '0') {
            $out .= '<p class="gereq-ssg__caption">' . $caption . '</p>';
        }

        return $out . '<div class="gereq-ssg__table">' . $html . '</div>';
    }

    /** Intrinsic pixels only (mirrors CmsImageViewer::mediaDimension); invalid → null. */
    private function dimension(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $parsed = (int) round((float) $value);
        if ($parsed <= 0 || $parsed > 4096) {
            return null;
        }

        return $parsed;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escAttr(string $value): string
    {
        return $this->esc($value);
    }
}
