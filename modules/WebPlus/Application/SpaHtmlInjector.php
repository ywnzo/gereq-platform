<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application;

use Symfony\Component\HttpFoundation\Request;

/**
 * Injects Web+ SEO/OG tags (+ LCP image preload) into the storefront SPA shell
 * so the first HTML response is useful for crawlers and Lighthouse without waiting for JS.
 */
final class SpaHtmlInjector
{
    public function __construct(
        private readonly WebPlusProvider $provider,
        private readonly MetaHtmlRenderer $renderer,
    ) {
    }

    public function inject(string $html, Request $request): string
    {
        if ($html === '' || !str_contains($html, '</head>')) {
            return $html;
        }
        // Already enriched (nested filter / cached shell).
        if (str_contains($html, 'data-gereq-webplus-spa="1"') || str_contains($html, 'gereq-webplus-spa')) {
            return $html;
        }

        $path = $request->getPathInfo();
        if ($path === '') {
            $path = '/';
        }

        try {
            $pkg = $this->provider->resolveForPath($path);
        } catch (\Throwable) {
            return $html;
        }

        return $this->renderer->injectIntoSpa($html, $pkg);
    }
}
