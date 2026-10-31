<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

use Symfony\Component\HttpFoundation\Request;

/**
 * Injects a semantic HTML body into the storefront SPA shell for pages with ssg=true.
 * Runs after Web+ head inject; Vue mount replaces #app on the client.
 */
final class CmsSsgBodyInjector
{
    public function __construct(
        private readonly CmsProvider $cms,
        private readonly CmsSsgHtmlRenderer $renderer,
    ) {
    }

    public function inject(string $html, Request $request): string
    {
        if ($html === '' || str_contains($html, 'data-gereq-ssg="1"')) {
            return $html;
        }
        if (!str_contains($html, 'id="app"') && !str_contains($html, "id='app'")) {
            return $html;
        }

        $slug = $this->slugFromPath($request->getPathInfo());
        if ($slug === null) {
            return $html;
        }

        $page = $this->cms->findPublishedBySlug($slug);
        if ($page === null) {
            return $html;
        }

        // Production uses frozen snapshot — ssg flag must be published.
        $serialized = $this->cms->serializePage($page, withBlocks: true, expandVariables: true);
        if (!(bool) ($serialized['ssg'] ?? false)) {
            return $html;
        }

        $fragment = $this->renderer->render($serialized);
        if (trim(strip_tags($fragment)) === '' && !str_contains($fragment, '<img')) {
            return $html;
        }

        $replacement = '<div id="app">' . "\n" . '<!-- gereq-cms-ssg -->' . "\n" . $fragment . "\n";
        $out = preg_replace(
            '/<div(\s[^>]*)?\sid=["\']app["\'][^>]*>\s*/i',
            $replacement,
            $html,
            1,
        );

        return is_string($out) ? $out : $html;
    }

    private function slugFromPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return 'home';
        }

        $path = '/' . ltrim($path, '/');
        if (preg_match('#^/(api|admin|build|media|brand|tickets|security)(/|$)#i', $path) === 1) {
            return null;
        }

        if (preg_match('#^/stranka/([a-z0-9][a-z0-9\-_/]*)$#i', $path, $m) === 1) {
            return strtolower(rtrim($m[1], '/'));
        }

        if (preg_match('#^/([a-z0-9][a-z0-9\-_]*)$#i', $path, $m) === 1) {
            $slug = strtolower($m[1]);
            if (in_array($slug, ['favicon.ico', 'robots.txt', 'sitemap.xml'], true)) {
                return null;
            }

            return $slug;
        }

        return null;
    }
}
