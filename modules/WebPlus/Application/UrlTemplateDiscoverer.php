<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application;

use Modules\WebPlus\Domain\WebMeta;

/**
 * Infers generic path templates from concrete URLs (segment variance), not modules.
 *
 * Example: /nabidky/a + /nabidky/b → /nabidky/{x}
 */
final class UrlTemplateDiscoverer
{
    private const TOKEN_NAMES = ['page', 'x', 'y', 'z'];

    /**
     * @param list<string> $paths concrete public paths
     * @return list<array{template: string, tokens: list<string>, samples: list<string>, hit_count: int}>
     */
    public function discover(array $paths, int $minHits = 2): array
    {
        $concrete = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $normalized = WebMeta::normalizePath($path);
            if ($normalized === '/' || PathPattern::isTemplate($normalized)) {
                continue;
            }
            $concrete[$normalized] = true;
        }
        $list = array_keys($concrete);
        if (count($list) < $minHits) {
            return [];
        }

        /** @var array<int, list<list<string>>> $byDepth */
        $byDepth = [];
        foreach ($list as $path) {
            $segments = $this->segments($path);
            if ($segments === []) {
                continue;
            }
            $byDepth[count($segments)][] = $segments;
        }

        $found = [];
        foreach ($byDepth as $groups) {
            if (count($groups) < $minHits) {
                continue;
            }
            foreach ($this->clusterTemplates($groups, $minHits) as $row) {
                $found[$row['template']] = $row;
            }
        }

        $out = array_values($found);
        usort($out, static fn (array $a, array $b): int => strcmp($a['template'], $b['template']));

        return $out;
    }

    /**
     * @param list<list<string>> $pathsAsSegments
     * @return list<array{template: string, tokens: list<string>, samples: list<string>, hit_count: int}>
     */
    private function clusterTemplates(array $pathsAsSegments, int $minHits): array
    {
        $depth = count($pathsAsSegments[0]);
        $out = [];

        // Prefix-bucketed clusters (same static head, varying tail) cover most FE URLs.
        for ($prefixLen = 0; $prefixLen < $depth; $prefixLen++) {
            /** @var array<string, list<list<string>>> $buckets */
            $buckets = [];
            foreach ($pathsAsSegments as $segments) {
                $key = implode('/', array_slice($segments, 0, $prefixLen));
                $buckets[$key][] = $segments;
            }
            foreach ($buckets as $members) {
                if (count($members) < $minHits) {
                    continue;
                }
                $row = $this->templateFromGroup($members);
                if ($row !== null) {
                    $out[$row['template']] = $row;
                }
            }
        }

        // Whole depth group (e.g. /o-nas + /kontakt → /{page})
        if (count($pathsAsSegments) >= $minHits) {
            $row = $this->templateFromGroup($pathsAsSegments);
            if ($row !== null) {
                $out[$row['template']] = $row;
            }
        }

        return array_values($out);
    }

    /**
     * @param list<list<string>> $members same depth
     * @return array{template: string, tokens: list<string>, samples: list<string>, hit_count: int}|null
     */
    private function templateFromGroup(array $members): ?array
    {
        if ($members === []) {
            return null;
        }
        $depth = count($members[0]);
        $variable = array_fill(0, $depth, false);
        $reference = $members[0];

        foreach ($members as $segments) {
            if (count($segments) !== $depth) {
                return null;
            }
            for ($i = 0; $i < $depth; $i++) {
                if ($segments[$i] !== $reference[$i]) {
                    $variable[$i] = true;
                }
            }
        }

        if (!in_array(true, $variable, true)) {
            return null;
        }

        $parts = [];
        $tokens = [];
        $tokenIdx = 0;
        for ($i = 0; $i < $depth; $i++) {
            if ($variable[$i]) {
                $name = self::TOKEN_NAMES[$tokenIdx] ?? ('p' . ($tokenIdx + 1));
                $tokenIdx++;
                // Prefer {slug} for a sole trailing wildcard under a static prefix.
                if ($tokenIdx === 1 && $i === $depth - 1 && $i > 0) {
                    $name = 'slug';
                }
                $parts[] = '{' . $name . '}';
                $tokens[] = $name;
            } else {
                $parts[] = $reference[$i];
            }
        }

        $template = '/' . implode('/', $parts);
        $samples = [];
        foreach (array_slice($members, 0, 5) as $segments) {
            $samples[] = '/' . implode('/', $segments);
        }

        return [
            'template' => $template,
            'tokens' => $tokens,
            'samples' => $samples,
            'hit_count' => count($members),
        ];
    }

    /** @return list<string> */
    private function segments(string $path): array
    {
        return array_values(array_filter(
            explode('/', trim(WebMeta::normalizePath($path), '/')),
            static fn (string $p): bool => $p !== '',
        ));
    }
}
