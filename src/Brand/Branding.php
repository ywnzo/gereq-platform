<?php

declare(strict_types=1);

namespace Gereq\Brand;

use Gereq\Setting\Settings;

/**
 * White-label branding for admin UI and public storefront.
 * Values come from settings; empty fields fall back to sensible defaults.
 */
final class Branding
{
    public const DEFAULT_LOGO = '/brand/icons/favicon.svg';
    public const DEFAULT_SIGNAL = '#d7ff45';
    public const DEFAULT_INK = '#0a0b0a';
    public const DEFAULT_CREAM = '#f5f5ef';
    public const DEFAULT_SITE_PRIMARY = '#17375a';
    public const ADMIN_FONTS = [
        'default' => '"IBM Plex Sans", ui-sans-serif, system-ui, sans-serif',
        'system' => 'system-ui, sans-serif',
        'serif' => 'Georgia, "DejaVu Serif", serif',
        'rounded' => '"Trebuchet MS", "DejaVu Sans", sans-serif',
    ];

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @return array{
     *   app_name: string,
     *   name: string,
     *   subtitle: string,
     *   logo_url: string,
     *   logo_custom: bool,
     *   favicon_url: string,
     *   palette_active: bool,
     *   admin_motion: bool,
     *   color_signal: string,
     *   color_ink: string,
     *   color_cream: string,
     *   color_on_signal: string,
     *   site_name: string,
     *   site_mark: string,
     *   site_signal: string,
     *   site_primary: string,
     *   tagline: string,
     *   footnote: string,
     *   custom_css_admin: string,
     *   custom_css_public: string
     * }
     */
    public function resolve(): array
    {
        $appName = $this->string('app.name', (string) config('app.name', 'GEREQ Platform'));
        $name = $this->string('brand.name', '');
        if ($name === '') {
            $name = $this->shortName($appName);
        }

        $subtitle = $this->string('brand.subtitle', '');
        $customLogoRaw = $this->string('brand.logo_url', '');
        $logoCustom = $customLogoRaw !== '';
        $logo = $logoCustom ? $this->urlOrDefault($customLogoRaw, self::DEFAULT_LOGO) : self::DEFAULT_LOGO;
        $favicon = $this->urlOrDefault($this->string('brand.favicon_url', ''), $logo);
        $logo = \Gereq\Support\UrlPrefix::path($logo);
        $favicon = \Gereq\Support\UrlPrefix::path($favicon);

        $signal = $this->hex('brand.color_signal', self::DEFAULT_SIGNAL);
        $ink = $this->hex('brand.color_ink', self::DEFAULT_INK);
        $cream = $this->hex('brand.color_cream', self::DEFAULT_CREAM);
        $siteSignal = $this->hex('brand.site_signal', $signal);
        $sitePrimary = $this->hex('brand.site_primary', self::DEFAULT_SITE_PRIMARY);

        // Public site name comes from Brand (Značka); cms.site_title is legacy fallback only.
        $siteName = $this->string('brand.site_name', '');
        if ($siteName === '') {
            $siteName = $this->string('brand.name', '');
        }
        if ($siteName === '') {
            $cmsTitle = $this->string('cms.site_title', '');
            if ($cmsTitle !== '' && $cmsTitle !== 'GEREQ Site') {
                $siteName = $cmsTitle;
            } else {
                $siteName = $this->shortName($appName);
            }
        }

        $siteMark = $this->string('brand.site_mark', '');
        if ($siteMark === '') {
            $siteMark = $this->initials($siteName !== '' ? $siteName : $name);
        }

        return [
            'app_name' => $appName,
            'name' => $name,
            'subtitle' => $subtitle,
            'logo_url' => $logo,
            'logo_custom' => $logoCustom,
            'favicon_url' => $favicon,
            'palette_active' => $this->resolvedPalette() !== null,
            'admin_motion' => $this->adminAppearance()['motion'],
            'color_signal' => $signal,
            'color_ink' => $ink,
            'color_cream' => $cream,
            'color_on_signal' => $this->contrastOn($signal),
            'site_name' => $siteName,
            'site_mark' => mb_substr($siteMark, 0, 3),
            'site_signal' => $siteSignal,
            'site_primary' => $sitePrimary,
            'tagline' => $this->string('brand.tagline', ''),
            'footnote' => $this->string('brand.footnote', ''),
            'custom_css_admin' => $this->customCssAdmin(),
            'custom_css_public' => $this->customCssPublic(),
        ];
    }

