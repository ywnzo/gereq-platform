<?php

declare(strict_types=1);

namespace Gereq\Api;

use Gereq\Http\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;

final class HealthController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->json([
            'data' => [
                'name' => 'GEREQ Platform',
                'version' => config('app.version', '0.1.0'),
                'status' => 'ok',
            ],
        ]);
    }
}
