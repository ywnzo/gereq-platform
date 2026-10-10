<?php

declare(strict_types=1);

namespace Gereq\Notifications\Http\Public;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Auth\Authenticator;
use Gereq\Http\PublicCors;
use Gereq\User\Entity\User;
use Gereq\Notifications\Application\MemberNotifier;
use Gereq\Notifications\Application\NotificationSse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Gereq\Debug\DebugBar;

final class PublicMemberNotificationsController
{
    public function __construct(
        private readonly MemberNotifier $notifier,
        private readonly NotificationSse $sse,
        private readonly Authenticator $auth,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function options(Request $request): Response
    {
        return PublicCors::apply(new Response('', 204), $request, 'GET, POST, OPTIONS');
    }

    public function features(Request $request): Response
    {
        return PublicCors::apply(ApiResponse::data(['enabled' => true, 'sse' => true]), $request, 'GET, OPTIONS');
    }

    public function index(Request $request): Response
    {
        $actorId = $this->actorId($request);
        if ($actorId === null) {
            return PublicCors::apply(new JsonResponse(['error' => 'unauthenticated'], 401), $request);
        }

        $page = $this->notifier->inboxPage($actorId, [
            'page' => $request->query->get('page', 1),
            'per_page' => $request->query->get('per_page', $request->query->get('limit', 50)),
            'category' => $request->query->get('category'),
            'status' => $request->query->get('status'),
            'event_id' => $request->query->get('event_id'),
        ]);

        return PublicCors::apply(ApiResponse::data($page), $request, 'GET, OPTIONS');
    }

    public function streamToken(Request $request): Response
    {
        $actorId = $this->actorId($request);
        if ($actorId === null) {
            return PublicCors::apply(new JsonResponse(['error' => 'unauthenticated'], 401), $request);
        }

        return PublicCors::apply(ApiResponse::data($this->sse->issue($actorId)), $request, 'GET, POST, OPTIONS');
    }

    public function stream(Request $request): Response
    {
        // Prefer store Bearer/cookie (no token in URL). Fall back to short-lived SSE token
        // from Authorization header, then legacy ?access_token= query.
        $accountId = $this->actorId($request);
        if ($accountId === null) {
            $token = $this->sseTokenFromRequest($request);
            $accountId = $this->sse->accountIdFromToken($token);
        }
        if ($accountId === null) {
            return PublicCors::apply(new JsonResponse(['error' => 'unauthenticated'], 401), $request, 'GET, OPTIONS');
        }
        $since = trim((string) ($request->query->get('since') ?: $request->headers->get('Last-Event-ID', '')));
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session !== null && $session->isStarted()) {
            $session->save();
        }

        $sse = $this->sse;
        $response = new StreamedResponse(static function () use ($sse, $accountId, $since): void {
            $sse->stream($accountId, $since);
        });
        $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set(DebugBar::HEADER_SKIP, '1');

        return PublicCors::apply($response, $request, 'GET, OPTIONS');
    }

    public function markRead(Request $request, string $id): Response
    {
        $actorId = $this->actorId($request);
        if ($actorId === null) {
            return PublicCors::apply(new JsonResponse(['error' => 'unauthenticated'], 401), $request);
        }
        $this->notifier->markRead($actorId, $id);

        return PublicCors::apply(ApiResponse::data(['status' => 'read']), $request);
    }

    public function markAllRead(Request $request): Response
    {
        $actorId = $this->actorId($request);
        if ($actorId === null) {
            return PublicCors::apply(new JsonResponse(['error' => 'unauthenticated'], 401), $request);
        }
        $this->notifier->markAllRead($actorId);

        return PublicCors::apply(ApiResponse::data(['status' => 'read_all']), $request);
    }

    private function sseTokenFromRequest(Request $request): string
    {
        $auth = (string) $request->headers->get('Authorization', '');
        if (preg_match('/^Bearer\s+(\S+)/i', $auth, $m) === 1) {
            $bearer = trim($m[1]);
            if (NotificationSse::looksLikeToken($bearer)) {
                return $bearer;
            }
        }

        return trim((string) $request->query->get('access_token', ''));
    }

    private function actorId(Request $request): ?string
    {
        // Prefer store Bearer/cookie over admin session — gereq_session is
        // host-scoped and would otherwise shadow the member on Vite proxy.
        if (
            class_exists(\Gereq\Customers\Domain\CustomerAccount::class)
            && class_exists(\Gereq\Customers\Application\StoreCookieBridge::class)
            && class_exists(\Gereq\Customers\Application\StoreTokenService::class)
        ) {
            try {
                $cookies = gereq()->make(\Gereq\Customers\Application\StoreCookieBridge::class);
                $tokens = gereq()->make(\Gereq\Customers\Application\StoreTokenService::class);
                $token = $cookies->tokenFromRequest($request);
                if ($token !== null) {
                    $claims = $tokens->parse($token);
                    if ($claims !== null) {
                        $account = $this->em->find(\Gereq\Customers\Domain\CustomerAccount::class, $claims['sub']);
                        if (
                            $account instanceof \Gereq\Customers\Domain\CustomerAccount
                            && $account->isActive()
                            && $account->tokenVersion() === $claims['ver']
                        ) {
                            return $account->id();
                        }
                    }
                }
            } catch (\Throwable) {
                // fall through to admin session
            }
        }

        $user = $this->auth->user();
        if ($user instanceof User) {
            return $user->id();
        }

        return null;
    }
}
