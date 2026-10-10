<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Support\Str;
use Modules\Events\Domain\CatalogEvent;

final class EventDemoSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?EventMediaLibrary $media = null,
    ) {
    }

    public function runMissing(): int
    {
        $existing = [];
        foreach ($this->em->getRepository(CatalogEvent::class)->findAll() as $event) {
            if ($event instanceof CatalogEvent) {
                $existing[$event->slug()] = $event;
            }
        }
        $created = 0;
        $updated = 0;
        foreach ($this->rows() as $row) {
            $slug = Str::slug((string) $row['title']);
            $event = $existing[$slug] ?? null;
            if ($event instanceof CatalogEvent) {
                if ($this->needsRichCopy($event) && isset($row['long'])) {
                    $this->applyCopy($event, $row);
                    $updated++;
                }
                if ($event->region() !== $row['region']) {
                    $event->setRegion($row['region']);
                    $updated++;
                }
                if ($this->galleryNeedsRefresh($event)) {
                    $event->setGallery(EventGallery::normalize($this->galleryFor($row)));
                    $updated++;
                }
                if ($this->media !== null && $this->attachRowMedia($event, $row)) {
                    $updated++;
                }
                continue;
            }
            $this->persistRow($row);
            $created++;
        }
        foreach ($this->em->getRepository(CatalogEvent::class)->findBy(['region' => 'Plzeň']) as $legacy) {
            if ($legacy instanceof CatalogEvent) {
                $legacy->setRegion('Plzeň-město');
                $updated++;
            }
        }
        if ($created > 0 || $updated > 0) {
            $this->em->flush();
        }

        return $created + $updated;
    }

    public function run(): int
    {
        $n = 0;
        foreach ($this->rows() as $row) {
            $this->persistRow($row);
            $n++;
        }
        if ($n > 0) {
            $this->em->flush();
        }

        return $n;
    }

    /** @return list<array<string, mixed>> */
    public function demoRows(): array
    {
        return $this->rows();
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    public function demoGallery(array $row): array
    {
        return EventGallery::normalize($this->galleryFor($row));
    }

    /** @param array<string, mixed> $row */
    private function persistRow(array $row): void
    {
        $event = new CatalogEvent($row['title'], $row['category'], $row['place'], $row['region'], $row['starts']);
        $this->applyCopy($event, $row);
        $event->setImageKey($row['image']);
        $event->setGallery(EventGallery::normalize($this->galleryFor($row)));
        $event->publish();
        $this->em->persist($event);
        $this->attachRowMedia($event, $row);
    }

    /** @param array<string, mixed> $row */
    private function attachRowMedia(CatalogEvent $event, array $row): bool
    {
        if ($this->media === null) {
            return false;
        }
        $photo = trim((string) ($row['photo'] ?? ''));
        $key = (string) ($row['image'] ?? $event->imageKey());
        if ($photo !== '') {
            if ($this->media->coverMatchesUrl($event, $photo)) {
                return false;
            }
            $this->media->attachCoverFromUrl($event, $photo, $key);

            return true;
        }
        if ($event->mediaAssetId() !== null) {
            return false;
        }
        $this->media->attachCover($event, $key);

        return true;
    }

    private function commons(string $file, int $width = 1600): string
    {
        return 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode($file) . '?width=' . $width;
    }

    private function mediaFile(string $file): string
    {
        return 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode($file);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function galleryFor(array $row): array
    {
        if (isset($row['gallery']) && is_array($row['gallery'])) {
            return $row['gallery'];
        }
        $cover = (string) ($row['photo'] ?? '');
        $pool = array_values(array_filter(
            $this->galleryPhotoPool(),
            static fn (string $url): bool => $url !== $cover,
        ));
        $seed = abs(crc32((string) ($row['title'] ?? 'event')));
        $items = [];
        for ($i = 0; $i < 3 && $pool !== []; $i++) {
            $url = $pool[($seed + $i * 7) % count($pool)];
            $items[] = [
                'kind' => 'photo',
                'url' => $url,
                'title' => 'Z místa',
            ];
        }
        $category = (string) ($row['category'] ?? '');
        if ($category === 'Zábava') {
            $items[] = [
                'kind' => 'video',
                'url' => $this->mediaFile('Fireworks.webm'),
                'title' => 'Záznam atmosféry',
            ];
        }
        if ($category === 'Pro děti') {
            $items[] = [
                'kind' => 'gif',
                'url' => $this->mediaFile('Newtons_cradle_animation_book_2.gif'),
                'title' => 'Pohyb a hra',
            ];
        }

        return $items;
    }

    /** @return list<string> */
    private function galleryPhotoPool(): array
    {
        return [
            $this->commons('Chodské slavnosti 2018 (001).jpg'),
            $this->commons('Chodské slavnosti 2018 (002).jpg'),
            $this->commons('Chodské slavnosti 2018 (003).jpg'),
            $this->commons('Chodské slavnosti 2018 (004).jpg'),
            $this->commons('Domažlice, Chodský hrad (9091).jpg'),
            $this->commons('Domažlice, náměstí Míru 65-68 (9457).jpg'),
            $this->commons('Metalfest-plzen.JPG'),
            $this->commons('Klatovy Černá věž (3).jpg'),
            $this->commons('Nýrsko, řeka Úhlava I.jpg'),
            $this->commons('Plzeň - Náměstí Republiky - Panorama View 360° 16.jpg'),
            $this->commons('Horšovský Týn 2012-08-05 Zámek - průčelí.jpg'),
            $this->commons('Velhartice hrad most.jpg'),
        ];
    }

    /** @param array<string, mixed> $row */
    private function applyCopy(CatalogEvent $event, array $row): void
    {
        $event->setDescription($row['description']);
        $event->setLongDescription($row['long'] ?? null);
        $event->setColor($row['color']);
        $event->setTimeLabel($row['time']);
        $event->setPrice($row['price'] ?? null);
        $event->setCapacity($row['capacity'] ?? null);
        $event->setOrganizer($row['organizer'] ?? null);
        if (isset($row['ends'])) {
            $event->setEndsAt($row['ends']);
        }
        if (isset($row['sections']) && is_array($row['sections'])) {
            $event->setSections($row['sections']);
        }
        $event->setSpotlight($row['spotlight'] ?? 'none');
    }

    private function galleryNeedsRefresh(CatalogEvent $event): bool
    {
        if ($event->gallery() === []) {
            return true;
        }
        foreach ($event->gallery() as $item) {
            $url = rawurldecode((string) ($item['url'] ?? ''));
            $kind = (string) ($item['kind'] ?? '');
            if ($kind === 'clip' || $kind === 'audio' || $kind === 'song') {
                return true;
            }
            if (
                str_contains($url, 'Danse macabre.ogg')
                || str_contains($url, 'Camille Saint-Sa')
                || str_contains($url, 'Vltava-main-theme')
                || preg_match('/\.(mp3|ogg|oga|wav|m4a|aac|flac)(\?|$)/', $url) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    private function needsRichCopy(CatalogEvent $event): bool
    {
        $plain = trim(html_entity_decode(strip_tags((string) $event->longDescription()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $plain === '' || mb_strlen($plain) < 1000 || $event->sections() === [];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function html(array $blocks): string
    {
        $out = [];
        foreach ($blocks as $block) {
            if (isset($block['h3'])) {
                $out[] = '<h3>' . $this->e((string) $block['h3']) . '</h3>';
            }
            foreach ($block['p'] ?? [] as $paragraph) {
                $out[] = '<p>' . $this->e((string) $paragraph) . '</p>';
            }
            if (isset($block['ul']) && is_array($block['ul'])) {
                $items = [];
                foreach ($block['ul'] as $item) {
                    $items[] = '<li>' . $this->e((string) $item) . '</li>';
                }
                $out[] = '<ul>' . implode('', $items) . '</ul>';
            }
        }

        return implode("\n", $out);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $tz = new \DateTimeZone('Europe/Prague');
        $at = static fn (string $d, string $t = '10:00'): \DateTimeImmutable => new \DateTimeImmutable($d . ' ' . $t, $tz);

        return [
            [
                'title' => 'Chodské slavnosti 2026',
                'image' => 'castle',
                'photo' => $this->commons('10.8.24 1 Chodske Slavnosti Domazlice 083 (53918409019).jpg'),
                'category' => 'Kultura',
                'color' => 'purple',
                'starts' => $at('2026-10-17', '10:00'),
                'ends' => $at('2026-10-19', '22:00'),
                'time' => 'od 10:00',
                'place' => 'Domažlice – historické centrum',
                'region' => 'Domažlice',
                'price' => 'od 890 Kč',
                'capacity' => 'festival · cca 4 500 lidí',
                'organizer' => 'Domažlice Live',
                'spotlight' => 'month',
                'description' => 'Tři dny krojů, muziky a náměstí, které žije.',
                'long' => $this->html([
                    ['p' => ['Chodské slavnosti 2026 znovu naplní historické jádro Domažlic krojovaným průvodem, jarmarkem a hudbou, která se nese od Šatlavy až k bráně. Tři dny, kdy město nepatří autům, ale sousedům, návštěvníkům z Horšovského Týna, Klatov i Plzně a všem, kdo chtějí vidět Chodsko tak, jak se tu slaví po generace. Náměstí se od pátku mění v otevřenou scénu: stánky s řemeslem, ochutnávky, dětské dílny a večery, u kterých má smysl zůstat až do tmy.']],
                    ['h3' => 'Program a atmosféra', 'p' => ['Páteční odpoledne otevírá jarmark a první pódia. Sobota je nejsilnější — průvod krojů, hlavní koncert a program, který se vejde do pěší vzdálenosti. Neděle patří klidnějšímu dopoledni, mši a zakončení, po kterém se centrum pomalu vrací k běžnému rytmu, ale chuť slavností zůstává.', 'Mezi vystoupeními stihnete kávu pod loubím, oběd z regionální kuchyně i procházku k Chodskému hradu. Tempo si určujete sami: někdo jde od stánku ke stánku, někdo si sedne na schody a poslouchá. Obě cesty platí.'], 'ul' => ['Pátek 7. 8. — otevření jarmarku od 14:00, večerní koncert na náměstí', 'Sobota 8. 8. — krojovaný průvod, hlavní pódium, dílny pro děti', 'Neděle 9. 8. — dopolední mše, odpolední zakončení slavností', 'Chodsko na talíři: koláče, uzené, limonády a pivo z blízkých pivovarů', 'Vše v historickém jádru, bez nutnosti přesouvat se MHD']],
                    ['h3' => 'Praktické informace', 'p' => ['Vstup na denní program je volný, na večerní koncerty platí vstupenky od 890 Kč v předprodeji. Parkování je značeno od silnice na Horšovský Týn a od Kdyně; v sobotu doporučujeme přijet před desátou. Vezměte pohodlnou obuv a vrstvy — na náměstí umí být odpoledne horko a večer svěží.', 'Akci pořádá město Domažlice ve spolupráci se spolky. Přesně proto sem KdeAco patří: abyste o víkendu věděli, že se vyplatí zůstat ve městě, ne jen projet kolem.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Proč sem jet',
                        'paragraphs' => [
                            'Sobota je den, který se nevyplatí vynechat, pokud můžete jen jeden. Centrum se zaplní dřív, než začne průvod — přijďte ráno.',
                        ],
                        'list' => [
                            'Volný denní program, večerní koncerty na vstupenku',
                            'Parkování značeno od Horšovského Týna',
                            'Vhodné pro rodiny i večerní publikum',
                        ],
                    ],
                ],
            ],
            [
                'title' => 'The Silver Spoons – letní koncert',
                'image' => 'band',
                'photo' => $this->commons('Domažlice, Chodský hrad (9091).jpg'),
                'category' => 'Zábava',
                'color' => 'purple',
                'starts' => $at('2026-10-10', '19:00'),
                'time' => '19:00',
                'place' => 'Domažlice – Chodský hrad',
                'region' => 'Domažlice',
                'price' => 'od 350 Kč',
                'capacity' => '420 míst',
                'organizer' => 'Domažlice Live',
                'spotlight' => 'week',
                'description' => 'Kytary, letní večer a kapela, která vás nenechá stát. Potkáme se pod Chodským hradem.',
                'long' => $this->html([
                    ['p' => ['The Silver Spoons přivezou pod Chodský hrad večer, který začíná ještě za světla a končí, až když se z hradeb stane jen silueta. Kapela hraje vlastní věci i úpravy, u kterých se dá zpívat, aniž byste znali každou sloku. Není to festival s pěti pódii — je to jeden koncert, kde slyšíte kytary napřímo a vidíte, kdo vedle vás tleská. Přijďte dřív: bar a občerstvení běží od 17:30 a předzahrádka se plní rychleji než sál.']],
                    ['h3' => 'Jak večer probíhá', 'p' => ['Vstupenky od 350 Kč platí na stání i na vyhrazená místa u pódia. Předkapela hraje od 19:00, hlavní set kolem osmé. Po koncertě zůstává dvůr otevřený, dokud to počasí a sousedé dovolí — ideální chvíle na poslední pivo a cestu městem, které je v noci tiché, ale ještě žije.', 'Zvuk je nastavený na hradní nádvoří, ne na stadion. Když chcete být vepředu, přijďte k otevření bran. Když chcete mluvit mezi písničkami, držte se vzadu u baru.'], 'ul' => ['Brány 17:30, předkapela 19:00, The Silver Spoons cca 20:00', 'Kapacita 420 lidí — při vyprodání se další lístky nepouštějí', 'Občerstvení na místě, platba kartou i hotově', 'Vstupenky v předprodeji i na místě, dokud zbývají']],
                    ['h3' => 'Co se hodí vědět', 'p' => ['Akci pořádá Domažlice Live. V případě vytrvalého deště se program přesouvá do sálu v dosahu pěti minut — sledujte KdeAco v den koncertu. Dress code žádný není, večer na nádvoří ale umí být chladný, i když odpoledne bylo vedro.', 'Tohle je typ večera, kvůli kterému má smysl zůstat v okrese: nemusíte do Plzně, a přesto slyšíte kapelu, která hraje naplno.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Vstup a místo',
                        'paragraphs' => ['Nádvoří Chodského hradu, stání i místa u pódia. Lístky od 350 Kč.'],
                        'list' => ['Otevření 17:30', 'Hlavní set kolem 20:00', 'Déšť: náhradní sál v pěší vzdálenosti'],
                    ],
                ],
            ],
            [
                'title' => 'Běh městem Tachov',
                'image' => 'run',
                'photo' => $this->commons('Tachov - náměstí Republiky 116-120.JPG'),
                'category' => 'Sport',
                'color' => 'green',
                'starts' => $at('2026-10-12', '10:00'),
                'time' => '10:00',
                'place' => 'Tachov – náměstí Republiky',
                'region' => 'Tachov',
                'price' => 'zdarma',
                'capacity' => 'neomezeno',
                'organizer' => 'Běháme Tachovsko',
                'spotlight' => 'week',
                'description' => 'Obujte tenisky a zažijte město v pohybu. Přijďte běžet, fandit nebo si jen užít společný den.',
                'long' => $this->html([
                    ['p' => ['Běh městem Tachov je nedělní dopoledne, kdy se náměstí Republiky mění ve startovní koridor a ulice, kudy jinak jezdí auta, patří běžcům, kočárkům a lidem s megafonem. Nejde o elitní závod s čipem za tisíce — jde o to, projít nebo doběhnout město, které znáte z nákupů, tentokrát v teniskách. Trasy 5 a 10 km vedou centrem, kolem rybníka a zpět na náměstí, kde čeká občerstvení a výsledková listina, kterou nikdo nečte příliš vážně.']],
                    ['h3' => 'Trasy a registrace', 'p' => ['Start obou tratí je v 10:00, nástup od 9:15. Registrace je na místě zdarma, stačí jméno a věková kategorie. Děti do 12 let běží zkrácený okruh s doprovodem. Tempo je na vás: někdo to bere jako trénink, někdo jako procházku s číslem na triku.', 'Trasa 10 km má jedno občerstvení v polovině, 5 km jde bez zastávky. Oba okruhy jsou značené a hlídané na křižovatkách. Fandit můžete kdekoli podél trati — nejvíc lidí bývá u radnice a u mostu.'], 'ul' => ['5 km — mírný profil, vhodné i pro první start', '10 km — okruh městem a kolem vody, dva kopce', 'Dětský běh 500 m na náměstí v 9:40', 'Šatna a úschovna v budově radnice', 'Medaile pro všechny, kdo doběhnou']],
                    ['h3' => 'Prakticky', 'p' => ['Pořadatel Běháme Tachovsko doporučuje vlastní láhev a oblečení podle počasí. Parkování je u sportovní haly, pěšky k náměstí pět minut. Po doběhu zůstává náměstí otevřené s hudbou do jedné odpoledne.', 'Když nechcete běžet, přijďte fandit. Tachov v pohybu vypadá jinak než Tachov v pondělí ráno — a přesně o to tady jde.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Startovní informace',
                        'paragraphs' => ['Náměstí Republiky, neděle 10:00. Vstup zdarma, registrace na místě.'],
                        'list' => ['5 a 10 km + dětský běh', 'Úschovna na radnici', 'Parkování u sportovní haly'],
                    ],
                ],
            ],
            [
                'title' => 'Dětský den v Domažlicích',
                'image' => 'child',
                'photo' => $this->commons('Domažlice, Chodské náměstí, památník (9076).jpg'),
                'category' => 'Pro děti',
                'color' => 'orange',
                'starts' => $at('2026-10-11', '09:00'),
                'time' => '9:00',
                'place' => 'Domažlice – městský park',
                'region' => 'Domažlice',
                'price' => 'od 190 Kč',
                'capacity' => 'rodinné vstupy',
                'organizer' => 'Domažlice Live',
                'spotlight' => 'none',
                'description' => 'Pro malé objevitele — dílny, hry a spousta prostoru na hraní.',
                'long' => $this->html([
                    ['p' => ['Dětský den v městském parku je postavený tak, aby vydržel od rána do odpoledne, aniž byste museli dítě tahat z jednoho konce města na druhý. Stanoviště, tvoření, skákací hrad, krátké show na malém pódiu a stín stromů, když slunce začne pálit. Vstupenka od 190 Kč platí na celý program; rodiče jdou s dětmi, ne čekají za plotem. Park je oplocený jen v místech atrakcí, zbytek zůstává volný k pikniku.']],
                    ['h3' => 'Co děti čeká', 'p' => ['Dopoledne patří dílnám: malování na kamínky, jednoduché řemeslo, šátky a korálky. Od jedenácti se na pódiu střídají pohádka, kouzelník a taneční skupina ze ZUŠ. Odpoledne je volnější — skákací atrakce, vodní hry (když je teplo) a honičky, které nikdo neorganizuje, a přesto fungují.', 'Stanoviště mají razítka do karty. Kdo posbírá všechna, dostane malou cenu na infostánku. Není to povinnost, jen důvod obejít park dokola.'], 'ul' => ['Otevření 9:00, hlavní show 11:00 a 14:00', 'Dílny průběžně, bez rezervace, dokud je materiál', 'Rodinné vstupné výhodnější než jednotlivé lístky', 'Občerstvení bez smažených věcí v první řadě — i ovocné mísy a limonáda', 'Přebalovací kout a klidová zóna u horní brány']],
                    ['h3' => 'Pro dospělé', 'p' => ['Pořadatel Domažlické rodiny počítá s kočárky i s dětmi, které už utíkají. Doporučujeme přijít dopoledne, než se naplní dílny. V parku je tráva, deka se hodí. Psi jen na vodítku a mimo zónu atrakcí.', 'Když hledáte neděli, která neskončí u tabletu, tohle je nejkratší cesta z náměstí.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Vstup a čas',
                        'paragraphs' => ['Městský park od 9:00. Vstup od 190 Kč, rodinné balíčky na místě i online.'],
                        'list' => ['Dílny bez rezervace', 'Klidová zóna u horní brány', 'Dopoledne je volnější než odpoledne'],
                    ],
                ],
            ],
            [
                'title' => 'Michal Hrůza – Open Air',
                'image' => 'music',
                'photo' => $this->commons('Metalfest-plzen.JPG'),
                'category' => 'Zábava',
                'color' => 'purple',
                'starts' => $at('2026-10-09', '20:00'),
                'time' => '20:00',
                'place' => 'Plzeň – Lochotín',
                'region' => 'Plzeň-město',
                'price' => 'od 590 Kč',
                'capacity' => 'amfiteátr',
                'organizer' => 'Plzeň Live',
                'spotlight' => 'week',
                'description' => 'Open air večer s největšími hity pod širým nebem.',
                'long' => $this->html([
                    ['p' => ['Michal Hrůza a kapela na Lochotíně je open air, jaký Plzeň umí: amfiteátr, svah, deky a hity, které znáte z rádia i z auta na D5. Začátek v 20:00 znamená, že slunce ještě stačí klesnout za stromy, než přijde nejsilnější část setu. Brány se otevírají v 18:00, občerstvení a merch jedou hned. Tohle není klubový koncert — počítejte s davem, s frontou na pivo a s tím, že si místo na trávě musíte uhájit včas.']],
                    ['h3' => 'Set a místo', 'p' => ['Program počítá s plným setem včetně písní, u kterých vstává celý svah. Ozvučení je stavěné na amfiteátr, sedadla v dolní části mají lepší výhled, tráva nahoře víc vzduchu. Vstupenky od 590 Kč, VIP sektor s odděleným vstupem se prodává zvlášť a rychle mizí.', 'Předkapele hrají od 18:30. Kdo chce jen Hrůzu, stihne přijít k osmé, ale místo na dekách už bude obsazené.'], 'ul' => ['Brány 18:00, předkapela 18:30, hlavní program 20:00', 'Deka a vrstvy oblečení — večer na Lochotíně chladne', 'Zákaz skleněných lahví, kontrola u vstupu', 'MHD výhodnější než parkování v ulicích pod svahem', 'Odvoz po koncertě posílený, poslední spoje sledujte v den akce']],
                    ['h3' => 'Vstupenky a počasí', 'p' => ['Pořadatel Plzeň Live koncert při vytrvalém dešti neruší, jen upravuje nástupy. Vstupenka platí bez ohledu na počasí. Lístky jen v oficiálním předprodeji — u vstupu se dokupují jen zbytky, pokud nějaké zbydou.', 'Když chcete jeden velký večer v kraji, aniž byste jeli do Prahy, Lochotín v červnu je jistota.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Než vyrazíte',
                        'paragraphs' => ['Amfiteátr Lochotín, brány od 18:00. Vstup od 590 Kč.'],
                        'list' => ['Deka, ne sklo', 'MHD místo auta', 'VIP se prodává zvlášť'],
                    ],
                ],
            ],
            [
                'title' => 'Výlet na hrad Rabí',
                'image' => 'castle',
                'photo' => $this->commons('Rabi - east view 01.jpg'),
                'category' => 'Ostatní',
                'color' => 'green',
                'starts' => $at('2026-10-25', '09:00'),
                'time' => '9:00',
                'place' => 'Klatovy – hrad Rabí',
                'region' => 'Klatovy',
                'price' => 'od 120 Kč',
                'capacity' => 'skupina 40',
                'organizer' => 'Šumava Trails',
                'description' => 'Procházka, výhledy a příběhy jednoho z největších hradů.',
                'long' => $this->html([
                    ['p' => ['Společný výlet na hrad Rabí je sobota pro lidi, kteří chtějí hrad slyšet, ne jen vyfotit z parkoviště. Sraz v 9:00 u horního parkoviště, tempo mírné, skupina max. 40 lidí. Průvodce vede prohlídku paláce, nádvoří a vyhlídky, kde je vidět krajina, kvůli které sem má smysl jet z Klatov i z Domažlic. Vstup od 120 Kč platí prohlídku; cesta autem nebo autobusem je na vás, spolujízdu lze domluvit v registraci.']],
                    ['h3' => 'Průběh dne', 'p' => ['Dopoledne patří prohlídce s výkladem o Žižkovi, o kameni a o tom, proč je Rabí tak rozlehlé. Poledne je volné — oběd v hospodě pod hradem, nebo vlastní svačina na zdi. Odpoledne krátká procházka podél Otavy pro ty, kdo chtějí ještě kousek jít. Návrat k autům kolem 15:30.', 'Děti od 8 let to zvládnou, menší spíš v nosítku než po schodech paláce. Pes jen tam, kam hrad psy pouští — ověřte si to v den výletu.'], 'ul' => ['Sraz 9:00, začátek prohlídky 9:20', 'Pohodlná obuv, hrady nemají výtah', 'Rezervace nutná, kapacita 40', 'Oběd individuálně, seznam hospod pošleme den předem', 'Zrušení do pátku večer bez stornopoplatku']],
                    ['h3' => 'Proč s námi, ne jen sami', 'p' => ['Krajské výlety drží skupinu pohromadě, aby výklad neskončil u informační tabule. Platíte prohlídku a organizaci, ne zájezd s mikrofonem v autobuse. Když přijedete sami, stejně se přidáte na nádvoří — a uslyšíte víc než z letáku.', 'Rabí v červnu bývá plné turistů. Společný start znamená, že máte průvodce jistého a nestojíte ve frontě jako náhodný návštěvník.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Sraz',
                        'paragraphs' => ['Horní parkoviště hradu Rabí, sobota 9:00. Vstup od 120 Kč, max. 40 lidí.'],
                        'list' => ['Mírné tempo', 'Děti od 8 let', 'Rezervace nutná'],
                    ],
                ],
            ],
            [
                'title' => 'MTB závod Šumava',
                'image' => 'bike',
                'photo' => $this->commons('Šumava lesy.JPG'),
                'category' => 'Sport',
                'color' => 'green',
                'starts' => $at('2026-10-18', '10:00'),
                'time' => '10:00',
                'place' => 'Klatovy – Šumava',
                'region' => 'Klatovy',
                'price' => 'od 450 Kč',
                'capacity' => '250 jezdců',
                'organizer' => 'Šumava Trails',
                'description' => 'Trasy kolem Klatov pro hobby i závodní jezdce.',
                'long' => $this->html([
                    ['p' => ['MTB závod Šumava startuje z depa nad Klatovy a vede po tratích, které znají místní jezdci z tréninků, ne z grafiky na webu. Dvě délky: hobby okruh kolem 25 km a závodní trať přes 45 km s převýšením, které vás donutí řadit. Start v 10:00, prezentace od 8:00. Startovné od 450 Kč zahrnuje číslo, občerstvení na trati a pivo nebo limonádu v cíli. Kapacita 250 jezdců, e-biky mají vlastní vlnu.']],
                    ['h3' => 'Tratě a pravidla', 'p' => ['Hobby trať je sjízdná i pro zdatnější začátečníky, ale po dešti klouže. Závodní trať má kořeny, krátké tlačenky a sjezdy, kde se nevyplácí riskovat bez zkušenosti. Přilba povinná, chrániče doporučené. Pořadatel Šumava Trails trať značí den předem, vymýšlet zkratky se neproplácí časem, ale diskvalifikací.', 'Občerstvení je na dvou místech: voda, iontový nápoj, ovoce. Mechanik v depu, ne v lese — vezměte duši a sadu.'], 'ul' => ['Prezentace 8:00–9:40, start 10:00 vlnově', 'Hobby 25 km, závod 45 km, e-bike samostatně', 'Čipové měření, výsledky online odpoledne', 'Parkování u depa, stan možné od pátku večer', 'Uzávěrka přihlášek při 250 jezdících, ne v den závodu']],
                    ['h3' => 'Po závodě', 'p' => ['Depo zůstává otevřené s jídlem a vyhlášením kolem 14:30. Sprchy v nedaleké hale, pěšky deset minut. Když nejedete závod, trať v ten den nepatří volným výletům — pořadatel žádá, abyste si kolo nechali na neděli.', 'Šumava umí být v červnu mokrá. Sledujte počasí a jeďte na pláštích, které znáte, ne na nových, co jste ještě neseřídili.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Startovné',
                        'paragraphs' => ['Od 450 Kč, 250 míst. Přilba povinná, e-biky ve vlastní kategorii.'],
                        'list' => ['Hobby 25 km / závod 45 km', 'Prezentace od 8:00', 'Kemp u depa od pátku'],
                    ],
                ],
            ],
            [
                'title' => 'Divadelní představení – Lakomec',
                'image' => 'theatre',
                'photo' => $this->commons('Domažlice, náměstí Míru 65-68 (9457).jpg'),
                'category' => 'Kultura',
                'color' => 'pink',
                'starts' => $at('2026-10-15', '19:00'),
                'time' => '19:00',
                'place' => 'Domažlice – Městské divadlo',
                'region' => 'Domažlice',
                'price' => 'od 280 Kč',
                'capacity' => 'hlavní sál',
                'organizer' => 'Tereza Malá',
                'description' => 'Klasika, která pořád umí rozesmát — a trochu i naštvat.',
                'long' => $this->html([
                    ['p' => ['Lakomec v Městském divadle Domažlice je komedie, kterou znáte ze školy, ale tady ji hraje soubor, který Harpagona nenechá být jen karikaturou. Režie drží tempo, text je srozumitelný a sál je dost blízko jevišti, abyste viděli, kdy herec hraje do hlediště schválně. Vstupenky od 280 Kč, začátek v 19:00, doporučený příchod dvacet minut předem — pozdní vstup se pouští až mezi obrazy.']],
                    ['h3' => 'Představení', 'p' => ['Délka zhruba dvě hodiny s jednou přestávkou. Kostýmy nejsou muzeální, scéna je civilní, vtipy sedí i bez znalosti Molièra. Po představení krátká debata s herci v foyer — dobrovolná, bez mikrofonů, spíš sklenka a otázky.', 'Hlavní sál, číslovaná sedadla. Prostřední řady mizí první. Balkón má horší sklon, ale levnější lístky.'], 'ul' => ['Pokladna otevřená od 18:00, předprodej online', 'Šatna v provozu, kabáty nenechávejte na sedadle', 'Mobil na tichý režim, fotografování během hry ne', 'Bar v přestávce, karta i hotovost', 'Bezbariérový vstup z boční ulice, hlaste se dopředu']],
                    ['h3' => 'Pro koho to je', 'p' => ['Střední školy sem jezdí, ale večerní představení je spíš pro dospělé publikum, které chce klasiku bez školního napětí. Děti mladší dvanácti spíš neusnou — spíš nepochopí, proč se dospělí smějí penězům.', 'Městské divadlo Domažlice hraje Lakomce v omezené sérii. Když chcete jistotu místa, nekupujte lístek až v neděli odpoledne.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Vstupenky',
                        'paragraphs' => ['Hlavní sál, od 280 Kč, začátek 19:00. Číslovaná místa.'],
                        'list' => ['Příchod 18:40', 'Debata po představení', 'Bezbariérový vstup po ohlášení'],
                    ],
                ],
            ],
            [
                'title' => 'Rock pod Šumavou 2026',
                'image' => 'rock',
                'photo' => $this->commons('10.8.24 1 Chodske Slavnosti Domazlice 024 (53917161737).jpg'),
                'category' => 'Zábava',
                'color' => 'purple',
                'starts' => $at('2026-10-24', '16:00'),
                'ends' => $at('2026-10-26', '23:00'),
                'time' => 'od 16:00',
                'place' => 'Klatovy – amfiteátr',
                'region' => 'Klatovy',
                'price' => 'od 890 Kč',
                'capacity' => 'festival',
                'organizer' => 'Běháme Tachovsko',
                'description' => 'Tři dny hudby pod širým nebem na Klatovsku.',
                'long' => $this->html([
                    ['p' => ['Rock pod Šumavou 2026 je třídenní open-air nad Klatovy: jedno hlavní pódium, food zóna a kemp, odkud na koncert dojdete pěšky za pár minut. Pátek otevírá areál od 16:00, sobota je nejsilnější line-up, neděle končí dřív, aby se dalo dojet domů. Vstupenky od 890 Kč na den, třídenní výhodnější. Tohle není letiště s dvaceti stany — je to festival, kam dojedete z Domažlic i z Plzně a ráno vás vzbudí ozvěna basy, ne siréna z dálnice.']],
                    ['h3' => 'Line-up a kemp', 'p' => ['Každý den jiný headliner, odpoledne regionální kapely. Program zveřejňujeme po blocích; sobotní večer držte volný, i když zbytek víkendu pracujete. Kemp otevírá ve čtvrtek večer, sprchy jsou, ale fronta v sobotu ráno taky. Stany jen v vyznačeném sektoru, auta mimo louku u pódia.', 'Food zóna jede do noci, voda z tanku je pitná. Tvrdý alkohol v kempu po třetí ráno pořadatel netoleruje — hluk vůči obci je důvod, proč festival vůbec smí existovat.'], 'ul' => ['Pátek od 16:00, sobota od 13:00, neděle od 12:00', 'Třídenní vs. denní vstupenka, kemp zvlášť', 'Zákaz skla a pyrotechniky', 'Kyvadlo z klatovského nádraží v pátek a sobotu', 'Děti do 12 let na denní vstup zdarma s rodičem']],
                    ['h3' => 'Jak to přežít', 'p' => ['Vrstvy, pláštěnka, dobíječka. Počasí na úpatí Šumavy se láme rychle. Pořadatel Rock pod Šumavou nevrací vstupné za déšť. Lékař je v areálu, nejbližší ATB v Klatovech.', 'Když chcete jen sobotu, přijďte odpoledne, ne až k headlinerovi — cesta od parkoviště trvá déle, než si myslíte.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Festivalové minimum',
                        'paragraphs' => ['Amfiteátr u Klatov, pátek až neděle. Vstup od 890 Kč / den.'],
                        'list' => ['Kemp od čtvrtka', 'Kyvadlo z nádraží', 'Sobota = hlavní den'],
                    ],
                ],
            ],
            [
                'title' => 'Pilsner Fest 2026',
                'image' => 'city',
                'photo' => $this->commons('Plzeň - Sady 5. května - View East over Mlýnská strouha towards Plzeňský Prazdroj (Pilsner Urquell Brewery).jpg'),
                'category' => 'Kultura',
                'color' => 'yellow',
                'starts' => $at('2026-10-02', '12:00'),
                'ends' => $at('2026-10-03', '23:00'),
                'time' => 'od 12:00',
                'place' => 'Plzeň – Prazdroj',
                'region' => 'Plzeň-město',
                'price' => 'od 490 Kč',
                'capacity' => 'areál pivovaru',
                'organizer' => 'Plzeňský Prazdroj',
                'spotlight' => 'month',
                'description' => 'Víkend v areálu pivovaru: pivo, kuchyně a pódia mezi komíny.',
                'long' => $this->html([
                    ['p' => ['Pilsner Fest 2026 otevírá areál Prazdroje na dva dny veřejnosti tak, jak to město zná: piva z tanku, kuchyně, která k pivu patří, a pódia mezi cihlovými halami. Vstup od 490 Kč platí na den včetně prvních toků podle typu lístku. Pátek je víc koncertní, sobota víc rodinná a rumělková od poledne. Není to jarmark na náměstí — je to festival v pivovaru, kde voní slad a kde se fronta na točené točí stejně jistě jako směna.']],
                    ['h3' => 'Co uvnitř', 'p' => ['Prohlídkové trasy jedou zkráceně, vstup na ně je v lístku nebo jako příplatek podle varianty. Hudební program střídá české kapely a DJ after. Dětská zóna v sobotu od 12:00 do 18:00, večer už areál patří dospělým.', 'Jídlo je drahé jako na každém festivalu, ale porce nejsou hračky. Voda z výdejních míst je zdarma. Kouření jen v označených koutech.'], 'ul' => ['Pátek 12:00–23:00, sobota 11:00–23:00', 'MHD k bráně pivovaru, parkování mimo centrum', 'Lístek na den, dvoudenní výhodnější do srpna', 'Vstup 18+ po 20:00, do té doby děti s rodiči', 'Příchod bez skla a bez vlastního alkoholu']],
                    ['h3' => 'Kdo to má řešit teď', 'p' => ['Kdo chce vidět Plzeň, jak slaví pivo u zdroje, ne u stánku na náměstí. Kdo nesnáší davy, ať jde v pátek odpoledne, ne v sobotu v pět. Pořadatel je Plzeňský Prazdroj, program se může měnit podle kapel, ne podle počasí — areál má zčásti střechu.', 'Lístky jen oficiálně. U brány se v říjnu zázračně neobjevují volná místa, když je vyprodáno.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Vstup',
                        'paragraphs' => ['Areál Prazdroje, pátek a sobota. Od 490 Kč / den.'],
                        'list' => ['Sobota víc rodin přes den', 'Po 20:00 18+', 'MHD k bráně'],
                    ],
                ],
            ],
            [
                'title' => 'Noční prohlídky hradu Švihov',
                'image' => 'castle',
                'photo' => $this->commons('Hrad Švihov od východu.jpg'),
                'category' => 'Ostatní',
                'color' => 'green',
                'starts' => $at('2026-07-18', '21:00'),
                'time' => '21:00',
                'place' => 'Švihov – vodní hrad',
                'region' => 'Klatovy',
                'price' => 'od 220 Kč',
                'capacity' => 'skupiny po 25',
                'organizer' => 'NPÚ Švihov',
                'description' => 'Hrad při svíčkách, vodní příkop a výklad, který ve dne neslyšíte.',
                'long' => $this->html([
                    ['p' => ['Noční prohlídky vodního hradu Švihov začínají ve 21:00, když se z příkopu stane černé zrcadlo a z nádvoří scéna se svíčkami. Průvodce neopakuje denní text — mluví o nočním provozu hradu, o vodě, o strachu z požáru a o tom, co v noci hlídali. Skupiny po 25 lidech, vstup od 220 Kč, rezervace nutná. Není to strašidelný park pro děti, i když tma umí zaskočit. Je to prohlídka pro lidi, kteří Švihov ve dne už viděli, nebo chtějí první dojem mít silnější.']],
                    ['h3' => 'Jak to probíhá', 'p' => ['Sraz 20:45 u mostu. Prohlídka trvá zhruba 75 minut, schody, úzké chodby, žádný výtah. Pohodlná obuv a baterka v telefonu stačí, ale nesviťte průvodci do obličeje. Fotky bez blesku, stativy ne.', 'Počasí: při bouřce se termín ruší, vstupné se vrací nebo přesouvá. Při mírném dešti se jde, nádvoří klouže.'], 'ul' => ['Termíny o sobotách v červenci, vždy 21:00', 'Kapacita 25, další skupina 22:20 pokud je zájem', 'Děti spíš od 10 let, kočárek se dovnitř nevejde', 'Parkování pod hradem, posledních 200 m pěšky', 'Pokladna jen předem online, na místě hotovost výjimečně']],
                    ['h3' => 'Po prohlídce', 'p' => ['Hospoda ve vsi jede do deseti. Zpět do Klatov autem dvacet minut, autobus noční nejezdí — počítejte s vlastním odvozem. NPÚ Švihov drží noční prohlídky jako omezenou sérii, ne jako každodenní produkt.', 'Když chcete hrad bez denního davu a s vodou, která v noci něco znamená, tohle je termín, který se vyplatí rezervovat včas.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Rezervace',
                        'paragraphs' => ['Soboty v červenci, 21:00, skupiny po 25. Od 220 Kč.'],
                        'list' => ['Sraz 20:45 u mostu', '75 minut', 'Vlastní odvoz nutný'],
                    ],
                ],
            ],
            [
                'title' => 'Farmářský trh v Klatovech',
                'image' => 'other',
                'photo' => $this->commons('Klatovy Černá věž (3).jpg'),
                'category' => 'Kultura',
                'color' => 'green',
                'starts' => $at('2026-06-13', '08:00'),
                'ends' => $at('2026-06-13', '13:00'),
                'time' => '8:00–13:00',
                'place' => 'Klatovy – náměstí Míru',
                'region' => 'Klatovy',
                'price' => 'zdarma',
                'capacity' => 'náměstí',
                'organizer' => 'Klatovské trhy',
                'description' => 'Sobotní ráno se sýry, chlebem a věcmi, které nevozí velkosklad.',
                'long' => $this->html([
                    ['p' => ['Farmářský trh na klatovském náměstí Míru je sobota od osmi do jedné, kdy se z dlažby stane krám bez dveří. Sýry z Pošumaví, chléb, který dojde před polednem, zelenina, med, maso od řezníků, co nevozí jen z burzy, a pár stánků s řemeslem, aby to nebylo jen o jídle. Vstup zdarma. Nejlepší věci mizí do deseti — kdo přijde v poledne, kupuje, co zbylo, ne to, kvůli čemu vstával.']],
                    ['h3' => 'Co tu bývá', 'p' => ['Stálí prodejci se střídají s hosty z Domažlicka a Tachovska. Platí se hotově častěji než kartou, ale karty přibývají. Ochutnávky jsou, ochutnávat celý sýr zadarmo ne. Tašky si přineste vlastní, igelit na trhu spíš neseženete.', 'Káva z vozíku a snídaně v lokálech kolem náměstí patří k ritálu. Trh není festival — hudba je tichá, děti sem patří, psi na vodítku taky, pokud neokusují sýry.'], 'ul' => ['Každá druhá sobota, 8:00–13:00, déšť trh neruší', 'Seznam stánků týden předem na KdeAco', 'Parkování na okraji centra, pěšky pět minut', 'Hotovost jistější než karta', 'Po 12:30 se balí, ne rozbaluje']],
                    ['h3' => 'Proč sem i z okresu', 'p' => ['Klatovské trhy drží kvalitu tím, že nepouštějí každého s dodávkou. Když chcete nákup, který má smysl vézt do Tachova nebo do Kdyně, jeďte ráno. Když chcete jen atmosféru, stačí káva a dva stánečky.', 'Tohle je přesně akce, kterou KdeAco má ukazovat vedle koncertů: obyčejná sobota, která město drží pohromadě.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Čas',
                        'paragraphs' => ['Náměstí Míru, 8:00–13:00, vstup volný. Nejkvalitnější nákup do deseti.'],
                        'list' => ['Vlastní taška', 'Hotovost', 'Déšť neruší'],
                    ],
                ],
            ],
            [
                'title' => 'Jazz nad Úhlavou',
                'image' => 'music',
                'photo' => $this->commons('Nýrsko, řeka Úhlava I.jpg'),
                'category' => 'Zábava',
                'color' => 'purple',
                'starts' => $at('2026-07-11', '19:30'),
                'time' => '19:30',
                'place' => 'Klatovy – nábřeží Úhlavy',
                'region' => 'Klatovy',
                'price' => 'od 290 Kč',
                'capacity' => 'venkovní hlediště',
                'organizer' => 'Jazz Klatovy',
                'description' => 'Letní jazz u řeky: dechy, kontrabas a město, které zpomalí.',
                'long' => $this->html([
                    ['p' => ['Jazz nad Úhlavou je jeden večer na nábřeží, kde se místo reprodukované hudby z restaurací ozývá kvartet, který ví, co dělá s tichem mezi tóny. Začátek 19:30, vstup od 290 Kč, stání i několik lavic. Řeka bere hluk města a vrací ozvěnu saxofonu. Není to jam session do rána — je to koncert s jasným začátkem a koncem, po kterém se dá jít na pivo pěšky, ne taxíkem z haly.']],
                    ['h3' => 'Program', 'p' => ['Hostující kvartet z Prahy nebo Brna, předskokan z klatovské scény. Dva sety, přestávka dvacet minut. Vstupenky číslované jen na lavicích, zbytek je volné stání na trávě. Deka se hodí, moskyti taky — jsme u vody.', 'Bar je malý, sortiment krátký, fronta v přestávce jistá. Kdo chce klid, koupí drink před prvním setem.'], 'ul' => ['Brány 18:30, začátek 19:30, konec cca 22:00', 'Při silném dešti přesun do sálu ZUŠ, stejné lístky', 'Děti vítány, kočárky na kraji hlediště', 'Zákaz vlastního alkoholu v hrazeném sektoru', 'Parkování u zimního stadionu, pěšky osm minut']],
                    ['h3' => 'Proč přijít', 'p' => ['Jazz Klatovy točí letní sérii, tenhle termín je hlavní. Když máte jazz spojený jen s klubem v Plzni, Úhlava v červenci je argument, že kraj má vlastní scénu. Když jazz nesnášíte, tenhle text vás nepřesvědčí — a to je v pořádku.', 'Místa na lavicích mizí první týden. Stání zůstává déle, ale výhled k řece je pořád lepší než k zdi sálu.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Místo',
                        'paragraphs' => ['Nábřeží Úhlavy, 19:30. Vstup od 290 Kč. Déšť = sál ZUŠ.'],
                        'list' => ['Deka a moskyti', 'Dva sety', 'Lavice číslované'],
                    ],
                ],
            ],
            [
                'title' => 'Horšovskotýnský jarmark',
                'image' => 'castle',
                'photo' => $this->commons('Horšovský Týn 2012-08-05 Zámek - průčelí.jpg'),
                'category' => 'Kultura',
                'color' => 'pink',
                'starts' => $at('2026-09-12', '09:00'),
                'ends' => $at('2026-09-13', '18:00'),
                'time' => 'od 9:00',
                'place' => 'Horšovský Týn – podzámčí',
                'region' => 'Domažlice',
                'price' => 'zdarma',
                'capacity' => 'město',
                'organizer' => 'Město Horšovský Týn',
                'description' => 'Řemesla pod zámkem, hudba na nádvoří a dvoudenní město v krojích i bez nich.',
                'long' => $this->html([
                    ['p' => ['Horšovskotýnský jarmark plní podzámčí a náměstí na dva zářijové dny řemeslem, které ještě umí držet nůž a hlínu. Vstup volný. Sobota je hustší, neděle klidnější a vhodnější s dětmi. Zámek má v ty dny delší otevírací dobu, prohlídky jdou koupit zvlášť. Jarmark není kopie Domažlic — je menší, bližší zdím a víc o věcech, které si odnesete, než o pódiu.']],
                    ['h3' => 'Stánky a scéna', 'p' => ['Kováři, hrnčíři, tkalci, uzeniny, medovina, pečivo. Scéna na nádvoří střídá folklór a akustické kapely, nic, co by přebilo řeč u stánku. Dětský koutek u fary, skákací hrad jen v sobotu. Parkování za řekou, pěšky deset minut do kopce — počítejte s tím.', 'Platí se hotově i kartou, podle stánku. Ceny jsou jarmarkové, ne supermarketové — jste tady kvůli původu, ne kvůli slevě.'], 'ul' => ['Sobota 9:00–18:00, neděle 9:00–16:00', 'Prohlídky zámku zvlášť, fronta v sobotu odpoledne', 'Psi na vodítku, do interiéru zámku ne', 'Občerstvení v podzámčí i v restauracích ve městě', 'Nedělní dopoledne nejméně lidí ve frontě na řemeslo']],
                    ['h3' => 'Tip na víkend', 'p' => ['Spojte jarmark s prohlídkou zámku v neděli ráno, až odjedou sobotní davy. Město Horšovský Týn drží akci jako tradiční, ne jako nový festival s influencery. Když chcete koupit něco, co má váhu, mluvte s řemeslníkem, netahejte jen fotku.', 'Z Domažlic je to kousek. Vyplatí se zůstat na oběd, ne otočit se u prvního stánku.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Dva dny',
                        'paragraphs' => ['Podzámčí Horšovského Týna, vstup volný. Sobota hustší, neděle klidnější.'],
                        'list' => ['Parkování za řekou', 'Zámek zvlášť', 'Neděle do 16:00'],
                    ],
                ],
            ],
            [
                'title' => 'Plzeňský půlmaraton',
                'image' => 'run',
                'photo' => $this->commons('Plzeň - Náměstí Republiky - Panorama View 360° 16.jpg'),
                'category' => 'Sport',
                'color' => 'green',
                'starts' => $at('2026-04-19', '09:00'),
                'time' => '9:00',
                'place' => 'Plzeň – náměstí Republiky',
                'region' => 'Plzeň-město',
                'price' => 'od 750 Kč',
                'capacity' => '4 000 běžců',
                'organizer' => 'Plzeňský půlmaraton z.s.',
                'description' => 'Silniční půlmaraton centrem Plzně a zpět na náměstí.',
                'long' => $this->html([
                    ['p' => ['Plzeňský půlmaraton startuje z náměstí Republiky a vede městem tak, abyste viděli katedrálu, nábřeží i sídliště, která jinak z tramvaje jen míjíte. 21,1 km, měřený, limit 3 hodiny. Startovné od 750 Kč zahrnuje číslo, čip, triko a občerstvení. Kapacita 4 000, vlny podle předpokládaného času. Není to trail a není to běh „jen tak kolem rybníka“ — je to silnice, tempo a dav, který vás nese, pokud se nenecháte zaskočit prvními kilometry z kopce.']],
                    ['h3' => 'Trasa a zázemí', 'p' => ['Profil je mírně zvlněný, nejtěžší úsek kolem 16. kilometru, kdy už náměstí není vidět a ještě není cíl. Občerstvení každé 3 km, sprchy v hale po doběhu, úschovna zavazadel na náměstí. Relé 2×10,5 km má vlastní start o něco později.', 'Uzávěrky ulic od rána, MHD posílená, auta nechte za městem. Fandit lze podél trati, nejvíc lidí je v centru a u řeky.'], 'ul' => ['Prezentace v sobotu i v neděli ráno, nedělní fronta delší', 'Start 9:00, vlny po 3–4 minutách', 'Limit 3:00, vozy uzavírají trať', 'Zdravotníci na trati i v cíli', 'Výsledky a diplomy online odpoledne']],
                    ['h3' => 'Pro koho', 'p' => ['Pro běžce, kteří chtějí oficiální čas v krajském městě, ne jen komunitní běh. Pro diváky, kteří chtějí neděli ve městě bez aut v centru. Pořadatel drží akci každoročně, tratě se mírně mění podle uzavírek.', 'Když běžíte poprvé půlmaraton, Plzeň je slušný začátek: rovina, servis, dav. Když honíte osobák, hlídejte 16. kilometr, ne startovní euforii.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Start',
                        'paragraphs' => ['Náměstí Republiky, neděle 9:00. Startovné od 750 Kč, 4 000 míst.'],
                        'list' => ['Limit 3 hodiny', 'Relé zvlášť', 'Prezentace i v sobotu'],
                    ],
                ],
            ],
            [
                'title' => 'Pohádkový les Český les',
                'image' => 'kids',
                'photo' => $this->commons('Landscape by Pelechy.jpg'),
                'category' => 'Pro děti',
                'color' => 'orange',
                'starts' => $at('2026-06-20', '09:30'),
                'time' => '9:30',
                'place' => 'Tachov – okraj Českého lesa',
                'region' => 'Tachov',
                'price' => 'od 80 Kč',
                'capacity' => 'stezka po vlnách',
                'organizer' => 'Lesy a děti Tachov',
                'description' => 'Stezka s úkoly v lese, masky a razítka — bez plastového lunaparku.',
                'long' => $this->html([
                    ['p' => ['Pohádkový les na okraji Českého lesa u Tachova je stezka dlouhá zhruba dva kilometry s osmi stanovišti, kde potkáte masky, úkoly a razítka, ne skákací hrad u supermarketu. Start 9:30, vstup od 80 Kč na dítě, dospělí zdarma jako doprovod. Tempo dětské, terén lesní — kořeny, bláto po dešti, žádný asfalt. Když chcete, aby dítě vidělo strom, ne jen maskota z pěny, tohle je ten výlet.']],
                    ['h3' => 'Stezka', 'p' => ['Stanoviště obsluhují dobrovolníci a žáci z tachovských škol. Úkoly jsou jednoduché: hádanka, skok, poznávačka listů. Na konci malá odměna a oheň na koupání buřtů, pokud počasí dovolí. Poslední vstup na stezku v 15:00, ať stihnete vyjít za světla.', 'Kočárek jen v terénní variantě, spíš nosítko. Psi na vodítku, mimo stanoviště s jídlem.'], 'ul' => ['Parkování na louce u startu, kyvadlo z Tachova v celou a v půl', 'Vlastní pití a svačina, stánek je malý', 'Repelent, dlouhé rukávy, klíšťata jsou téma', 'Při bouřce se stezka uzavírá, vstupné se vrací', 'Fotky vítány, ale nestrkejte dětem mikrofon do obličeje']],
                    ['h3' => 'Organizace', 'p' => ['Lesy a děti Tachov točí akci jednou za červen. Kapacita není tvrdá, ale vlny na startu brání frontě v lese. Když přijedete v deset, jdete skoro hned. Když ve dvě, čekáte.', 'Není to zábavní park. Je to les, který na jeden den mluví pohádkou — a pak zase jen šumí. To je na tom to dobré.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Start stezky',
                        'paragraphs' => ['Okraj Českého lesa u Tachova, 9:30. Dítě od 80 Kč, dospělý doprovod zdarma.'],
                        'list' => ['2 km, 8 stanovišť', 'Poslední vstup 15:00', 'Kyvadlo z města'],
                    ],
                ],
            ],
            [
                'title' => 'Den na hradě Velhartice',
                'image' => 'castle',
                'photo' => $this->commons('Velhartice hrad most.jpg'),
                'category' => 'Ostatní',
                'color' => 'green',
                'starts' => $at('2026-08-22', '10:00'),
                'ends' => $at('2026-08-22', '17:00'),
                'time' => '10:00–17:00',
                'place' => 'Velhartice – hrad a zámek',
                'region' => 'Klatovy',
                'price' => 'od 160 Kč',
                'capacity' => 'areál',
                'organizer' => 'NPÚ Velhartice',
                'description' => 'Most, palác a den, kdy areál žije víc než běžnou prohlídkou.',
                'long' => $this->html([
                    ['p' => ['Den na hradě Velhartice je sobota, kdy se k běžným prohlídkám přidávají řemesla na nádvoří, šermířská ukázka a delší otevírací doba mostu, kvůli kterému sem lidé jezdí. Vstup od 160 Kč do areálu, prohlídkové okruhy zvlášť. Od 10:00 do 17:00. Krajina pod Šumavou, kámen a dřevo, žádný plastový „středověk“. Když Velhartice znáte jen z fotky mostu, tenhle den most uvidíte i zevnitř provozu, ne jen z parkoviště.']],
                    ['h3' => 'Program v areálu', 'p' => ['Dopoledne klidnější prohlídky, odpoledne ukázky a víc lidí. Šerm v 14:00 na dolním nádvoří, dílny pro děti průběžně. Občerstvení v hospodářském křídle, kapacita malá — svačina v batohu není ostuda.', 'Psi v areálu na vodítku, do interiérů ne. Kočárky na nádvoří ano, na most a do paláce spíš ne.'], 'ul' => ['Areál 10:00–17:00, pokladna do 16:15', 'Okruh palác a okruh podzemí, oba s průvodcem', 'Parkování pod hradem, výstup 10 minut', 'Bezbariérově jen část nádvoří', 'Poslední šermířská ukázka 14:00, při dešti zkrácená']],
                    ['h3' => 'Jak to spojit', 'p' => ['NPÚ Velhartice doporučuje spojit den s výletem na Klatovy nebo Rabí, ne cpát tři hrady do osmi hodin. Cesta z Klatov je autovstřícná, autobusů je málo — počítejte s vlastním vozem nebo se spolujízdou z KdeAco vlákna k akci.', 'Most je důvod, proč sem všichni jedou. Zůstaňte dost dlouho, abyste viděli i palác, ne jen selfie.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Areál',
                        'paragraphs' => ['Sobota 10:00–17:00, vstup od 160 Kč. Prohlídky okruhů zvlášť.'],
                        'list' => ['Šerm 14:00', 'Svačina s sebou', 'Parkování pod hradem'],
                    ],
                ],
            ],
            [
                'title' => 'Stand-up večer v Pekle',
                'image' => 'theatre',
                'photo' => $this->commons('Domažlice, Chodské náměstí, náměstí Míru 67 (9069).jpg'),
                'category' => 'Kultura',
                'color' => 'pink',
                'starts' => $at('2026-06-19', '20:00'),
                'time' => '20:00',
                'place' => 'Domažlice – klub Peklo',
                'region' => 'Domažlice',
                'price' => 'od 250 Kč',
                'capacity' => '90 míst',
                'organizer' => 'Peklo Live',
                'description' => 'Komici z Prahy a Plzně v sále, kde slyšíte i nadechnutí.',
                'long' => $this->html([
                    ['p' => ['Stand-up večer v klubu Peklo je 90 míst, pivo z pultu a komici, kteří hrají do obličeje, ne do kamery. Začátek 20:00, dveře 19:15, vstup od 250 Kč. Line-up tří jmen: jeden z Plzně, dva hosté. Není to televizní natáčení a není to open mic, kde se učí začátečníci. Je to večer, po kterém jdete pěšky přes náměstí a pořád se smějete větě, která by v hale zanikla.']],
                    ['h3' => 'Pravidla sálu', 'p' => ['Stoly jsou blízko, pozdní příchod ruší. Telefon v kapse, ne na stole. Nahrávat set bez svolení komika je důvod k vyvedení — Peklo tohle hlídá. Jídlo v sále ne, bar ano. Kuřárna venku, vchod se během setu nezavírá, ale světlo z dveří vadí.', 'Místa nečíslovaná, kdo chce první řadu, přijde k otevření. Kdo chce utéct po prvním komikovi, sedí vzadu.'], 'ul' => ['Dveře 19:15, začátek 20:00, tři bloky + přestávka', '18+ kvůli jazyku, ne kvůli baru', 'Vstupenky předem, na místě jen zbytky', 'Platba na baru kartou, vstupenka často jen QR', 'Po akci bar do 23:30, hudba tichá']],
                    ['h3' => 'Proč Peklo, ne Plzeň', 'p' => ['Peklo Live vozí jména, která jinak hrají kluby v krajských městech. Domažlice na mapě stand-upu chybí — tenhle večer to má spravit. Když vtipy o Praze nesnášíte, pořád zbývají vtipy o okrese, ty tady padají nejlíp.', 'Vyprodáno bývá týden předem. Když čtete tohle v den koncertu, volejte klub, nestrkejte se k pokladně naslepo.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Klub',
                        'paragraphs' => ['Peklo, 90 míst, od 250 Kč, 20:00. Vstup 18+.'],
                        'list' => ['Dveře 19:15', 'Místa nečíslovaná', 'Bez nahrávání'],
                    ],
                ],
            ],
            [
                'title' => 'Street food festival Plzeň',
                'image' => 'other',
                'photo' => $this->commons('Plzeň - Americká třída - View ESE - Eclectic architecture.jpg'),
                'category' => 'Kultura',
                'color' => 'orange',
                'starts' => $at('2026-08-15', '11:00'),
                'ends' => $at('2026-08-16', '22:00'),
                'time' => 'od 11:00',
                'place' => 'Plzeň – Americká / Škoda areál',
                'region' => 'Plzeň-město',
                'price' => 'zdarma',
                'capacity' => 'open air',
                'organizer' => 'Street Food Plzeň',
                'description' => 'Dva dny vozů s jídlem, pivo a hudba bez vstupenky na bráně.',
                'long' => $this->html([
                    ['p' => ['Street food festival v Plzni je víkend, kdy se z asfaltu u Americké stane řada vozů s jídlem, které v týdnu sháníte po městě zvlášť. Vstup volný, platíte jen jídlo a pití. Sobota 11:00–22:00, neděle 11:00–20:00. Hudba z malého pódia, nic, co by přebilo frontu na bao. Děti přes den, večer víc dospělých a delší fronty na pivo. Není to gastronomická konference — je to hlad, židle z plastu a vůně, která jde proti větru až k tramvaji.']],
                    ['h3' => 'Jídlo a provoz', 'p' => ['Dvacítka vozů, rotace mezi dny malá. Karty skoro všude, hotovost jako záloha, když spadne terminál. Porce festivalové, ceny taky. Voda z tanku zdarma. Alergeny na tabulích, ptejte se, než zaplatíte — v hluku se špatně reklamuje.', 'Odpad tříděný, kdo nechá tác na zemi, potká dobrovolníky s výmluvným pohledem. Psi ano, ale ne ke grilu.'], 'ul' => ['Sobota do 22:00, neděle do 20:00', 'Nejmenší fronty v sobotu 11:30 a v neděli 16:00', 'MHD, kolo, parkování v garagech v centru', 'Bezbariérový asfalt, bláto jen při dešti v trávě', 'Ztráty a nálezy u infostánku']],
                    ['h3' => 'Kdy jít', 'p' => ['Street Food Plzeň doporučuje neděli odpoledne, kdo nesnáší davy. Sobotní večer je o pití a o tom, že už jste jedli dvakrát. Když chcete ochutnat víc než dva vozy, sdílejte porce — žaludek má limit, Instagram ne.', 'Tohle je letní Plzeň bez vstupenky. Přijďte hladoví, ne na plno po obědě v restauraci.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Vstup volný',
                        'paragraphs' => ['Sobota 11–22, neděle 11–20. Platíte jídlo, ne bránu.'],
                        'list' => ['Karty skoro všude', 'Voda zdarma', 'Neděle klidnější'],
                    ],
                ],
            ],
            [
                'title' => 'Adventní trhy v Tachově',
                'image' => 'city',
                'photo' => $this->commons('Tachov náměstí Republiky č.p. 116 duben 2025.jpg'),
                'category' => 'Kultura',
                'color' => 'red',
                'starts' => $at('2026-12-04', '14:00'),
                'ends' => $at('2026-12-06', '20:00'),
                'time' => 'od 14:00',
                'place' => 'Tachov – náměstí Republiky',
                'region' => 'Tachov',
                'price' => 'zdarma',
                'capacity' => 'náměstí',
                'organizer' => 'Město Tachov',
                'description' => 'První adventní víkend: stromeček, trhy a svařák, který voní do postranních ulic.',
                'long' => $this->html([
                    ['p' => ['Adventní trhy v Tachově otevírají první prosincový víkend náměstí Republiky stánky, stromečkem a programem, který neskončí u reprodukované koledy z reproduktoru. Vstup volný. Pátek od 14:00 rozsvícení, sobota celý den, neděle do večera. Je to okresní advent: známé tváře, řemeslo z okolí, cukroví, které ještě někdo peče, a zima, která vás donutí ke svařáku dřív, než přiznáte, že mrznete.']],
                    ['h3' => 'Program', 'p' => ['Páteční rozsvícení v 17:00, sbor a krátký projev. Sobota jarmark od 10:00, odpoledne vystoupení škol. Neděle klidnější, koledy, dílny v knihovně. Stánky s punčem, klobásou, keramikou a věncemi. Ceny jsou městské, ne pražské, ale zadarmo není nic kromě vstupu na dlažbu.', 'Dětský vláček v sobotu, fronta jistá. Bruslení není — je náměstí, ne stadion.'], 'ul' => ['Pátek 14:00–21:00, sobota 10:00–21:00, neděle 10:00–20:00', 'Rozsvícení stromu pátek 17:00', 'Parkování mimo náměstí, pěší zóna rozšířená', 'Psi na vodítku, ohňostroj nebude', 'Program škol v sobotu od 14:00']],
                    ['h3' => 'Proč zůstat ve městě', 'p' => ['Město Tachov točí advent jako věc pro lidi, kteří jinak jedou za nákupem do Plzně. Když chcete první víkend v prosinci bez obchodního centra, náměstí stačí. Když chcete designový trh z metropole, jeďte jinam — tady je punč, zima a sousedé.', 'Přijďte v pátek na světla. Sobota je plnější, neděle je na klidný nákup věnců.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'První advent',
                        'paragraphs' => ['Náměstí Republiky, pátek až neděle, vstup volný. Rozsvícení v pátek v 17:00.'],
                        'list' => ['Sobota nejplnější', 'Parkování mimo centrum', 'Bez ohňostroje'],
                    ],
                ],
            ],
            [
                'title' => 'Výstava Klatovské karafiáty',
                'image' => 'other',
                'photo' => $this->commons('Gartennelke 1.jpg'),
                'category' => 'Kultura',
                'color' => 'pink',
                'starts' => $at('2026-07-25', '09:00'),
                'ends' => $at('2026-07-26', '17:00'),
                'time' => '9:00–17:00',
                'place' => 'Klatovy – sportovní hala',
                'region' => 'Klatovy',
                'price' => 'od 100 Kč',
                'capacity' => 'hala',
                'organizer' => 'Klatovské karafiáty z.s.',
                'description' => 'Dva dny květin, které město proslavily víc než ledacos jiného.',
                'long' => $this->html([
                    ['p' => ['Výstava Klatovské karafiáty plní sportovní halu na dva červencové dny barvou, která k městu patří víc než turistický slogan. Vstup od 100 Kč, děti do 12 let zdarma. Sobota 9:00–17:00, neděle stejně. Nejde o zahradnické centrum s akciovou sazenicí — jde o soutěžní stoly, aranžmá, výpěstky pěstitelů a o to, že karafiát tady není květina z benzínky. Kdo Klatovy zná jen jako křižovatku na Šumavu, tady pochopí, proč má město v erbu něco jiného než hrad.']],
                    ['h3' => 'Co uvidíte', 'p' => ['Soutěžní kategorie, ukázkové záhony v kádích, aranžéři v neděli dopoledne naživo. Prodej sazenic až ke konci výstavy, ať se nic nevadí dřív. Foto bez blesku, nedotýkat se soutěžních stolů — hlídá se to slušně, ale jistě.', 'Kavárna v hale, fronta v sobotu v deset. Parkování u haly, MHD zastávka kousek.'], 'ul' => ['Sobota i neděle 9:00–17:00, pokladna do 16:30', 'Komentované prohlídky v 11:00 a 14:00', 'Dílna pro děti v neděli od 10:00', 'Prodej sazenic od nedělního poledne', 'Bezbariérový vstup hlavní branou']],
                    ['h3' => 'Proč jít, i když nekvetete', 'p' => ['Klatovské karafiáty z.s. drží výstavu jako důkaz, že region umí i jemnou věc, ne jen trail a pivo. Hodina v hale stačí. Kdo pěstuje, zůstane tři. Kdo chce dárek, počká na sazenice v neděli — v sobotu ještě visí tabulka „nesahat“.', 'V červenci je hala teplá. Voda s sebou, ne jen káva.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Hala',
                        'paragraphs' => ['Sportovní hala Klatovy, so–ne 9–17. Vstup od 100 Kč, děti do 12 zdarma.'],
                        'list' => ['Komentované prohlídky 11 a 14', 'Sazenice v neděli', 'Bez blesku'],
                    ],
                ],
            ],
            [
                'title' => 'Beach volejbal Lochotín',
                'image' => 'sport',
                'photo' => $this->commons('2019-06-01 BeachVolleyball, Die Techniker beach tour Nürnberg StP 1609 LR10 by Stepro.jpg'),
                'category' => 'Sport',
                'color' => 'green',
                'starts' => $at('2026-07-04', '09:00'),
                'ends' => $at('2026-07-05', '18:00'),
                'time' => 'od 9:00',
                'place' => 'Plzeň – Lochotín, beach kurty',
                'region' => 'Plzeň-město',
                'price' => 'od 400 Kč / tým',
                'capacity' => '32 týmů',
                'organizer' => 'Beach Plzeň',
                'description' => 'Víkendový turnaj na písku: hobby kategorií víc než elitní exhibice.',
                'long' => $this->html([
                    ['p' => ['Beach volejbal na Lochotíně je víkendový turnaj, kam se hlásí týmy z Plzně, Klatov i Domažlic, které hrají po práci, ne na okruhu. Startovné od 400 Kč na tým, 32 míst v hobby, zvlášť mix a zvlášť muži/ženy podle naplnění. Sobota skupiny, neděle pavouk. Kurty u bazénu, stín malý, krém na slunce nutný. Diváci zdarma na trávě, občerstvení z kiosku.']],
                    ['h3' => 'Herní den', 'p' => ['Prezentace v sobotu 8:15, první míč 9:00. Hraje se na zkrácené sety, ať se 32 týmů vejde do světla. Protesty řeší hlavní rozhodčí, ne skupina u piva. Přihlášky do středy před turnajem, náhradníci ze seznamu.', 'Sprchy v areálu bazénu, platba zvlášť pokud nejste hráč. Parkování omezené, MHD na Lochotín v létě dává smysl.'], 'ul' => ['Hobby 32 týmů, startovné na tým ne na osobu', 'Mix preferovaný, čisté kategorie při naplnění', 'Míč turnajový, vlastní jen na rozehrání', 'Pití v kiosku, vlastní voda v týmovém stanu ano', 'Při bouřce přerušení, neděle se hraje do dohrání']],
                    ['h3' => 'Proč se hlásit', 'p' => ['Beach Plzeň točí léta, úroveň je slušná hobby, ne hanba pro okresní dvojku. Když chcete exhibici ligových jmen, jeďte jinam. Když chcete víkend na písku s lidmi, které potkáte v hale v zimě, hlaste tým včas.', 'Diváci: neděle odpoledne semifinále. Sobota je o počtech a o slunci.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Turnaj',
                        'paragraphs' => ['Kurty Lochotín, so–ne. 400 Kč / tým, 32 míst hobby.'],
                        'list' => ['Prezentace 8:15', 'Diváci zdarma', 'Přihlášky do středy'],
                    ],
                ],
            ],
            [
                'title' => 'Řemeslný jarmark v Plané',
                'image' => 'other',
                'photo' => $this->commons('Planá, náměstí Svobody (8).jpg'),
                'category' => 'Kultura',
                'color' => 'yellow',
                'starts' => $at('2026-09-05', '09:00'),
                'ends' => $at('2026-09-05', '16:00'),
                'time' => '9:00–16:00',
                'place' => 'Planá – náměstí',
                'region' => 'Tachov',
                'price' => 'zdarma',
                'capacity' => 'náměstí',
                'organizer' => 'Město Planá',
                'description' => 'Jedna sobota řemesla na tachovském konci kraje — bez velkého pódia.',
                'long' => $this->html([
                    ['p' => ['Řemeslný jarmark v Plané je sobota 9:00–16:00 na náměstí, kde se vejde trh, aniž by zmizelo město. Vstup volný. Stánky s dřevem, kůží, medem, pečivem a věcmi, které se v Tachově i v Plzni shánějí hůř, protože Planá není na hlavní turistické trase. Právě proto sem má smysl jet: menší fronty, řeč s člověkem, který věc dělal, a oběd v restauraci, která v ten den vaří pro jarmark, ne pro náhodu.']],
                    ['h3' => 'Co čekat', 'p' => ['Zhruba třicet stánků, malé pódium s dechovkou a školním sborem, nic, co by přebilo trh. Dětský koutek u radnice, malování na obličej do jedné. Parkování v postranních ulicích, náměstí uzavřené.', 'Hotovost jistější. Káva z okénka, fronta krátká. Psi na vodítku.'], 'ul' => ['Sobota 9:00–16:00, stánky se balí od 15:30', 'Nejlepší výběr do 11:00', 'Autobus z Tachova dopoledne, zpáteční odpoledne ověřte', 'Dešťová varianta podél podloubí, neruší se', 'Infostánek města u kašny']],
                    ['h3' => 'Proč Planá', 'p' => ['Město Planá drží jarmark jako věc pro vlastní lidi i pro ty z hranice. Když objíždíte jen Domažlice a Klatovy, Tachovsko vám zmizí z mapy. Jedna sobota to spraví, a ještě si odvezete prkénko, ne jen leták.', 'Když chcete velký festival, jeďte jinam. Když chcete koupit nůž od člověka, který ho koval, zůstaňte do oběda.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Sobota v Plané',
                        'paragraphs' => ['Náměstí 9:00–16:00, vstup volný. Zhruba 30 stánků.'],
                        'list' => ['Hotovost', 'Do 11:00 nejlepší výběr', 'Déšť = podloubí'],
                    ],
                ],
            ],
            [
                'title' => 'Cyklovýlet Chodskem',
                'image' => 'bike',
                'photo' => $this->commons('Klenčí pod Čerchovem červen 2022 (1).jpg'),
                'category' => 'Ostatní',
                'color' => 'green',
                'starts' => $at('2026-06-21', '08:30'),
                'time' => '8:30',
                'place' => 'Domažlice – sraz u Chodského hradu',
                'region' => 'Domažlice',
                'price' => 'od 150 Kč',
                'capacity' => 'skupina 30',
                'organizer' => 'Cyklo Chodsko',
                'description' => 'Mírný okruh vesnicemi, občerstvení v polovině a návrat k hradu.',
                'long' => $this->html([
                    ['p' => ['Cyklovýlet Chodskem startuje v 8:30 u Chodského hradu a vede okruh zhruba 45 km po silnicích a cyklostezkách, které znají místní, ne jen Strava segmenty. Tempo společné, ne závodní — kdo chce letět, ať jede sám v jiný den. Vložné 150 Kč platí značení, doprovodné auto s vodou a rezervaci stolu na oběd v Draženově. Kapacita 30 kol, e-biky vítány, silniční kola spíš ne — povrch není jen asfalt.']],
                    ['h3' => 'Trasa', 'p' => ['Domažlice – Mrákov – Klenčí – Draženov a zpět. Dva kratší kopce, jeden sjezd, kde se vyplatí brzdit. Oběd v půli, kdo nechce jíst, čeká s nápojem. Návrat k hradu kolem 14:30. Přilba povinná, reflex na zádech vítán.', 'Sraz 8:15, odjezd 8:30. Kdo přijde v 8:40, jede stopou, ne se skupinou. Doprovodné auto není taxi pro unavené — je to voda a defekt.'], 'ul' => ['45 km, mírné tempo, pauza na oběd', 'Přihláška do pátku, seznam náhradníků', 'Vlastní kolo seřízené, servis v terénu jen na duši', 'Děti jen se zdatným doprovodem a vlastním tempem skupiny', 'Při dešti se jede v pláštěnkách, neruší se do bouřky']],
                    ['h3' => 'Pro koho', 'p' => ['Cyklo Chodsko točí výlety pro lidi, kteří chtějí kraj vidět z kola, ne z parkoviště u hospody. Kdo závodí, bude se nudit. Kdo nasedá jednou za měsíc, 45 km v červnu dá, když nejede na hlad.', 'Po návratu kolo k hradu, pivo na náměstí, den uzavřený. Přesně tak má vypadat neděle na Domažlicku.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Sraz',
                        'paragraphs' => ['Chodský hrad 8:15, odjezd 8:30. 150 Kč, max. 30 kol, 45 km.'],
                        'list' => ['Přilba povinná', 'Oběd v Draženově', 'E-biky ano'],
                    ],
                ],
            ],
            [
                'title' => 'Letní kino ve Starém Plzenci',
                'image' => 'theatre',
                'photo' => $this->commons('Plzeň - Náměstí Republiky - Panorama View 360° 16.jpg'),
                'category' => 'Kultura',
                'color' => 'pink',
                'starts' => $at('2026-07-18', '21:00'),
                'time' => '21:00',
                'place' => 'Starý Plzenec – nádvoří pod hradem Radyně',
                'region' => 'Plzeň-jih',
                'price' => 'od 120 Kč',
                'capacity' => 'nádvoří · 250 míst',
                'organizer' => 'Letní kino Plzeň-jih',
                'description' => 'Jedna sobota pod Radyní: film, deka a výhled, kvůli kterému má smysl vyjet z města.',
                'long' => $this->html([
                    ['p' => ['Letní kino ve Starém Plzenci není projekce na parkovišti hypermarketu. Plátno stojí na nádvoří pod hradem Radyně, začátek v 21:00, až slunce klesne za kopec a zdi drží teplo dne. Vstup od 120 Kč, deka vlastní, židle pár kousků u zdi pro ty, kdo už na zemi nesedí. Film oznamujeme týden předem — většinou česká novinka nebo osvědčený letní titul, ne festivalová soutěž. Přijíždějí lidé z Dobřan, Přeštic i z Plzně, kteří chtějí jeden večer mimo centrum, aniž by jeli na Šumavu.']],
                    ['h3' => 'Průběh večera', 'p' => ['Brány v 20:00, občerstvení z vozíku, limonáda a pivo, žádný velký catering. Kdo chce jíst pořádně, stihne hospodu ve městě před osmou. Po filmu není afterparty — jen cesta dolů a ticho, kvůli kterému sem lidé jezdí. Parkování značeno od silnice na Spálené Poříčí; v sobotu po dvacáté raději pěšky z náměstí, auto nahoře mizí rychle.', 'Děti vítány, dokud zvládnou ticho po začátku. Psi na vodítku mimo hlediště. Při vytrvalém dešti přesun do sálu školy, stejné lístky, stejný film, horší výhled na hrad.'], 'ul' => ['Sobota 18. 7., brány 20:00, film 21:00', 'Vstup od 120 Kč, předprodej i na místě, dokud je místo', 'Deka, vrstvy, čelovka na cestu dolů', 'Občerstvení z vozíku, hotovost jistější', 'Déšť = škola ve Starém Plzenci, neruší se']],
                    ['h3' => 'Proč jih, ne Lochotín', 'p' => ['Plzeň-jih má vlastní večery, které se ve městě ztratí. Letní kino drží spolek, ne distributor. Když chcete amfiteátr a dav, jeďte na Lochotín. Když chcete film pod zdí, odkud je vidět krajina k Přešticím, zůstaňte tady.', 'Lístky mizí v týdnu, kdy padne název filmu. Kdo čte tohle v den projekce, volejte pořadateli, nestrkejte se k bráně naslepo.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Večer pod Radyní',
                        'paragraphs' => ['Starý Plzenec, sobota 21:00. Vstup od 120 Kč, deka vlastní.'],
                        'list' => ['Brány 20:00', 'Při dešti sál školy', 'Parkování mizí po 20:00'],
                    ],
                ],
            ],
            [
                'title' => 'Trail Třemošná',
                'image' => 'sport',
                'photo' => $this->commons('Šumava lesy.JPG'),
                'category' => 'Sport',
                'color' => 'green',
                'starts' => $at('2026-09-13', '09:00'),
                'time' => '9:00',
                'place' => 'Třemošná – start u sportovní haly',
                'region' => 'Plzeň-sever',
                'price' => 'od 350 Kč',
                'capacity' => '800 běžců',
                'organizer' => 'Běháme sever',
                'description' => 'Lesní okruh za Třemošnou: měřený trail, ne silnice kolem supermarketu.',
                'long' => $this->html([
                    ['p' => ['Trail Třemošná startuje v 9:00 u sportovní haly a vede do lesů, kvůli kterým má Plzeň-sever na mapě smysl i mimo průmyslový pruh. Dvě trasy: 12 km a 21 km, měřené, limit 3 a 4,5 hodiny. Startovné od 350 Kč zahrnuje číslo, čip, občerstvení a medaili, která není z plastu supermarketu. Terén kořeny, jehličí, dva kratší sjezdy, kde se vyplatí dívat pod nohy. Není to silniční desítka a není to Šumava — je to okres, který umí běžet, aniž byste museli na Božkov nebo na Lochotín.']],
                    ['h3' => 'Den závodu', 'p' => ['Prezentace od 7:30, vlny podle trasy. Trasa 12 km je pro lidi, kteří chtějí trail zkusit. 21 km je pro ty, kdo už ví, že kořen bolí víc než asfalt. Občerstvení na trati voda a iont, na cíli i slané. Sprchy v hale, parkování u školy, MHD z Plzně na Třemošnou stíhá prezentaci, když jedete osmý spoj, ne desátý.', 'Děti mají doprovodný běh 1 km v 11:30, startovné 50 Kč. Psi na trati závodu ne, na doprovodném ano na vodítku.'], 'ul' => ['Start 9:00, prezentace 7:30–8:40', '12 km a 21 km, GPS stopa týden předem', 'Přihlášky do středy, na místě jen vratky a náhradníci', 'Vlastní hůlky povoleny, sluchátka na obou uších ne', 'Při bouřce odklad vln, neruší se do odvolání']],
                    ['h3' => 'Proč Třemošná', 'p' => ['Běháme sever točí závod pro lidi z Kaznějova, Horní Břízy a z města, kteří nechtějí další okruh v Borském parku. Kdo honí osobák na silnici, bude se zlobit na kořeny. Kdo chce neděli v lese s časem na čipu, hlaste se včas.', 'Kapacita 800. Když zbývá týden a volná místa, berte 12 km — jednadvacítka mizí dřív.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Start',
                        'paragraphs' => ['Třemošná, sportovní hala, neděle 9:00. Od 350 Kč, 12 nebo 21 km.'],
                        'list' => ['Prezentace od 7:30', 'Dětský běh 11:30', 'Sprchy v hale'],
                    ],
                ],
            ],
            [
                'title' => 'Rokycanské náměstí žije',
                'image' => 'other',
                'photo' => $this->commons('Klatovy Černá věž (3).jpg'),
                'category' => 'Kultura',
                'color' => 'yellow',
                'starts' => $at('2026-06-13', '14:00'),
                'ends' => $at('2026-06-13', '22:00'),
                'time' => 'od 14:00',
                'place' => 'Rokycany – Masarykovo náměstí',
                'region' => 'Rokycany',
                'price' => 'zdarma',
                'capacity' => 'náměstí',
                'organizer' => 'Město Rokycany',
                'description' => 'Sobota na náměstí: jarmark, pódium a večer, který nenechá okres jen jako zastávku na D5.',
                'long' => $this->html([
                    ['p' => ['Rokycanské náměstí žije je sobota, kdy Masarykovo náměstí nepatří autům, ale stánkům, pódiu a lidem, kteří jinak městem jen projedou po D5. Od 14:00 jarmark a dílny, od 17:00 hlavní program, konec kolem desáté. Vstup volný. Pořadatel je město, ne agentura s přenosným plotem. Přijíždějí sousedé z Holoubkova, Mirošova i z Plzně, kteří chtějí vidět, že Rokycany mají vlastní sobotu, ne jen nádraží a sjezd.']],
                    ['h3' => 'Program', 'p' => ['Odpoledne patří řemeslu, dětským dílnám u radnice a ochutnávkám z okresu. Večer pódium: regionální kapela, taneční soubor a jeden host, kterého oznamujeme měsíc předem. Občerstvení ze stánků, restaurace kolem náměstí jedou naplno — rezervace dávají smysl, kdo chce sedět, ne stát s kelímkem.', 'Parkování na okraji centra, pěšky pět minut. MHD z Plzně na Rokycany je v sobotu slušná, zpáteční po desáté ověřte, ať nestojíte na perónu s posledním spojem v kapse jen jako přání.'], 'ul' => ['Sobota 13. 6., 14:00–22:00, vstup volný', 'Jarmark do 18:00, pódium do 22:00', 'Dílny bez rezervace, materiál do vyčerpání', 'Psi na vodítku, kola k stojanům u pošty', 'Déšť program zkracuje, neruší, pódium má střechu']],
                    ['h3' => 'Proč zůstat v okrese', 'p' => ['Rokycany na KdeAco chyběly, protože je lidé berou jako průjezd. Tahle sobota má tu mezeru spravit. Když chcete velký festival, jeďte do Plzně. Když chcete náměstí, kde potkáte známé tváře a ještě stihnete vlak, zůstaňte tady.', 'Program zveřejňujeme dva týdny předem. Kdo chce jen jarmark, stačí odpoledne. Kdo chce pódium, ať počítá s tím, že stání je volné, židle nejsou.']],
                ]),
                'sections' => [
                    [
                        'heading' => 'Sobota v Rokycanech',
                        'paragraphs' => ['Masarykovo náměstí, 14:00–22:00, vstup volný.'],
                        'list' => ['Jarmark do 18:00', 'Pódium do 22:00', 'Parkování na okraji'],
                    ],
                ],
            ],
        ];
    }
}
