<?php

declare(strict_types=1);

namespace Gereq\Api\OpenApi;

use Gereq\Api\ApiRegistry;
use Gereq\Config\Repository;

final class OpenApiGenerator
{
    public function __construct(
        private readonly ApiRegistry $api,
        private readonly Repository $config,
    ) {
    }

    /** @return array<string, mixed> */
    public function generate(): array
    {
        $paths = [];
        foreach ($this->api->allRoutes() as $route) {
            $path = preg_replace('#/+#', '/', $route->fullPath((string) $this->config->get('api.prefix', '/api/v1'))) ?: '/';
            $path = rtrim($path, '/') ?: '/';
            $path = preg_replace('#\{(\w+)\}#', '{$1}', $path) ?: $path;
            foreach ($route->methods as $method) {
                $paths[$path][strtolower($method)] = [
                    'summary' => $route->summary !== '' ? $route->summary : strtoupper($method) . ' ' . $path,
                    'tags' => [$route->module],
                    'security' => [['bearerAuth' => []], ['sessionAuth' => []]],
                    'x-scopes' => $route->scopes,
                    'responses' => [
                        '200' => ['description' => 'OK'],
                        '401' => ['description' => 'Unauthenticated'],
                        '403' => ['description' => 'Forbidden'],
                    ],
                ];
            }
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'GEREQ Platform API',
                'version' => (string) $this->config->get('app.version', '0.1.0'),
            ],
            'servers' => [
                ['url' => (string) $this->config->get('app.url', '')],
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'API token grq_live_* / grq_test_*',
                    ],
                    'sessionAuth' => [
                        'type' => 'apiKey',
                        'in' => 'cookie',
                        'name' => 'PHPSESSID',
                    ],
                ],
            ],
        ];
    }
}
