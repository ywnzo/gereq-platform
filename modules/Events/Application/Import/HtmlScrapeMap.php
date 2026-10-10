<?php

declare(strict_types=1);

namespace Modules\Events\Application\Import;

/**
 * Optional HTML scrape field map. Empty values fall back to Ketnet/Modelina defaults (mkstc.cz).
 *
 * @phpstan-type MapArray array<string, scalar|null>
 */
final class HtmlScrapeMap
{
    /** @var array<string, string> */
    public const DEFAULTS = [
        'card' => 'event-list-item\\s+event-card',
        'link' => 'href="(/udalost/[^"]+)"',
        'title' => '<h2[^>]*>\\s*<a[^>]*>(.*?)</a>',
        'type' => '<div class="type">(.*?)</div>',
        'date_class' => 'date-item',
        'time_class' => 'time-item',
        'place_class' => 'place-item',
        'price_class' => 'price-item',
        'image' => 'data-src="(https?://[^"]+)"',
        'page_param' => 'strana',
        'detail_body' => 'class="content text">(.*?)</div>',
        'detail_teaser' => 'class="description">(.*?)</div>',
        'detail_gallery' => 'id="gallery"',
        'poster_alt' => 'plakát|plakat',
    ];

    /** @param array<string, mixed> $override */
    public function __construct(private readonly array $override = [])
    {
    }

    /** @param array<string, mixed> $config Feed config blob */
    public static function fromConfig(array $config): self
    {
        $map = [];
        if (isset($config['map']) && is_array($config['map'])) {
            $map = $config['map'];
        }
        // Flat config_map_* keys also accepted
        foreach (self::DEFAULTS as $key => $_) {
            $flat = $config['map_' . $key] ?? $config['config_map_' . $key] ?? null;
            if (is_string($flat) && trim($flat) !== '') {
                $map[$key] = $flat;
            }
        }

        return new self($map);
    }

    public function get(string $key): string
    {
        $value = $this->override[$key] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return self::DEFAULTS[$key] ?? '';
    }

    /** @return array<string, string> Only non-default overrides (for storage). */
    public function overrides(): array
    {
        $out = [];
        foreach ($this->override as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value === '' || !array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            if ($value === self::DEFAULTS[$key]) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $out = self::DEFAULTS;
        foreach ($this->overrides() as $key => $value) {
            $out[$key] = $value;
        }

        return $out;
    }
}
