<?php

declare(strict_types=1);

namespace Modules\Notifications\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Notification\DatabaseNotification;
use Gereq\Notification\Notifier;
use Gereq\User\Entity\User;
use Modules\Notifications\Domain\NotificationPreference;

final class NotifierBridge
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Notifier $notifier,
    ) {
    }

    /** @param array<string, mixed> $meta */
    public function notifyUser(User $user, string $eventType, string $title, string $body, array $meta = []): void
    {
        $channels = [];
        if ($this->allowed($user->id(), 'in_app', $eventType)) {
            $channels[] = 'database';
        }
        if ($this->allowed($user->id(), 'email', $eventType)) {
            $channels[] = 'email';
        }
        if ($channels === []) {
            return;
        }

        $this->notifier->send($user, new SimpleNotification($title, $body, $meta + ['event_type' => $eventType], $channels));
    }

    public function notifyAdmins(string $eventType, string $title, string $body, array $meta = []): void
    {
        $users = $this->em->getRepository(User::class)->findBy(['status' => 'active']);
        foreach ($users as $user) {
            if ($user instanceof User && $user->can('notifications.view')) {
                $this->notifyUser($user, $eventType, $title, $body, $meta);
            }
        }
    }

    public function unreadCount(string $userId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(n.id)')
            ->from(DatabaseNotification::class, 'n')
            ->where('n.userId = :uid')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('uid', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<DatabaseNotification> */
    public function inbox(string $userId, int $limit = 50): array
    {
        return $this->em->getRepository(DatabaseNotification::class)->findBy(
            ['userId' => $userId],
            ['createdAt' => 'DESC'],
            $limit,
        );
    }

    private function allowed(string $userId, string $channel, string $eventType): bool
    {
        $pref = $this->em->getRepository(NotificationPreference::class)->findOneBy([
            'userId' => $userId,
            'channel' => $channel,
            'eventType' => $eventType,
        ]);
        if ($pref instanceof NotificationPreference) {
            return $pref->enabled();
        }
        // default on for in_app, off for email unless preference exists
        return $channel === 'in_app' || $channel === 'database';
    }
}