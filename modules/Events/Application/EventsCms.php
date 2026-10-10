<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\Cms\Domain\Page;
use Modules\Cms\Domain\PageBlock;
use Modules\Cms\Domain\SiteMenu;
use Modules\Cms\Domain\SiteMenuItem;

/** Seeds KdeAco CMS pages + header/footer menus when the storefront has none. */
final class EventsCms
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
    ) {
    }

    public function seedIfEmpty(): void
    {
        if (!class_exists(Page::class) || !class_exists(SiteMenu::class)) {
            return;
        }
        $this->seedPages();
        $this->seedMenus();
    }

    private function seedPages(): void
    {
        $repo = $this->em->getRepository(Page::class);
        $revision = (int) $this->settings->getLive('events.legal_docs_revision', 0);
        $refreshLegal = $revision < LegalDocs::REVISION;

        foreach ($this->pages() as $row) {
            $existing = $repo->findOneBy(['slug' => $row['slug']]);
            $legal = in_array($row['slug'], ['cookies', 'ochrana-osobnich-udaju', 'obchodni-podminky'], true);
            if ($existing instanceof Page) {
                if ($legal && $refreshLegal) {
                    $existing->setTitle($row['title']);
                    $existing->setStatus('published');
                    $existing->setMetaDescription($row['meta']);
                    $this->replaceBlocks($existing, $row['blocks']);
                }
                continue;
            }
            $page = new Page($row['slug'], $row['title']);
            $page->setStatus('published');
            $page->setMetaDescription($row['meta']);
            $this->em->persist($page);
            $this->em->flush();
            foreach ($row['blocks'] as $i => $block) {
                $this->em->persist(new PageBlock($page->id(), $block[0], $i, $block[1]));
            }
            $this->em->flush();
        }

        if ($refreshLegal) {
            $this->settings->setLive('events.legal_docs_revision', LegalDocs::REVISION, SettingRecord::TYPE_INTEGER, 'events');
        }
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $blocks */
    private function replaceBlocks(Page $page, array $blocks): void
    {
        foreach ($this->em->getRepository(PageBlock::class)->findBy(['pageId' => $page->id()]) as $block) {
            $this->em->remove($block);
        }
        $this->em->flush();
        foreach ($blocks as $i => $block) {
            $this->em->persist(new PageBlock($page->id(), $block[0], $i, $block[1]));
        }
        $this->em->flush();
    }

    private function seedMenus(): void
    {
        $pageRepo = $this->em->getRepository(Page::class);
        $menuRepo = $this->em->getRepository(SiteMenu::class);
        $about = $pageRepo->findOneBy(['slug' => 'o-nas']);
        $privacy = $pageRepo->findOneBy(['slug' => 'ochrana-osobnich-udaju']);
        $terms = $pageRepo->findOneBy(['slug' => 'obchodni-podminky']);
        $cookies = $pageRepo->findOneBy(['slug' => 'cookies']);

        $header = $menuRepo->findOneBy(['location' => 'header']);
        if ($header === null) {
            $header = new SiteMenu('Hlavní menu', 'header');
            $this->em->persist($header);
            $this->em->flush();
        }

        $footer = $menuRepo->findOneBy(['location' => 'footer']);
        if ($footer === null) {
            $footer = new SiteMenu('Patička', 'footer');
            $this->em->persist($footer);
            $this->em->flush();
            $i = 0;
            if ($about instanceof Page) {
                $this->addPageItem($footer->id(), 'O nás', $about->id(), $i++);
            }
            $this->addUrlItem($footer->id(), 'Kontakt', '/kontakt', $i++);
            if ($terms instanceof Page) {
                $this->addPageItem($footer->id(), 'Obchodní podmínky', $terms->id(), $i++);
            }
            if ($privacy instanceof Page) {
                $this->addPageItem($footer->id(), 'Ochrana osobních údajů', $privacy->id(), $i++);
            }
            if ($cookies instanceof Page) {
                $this->addPageItem($footer->id(), 'Zásady cookies', $cookies->id(), $i);
            }
            $this->em->flush();

            return;
        }

        $this->ensureFooterLegal($footer->id(), $privacy, $terms, $cookies);
    }

    private function ensureFooterLegal(string $menuId, ?Page $privacy, ?Page $terms, ?Page $cookies): void
    {
        $items = $this->em->getRepository(SiteMenuItem::class)->findBy(['menuId' => $menuId]);
        $pageIds = [];
        $urls = [];
        $max = -1;
        foreach ($items as $item) {
            $max = max($max, $item->sortOrder());
            if ($item->pageId()) {
                $pageIds[$item->pageId()] = true;
            }
            if ($item->url()) {
                $urls[rtrim((string) $item->url(), '/')] = true;
            }
        }
        $order = $max + 1;
        if ($terms instanceof Page && !isset($pageIds[$terms->id()]) && !isset($urls['/stranka/obchodni-podminky'])) {
            $this->addPageItem($menuId, 'Obchodní podmínky', $terms->id(), $order++);
        }
        if ($privacy instanceof Page && !isset($pageIds[$privacy->id()])) {
            $this->addPageItem($menuId, 'Ochrana osobních údajů', $privacy->id(), $order++);
        }
        if ($cookies instanceof Page && !isset($pageIds[$cookies->id()]) && !isset($urls['/stranka/cookies'])) {
            $this->addPageItem($menuId, 'Zásady cookies', $cookies->id(), $order);
        }
        $this->em->flush();
    }

    private function addPageItem(string $menuId, string $label, string $pageId, int $order): void
    {
        $item = new SiteMenuItem($menuId, $label, $order);
        $item->setPageId($pageId);
        $this->em->persist($item);
    }

    private function addUrlItem(string $menuId, string $label, string $url, int $order): void
    {
        $item = new SiteMenuItem($menuId, $label, $order);
        $item->setUrl($url);
        $this->em->persist($item);
    }

    /** @return list<array{slug: string, title: string, meta: string, blocks: list<array{0: string, 1: array<string, mixed>}>}> */
    private function pages(): array
    {
        return [
            [
                'slug' => 'o-nas',
                'title' => 'O nás',
                'meta' => 'KdeAco je komunitní katalog akcí v Plzni a Plzeňském kraji. Srdce má v Domažlicích.',
                'blocks' => [
                    ['heading', ['text' => 'O KdeAco', 'level' => 1]],
                    ['text', ['body' => '<p>KdeAco je katalog akcí, které sem dali lidé. Zábava, sport, kultura, program pro děti i ostatní tipy — bez „někdy příště“.</p>']],
                    ['text', ['body' => '<p>Komunitní portál Plzeňského kraje. Srdce máme v Domažlicích, program sbíráme z celého kraje.</p>']],
                    ['heading', ['text' => 'Jak to funguje', 'level' => 2]],
                    ['html', ['html' => '<ul><li>Prohlížíte aktuální akce podle kraje, termínu a nálady.</li><li>Přihlášení návštěvníci si ukládají oblíbené a hlásí zájem.</li><li>Kdokoli může navrhnout akci — my ji zkontrolujeme a zveřejníme.</li></ul>']],
                    ['cta', ['label' => 'Napsat týmu', 'url' => '/kontakt', 'style' => 'primary']],
                ],
            ],
            [
                'slug' => 'cookies',
                'title' => 'Zásady cookies',
                'meta' => 'Cookies a podobné technologie na KdeAco: nezbytné prostředky, souhlas a jak ho změnit.',
                'blocks' => [
                    ['heading', ['text' => 'Zásady cookies', 'level' => 1]],
                    ['html', ['html' => LegalDocs::cookiesHtml()]],
                ],
            ],
            [
                'slug' => 'ochrana-osobnich-udaju',
                'title' => 'Ochrana osobních údajů',
                'meta' => 'Informace o zpracování osobních údajů na KdeAco podle čl. 13 a 14 GDPR.',
                'blocks' => [
                    ['heading', ['text' => 'Ochrana osobních údajů', 'level' => 1]],
                    ['html', ['html' => LegalDocs::privacyHtml()]],
                    ['cta', ['label' => 'Obchodní podmínky', 'url' => '/stranka/obchodni-podminky', 'style' => 'ghost']],
                    ['cta', ['label' => 'Zásady cookies', 'url' => '/stranka/cookies', 'style' => 'ghost']],
                    ['cta', ['label' => 'Nastavení cookies', 'url' => '#cookies', 'style' => 'ghost']],
                ],
            ],
            [
                'slug' => 'obchodni-podminky',
                'title' => 'Obchodní podmínky',
                'meta' => 'Obchodní podmínky KdeAco: užívání katalogu, licence k fotografiím, oprávnění a hlášení obsahu.',
                'blocks' => [
                    ['heading', ['text' => 'Obchodní podmínky', 'level' => 1]],
                    ['html', ['html' => LegalDocs::termsHtml()]],
                    ['cta', ['label' => 'Ochrana osobních údajů', 'url' => '/stranka/ochrana-osobnich-udaju', 'style' => 'ghost']],
                    ['cta', ['label' => 'Kontakt', 'url' => '/kontakt', 'style' => 'ghost']],
                ],
            ],
        ];
    }
}
