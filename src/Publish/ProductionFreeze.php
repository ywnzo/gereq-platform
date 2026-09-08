<?php

declare(strict_types=1);

namespace Gereq\Publish;

use Gereq\Publish\Entity\ContentStagingEntry;

/**
 * Entity publish pattern: freeze production snapshot on first edit,
 * keep working copy in live tables for admin, public API serves snapshot until publish.
 */
final class ProductionFreeze
{
    public function __construct(private readonly StagingStore $store)
    {
    }

    /**
     * @param callable(): array<string, mixed> $snapshotFn
     */
    public function freezeIfNeeded(
        string $category,
        string $resourceType,
        string $resourceKey,
        callable $snapshotFn,
        string $label,
        ?string $createdBy = null,
    ): ContentStagingEntry {
        $existing = $this->store->find($category, $resourceKey);
        if ($existing instanceof ContentStagingEntry) {
            // Do not downgrade a pending delete/create; only refresh label.
            $payload = $existing->payload();
            $payload['working_label'] = $label;
            $action = $existing->action();
            if ($action === ContentStagingEntry::ACTION_DELETE || $action === ContentStagingEntry::ACTION_CREATE) {
                return $this->store->upsert(
                    $category,
                    $resourceType,
                    $resourceKey,
                    $action,
                    $payload,
                    $label,
                    $createdBy,
                );
            }

            return $this->store->upsert(
                $category,
                $resourceType,
                $resourceKey,
                $existing->action(),
                $payload,
                $label,
                $createdBy,
            );
        }

        return $this->store->upsert(
            $category,
            $resourceType,
            $resourceKey,
            ContentStagingEntry::ACTION_UPDATE,
            [
                'production_snapshot' => $snapshotFn(),
                'working_label' => $label,
            ],
            $label,
            $createdBy,
        );
    }

    /**
     * Soft-delete via staging: keep DB row for admin hide + public freeze until publish apply.
     *
     * @param callable(): array<string, mixed> $snapshotFn
     */
    public function stageDelete(
        string $category,
        string $resourceType,
        string $resourceKey,
        callable $snapshotFn,
        string $label,
        ?string $createdBy = null,
    ): ContentStagingEntry {
        $existing = $this->store->find($category, $resourceKey);
        $snapshot = null;
        if ($existing instanceof ContentStagingEntry) {
            $prev = $existing->payload()['production_snapshot'] ?? null;
            $snapshot = is_array($prev) ? $prev : null;
        }
        if ($snapshot === null) {
            $snapshot = $snapshotFn();
        }

        return $this->store->upsert(
            $category,
            $resourceType,
            $resourceKey,
            ContentStagingEntry::ACTION_DELETE,
            [
                'production_snapshot' => $snapshot,
                'working_label' => $label,
                'pending_delete' => true,
            ],
            $label,
            $createdBy,
        );
    }

    /**
     * New resource that must not appear on public FE until publish.
     * Working copy may already exist in admin tables.
     */
    public function stageCreate(
        string $category,
        string $resourceType,
        string $resourceKey,
        string $label,
        ?string $createdBy = null,
        array $extraPayload = [],
    ): ContentStagingEntry {
        $existing = $this->store->find($category, $resourceKey);
        if ($existing instanceof ContentStagingEntry && $existing->action() === ContentStagingEntry::ACTION_DELETE) {
            // Re-created after staged delete — treat as update of existing production.
            $payload = $existing->payload();
            $payload['working_label'] = $label;
            unset($payload['pending_delete']);

            return $this->store->upsert(
                $category,
                $resourceType,
                $resourceKey,
                ContentStagingEntry::ACTION_UPDATE,
                $payload,
                $label,
                $createdBy,
            );
        }

        return $this->store->upsert(
            $category,
            $resourceType,
            $resourceKey,
            ContentStagingEntry::ACTION_CREATE,
            array_merge([
                'production_snapshot' => null,
                'working_label' => $label,
                'pending_create' => true,
            ], $extraPayload),
            $label,
            $createdBy,
        );
    }

    /** @return array<string, mixed>|null */
    public function productionSnapshot(string $category, string $resourceKey): ?array
    {
        $entry = $this->store->find($category, $resourceKey);
        if ($entry === null) {
            return null;
        }

        $snapshot = $entry->payload()['production_snapshot'] ?? null;

        return is_array($snapshot) ? $snapshot : null;
    }

    public function hasFreeze(string $category, string $resourceKey): bool
    {
        return $this->store->find($category, $resourceKey) instanceof ContentStagingEntry;
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
}
