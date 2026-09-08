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

    /** Human-readable card for the pending-change UI. */
    public function describe(ContentStagingEntry $entry): ChangeCard;
}
