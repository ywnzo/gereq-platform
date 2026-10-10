<?php

declare(strict_types=1);

namespace Gereq\Publish\Http;

use Gereq\Auth\Authenticator;
use Gereq\Publish\PublishService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PublishController
{
    public function __construct(
        private readonly PublishService $publish,
        private readonly Authenticator $auth,
    ) {
    }

    public function pending(Request $request): JsonResponse
    {
        $preview = max(0, (int) $request->query->get('preview', 0));
        $data = $this->publish->pending($preview);

        // Release the session lock before JSON encode — badge hover is concurrent
        // with other admin GETs and must not hold Redis session locks longer than needed.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        return new JsonResponse([
            'ok' => true,
            'data' => $data,
        ]);
    }

    public function publish(Request $request): JsonResponse
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
            $result = $this->publish->publish($ids, $this->auth->user()?->id());
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

    public function releases(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));

        return new JsonResponse([
            'ok' => true,
            'data' => [
                'releases' => $this->publish->releases($limit),
            ],
        ]);
    }
}
