<?php

declare(strict_types=1);

namespace Modules\Events\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Gereq\Config\Repository;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\Testing\TestCase;
use Modules\Events\Application\Import\CategoryMapper;
use Modules\Events\Application\Import\EventAvailability;
use Modules\Events\Application\Import\HtmlListSource;
use Modules\Events\Application\Import\HtmlScrapeMap;
use Modules\Events\Application\Import\IcalSource;
use Modules\Events\Application\Import\KlatovyAspSource;
use Modules\Events\Application\Import\VikendoSource;
use Modules\Vikendo\Application\VikendoClient;

final class EventImportSourcesTest extends TestCase
{
    public function testAvailabilityBadgeStripsVyprodanoFromTitle(): void
    {
        [$title, $capacity] = EventAvailability::splitTitle('"Haló, tady máma!" - VYPRODÁNO');
        self::assertSame('Haló, tady máma!', $title);
        self::assertSame('Vyprodáno', $capacity);
        self::assertTrue(EventAvailability::isSoldOut($capacity));
    }

    public function testCategoryMapperKidsAndSport(): void
    {
        self::assertSame('Pro děti', CategoryMapper::map(['Bublinová párty pro děti']));
        self::assertSame('Sport', CategoryMapper::map(['Běžecký závod']));
        self::assertSame('Zábava', CategoryMapper::map(['Koncert', 'Hudba']));
        self::assertSame('Kultura', CategoryMapper::map(['Výstava v galerii']));
        // Ticket boilerplate must not force "Pro děti"
        self::assertSame(
            'Zábava',
            CategoryMapper::map(['A. Dvořák: REQUIEM', 'Snížené vstupné, děti a studenti 300 Kč', 'Hudba']),
        );
        // "běh" must not match inside "příběh"
        self::assertSame(
            'Zábava',
            CategoryMapper::map(['TECHTLE MECHTLE: Haló, tady máma!', 'energický příběh plný smíchu', 'Hudba']),
        );
    }

    public function testHtmlListParsesEventCards(): void
    {
        $html = <<<'HTML'
<div class="event-list-item event-card">
 <img class="img preload-img" data-src="https://www.tachovsko.com/rimg/39972/506x0/fill.jpg" alt="x" />
 <div class="body">
  <h2 class="title type-link"><a class="button" href="/udalost/moda-v-dobe-windischgratzu.html">Móda v době Windischgrätzů</a></h2>
  <div class="type">Výstava</div>
  <div class="contact-items">
   <div class="contact-item date-item"><div class="text">Středa 8. 4. 2026</div></div>
   <div class="contact-item time-item"><div class="text">10.00</div></div>
   <div class="contact-item place-item"><div class="text">Tachovský zámek</div></div>
   <div class="contact-item price-item price-is-1"><div class="text">50 Kč</div></div>
  </div>
 </div>
</div>
HTML;

        $rows = (new HtmlListSource('tachov', 'https://www.mkstc.cz/udalosti.html', 'Tachov', fetchDetails: false))->parseList($html);
        self::assertCount(1, $rows);
        self::assertSame('tachov', $rows[0]->source);
        self::assertSame('moda-v-dobe-windischgratzu', $rows[0]->sourceId);
        self::assertSame('2026-04-08 10:00', $rows[0]->startsAt->format('Y-m-d H:i'));
    }

    public function testHtmlDetailParsesDescriptionPosterAndGallery(): void
    {
        $html = <<<'HTML'
<meta property="og:description" content="Romantický / Komedie" />
<meta property="og:image" content="https://www.tachovsko.com/rimg/43095/1200x630/fill.jpg" />
<div class="content text"><h2>Dokonalý den</h2><p><strong>Když se Anně porouchá auto.</strong></p></div>
<iframe src="https://www.youtube.com/embed/KVz5aiXoQ2M?autoplay=0"></iframe>
<section class="article-gallery"><div id="gallery" class="items">
<img src="https://www.tachovsko.com/rimg/43088/292x169/fill.jpg" data-ngsrc="https://www.tachovsko.com/rimg/43088/1920x1080/resample.jpg" alt="Plakát" />
<img src="https://www.tachovsko.com/rimg/43089/292x169/fill.jpg" data-ngsrc="https://www.tachovsko.com/rimg/43089/1920x1080/resample.jpg" alt="Dokonalý den" />
</div></div></section>
HTML;
        $parsed = (new HtmlListSource('tachov', 'https://www.mkstc.cz/udalosti.html', 'Tachov', fetchDetails: false))->parseDetail($html);
        self::assertSame('Romantický / Komedie', $parsed['teaser']);
        self::assertStringContainsString('Když se Anně porouchá auto', (string) $parsed['bodyHtml']);
        self::assertNotNull($parsed['posterUrl']);
        self::assertGreaterThanOrEqual(2, count($parsed['gallery']));
        self::assertSame('video', $parsed['gallery'][0]['kind']);
    }

