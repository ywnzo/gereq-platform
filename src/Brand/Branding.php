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
     *   color_signal: string,
     *   color_ink: string,
     *   color_cream: string,
     *   color_on_signal: string,
     *   site_name: string,
     *   site_mark: string,
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

        $signal = $this->hex('brand.color_signal', self::DEFAULT_SIGNAL);
        $ink = $this->hex('brand.color_ink', self::DEFAULT_INK);
        $cream = $this->hex('brand.color_cream', self::DEFAULT_CREAM);
        $sitePrimary = $this->hex('brand.site_primary', self::DEFAULT_SITE_PRIMARY);

        $siteName = $this->string('brand.site_name', '');
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
            'color_signal' => $signal,
            'color_ink' => $ink,
            'color_cream' => $cream,
            'color_on_signal' => $this->contrastOn($signal),
            'site_name' => $siteName,
            'site_mark' => mb_substr($siteMark, 0, 3),
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

        return [
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
                'signal' => $b['color_signal'],
                'ink' => $b['color_ink'],
                'cream' => $b['color_cream'],
                'primary' => $b['site_primary'],
                'on_signal' => $b['color_on_signal'],
            ],
        ];
    }

    /** Inline CSS overrides for admin / auth layouts (must load after kernel.css). */
    public function adminCssVariables(): string
    {
        $lines = [':root, html, html.dark, [data-theme="gereq"], [data-theme="gereq-dark"] {'];
        foreach ($this->cssVariableMap() as $name => $value) {
            $lines[] = "{$name}: {$value} !important;";
        }
        $lines[] = '}';
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

        // Legacy keys (CMS / shared CSS) until first save under brand.custom_css.
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
     *
     * @return array{admin: list<array{name: string, value: string, hint: string}>, public: list<array{name: string, value: string, hint: string}>}
     */
    public function documentedVariables(): array
    {
        $b = $this->resolve();

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
                ['name' => '--gereq-ink', 'value' => 'theme', 'hint' => 'UI ink / text (theme)'],
                ['name' => '--gereq-cream', 'value' => 'theme', 'hint' => 'UI cream (theme)'],
                ['name' => '--gereq-paper', 'value' => 'theme', 'hint' => 'Paper surface (theme)'],
                ['name' => '--background', 'value' => 'theme', 'hint' => 'Pozadí stránky'],
                ['name' => '--surface', 'value' => 'theme', 'hint' => 'Karty / panely'],
                ['name' => '--text', 'value' => 'theme', 'hint' => 'Hlavní text'],
                ['name' => '--text-muted', 'value' => 'theme', 'hint' => 'Vedlejší text'],
                ['name' => '--border', 'value' => 'theme', 'hint' => 'Ohraničení'],
            ],
            'public' => [
                ['name' => '--color-brand-50', 'value' => 'scale', 'hint' => 'Nejsvětlejší brand'],
                ['name' => '--color-brand-600', 'value' => $b['site_primary'], 'hint' => 'Brand střed'],
                ['name' => '--color-brand-700', 'value' => $b['site_primary'], 'hint' => 'Primární barva webu'],
                ['name' => '--color-brand-900', 'value' => 'scale', 'hint' => 'Nejtmavší brand'],
                ['name' => '--color-paper', 'value' => '#f5f6f8', 'hint' => 'Pozadí stránky'],
                ['name' => '--color-surface', 'value' => '#ffffff', 'hint' => 'Karty'],
                ['name' => '--color-ink-600', 'value' => '#545e70', 'hint' => 'Text muted'],
                ['name' => '--color-ink-900', 'value' => '#1b202b', 'hint' => 'Hlavní text'],
                ['name' => '--font-sans', 'value' => 'Archivo', 'hint' => 'Základní font'],
                ['name' => '--radius-card', 'value' => '0.75rem', 'hint' => 'Radius karet'],
                ['name' => '--radius-control', 'value' => '0.5rem', 'hint' => 'Radius inputů'],
            ],
        ];
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
