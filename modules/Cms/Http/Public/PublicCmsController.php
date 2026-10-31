<?php

declare(strict_types=1);

namespace Modules\Cms\Http\Public;

use Gereq\Api\ApiResponse;
use Gereq\Http\PublicCors;
use Modules\Cms\Application\CmsProvider;
use Modules\Cms\Domain\Page;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Public JSON API for the storefront SPA — no HTML rendering. */
final class PublicCmsController
{
    public function __construct(private readonly CmsProvider $provider)
    {
    }

    public function options(Request $request): Response
    {
        return $this->cors(new Response('', 204), $request);
    }

    public function pages(Request $request): Response
    {
        $limit = max(1, min(100, (int) $request->query->get('limit', 50)));
        $items = array_map(
            fn ($page): array => $this->provider->serializePage($page, false),
            $this->provider->publishedPages($limit),
        );

        return $this->cors(ApiResponse::collection($items, ['total' => count($items)]), $request);
    }

    public function page(Request $request, string $slug): Response
    {
        $page = $this->provider->findPublishedBySlug($slug);
        if ($page === null) {
            return $this->cors(ApiResponse::notFound('page_not_found', 'Stránka neexistuje.'), $request);
        }

        $data = $this->provider->serializePage($page, true, true);
        $data['blocks'] = PublicFormController::endpoints($data['blocks'] ?? [], (string) $data['slug']);
        return $this->cors(ApiResponse::data($data), $request);
    }

    public function menus(Request $request): Response
    {
        $location = trim((string) $request->query->get('location', ''));
        $items = $this->provider->navMenus($location !== '' ? $location : null);

        return $this->cors(ApiResponse::collection($items, ['total' => count($items)]), $request);
    }

    public function menu(Request $request, string $location): Response
    {
        if (!in_array($location, Page::MENU_LOCATIONS, true)) {
            return $this->cors(ApiResponse::notFound('menu_not_found', 'Menu neexistuje.'), $request);
        }

        return $this->cors(ApiResponse::data($this->provider->navMenu($location)), $request);
    }

    private function cors(Response $response, Request $request): Response
    {
        return PublicCors::apply($response, $request, 'GET, OPTIONS');
    }
}
