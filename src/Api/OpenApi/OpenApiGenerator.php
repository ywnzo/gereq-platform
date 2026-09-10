<?php

declare(strict_types=1);

namespace Gereq\Api\OpenApi;

use Gereq\Api\ApiRegistry;
use Gereq\Api\ApiRouteDefinition;
use Gereq\Config\Repository;
use Gereq\Support\AppVersion;
use Gereq\Validation\ValidationRegistry;
use Symfony\Component\HttpFoundation\RequestStack;

final class OpenApiGenerator
{
    public function __construct(
        private readonly ApiRegistry $api,
        private readonly Repository $config,
        private readonly ValidationRegistry $validation,
        private readonly RequestStack $requestStack,
        private readonly ValidationRulesToSchema $rulesToSchema,
    ) {
    }

    /** @return array<string, mixed> */
    public function generate(): array
    {
        $prefix = (string) $this->config->get('api.prefix', '/api/v1');
        $paths = [];
        $tags = [];
        $schemas = [];

        foreach ($this->api->allRoutes() as $route) {
            $path = $this->normalizePath($route->fullPath($prefix));
            $tags[$route->module] = ['name' => $route->module];

            foreach ($route->methods as $method) {
                $opMethod = strtolower($method);
                $operation = $this->operation($route, $path, $opMethod);
                $ruleset = $this->rulesetName($route);
                if ($ruleset !== null && $this->validation->has($ruleset)) {
                    $schemaName = $this->schemaName($ruleset);
                    if (!isset($schemas[$schemaName])) {
                        $schemas[$schemaName] = $this->rulesToSchema->convert($this->validation->rulesFor($ruleset));
                    }
                    if (in_array($opMethod, ['post', 'put', 'patch'], true)) {
                        $operation['requestBody'] = [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/' . $schemaName],
                                ],
                            ],
                        ];
                    }
                } elseif (in_array($opMethod, ['post', 'put', 'patch'], true)) {
                    $operation['requestBody'] = [
                        'required' => false,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'additionalProperties' => true,
                                ],
                            ],
                        ],
                    ];
                }

                $paths[$path][$opMethod] = $operation;
            }
        }

        ksort($paths);
        ksort($tags);
        ksort($schemas);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'GEREQ Platform API',
                'version' => (string) $this->config->get('app.version', AppVersion::DEFAULT),
                'description' => 'Autentizace: session cookie (admin) nebo Bearer token `grq_live_*` / `grq_test_*`.',
            ],
            'servers' => [
                ['url' => $this->serverUrl()],
            ],
            'tags' => array_values($tags),
            'paths' => $paths === [] ? new \stdClass() : $paths,
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
                        'name' => 'gereq_session',
                    ],
                ],
                'schemas' => $schemas === [] ? new \stdClass() : $schemas,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function operation(ApiRouteDefinition $route, string $path, string $method): array
    {
        $summary = $route->summary !== '' ? $route->summary : $this->inferSummary($method, $path, $route->module);
        $parameters = [...$this->pathParameters($path)];
        if ($method === 'get' && !$this->isItemPath($path)) {
            $parameters = [...$parameters, ...$this->listQueryParameters()];
        }

        $responses = [
            '200' => ['description' => 'OK'],
            '401' => ['description' => 'Unauthenticated'],
            '403' => ['description' => 'Forbidden'],
        ];
        if ($method === 'post') {
            $responses = ['201' => ['description' => 'Created']] + $responses;
        }
        if ($method === 'delete') {
            $responses = ['204' => ['description' => 'No Content']] + $responses;
        }
        if (in_array($method, ['post', 'put', 'patch'], true)) {
            $responses['422'] = ['description' => 'Validation error'];
        }

        $op = [
            'operationId' => $this->operationId($method, $path),
            'summary' => $summary,
            'tags' => [$route->module],
            'security' => [['bearerAuth' => []], ['sessionAuth' => []]],
            'x-scopes' => $route->scopes,
            'responses' => $responses,
        ];
        if ($route->scopes !== []) {
            $op['description'] = 'Scopes: `' . implode('`, `', $route->scopes) . '`';
        }
        if ($parameters !== []) {
            $op['parameters'] = $parameters;
        }

        return $op;
    }

    /** @return list<array<string, mixed>> */
    private function pathParameters(string $path): array
    {
        if (!preg_match_all('#\{(\w+)\}#', $path, $m)) {
            return [];
        }

        $out = [];
        foreach (array_unique($m[1]) as $name) {
            $out[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function listQueryParameters(): array
    {
        return [
            [
                'name' => 'page',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
            ],
            [
                'name' => 'q',
                'in' => 'query',
                'required' => false,
                'description' => 'Fulltext search (alias: search)',
                'schema' => ['type' => 'string'],
            ],
            [
                'name' => 'sort',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'string'],
            ],
            [
                'name' => 'direction',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'],
            ],
            [
                'name' => 'filter',
                'in' => 'query',
                'required' => false,
                'description' => 'Filter map, e.g. filter[stage]=won',
                'style' => 'deepObject',
                'explode' => true,
                'schema' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            ],
        ];
    }

    private function isItemPath(string $path): bool
    {
        if (preg_match('#\{[^/]+\}$#', $path)) {
            return true;
        }

        return (bool) preg_match('#\{[^/]+\}/(complete|stage|archive|restore|convert)$#', $path);
    }

    private function normalizePath(string $path): string
    {
        $path = preg_replace('#/+#', '/', $path) ?: '/';
        $path = rtrim($path, '/') ?: '/';

        return preg_replace('#\{(\w+)\}#', '{$1}', $path) ?: $path;
    }

    private function serverUrl(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            return $request->getSchemeAndHttpHost();
        }

        $configured = rtrim((string) $this->config->get('app.url', ''), '/');
        if ($configured !== '' && !str_starts_with($configured, '0.0.0.0')) {
            if (!preg_match('#^https?://#i', $configured)) {
                $configured = 'https://' . $configured;
            }

            return $configured;
        }

        return '';
    }

    private function rulesetName(ApiRouteDefinition $route): ?string
    {
        $fromMeta = $route->meta['ruleset'] ?? null;
        if (is_string($fromMeta) && $fromMeta !== '') {
            return $fromMeta;
        }

        return null;
    }

    private function schemaName(string $ruleset): string
    {
        $name = preg_replace('/[^a-zA-Z0-9]+/', '_', $ruleset) ?: 'Schema';

        return ucwords($name, '_') ?: 'Schema';
    }

    private function operationId(string $method, string $path): string
    {
        $slug = trim(preg_replace('#[^a-zA-Z0-9]+#', '_', $path) ?? '', '_');

        return strtolower($method) . '_' . $slug;
    }

    public function inferSummary(string $method, string $path, string $module): string
    {
        $relative = preg_replace('#^/api/(?:v\d+/[^/]+|public)#', '', $path) ?: '';
        $relative = trim($relative, '/');
        $segments = $relative === '' ? [] : explode('/', $relative);
        $actions = [];
        $resources = [];
        foreach ($segments as $seg) {
            if (preg_match('#^\{#', $seg)) {
                continue;
            }
            if (in_array($seg, ['archive', 'restore', 'complete', 'stage', 'convert', 'attach', 'detach'], true)) {
                $actions[] = $seg;
            } else {
                $resources[] = $seg;
            }
        }

        $resource = $resources !== [] ? end($resources) : $module;
        $resource = str_replace('-', ' ', $resource);
        $singular = str_ends_with($resource, 'ies')
            ? substr($resource, 0, -3) . 'y'
            : (str_ends_with($resource, 's') ? substr($resource, 0, -1) : $resource);

        $action = $actions !== [] ? end($actions) : null;
        if ($action !== null) {
            return ucfirst($action) . ' ' . $singular;
        }

        $hasId = (bool) preg_match('#\{[^/]+\}$#', $path);

        return match ($method) {
            'get' => $hasId ? 'Get ' . $singular : 'List ' . $resource,
            'post' => 'Create ' . $singular,
            'put', 'patch' => 'Update ' . $singular,
            'delete' => 'Delete ' . $singular,
            default => strtoupper($method) . ' ' . $path,
        };
    }
}