    public function testHtmlListHonorsCustomMapOverrides(): void
    {
        $html = <<<'HTML'
<section class="evt">
 <a class="evt-link" href="/show/custom-event.html">Custom Event</a>
 <div class="contact-item when"><div class="text">12. 5. 2026</div></div>
 <div class="contact-item clock"><div class="text">18:30</div></div>
</section>
HTML;
        $map = new HtmlScrapeMap([
            'card' => 'class="evt"',
            'link' => 'href="(/show/[^"]+)"',
            'title' => 'class="evt-link"[^>]*>(.*?)</a>',
            'date_class' => 'when',
            'time_class' => 'clock',
            'type' => 'NOMATCH',
            'place_class' => 'missing',
            'price_class' => 'missing',
            'image' => 'NOMATCH',
        ]);
        $rows = (new HtmlListSource(
            'custom',
            'https://example.com/list.html',
            'Tachov',
            fetchDetails: false,
            map: $map,
        ))->parseList($html);
        self::assertCount(1, $rows);
        self::assertSame('custom-event', $rows[0]->sourceId);
        self::assertSame('Custom Event', $rows[0]->title);
        self::assertSame('2026-05-12 18:30', $rows[0]->startsAt->format('Y-m-d H:i'));
    }

    public function testKlatovyParsesTableRows(): void
    {
        $html = <<<'HTML'
<table class='table-akce' cellspacing='0'>
<tr><td class='akcem_d_KT_KD akce-bd' ><span class="akce-day1">10.10.26</span>
 , 19:00</td><td class='akcem_m_KT_KD akce-bm'>Kulturní dům Klatovy</td></tr><tr><td colspan='3' class='akcem_t_KT_KD akce-bt'>Kateřina Marie Tichá &amp; Bandjeez</td></tr><tr><td colspan='3' class='akcem_b_KT_KD akce-bb'><div class="akce-mbodyab">Koncert v Klatovech!</div><div class="akce-rozsirene"><div class="akce-mbody">Delší popis koncertu.</div><div class="akce-prilohy"><div class="akce-priloha1"><a href="https://www.sumavanet.cz/mkskt/user/2026_10_10_KMT.jpg" class="fancybox">Příloha</a></div></div></div>
<div class='akce-autor'>(Zapsal:  MKS Klatovy, 07.09.26)</div></td></tr>
</table>
HTML;

        $rows = (new KlatovyAspSource())->parseHtml($html);
        self::assertCount(1, $rows);
        self::assertSame('Kateřina Marie Tichá & Bandjeez', $rows[0]->title);
        self::assertSame('2026-10-10 19:00', $rows[0]->startsAt->format('Y-m-d H:i'));
        self::assertSame('https://www.sumavanet.cz/mkskt/user/2026_10_10_KMT.jpg', $rows[0]->imageUrl);
    }

    public function testKlatovyParsesPdfAttachmentAsImageCandidate(): void
    {
        $html = <<<'HTML'
<table class='table-akce' cellspacing='0'>
<tr><td class='akcem_d_KT_KD akce-bd' ><span class="akce-day1">30.11.26</span>
 , 19:00</td><td class='akcem_m_KT_KD akce-bm'>Kulturní dům Klatovy</td></tr><tr><td colspan='3' class='akcem_t_KT_KD akce-bt'>Vánoce Osmanyho Laffity</td></tr><tr><td colspan='3' class='akcem_b_KT_KD akce-bb'><div class="akce-mbodyab">Originální pořad.</div><div class="akce-rozsirene"><div class="akce-mbody">Delší popis.</div><div class="akce-prilohy"><div class="akce-priloha1"><a href="https://www.sumavanet.cz/mkskt/user/2026-11-30_Vanoce_Osmany.pdf" target="_blank">Příloha</a></div></div></div>
<div class='akce-autor'>(Zapsal:  MKS Klatovy, 07.09.26)</div></td></tr>
</table>
HTML;
        $rows = (new KlatovyAspSource())->parseHtml($html);
        self::assertCount(1, $rows);
        self::assertSame('https://www.sumavanet.cz/mkskt/user/2026-11-30_Vanoce_Osmany.pdf', $rows[0]->imageUrl);
    }

