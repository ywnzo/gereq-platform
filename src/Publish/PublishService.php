<?php

declare(strict_types=1);

namespace Gereq\Publish;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Cache\StoreFrontCache;
use Gereq\Publish\Entity\ContentRelease;
use Gereq\Publish\Entity\ContentReleaseItem;
use Gereq\Publish\Entity\ContentStagingEntry;

final class PublishService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StagingStore $store,
        private readonly PublishableRegistry $registry,
        private readonly ContentVersion $versions,
        private readonly ContentReadContext $readContext,
        private readonly StoreFrontCache $storeFrontCache,
        private readonly StagingSignal $signal = new StagingSignal(),
    ) {
    }

    /**
     * @return array{
     *   count: int,
     *   version: string,
     *   categories: list<array{
     *     category: string,
     *     label: string,
     *     items: list<array{
     *       id: string,
     *       label: string,
     *       action: string,
     *       resource_key: string,
     *       resource_type: string,
     *       title: string,
     *       summary: string,
     *       href: ?string,
     *       updated_at: string
     *     }>
     *   }>
     * }
     */
    public function pending(int $previewLimitPerCategory = 0): array
    {
        $entries = $this->store->all();
        $grouped = [];

        foreach ($this->registry->all() as $definition) {
            $grouped[$definition->category] = [
                'category' => $definition->category,
                'label' => $definition->label,
                'items' => [],
            ];
        }

        foreach ($entries as $entry) {
            $category = $entry->category();
            if (!isset($grouped[$category])) {
                $grouped[$category] = [
                    'category' => $category,
                    'label' => $category,
                    'items' => [],
                ];
            }

            $card = $this->describe($entry);
            $grouped[$category]['items'][] = [
                'id' => $entry->id(),
                'label' => $entry->label(),
                'action' => $entry->action(),
                'resource_key' => $entry->resourceKey(),
                'resource_type' => $entry->resourceType(),
                'title' => $card->title,
                'summary' => $card->summary,
                'href' => $card->href,
                'updated_at' => $entry->updatedAt()->format(DATE_ATOM),
            ];
        }

        $categories = [];
        foreach ($grouped as $group) {
            if ($group['items'] === []) {
                continue;
            }
            if ($previewLimitPerCategory > 0) {
                $group['items'] = array_slice($group['items'], 0, $previewLimitPerCategory);
            }
            $categories[] = $group;
        }

        return [
            'count' => count($entries),
            'version' => $this->versions->current(),
            'categories' => $categories,
        ];
    }

    /**
     * Discard staged changes (revert working copy where needed, drop staging rows).
     *
     * @param list<string> $entryIds
     * @return array{discarded: int}
     */
    public function discard(array $entryIds): array
    {
        $entries = $this->store->findByIds($entryIds);
        if ($entries === []) {
            throw new \InvalidArgumentException('No staging entries selected.');
        }

        $this->em->beginTransaction();
        try {
            foreach ($entries as $entry) {
                if (!$this->registry->has($entry->category())) {
                    throw new \RuntimeException(sprintf('Unknown publishable category "%s".', $entry->category()));
                }
                $this->registry->adapter($entry->category())->discard($entry);
            }
            $this->store->removeMany($entries);
            $this->em->commit();
            $this->storeFrontCache->flush();

            return ['discarded' => count($entries)];
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    /**
     * Detail payload for the content workspace UI.
     *
     * @return array{
     *   id: string,
     *   category: string,
     *   category_label: string,
     *   resource_key: string,
     *   resource_type: string,
     *   action: string,
     *   label: string,
     *   title: string,
     *   summary: string,
     *   href: ?string,
     *   production_snapshot: array<string, mixed>|null,
     *   payload: array<string, mixed>,
     *   updated_at: string,
     *   created_by: ?string
     * }|null
     */
    public function entry(string $id): ?array
    {
        $entries = $this->store->findByIds([$id]);
        $entry = $entries[0] ?? null;
        if (!$entry instanceof ContentStagingEntry) {
            return null;
        }

        $card = $this->describe($entry);
        $categoryLabel = $entry->category();
        if ($this->registry->has($entry->category())) {
            $categoryLabel = $this->registry->get($entry->category())->label;
        }
        $snap = $entry->payload()['production_snapshot'] ?? null;

        return [
            'id' => $entry->id(),
            'category' => $entry->category(),
            'category_label' => $categoryLabel,
            'resource_key' => $entry->resourceKey(),
            'resource_type' => $entry->resourceType(),
            'action' => $entry->action(),
            'label' => $entry->label(),
            'title' => $card->title,
            'summary' => $card->summary,
            'href' => $card->href,
            'production_snapshot' => is_array($snap) ? $snap : null,
            'payload' => $entry->payload(),
            'updated_at' => $entry->updatedAt()->format(DATE_ATOM),
            'created_by' => $entry->createdBy(),
        ];
    }

    /**
     * @param list<string> $entryIds
     * @return array{version: string, published: int, release_id: string}
     */
    public function publish(array $entryIds, ?string $actorId = null): array
    {
        $entries = $this->store->findByIds($entryIds);
        if ($entries === []) {
            throw new \InvalidArgumentException('No staging entries selected.');
        }

        $byCategory = [];
        foreach ($entries as $entry) {
            $byCategory[$entry->category()][] = $entry->resourceKey();
        }

        $before = [];
        foreach ($byCategory as $category => $keys) {
            if (!$this->registry->has($category)) {
                continue;
            }
            $before[$category] = $this->registry->adapter($category)->snapshot(array_values(array_unique($keys)));
        }

        $this->em->beginTransaction();
        try {
            $this->readContext->withProduction(function () use ($entries): void {
                foreach ($entries as $entry) {
                    if (!$this->registry->has($entry->category())) {
                        throw new \RuntimeException(sprintf('Unknown publishable category "%s".', $entry->category()));
                    }
                    $this->registry->adapter($entry->category())->apply($entry);
                }
            });

            // "after" is derived from the staged payloads (desired production state).
            $after = [];
            foreach ($entries as $entry) {
                $category = $entry->category();
                $payload = $entry->payload();
                if ($entry->action() === ContentStagingEntry::ACTION_SET) {
                    $after[$category][$entry->resourceKey()] = [
                        'value' => $payload['value'] ?? null,
                        'type' => $payload['type'] ?? 'string',
                    ];
                } elseif ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
                    $after[$category][$entry->resourceKey()] = null;
                } else {
                    // Working copy is already the desired state; keep a marker for audit.
                    $after[$category][$entry->resourceKey()] = [
                        'applied_action' => $entry->action(),
                        'label' => $entry->label(),
                    ];
                }
            }

            $backup = [
                'kind' => 'publish',
                'before' => $before,
                'after' => $after,
            ];

            $version = $this->versions->bump(count($entries));
            $release = new ContentRelease($version, count($entries), $backup, $actorId);
            $this->em->persist($release);

            foreach ($entries as $entry) {
                $this->em->persist(new ContentReleaseItem(
                    $release->id(),
                    $entry->category(),
                    $entry->resourceKey(),
                    $entry->label(),
                    $entry->action(),
                    $entry->payload(),
                ));
                $this->em->remove($entry);
            }

            $this->em->flush();
            $this->em->commit();
            $this->signal->bump();

            $this->storeFrontCache->flush();

            return [
                'version' => $version,
                'published' => count($entries),
                'release_id' => $release->id(),
            ];
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    /**
     * @return list<array{id: string, version: string, change_count: int, created_at: string, created_by: ?string, can_rollback: bool, kind: string}>
     */
    public function releases(int $limit = 50): array
    {
        try {
            /** @var list<ContentRelease> $rows */
            $rows = $this->em->getRepository(ContentRelease::class)
                ->findBy([], ['createdAt' => 'DESC'], $limit);

            return array_map(function (ContentRelease $r): array {
                $before = $this->backupBefore($r->backupJson());

                return [
                    'id' => $r->id(),
                    'version' => $r->version(),
                    'change_count' => $r->changeCount(),
                    'created_at' => $r->createdAt()->format(DATE_ATOM),
                    'created_by' => $r->createdBy(),
                    'can_rollback' => $before !== [],
                    'kind' => $this->backupKind($r->backupJson()),
                ];
            }, $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Emergency rollback: restore production to the "before" snapshots of a release.
     *
     * @return array{version: string, restored: int, expected: int, skipped: int, status: 'ok'|'partial', release_id: string}
     */
    public function rollbackRelease(string $releaseId, ?string $actorId = null): array
    {
        $release = $this->em->find(ContentRelease::class, $releaseId);
        if (!$release instanceof ContentRelease) {
            throw new \InvalidArgumentException('Release not found.');
        }

        $before = $this->backupBefore($release->backupJson());
        if ($before === []) {
            throw new \InvalidArgumentException('Release has no rollback backup.');
        }

        $expected = 0;
        foreach ($before as $snapshots) {
            if (!is_array($snapshots)) {
                continue;
            }
            foreach ($snapshots as $resourceKey => $_snap) {
                if (is_string($resourceKey) && $resourceKey !== '') {
                    $expected++;
                }
            }
        }
        if ($expected === 0) {
            throw new \InvalidArgumentException('Release has no rollback backup.');
        }

        $restored = 0;
        $skipped = 0;
        $currentBackup = [];

        $this->em->beginTransaction();
        try {
            $this->readContext->withProduction(function () use ($before, &$restored, &$skipped, &$currentBackup): void {
                foreach ($before as $category => $snapshots) {
                    if (!$this->registry->has($category)) {
                        foreach ($snapshots as $resourceKey => $_snap) {
                            if (is_string($resourceKey) && $resourceKey !== '') {
                                $skipped++;
                            }
                        }
                        continue;
                    }
                    $adapter = $this->registry->adapter($category);
                    $keys = [];
                    foreach ($snapshots as $resourceKey => $_snap) {
                        if (is_string($resourceKey) && $resourceKey !== '') {
                            $keys[] = $resourceKey;
                        }
                    }
                    if ($keys !== []) {
                        $currentBackup[$category] = $adapter->snapshot($keys);
                    }

                    foreach ($snapshots as $resourceKey => $snapshot) {
                        if (!is_string($resourceKey) || $resourceKey === '') {
                            continue;
                        }
                        $snap = is_array($snapshot) ? $snapshot : null;
                        $adapter->restore($resourceKey, $snap);
                        $existing = $this->store->find($category, $resourceKey);
                        if ($existing instanceof ContentStagingEntry) {
                            $this->store->remove($existing);
                        }
                        $restored++;
                    }
                }
            });

            if ($restored === 0) {
                throw new \InvalidArgumentException('Nothing could be restored (missing publish adapters).');
            }

            $version = $this->versions->bump(max(1, $restored));
            $rollbackRelease = new ContentRelease($version, $restored, [
                'kind' => 'rollback',
                'rolled_back_release_id' => $release->id(),
                'rolled_back_version' => $release->version(),
                'before' => $currentBackup,
                'after' => $before,
                'expected' => $expected,
                'skipped' => $skipped,
            ], $actorId);
            $this->em->persist($rollbackRelease);
            $this->em->flush();
            $this->em->commit();
            $this->signal->bump();
            $this->storeFrontCache->flush();

            return [
                'version' => $version,
                'restored' => $restored,
                'expected' => $expected,
                'skipped' => $skipped,
                'status' => $skipped > 0 || $restored < $expected ? 'partial' : 'ok',
                'release_id' => $rollbackRelease->id(),
            ];
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $backupJson
     * @return array<string, array<string, mixed|null>>
     */
    private function backupBefore(array $backupJson): array
    {
        if (isset($backupJson['before']) && is_array($backupJson['before'])) {
            /** @var array<string, array<string, mixed|null>> $before */
            $before = $backupJson['before'];

            return $before;
        }

        // Legacy flat map: category => [resourceKey => snapshot]
        $out = [];
        foreach ($backupJson as $category => $snapshots) {
            if (!is_string($category) || !is_array($snapshots) || $category === 'kind') {
                continue;
            }
            $out[$category] = $snapshots;
        }

        return $out;
    }

    /** @param array<string, mixed> $backupJson */
    private function backupKind(array $backupJson): string
    {
        $kind = $backupJson['kind'] ?? 'publish';

        return is_string($kind) && $kind !== '' ? $kind : 'publish';
    }

    private function describe(ContentStagingEntry $entry): ChangeCard
    {
        if (!$this->registry->has($entry->category())) {
            return new ChangeCard($entry->label());
        }

        return $this->registry->adapter($entry->category())->describe($entry);
    }
}
