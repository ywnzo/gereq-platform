<?php

declare(strict_types=1);

namespace Modules\Events\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Testing\TestCase;
use Gereq\Support\Str;
use Modules\Events\Application\EventDemoSeeder;

final class EventsDomainTest extends TestCase
{
    public function testSlugifyMatchesFrontend(): void
    {
        self::assertSame('chodske-slavnosti-2026', Str::slug('Chodské slavnosti 2026'));
    }

    public function testDemoEventsHaveLongFormattedCopy(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $rows = (new EventDemoSeeder($em))->demoRows();
        self::assertGreaterThanOrEqual(20, count($rows));
        foreach ($rows as $row) {
            $html = (string) $row['long'];
            $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            self::assertStringContainsString('<p>', $html, (string) $row['title']);
            self::assertStringContainsString('<h3>', $html, (string) $row['title']);
            self::assertGreaterThanOrEqual(1000, mb_strlen($plain), (string) $row['title']);
            self::assertNotEmpty($row['sections'], (string) $row['title']);
            self::assertContains($row['region'], \Modules\Events\Domain\CatalogEvent::REGIONS, (string) $row['title']);
            self::assertStringContainsString('commons.wikimedia.org', (string) $row['photo'], (string) $row['title']);
        }
    }

    public function testGalleryNormalizesYoutubeVideoDropsAudioAndRejectsJavascript(): void
    {
        $items = \Modules\Events\Application\EventGallery::publicItems([
            ['kind' => 'video', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'Záznam', 'start' => 12, 'end' => 40],
            ['kind' => 'clip', 'url' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Vltava-main-theme-2.ogg', 'title' => 'Úryvek'],
            ['kind' => 'photo', 'url' => 'javascript:alert(1)', 'title' => 'nope'],
            ['kind' => 'gif', 'url' => '/media/abc/file', 'title' => 'gif'],
        ]);
        self::assertCount(2, $items);
        self::assertSame('youtube', $items[0]['player']);
        self::assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', (string) $items[0]['embed_url']);
        self::assertStringContainsString('start=12', (string) $items[0]['embed_url']);
        self::assertSame('gif', $items[1]['kind']);
        self::assertSame('/media/abc/file', $items[1]['url']);
    }

    public function testGalleryResolvesMediaLibraryAsset(): void
    {
        $id = '11111111-1111-1111-1111-111111111111';
        $items = \Modules\Events\Application\EventGallery::normalize([
            ['kind' => 'video', 'media_asset_id' => $id, 'title' => 'Z galerie'],
        ]);
        self::assertCount(1, $items);
        self::assertSame('/media/' . $id . '/file', $items[0]['url']);
        self::assertSame($id, $items[0]['media_asset_id']);
    }

    public function testDemoConcertsHaveVideoNotSongClip(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $rows = (new EventDemoSeeder($em))->demoRows();
        $concerts = 0;
        foreach ($rows as $row) {
            $gallery = (new EventDemoSeeder($em))->demoGallery($row);
            self::assertNotEmpty($gallery, (string) $row['title']);
            $kinds = array_column($gallery, 'kind');
            self::assertNotContains('clip', $kinds, (string) $row['title']);
            if (($row['category'] ?? '') === 'Zábava') {
                $concerts++;
                self::assertContains('video', $kinds, (string) $row['title']);
            }
        }
        self::assertGreaterThanOrEqual(3, $concerts);
    }

    public function testUiMetaHasStorefrontLists(): void
    {
        $cards = \Modules\Events\Application\EventUiMeta::categoryCards();
        self::assertSame(['Zábava', 'Sport', 'Pro děti', 'Kultura', 'Ostatní'], array_column($cards, 'name'));
        self::assertSame('Zábava', $cards[0]['filter']);
        self::assertSame('Ostatní', $cards[4]['filter']);
        self::assertSame(
            \Modules\Events\Domain\CatalogEvent::REGIONS,
            array_column(\Modules\Events\Application\EventUiMeta::regions(), 'value'),
        );
        self::assertSame('Plzeň-město', \Modules\Events\Application\EventUiMeta::regions()[2]['value']);
        self::assertSame('VEČER, KTERÝ MÁ RYTMUS', \Modules\Events\Application\EventUiMeta::kickerFor('Zábava'));
        self::assertSame(
            \Modules\Events\Domain\CatalogEvent::CATEGORIES,
            ['Zábava', 'Sport', 'Pro děti', 'Kultura', 'Ostatní'],
        );
        self::assertSame('/media/abc/file?w=800', \Modules\Events\Application\EventMediaLibrary::publicUrl('abc', 800));
    }

    public function testExternalUrlNormalizesHttpsAndRejectsJavascript(): void
    {
        self::assertSame('https://www.facebook.com/events/1', \Modules\Events\Domain\CatalogEvent::normalizeExternalUrl('www.facebook.com/events/1'));
        self::assertSame('https://tickets.example/akce', \Modules\Events\Domain\CatalogEvent::normalizeExternalUrl('https://tickets.example/akce'));
        self::assertNull(\Modules\Events\Domain\CatalogEvent::normalizeExternalUrl('javascript:alert(1)'));
        self::assertNull(\Modules\Events\Domain\CatalogEvent::normalizeExternalUrl(''));
    }

    public function testScheduleTimeUsesRange(): void
    {
        $start = new \DateTimeImmutable('2026-06-12 18:00:00');
        $event = new \Modules\Events\Domain\CatalogEvent('Test', 'Kultura', 'Náměstí', 'Domažlice', $start);
        self::assertSame('18:00', \Modules\Events\Application\EventCatalog::scheduleTime($event));
        $event->setEndsAt(new \DateTimeImmutable('2026-06-12 22:30:00'));
        self::assertSame('18:00–22:30', \Modules\Events\Application\EventCatalog::scheduleTime($event));
        $event->setEndsAt(new \DateTimeImmutable('2026-06-13 01:00:00'));
        self::assertSame('12. 6. 18:00 – 13. 6. 01:00', \Modules\Events\Application\EventCatalog::scheduleTime($event));
        $event->setShowEndsAt(false);
        self::assertSame('18:00', \Modules\Events\Application\EventCatalog::scheduleTime($event));
        $event->setShowStartsAt(false);
        $event->setShowEndsAt(true);
        self::assertSame('01:00', \Modules\Events\Application\EventCatalog::scheduleTime($event));
        $event->setShowEndsAt(false);
        self::assertSame('', \Modules\Events\Application\EventCatalog::scheduleTime($event));
    }

    public function testPublicDateLabelRespectsVisibilityFlags(): void
    {
        $start = new \DateTimeImmutable('2026-06-12 18:00:00');
        $end = new \DateTimeImmutable('2026-06-13 01:00:00');
        self::assertSame(
            '12. 6. 2026 – 13. 6. 2026',
            \Modules\Events\Application\EventCatalog::publicDateLabel($start, $end, true, true),
        );
        self::assertSame('12. 6. 2026', \Modules\Events\Application\EventCatalog::publicDateLabel($start, $end, true, false));
        self::assertSame('13. 6. 2026', \Modules\Events\Application\EventCatalog::publicDateLabel($start, $end, false, true));
        self::assertSame('', \Modules\Events\Application\EventCatalog::publicDateLabel($start, $end, false, false));
    }

    public function testTryParseDateAcceptsDatetimeLocal(): void
    {
        $dt = \Modules\Events\Application\EventCatalog::tryParseDate('2026-06-12T18:00');
        self::assertInstanceOf(\DateTimeImmutable::class, $dt);
        self::assertSame('2026-06-12 18:00', $dt->format('Y-m-d H:i'));
    }

    public function testCityIsTakenFromPlaceBeforeDash(): void
    {
        $starts = new \DateTimeImmutable('2026-07-01 10:00:00');
        $event = new \Modules\Events\Domain\CatalogEvent(
            'Pilsner Fest',
            'Kultura',
            'Plzeň – Prazdroj',
            'Plzeň-město',
            $starts,
        );
        self::assertSame('Plzeň', $event->city());

        $plain = new \Modules\Events\Domain\CatalogEvent('Trh', 'Kultura', 'Domažlice', 'Domažlice', $starts);
        self::assertSame('Domažlice', $plain->city());

        $address = new \Modules\Events\Domain\CatalogEvent(
            'Koncert',
            'Kultura',
            'MKS | velký sál, nám. Míru 51, 344 01 Domažlice',
            'Domažlice',
            $starts,
        );
        self::assertSame('Domažlice', $address->city());

        $beforeZip = new \Modules\Events\Domain\CatalogEvent(
            'Film',
            'Kultura',
            'Kino Čakan, Vodní 8, Domažlice, 344 01',
            'Domažlice',
            $starts,
        );
        self::assertSame('Domažlice', $beforeZip->city());

        $venue = new \Modules\Events\Domain\CatalogEvent(
            'Koncert',
            'Kultura',
            'Kulturní dům Klatovy',
            'Klatovy',
            $starts,
        );
        self::assertSame('Klatovy', $venue->city());

        $castle = new \Modules\Events\Domain\CatalogEvent('Koncert', 'Kultura', 'Tachovský zámek', 'Tachov', $starts);
        self::assertSame('Tachov', $castle->city());
    }

    public function testRejectStoresReasonAndClearsOnPublish(): void
    {
        $starts = new \DateTimeImmutable('2026-07-01 10:00:00');
        $event = new \Modules\Events\Domain\CatalogEvent('Tip', 'Kultura', 'Domažlice', 'Domažlice', $starts);
        $event->reject('Chybí místo konání.');
        self::assertSame('rejected', $event->status());
        self::assertSame('Chybí místo konání.', $event->rejectionReason());
        self::assertInstanceOf(\DateTimeImmutable::class, $event->rejectedAt());
        $event->publish();
        self::assertSame('published', $event->status());
        self::assertNull($event->rejectionReason());
        self::assertNull($event->rejectedAt());
        $event->setSpotlight('week');
        $event->archive();
        self::assertSame('archived', $event->status());
        self::assertSame('none', $event->spotlight());
    }

    public function testEventsGroupByCityAreSortable(): void
    {
        $starts = new \DateTimeImmutable('2026-07-01 10:00:00');
        $tachov = new \Modules\Events\Domain\CatalogEvent('Běh', 'Sport', 'Tachov – náměstí', 'Tachov', $starts);
        $plzen = new \Modules\Events\Domain\CatalogEvent('Fest', 'Kultura', 'Plzeň – Prazdroj', 'Plzeň-město', $starts);
        $another = new \Modules\Events\Domain\CatalogEvent('Jazz', 'Zábava', 'Plzeň – Lochotín', 'Plzeň-město', $starts);

        $groups = \Modules\Events\Domain\CatalogEvent::groupByCity([$tachov, $plzen, $another]);
        self::assertSame(['Plzeň', 'Tachov'], array_keys($groups));
        self::assertCount(2, $groups['Plzeň']);
        self::assertSame(['Tachov', 'Plzeň'], array_keys(\Modules\Events\Domain\CatalogEvent::groupByCity([$tachov, $plzen], 'desc')));
    }

    public function testElapsedUsesEndThenStart(): void
    {
        $starts = new \DateTimeImmutable('2026-07-01 10:00:00');
        $event = new \Modules\Events\Domain\CatalogEvent('Tip', 'Kultura', 'Domažlice', 'Domažlice', $starts);
        self::assertTrue($event->hasElapsed(new \DateTimeImmutable('2026-07-01 10:00:01')));
        self::assertFalse($event->hasElapsed(new \DateTimeImmutable('2026-07-01 09:59:59')));
        $event->setEndsAt(new \DateTimeImmutable('2026-07-01 18:00:00'));
        self::assertFalse($event->hasElapsed(new \DateTimeImmutable('2026-07-01 17:00:00')));
        self::assertTrue($event->hasElapsed(new \DateTimeImmutable('2026-07-01 18:00:01')));
        $event->publish();
        self::assertTrue($event->isPubliclyVisible(new \DateTimeImmutable('2026-07-01 12:00:00')));
        self::assertFalse($event->isPubliclyVisible(new \DateTimeImmutable('2026-07-01 19:00:00')));
    }

    public function testCategoryCaptureAndCardFromSnapshot(): void
    {
        $item = new \Modules\Events\Domain\EventCategory('Festivaly', 'Festivaly');
        $item->setBlurb('Venku');
        $item->setIcon('festival');
        $item->setColorHex('#112233');
        $item->setTint('#fafafa');
        $item->setImageKey('music');
        $item->setEventColor('purple');
        $item->setShowOnHome(true);
        $item->setAssignable(true);
        self::assertFalse($item->hideDates());
        $item->setHideDates(true);
        $snap = \Modules\Events\Publish\EventCategoryPublishAdapter::capture($item);
        self::assertSame('Festivaly', $snap['slug']);
        self::assertSame('Venku', $snap['blurb']);
        self::assertTrue($snap['hide_dates']);
        $card = \Modules\Events\Publish\EventCategoryPublishAdapter::cardFromSnapshot($snap);
        self::assertSame('Festivaly', $card['name']);
        self::assertSame('Festivaly', $card['filter']);
        self::assertSame('festival', $card['icon']);
    }

    public function testEventSnapshotIsLiveRespectsStatusAndEnd(): void
    {
        $event = new \Modules\Events\Domain\CatalogEvent('Tip', 'Kultura', 'Domažlice', 'Domažlice', new \DateTimeImmutable('2026-07-01 10:00:00'));
        $event->setEndsAt(new \DateTimeImmutable('2026-07-01 18:00:00'));
        $event->publish();
        $snap = \Modules\Events\Publish\EventPublishAdapter::capture($event);
        self::assertTrue(\Modules\Events\Publish\EventPublishAdapter::snapshotIsLive($snap, new \DateTimeImmutable('2026-07-01 12:00:00')));
        self::assertFalse(\Modules\Events\Publish\EventPublishAdapter::snapshotIsLive($snap, new \DateTimeImmutable('2026-07-01 19:00:00')));
        $snap['status'] = 'pending';
        self::assertFalse(\Modules\Events\Publish\EventPublishAdapter::snapshotIsLive($snap, new \DateTimeImmutable('2026-07-01 12:00:00')));
        $snap['status'] = 'published';
        $snap['hide_dates'] = true;
        self::assertTrue(\Modules\Events\Publish\EventPublishAdapter::snapshotIsLive($snap, new \DateTimeImmutable('2026-07-01 19:00:00')));
        unset($snap['hide_dates']);
        $snap['show_starts_at'] = false;
        $snap['show_ends_at'] = false;
        self::assertTrue(\Modules\Events\Publish\EventPublishAdapter::snapshotIsLive($snap, new \DateTimeImmutable('2026-07-01 19:00:00')));
    }
}
