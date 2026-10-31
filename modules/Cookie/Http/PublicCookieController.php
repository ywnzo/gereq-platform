<?php

declare(strict_types=1);

namespace Modules\Cookie\Http;

use Gereq\Api\ApiResponse;
use Gereq\Http\PublicCors;
use Modules\Cookie\Application\CookieImageStore;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Modules\Cookie\Application\CookieConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicCookieController
{
    public function __construct(private readonly CookieConfig $config, private readonly CookieImageStore $images)
    {
    }

    public function config(Request $request): Response
    {
        $payload = $this->config->payload();
        $response = $request->getPathInfo() === '/api/public/webplus/cookies'
            ? ApiResponse::data($payload) : new JsonResponse($payload);
        $response->headers->set('Cache-Control', 'no-store');

        return PublicCors::apply($response, $request);
    }

    public function options(Request $request): Response
    {
        return PublicCors::apply(new Response('', 204), $request);
    }

    public function image(Request $request, string $name): Response
    {
        $path = $this->images->path($name);
        if ($path === null) {
            return new Response('', 404);
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', CookieImageStore::mime($name));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'public, max-age=2592000, immutable');
        $response->setAutoEtag();
        $response->isNotModified($request);

        return $response;
    }

    public function widget(): Response
    {
        return new Response((string) file_get_contents(dirname(__DIR__) . '/Resources/public/widget.js'), 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
