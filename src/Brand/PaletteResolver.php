<?php

declare(strict_types=1);

namespace Gereq\Brand;

/** Resolves a shared brand palette into semantic light and dark color tokens. */
final class PaletteResolver
{
    /**
     * @param array<string, mixed> $input
     * @return array{version: int, storefront_mode: string, colors: array<string, string>, modes: array<string, array<string, string>>, warnings: list<string>}
     */
    public function resolve(array $input): array
    {
        $colors = [];
        foreach (['primary', 'secondary', 'accent', 'light_background', 'light_surface', 'dark_background', 'dark_surface'] as $key) {
            $colors[$key] = $this->normalize($input[$key] ?? null, $key);
        }

        $storefrontMode = $input['storefront_mode'] ?? 'light';
        if (!is_string($storefrontMode) || !in_array($storefrontMode, ['light', 'dark'], true)) {
            throw new \InvalidArgumentException('Invalid storefront palette mode');
        }

        $warnings = [];
        $modes = [];
        foreach (['light', 'dark'] as $mode) {
            $background = $colors[$mode . '_background'];
            $surface = $colors[$mode . '_surface'];
            $text = $this->foreground($background);
            $surfaceText = $this->foreground($surface);
            $muted = $this->muted($text, $background, $background);
            $surfaceMuted = $this->muted($surfaceText, $surface, $surface);
            $secondarySurface = $this->mix($background, $surface, 0.5);
            if ($this->luminance($surface) < 0.03 || $this->luminance($surface) > 0.9) {
                for ($amount = 0.05; $this->contrast($secondarySurface, $surface) < 1.5 && $amount <= 0.4; $amount += 0.05) {
                    $secondarySurface = $this->mix($surface, $this->foreground($surface), $amount);
                }
            }
            $headerKey = $mode . '_header';
            $header = isset($input[$headerKey]) ? $this->normalize($input[$headerKey], $headerKey) : $secondarySurface;
            $colors[$headerKey] = $header;
            if ($this->contrast($text, $surface) < 4.5) {
                $warnings[] = $mode . '.text_on_surface';
            }
            foreach (['primary', 'secondary', 'accent'] as $role) {
                if ($this->contrast($colors[$role], $background) < 4.5 || $this->contrast($colors[$role], $surface) < 4.5) {
                    $warnings[] = $mode . '.' . $role . '_on_surface';
                }
            }

            $modes[$mode] = [
                'background' => $background,
                'surface' => $surface,
                'surface_secondary' => $secondarySurface,
                'header' => $header,
                'text' => $text,
                'text_on_surface' => $surfaceText,
                'text_muted' => $muted,
                'text_muted_on_surface' => $surfaceMuted,
                'border' => $this->mix($surface, $surfaceText, 0.25),
                'primary' => $colors['primary'],
                'on_primary' => $this->foreground($colors['primary']),
                'brand_50' => $this->mix($colors['primary'], '#ffffff', 0.88),
                'brand_600' => $this->mix($colors['primary'], '#000000', 0.08),
                'brand_900' => $this->mix($colors['primary'], '#000000', 0.42),
                'secondary' => $colors['secondary'],
                'on_secondary' => $this->foreground($colors['secondary']),
                'accent' => $colors['accent'],
                'on_accent' => $this->foreground($colors['accent']),
            ];
        }

        return [
            'version' => 1,
            'storefront_mode' => $storefrontMode,
            'colors' => $colors,
            'modes' => $modes,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, string> $mode
     * @return array<string, string>
     */
    public function cssTokens(array $mode, int $panelOpacity = 100): array
    {
        $panelOpacity = max(0, min(100, $panelOpacity));
        $panelBackground = $this->mix($mode['background'], $mode['surface'], $panelOpacity / 100);
        $panelText = $this->foreground($panelBackground);
        $panelMuted = $this->muted($panelText, $panelBackground, $panelBackground);
        $secondaryText = $this->contrast($mode['text'], $mode['surface_secondary']) >= 4.5
            ? $mode['text']
            : $this->foreground($mode['surface_secondary']);
        $secondaryMuted = $this->muted($secondaryText, $mode['surface_secondary'], $mode['surface_secondary']);
        $headerText = $this->foreground($mode['header']);
        $headerMuted = $this->muted($headerText, $mode['header'], $mode['header']);

        return [
            '--background' => $mode['background'],
            '--surface' => $mode['surface'],
            '--surface-2' => $mode['surface_secondary'],
            '--card-header' => $mode['header'],
            '--text-on-card-header' => $headerText,
            '--text-muted-on-card-header' => $headerMuted,
            '--text' => $mode['text'],
            '--text-on-surface' => $mode['text_on_surface'],
            '--text-muted-on-surface' => $mode['text_muted_on_surface'],
            '--text-on-panel' => $panelText,
            '--text-muted-on-panel' => $panelMuted,
            '--text-on-secondary-surface' => $secondaryText,
            '--text-muted-on-secondary-surface' => $secondaryMuted,
            '--text-muted' => $mode['text_muted'],
            '--text-soft' => $mode['text_muted'],
            '--border' => $mode['border'],
            '--border-soft' => $mode['border'],
            '--hover' => 'color-mix(in srgb, ' . $mode['text'] . ' 4%, transparent)',
            '--hover-strong' => 'color-mix(in srgb, ' . $mode['text'] . ' 7%, transparent)',
            '--primary' => $mode['primary'],
            '--primary-fg' => $mode['on_primary'],
            '--secondary' => $mode['secondary'],
            '--secondary-fg' => $mode['on_secondary'],
            '--accent' => $mode['accent'],
            '--accent-fg' => $mode['on_accent'],
            '--focus' => $mode['primary'],
            '--sidebar' => $mode['surface'],
            '--sidebar-text' => $mode['text_muted_on_surface'],
            '--sidebar-muted' => $mode['text_muted_on_surface'],
            '--sidebar-active' => $mode['text_on_surface'],
            '--sidebar-line' => $mode['primary'],
            '--glass-surface' => 'color-mix(in srgb, ' . $mode['surface'] . ' ' . $panelOpacity . '%, transparent)',
            '--ambient-opacity' => (string) ((100 - $panelOpacity) / 100),
            '--glass-sidebar' => $mode['surface'],
            '--glass-topbar' => $mode['surface'],
            '--gereq-signal' => $mode['primary'],
            '--gereq-brand' => $mode['surface'],
            '--brand-ink' => $mode['surface'],
            '--brand-cream' => $mode['text_on_surface'],
            '--gereq-paper' => $mode['background'],
            '--gereq-wash' => $mode['background'],
            '--gereq-white' => $mode['surface'],
            '--gereq-fg' => $mode['text'],
            '--gereq-muted' => $mode['text_muted'],
            '--gereq-border' => $mode['border'],
            '--gereq-ink' => $mode['text'],
            '--gereq-cream' => $mode['background'],
            '--color-base-100' => $mode['surface'],
            '--color-base-200' => $mode['background'],
            '--color-base-300' => $mode['border'],
            '--color-base-content' => $mode['text'],
            '--color-primary' => $mode['primary'],
            '--color-primary-content' => $mode['on_primary'],
            '--color-secondary' => $mode['secondary'],
            '--color-secondary-content' => $mode['on_secondary'],
            '--color-accent' => $mode['accent'],
            '--color-accent-content' => $mode['on_accent'],
        ];
    }

    private function normalize(mixed $value, string $key): string
    {
        if (!is_string($value) || preg_match('/^#([a-f0-9]{6})$/i', trim($value)) !== 1) {
            throw new \InvalidArgumentException('Invalid palette color: ' . $key);
        }

        return strtolower(trim($value));
    }

    private function foreground(string $background): string
    {
        return $this->contrast('#000000', $background) >= $this->contrast('#ffffff', $background)
            ? '#000000'
            : '#ffffff';
    }

    private function muted(string $foreground, string $background, string $surface): string
    {
        // Find the softest text that still meets normal-text contrast on both surfaces.
        $best = $foreground;
        for ($amount = 0.05; $amount <= 0.8; $amount += 0.05) {
            $candidate = $this->mix($foreground, $background, $amount);
            if ($this->contrast($candidate, $background) < 4.5 || $this->contrast($candidate, $surface) < 4.5) {
                break;
            }
            $best = $candidate;
        }

        return $best;
    }

    private function contrast(string $first, string $second): float
    {
        $a = $this->luminance($first);
        $b = $this->luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private function luminance(string $color): float
    {
        $channels = [];
        for ($i = 1; $i < 7; $i += 2) {
            $channel = hexdec(substr($color, $i, 2)) / 255;
            $channels[] = $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function mix(string $first, string $second, float $amount): string
    {
        $channels = [];
        for ($i = 1; $i < 7; $i += 2) {
            $a = hexdec(substr($first, $i, 2));
            $b = hexdec(substr($second, $i, 2));
            $channels[] = (int) round($a + ($b - $a) * $amount);
        }

        return sprintf('#%02x%02x%02x', $channels[0], $channels[1], $channels[2]);
    }
}
