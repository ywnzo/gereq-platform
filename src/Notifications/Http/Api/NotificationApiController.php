<?php

declare(strict_types=1);

namespace Gereq\Notifications\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Notifications\Channel\DatabaseNotification;
use Gereq\User\Entity\User;
use Gereq\Notifications\Application\NotifierBridge;
use Gereq\Notifications\Domain\NotificationPreference;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class NotificationApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly NotifierBridge $bridge,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): JsonResponse
    {
        $user = $this->user();
        $items = $this->bridge->inbox($user->id(), 50);

        return ApiResponse::collection(array_map([$this, 'serialize'], $items), [
            'unread' => $this->bridge->unreadCount($user->id()),
        ]);
    }

    public function markRead(string $id): JsonResponse
    {
        $user = $this->user();
        $item = $this->em->find(DatabaseNotification::class, $id);
        if (
            !$item instanceof DatabaseNotification
            || $item->actorType() !== DatabaseNotification::ACTOR_USER
            || $item->userId() !== $user->id()
        ) {
            return $this->jsonError('not_found', 'Notification not found', [], 404);
        }
        $item->markRead();
        $this->em->flush();

        return ApiResponse::data($this->serialize($item));
    }

    public function preferences(): JsonResponse
    {
        $user = $this->user();
        $prefs = $this->em->getRepository(NotificationPreference::class)->findBy(['userId' => $user->id()]);

        return ApiResponse::collection(array_map(static fn (NotificationPreference $p): array => [
            'id' => $p->id(),
            'channel' => $p->channel(),
            'event_type' => $p->eventType(),
            'enabled' => $p->enabled(),
        ], $prefs));
    }

    private function user(): User
    {
        $user = $this->auth->user();
        if (!$user instanceof User) {
            $this->abort(401);
        }

        return $user;
    }

    private function serialize(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id(),
            'type' => $n->type(),
            'data' => $n->data(),
            'read_at' => $n->readAt()?->format(DATE_ATOM),
            'created_at' => $n->createdAt()->format(DATE_ATOM),
        ];
    }
}