    /** @return array<string, mixed> */
    public function toPublic(): array
    {
        $b = $this->resolve();

        $payload = [
            'app_name' => $b['app_name'],
            'name' => $b['name'],
            'site_name' => $b['site_name'],
            'site_mark' => $b['site_mark'],
            'logo_url' => $b['logo_custom'] ? $b['logo_url'] : null,
            'favicon_url' => $this->string('brand.favicon_url', '') !== '' || $b['logo_custom']
                ? $b['favicon_url']
                : null,
            'tagline' => $b['tagline'],
            'custom_css' => $b['custom_css_public'],
            'colors' => [
                'signal' => $b['site_signal'],
                'ink' => $b['color_ink'],
                'cream' => $b['color_cream'],
                'primary' => $b['site_primary'],
                'on_signal' => $this->contrastOn($b['site_signal']),
            ],
        ];

        $palette = $this->resolvedPalette();
        if ($palette !== null) {
            $payload['palette'] = $palette;
        }

        return $payload;
    }

    /**
     * @return array{version: int, storefront_mode: string, colors: array<string, string>, modes: array<string, array<string, string>>, warnings: list<string>}|null
     */
    public function resolvedPalette(): ?array
    {
        $value = $this->settings->get('brand.palette', []);
        if (!is_array($value) || $value === []) {
            return null;
        }

        try {
            return (new PaletteResolver())->resolve($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @return array{motion: bool, panel_opacity: int} */
    public function adminAppearance(): array
    {
        $opacity = $this->settings->has('brand.admin_panel_opacity')
            ? (int) $this->settings->get('brand.admin_panel_opacity', 100)
            : ($this->resolvedPalette() !== null ? 100 : 66);

        return [
            'motion' => (bool) $this->settings->get('brand.admin_motion', true),
            'panel_opacity' => max(0, min(100, $opacity)),
        ];
    }

    /** Inline CSS overrides for admin / auth layouts (must load after kernel.css). */
    public function adminCssVariables(): string
    {
        $lines = [':root, html, html.dark, [data-theme="gereq"], [data-theme="gereq-dark"] {'];
        $font = (string) $this->settings->get('brand.admin_font', 'default');
        if ($font !== 'default' && isset(self::ADMIN_FONTS[$font])) {
            $lines[] = '--gereq-font-sans: ' . self::ADMIN_FONTS[$font] . ' !important;';
            $lines[] = '--gereq-font-display: ' . self::ADMIN_FONTS[$font] . ' !important;';
        }
        foreach ($this->cssVariableMap() as $name => $value) {
            $lines[] = "{$name}: {$value} !important;";
        }
        $lines[] = '}';
        $palette = $this->resolvedPalette();
        $opacity = $this->adminAppearance()['panel_opacity'];
        if ($palette === null && $this->settings->has('brand.admin_panel_opacity')) {
            $lines[] = 'html { --glass-surface: color-mix(in srgb, var(--surface) ' . $opacity . '%, transparent) !important;'
                . ' --ambient-opacity: ' . ((100 - $opacity) / 100) . ' !important; }';
        }
        if ($palette !== null) {
            $resolver = new PaletteResolver();
            foreach (['light', 'dark'] as $mode) {
                $selector = $mode === 'light'
                    ? 'html[data-brand-palette="1"]:not(.dark):not([data-theme="gereq-dark"])'
                    : 'html.dark[data-brand-palette="1"], html[data-theme="gereq-dark"][data-brand-palette="1"]';
                $lines[] = $selector . ' {';
                foreach ($resolver->cssTokens($palette['modes'][$mode], $opacity) as $name => $value) {
                    $lines[] = "{$name}: {$value} !important;";
                }
                $lines[] = '}';
            }
        }
        $custom = $this->customCssAdmin();
        if ($custom !== '') {
            $lines[] = $custom;
        }

        return implode("\n", $lines);
    }

    public function customCssAdmin(): string
    {
        if ($this->settings->has('brand.custom_css')) {
            return $this->sanitizeCss($this->string('brand.custom_css', ''));
        }

        // Legacy keys (Cms / shared CSS) until first save under brand.custom_css.
        $raw = $this->string('cms.custom_css_admin', '');
        if ($raw === '') {
            $raw = $this->string('cms.custom_css', '');
        }

        return $this->sanitizeCss($raw);
    }

    public function customCssPublic(): string
    {
        $raw = $this->string('cms.custom_css_public', '');
        if ($raw === '') {
            $raw = $this->string('cms.custom_css', '');
        }

        return $this->sanitizeCss($raw);
    }

    /**
     * Documented tokens for the CSS editor (admin vs public storefront).
     * Public color defaults come from Brand (Značka); custom CSS overrides chip swatches.
     *
     * @return array{admin: list<array{name: string, value: string, hint: string}>, public: list<array{name: string, value: string, hint: string}>}
     */
    public function documentedVariables(): array
    {
        $b = $this->resolve();
        $public = $this->publicCssDefaults($b);
        $fromCss = $this->parseCssCustomProperties($b['custom_css_public']);
        foreach ($public as $i => $item) {
            $name = $item['name'];
            if (isset($fromCss[$name]) && $fromCss[$name] !== '') {
                $public[$i]['value'] = $fromCss[$name];
            }
        }

        return [
            'admin' => [
                ['name' => '--primary', 'value' => $b['color_signal'], 'hint' => 'Akcent / tlačítka'],
                ['name' => '--primary-fg', 'value' => $b['color_on_signal'], 'hint' => 'Text na akcentu'],
                ['name' => '--accent', 'value' => $b['color_signal'], 'hint' => 'Alias akcentu'],
                ['name' => '--focus', 'value' => $b['color_signal'], 'hint' => 'Focus ring'],
                ['name' => '--sidebar-line', 'value' => $b['color_signal'], 'hint' => 'Aktivní položka menu'],
                ['name' => '--color-primary', 'value' => $b['color_signal'], 'hint' => 'Primární (alias)'],
                ['name' => '--color-primary-content', 'value' => $b['color_on_signal'], 'hint' => 'Text na primární'],
                ['name' => '--gereq-signal', 'value' => $b['color_signal'], 'hint' => 'Token signal'],
                ['name' => '--brand-ink', 'value' => $b['color_ink'], 'hint' => 'Brand ink (konstanta)'],
                ['name' => '--brand-cream', 'value' => $b['color_cream'], 'hint' => 'Brand cream (konstanta)'],
                ['name' => '--gereq-ink', 'value' => $b['color_ink'], 'hint' => 'UI ink / text'],
                ['name' => '--gereq-cream', 'value' => $b['color_cream'], 'hint' => 'UI cream'],
                ['name' => '--gereq-paper', 'value' => $b['color_cream'], 'hint' => 'Paper surface'],
                ['name' => '--background', 'value' => $b['color_cream'], 'hint' => 'Pozadí stránky'],
                ['name' => '--surface', 'value' => $this->mixHex($b['color_cream'], '#ffffff', 0.35), 'hint' => 'Karty / panely'],
                ['name' => '--text', 'value' => $b['color_ink'], 'hint' => 'Hlavní text'],
                ['name' => '--text-muted', 'value' => $this->mixHex($b['color_ink'], $b['color_cream'], 0.45), 'hint' => 'Vedlejší text'],
                ['name' => '--border', 'value' => $this->mixHex($b['color_ink'], $b['color_cream'], 0.78), 'hint' => 'Ohraničení'],
            ],
            'public' => $public,
        ];
    }

    /**
     * Default public FE tokens from Brand settings (before custom CSS).
     *
     * @param array{site_primary: string, color_ink: string, color_cream: string, color_on_signal: string} $b
     * @return list<array{name: string, value: string, hint: string}>
     */
    private function publicCssDefaults(array $b): array
    {
        $palette = $this->resolvedPalette();
        if ($palette !== null) {
            $mode = $palette['modes'][$palette['storefront_mode']];

            return [
                ['name' => '--color-brand-50', 'value' => $mode['brand_50'], 'hint' => 'Nejsvětlejší brand'],
                ['name' => '--color-brand-600', 'value' => $mode['brand_600'], 'hint' => 'Brand střed'],
                ['name' => '--color-brand-700', 'value' => $mode['primary'], 'hint' => 'Primární barva'],
                ['name' => '--color-brand-900', 'value' => $mode['brand_900'], 'hint' => 'Nejtmavší brand'],
                ['name' => '--color-secondary', 'value' => $mode['secondary'], 'hint' => 'Sekundární barva'],
                ['name' => '--color-accent', 'value' => $mode['accent'], 'hint' => 'Doplňková barva'],
                ['name' => '--color-on-primary', 'value' => $mode['on_primary'], 'hint' => 'Text na hlavní barvě'],
                ['name' => '--color-on-secondary', 'value' => $mode['on_secondary'], 'hint' => 'Text na sekundární barvě'],
                ['name' => '--color-on-accent', 'value' => $mode['on_accent'], 'hint' => 'Text na doplňkové barvě'],
                ['name' => '--color-paper', 'value' => $mode['background'], 'hint' => 'Pozadí stránky'],
                ['name' => '--color-surface', 'value' => $mode['surface'], 'hint' => 'Karty'],
                ['name' => '--color-header', 'value' => $mode['header'], 'hint' => 'Záhlaví karet'],
                ['name' => '--color-on-header', 'value' => $mode['text_on_header'], 'hint' => 'Text na záhlaví karty'],
                ['name' => '--color-on-surface', 'value' => $mode['text_on_surface'], 'hint' => 'Text na kartě'],
                ['name' => '--color-muted-on-surface', 'value' => $mode['text_muted_on_surface'], 'hint' => 'Vedlejší text na kartě'],
                ['name' => '--border', 'value' => $mode['border'], 'hint' => 'Ohraničení'],
                ['name' => '--color-ink-600', 'value' => $mode['text_muted'], 'hint' => 'Text muted'],
                ['name' => '--color-ink-900', 'value' => $mode['text'], 'hint' => 'Hlavní text'],
                ['name' => '--font-sans', 'value' => 'Arial', 'hint' => 'Základní font'],
                ['name' => '--radius-card', 'value' => '0.75rem', 'hint' => 'Radius karet'],
                ['name' => '--radius-control', 'value' => '0.5rem', 'hint' => 'Radius inputů'],
            ];
        }

        $primary = $b['site_primary'];
        $ink = $b['color_ink'];
        $cream = $b['color_cream'];
        // Dark public sites use ink as paper; light sites use cream.
        $paperLuma = $this->hexLuma($ink);
        $paper = $paperLuma < 0.45 ? $ink : $cream;
        $surface = $paperLuma < 0.45
            ? $this->mixHex($ink, '#ffffff', 0.08)
            : $this->mixHex($cream, '#ffffff', 0.55);
        $ink900 = $this->contrastOn($paper);
        $ink600 = $this->mixHex($ink900, $paper, 0.42);

        return [
            ['name' => '--color-brand-50', 'value' => $this->mixHex($primary, '#ffffff', 0.88), 'hint' => 'Nejsvětlejší brand'],
            ['name' => '--color-brand-600', 'value' => $this->mixHex($primary, '#000000', 0.08), 'hint' => 'Brand střed'],
            ['name' => '--color-brand-700', 'value' => $primary, 'hint' => 'Primární barva webu (Značka)'],
            ['name' => '--color-brand-900', 'value' => $this->mixHex($primary, '#000000', 0.42), 'hint' => 'Nejtmavší brand'],
            ['name' => '--color-paper', 'value' => $paper, 'hint' => 'Pozadí stránky'],
            ['name' => '--color-surface', 'value' => $surface, 'hint' => 'Karty'],
            ['name' => '--color-ink-600', 'value' => $ink600, 'hint' => 'Text muted'],
            ['name' => '--color-ink-900', 'value' => $ink900, 'hint' => 'Hlavní text'],
            ['name' => '--font-sans', 'value' => 'Archivo', 'hint' => 'Základní font'],
            ['name' => '--radius-card', 'value' => '0.75rem', 'hint' => 'Radius karet'],
            ['name' => '--radius-control', 'value' => '0.5rem', 'hint' => 'Radius inputů'],
        ];
    }

    /** @return array<string, string> */
    private function parseCssCustomProperties(string $css): array
    {
        if ($css === '') {
            return [];
        }
        $out = [];
        if (preg_match_all('/(--[a-zA-Z0-9_-]+)\s*:\s*([^;}+{]+);/u', $css, $matches, PREG_SET_ORDER) === false) {
            return [];
        }
        foreach ($matches as $match) {
            $name = trim($match[1]);
            $value = trim($match[2]);
            if ($name === '' || $value === '' || str_starts_with($value, '/*')) {
                continue;
            }
            $out[$name] = $value;
        }

        return $out;
    }

    private function mixHex(string $from, string $to, float $amount): string
    {
        $a = $this->hexToRgb($from);
        $b = $this->hexToRgb($to);
        if ($a === null || $b === null) {
            return $from;
        }
        $t = max(0.0, min(1.0, $amount));
        $r = (int) round($a[0] + ($b[0] - $a[0]) * $t);
        $g = (int) round($a[1] + ($b[1] - $a[1]) * $t);
        $bl = (int) round($a[2] + ($b[2] - $a[2]) * $t);

        return sprintf('#%02x%02x%02x', $r, $g, $bl);
    }

    /** @return array{0: int, 1: int, 2: int}|null */
    private function hexToRgb(string $hex): ?array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private function hexLuma(string $hex): float
    {
        $rgb = $this->hexToRgb($hex);
        if ($rgb === null) {
            return 0.5;
        }

        return (0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2]) / 255;
    }

    private function sanitizeCss(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $raw = preg_replace('/<\/style/i', '', $raw) ?? $raw;
        $raw = preg_replace('/<script/i', '', $raw) ?? $raw;

        return trim($raw);
    }

    /** Inline style for <html> — highest priority over stylesheet token resets. */
    public function htmlStyleAttribute(): string
    {
        $parts = [];
        foreach ($this->cssVariableMap() as $name => $value) {
            $parts[] = $name . ':' . $value;
        }

        return implode(';', $parts);
    }

    /** @return array<string, string> */
    private function cssVariableMap(): array
    {
        $b = $this->resolve();
        $signal = $b['color_signal'];
        $onSignal = $b['color_on_signal'];
        $ink = $b['color_ink'];
        $cream = $b['color_cream'];

        return [
            // Accent only — surfaces/text stay theme-driven (light/dark).
            '--gereq-signal' => $signal,
            '--gereq-brand' => $ink,
            '--brand-ink' => $ink,
            '--brand-cream' => $cream,
            '--primary' => $signal,
            '--primary-fg' => $onSignal,
            '--accent' => $signal,
            '--focus' => $signal,
            '--sidebar-line' => $signal,
            '--color-primary' => $signal,
            '--color-primary-content' => $onSignal,
            '--color-secondary' => $signal,
            '--color-secondary-content' => $onSignal,
            '--color-accent' => $signal,
            '--color-accent-content' => $onSignal,
        ];
    }

    private function string(string $key, string $default): string
    {
        $value = $this->settings->get($key, $default);
        if (!is_string($value) && !is_numeric($value)) {
            return $default;
        }

        return trim((string) $value);
    }

    private function hex(string $key, string $default): string
    {
        $raw = $this->string($key, $default);
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $raw) === 1) {
            return strtolower($raw);
        }

        return $default;
    }

    private function urlOrDefault(string $url, string $default): string
    {
        if ($url === '') {
            return $default;
        }
        if (str_starts_with($url, '/') || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return $default;
    }

    private function shortName(string $appName): string
    {
        $trimmed = trim(preg_replace('/\s+Platform$/iu', '', $appName) ?? $appName);
        if ($trimmed === '') {
            return 'GEREQ';
        }
        $parts = preg_split('/\s+/u', $trimmed) ?: [];

        return $parts[0] !== '' ? $parts[0] : 'GEREQ';
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        if ($parts === [] || $parts[0] === '') {
            return 'RE';
        }
        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }

    private function contrastOn(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luma = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;

        return $luma > 0.55 ? '#18181b' : '#ffffff';
    }
}
