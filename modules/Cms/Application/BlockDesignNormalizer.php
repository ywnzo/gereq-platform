<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

/**
 * Shared whitelist/clamp for Cms block design payload fields.
 */
final class BlockDesignNormalizer
{
    private const BG_TOKENS = ['none', 'paper', 'background', 'surface', 'header', 'ink', 'brand', 'secondary', 'accent', 'brand-soft', 'light-background', 'light-surface', 'light-header', 'dark-background', 'dark-surface', 'dark-header'];
    private const TEXT_TOKENS = ['inherit', 'ink', 'muted', 'brand', 'secondary', 'accent', 'paper', 'background', 'surface', 'header', 'light-background', 'light-surface', 'light-header', 'dark-background', 'dark-surface', 'dark-header'];
    private const BORDER_COLOR_TOKENS = ['ink', 'muted', 'brand', 'secondary', 'accent', 'paper', 'background', 'surface', 'header', 'brand-soft', 'light-background', 'light-surface', 'light-header', 'dark-background', 'dark-surface', 'dark-header'];
    private const FONT_FAMILIES = ['inherit', 'sans', 'display', 'serif', 'mono', 'system', 'arial', 'verdana', 'tahoma', 'trebuchet', 'georgia', 'times', 'palatino', 'courier', 'impact'];
    private const FONT_SIZES = ['inherit', 'sm', 'md', 'lg', 'xl', '2xl', '3xl', 'eyebrow', 'display-sm', 'display-md', 'display'];
    private const FONT_WEIGHTS = ['inherit', 'normal', 'medium', 'semibold', 'bold'];
    /** Icon pixel sizes (storefront IconToolbar). */
    private const ICON_SIZES = ['8', '16', '24', '32', '40', '48', '56', '64'];
    /** Material Symbols wght (stroke thickness). */
    private const ICON_WEIGHTS = ['100', '200', '300', '400', '500', '600', '700'];
    private const ICON_STYLES = ['outlined', 'rounded', 'sharp'];
    private const ICON_GRADES = ['-25', '0', '200'];
    private const ICON_OPSZ = ['20', '24', '40', '48'];
    /** Legacy icon size tokens → px. */
    private const ICON_SIZE_LEGACY = [
        'sm' => '16',
        'md' => '24',
        'lg' => '48',
        'xl' => '56',
    ];
    private const GALLERY_VARIANTS = [
        'grid',
        'grid-3',
        'masonry',
        'mosaic',
        'spotlight',
        'carousel',
        'carousel-peek',
        'filmstrip',
    ];

    /** Layout (container) purpose presets — mirror cms-lib layoutPresets. */
    private const LAYOUT_VARIANTS = [
        'stack',
        'row',
        'image',
        'cta',
        'grid-2',
        'grid-3',
        'hero',
        'cards',
    ];

