<?php

declare(strict_types=1);

namespace Modules\Listings\Http\Public;

use Gereq\Http\PublicCors;
use Modules\Listings\Application\CitiesProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicCitiesController
{
    public function __construct(private readonly CitiesProvider $provider)
    {
    }

    public function options(Request $request): Response
    {
        return $this->cors(new Response('', 204), $request);
    }

    public function index(Request $request): Response
    {
        $counts = $this->provider->publishedCounts();
        $onlyWithListings = $request->query->getBoolean('with_listings', false);
        $limit = max(0, min(50, (int) $request->query->get('limit', 0)));

        $items = [];
        foreach ($this->provider->allActive() as $city) {
            $count = $counts[$city->name()] ?? 0;
            if ($onlyWithListings && $count < 1) {
                continue;
            }
            $items[] = $this->provider->serialize($city, $count);
        }

        usort($items, static function (array $a, array $b): int {
            $byCount = ($b['listing_count'] ?? 0) <=> ($a['listing_count'] ?? 0);
            if ($byCount !== 0) {
                return $byCount;
            }

            return ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0);
        });

        if ($limit > 0) {
            $items = array_slice($items, 0, $limit);
        }

        return $this->cors(new JsonResponse(['data' => $items]), $request);
    }

    private function cors(Response $response, Request $request): Response
    {
        return PublicCors::apply($response, $request, 'GET, OPTIONS');
    }
}
