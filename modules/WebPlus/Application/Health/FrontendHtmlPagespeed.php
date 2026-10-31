<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application\Health;

/**
 * Pure HTML probes for storefront PageSpeed / first-paint health.
 *
 * @phpstan-type PagespeedHtmlFacts array{
 *     has_title: bool,
 *     has_description: bool,
 *     has_og_title: bool,
 *     has_og_image: bool,
 *     has_spa_inject: bool,
 *     has_lcp_preload: bool,
 *     eager_rounded_sharp: bool,
 *     eager_outlined_woff2: bool,
 *     spa_shell: bool
 * }
 */
final class FrontendHtmlPagespeed
{
    /**
     * @return PagespeedHtmlFacts
     */
    public static function analyze(string $html): array
    {
        return [
            'has_title' => preg_match('#<title[^>]*>\s*[^<]+</title>#i', $html) === 1,
            'has_description' => preg_match('#<meta[^>]+name=["\']description["\'][^>]*>#i', $html) === 1
                || preg_match('#property=["\']og:description["\']#i', $html) === 1,
            'has_og_title' => preg_match('#property=["\']og:title["\']#i', $html) === 1,
            'has_og_image' => preg_match('#property=["\']og:image["\']#i', $html) === 1,
            'has_spa_inject' => str_contains($html, 'data-gereq-webplus-spa')
                || str_contains($html, 'gereq-webplus-spa'),
            'has_lcp_preload' => str_contains($html, 'data-gereq-lcp')
                || preg_match('#<link[^>]+rel=["\']preload["\'][^>]+as=["\']image["\']#i', $html) === 1
                || preg_match('#<link[^>]+as=["\']image["\'][^>]+rel=["\']preload["\']#i', $html) === 1,
            'eager_rounded_sharp' => preg_match('#material-symbols-(rounded|sharp)[^"\'\s]*\.(woff2|css)#i', $html) === 1,
            'eager_outlined_woff2' => preg_match('#material-symbols-outlined[^"\'\s]*\.woff2#i', $html) === 1
                && preg_match('#rel=["\']preload["\'][^>]+material-symbols-outlined#i', $html) === 1,
            'spa_shell' => str_contains($html, 'id="app"') || str_contains($html, 'id="root"'),
        ];
    }
}
