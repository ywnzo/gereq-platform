<?php

declare(strict_types=1);

namespace Modules\WebPlus\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\WebPlus\Application\WebPlusProvider;
use Modules\WebPlus\Domain\WebMeta;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class WebPlusApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly WebPlusProvider $webplus,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest(
            $request,
            sortable: ['path', 'updatedAt', 'createdAt'],
            filterable: ['active', 'cms_page_id'],
        );

        $qb = $this->em->getRepository(WebMeta::class)->createQueryBuilder('m');
        self_repair_search($qb, 'm', ['path', 'seoTitle', 'ogTitle'], $query->search);
        if (isset($query->filters['active']) && $query->filters['active'] !== '') {
            $qb->andWhere('m.active = :active')
                ->setParameter('active', filter_var($query->filters['active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (isset($query->filters['cms_page_id']) && $query->filters['cms_page_id'] !== '') {
            $qb->andWhere('m.cmsPageId = :pageId')->setParameter('pageId', $query->filters['cms_page_id']);
        }

        $sort = $query->sort ?? 'path';
        $qb->orderBy('m.' . $sort, strtoupper($query->direction));

        $total = (int) (clone $qb)->select('COUNT(m.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<WebMeta> $items */
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (WebMeta $m): array => $this->webplus->serialize($m), $items),
            $query->meta($total),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        if ($this->webplus->findByPath($data['path']) instanceof WebMeta) {
            return ApiResponse::error('validation_error', 'Path taken', ['path' => 'taken'], 422);
        }

        $meta = new WebMeta($data['path']);
        $this->applyData($meta, $data);
        $this->em->persist($meta);
        $this->em->flush();

        $payload = $this->webplus->serialize($meta);
        $this->events->emit('webplus.meta.created', ['type' => 'webplus_meta', 'id' => $meta->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $meta = $this->webplus->find($id);
        if ($meta === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->webplus->serialize($meta));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $meta = $this->webplus->find($id);
        if ($meta === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validated($request, partial: true);
        if (isset($data['path'])) {
            $dup = $this->webplus->findByPath($data['path']);
            if ($dup instanceof WebMeta && $dup->id() !== $meta->id()) {
                return ApiResponse::error('validation_error', 'Path taken', ['path' => 'taken'], 422);
            }
            $meta->setPath($data['path']);
        }
        $this->applyData($meta, $data, partial: true);
        $this->em->flush();

        $payload = $this->webplus->serialize($meta);
        $this->events->emit('webplus.meta.updated', ['type' => 'webplus_meta', 'id' => $meta->id()], $payload);

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $meta = $this->webplus->find($id);
        if ($meta === null) {
            return ApiResponse::notFound();
        }

        $payload = $this->webplus->serialize($meta);
        $this->em->remove($meta);
        $this->em->flush();
        $this->events->emit('webplus.meta.deleted', ['type' => 'webplus_meta', 'id' => $id], $payload);

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    public function resolve(Request $request): JsonResponse
    {
        $path = trim((string) $request->query->get('path', ''));
        $pageId = trim((string) $request->query->get('cms_page_id', ''));
        if ($pageId !== '') {
            $slug = trim((string) $request->query->get('slug', ''));
            $resolved = $this->webplus->resolveForCmsPage(
                $pageId,
                $slug !== '' ? $slug : $pageId,
                $this->nullableQuery($request, 'title'),
                $this->nullableQuery($request, 'description'),
            );

            return ApiResponse::data($resolved);
        }
        if ($path === '') {
            return ApiResponse::error('validation_error', 'path or cms_page_id required', [], 422);
        }

        return ApiResponse::data($this->webplus->resolveForPath(
            $path,
            $this->nullableQuery($request, 'title'),
            $this->nullableQuery($request, 'description'),
        ));
    }

    public function site(): JsonResponse
    {
        return ApiResponse::data($this->webplus->siteDefaults());
    }



    public function googleProducts(): Response
    {
        $generated = $this->webplus->generateGoogleProductsFeed();
        if ($generated === null) {
            return ApiResponse::notFound('feed_disabled', 'Google Products feed is disabled.');
        }

        return new Response($generated['body'], 200, [
            'Content-Type' => $generated['content_type'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $payload = $request->request->all();
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }

        $out = [];
        if (!$partial || array_key_exists('path', $payload)) {
            $path = WebMeta::normalizePath((string) ($payload['path'] ?? ''));
            if ($path === '' && !$partial) {
                // normalizePath never returns empty — '/' is ok
            }
            $out['path'] = $path !== '' ? $path : '/';
        }

        $map = [
            'cms_page_id' => 'cms_page_id',
            'active' => 'active',
            'seo_title' => 'seo_title',
            'seo_description' => 'seo_description',
            'canonical_url' => 'canonical_url',
            'robots' => 'robots',
            'keywords' => 'keywords',
            'geo_region' => 'geo_region',
            'geo_placename' => 'geo_placename',
            'geo_position' => 'geo_position',
            'ai_summary' => 'ai_summary',
            'json_ld' => 'json_ld',
            'og_title' => 'og_title',
            'og_description' => 'og_description',
            'og_image_url' => 'og_image_url',
            'og_type' => 'og_type',
            'og_url' => 'og_url',
            'og_locale' => 'og_locale',
            'twitter_card' => 'twitter_card',
            'twitter_title' => 'twitter_title',
            'twitter_description' => 'twitter_description',
            'twitter_image_url' => 'twitter_image_url',
            'sitemap_include' => 'sitemap_include',
            'sitemap_priority' => 'sitemap_priority',
            'sitemap_changefreq' => 'sitemap_changefreq',
        ];

        foreach ($map as $key => $_) {
            if (!$partial && !array_key_exists($key, $payload) && !in_array($key, ['path', 'active', 'robots', 'og_type', 'twitter_card', 'sitemap_include'], true)) {
                continue;
            }
            if ($partial && !array_key_exists($key, $payload)) {
                continue;
            }
            $out[$key] = $payload[$key] ?? null;
        }

        if (!$partial) {
            $out['active'] = $out['active'] ?? true;
            $out['robots'] = $out['robots'] ?? 'index,follow';
            $out['og_type'] = $out['og_type'] ?? 'website';
            $out['twitter_card'] = $out['twitter_card'] ?? 'summary_large_image';
            $out['sitemap_include'] = $out['sitemap_include'] ?? true;
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    private function applyData(WebMeta $meta, array $data, bool $partial = false): void
    {
        if (!$partial || array_key_exists('cms_page_id', $data)) {
            $pageId = $data['cms_page_id'] ?? null;
            $meta->setCmsPageId(is_string($pageId) && $pageId !== '' ? $pageId : null);
        }
        if (!$partial || array_key_exists('active', $data)) {
            $meta->setActive($this->boolish($data['active'] ?? true));
        }
        if (!$partial || array_key_exists('seo_title', $data)) {
            $meta->setSeoTitle($this->str($data['seo_title'] ?? null));
        }
        if (!$partial || array_key_exists('seo_description', $data)) {
            $meta->setSeoDescription($this->str($data['seo_description'] ?? null));
        }
        if (!$partial || array_key_exists('canonical_url', $data)) {
            $meta->setCanonicalUrl($this->str($data['canonical_url'] ?? null));
        }
        if (!$partial || array_key_exists('robots', $data)) {
            $meta->setRobots((string) ($data['robots'] ?? 'index,follow'));
        }
        if (!$partial || array_key_exists('keywords', $data)) {
            $meta->setKeywords($this->str($data['keywords'] ?? null));
        }
        if (!$partial || array_key_exists('geo_region', $data)) {
            $meta->setGeoRegion($this->str($data['geo_region'] ?? null));
        }
        if (!$partial || array_key_exists('geo_placename', $data)) {
            $meta->setGeoPlacename($this->str($data['geo_placename'] ?? null));
        }
        if (!$partial || array_key_exists('geo_position', $data)) {
            $meta->setGeoPosition($this->str($data['geo_position'] ?? null));
        }
        if (!$partial || array_key_exists('ai_summary', $data)) {
            $meta->setAiSummary($this->str($data['ai_summary'] ?? null));
        }
        if (!$partial || array_key_exists('json_ld', $data)) {
            $jsonLd = $data['json_ld'] ?? null;
            $meta->setJsonLd(is_array($jsonLd) ? $jsonLd : null);
        }
        if (!$partial || array_key_exists('og_title', $data)) {
            $meta->setOgTitle($this->str($data['og_title'] ?? null));
        }
        if (!$partial || array_key_exists('og_description', $data)) {
            $meta->setOgDescription($this->str($data['og_description'] ?? null));
        }
        if (!$partial || array_key_exists('og_image_url', $data)) {
            $meta->setOgImageUrl($this->str($data['og_image_url'] ?? null));
        }
        if (!$partial || array_key_exists('og_type', $data)) {
            $meta->setOgType((string) ($data['og_type'] ?? 'website'));
        }
        if (!$partial || array_key_exists('og_url', $data)) {
            $meta->setOgUrl($this->str($data['og_url'] ?? null));
        }
        if (!$partial || array_key_exists('og_locale', $data)) {
            $meta->setOgLocale($this->str($data['og_locale'] ?? null));
        }
        if (!$partial || array_key_exists('twitter_card', $data)) {
            $meta->setTwitterCard((string) ($data['twitter_card'] ?? 'summary_large_image'));
        }
        if (!$partial || array_key_exists('twitter_title', $data)) {
            $meta->setTwitterTitle($this->str($data['twitter_title'] ?? null));
        }
        if (!$partial || array_key_exists('twitter_description', $data)) {
            $meta->setTwitterDescription($this->str($data['twitter_description'] ?? null));
        }
        if (!$partial || array_key_exists('twitter_image_url', $data)) {
            $meta->setTwitterImageUrl($this->str($data['twitter_image_url'] ?? null));
        }
        if (!$partial || array_key_exists('sitemap_include', $data)) {
            $meta->setSitemapInclude($this->boolish($data['sitemap_include'] ?? true));
        }
        if (!$partial || array_key_exists('sitemap_priority', $data)) {
            $meta->setSitemapPriority($this->str($data['sitemap_priority'] ?? null) ?? '0.5');
        }
        if (!$partial || array_key_exists('sitemap_changefreq', $data)) {
            $meta->setSitemapChangefreq($this->str($data['sitemap_changefreq'] ?? null) ?? 'weekly');
        }
    }

    private function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function boolish(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true);
    }

    private function nullableQuery(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query->get($key, ''));

        return $value !== '' ? $value : null;
    }
}
