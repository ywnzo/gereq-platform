<?php

declare(strict_types=1);

namespace Gereq\Publish;

use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Setting\SettingRecord;

final class StagingWriter
{
    public function __construct(
        private readonly StagingStore $store,
        private readonly PublishableRegistry $registry,
    ) {
    }

    public function stageSetting(
        string $key,
        mixed $value,
        string $type = SettingRecord::TYPE_STRING,
        ?string $module = null,
        ?string $label = null,
        ?string $createdBy = null,
    ): ContentStagingEntry {
        $category = $this->registry->categoryForSettingsKey($key);
        if ($category === null) {
            throw new \InvalidArgumentException(sprintf('Setting "%s" is not publishable.', $key));
        }

        $definition = $this->registry->get($category);
        $display = $label ?? ($definition->label . ': ' . $key);

        return $this->store->upsert(
            $category,
            'setting',
            $key,
            ContentStagingEntry::ACTION_SET,
            [
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'module' => $module,
            ],
            $display,
            $createdBy,
        );
    }

    /** Drop a staged setting when it matches production again (or was never a real change). */
    public function unstageSetting(string $key): void
    {
        $category = $this->registry->categoryForSettingsKey($key);
        if ($category === null) {
            return;
        }

        $existing = $this->store->find($category, $key);
        if ($existing instanceof ContentStagingEntry) {
            $this->store->remove($existing);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function stageResource(
        string $category,
        string $resourceType,
        string $resourceKey,
        string $action,
        array $payload,
        string $label,
        ?string $createdBy = null,
    ): ContentStagingEntry {
        if (!$this->registry->has($category)) {
            throw new \InvalidArgumentException(sprintf('Publishable category "%s" is not registered.', $category));
        }

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
}
