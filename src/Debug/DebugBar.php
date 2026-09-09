<?php

declare(strict_types=1);

namespace Gereq\Debug;

use Gereq\Auth\Authenticator;
use Gereq\Config\Repository;
use Gereq\Event\EventBus;
use Gereq\Event\PlatformEvent;
use Gereq\Http\Router;
use Gereq\Publish\ContentVersion;
use Gereq\Publish\StagingStore;
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
        private readonly ?Authenticator $auth,
        private readonly ?StagingStore $staging = null,
        private readonly ?ContentVersion $contentVersion = null,
    ) {
        $this->mark('start');
        $this->eventsBus->setTracer(function (string $class, float $ms, ?object $event = null): void {
            $row = [
                'event' => $class,
                'duration' => round($ms, 2),
            ];
            if ($event instanceof PlatformEvent) {
                $row['type'] = $event->type();
                $data = $event->data();
                if ($data !== []) {
                    $row['summary'] = $this->summarizeEventData($data);
                }
            }
            $this->events[] = $row;
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
        $normalized = $this->dumper->normalize($value);
        $safe = $this->redactor->value($normalized);

        $this->dumps[] = [
            'value' => $safe,
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

    public const HEADER_SKIP = 'X-Gereq-No-Debug';

    public function inject(Request $request, Response $response): Response
    {
        if (!$this->enabled()) {
            return $response;
        }

        $this->applyNPlusOneHeaders($response);
        $this->reportNPlusOnes($request);

        if ($response instanceof JsonResponse || $this->wantsAjaxDebug($request, $response)) {
            $this->attachAjaxDebugHeader($request, $response);
        }

        if ($this->injected) {
            return $response;
        }
        if ($response instanceof JsonResponse || $response instanceof RedirectResponse) {
            return $response;
        }
        if ($this->shouldSkipHtmlBar($request, $response)) {
            $response->headers->remove(self::HEADER_SKIP);

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

    private function shouldSkipHtmlBar(Request $request, Response $response): bool
    {
        if ($response->headers->get(self::HEADER_SKIP) === '1') {
            return true;
        }
        if ($request->attributes->get('_gereq_skip_debug') === true) {
            return true;
        }
        // Floating / embedded admin shells (AI float iframe, etc.).
        if (strtolower((string) $request->headers->get('Sec-Fetch-Dest', '')) === 'iframe') {
            return true;
        }
        if (in_array($request->query->get('embed'), [true, 1, '1', 'true', 'yes'], true)) {
            return true;
        }

        return false;
    }

    private function wantsAjaxDebug(Request $request, Response $response): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }
        $accept = (string) $request->headers->get('Accept', '');
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        $requestedWith = strtolower((string) $request->headers->get('X-Requested-With', ''));
        if ($requestedWith === 'xmlhttprequest') {
            return true;
        }
        $contentType = (string) $response->headers->get('Content-Type', '');

        return str_contains($contentType, 'application/json');
    }

    private function attachAjaxDebugHeader(Request $request, Response $response): void
    {
        try {
            $payload = $this->ajaxPayload($request, $response);
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            // Nginx / prohlížeče: držet header pod ~6 KiB
            if (strlen($json) > 6000) {
                $payload['events'] = array_slice($payload['events'] ?? [], 0, 20);
                $payload['truncated'] = true;
                $json = json_encode($payload, JSON_THROW_ON_ERROR);
            }
            $response->headers->set('X-Gereq-Debug', $json);
            $expose = (string) $response->headers->get('Access-Control-Expose-Headers', '');
            $parts = array_filter(array_map('trim', explode(',', $expose)));
            if (!in_array('X-Gereq-Debug', $parts, true)) {
                $parts[] = 'X-Gereq-Debug';
                $response->headers->set('Access-Control-Expose-Headers', implode(', ', $parts));
            }
        } catch (\Throwable) {
            // debug nesmí shodit request
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ajaxPayload(Request $request, Response $response): array
    {
        $started = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        return [
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'status' => $response->getStatusCode(),
            'duration' => round((microtime(true) - $started) * 1000, 1),
            'queryCount' => $this->queries->count(),
            'queryMs' => $this->queries->totalMs(),
            'events' => $this->events,
            'exceptions' => array_map(static fn (array $e): array => [
                'class' => $e['class'],
                'message' => $e['message'],
            ], $this->exceptions),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function summarizeEventData(array $data): array
    {
        $keep = [
            'changed_count',
            'changed_folder_ids',
            'synced',
            'action',
            'added_count',
            'removed_count',
            'updated_count',
            'message_id',
            'from_folder_id',
            'folder_id',
        ];
        $out = [];
        foreach ($keep as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }
        if ($out === [] && $data !== []) {
            $i = 0;
            foreach ($data as $k => $v) {
                if ($i >= 6) {
                    break;
                }
                if (is_scalar($v) || $v === null) {
                    $out[(string) $k] = $v;
                    $i++;
                } elseif (is_array($v) && count($v) <= 8 && array_is_list($v)) {
                    $out[(string) $k] = $v;
                    $i++;
                }
            }
        }

        return $out;
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
            'projectRoot' => $this->editorProjectRoot(),
            'serverRoot' => $this->basePath,
            'route' => $this->route($request),
            'request' => $this->requestBag($request),
            'session' => $this->sessionBag($request),
            'user' => $this->userBag(),
            'dumps' => $this->dumps,
            'exceptions' => $this->exceptions,
            'events' => $this->events,
            'timeline' => $this->timelineMs($started),
            'config' => $this->configBag(),
            'staging' => $this->stagingBag(),
        ];
    }

    /**
     * Absolute project root for opening files in a local IDE.
     * Prefer app.debug_editor_root when the app runs in Docker (/var/www/html)
     * but the editor is on the host machine.
     */
    private function editorProjectRoot(): string
    {
        $override = trim((string) $this->config->get('app.debug_editor_root', ''));
        if ($override !== '') {
            return rtrim($override, "/\\");
        }

        return rtrim($this->basePath, "/\\");
    }

    /**
     * @return array{
     *   count: int,
     *   version: string,
     *   entries: list<array{
     *     id: string,
     *     category: string,
     *     action: string,
     *     label: string,
     *     resource_type: string,
     *     resource_key: string,
     *     updated_at: string
     *   }>
     * }
     */
    private function stagingBag(): array
    {
        $version = '—';
        try {
            $version = $this->contentVersion?->current() ?? '—';
        } catch (\Throwable) {
            $version = '—';
        }

        if ($this->staging === null) {
            return [
                'count' => 0,
                'version' => $version,
                'entries' => [],
            ];
        }

        try {
            $entries = [];
            foreach ($this->staging->all() as $entry) {
                $entries[] = [
                    'id' => $entry->id(),
                    'category' => $entry->category(),
                    'action' => $entry->action(),
                    'label' => $entry->label(),
                    'resource_type' => $entry->resourceType(),
                    'resource_key' => $entry->resourceKey(),
                    'updated_at' => $entry->updatedAt()->format('c'),
                ];
            }

            return [
                'count' => count($entries),
                'version' => $version,
                'entries' => $entries,
            ];
        } catch (\Throwable) {
            return [
                'count' => 0,
                'version' => $version,
                'entries' => [],
            ];
        }
    }

    /** @return list<array{name: string, file: ?string, duration: float}> */
    private function views(): array
    {
        if ($this->twigProfile === null) {
            return [];
        }

        return $this->collectViews($this->twigProfile);
    }

    /**
     * @return list<array{name: string, file: ?string, duration: float}>
     */
    private function collectViews(Profile $profile): array
    {
        $items = [];
        if ($profile->isTemplate()) {
            $name = $profile->getTemplate();
            $items[] = [
                'name' => $name,
                'file' => $this->resolveTwigRelativePath($name),
                'duration' => round($profile->getDuration() * 1000, 2),
            ];
        }
        foreach ($profile->getProfiles() as $child) {
            $items = [...$items, ...$this->collectViews($child)];
        }

        return $items;
    }

    /**
     * Map a Twig logical name (@ns/… or resources/views path) to a path
     * relative to the project root, mirroring TwigFactory loader paths.
     */
    private function resolveTwigRelativePath(string $name): ?string
    {
        $absolute = $this->locateTwigTemplate($name);
        if ($absolute === null) {
            return null;
        }

        $rel = $this->rel($absolute);

        return $rel !== '' ? $rel : null;
    }

    private function locateTwigTemplate(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);
        if (preg_match('#^@([^/]+)/(.+)$#', $name, $m) === 1) {
            $namespace = strtolower($m[1]);
            $relative = $m[2];
            foreach (['modules', 'integrations'] as $bucket) {
                $root = $this->basePath . '/' . $bucket;
                if (!is_dir($root)) {
                    continue;
                }
                foreach (scandir($root) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..' || strtolower($entry) !== $namespace) {
                        continue;
                    }
                    $path = $root . '/' . $entry . '/Resources/views/' . $relative;
                    if (is_file($path)) {
                        return $path;
                    }
                }
            }

            return null;
        }

        $path = $this->basePath . '/resources/views/' . ltrim($name, '/');

        return is_file($path) ? $path : null;
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
            $permissions = [];
            foreach ($role->permissions() as $permission) {
                $permissions[] = $permission->name();
            }
            $roles[] = [
                'id' => $role->id(),
                'name' => $role->name(),
                'label' => $role->label(),
                'description' => $role->description(),
                'privileged' => $role->isPrivileged(),
                'permissions' => $permissions,
            ];
        }

        $payload = [
            'guest' => false,
            'id' => $user->id(),
            'name' => $user->name(),
            'email' => $user->email(),
            'status' => $user->status(),
            'locale' => $user->locale(),
            'initials' => $user->initials(),
            'isActive' => $user->isActive(),
            'isAdministrator' => $user->isAdministrator(),
            'totpEnabled' => $user->totpEnabled(),
            'passkeyEnabled' => $user->passkeyEnabled(),
            'oauthProvider' => $user->oauthProvider(),
            'oauthId' => $user->oauthId(),
            'lastLoginAt' => $user->lastLoginAt()?->format('c'),
            'createdAt' => $user->createdAt()->format('c'),
            'updatedAt' => $user->updatedAt()->format('c'),
            'roles' => $roles,
            'permissions' => $user->permissionNames(),
        ];

        $value = $this->redactor->value($payload);

        return is_array($value) ? $value : $payload;
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
            'app.debug_editor_root' => $this->config->get('app.debug_editor_root'),
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
