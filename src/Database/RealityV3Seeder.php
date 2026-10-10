<?php

declare(strict_types=1);

namespace Gereq\Database;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\PasswordHasher;
use Gereq\Module\InstalledModule;
use Gereq\Module\ModuleManager;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\User\Entity\Permission;
use Gereq\User\Entity\Role;
use Gereq\User\Entity\User;

/**
 * realityV3 demo profile: admin-client + broker users, RealEstates modules, emailing SMTP/IMAP.
 */
final class RealityV3Seeder
{
    public const ADMIN_EMAIL = 'rakad@realityv3.dev';
    public const ADMIN_PASSWORD = '5nAc,Ji43?5x';
    public const ADMIN_NAME = 'Anes Rakad';

    public const BROKER_EMAIL = 'rakad2@realityv3.dev';
    public const BROKER_PASSWORD = '1xYg,Ji22?5y';
    public const BROKER_NAME = 'Anes Rakad (makléř)';

    /** @var list<string> */
    public const MODULES = [
        'calendar',
        'cms',
        'crm',
        'currency',
        'customers',
        'documents',
        'media',
        'listings',
        'reviews',
        'webplus',
        'vitality',
        'portals',
        'viewings',
        'emailing',
    ];

    /** Modules that must stay off for this demo profile. */
    public const MODULES_OFF = [
        'newsletter',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasswordHasher $hasher,
        private readonly ModuleManager $modules,
        private readonly Settings $settings,
    ) {
    }

    public static function isActive(): bool
    {
        return strtolower((string) env_value('DEMO_PROFILE', '')) === 'realityv3';
    }

    /** @return list<string> */
    public function seed(): array
    {
        $log = [];

        $this->ensureAdminClientRole($log);
        $this->ensureBrokerRole($log);
        $this->enableModules($log);
        $this->disableUnwantedModules($log);
        $this->configureWebPlus($log);
        $admin = $this->ensureUser(
            self::ADMIN_EMAIL,
            (string) env_value('REALITYV3_ADMIN_NAME', self::ADMIN_NAME),
            (string) env_value('REALITYV3_ADMIN_PASSWORD', self::ADMIN_PASSWORD),
            'admin-client',
            $log,
        );
        $brokerUser = $this->ensureUser(
            self::BROKER_EMAIL,
            (string) env_value('REALITYV3_BROKER_NAME', self::BROKER_NAME),
            (string) env_value('REALITYV3_BROKER_PASSWORD', self::BROKER_PASSWORD),
            'broker',
            $log,
        );
        $this->linkBroker($brokerUser, $log);
        $this->configureEmailing($log);
        unset($admin);

        $this->em->flush();
        $log[] = 'realityV3: profil dokončen.';

        return $log;
    }

