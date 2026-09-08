<?php

declare(strict_types=1);

namespace Gereq\Publish;

use Gereq\Publish\Entity\ContentStagingEntry;

/**
 * Resolve frozen production snapshots for public API reads.
 */
final class FrozenPublicReader
{
    public function __construct(private readonly StagingStore $store)
    {
    }

    /** @return array<string, mixed>|null */
    public function snapshot(string $category, string $resourceKey): ?array
    {
        $entry = $this->store->find($category, $resourceKey);
        if ($entry === null) {
            return null;
        }
        // Pending create: nothing was on production yet.
        if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
            return null;
        }
        $snap = $entry->payload()['production_snapshot'] ?? null;

        return is_array($snap) ? $snap : null;
    }

    /**
     * Find a frozen snapshot by a field value (e.g. slug).
     *
     * @return array{resource_key: string, snapshot: array<string, mixed>}|null
     */
    public function findSnapshotBy(string $category, string $field, string $value): ?array
    {
        foreach ($this->store->all() as $entry) {
            if ($entry->category() !== $category) {
                continue;
            }
            if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
                continue;
            }
            $snap = $entry->payload()['production_snapshot'] ?? null;
            if (!is_array($snap)) {
                continue;
            }
            if ((string) ($snap[$field] ?? '') === $value) {
                return [
                    'resource_key' => $entry->resourceKey(),
                    'snapshot' => $snap,
                ];
            }
        }

        return null;
    }

    public function isFrozen(string $category, string $resourceKey): bool
    {
        return $this->snapshot($category, $resourceKey) !== null;
    }

    public function isPendingDelete(string $category, string $resourceKey): bool
    {
        return $this->store->isPendingDelete($category, $resourceKey);
    }

    public function isPendingCreate(string $category, string $resourceKey): bool
    {
        return $this->store->isPendingCreate($category, $resourceKey);
    }

    /** @return list<string> */
    public function pendingDeleteKeys(string $category): array
    {
        return $this->store->pendingDeleteKeys($category);
    }

    /** @return list<string> */
    public function pendingCreateKeys(string $category): array
    {
        return $this->store->pendingCreateKeys($category);
    }

    /**
     * @return list<array{resource_key: string, snapshot: array<string, mixed>}>
     */
    public function allSnapshots(string $category): array
    {
        $out = [];
        foreach ($this->store->all() as $entry) {
            if ($entry->category() !== $category) {
                continue;
            }
            if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
                continue;
            }
            $snap = $entry->payload()['production_snapshot'] ?? null;
            if (!is_array($snap)) {
                continue;
            }
            $out[] = [
                'resource_key' => $entry->resourceKey(),
                'snapshot' => $snap,
            ];
        }

        return $out;
    }
}