    public function testKlatovyParsesYoutubeVideoklipIntoGallery(): void
    {
        $html = <<<'HTML'
<table class='table-akce' cellspacing='0'>
<tr><td class='akcem_d_KT_KD akce-bd' ><span class="akce-day1">18.05.27</span>
 , 19:00</td><td class='akcem_m_KT_KD akce-bm'>Kulturní dům Klatovy</td></tr><tr><td colspan='3' class='akcem_t_KT_KD akce-bt'>Karel Svoboda - Ještě světu šanci dej 2</td></tr><tr><td colspan='3' class='akcem_b_KT_KD akce-bb'><div class="akce-mbodyab">Představujeme pokračování úspěšného hudebního projektu.</div><div class="akce-rozsirene"><div class="akce-mbody">Delší popis koncertu.<br /><br />&#127916; Videoklip: https://youtu.be/_t03GzWaRv8<br /></div></div>
<div class='akce-autor'>(Zapsal:  MKS Klatovy, 07.09.26)</div></td></tr>
</table>
HTML;
        $rows = (new KlatovyAspSource())->parseHtml($html);
        self::assertCount(1, $rows);
        self::assertNotSame([], $rows[0]->gallery);
        self::assertSame('video', $rows[0]->gallery[0]['kind']);
        self::assertSame('https://www.youtube.com/watch?v=_t03GzWaRv8', $rows[0]->gallery[0]['url']);
        self::assertSame('https://img.youtube.com/vi/_t03GzWaRv8/hqdefault.jpg', $rows[0]->imageUrl);
    }

    public function testIcalParsesVevents(): void
    {
        $ics = <<<'ICS'
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:test-1@example.com
DTSTART:20261020T190000
DTEND:20261020T210000
SUMMARY:Test koncert
LOCATION:Domažlice
DESCRIPTION:Popis akce
URL:https://example.com/akce
ATTACH;FMTTYPE=image/jpeg:https://example.com/wp-content/uploads/poster.jpg
END:VEVENT
END:VCALENDAR
ICS;
        $rows = (new IcalSource('demo', 'https://example.com/feed.ics', 'Domažlice', 'MKS'))->parse($ics);
        self::assertCount(1, $rows);
        self::assertSame('Test koncert', $rows[0]->title);
        self::assertSame('test-1@example.com', $rows[0]->sourceId);
        self::assertSame('2026-10-20 19:00', $rows[0]->startsAt->format('Y-m-d H:i'));
        self::assertSame('Zábava', $rows[0]->category);
        self::assertSame('https://example.com/wp-content/uploads/poster.jpg', $rows[0]->imageUrl);
    }

    public function testVikendoMapsDistrictAndKidsCategory(): void
    {
        $source = new VikendoSource(new VikendoClient($this->settings()));

        $row = $source->mapRow([
            'id' => 285175,
            'name' => 'Podzimníčkování pro děti',
            'description' => '<p>Akce v MC</p>',
            'start_date' => '2026-10-08T16:30:00.000+02:00',
            'end_date' => '2026-10-08T18:00:00.000+02:00',
            'url' => 'https://example.com/akce',
            'place' => ['name' => 'Mateřské centrum Slovíčko'],
            'municipality' => [
                'name' => 'Plzeň',
                'district_name' => 'Plzeň-město',
                'name_formatted' => 'Plzeň (Plzeň-město)',
            ],
            'categories' => [['name' => 'Děti']],
            'thumbnail' => ['url' => 'https://example.com/poster.jpg'],
        ]);

        self::assertNotNull($row);
        self::assertSame('vikendo', $row->source);
        self::assertSame('285175', $row->sourceId);
        self::assertSame('Plzeň-město', $row->region);
        self::assertSame('Pro děti', $row->category);
        self::assertStringContainsString('Plzeň', $row->place);
        self::assertSame('16:30', $row->timeLabel);
    }

    public function testVikendoDefaultDistrictsMatchKdeacoRegions(): void
    {
        self::assertSame(
            ['3401', '3404', '3405', '3406', '3407', '3408', '3410'],
            VikendoSource::DEFAULT_DISTRICT_EIDS,
        );
    }

    private function settings(): Settings
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findAll')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(SettingRecord::class)->willReturn($repo);

        $publishables = new PublishableRegistry();

        return new Settings(
            $em,
            new Repository([]),
            new ContentReadContext(),
            $publishables,
            new StagingWriter(new StagingStore($em), $publishables),
        );
    }
}
