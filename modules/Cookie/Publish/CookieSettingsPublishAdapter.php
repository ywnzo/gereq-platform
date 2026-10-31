<?php

declare(strict_types=1);

namespace Modules\Cookie\Publish;

use Modules\Cookie\Application\CookieConfig;
use Gereq\Publish\ChangeCard;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableAdapterInterface;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;

/**
 * Public cookie consent settings, staged before publication.
 */
final class CookieSettingsPublishAdapter implements PublishableAdapterInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function settingsKeys(): array
    {
        return array_keys(CookieConfig::definitions());
    }

    public function snapshot(array $resourceKeys): array
    {
        $out = [];
        foreach ($resourceKeys as $key) {
            if (!in_array($key, array_keys(CookieConfig::definitions()), true)) {
                continue;
            }
            $out[$key] = [
                'value' => $this->settings->getLive($key),
                'type' => $this->settings->typeOf($key),
            ];
        }

        return $out;
    }

    public function apply(ContentStagingEntry $entry): void
    {
        $payload = $entry->payload();
        $key = (string) ($payload['key'] ?? $entry->resourceKey());
        $type = (string) ($payload['type'] ?? SettingRecord::TYPE_STRING);
        $module = isset($payload['module']) && is_string($payload['module']) ? $payload['module'] : null;
        $value = $payload['value'] ?? null;

        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            // Settings keys are never deleted via staging; skip.
            return;
        }

        $this->settings->setLive($key, $value, $type, $module);
    }

    public function discard(ContentStagingEntry $entry): void
    {
        // Settings overlay lives only in staging — removing the entry restores production reads.
    }

    public function restore(string $resourceKey, ?array $snapshot): void
    {
        if ($snapshot === null) {
            return;
        }

        $type = (string) ($snapshot['type'] ?? SettingRecord::TYPE_STRING);
        $this->settings->setLive($resourceKey, $snapshot['value'] ?? null, $type);
    }

    public function describe(ContentStagingEntry $entry): ChangeCard
    {
        $key = $entry->resourceKey();
        $payload = $entry->payload();
        $value = $payload['value'] ?? null;
        $summary = is_scalar($value) ? (string) $value : '';
        if (strlen($summary) > 80) {
            $summary = substr($summary, 0, 77) . '…';
        }

        return new ChangeCard($key, $summary);
    }
}
