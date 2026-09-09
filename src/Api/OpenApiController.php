<?php

declare(strict_types=1);

namespace Gereq\Api;

use Gereq\Api\OpenApi\OpenApiGenerator;
use Symfony\Component\HttpFoundation\JsonResponse;

final class OpenApiController
{
    public function __construct(private readonly OpenApiGenerator $generator)
    {
    }

    public function show(): JsonResponse
    {
        return new JsonResponse(
            $this->generator->generate(),
            200,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ],
            false,
        );
    }
}
