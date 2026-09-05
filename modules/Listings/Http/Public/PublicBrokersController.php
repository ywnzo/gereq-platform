<?php

declare(strict_types=1);

namespace Modules\Listings\Http\Public;

use Gereq\Http\PublicCors;
use Modules\Listings\Application\BrokersProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicBrokersController
{
    public function __construct(private readonly BrokersProvider $provider)
    {
    }

    public function options(Request $request): Response
    {
        return $this->cors(new Response('', 204), $request);
    }

    public function index(Request $request): Response
    {
        $items = array_map(
            fn ($broker) => $this->provider->serialize($broker),
            $this->provider->allActive(),
        );

        return $this->cors(new JsonResponse(['data' => $items]), $request);
    }

    public function show(Request $request, string $id): Response
    {
        $broker = $this->provider->find($id);
        if ($broker === null || !$broker->active()) {
            return $this->cors(new JsonResponse(['error' => 'not_found'], 404), $request);
        }

        return $this->cors(new JsonResponse(['data' => $this->provider->serialize($broker)]), $request);
    }

    private function cors(Response $response, Request $request): Response
    {
        return PublicCors::apply($response, $request, 'GET, OPTIONS');
    }
}
