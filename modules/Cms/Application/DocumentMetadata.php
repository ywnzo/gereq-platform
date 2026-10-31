<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

/** Safe native element metadata for document-tree storefronts. */
final class DocumentMetadata
{
    private const TAGS = ['section', 'article', 'div', 'nav', 'aside', 'ul', 'ol', 'li', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span', 'strong', 'em', 'b', 'i', 'small', 'a', 'blockquote', 'figure', 'figcaption', 'dl', 'dt', 'dd', 'details', 'summary', 'br', 'hr', 'code', 'address', 'time', 'img', 'button'];
    private const ATTRS = ['id', 'class', 'title', 'role', 'target', 'rel', 'width', 'height', 'loading', 'decoding', 'alt', 'sizes', 'srcset', 'style', 'type', 'media', 'tabindex'];

    /** @param array<string, mixed> $raw @return array<string, mixed> */
    public static function normalize(array $raw): array
    {
        $out = [];
        $tag = (string) ($raw['gereq_tag'] ?? '');
        if (in_array($tag, self::TAGS, true)) {
            $out['gereq_tag'] = $tag;
        }
        $component = (string) ($raw['gereq_component'] ?? '');
        if (in_array($component, ['inquiry', 'team-profile'], true)) {
            $out['gereq_component'] = $component;
        }
        if (in_array($raw['gereq_person'] ?? null, ['jiri', 'ernie'], true)) {
            $out['gereq_person'] = $raw['gereq_person'];
        }
        if (is_array($raw['gereq_attrs'] ?? null)) {
            $out['gereq_attrs'] = self::attributes($raw['gereq_attrs']);
        }
        if (is_array($raw['gereq_sources'] ?? null)) {
            $out['gereq_sources'] = array_map(
                static fn (array $source): array => self::attributes($source),
                array_values(array_filter(array_slice($raw['gereq_sources'], 0, 5), 'is_array')),
            );
        }
        foreach (['gereq_space_before', 'gereq_space_after'] as $space) {
            if (!empty($raw[$space])) {
                $out[$space] = 1;
            }
        }
        foreach (['gereq_original_url', 'gereq_original_icon'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key])) {
                $out[$key] = mb_substr($raw[$key], 0, 2048);
            }
        }
        if (is_array($raw['gereq_initial'] ?? null)) {
            $out['gereq_initial'] = array_intersect_key($raw['gereq_initial'], array_flip(['display', 'dir', 'gap', 'grid_cols', 'width', 'height', 'align', 'spacing']));
        }

        return $out;
    }

    /** @param array<string, mixed> $attrs @return array<string, string> */
    private static function attributes(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            if (!in_array($key, self::ATTRS, true) && preg_match('/^aria-[a-z-]+$/D', $key) !== 1) {
                continue;
            }
            $value = (string) $value;
            if ($key === 'style' && preg_match('/(?:url\s*\(|expression|javascript|behavior|-moz-binding|[<>\\\\])/i', $value) === 1) {
                continue;
            }
            if (preg_match('/(?:javascript|vbscript|data):/i', $value) === 1) {
                continue;
            }
            $out[$key] = mb_substr($value, 0, 20000);
        }

        return $out;
    }
}
