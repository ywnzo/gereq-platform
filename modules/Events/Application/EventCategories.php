<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\StagingStore;
use Modules\Events\Domain\CatalogEvent;
use Modules\Events\Domain\EventCategory;
use Modules\Events\Publish\EventCategoryPublishAdapter;

final class EventCategories
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StagingStore $staging,
    ) {
    }

    public function ensureHideDatesColumn(): void
    {
        try {
            $conn = $this->em->getConnection();
            $table = $conn->createSchemaManager()->introspectTable('kdeaco_event_categories');
            if ($table->hasColumn('hide_dates')) {
                return;
            }
            $platform = $conn->getDatabasePlatform();
            $sql = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform
                ? 'ALTER TABLE kdeaco_event_categories ADD COLUMN hide_dates BOOLEAN NOT NULL DEFAULT FALSE'
                : 'ALTER TABLE kdeaco_event_categories ADD COLUMN hide_dates INTEGER NOT NULL DEFAULT 0';
            $conn->executeStatement($sql);
        } catch (\Throwable) {
            // Tabulka ještě nemusí existovat — file migrace ji doplní.
        }
    }

    /** @return list<EventCategory> */
    public function all(): array
    {
        try {
            /** @var list<EventCategory> $rows */
            $rows = $this->em->getRepository(EventCategory::class)->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']);
        } catch (\Throwable) {
            return [];
        }

        return $rows;
    }

    /** Drop leftover staging rows — categories go live immediately. */
    public function discardStaging(?string $id = null): void
    {
        if ($id !== null) {
            $entry = $this->staging->find(EventCategoryPublishAdapter::CATEGORY, $id);
            if ($entry instanceof ContentStagingEntry) {
                $this->staging->remove($entry);
            }

            return;
        }

        try {
            /** @var list<ContentStagingEntry> $stale */
            $stale = $this->em->createQueryBuilder()
                ->select('e')
                ->from(ContentStagingEntry::class, 'e')
                ->where('e.category = :cat')
                ->setParameter('cat', EventCategoryPublishAdapter::CATEGORY)
                ->getQuery()
                ->getResult();
        } catch (\Throwable) {
            return;
        }
        if ($stale !== []) {
            $this->staging->removeMany($stale);
        }
    }

    public function delete(EventCategory $row): void
    {
        $this->discardStaging($row->id());
        $this->em->remove($row);
        $this->em->flush();
    }

    public function find(string $id): ?EventCategory
    {
        $row = $this->em->find(EventCategory::class, $id);

        return $row instanceof EventCategory ? $row : null;
    }

    public function findBySlug(string $slug): ?EventCategory
    {
        try {
            $row = $this->em->getRepository(EventCategory::class)->findOneBy(['slug' => $slug]);
        } catch (\Throwable) {
            return null;
        }

        return $row instanceof EventCategory ? $row : null;
    }

    /** @return list<string> */
    public function assignableSlugs(): array
    {
        $out = [];
        foreach ($this->all() as $row) {
            if ($row->assignable()) {
                $out[] = $row->slug();
            }
        }

        return $out !== [] ? $out : CatalogEvent::CATEGORIES;
    }

    /** @return list<array{value: string, label: string}> */
    public function assignableChoices(?string $current = null): array
    {
        $choices = [];
        $seen = [];
        foreach ($this->all() as $row) {
            if (!$row->assignable() && $row->slug() !== $current) {
                continue;
            }
            $label = $row->name() === $row->slug() ? $row->name() : $row->name() . ' (' . $row->slug() . ')';
            $choices[] = ['value' => $row->slug(), 'label' => $label];
            $seen[$row->slug()] = true;
        }
        if ($current !== null && $current !== '' && !isset($seen[$current])) {
            $choices[] = ['value' => $current, 'label' => $current];
        }
        if ($choices === []) {
            foreach (CatalogEvent::CATEGORIES as $name) {
                $choices[] = ['value' => $name, 'label' => $name];
            }
        }

        return $choices;
    }

    public function colorFor(string $slug): string
    {
        return $this->findBySlug($slug)?->eventColor() ?? EventUiMeta::COLORS[$slug] ?? 'green';
    }

    public function imageFor(string $slug): string
    {
        return $this->findBySlug($slug)?->imageKey() ?? EventUiMeta::IMAGES[$slug] ?? 'other';
    }

    public function kickerFor(string $slug): string
    {
        $kicker = $this->findBySlug($slug)?->kicker();
        return ($kicker !== null && $kicker !== '') ? $kicker : (EventUiMeta::KICKERS[$slug] ?? 'CHVÍLE PRO KULTURU');
    }

    /**
     * @return array{
     *   slugs: list<string>,
     *   kickers: array<string, string>,
     *   images: array<string, string>,
     *   colors: array<string, string>,
     *   hide_dates: list<string>
     * }
     */
    public function publicFilterMeta(): array
    {
        $assignable = [];
        $kickers = [];
        $images = [];
        $colors = [];
        foreach ($this->all() as $row) {
            if (!$row->assignable()) {
                continue;
            }
            $slug = $row->slug();
            $assignable[] = $slug;
            $images[$slug] = $row->imageKey();
            $colors[$slug] = $row->eventColor();
            if ($row->kicker() !== null && $row->kicker() !== '') {
                $kickers[$slug] = $row->kicker();
            }
        }

        return [
            'slugs' => $assignable,
            'kickers' => $kickers,
            'images' => $images,
            'colors' => $colors,
            'hide_dates' => $this->publicHideDatesSlugs(),
        ];
    }

    public function hidesDates(string $slug): bool
    {
        return in_array($slug, $this->hideDatesSlugs(), true);
    }

    /** @return list<string> */
    public function hideDatesSlugs(): array
    {
        $out = [];
        foreach ($this->all() as $row) {
            if ($row->hideDates()) {
                $out[] = $row->slug();
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function publicHideDatesSlugs(): array
    {
        return $this->hideDatesSlugs();
    }

    public function hidesDatesPublic(string $slug): bool
    {
        return in_array($slug, $this->publicHideDatesSlugs(), true);
    }

    /**
     * @param array<string, string> $photos
     * @return list<array<string, mixed>>
     */
    public function publicCards(array $photos = []): array
    {
        $cards = [];
        foreach ($this->all() as $row) {
            if ($row->showOnHome()) {
                $cards[] = $row->toCard($photos);
            }
        }

        return $cards;
    }

    public function eventCount(string $slug): int
    {
        try {
            return (int) $this->em->getRepository(CatalogEvent::class)->count(['category' => $slug]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(EventCategory $row, array $data): EventCategory
    {
        $previousSlug = $row->slug();
        if (isset($data['name'])) {
            $row->setName((string) $data['name']);
        }
        if (isset($data['slug']) && trim((string) $data['slug']) !== '') {
            $row->setSlug((string) $data['slug']);
        } elseif ($row->slug() === '') {
            $row->setSlug($row->name());
        }
        $dup = $this->findBySlug($row->slug());
        if ($dup instanceof EventCategory && $dup->id() !== $row->id()) {
            throw new \InvalidArgumentException('events.categories.error.slug_taken');
        }
        $row->setBlurb(isset($data['blurb']) ? (string) $data['blurb'] : null);
        $row->setIcon(isset($data['icon']) ? (string) $data['icon'] : null);
        $row->setColorHex(isset($data['color_hex']) ? (string) $data['color_hex'] : null);
        $row->setTint(isset($data['tint']) ? (string) $data['tint'] : null);
        if (isset($data['image_key'])) {
            $row->setImageKey((string) $data['image_key']);
        }
        if (array_key_exists('media_asset_id', $data)) {
            $id = trim((string) ($data['media_asset_id'] ?? ''));
            $row->setMediaAssetId($id !== '' ? $id : null);
        }
        if (isset($data['event_color'])) {
            $row->setEventColor((string) $data['event_color']);
        }
        $row->setKicker(isset($data['kicker']) ? (string) $data['kicker'] : null);
        $row->setShowOnHome($this->bool($data['show_on_home'] ?? false));
        $row->setAssignable($this->bool($data['assignable'] ?? false));
        $row->setHideDates($this->bool($data['hide_dates'] ?? false));
        $row->setSortOrder((int) ($data['sort_order'] ?? $row->sortOrder()));
        $this->em->flush();
        if ($previousSlug !== '' && $previousSlug !== $row->slug()) {
            $this->em->createQueryBuilder()
                ->update(CatalogEvent::class, 'e')
                ->set('e.category', ':next')
                ->where('e.category = :prev')
                ->setParameter('next', $row->slug())
                ->setParameter('prev', $previousSlug)
                ->getQuery()
                ->execute();
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): EventCategory
    {
        $row = new EventCategory((string) $data['name'], (string) ($data['slug'] ?? $data['name']));
        $this->em->persist($row);
        $this->save($row, $data);

        return $row;
    }

    public function seedIfEmpty(): int
    {
        try {
            if ($this->em->getRepository(EventCategory::class)->count([]) > 0) {
                return 0;
            }
        } catch (\Throwable) {
            return 0;
        }

        $n = 0;
        foreach (self::defaults() as $i => $row) {
            $this->em->persist($this->makeFromDefault($row, $i));
            $n++;
        }
        $this->em->flush();

        return $n;
    }

    /**
     * Remap legacy slugs and ensure the canonical five exist.
     * Custom categories added in admin are kept.
     */
    public function reconcileCanonical(): void
    {
        try {
            $this->em->getRepository(EventCategory::class)->count([]);
        } catch (\Throwable) {
            return;
        }

        /** @var array<string, string> $legacy */
        $legacy = [
            'Koncerty' => 'Zábava',
            'Turistika' => 'Ostatní',
            'Divadlo' => 'Kultura',
            'ostatni' => 'Ostatní',
        ];

        foreach ($legacy as $from => $to) {
            if ($from === $to) {
                continue;
            }
            $this->em->createQueryBuilder()
                ->update(CatalogEvent::class, 'e')
                ->set('e.category', ':to')
                ->where('e.category = :from')
                ->setParameter('to', $to)
                ->setParameter('from', $from)
                ->getQuery()
                ->execute();
        }

        foreach ($legacy as $from => $to) {
            $row = $this->findBySlug($from);
            if (!$row instanceof EventCategory) {
                continue;
            }
            $target = $this->findBySlug($to);
            if ($target instanceof EventCategory && $target->id() !== $row->id()) {
                $this->em->remove($row);
                continue;
            }
            $row->setSlug($to);
            $row->setName($to);
        }
        $this->em->flush();

        foreach (self::defaults() as $i => $def) {
            $slug = (string) $def['slug'];
            if ($this->findBySlug($slug) instanceof EventCategory) {
                continue;
            }
            $this->em->persist($this->makeFromDefault($def, $i));
        }
        $this->em->flush();

        $this->remapCustomerPreferences($legacy);
    }

    /** @return list<array<string, mixed>> */
    public static function defaults(): array
    {
        return [
            [
                'name' => 'Zábava',
                'slug' => 'Zábava',
                'image' => 'music',
                'icon' => 'celebration',
                'color' => '#7c39c7',
                'tint' => '#faf7ff',
                'blurb' => 'Večery s rytmem',
                'event_color' => 'purple',
                'kicker' => 'VEČER, KTERÝ MÁ RYTMUS',
                'homepage' => true,
                'assignable' => true,
            ],
            [
                'name' => 'Sport',
                'slug' => 'Sport',
                'image' => 'sport',
                'icon' => 'sports_soccer',
                'color' => '#279d4f',
                'tint' => '#f4fbf6',
                'blurb' => 'Společně v pohybu',
                'event_color' => 'green',
                'kicker' => 'POTKÁME SE V POHYBU',
                'homepage' => true,
                'assignable' => true,
            ],
            [
                'name' => 'Pro děti',
                'slug' => 'Pro děti',
                'image' => 'kids',
                'icon' => 'child_care',
                'color' => '#d99a29',
                'tint' => '#fffbf3',
                'blurb' => 'Malé velké radosti',
                'event_color' => 'orange',
                'kicker' => 'PRO MALÉ OBJEVITELE',
                'homepage' => true,
                'assignable' => true,
            ],
            [
                'name' => 'Kultura',
                'slug' => 'Kultura',
                'image' => 'theatre',
                'icon' => 'theater_comedy',
                'color' => '#ce5165',
                'tint' => '#fff7f7',
                'blurb' => 'Něco pro duši',
                'event_color' => 'pink',
                'kicker' => 'CHVÍLE PRO KULTURU',
                'homepage' => true,
                'assignable' => true,
            ],
            [
                'name' => 'Ostatní',
                'slug' => 'Ostatní',
                'image' => 'other',
                'icon' => 'more_horiz',
                'color' => '#526877',
                'tint' => '#f7f9fa',
                'blurb' => 'Zkuste něco nového',
                'event_color' => 'green',
                'kicker' => 'ZKUSTE NĚCO NOVÉHO',
                'homepage' => true,
                'assignable' => true,
            ],
        ];
    }

    /** @param array<string, mixed> $row */
    private function makeFromDefault(array $row, int $index): EventCategory
    {
        $item = new EventCategory((string) $row['name'], (string) $row['slug']);
        $this->applyDefault($item, $row, $index);

        return $item;
    }

    /** @param array<string, mixed> $row */
    private function applyDefault(EventCategory $item, array $row, int $index): void
    {
        $item->setName((string) $row['name']);
        $item->setSlug((string) $row['slug']);
        $item->setBlurb(isset($row['blurb']) ? (string) $row['blurb'] : null);
        $item->setIcon(isset($row['icon']) ? (string) $row['icon'] : null);
        $item->setColorHex(isset($row['color']) ? (string) $row['color'] : null);
        $item->setTint(isset($row['tint']) ? (string) $row['tint'] : null);
        $item->setImageKey((string) ($row['image'] ?? 'other'));
        $item->setEventColor((string) ($row['event_color'] ?? 'purple'));
        $item->setKicker(isset($row['kicker']) ? (string) $row['kicker'] : null);
        $item->setShowOnHome((bool) ($row['homepage'] ?? true));
        $item->setAssignable((bool) ($row['assignable'] ?? true));
        $item->setSortOrder($index * 10);
    }

    /** @param array<string, string> $legacy */
    private function remapCustomerPreferences(array $legacy): void
    {
        try {
            $conn = $this->em->getConnection();
            $rows = $conn->fetchAllAssociative(
                'SELECT id, preferred_categories FROM customers WHERE preferred_categories IS NOT NULL'
            );
        } catch (\Throwable) {
            return;
        }

        foreach ($rows as $row) {
            $raw = $row['preferred_categories'] ?? null;
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($decoded)) {
                continue;
            }
            $next = [];
            $seen = [];
            foreach ($decoded as $item) {
                if (!is_string($item) || $item === '') {
                    continue;
                }
                $value = $legacy[$item] ?? $item;
                if (isset($seen[$value])) {
                    continue;
                }
                $seen[$value] = true;
                $next[] = $value;
            }
            if ($next === $decoded) {
                continue;
            }
            try {
                $conn->update(
                    'customers',
                    ['preferred_categories' => json_encode(array_values($next), JSON_UNESCAPED_UNICODE)],
                    ['id' => $row['id']],
                );
            } catch (\Throwable) {
                // Preference remap must not break boot.
            }
        }
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }
}