    /** @param list<string> $log */
    private function ensureAdminClientRole(array &$log): void
    {
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => 'admin-client']);
        if (!$role instanceof Role) {
            $role = new Role(
                'admin-client',
                'Admin klient',
                'Plný provozní přístup jako superadmin, bez zapínání a vypínání modulů.',
            );
            $this->em->persist($role);
            $this->em->flush();
            $log[] = 'Role admin-client vytvořena.';
        } else {
            $role->setLabel('Admin klient');
            $role->setDescription('Plný provozní přístup jako superadmin, bez zapínání a vypínání modulů.');
        }

        $all = $this->em->getRepository(Permission::class)->findAll();
        foreach ($all as $permission) {
            if (!str_starts_with($permission->name(), 'modules.')) {
                $role->givePermission($permission);
            }
        }
        $this->em->flush();
        $log[] = 'Role admin-client: oprávnění synchronizována (bez modules.*).';
    }

    /** @param list<string> $log */
    private function ensureBrokerRole(array &$log): void
    {
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => 'broker']);
        if (!$role instanceof Role) {
            $role = new Role(
                'broker',
                'Makléř',
                'Vlastní kalendář, prohlídky, nabídky, klienti, dokumenty a média — bez CRM/automatizace.',
            );
            $this->em->persist($role);
            $this->em->flush();
            $log[] = 'Role broker vytvořena.';
        } else {
            $role->setLabel('Makléř');
            $role->setDescription('Vlastní kalendář, prohlídky, nabídky, klienti, dokumenty a média — bez CRM/automatizace.');
        }

        $names = [
            'calendar.view',
            'calendar.manage',
            'viewings.view',
            'viewings.manage',
            'listings.view',
            'listings.manage',
            'customers.view',
            'documents.view',
            'documents.manage',
            'media.view',
            'media.manage',
        ];
        $permissions = [];
        foreach ($names as $name) {
            $permission = $this->em->getRepository(Permission::class)->findOneBy(['name' => $name]);
            if ($permission instanceof Permission) {
                $permissions[] = $permission;
            }
        }
        $role->syncPermissions(...$permissions);
        $this->em->flush();
        $log[] = 'Role broker: oprávnění synchronizována (' . count($permissions) . '/' . count($names) . ').';
    }

    /** @param list<string> $log */
    private function enableModules(array &$log): void
    {
        $enabled = 0;
        foreach ($this->modules->discover() as $manifest) {
            $should = in_array($manifest->id, self::MODULES, true);
            $row = $this->em->find(InstalledModule::class, $manifest->id);
            if ($row === null) {
                $this->em->persist(new InstalledModule($manifest->id, $manifest->version, $should));
                if ($should) {
                    ++$enabled;
                }
                continue;
            }
            if ($should && !$row->enabled()) {
                $row->enable();
                $row->setVersion($manifest->version);
                ++$enabled;
            } elseif (!$should && $row->enabled()) {
                // Keep non-listed modules as-is when already enabled by core Seeder;
                // realityV3 only guarantees the required set is on.
            }
        }
        $this->em->flush();
        $log[] = 'realityV3 moduly: zajištěno zapnutí ' . count(self::MODULES) . ' (nově/obnoveno: ' . $enabled . ').';
    }

    /** @param list<string> $log */
    private function disableUnwantedModules(array &$log): void
    {
        $result = $this->modules->pauseMany(self::MODULES_OFF);
        $log[] = sprintf(
            'realityV3 moduly vypnuty (%s): paused=%d%s',
            implode(', ', self::MODULES_OFF),
            (int) ($result['ok'] ?? 0),
            !empty($result['errors']) ? '; errors: ' . implode('; ', $result['errors']) : '',
        );
    }

    /** @param list<string> $log */
    private function configureWebPlus(array &$log): void
    {
        if (!class_exists(\Modules\WebPlus\Domain\WebMeta::class)) {
            return;
        }

        $fe = rtrim((string) env_value('REALITYV3_FE_URL', 'https://client.gereq.cz/realityV3/frontend'), '/');
        $apiOrigin = rtrim((string) env_value('APP_URL', 'https://client.gereq.cz/realityV3/admin'), '/');
        $publicOrigin = preg_replace('#/realityV3/admin$#', '', $apiOrigin);
        if (!is_string($publicOrigin) || $publicOrigin === '') {
            $publicOrigin = 'https://client.gereq.cz';
        }
        $sitemap = $publicOrigin . '/realityV3/api/public/webplus/sitemap.xml';
        $ogImage = 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?auto=format&fit=crop&w=1200&h=630&q=80';

        $pairs = [
            ['webplus.site_name', 'Anes Rakad · Realitní portál', SettingRecord::TYPE_STRING],
            ['webplus.base_url', $fe, SettingRecord::TYPE_STRING],
            ['webplus.frontend_url', $fe, SettingRecord::TYPE_STRING],
            ['webplus.default_og_image', $ogImage, SettingRecord::TYPE_STRING],
            ['webplus.default_robots', 'index,follow', SettingRecord::TYPE_STRING],
            ['webplus.default_og_locale', 'cs_CZ', SettingRecord::TYPE_STRING],
            ['webplus.cms_pages_path', '/stranka/{slug}', SettingRecord::TYPE_STRING],
            ['webplus.google_feed_enabled', true, SettingRecord::TYPE_BOOLEAN],
            ['webplus.google_feed_title', 'Anes Rakad — nabídky', SettingRecord::TYPE_STRING],
            ['webplus.google_feed_description', 'Aktuální nabídky nemovitostí', SettingRecord::TYPE_STRING],
            ['webplus.google_feed_currency', 'CZK', SettingRecord::TYPE_STRING],
            ['webplus.google_feed_brand', 'Anes Rakad', SettingRecord::TYPE_STRING],
            ['webplus.google_feed_listings_path', '/nabidky/{slug}', SettingRecord::TYPE_STRING],
            ['webplus.google_feed_sources', 'listings', SettingRecord::TYPE_STRING],
            ['webplus.robots_txt', "User-agent: *\nAllow: /\n\nSitemap: {$sitemap}\n", SettingRecord::TYPE_STRING],
            ['webplus.llms_txt', "# Anes Rakad · Realitní portál\n\n> Demo RealEstates + Web+ na platformě GEREQ.\n\n## Site\n- Frontend: {$fe}\n- Katalog: {$fe}/nabidky\n- Lokalita: Praha, Česká republika\n", SettingRecord::TYPE_STRING],
        ];

        foreach ($pairs as [$key, $value, $type]) {
            $this->settings->setLive($key, $value, $type, 'webplus');
        }

        $repo = $this->em->getRepository(\Modules\WebPlus\Domain\WebMeta::class);
        $ensure = function (
            string $path,
            string $title,
            string $desc,
            string $aiSummary,
            bool $sitemapInclude,
            string $prio,
            ?string $cmsPageId = null,
        ) use (
            $repo,
            $ogImage,
            $fe
): void {
            $meta = $repo->findOneBy(['path' => $path]);
            if (!$meta instanceof \Modules\WebPlus\Domain\WebMeta) {
                $meta = new \Modules\WebPlus\Domain\WebMeta($path);
                $this->em->persist($meta);
            }
            $meta->setActive(true);
            if ($cmsPageId !== null && $cmsPageId !== '') {
                $meta->setCmsPageId($cmsPageId);
            }
            $meta->setSeoTitle($title);
            $meta->setSeoDescription($desc);
            $meta->setOgTitle($title);
            $meta->setOgDescription($desc);
            $meta->setOgImageUrl($ogImage);
            $meta->setOgType('website');
            $meta->setOgUrl($fe . ($path === '/' ? '' : $path));
            $meta->setOgLocale('cs_CZ');
            $meta->setRobots('index,follow');
            $meta->setTwitterCard('summary_large_image');
            $meta->setGeoRegion('CZ-PR');
            $meta->setGeoPlacename('Praha');
            $meta->setGeoPosition('50.0755;14.4378');
            $meta->setAiSummary($aiSummary);
            $meta->setSitemapInclude($sitemapInclude);
            $meta->setSitemapPriority($prio);
            $meta->setSitemapChangefreq('weekly');
        };

        $cmsBySlug = [];
        if (class_exists(\Modules\Cms\Domain\Page::class)) {
            foreach ($this->em->getRepository(\Modules\Cms\Domain\Page::class)->findBy(['status' => 'published']) as $page) {
                if ($page instanceof \Modules\Cms\Domain\Page) {
                    $cmsBySlug[$page->slug()] = $page->id();
                }
            }
        }

        $ensure(
            '/',
            'Anes Rakad · Realitní portál',
            'Nabídky nemovitostí — prodej i pronájem v Praze a okolí. Demo RealEstates + Web+ na platformě GEREQ.',
            'Anes Rakad Realitní portál je demo realitní web na platformě GEREQ RealEstates. Nabízí katalog bytů, domů a pozemků k prodeji i pronájmu s důrazem na Prahu a Středočeský kraj.',
            true,
            '1.0',
            $cmsBySlug['home'] ?? null,
        );
        $ensure(
            '/nabidky',
            'Nabídky nemovitostí',
            'Katalog aktuálních nabídek — byty, domy, pozemky a komerční nemovitosti v Praze a okolí.',
            'Katalog nabídek Anes Rakad obsahuje aktuální byty, domy, pozemky a komerční nemovitosti. Filtrujte podle lokality, ceny a typu nabídky.',
            true,
            '0.9',
        );
        $ensure('/nabidky/{slug}', '{title}', '{description}', '{description}', false, '0.7');
        $ensure(
            '/o-nas',
            'O nás · Anes Rakad',
            'Poznejte realitní tým Anes Rakad — osobní přístup k prodeji a pronájmu nemovitostí v Praze.',
            'Anes Rakad je realitní makléř zaměřený na Prahu. Klientům pomáhá s prodejem, koupí i pronájmem bytů a domů s důrazem na transparentní komunikaci.',
            true,
            '0.8',
            $cmsBySlug['o-nas'] ?? null,
        );
        $ensure(
            '/kontakt',
            'Kontakt · Anes Rakad',
            'Spojte se s realitním týmem Anes Rakad — konzultace k prodeji, koupi i pronájmu nemovitostí v Praze.',
            'Kontaktujte Anes Rakad Realitní portál pro konzultaci nabídky, prohlídku nebo odhad ceny nemovitosti v Praze a okolí.',
            true,
            '0.8',
            $cmsBySlug['kontakt'] ?? null,
        );
        $ensure(
            '/ochrana-osobnich-udaju',
            'Ochrana osobních údajů',
            'Informace o zpracování osobních údajů na realitním portálu Anes Rakad v souladu s GDPR.',
            'Stránka ochrany osobních údajů vysvětluje, jak Anes Rakad Realitní portál zpracovává osobní data klientů a návštěvníků webu.',
            true,
            '0.3',
            $cmsBySlug['ochrana-osobnich-udaju'] ?? null,
        );
        $ensure(
            '/cookies',
            'Zásady cookies',
            'Jak realitní portál Anes Rakad používá cookies a podobné technologie na webu.',
            'Zásady cookies popisují technické, preferenční a analytické cookies používané na webu Anes Rakad Realitní portál.',
            true,
            '0.3',
            $cmsBySlug['cookies'] ?? null,
        );

        // Also expose Cms pages under /stranka/{slug} (FE routes) — without duplicate cms_page_id
        foreach (['o-nas', 'ochrana-osobnich-udaju', 'cookies', 'kontakt'] as $slug) {
            $sourcePath = '/' . $slug;
            $source = $repo->findOneBy(['path' => $sourcePath]);
            if (!$source instanceof \Modules\WebPlus\Domain\WebMeta) {
                continue;
            }
            $ensure(
                '/stranka/' . $slug,
                (string) $source->seoTitle(),
                (string) $source->seoDescription(),
                (string) $source->aiSummary(),
                false,
                '0.5',
                null,
            );
        }

        $this->em->flush();
        $log[] = 'Web+: frontend/base URL = ' . $fe . '; OG + GEO meta připraveny.';
    }

    /**
     * @param list<string> $log
     */
    private function ensureUser(string $email, string $name, string $password, string $roleName, array &$log): User
    {
        $email = strtolower($email);
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => $roleName]);

        if ($user === null) {
            $user = new User($name, $email, $this->hasher->hash($password));
            $user->setLocale('cs');
            if ($role instanceof Role) {
                $user->assignRole($role);
            }
            $this->em->persist($user);
            $log[] = "Uživatel {$email} vytvořen (role: {$roleName}).";
        } else {
            $user->setName($name);
            $user->setPasswordHash($this->hasher->hash($password));
            $user->setLocale('cs');
            if ($role instanceof Role && !$user->hasRole($roleName)) {
                $user->assignRole($role);
            }
            $log[] = "Uživatel {$email} aktualizován (role: {$roleName}).";
        }

        $this->em->flush();

        return $user;
    }

    /** @param list<string> $log */
    private function linkBroker(User $brokerUser, array &$log): void
    {
        if (!class_exists(\Modules\Listings\Domain\Broker::class)) {
            return;
        }

        $repo = $this->em->getRepository(\Modules\Listings\Domain\Broker::class);
        $email = 'anes.rakad@realityv3.dev';
        $broker = $repo->findOneBy(['email' => $email]);
        if (!$broker instanceof \Modules\Listings\Domain\Broker) {
            $broker = new \Modules\Listings\Domain\Broker('Anes', 'Rakad', $email);
            $this->em->persist($broker);
        }
        $broker->setPhone('+420 777 000 100');
        $broker->setPosition('Majitel / makléř');
        $broker->setSkills('Prodej a pronájem, Praha');
        $broker->setDescription('Demo makléř realityV3 — účet ' . self::BROKER_EMAIL . '.');
        $broker->setActive(true);
        $broker->setSortOrder(0);
        $broker->setUserId($brokerUser->id());
        $this->em->flush();
        $log[] = 'Makléř Anes Rakad propojen s uživatelem ' . self::BROKER_EMAIL . '.';
        $this->assignBrokerDemoPortfolio($broker, $brokerUser, $log);
    }

    /**
     * Give the demo makléř a real slice of seed data (listings, viewings, deals, calendar).
     *
     * @param list<string> $log
     */
    private function assignBrokerDemoPortfolio(
        \Modules\Listings\Domain\Broker $broker,
        User $brokerUser,
        array &$log,
    ): void {
        $brokerId = $broker->id();
        $movedListings = 0;
        $movedViewings = 0;
        $movedDeals = 0;
        $movedEvents = 0;

        if (class_exists(\Modules\Listings\Domain\Listing::class)) {
            /** @var list<\Modules\Listings\Domain\Listing> $listings */
            $listings = $this->em->getRepository(\Modules\Listings\Domain\Listing::class)
                ->createQueryBuilder('l')
                ->orderBy('l.createdAt', 'ASC')
                ->getQuery()
                ->getResult();

            $listingIdsWithViewings = [];
            if (class_exists(\Modules\Viewings\Domain\Viewing::class)) {
                foreach ($this->em->getRepository(\Modules\Viewings\Domain\Viewing::class)->findAll() as $viewing) {
                    $listingIdsWithViewings[$viewing->listingId()] = true;
                }
            }
            usort($listings, static function ($a, $b) use ($listingIdsWithViewings): int {
                $aw = isset($listingIdsWithViewings[$a->id()]) ? 0 : 1;
                $bw = isset($listingIdsWithViewings[$b->id()]) ? 0 : 1;

                return $aw <=> $bw;
            });

            $take = (int) max(8, (int) floor(count($listings) / 2));
            foreach (array_slice($listings, 0, $take) as $listing) {
                if ($listing->brokerId() === $brokerId) {
                    continue;
                }
                $listing->setBrokerId($brokerId);
                if (method_exists($listing, 'setBrokerName')) {
                    $listing->setBrokerName($broker->fullName());
                }
                ++$movedListings;
            }
            $this->em->flush();
        }

        if (class_exists(\Modules\Viewings\Domain\Viewing::class)) {
            $listingIds = [];
            if (class_exists(\Modules\Listings\Domain\Listing::class)) {
                foreach ($this->em->getRepository(\Modules\Listings\Domain\Listing::class)->findBy(['brokerId' => $brokerId]) as $l) {
                    $listingIds[$l->id()] = true;
                }
            }
            /** @var list<\Modules\Viewings\Domain\Viewing> $viewings */
            $viewings = $this->em->getRepository(\Modules\Viewings\Domain\Viewing::class)->findAll();
            foreach ($viewings as $viewing) {
                if (!isset($listingIds[$viewing->listingId()])) {
                    continue;
                }
                if ($viewing->brokerId() !== $brokerId) {
                    $viewing->setBrokerId($brokerId);
                    ++$movedViewings;
                }
            }
            $this->em->flush();
        }

        if (class_exists(\Modules\CRM\Domain\Deal::class)) {
            /** @var list<\Modules\CRM\Domain\Deal> $deals */
            $deals = $this->em->getRepository(\Modules\CRM\Domain\Deal::class)->findAll();
            foreach ($deals as $i => $deal) {
                if ($deal->brokerId() !== null && $deal->brokerId() !== '') {
                    continue;
                }
                // Assign every other unassigned deal to the demo broker.
                if ($i % 2 === 0) {
                    $deal->setBrokerId($brokerId);
                    ++$movedDeals;
                }
            }
            $this->em->flush();
        }

        if (class_exists(\Modules\Calendar\Domain\CalendarEvent::class)) {
            /** @var list<\Modules\Calendar\Domain\CalendarEvent> $events */
            $events = $this->em->getRepository(\Modules\Calendar\Domain\CalendarEvent::class)
                ->createQueryBuilder('e')
                ->orderBy('e.startsAt', 'ASC')
                ->getQuery()
                ->getResult();
            foreach ($events as $event) {
                if ($event->createdBy() === null || $event->createdBy() === '') {
                    $event->setCreatedBy($brokerUser->id());
                    ++$movedEvents;
                }
                if (class_exists(\Modules\Calendar\Domain\CalendarEventLink::class)) {
                    $existing = $this->em->getRepository(\Modules\Calendar\Domain\CalendarEventLink::class)->findOneBy([
                        'eventId' => $event->id(),
                        'entityType' => 'broker',
                        'entityId' => $brokerId,
                    ]);
                    if ($existing === null) {
                        $link = new \Modules\Calendar\Domain\CalendarEventLink($event->id(), 'broker', $brokerId);
                        $this->em->persist($link);
                    }
                }
            }
            $this->em->flush();
        }

        $log[] = sprintf(
            'Demo portfolio makléře: nabídky +%d, prohlídky +%d, dealy +%d, kalendář +%d.',
            $movedListings,
            $movedViewings,
            $movedDeals,
            $movedEvents,
        );
    }

    /** @param list<string> $log */
    private function configureEmailing(array &$log): void
    {
        if (!class_exists(\Modules\Emailing\Domain\MailAccount::class)) {
            return;
        }

        $smtpHost = trim((string) env_value('EMAILING_SMTP_HOST', ''));
        $imapHost = trim((string) env_value('EMAILING_IMAP_HOST', ''));
        if ($smtpHost === '' && $imapHost === '') {
            $log[] = 'Emailing: SMTP/IMAP env empty — keep demo account (set during install).';

            return;
        }

        $email = strtolower(trim((string) env_value('EMAILING_ACCOUNT_EMAIL', self::ADMIN_EMAIL)));
        $name = trim((string) env_value('EMAILING_ACCOUNT_NAME', 'Reality V3'));
        $smtpPort = (int) env_value('EMAILING_SMTP_PORT', 587);
        $smtpEnc = strtolower(trim((string) env_value('EMAILING_SMTP_ENCRYPTION', 'tls'))) ?: 'tls';
        $smtpUser = trim((string) env_value('EMAILING_SMTP_USERNAME', $email));
        $smtpPass = (string) env_value('EMAILING_SMTP_PASSWORD', '');
        $imapProto = strtolower(trim((string) env_value('EMAILING_IMAP_PROTOCOL', 'imap'))) ?: 'imap';
        $imapPort = (int) env_value('EMAILING_IMAP_PORT', 993);
        $imapEnc = strtolower(trim((string) env_value('EMAILING_IMAP_ENCRYPTION', 'ssl'))) ?: 'ssl';
        $imapUser = trim((string) env_value('EMAILING_IMAP_USERNAME', $email));
        $imapPass = (string) env_value('EMAILING_IMAP_PASSWORD', $smtpPass);

        $encSmtp = null;
        $encImap = null;
        if (class_exists(\Modules\Emailing\Application\CredentialVault::class)) {
            try {
                /** @var \Modules\Emailing\Application\CredentialVault $vault */
                $vault = gereq()->make(\Modules\Emailing\Application\CredentialVault::class);
                $encSmtp = $vault->encrypt($smtpPass);
                $encImap = $vault->encrypt($imapPass);
            } catch (\Throwable) {
                $encSmtp = null;
                $encImap = null;
            }
        }

        $repo = $this->em->getRepository(\Modules\Emailing\Domain\MailAccount::class);
        $account = $repo->findOneBy(['email' => $email]);
        if (!$account instanceof \Modules\Emailing\Domain\MailAccount) {
            $account = $repo->findOneBy(['isDefault' => true]);
        }
        if (!$account instanceof \Modules\Emailing\Domain\MailAccount) {
            $account = new \Modules\Emailing\Domain\MailAccount($name, $email);
            $this->em->persist($account);
        }

        foreach ($repo->findBy(['isDefault' => true]) as $other) {
            if ($other->id() !== $account->id()) {
                $other->setDefault(false);
            }
        }

        $account->setName($name);
        $account->setEmail($email);
        if ($smtpHost !== '') {
            $account->setSmtp($smtpHost, $smtpPort, $smtpEnc, $smtpUser, $encSmtp);
        }
        if ($imapHost !== '') {
            $account->setIncoming($imapProto, $imapHost, $imapPort, $imapEnc, $imapUser, $encImap);
        }
        $account->setDefault(true);
        $account->setActive(true);
        $safeEmail = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $account->setSignatureHtml(
            '<p>S pozdravem,<br><strong>Reality V3</strong><br>'
            . '<a href="mailto:' . $safeEmail . '">' . $safeEmail . '</a></p>'
        );

        $this->em->flush();
        $log[] = 'Emailing: account ' . $email . ' configured'
            . ' (SMTP=' . ($smtpHost !== '' ? $smtpHost : '—')
            . ', IMAP=' . ($imapHost !== '' ? $imapHost : '—') . ').';
    }
}
