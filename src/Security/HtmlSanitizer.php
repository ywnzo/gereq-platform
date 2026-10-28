<?php

declare(strict_types=1);

namespace Gereq\Security;

/**
 * Allowlist HTML sanitizer for TipTap / Cms rich text.
 * Strips scripts, event handlers, and dangerous URLs.
 */
final class HtmlSanitizer
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike',
        'ul', 'ol', 'li', 'blockquote', 'hr',
        'h1', 'h2', 'h3', 'h4',
        'a', 'span', 'div',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'colgroup', 'col', 'caption',
    ];

    /** @var array<string, list<string>> */
    private const ALLOWED_ATTR = [
        'a' => ['href', 'title', 'rel', 'target'],
        'p' => ['style'],
        'h1' => ['style'],
        'h2' => ['style'],
        'h3' => ['style'],
        'h4' => ['style'],
        'div' => ['style'],
        'span' => ['style'],
        'li' => ['style'],
        'table' => ['style'],
        'tr' => ['style'],
        'th' => ['style', 'rowspan', 'colspan'],
        'td' => ['style', 'rowspan', 'colspan'],
        'col' => ['style']
    ];

    /**
     * Allowed inline CSS properties for rich text
     * (align / font / weight / size / color / highlight).
     *
     * @var list<string>
     */
    private const ALLOWED_STYLES = [
        'text-align',
        'font-family',
        'font-weight',
        'font-size',
        'color',
        'background-color',
        'width',
        'height',
        'table-layout',
        'border',
        '--tbl-border',
        '--tbl-pad',
    ];

    public function sanitize(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"><div id="gereq-sanitize-root">' . $html . '</div>';
        try {
            $loaded = $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return '';
        }

        $root = $dom->getElementById('gereq-sanitize-root');
        if (!$root instanceof \DOMElement) {
            return '';
        }

        $this->walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    public function sanitizeNullable(?string $html): ?string
    {
        $clean = $this->sanitize($html);

        return $clean === '' ? null : $clean;
    }

    private function walk(\DOMNode $node): void
    {
        if (!$node->hasChildNodes()) {
            return;
        }

        /** @var list<\DOMNode> $children */
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    // Discard executable/embedded content, including its descendants.
                    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'template', 'svg', 'math', 'noscript'], true)) {
                        $node->removeChild($child);
                        continue;
                    }
                    // Sanitize descendants before promoting them into the allowed tree.
                    $this->walk($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                $this->cleanAttributes($child, $tag);
                $this->walk($child);
            } elseif ($child instanceof \DOMComment) {
                $node->removeChild($child);
            }
        }
    }

    private function cleanAttributes(\DOMElement $el, string $tag): void
    {
        $allowed = self::ALLOWED_ATTR[$tag] ?? [];
        /** @var list<string> $names */
        $names = [];
        foreach ($el->attributes ?? [] as $attr) {
            $names[] = $attr->name;
        }

        foreach ($names as $name) {
            $lower = strtolower($name);
            if (str_starts_with($lower, 'on') || !in_array($lower, $allowed, true)) {
                $el->removeAttribute($name);
                continue;
            }

            $value = $el->getAttribute($name);
            if ($lower === 'href') {
                $safe = $this->safeUrl($value);
                if ($safe === null) {
                    $el->removeAttribute($name);
                } else {
                    $el->setAttribute('href', $safe);
                    $el->setAttribute('rel', 'noopener noreferrer');
                }
            } elseif ($lower === 'target') {
                if (!in_array($value, ['_blank', '_self'], true)) {
                    $el->removeAttribute($name);
                }
            } elseif ($lower === 'style') {
                $clean = $this->safeStyle($value);
                if ($clean === '') {
                    $el->removeAttribute($name);
                } else {
                    $el->setAttribute('style', $clean);
                }
            }
        }
    }

    private function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#')) {
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return $url;
        }
        if (preg_match('#^(https?://|mailto:)#i', $url) === 1) {
            return $url;
        }

        return null;
    }

    private function safeStyle(string $style): string
    {
        $parts = [];
        foreach (explode(';', $style) as $decl) {
            $decl = trim($decl);
            if ($decl === '' || !str_contains($decl, ':')) {
                continue;
            }
            [$prop, $val] = array_map('trim', explode(':', $decl, 2));
            $prop = strtolower($prop);
            if (!in_array($prop, self::ALLOWED_STYLES, true)) {
                continue;
            }
            if ($prop === 'text-align' && !in_array(strtolower($val), ['left', 'center', 'right', 'justify'], true)) {
                continue;
            }
            if ($prop === 'font-weight') {
                $lw = strtolower($val);
                if (!preg_match('/^(normal|bold|bolder|lighter|[1-9]00)$/', $lw)) {
                    continue;
                }
                $parts[] = $prop . ': ' . $lw;
                continue;
            }
            if ($prop === 'font-family') {
                if (!preg_match("/^[a-zA-Z0-9\\s,\"'_\\-]+$/", $val) || str_contains(strtolower($val), 'expression')) {
                    continue;
                }
                $parts[] = $prop . ': ' . $val;
                continue;
            }
            if ($prop === 'font-size') {
                $cleanSize = $this->safeFontSize($val);
                if ($cleanSize === null) {
                    continue;
                }
                $parts[] = $prop . ': ' . $cleanSize;
                continue;
            }
            if ($prop === 'color' || $prop === 'background-color') {
                $cleanColor = $this->safeCssColor($val);
                if ($cleanColor === null) {
                    continue;
                }
                $parts[] = $prop . ': ' . $cleanColor;
                continue;
            }
            if (preg_match('/[<>"\'{}]|expression|javascript:|url\(/i', $val)) {
                continue;
            }
            $parts[] = $prop . ': ' . strtolower($val);
        }

        return implode('; ', $parts);
    }

    private function safeFontSize(string $val): ?string
    {
        $val = trim($val);
        $lower = strtolower($val);
        if (in_array($lower, ['inherit', 'smaller', 'larger', 'xx-small', 'x-small', 'small', 'medium', 'large', 'x-large', 'xx-large'], true)) {
            return $lower;
        }
        // 1.15em, 14px, 120%, 0.85rem
        if (preg_match('/^(\d+\.?\d*|\.\d+)(px|em|rem|%)$/i', $val) === 1) {
            return strtolower($val);
        }

        return null;
    }

    private function safeCssColor(string $val): ?string
    {
        $val = trim($val);
        $lower = strtolower($val);
        if (in_array($lower, ['inherit', 'transparent', 'currentcolor'], true)) {
            return $lower;
        }
        if (in_array($lower, ['var(--color-paper)', 'var(--color-surface)', 'var(--color-header)', 'var(--color-brand-700)', 'var(--color-secondary)', 'var(--color-accent)'], true)) {
            return $lower;
        }
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $val) === 1) {
            return strtolower($val);
        }
        // rgb(245, 197, 24) / rgba(245, 197, 24, 0.5) — browsers often emit this from foreColor
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*(0|0?\.\d+|1(\.0)?))?\s*\)$/i', $val, $m) === 1) {
            $r = min(255, max(0, (int) $m[1]));
            $g = min(255, max(0, (int) $m[2]));
            $b = min(255, max(0, (int) $m[3]));
            if (isset($m[4]) && $m[4] !== '') {
                $a = (float) $m[4];
                if ($a < 0 || $a > 1) {
                    return null;
                }

                return sprintf('rgba(%d, %d, %d, %s)', $r, $g, $b, rtrim(rtrim(sprintf('%.3f', $a), '0'), '.'));
            }

            return sprintf('rgb(%d, %d, %d)', $r, $g, $b);
        }

        return null;
    }
}
