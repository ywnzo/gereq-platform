<?php

declare(strict_types=1);

namespace Gereq\Publish\Adapter;

use Gereq\Publish\ChangeCard;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableAdapterInterface;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;

/**
 * Branding + public site settings (colors, logo, CSS, site title).
 */
final class BrandingPublishAdapter implements PublishableAdapterInterface
{
    /** @var list<string> */
    private const KEYS = [
        'brand.name',
        'brand.subtitle',
        'brand.logo_url',
        'brand.favicon_url',
        'brand.color_signal',
        'brand.color_ink',
        'brand.color_cream',
        'brand.site_primary',
        'brand.site_name',
        'brand.site_mark',
        'brand.tagline',
        'brand.footnote',
        'cms.site_title',
        'cms.home_page_id',
        'cms.custom_css_public',
    ];

    public function __construct(private readonly Settings $settings)
    {
    }

    public function settingsKeys(): array
    {
        return self::KEYS;
    }

    public function snapshot(array $resourceKeys): array
    {
        $out = [];
        foreach ($resourceKeys as $key) {
            if (!in_array($key, self::KEYS, true)) {
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