    /**
     * @param array<string, mixed> $payload
     * @param callable(mixed): list<array<string, mixed>> $normalizeChildren
     * @return array<string, mixed>
     */
    public static function layout(array $payload, callable $normalizeChildren): array
    {
        $display = trim((string) ($payload['display'] ?? 'flex'));
        if (!in_array($display, ['flex', 'grid'], true)) {
            $display = 'flex';
        }

        $dir = trim((string) ($payload['dir'] ?? 'column'));
        if (!in_array($dir, ['row', 'column', 'row-reverse', 'column-reverse'], true)) {
            $dir = 'column';
        }

        $flexWrap = trim((string) ($payload['flex_wrap'] ?? ''));
        if (!in_array($flexWrap, ['nowrap', 'wrap', 'wrap-reverse'], true)) {
            $flexWrap = !empty($payload['wrap']) ? 'wrap' : 'nowrap';
        }

        $justifyOpts = [
            'flex-start', 'flex-end', 'center', 'space-between', 'space-around',
            'space-evenly', 'start', 'end', 'stretch', 'left', 'right',
        ];
        $alignOpts = [
            'stretch', 'flex-start', 'flex-end', 'center', 'baseline',
            'start', 'end', 'self-start', 'self-end',
        ];
        $alignContentOpts = [
            ...$alignOpts,
            'space-between', 'space-around', 'space-evenly',
        ];

        $justify = trim((string) ($payload['justify'] ?? 'flex-start'));
        if (!in_array($justify, $justifyOpts, true)) {
            $justify = 'flex-start';
        }
        $alignItems = trim((string) ($payload['align_items'] ?? 'stretch'));
        if (!in_array($alignItems, $alignOpts, true)) {
            $alignItems = 'stretch';
        }
        $alignContent = trim((string) ($payload['align_content'] ?? 'stretch'));
        if (!in_array($alignContent, $alignContentOpts, true)) {
            $alignContent = 'stretch';
        }
        $justifyItems = trim((string) ($payload['justify_items'] ?? 'stretch'));
        if (!in_array($justifyItems, $alignOpts, true)) {
            $justifyItems = 'stretch';
        }

        $flow = trim((string) ($payload['grid_auto_flow'] ?? 'row'));
        if (!in_array($flow, ['row', 'column', 'dense', 'row dense', 'column dense'], true)) {
            $flow = 'row';
        }

        return [
            'variant' => BlockDesignNormalizer::layoutVariant($payload['variant'] ?? 'stack'),
            'display' => $display,
            'dir' => $dir,
            'flex_wrap' => $flexWrap,
            'wrap' => $flexWrap === 'nowrap' ? 0 : 1,
            'gap' => max(0, min(256, (int) ($payload['gap'] ?? 16))),
            'row_gap' => self::normalizeOptionalGap($payload['row_gap'] ?? ''),
            'column_gap' => self::normalizeOptionalGap($payload['column_gap'] ?? ''),
            'justify' => $justify,
            'align_items' => $alignItems,
            'align_content' => $alignContent,
            'justify_items' => $justifyItems,
            'grid_cols' => self::normalizeGridTracks(
                (string) ($payload['grid_cols'] ?? $payload['columns'] ?? '2'),
                '2',
            ),
            'grid_rows' => self::normalizeGridTracks((string) ($payload['grid_rows'] ?? ''), ''),
            'grid_auto_flow' => $flow,
            'grid_auto_rows' => self::normalizeGridTracks((string) ($payload['grid_auto_rows'] ?? ''), ''),
            'grid_auto_cols' => self::normalizeGridTracks((string) ($payload['grid_auto_cols'] ?? ''), ''),
            'min_height' => max(0, min(1200, (int) ($payload['min_height'] ?? 0))),
            'children' => $normalizeChildren($payload['children'] ?? []),
        ];
    }

    private static function normalizeOptionalGap(mixed $value): string|int
    {
        if ($value === '' || $value === null) {
            return '';
        }

        return max(0, min(256, (int) $value));
    }

    private static function normalizeGridTracks(string $value, string $default): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($value === '') {
            return $default;
        }
        if (preg_match('/^\d{1,2}$/', $value) === 1) {
            return (string) max(1, min(12, (int) $value));
        }
        if (preg_match('/^[0-9a-zA-Z%.,\s()\/_-]+$/', $value) !== 1) {
            return $default;
        }
        if (preg_match('/expression|url\s*\(|@import|<|>/i', $value) === 1) {
            return $default;
        }

