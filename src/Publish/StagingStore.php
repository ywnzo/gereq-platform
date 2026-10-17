<?php

declare(strict_types=1);

namespace Gereq\Publish;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Publish\Entity\ContentStagingEntry;

final class StagingStore
{
    /** @var array<string, ContentStagingEntry|false> */
    private array $findCache = [];

    /** @var array<string, true> Categories whose full set is already in findCache (misses = null). */
    private array $categoryFullyLoaded = [];

    private bool $allLoaded = false;

    private ?int $countCache = null;

    private int $countCacheRevision = -1;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StagingSignal $signal = new StagingSignal(),
    ) {
    }

    /**
     * Load every staging row once (request-scoped). Prefer over multiple preloadCategory calls.
     */
    public function preloadAll(): void
    {
        if ($this->allLoaded) {
            return;
        }

        try {
            /** @var list<ContentStagingEntry> $rows */
            $rows = $this->em->getRepository(ContentStagingEntry::class)->findBy([]);
            foreach ($rows as $row) {
                $this->findCache[$row->category() . "\0" . $row->resourceKey()] = $row;
                $this->categoryFullyLoaded[$row->category()] = true;
            }
        } catch (\Throwable) {
            // table missing / mid-migrate — treat as empty
        }

        $this->allLoaded = true;
    }

    /**
     * Load all staging rows for a category in one query and fill the find cache.
     * Subsequent find() / isPending* for that category hit memory only.
     */
    public function preloadCategory(string $category): void
    {
        $this->preloadCategories([$category]);
    }

    /**
     * @param list<string> $categories
     */
    public function preloadCategories(array $categories): void
    {
        if ($this->allLoaded) {
            return;
        }

        $pending = [];
        foreach ($categories as $category) {
            if (!is_string($category) || $category === '' || isset($this->categoryFullyLoaded[$category])) {
                continue;
            }
            $pending[$category] = true;
        }
        $pending = array_keys($pending);
        if ($pending === []) {
            return;
        }

        // Multiple category preloads share one SQL fingerprint → false N+1 in the detector.
        // When more than one category is needed, load the whole table once.
        if (count($pending) > 1) {
            $this->preloadAll();

            return;
        }

        try {
            /** @var list<ContentStagingEntry> $rows */
            $rows = $this->em->createQueryBuilder()
                ->select('e')
                ->from(ContentStagingEntry::class, 'e')
                ->where('e.category IN (:categories)')
                ->setParameter('categories', $pending)
                ->getQuery()
                ->getResult();

            foreach ($rows as $row) {
                $this->findCache[$row->category() . "\0" . $row->resourceKey()] = $row;
            }
            foreach ($pending as $category) {
                $this->categoryFullyLoaded[$category] = true;
            }
        } catch (\Throwable) {
            foreach ($pending as $category) {
                $this->categoryFullyLoaded[$category] = true;
            }
        }
    }

    public function count(): int
    {
        $revision = $this->signal->revision();
        if ($this->countCache !== null && $this->countCacheRevision === $revision) {
            return $this->countCache;
        }

        try {
            $count = (int) $this->em->createQueryBuilder()
                ->select('COUNT(e.id)')
                ->from(ContentStagingEntry::class, 'e')
                ->getQuery()
                ->getSingleScalarResult();
        } catch (\Throwable) {
            $count = 0;
        }

        $this->countCache = $count;
        $this->countCacheRevision = $revision;

        return $count;
    }

    /** @return list<ContentStagingEntry> */
    public function all(): array
    {
        try {
            /** @var list<ContentStagingEntry> $rows */
            $rows = $this->em->getRepository(ContentStagingEntry::class)
                ->findBy([], ['category' => 'ASC', 'updatedAt' => 'DESC']);

            foreach ($rows as $row) {
                $this->findCache[$row->category() . "\0" . $row->resourceKey()] = $row;
                $this->categoryFullyLoaded[$row->category()] = true;
            }
            // Whole table is in memory now — later find() misses must not hit the DB again.
            $this->allLoaded = true;

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

        foreach ($rows as $row) {
            $this->findCache[$row->category() . "\0" . $row->resourceKey()] = $row;
        }

        return $rows;
    }

    public function find(string $category, string $resourceKey): ?ContentStagingEntry
    {
        $cacheKey = $category . "\0" . $resourceKey;
        if (array_key_exists($cacheKey, $this->findCache)) {
            $hit = $this->findCache[$cacheKey];

            return $hit instanceof ContentStagingEntry ? $hit : null;
        }

        if ($this->allLoaded || isset($this->categoryFullyLoaded[$category])) {
            $this->findCache[$cacheKey] = false;

            return null;
        }

        // First miss: load the whole staging table once (avoids per-key and
        // multi-category preload fingerprint N+1).
        $this->preloadAll();
        if (array_key_exists($cacheKey, $this->findCache)) {
            $hit = $this->findCache[$cacheKey];

            return $hit instanceof ContentStagingEntry ? $hit : null;
        }

        $this->findCache[$cacheKey] = false;

        return null;
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
            $this->findCache[$category . "\0" . $resourceKey] = $existing;
            $this->signal->bump();

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
        $this->findCache[$category . "\0" . $resourceKey] = $entry;
        $this->signal->bump();

        return $entry;
    }

    public function remove(ContentStagingEntry $entry): void
    {
        unset($this->findCache[$entry->category() . "\0" . $entry->resourceKey()]);
        $this->em->remove($entry);
        $this->em->flush();
        $this->signal->bump();
    }

    /** @param list<ContentStagingEntry> $entries */
    public function removeMany(array $entries): void
    {
        if ($entries === []) {
            return;
        }
        foreach ($entries as $entry) {
            unset($this->findCache[$entry->category() . "\0" . $entry->resourceKey()]);
            $this->em->remove($entry);
        }
        $this->em->flush();
        $this->signal->bump();
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
        // Prefer full table once — avoids a second same-fingerprint category preload later in the request.
        $this->preloadAll();
        $keys = [];
        $prefix = $category . "\0";
        foreach ($this->findCache as $cacheKey => $entry) {
            if (!str_starts_with((string) $cacheKey, $prefix)) {
                continue;
            }
            if ($entry instanceof ContentStagingEntry && $entry->action() === $action) {
                $keys[] = $entry->resourceKey();
            }
        }

        return $keys;
    }
}
