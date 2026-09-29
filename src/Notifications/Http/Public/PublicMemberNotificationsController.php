<?php

declare(strict_types=1);

namespace Gereq\Notifications\Http\Public;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Auth\Authenticator;
use Gereq\Http\PublicCors;
use Gereq\User\Entity\User;
use Gereq\Notifications\Application\MemberNotifier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicMemberNotificationsController
{
    public function __construct(
        private readonly MemberNotifier $notifier,
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
        return PublicCors::apply(ApiResponse::data(['enabled' => true]), $request, 'GET, OPTIONS');
    }

    public function index(Request $request): Response
    {
        $actorId = $this->actorId($request);
        if ($actorId === null) {
            return PublicCors::apply(new JsonResponse(['error' => 'unauthenticated'], 401), $request);
        }
        $category = $request->query->get('category');
        $items = $this->notifier->inbox($actorId, 50, is_string($category) ? $category : null);

        return PublicCors::apply(ApiResponse::data([
            'items' => $items,
            'unread' => $this->notifier->unreadCount($actorId),
        ]), $request, 'GET, OPTIONS');
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
