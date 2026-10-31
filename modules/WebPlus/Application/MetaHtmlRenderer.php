<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application;

/** Renders a Web+ package as HTML head tags or a standalone share document. */
final class MetaHtmlRenderer
{
    /**
     * @param array<string, mixed> $pkg
     */
    public function head(array $pkg): string
    {
        return implode("\n    ", $this->headParts($pkg, managed: false));
    }

    /**
     * Inject Web+ tags into a Vite SPA index.html (first paint / crawlers / LCP preload).
     *
     * @param array<string, mixed> $pkg
     */
    public function injectIntoSpa(string $html, array $pkg): string
    {
        $parts = $this->headParts($pkg, managed: true);
        $preload = $this->lcpPreloadTag($pkg);
        if ($preload !== null) {
            $parts[] = $preload;
        }
        if ($parts === []) {
            return $html;
        }

        // Strip SPA shell defaults that would duplicate / fight managed tags.
        $html = (string) preg_replace('/<title>\s*[^<]*\s*<\/title>/i', '', $html);
        $html = (string) preg_replace('/<meta\s+name=["\']description["\'][^>]*>/i', '', $html);

        $fragment = "    <!-- gereq-webplus-spa -->\n    "
            . implode("\n    ", $parts)
            . "\n    <meta name=\"gereq-webplus-spa\" content=\"1\" data-gereq-webplus-spa=\"1\" data-webplus=\"1\">";

        if (!str_contains($html, '</head>')) {
            return $html;
        }

        return (string) preg_replace('/<\/head>/i', $fragment . "\n  </head>", $html, 1);
    }

    /**
     * @param array<string, mixed> $pkg
     * @return list<string>
     */
    private function headParts(array $pkg, bool $managed): array
    {
        $mark = $managed ? ' data-webplus="1"' : '';
        $parts = [];
        $title = $this->documentTitle($pkg);
        if ($title !== null) {
            $parts[] = '<title' . $mark . '>' . $this->esc($title) . '</title>';
        }

        $tags = is_array($pkg['tags'] ?? null) ? $pkg['tags'] : [];
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $kind = (string) ($tag['kind'] ?? '');
            $key = (string) ($tag['key'] ?? '');
            $value = (string) ($tag['value'] ?? '');
            if ($key === '' || $value === '') {
                continue;
            }
            if ($kind === 'title') {
                continue;
            }
            if ($kind === 'link' && $key === 'canonical') {
                $parts[] = '<link rel="canonical" href="' . $this->esc($value) . '"' . $mark . '>';
                continue;
            }
            if ($kind === 'name' || $kind === 'property') {
                $attr = $kind === 'name' ? 'name' : 'property';
                $parts[] = '<meta ' . $attr . '="' . $this->esc($key) . '" content="' . $this->esc($value) . '"' . $mark . '>';
            }
        }

        $jsonLd = is_array($pkg['json_ld'] ?? null) ? $pkg['json_ld'] : [];
        foreach ($jsonLd as $index => $item) {
            if (!is_array($item) || $item === []) {
                continue;
            }
            $parts[] = '<script type="application/ld+json"' . $mark
                . ($managed ? ' data-webplus-ld="' . (int) $index . '"' : '')
                . '>' . json_encode(
                    $item,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ) . '</script>';
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $pkg
     */
    private function lcpPreloadTag(array $pkg): ?string
    {
        $og = is_array($pkg['og'] ?? null) ? $pkg['og'] : [];
        $image = is_string($og['image'] ?? null) ? trim($og['image']) : '';
        if ($image === '') {
            foreach (is_array($pkg['tags'] ?? null) ? $pkg['tags'] : [] as $tag) {
                if (!is_array($tag)) {
                    continue;
                }
                if (($tag['kind'] ?? '') === 'property' && ($tag['key'] ?? '') === 'og:image') {
                    $image = trim((string) ($tag['value'] ?? ''));
                    break;
                }
            }
        }
        if ($image === '' || !preg_match('#^(https?:)?/#i', $image)) {
            return null;
        }

        return '<link rel="preload" as="image" href="' . $this->esc($image)
            . '" fetchpriority="high" data-webplus="1" data-gereq-lcp="1">';
    }

    /**
     * @param array<string, mixed> $pkg
     */
    public function document(array $pkg): string
    {
        $head = $this->head($pkg);
        $title = $this->documentTitle($pkg) ?? 'RealEstates';
        $seo = is_array($pkg['seo'] ?? null) ? $pkg['seo'] : [];
        $description = is_string($seo['description'] ?? null) ? $seo['description'] : '';
        $canonical = is_string($seo['canonical_url'] ?? null) ? $seo['canonical_url'] : '';
        $og = is_array($pkg['og'] ?? null) ? $pkg['og'] : [];
        $image = is_string($og['image'] ?? null) ? $og['image'] : '';

        $body = '<h1>' . $this->esc($title) . '</h1>';
        if ($description !== '') {
            $body .= '<p>' . $this->esc($description) . '</p>';
        }
        if ($image !== '') {
            $body .= '<p><img src="' . $this->esc($image) . '" alt=""></p>';
        }
        if ($canonical !== '') {
            $body .= '<p><a href="' . $this->esc($canonical) . '">' . $this->esc($canonical) . '</a></p>';
        }

        return <<<HTML
<!doctype html>
<html lang="cs">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {$head}
  </head>
  <body>
    {$body}
  </body>
</html>
HTML;
    }

    /**
     * @param array<string, mixed> $pkg
     */
    private function documentTitle(array $pkg): ?string
    {
        $seo = is_array($pkg['seo'] ?? null) ? $pkg['seo'] : [];
        $og = is_array($pkg['og'] ?? null) ? $pkg['og'] : [];
        $site = is_string($pkg['site_name'] ?? null) ? $pkg['site_name'] : null;
        $title = is_string($seo['title'] ?? null) && $seo['title'] !== ''
            ? $seo['title']
            : (is_string($og['title'] ?? null) ? $og['title'] : null);
        if ($title === null || $title === '') {
            return $site;
        }
        if ($site !== null && $site !== '' && !str_contains($title, $site)) {
            return $title . ' · ' . $site;
        }

        return $title;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
