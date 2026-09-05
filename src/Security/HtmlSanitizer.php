<?php

declare(strict_types=1);

namespace Gereq\Security;

/**
 * Allowlist HTML sanitizer for TipTap / CMS rich text.
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
    ];

    /** Allowed inline CSS properties (text-align from TipTap). */
    private const ALLOWED_STYLES = ['text-align'];

    public function sanitize(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"><div id="gereq-sanitize-root">' . $html . '</div>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('gereq-sanitize-root');
        if (!$root instanceof \DOMElement) {
            return strip_tags($html, '<' . implode('><', self::ALLOWED_TAGS) . '>');
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
                    // Keep text content of disallowed wrappers.
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
            $parts[] = $prop . ': ' . strtolower($val);
        }

        return implode('; ', $parts);
    }
}
