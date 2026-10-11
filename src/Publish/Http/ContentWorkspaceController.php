<?php

declare(strict_types=1);

namespace Gereq\Publish\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Publish\PublishService;
use Gereq\Publish\StagingStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class ContentWorkspaceController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly PublishService $publish,
        private readonly StagingStore $store,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $pending = $this->publish->pending();

        return $this->page('admin/content/staging.html.twig', [
            'pageTitle' => 'publish.workspace.title',
            'pending' => $pending,
            'releases' => $this->publish->releases(20),
            'pending_count' => $pending['count'],
            'content_version' => $pending['version'],
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $entry = $this->publish->entry($id);
        if ($entry === null) {
            if ($this->wantsJson($request)) {
                return new JsonResponse(['ok' => false, 'error' => 'Staging entry not found.'], 404);
            }
            $this->abort(404, 'Staging entry not found.');
        }

        if ($this->wantsJson($request)) {
            return new JsonResponse([
                'ok' => true,
                'data' => $entry,
            ]);
        }

        return $this->page('admin/content/staging_show.html.twig', [
            'pageTitle' => $entry['title'],
            'entry' => $entry,
        ]);
    }

    public function rollback(Request $request): JsonResponse
    {
        $payload = $request->request->all();
        if ($payload === [] && str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            $decoded = json_decode((string) $request->getContent(), true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $releaseId = $payload['release_id'] ?? $payload['id'] ?? '';
        if (!is_string($releaseId) || $releaseId === '') {
            return new JsonResponse(['ok' => false, 'error' => 'release_id required'], 422);
        }

        try {
            $result = $this->publish->rollbackRelease($releaseId, $this->auth->user()?->id());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return new JsonResponse([
            'ok' => true,
            'data' => $result,
        ]);
    }

    public function discard(Request $request): JsonResponse
    {
        $payload = $request->request->all();
        if ($payload === [] && str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            $decoded = json_decode((string) $request->getContent(), true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $ids = $payload['entry_ids'] ?? $payload['ids'] ?? [];
        if (!is_array($ids)) {
            return new JsonResponse(['ok' => false, 'error' => 'entry_ids required'], 422);
        }

        /** @var list<string> $ids */
        $ids = array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== ''));
        if ($ids === []) {
            return new JsonResponse(['ok' => false, 'error' => 'No changes selected'], 422);
        }

        try {
            $result = $this->publish->discard($ids);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return new JsonResponse([
            'ok' => true,
            'data' => array_merge($result, [
                'count' => $this->store->count(),
            ]),
        ]);
    }

    public function count(Request $request): JsonResponse
    {
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        return new JsonResponse([
            'ok' => true,
            'data' => [
                'count' => $this->store->count(),
            ],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
