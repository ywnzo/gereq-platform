<?php

declare(strict_types=1);

namespace Modules\WebPlus\Http\Public;

use Gereq\Api\ApiResponse;
use Gereq\Cache\StoreFrontCache;
use Gereq\Http\PublicCors;
use Modules\Cms\Domain\Page;
use Modules\WebPlus\Application\MetaHtmlRenderer;
use Modules\WebPlus\Application\WebPlusProvider;
use Modules\WebPlus\Domain\WebMeta;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Public storefront API — SEO/GEO/OG packages + discovery files + Google Products feed. */
final class PublicWebPlusController
{
    public function __construct(
        private readonly WebPlusProvider $webplus,
        private readonly EntityManagerInterface $em,
        private readonly MetaHtmlRenderer $htmlRenderer,
        private readonly StoreFrontCache $storeFrontCache,
    ) {
    }

    public function options(Request $request): Response
    {
        return $this->cors(new Response('', 204), $request);
    }

    public function meta(Request $request): Response
    {
        $path = trim((string) $request->query->get('path', ''));
        if ($path === '') {
            return $this->cors(ApiResponse::error('validation_error', 'Query param path is required.', [
                'path' => 'required',
            ], 422), $request);
        }

        $title = $this->nullableQuery($request, 'title');
        $description = $this->nullableQuery($request, 'description');
        $format = strtolower(trim((string) $request->query->get('format', '')));
        $cacheKey = 'webplus.meta.' . md5($path . "\0" . ($title ?? '') . "\0" . ($description ?? '') . "\0" . $format);
        if ($format === 'html') {
            $html = $this->storeFrontCache->remember(
                $cacheKey,
                function () use ($path, $title, $description): string {
                    $pkg = $this->webplus->resolveForPath($path, $title, $description);

                    return $this->htmlRenderer->document($pkg);
                },
            );

            return $this->cors(new Response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]), $request);
        }

        $pkg = $this->storeFrontCache->remember(
            $cacheKey,
            fn (): array => $this->webplus->resolveForPath($path, $title, $description),
        );

        return $this->cors(ApiResponse::data($pkg), $request);
    }

    public function html(Request $request): Response
    {
        $path = trim((string) $request->query->get('path', ''));
        if ($path === '') {
            return $this->cors(ApiResponse::error('validation_error', 'Query param path is required.', [
                'path' => 'required',
            ], 422), $request);
        }

        $title = $this->nullableQuery($request, 'title');
        $description = $this->nullableQuery($request, 'description');
        $html = $this->storeFrontCache->remember(
            'webplus.html.' . md5($path . "\0" . ($title ?? '') . "\0" . ($description ?? '')),
            function () use ($path, $title, $description): string {
                $pkg = $this->webplus->resolveForPath($path, $title, $description);

                return $this->htmlRenderer->document($pkg);
            },
        );

        return $this->cors(new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]), $request);
    }

    public function metaByPage(Request $request, string $pageId): Response
    {
        $slug = '';
        $title = null;
        $description = null;
        if (class_exists(Page::class)) {
            $page = $this->em->find(Page::class, $pageId);
            if ($page instanceof Page) {
                $slug = $page->slug();
                $title = $page->title();
                $description = $page->metaDescription();
            }
        }
        if ($slug === '') {
            $meta = $this->webplus->findByCmsPageId($pageId);
            if ($meta instanceof WebMeta && $meta->isActive()) {
                return $this->cors(ApiResponse::data($this->webplus->serializeResolved($meta)), $request);
            }

            return $this->cors(ApiResponse::notFound('page_not_found', 'Stránka neexistuje.'), $request);
        }

        $resolved = $this->webplus->resolveForCmsPage($pageId, $slug, $title, $description);

        return $this->cors(ApiResponse::data($resolved), $request);
    }

    public function site(Request $request): Response
    {
        $payload = $this->storeFrontCache->remember('webplus.site', function (): array {
            $defaults = $this->webplus->siteDefaults();
            $endpoints = $this->webplus->publicSiteEndpoints([
                'meta' => '/api/public/webplus/meta?path={path}',
                'html' => '/api/public/webplus/html?path={path}',
                'meta_by_page' => '/api/public/webplus/meta/by-page/{pageId}',
                'sitemap' => '/api/public/webplus/sitemap.xml',
                'robots' => '/api/public/webplus/robots.txt',
                'llms' => '/api/public/webplus/llms.txt',
                'google_products' => '/api/public/webplus/google-products.xml',
            ]);

            return [
                'site' => $defaults,
                'endpoints' => $endpoints,
            ];
        });

        return $this->cors(ApiResponse::data($payload), $request);
    }



    public function sitemap(Request $request): Response
    {
        $xml = $this->storeFrontCache->remember(
            'webplus.sitemap',
            fn (): string => $this->webplus->renderSitemapXml(),
        );

        return $this->cors(new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]), $request);
    }

    public function robots(Request $request): Response
    {
        $body = $this->storeFrontCache->remember(
            'webplus.robots',
            fn (): string => $this->webplus->robotsTxt(),
        );

        return $this->cors(new Response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]), $request);
    }

    public function llms(Request $request): Response
    {
        $body = $this->storeFrontCache->remember('webplus.llms', function (): string {
            $body = $this->webplus->llmsTxt();
            if ($body === '') {
                $site = $this->webplus->siteDefaults();
                $name = $site['site_name'] !== '' ? $site['site_name'] : 'Website';
                $body = "# {$name}\n\n> Generative-engine summary (llms.txt). Configure content in Web+ settings.\n";
            }

            return $body;
        });

        return $this->cors(new Response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]), $request);
    }

    public function googleProducts(Request $request): Response
    {
        $generated = $this->webplus->generateGoogleProductsFeed();
        if ($generated === null) {
            return $this->cors(ApiResponse::error('feed_disabled', 'Google Products feed is disabled.', [], 404), $request);
        }

        return $this->cors(new Response($generated['body'], 200, [
            'Content-Type' => $generated['content_type'],
        ]), $request);
    }

    private function nullableQuery(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query->get($key, ''));

        return $value !== '' ? $value : null;
    }

    private function cors(Response $response, Request $request): Response
    {
        return PublicCors::apply($response, $request, 'GET, OPTIONS');
    }
}
