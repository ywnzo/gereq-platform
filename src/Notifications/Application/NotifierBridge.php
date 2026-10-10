<?php

declare(strict_types=1);

namespace Gereq\Notifications\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Notifications\Channel\DatabaseNotification;
use Gereq\Notifications\Channel\Notifier;
use Gereq\Notifications\Domain\NotificationPreference;
use Gereq\User\Entity\User;

final class NotifierBridge
{
    /** @var array<string, int> */
    private array $unreadCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Notifier $notifier,
    ) {
    }

    /** @param array<string, mixed> $meta */
    public function notifyUser(User $user, string $eventType, string $title, string $body, array $meta = []): void
    {
        $prefs = $this->preferencesFor($user->id(), $eventType);
        $channels = [];
        if ($this->channelAllowed($prefs, 'in_app')) {
            $channels[] = 'database';
        }
        if ($this->channelAllowed($prefs, 'email')) {
            $channels[] = 'email';
        }
        if ($channels === []) {
            return;
        }

        $this->notifier->send($user, new SimpleNotification($title, $body, $meta + ['event_type' => $eventType], $channels));
        unset($this->unreadCache[$user->id()]);
    }

    /** @param array<string, mixed> $meta */
    public function notifyAdmins(string $eventType, string $title, string $body, array $meta = []): void
    {
        $users = $this->usersWithNotificationView();
        if ($users === []) {
            return;
        }

        $prefsByUser = $this->preferencesForUsers(
            array_map(static fn (User $u): string => $u->id(), $users),
            $eventType,
        );

        foreach ($users as $user) {
            $prefs = $prefsByUser[$user->id()] ?? [];
            $channels = [];
            if ($this->channelAllowed($prefs, 'in_app')) {
                $channels[] = 'database';
            }
            if ($this->channelAllowed($prefs, 'email')) {
                $channels[] = 'email';
            }
            if ($channels === []) {
                continue;
            }
            $this->notifier->send($user, new SimpleNotification($title, $body, $meta + ['event_type' => $eventType], $channels));
            unset($this->unreadCache[$user->id()]);
        }
    }

    public function unreadCount(string $userId): int
    {
        if (array_key_exists($userId, $this->unreadCache)) {
            return $this->unreadCache[$userId];
        }

        return $this->unreadCache[$userId] = (int) $this->em->createQueryBuilder()
            ->select('COUNT(n.id)')
            ->from(DatabaseNotification::class, 'n')
            ->where('n.userId = :uid')
            ->andWhere('n.actorType = :actor')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('uid', $userId)
            ->setParameter('actor', DatabaseNotification::ACTOR_USER)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<DatabaseNotification> */
    public function inbox(string $userId, int $limit = 50): array
    {
        return $this->em->getRepository(DatabaseNotification::class)->findBy(
            ['userId' => $userId, 'actorType' => DatabaseNotification::ACTOR_USER],
            ['createdAt' => 'DESC'],
            $limit,
        );
    }

    /** @return list<User> */
    private function usersWithNotificationView(): array
    {
        /** @var list<User> $users */
        $users = $this->em->createQueryBuilder()
            ->select('DISTINCT u')
            ->from(User::class, 'u')
            ->leftJoin('u.roles', 'r')
            ->leftJoin('r.permissions', 'p')
            ->where('u.status = :status')
            ->andWhere('r.name IN (:privileged) OR p.name = :perm')
            ->setParameter('status', 'active')
            ->setParameter('privileged', ['superadmin', 'administrator', 'admin'])
            ->setParameter('perm', 'notifications.view')
            ->getQuery()
            ->getResult();

        return $users;
    }

    /**
     * @return array<string, bool> channel => enabled (missing = use default)
     */
    private function preferencesFor(string $userId, string $eventType): array
    {
        $map = $this->preferencesForUsers([$userId], $eventType);

        return $map[$userId] ?? [];
    }

    /**
     * @param list<string> $userIds
     * @return array<string, array<string, bool>> userId => channel => enabled
     */
    private function preferencesForUsers(array $userIds, string $eventType): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn (string $id): bool => $id !== '')));
        if ($userIds === []) {
            return [];
        }

        /** @var list<NotificationPreference> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('p')
            ->from(NotificationPreference::class, 'p')
            ->where('p.userId IN (:uids)')
            ->andWhere('p.eventType = :event')
            ->setParameter('uids', $userIds)
            ->setParameter('event', $eventType)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->userId()][$row->channel()] = $row->enabled();
        }

        return $out;
    }

    /** @param array<string, bool> $prefs */
    private function channelAllowed(array $prefs, string $channel): bool
    {
        if (array_key_exists($channel, $prefs)) {
            return $prefs[$channel];
        }

        // Default on for in_app, off for email unless preference exists.
        return $channel === 'in_app' || $channel === 'database';
    }
}
