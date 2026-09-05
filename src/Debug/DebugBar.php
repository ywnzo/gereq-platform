<?php

declare(strict_types=1);

namespace Gereq\Debug;

use Gereq\Auth\Authenticator;
use Gereq\Config\Repository;
use Gereq\Event\EventBus;
use Gereq\Http\Router;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Profiler\Profile;

final class DebugBar
{
    /** @var list<array{label: string, at: float}> */
    private array $timeline = [];

    /** @var list<array{html: string, file: string, line: int}> */
    private array $dumps = [];

    /** @var list<array{class: string, message: string, file: string, line: int}> */
    private array $exceptions = [];

    /** @var list<array{event: string, duration: float}> */
    private array $events = [];

    private ?Profile $twigProfile = null;

    private bool $injected = false;

    private bool $nPlusOneReported = false;

    public function __construct(
        private readonly Repository $config,
        private readonly QueryCollector $queries,
        private readonly Dumper $dumper,
        private readonly Redactor $redactor,
        private readonly Renderer $renderer,
        private readonly Router $router,
        private readonly EventBus $eventsBus,
        private readonly string $basePath,
        private readonly ?Authenticator $auth = null,
    ) {
        $this->mark('start');
        $this->eventsBus->setTracer(function (string $class, float $ms): void {
            $this->events[] = ['event' => $class, 'duration' => round($ms, 2)];
        });
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('app.debug', false);
    }

    public function queries(): QueryCollector
    {
        return $this->queries;
    }

    public function setTwigProfile(Profile $profile): void
    {
        $this->twigProfile = $profile;
    }

    public function twigProfile(): ?Profile
    {
        return $this->twigProfile;
    }

    public function mark(string $label): void
    {
        $this->timeline[] = [
            'label' => $label,
            'at' => microtime(true),
        ];
    }

    public function addDump(mixed $value, string $file = '', int $line = 0): void
    {
        $this->dumps[] = [
            'html' => $this->dumper->dump($this->redactor->value($value)),
            'file' => $file !== '' ? $this->rel($file) : '',
            'line' => $line,
        ];
    }

    public function addException(\Throwable $exception): void
    {
        $current = $exception;
        while ($current !== null) {
            $this->exceptions[] = [
                'class' => $current::class,
                'message' => $current->getMessage(),
                'file' => $this->rel($current->getFile()),
                'line' => $current->getLine(),
            ];
            $current = $current->getPrevious();
        }
    }

    public function inject(Request $request, Response $response): Response
    {
        if (!$this->enabled()) {
            return $response;
        }

        $this->applyNPlusOneHeaders($response);
        $this->reportNPlusOnes($request);

        if ($this->injected) {
            return $response;
        }
        if ($response instanceof JsonResponse || $response instanceof RedirectResponse) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type', 'text/html');
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return $response;
        }

        $html = $response->getContent();
        if (!is_string($html) || str_contains($html, 'id="gereq-debug"')) {
            return $response;
        }

        $nonce = (string) $request->attributes->get('csp_nonce', '');
        $bar = $this->renderer->render($this->payload($request, $response), $nonce);

        if (str_contains($html, '</body>')) {
            $html = str_replace('</body>', $bar . '</body>', $html);
        } elseif (str_contains($html, '</html>')) {
            $html = str_replace('</html>', $bar . '</html>', $html);
        } else {
            $html .= $bar;
        }

