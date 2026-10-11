<?php

declare(strict_types=1);

namespace Gereq\Publish;

use Gereq\Publish\Entity\ContentStagingEntry;

interface PublishableAdapterInterface
{
    /**
     * Settings keys owned by this adapter (empty for entity-only adapters).
     *
     * @return list<string>
     */
    public function settingsKeys(): array;

    /**
     * Snapshot production values for the given resource keys (for backup).
     *
     * @param list<string> $resourceKeys
     * @return array<string, mixed>
     */
    public function snapshot(array $resourceKeys): array;

    /** Apply one staged entry onto production. */
    public function apply(ContentStagingEntry $entry): void;

    /**
     * Discard a staged change (revert working copy / keep production).
     * Staging row is removed by PublishService after this returns.
     *
     * - create → remove the unpublished working copy
     * - update → restore entity from production_snapshot
     * - delete → keep the row (only drop the pending-delete flag via staging removal)
     * - set → no-op (settings overlay drops with the staging row)
     */
    public function discard(ContentStagingEntry $entry): void;

    /**
     * Restore production from a release backup snapshot for one resource key.
     *
     * @param array<string, mixed>|null $snapshot null = resource did not exist before the release (undo create)
     */
    public function restore(string $resourceKey, ?array $snapshot): void;

    /** Human-readable card for the pending-change UI. */
    public function describe(ContentStagingEntry $entry): ChangeCard;
}
