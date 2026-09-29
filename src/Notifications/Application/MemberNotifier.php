<?php

declare(strict_types=1);

namespace Gereq\Notifications\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Notification\DatabaseNotification;
use Gereq\Notifications\Domain\NotificationPreference;

/**
 * Member (CustomerAccount) in-app notification feed for the storefront.
 */
final class MemberNotifier
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @param array<string, mixed> $meta */
    public function notify(string $accountId, string $eventType, string $title, string $body, string $category = 'all', array $meta = []): void
    {
        if ($accountId === '' || !$this->allowed($accountId, 'in_app', $eventType)) {
            return;
        }

        $record = new DatabaseNotification($accountId, 'member_feed', [
            'title' => $title,
            'body' => $body,
            'category' => $category,
            'event_type' => $eventType,
            'meta' => $meta,
        ]);
        $this->em->persist($record);
        $this->em->flush();
    }

    public function unreadCount(string $accountId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(n.id)')
            ->from(DatabaseNotification::class, 'n')
            ->where('n.userId = :uid')
            ->andWhere('n.type = :type')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('uid', $accountId)
            ->setParameter('type', 'member_feed')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<array<string, mixed>> */
    public function inbox(string $accountId, int $limit = 50, ?string $category = null): array
    {
        /** @var list<DatabaseNotification> $rows */
        $rows = $this->em->getRepository(DatabaseNotification::class)->findBy(
            ['userId' => $accountId, 'type' => 'member_feed'],
            ['createdAt' => 'DESC'],
            $limit,
        );
        $out = [];
        foreach ($rows as $row) {
            $data = $row->data();
            $cat = (string) ($data['category'] ?? 'all');
            if ($category !== null && $category !== '' && $category !== 'all' && $cat !== $category) {
                continue;
            }
            $out[] = [
                'id' => $row->id(),
                'title' => (string) ($data['title'] ?? ''),
                'body' => (string) ($data['body'] ?? ''),
                'category' => $cat,
                'read' => $row->readAt() !== null,
                'created_at' => $row->createdAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $out;
    }

    public function markRead(string $accountId, string $id): void
    {
        $row = $this->em->find(DatabaseNotification::class, $id);
        if ($row instanceof DatabaseNotification && $row->userId() === $accountId && $row->readAt() === null) {
            $row->markRead();
            $this->em->flush();
        }
    }

    public function markAllRead(string $accountId): void
    {
        $rows = $this->em->getRepository(DatabaseNotification::class)->findBy([
            'userId' => $accountId,
            'type' => 'member_feed',
            'readAt' => null,
        ]);
        foreach ($rows as $row) {
            if ($row instanceof DatabaseNotification) {
                $row->markRead();
            }
        }
        $this->em->flush();
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

        return $channel === 'in_app' || $channel === 'database';
    }
}