        $response->setContent($html);
        $this->injected = true;

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Request $request, Response $response): array
    {
        $started = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $duration = (microtime(true) - $started) * 1000;
        $memory = memory_get_peak_usage(true);

        return [
            'status' => $response->getStatusCode(),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'duration' => round($duration, 1),
            'memory' => $this->bytes($memory),
            'memoryBytes' => $memory,
            'php' => PHP_VERSION,
            'env' => (string) $this->config->get('app.env', 'local'),
            'queries' => $this->queries->all(),
            'queryCount' => $this->queries->count(),
            'queryMs' => $this->queries->totalMs(),
            'nPlusOnes' => $this->queries->nPlusOnes(),
            'nPlusOneCount' => $this->queries->nPlusOneCount(),
            'nPlusOneThreshold' => $this->queries->nPlusOneThreshold(),
            'views' => $this->views(),
            'route' => $this->route($request),
            'request' => $this->requestBag($request),
            'session' => $this->sessionBag($request),
            'user' => $this->userBag(),
            'dumps' => $this->dumps,
            'exceptions' => $this->exceptions,
            'events' => $this->events,
            'timeline' => $this->timelineMs($started),
            'config' => $this->configBag(),
        ];
    }

    /** @return list<array{name: string, duration: float}> */
    private function views(): array
    {
        if ($this->twigProfile === null) {
            return [];
        }

        return $this->collectViews($this->twigProfile);
    }

    /**
     * @return list<array{name: string, duration: float}>
     */
    private function collectViews(Profile $profile): array
    {
        $items = [];
        if ($profile->isTemplate()) {
            $items[] = [
                'name' => $profile->getTemplate(),
                'duration' => round($profile->getDuration() * 1000, 2),
            ];
        }
        foreach ($profile->getProfiles() as $child) {
            $items = [...$items, ...$this->collectViews($child)];
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function route(Request $request): array
    {
        $name = (string) $request->attributes->get('_route', '');
        $matched = null;
        if ($name !== '') {
            foreach ($this->router->all() as $route) {
                if ($route->getName() === $name) {
                    $matched = $route;
                    break;
                }
            }
        }

        $handler = $matched?->getHandler();
        $handlerLabel = is_array($handler)
            ? implode('::', $handler)
            : (is_string($handler) ? $handler : 'closure');

        return [
            'name' => $name !== '' ? $name : '—',
            'path' => $matched?->getPath() ?? $request->getPathInfo(),
            'methods' => $matched?->getMethods() ?? [$request->getMethod()],
            'handler' => $handlerLabel,
            'middleware' => $matched?->getMiddleware() ?? [],
            'permission' => $matched?->getPermission(),
            'params' => $request->attributes->get('_route_params', []),
        ];
    }

    /** @return array<string, mixed> */
    private function requestBag(Request $request): array
    {
        return [
            'url' => $request->getUri(),
            'ip' => $request->getClientIp(),
            'query' => $this->redactor->value($request->query->all()),
            'request' => $this->redactor->value($request->request->all()),
            'headers' => $this->redactor->value($request->headers->all()),
        ];
    }

    /** @return array<string, mixed> */
    private function sessionBag(Request $request): array
    {
        if (!$request->hasSession()) {
            return [];
        }

        try {
            $value = $this->redactor->value($request->getSession()->all());

            return is_array($value) ? $value : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    private function userBag(): array
    {
        $user = $this->auth?->user();
        if ($user === null) {
            return ['guest' => true];
        }

        $roles = [];
        foreach ($user->roles() as $role) {
            $roles[] = $role->name();
        }

        return [
            'guest' => false,
            'id' => $user->id(),
            'name' => $user->name(),
            'email' => $user->email(),
            'roles' => $roles,
        ];
    }

    /**
     * @return list<array{label: string, ms: float}>
     */
    private function timelineMs(float $started): array
    {
        $rows = [];
        foreach ($this->timeline as $item) {
            $rows[] = [
                'label' => $item['label'],
                'ms' => round(($item['at'] - $started) * 1000, 1),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function configBag(): array
    {
        $value = $this->redactor->value([
            'app.name' => $this->config->get('app.name'),
            'app.env' => $this->config->get('app.env'),
            'app.debug' => $this->config->get('app.debug'),
            'app.n_plus_one_threshold' => $this->config->get('app.n_plus_one_threshold'),
            'app.url' => $this->config->get('app.url'),
            'app.version' => $this->config->get('app.version'),
            'database.connection' => $this->config->get('database.connection'),
            'cache.driver' => $this->config->get('cache.driver'),
            'mail.dsn' => $this->config->get('mail.dsn'),
        ]);

        return is_array($value) ? $value : [];
    }

    private function rel(string $file): string
    {
        if ($this->basePath !== '' && str_starts_with($file, $this->basePath)) {
            return ltrim(substr($file, strlen($this->basePath)), '/');
        }

        return $file;
    }

    private function applyNPlusOneHeaders(Response $response): void
    {
        $hits = $this->queries->nPlusOnes();
        if ($hits === []) {
            return;
        }

        $response->headers->set('X-Debug-N-Plus-One', (string) count($hits));
        $response->headers->set(
            'X-Debug-N-Plus-One-Queries',
            (string) array_sum(array_map(static fn (array $hit): int => $hit['count'], $hits)),
        );
    }

    private function reportNPlusOnes(Request $request): void
    {
        if ($this->nPlusOneReported || !(bool) $this->config->get('app.n_plus_one_log', true)) {
            return;
        }

        $hits = $this->queries->nPlusOnes();
        if ($hits === []) {
            return;
        }

        $this->nPlusOneReported = true;
        $path = $request->getMethod() . ' ' . $request->getPathInfo();
        foreach ($hits as $hit) {
            $origins = $hit['origins'] !== [] ? implode(', ', $hit['origins']) : '—';
            $sql = strlen($hit['sql']) > 240 ? substr($hit['sql'], 0, 240) . '…' : $hit['sql'];
            error_log(sprintf(
                '[N+1] %s · %dx · %s ms · %s · %s',
                $path,
                $hit['count'],
                $hit['totalMs'],
                $origins,
                $sql,
            ));
        }
    }

    private function bytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return round($bytes / 1024) . ' kB';
    }
}
