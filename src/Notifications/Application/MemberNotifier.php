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
        $page = $this->inboxPage($accountId, [
            'page' => 1,
            'per_page' => $limit,
            'category' => $category,
        ]);

        return $page['items'];
    }

    /**
     * @param array{page?: int|string, per_page?: int|string, category?: ?string, status?: ?string, event_id?: ?string} $query
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, unread: int}
     */
    public function inboxPage(string $accountId, array $query = []): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? 10);
        if ($perPage < 1) {
            $perPage = 10;
        }
        if ($perPage > 50) {
            $perPage = 50;
        }
        $category = trim((string) ($query['category'] ?? ''));
        $status = strtolower(trim((string) ($query['status'] ?? '')));
        $eventId = trim((string) ($query['event_id'] ?? ''));

        /** @var list<DatabaseNotification> $rows */
        $rows = $this->em->getRepository(DatabaseNotification::class)->findBy(
            ['userId' => $accountId, 'type' => 'member_feed'],
            ['createdAt' => 'DESC'],
            500,
        );

        $filtered = [];
        foreach ($rows as $row) {
            $item = $this->serialize($row);
            if ($category !== '' && $category !== 'all' && $item['category'] !== $category) {
                continue;
            }
            if ($eventId !== '' && (string) ($item['meta']['event_id'] ?? '') !== $eventId) {
                continue;
            }
            if (!$this->matchesStatus($item, $status)) {
                continue;
            }
            $filtered[] = $item;
        }

        $total = count($filtered);
        $offset = ($page - 1) * $perPage;
        if ($offset >= $total && $total > 0) {
            $page = (int) max(1, (int) ceil($total / $perPage));
            $offset = ($page - 1) * $perPage;
        }

        return [
            'items' => array_slice($filtered, $offset, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'unread' => $this->unreadCount($accountId),
        ];
    }

    /** @param array<string, mixed> $item */
    private function matchesStatus(array $item, string $status): bool
    {
        if ($status === '' || $status === 'all') {
            return true;
        }
        $type = strtolower((string) ($item['event_type'] ?? ''));
        $read = (bool) ($item['read'] ?? false);

        return match ($status) {
            'unread' => !$read,
            'read' => $read,
            'rejected' => str_contains($type, 'reject') || str_contains($type, 'fail'),
            'published', 'approved' => str_contains($type, 'publish') || str_contains($type, 'approv'),
            default => true,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function inboxSince(string $accountId, string $afterId, ?\DateTimeImmutable $notBefore = null, int $limit = 20): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('n')
            ->from(DatabaseNotification::class, 'n')
            ->where('n.userId = :uid')
            ->andWhere('n.type = :type')
            ->setParameter('uid', $accountId)
            ->setParameter('type', 'member_feed')
            ->orderBy('n.id', 'ASC')
            ->setMaxResults($limit);
        if ($afterId !== '') {
            $qb->andWhere('n.id > :after')->setParameter('after', $afterId);
        } elseif ($notBefore instanceof \DateTimeImmutable) {
            $qb->andWhere('n.createdAt >= :from')->setParameter('from', $notBefore);
        } else {
            return [];
        }

        /** @var list<DatabaseNotification> $rows */
        $rows = $qb->getQuery()->getResult();
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->serialize($row);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function serialize(DatabaseNotification $row): array
    {
        $data = $row->data();

        $meta = $data['meta'] ?? null;
        $html = (string) ($data['html'] ?? '');
        if ($html === '' && is_array($meta)) {
            $html = (string) ($meta['html'] ?? $meta['reason'] ?? '');
        }

        $publicMeta = [];
        if (is_array($meta)) {
            foreach (['event_id', 'slug', 'reason'] as $key) {
                if (!array_key_exists($key, $meta)) {
                    continue;
                }
                $value = $meta[$key];
                if (is_string($value) || is_int($value) || is_float($value)) {
                    $publicMeta[$key] = (string) $value;
                }
            }
        }

        return [
            'id' => $row->id(),
            'title' => (string) ($data['title'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'html' => $html,
            'category' => (string) ($data['category'] ?? 'all'),
            'event_type' => (string) ($data['event_type'] ?? ''),
            'meta' => $publicMeta,
            'read' => $row->readAt() !== null,
            'created_at' => $row->createdAt()->format(\DateTimeInterface::ATOM),
        ];
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
