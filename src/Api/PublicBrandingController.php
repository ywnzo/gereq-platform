<?php

declare(strict_types=1);

namespace Gereq\Api;

use Gereq\Brand\Branding;
use Gereq\Cache\StoreFrontCache;
use Gereq\Http\PublicCors;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicBrandingController
{
    public function __construct(
        private readonly Branding $branding,
        private readonly StoreFrontCache $storeFrontCache,
    ) {
    }

    public function options(Request $request): Response
    {
        return PublicCors::apply(new Response('', 204), $request, 'GET, OPTIONS');
    }

    public function show(Request $request): Response
    {
        $payload = $this->storeFrontCache->remember('branding', fn (): array => $this->branding->toPublic());

        return PublicCors::apply(
            ApiResponse::data($payload),
            $request,
            'GET, OPTIONS',
        );
    }
}