        return $value;
    }


    public static function textOverflow(mixed $value): string
    {
        return in_array($value, ['wrap', 'clip', 'ellipsis'], true) ? $value : 'wrap';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function merge(array $payload, array $raw): array
    {
        $payload = array_merge($payload, DocumentMetadata::normalize($raw));
        $payload['text_overflow'] = self::textOverflow($raw['text_overflow'] ?? 'wrap');
        $payload['text_limits'] = TextLimits::normalize($raw['text_limits'] ?? []);

        // Stable branded storefront field identity must survive editor save/publish.
        $slot = (string) ($raw['gereq_slot'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $slot) === 1) {
            $payload['gereq_slot'] = $slot;
        }

        if (isset($raw['admin_label']) && is_string($raw['admin_label'])) {
            $payload['admin_label'] = mb_substr(trim($raw['admin_label']), 0, 190);
        }

        $spacing = trim((string) ($raw['spacing'] ?? 'normal'));
        if (!in_array($spacing, ['tight', 'normal', 'loose'], true)) {
            $spacing = 'normal';
        }
        $align = trim((string) ($raw['align'] ?? 'start'));
        if (!in_array($align, ['start', 'center', 'end'], true)) {
            $align = 'start';
        }

        $payload['spacing'] = $spacing;
        $payload['align'] = $align;
        $payload['z_index'] = max(0, min(100, (int) ($raw['z_index'] ?? 1)));

        $width = trim((string) ($raw['width'] ?? $raw['img_w'] ?? '100'));
        if (!in_array($width, ['auto', '25', '33', '50', '66', '75', '100'], true)) {
            $width = '100';
        }
        $height = trim((string) ($raw['height'] ?? $raw['img_h'] ?? 'auto'));
        if (!in_array($height, ['auto', 'sm', 'md', 'lg', 'xl', 'square'], true)) {
            $height = 'auto';
        }
        $imgFit = trim((string) ($raw['img_fit'] ?? 'cover'));
        if (!in_array($imgFit, ['cover', 'contain', 'fill'], true)) {
            $imgFit = 'cover';
        }
        $ratio = trim((string) ($raw['ratio'] ?? $raw['ration'] ?? ''));
        if (!in_array($ratio, ['1/1', '4/3', '3/2', '16/9', '21/9'], true)) {
            $ratio = '';
        }
        $line = trim((string) ($raw['line'] ?? 'solid'));
        if (!in_array($line, ['solid', 'dashed', 'dotted'], true)) {
            $line = 'solid';
        }
        $thickness = max(1, min(4, (int) ($raw['thickness'] ?? 1)));

        $payload['width'] = $width;
        $payload['height'] = $height;
        $payload['img_fit'] = $imgFit;
        foreach (['image_width', 'image_height', 'gallery_thumb_width', 'gallery_thumb_height', 'image_zoom_width', 'image_zoom_height'] as $key) {
            $maximum = str_starts_with($key, 'gallery_thumb') ? 512 : 4096;
            $payload[$key] = max(0, min($maximum, (int) ($raw[$key] ?? $payload[$key] ?? 0)));
        }
        if (array_key_exists('media_ids', $payload)) {
            $payload['gallery_items'] = GalleryImageSettingsNormalizer::normalize($raw['gallery_items'] ?? $payload['gallery_items'] ?? '{}');
        }
        $filmSpeed = (int) ($raw['film_speed'] ?? $payload['film_speed'] ?? 40);
        $payload['film_speed'] = max(8, min(180, $filmSpeed > 0 ? $filmSpeed : 40));
        $payload['ratio'] = $ratio;
        $payload['line'] = $line;
        $payload['thickness'] = $thickness;

        $visible = (int) ($raw['visible'] ?? 1);
        $payload['visible'] = $visible === 0 ? 0 : 1;
        $payload['opacity'] = max(0, min(100, (int) ($raw['opacity'] ?? 100)));
        $payload['bg_color'] = self::normalizeColor((string) ($raw['bg_color'] ?? 'none'), self::BG_TOKENS, 'none');
        $payload['text_color'] = self::normalizeColor((string) ($raw['text_color'] ?? 'inherit'), self::TEXT_TOKENS, 'inherit');
        $payload['hover_color'] = self::normalizeColor((string) ($raw['hover_color'] ?? 'inherit'), self::TEXT_TOKENS, 'inherit');
        $payload['border_w'] = max(0, min(8, (int) ($raw['border_w'] ?? 0)));
        $borderStyle = trim((string) ($raw['border_style'] ?? 'solid'));
        $payload['border_style'] = in_array($borderStyle, ['solid', 'dashed', 'dotted'], true) ? $borderStyle : 'solid';
        $payload['border_color'] = self::normalizeColor((string) ($raw['border_color'] ?? 'ink'), self::BORDER_COLOR_TOKENS, 'ink');
        if (array_key_exists('quote_border_color', $raw)) {
            $payload['quote_border_color'] = self::normalizeColor((string) $raw['quote_border_color'], ['none', ...self::BORDER_COLOR_TOKENS], 'accent');
        }
        $shadow = trim((string) ($raw['shadow'] ?? 'none'));
        $payload['shadow'] = in_array($shadow, ['none', 'soft', 'strong'], true) ? $shadow : 'none';
        $payload['radius'] = max(0, min(50, (int) ($raw['radius'] ?? 0)));
        $payload['padding'] = max(0, min(64, (int) ($raw['padding'] ?? 0)));
        foreach (['margin' => 256, 'margin_top' => 256, 'margin_right' => 256, 'margin_bottom' => 256, 'margin_left' => 256, 'padding_top' => 512, 'padding_right' => 512, 'padding_bottom' => 512, 'padding_left' => 512] as $key => $maximum) {
            if (isset($raw[$key]) && $raw[$key] !== '' && is_numeric($raw[$key])) {
                $payload[$key] = max(0, min($maximum, (int) $raw[$key]));
            } else {
                unset($payload[$key]);
            }
        }


        $fontFamily = trim((string) ($raw['font_family'] ?? 'inherit'));
        $payload['font_family'] = in_array($fontFamily, self::FONT_FAMILIES, true) ? $fontFamily : 'inherit';
        $fontSize = trim((string) ($raw['font_size'] ?? 'inherit'));
        $payload['font_size'] = in_array($fontSize, self::FONT_SIZES, true) ? $fontSize : 'inherit';
        $fontWeight = trim((string) ($raw['font_weight'] ?? 'inherit'));
        $payload['font_weight'] = in_array($fontWeight, self::FONT_WEIGHTS, true) ? $fontWeight : 'inherit';

        // Flex / grid item props (effective when parent is a layout container).
        $alignSelf = trim((string) ($raw['align_self'] ?? ''));
        $payload['align_self'] = in_array($alignSelf, [
            '', 'auto', 'stretch', 'flex-start', 'flex-end', 'center', 'baseline', 'start', 'end', 'self-start', 'self-end',
        ], true) ? $alignSelf : '';
        $justifySelf = trim((string) ($raw['justify_self'] ?? ''));
        $payload['justify_self'] = in_array($justifySelf, [
            '', 'auto', 'stretch', 'flex-start', 'flex-end', 'center', 'start', 'end', 'self-start', 'self-end',
        ], true) ? $justifySelf : '';

        $flexGrow = trim((string) ($raw['flex_grow'] ?? ''));
        $payload['flex_grow'] = preg_match('/^-?\d+(\.\d+)?$/', $flexGrow) === 1 ? $flexGrow : '';
        $flexShrink = trim((string) ($raw['flex_shrink'] ?? ''));
        $payload['flex_shrink'] = preg_match('/^-?\d+(\.\d+)?$/', $flexShrink) === 1 ? $flexShrink : '';
        $flexBasis = trim((string) ($raw['flex_basis'] ?? ''));
        $payload['flex_basis'] = preg_match(
            '/^(\d+(\.\d+)?(px|%|rem|em|fr)?|auto|content|max-content|min-content)?$/',
            $flexBasis,
        ) === 1 ? $flexBasis : '';

        $order = trim((string) ($raw['order'] ?? ''));
        if ($order !== '' && is_numeric($order)) {
            $payload['order'] = (string) max(-20, min(20, (int) $order));
        } else {
            $payload['order'] = '';
        }

        $gridColumn = trim((string) ($raw['grid_column'] ?? $raw['grid_col'] ?? ''));
        $payload['grid_column'] = self::normalizeGridLine($gridColumn);
        $gridRow = trim((string) ($raw['grid_row'] ?? ''));
        $payload['grid_row'] = self::normalizeGridLine($gridRow);

        return $payload;
    }

    /** Span N or safe grid line string (e.g. "1 / -1"). */
    private static function normalizeGridLine(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d{1,2}$/', $value) === 1) {
            return (string) max(1, min(12, (int) $value));
        }
        if (preg_match('/^[0-9a-zA-Z\s\/-]+$/', $value) !== 1) {
            return '';
        }

        return $value;
    }

    /**
     * @param list<string> $tokens
     */
    public static function normalizeColor(string $value, array $tokens, string $default): string
    {
        $value = trim($value);
        if ($value === '') {
            return $default;
        }
        if (in_array($value, $tokens, true)) {
            return $value;
        }
        $hex = self::normalizeHex($value);

        return $hex ?? $default;
    }

    public static function normalizeHex(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) !== 1) {
            return null;
        }
        if (strlen($value) === 4) {
            return sprintf(
                '#%s%s%s%s%s%s',
                $value[1],
                $value[1],
                $value[2],
                $value[2],
                $value[3],
                $value[3],
            );
        }

        return strtolower($value);
    }

    /** @return list<string> */
    public static function bgTokens(): array
    {
        return self::BG_TOKENS;
    }

    /** @return list<string> */
    public static function textTokens(): array
    {
        return self::TEXT_TOKENS;
    }

    /** @return list<string> */
    public static function galleryVariants(): array
    {
        return self::GALLERY_VARIANTS;
    }

    public static function galleryVariant(mixed $value): string
    {
        $variant = trim((string) ($value ?? 'grid'));

        return in_array($variant, self::GALLERY_VARIANTS, true) ? $variant : 'grid';
    }

    /** @return list<string> */
    public static function layoutVariants(): array
    {
        return self::LAYOUT_VARIANTS;
    }

    public static function layoutVariant(mixed $value): string
    {
        $variant = trim((string) ($value ?? 'stack'));

        return in_array($variant, self::LAYOUT_VARIANTS, true) ? $variant : 'stack';
    }

    /** Normalize icon size to px string (8…64); maps legacy sm/md/lg/xl. */
    public static function normalizeIconSize(mixed $value): string
    {
        $size = trim((string) ($value ?? '24'));
        if (isset(self::ICON_SIZE_LEGACY[$size])) {
            return self::ICON_SIZE_LEGACY[$size];
        }
        if (in_array($size, self::ICON_SIZES, true)) {
            return $size;
        }

        return '24';
    }

    /** Normalize Material Symbols wght (100…700, steps of 100). */
    public static function normalizeIconWeight(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '400';
        }
        $n = (int) round(((int) $value) / 100) * 100;

        return (string) max(100, min(700, $n));
    }

    public static function normalizeIconStyle(mixed $value): string
    {
        $style = strtolower(trim((string) ($value ?? 'outlined')));
        if ($style === 'round') {
            $style = 'rounded';
        }
        if (in_array($style, self::ICON_STYLES, true)) {
            return $style;
        }

        return 'outlined';
    }

    public static function normalizeIconFill(mixed $value): string
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            return '1';
        }

        return '0';
    }

    public static function normalizeIconGrade(mixed $value): string
    {
        $grade = trim((string) ($value ?? '0'));
        if (in_array($grade, self::ICON_GRADES, true)) {
            return $grade;
        }
        if (is_numeric($grade)) {
            $n = (int) $grade;

            return (string) max(-50, min(200, $n));
        }

        return '0';
    }

    public static function normalizeIconOpsz(mixed $value): string
    {
        $opsz = trim((string) ($value ?? ''));
        if ($opsz === '') {
            return '';
        }
        if (in_array($opsz, self::ICON_OPSZ, true)) {
            return $opsz;
        }
        if (is_numeric($opsz)) {
            $n = max(20, min(48, (int) $opsz));
            $best = self::ICON_OPSZ[0];
            $bestDist = PHP_INT_MAX;
            foreach (self::ICON_OPSZ as $candidate) {
                $dist = abs($n - (int) $candidate);
                if ($dist < $bestDist) {
                    $best = $candidate;
                    $bestDist = $dist;
                }
            }

            return $best;
        }

        return '';
    }

    /** @return list<string> */
    public static function iconSizes(): array
    {
        return self::ICON_SIZES;
    }

    /** @return list<string> */
    public static function iconWeights(): array
    {
        return self::ICON_WEIGHTS;
    }
}
