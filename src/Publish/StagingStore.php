<?php

declare(strict_types=1);

namespace Gereq\Publish;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Publish\Entity\ContentStagingEntry;

final class StagingStore
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function count(): int
    {
        try {
            return (int) $this->em->createQueryBuilder()
                ->select('COUNT(e.id)')
                ->from(ContentStagingEntry::class, 'e')
                ->getQuery()
                ->getSingleScalarResult();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return list<ContentStagingEntry> */
    public function all(): array
    {
        try {
            /** @var list<ContentStagingEntry> $rows */
            $rows = $this->em->getRepository(ContentStagingEntry::class)
                ->findBy([], ['category' => 'ASC', 'updatedAt' => 'DESC']);

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<ContentStagingEntry> */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== ''));
        if ($ids === []) {
            return [];
        }

        /** @var list<ContentStagingEntry> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('e')
            ->from(ContentStagingEntry::class, 'e')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function find(string $category, string $resourceKey): ?ContentStagingEntry
    {
        try {
            /** @var ContentStagingEntry|null $row */
            $row = $this->em->getRepository(ContentStagingEntry::class)->findOneBy([
                'category' => $category,
                'resourceKey' => $resourceKey,
            ]);

            return $row;
        } catch (\Throwable) {
            return null;
        }
    }

    public function findSettingsOverlay(string $key): ?array
    {
        $entry = $this->find('branding', $key);
        if ($entry === null) {
            // Also check any category that stores settings by key
            foreach ($this->all() as $candidate) {
                if ($candidate->resourceType() === 'setting' && $candidate->resourceKey() === $key) {
                    $entry = $candidate;
                    break;
                }
            }
        }

        if ($entry === null) {
            return null;
        }

        $payload = $entry->payload();

        return [
            'value' => $payload['value'] ?? null,
            'type' => (string) ($payload['type'] ?? 'string'),
            'module' => $payload['module'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function upsert(
        string $category,
        string $resourceType,
        string $resourceKey,
        string $action,
        array $payload,
        string $label,
        ?string $createdBy = null,
    ): ContentStagingEntry {
        $existing = $this->find($category, $resourceKey);
        if ($existing instanceof ContentStagingEntry) {
            $existing->replace($action, $payload, $label, $createdBy);
            $this->em->flush();

            return $existing;
        }

        $entry = new ContentStagingEntry(
            $category,
            $resourceType,
            $resourceKey,
            $action,
            $payload,
            $label,
            $createdBy,
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    public function remove(ContentStagingEntry $entry): void
    {
        $this->em->remove($entry);
        $this->em->flush();
    }

    /** @param list<ContentStagingEntry> $entries */
    public function removeMany(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->em->remove($entry);
        }
        $this->em->flush();
    }

    public function isPendingDelete(string $category, string $resourceKey): bool
    {
        $entry = $this->find($category, $resourceKey);

        return $entry instanceof ContentStagingEntry
            && $entry->action() === ContentStagingEntry::ACTION_DELETE;
    }

    public function isPendingCreate(string $category, string $resourceKey): bool
    {
        $entry = $this->find($category, $resourceKey);

        return $entry instanceof ContentStagingEntry
            && $entry->action() === ContentStagingEntry::ACTION_CREATE;
    }

    /** @return list<string> */
    public function pendingDeleteKeys(string $category): array
    {
        return $this->keysWithAction($category, ContentStagingEntry::ACTION_DELETE);
    }

    /** @return list<string> */
    public function pendingCreateKeys(string $category): array
    {
        return $this->keysWithAction($category, ContentStagingEntry::ACTION_CREATE);
    }

    /** @return list<string> */
    private function keysWithAction(string $category, string $action): array
    {
        $keys = [];
        foreach ($this->all() as $entry) {
            if ($entry->category() === $category && $entry->action() === $action) {
                $keys[] = $entry->resourceKey();
            }
        }

        return $keys;
    }
}
