<?php

declare(strict_types=1);

namespace Modules\Events\Application;

/** Storefront lists: admin/staging rows, production overlay, hardcoded fallbacks. */
final class EventUiMeta
{
    public const COLORS = [
        'Zábava' => 'purple',
        'Sport' => 'green',
        'Pro děti' => 'orange',
        'Kultura' => 'pink',
        'Ostatní' => 'green',
    ];

    public const IMAGES = [
        'Zábava' => 'music',
        'Sport' => 'sport',
        'Pro děti' => 'kids',
        'Kultura' => 'theatre',
        'Ostatní' => 'other',
    ];

    public const KICKERS = [
        'Zábava' => 'VEČER, KTERÝ MÁ RYTMUS',
        'Sport' => 'POTKÁME SE V POHYBU',
        'Pro děti' => 'PRO MALÉ OBJEVITELE',
        'Kultura' => 'CHVÍLE PRO KULTURU',
        'Ostatní' => 'ZKUSTE NĚCO NOVÉHO',
    ];

    public function __construct(
        private readonly EventCategories $categories,
        private readonly EventRegionImages $regionImages,
    ) {
    }

    /**
     * @param array<string, string> $photos
     * @param array<string, mixed> $oauth
     */
    public function payload(array $photos = [], array $oauth = []): array
    {
        $cards = $this->categories->publicCards($photos);
        $meta = $this->categories->publicFilterMeta();
        $assignable = $meta['slugs'];
        $kickers = $meta['kickers'];
        $images = $meta['images'];
        $colors = $meta['colors'];

        if ($cards === []) {
            $cards = self::categoryCards();
            foreach ($cards as &$card) {
                $key = (string) ($card['image'] ?? '');
                $card['image_url'] = $photos[$key] ?? null;
            }
            unset($card);
        }
        if ($assignable === []) {
            $assignable = \Modules\Events\Domain\CatalogEvent::CATEGORIES;
            $kickers = self::KICKERS;
            $images = self::IMAGES;
            $colors = self::COLORS;
        }

        $this->regionImages->seedMissing();

        return [
            'categories' => $assignable,
            'category_cards' => $cards,
            'regions' => $this->regionImages->publicRegions(),
            'kickers' => $kickers,
            'image_for_category' => $images,
            'color_for_category' => $colors,
            'hide_dates' => $meta['hide_dates'] ?? [],
            'photos' => $photos,
            'spotlights' => ['week', 'month'],
            'oauth' => $oauth !== [] ? $oauth : [
                'google' => false,
                'facebook' => false,
                'providers' => [],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function categoryCards(): array
    {
        $cards = [];
        foreach (EventCategories::defaults() as $row) {
            if (!($row['homepage'] ?? false)) {
                continue;
            }
            $cards[] = [
                'name' => $row['name'],
                'image' => $row['image'],
                'icon' => $row['icon'],
                'color' => $row['color'],
                'tint' => $row['tint'],
                'filter' => ($row['assignable'] ?? false) ? $row['slug'] : '',
                'blurb' => $row['blurb'] ?? '',
            ];
        }

        return $cards;
    }

    /** @return list<array{label: string, value: string, last?: bool}> */
    public static function regions(): array
    {
        $values = \Modules\Events\Domain\CatalogEvent::REGIONS;
        $last = array_key_last($values);
        $rows = [];
        foreach ($values as $index => $value) {
            $row = ['label' => $value, 'value' => $value];
            if ($index === $last) {
                $row['last'] = true;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public static function colorFor(string $category): string
    {
        return self::COLORS[$category] ?? 'green';
    }

    public static function imageFor(string $category): string
    {
        return self::IMAGES[$category] ?? 'other';
    }

    public static function kickerFor(string $category): string
    {
        return self::KICKERS[$category] ?? 'CHVÍLE PRO KULTURU';
    }
}
