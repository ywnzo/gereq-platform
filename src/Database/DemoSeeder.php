<?php

declare(strict_types=1);

namespace Gereq\Database;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\PasswordHasher;
use Gereq\Notification\DatabaseNotification;
use Gereq\User\Entity\Role;
use Gereq\User\Entity\User;

/**
 * Idempotent demo data for clicking through the admin UI.
 * Marker: product SKU GRQ-SVC-IMPL — when present, business data is not recreated.
 */
final class DemoSeeder
{
    public const DEMO_EMAIL = 'demo@gereq.cz';
    public const DEMO_PASSWORD = 'demo123';
    public const MARKER_SKU = 'GRQ-SVC-IMPL';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasswordHasher $hasher,
    ) {
    }

    /** @return list<string> */
    public function seed(): array
    {
        $log = [];

        $users = $this->seedUsers($log);
        $this->syncRolePermissions($log);
        $demo = $users['demo'];
        $manager = $users['manager'];
        $editor = $users['editor'];

        // Shipping/payment/coupon/newsletter catalog — always upsert (even with existing marker)
        $this->seedShipping($log);
        $this->seedPayments($log);
        $this->seedCoupons($log);
        $this->seedNewsletter($log);
        $this->seedLoyaltyFeedsBundles($log);
        // CMS one-page (home) — always refresh landing section content
        $this->ensureCmsHomeOnePage($log);
        // Real-estate CRM + listing → e-mail automation — always upsert
        $automation = new RealEstateAutomationDemoSeeder($this->em);
        foreach ($automation->seed() as $line) {
            $log[] = $line;
        }

        if ($this->alreadySeeded()) {
            $this->seedReviewsAndReturnsFromExisting($log);
            $this->seedProductVariantsFromExisting($log);
            $log[] = 'Business demo data už existují (marker ' . self::MARKER_SKU . ') — přeskočeno.';
            $this->em->flush();

            return $log;
        }

        $customers = $this->seedCustomersEnrichment($demo, $log);
        $products = $this->seedCatalog($log);
        $warehouse = $this->seedWarehouse($products, $demo, $log);
        $deals = $this->seedCrm($customers, $log);
        $this->seedOrders($customers, $products, $log);
        $this->seedCarts($customers, $products, $log);
        $this->seedProjects($customers, $deals, $demo, $manager, $editor, $log);
        $this->seedCalendar($demo, $manager, $customers, $deals, $log);
        $this->seedDocumentsAndMedia($demo, $customers, $products, $log);
        $this->seedCms($products, $log);
        $this->seedNotifications($demo, $manager, $log);
        $this->seedReviews($customers, $products, $log);
        $this->seedReturns($customers, $products, $log);

        $this->em->flush();
        $log[] = 'Demo data připravena.';
        $log[] = sprintf('Přihlášení: %s / %s (role superadmin)', self::DEMO_EMAIL, self::DEMO_PASSWORD);

        return $log;
    }

    /** @param list<string> $log */
    private function syncRolePermissions(array &$log): void
    {
        $this->ensureRole('superadmin', 'Superadmin', 'Plný přístup ke všemu (včetně uživatelů a rolí).', ['administrator']);
        $this->ensureRole('admin', 'Admin', 'Plný přístup ke všemu (developer) — včetně modulů, rolí a systémových částí.', ['manager']);
        $this->ensureRole('admin-client', 'Admin klient', 'Plný provozní přístup jako superadmin, bez zapínání a vypínání modulů.', []);
        $this->ensureRole('editor', 'Editor', 'Úpravy obsahu bez správy uživatelů.', []);
        $this->ensureRole('view', 'View', 'Pouze čtení.', ['viewer']);
        $this->em->flush();

        $admin = $this->em->getRepository(Role::class)->findOneBy(['name' => 'admin']);
        $adminClient = $this->em->getRepository(Role::class)->findOneBy(['name' => 'admin-client']);
        $editorRole = $this->em->getRepository(Role::class)->findOneBy(['name' => 'editor']);
        $view = $this->em->getRepository(Role::class)->findOneBy(['name' => 'view']);
        $all = $this->em->getRepository(\Gereq\User\Entity\Permission::class)->findAll();

        // Admin is privileged (isPrivileged); permissions are still synced for UI/DB consistency.
        if ($admin instanceof Role) {
            foreach ($all as $permission) {
                $admin->givePermission($permission);
            }
        }
        if ($adminClient instanceof Role) {
            foreach ($all as $permission) {
                if (!str_starts_with($permission->name(), 'modules.')) {
                    $adminClient->givePermission($permission);
                }
            }
        }
        if ($editorRole instanceof Role) {
            foreach ($all as $permission) {
                if (
                    (str_ends_with($permission->name(), '.view')
                        || str_ends_with($permission->name(), '.edit')
                        || str_ends_with($permission->name(), '.create'))
                    && !str_starts_with($permission->name(), 'users.')
                    && !str_starts_with($permission->name(), 'modules.')
                    && !str_starts_with($permission->name(), 'roles.')
                    && !str_starts_with($permission->name(), 'settings.')
                ) {
                    $editorRole->givePermission($permission);
                }
            }
        }
        if ($view instanceof Role) {
            foreach ($all as $permission) {
                if (str_ends_with($permission->name(), '.view')) {
                    $view->givePermission($permission);
                }
            }
        }

        $this->em->flush();
        $log[] = 'Oprávnění rolí admin/admin-client/editor/view synchronizována.';
    }

    /** @param list<string> $legacyNames */
    private function ensureRole(string $name, string $label, string $description, array $legacyNames): void
    {
        $existing = $this->em->getRepository(Role::class)->findOneBy(['name' => $name]);
        if ($existing instanceof Role) {
            $existing->setLabel($label);
            $existing->setDescription($description);

            return;
        }

        foreach ($legacyNames as $legacy) {
            $old = $this->em->getRepository(Role::class)->findOneBy(['name' => $legacy]);
            if ($old instanceof Role) {
                $old->rename($name);
                $old->setLabel($label);
                $old->setDescription($description);

                return;
            }
        }

        $this->em->persist(new Role($name, $label, $description));
    }

    private function alreadySeeded(): bool
    {
        if (!class_exists(\Modules\Products\Domain\Product::class)) {
            return false;
        }

        return $this->em->getRepository(\Modules\Products\Domain\Product::class)
            ->findOneBy(['sku' => self::MARKER_SKU]) !== null;
    }

    /**
     * @param list<string> $log
     * @return array{demo: User, manager: User, editor: User, view: User, admin: ?User}
     */
    private function seedUsers(array &$log): array
    {
        $defs = [
            'demo' => [self::DEMO_EMAIL, 'Anna Demo', 'superadmin', 'cs'],
            'manager' => ['manazer@gereq.cz', 'Petr Manažer', 'admin', 'cs'],
            'editor' => ['editor@gereq.cz', 'Lucie Editorová', 'editor', 'cs'],
            'view' => ['view@gereq.cz', 'Jan View', 'view', 'cs'],
        ];

        $out = ['demo' => null, 'manager' => null, 'editor' => null, 'view' => null, 'admin' => null];

        foreach ($defs as $key => [$email, $name, $roleName, $locale]) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user === null) {
                $user = new User($name, $email, $this->hasher->hash(self::DEMO_PASSWORD));
                $role = $this->em->getRepository(Role::class)->findOneBy(['name' => $roleName]);
                if ($role instanceof Role) {
                    $user->assignRole($role);
                }
                $user->setLocale($locale);
                $this->em->persist($user);
                $log[] = "Uživatel {$email} vytvořen (heslo: " . self::DEMO_PASSWORD . ", role: {$roleName}).";
            } else {
                $user->setLocale($locale);
                $role = $this->em->getRepository(Role::class)->findOneBy(['name' => $roleName]);
                if ($role instanceof Role && !$user->hasRole($roleName)) {
                    $user->assignRole($role);
                    $log[] = "Uživateli {$email} doplněna role {$roleName}.";
                } else {
                    $log[] = "Uživatel {$email} už existuje.";
                }
            }
            $out[$key] = $user;
        }

        $admin = $this->em->getRepository(User::class)->findOneBy([
            'email' => strtolower((string) env_value('ADMIN_EMAIL', 'jiri.novak@gereq.cz')),
        ]);
        $out['admin'] = $admin instanceof User ? $admin : null;

        $this->em->flush();

        /** @var array{demo: User, manager: User, editor: User, view: User, admin: ?User} */
        return $out;
    }

    /**
     * @param list<string> $log
     * @return list<\Modules\Customers\Domain\Customer>
     */
    private function seedCustomersEnrichment(User $demo, array &$log): array
    {
        if (!class_exists(\Modules\Customers\Domain\Customer::class)) {
            return [];
        }

        $repo = $this->em->getRepository(\Modules\Customers\Domain\Customer::class);
        $extra = [
            ['Klára Benešová', 'klara.benesova@nordsoft.cz', '+420 777 880 112', 'NordSoft s.r.o.', 'Preferuje faktury v PDF e-mailem.', 'active'],
            ['Ondřej Malý', 'ondrej.maly@citycafe.cz', '+420 603 119 440', 'City Café Praha', 'Zájem o e-shop modul.', 'lead'],
            ['Helena Richter', 'helena.richter@logistik-cz.eu', '+420 725 661 903', 'Logistik CZ a.s.', 'Framework smlouva do 2027.', 'active'],
        ];

        foreach ($extra as [$name, $email, $phone, $company, $note, $status]) {
            if ($repo->findOneBy(['email' => $email]) !== null) {
                continue;
            }
            $c = new \Modules\Customers\Domain\Customer($name, $email);
            $c->setPhone($phone);
            $c->setCompany($company);
            $c->setNote($note);
            $c->setStatus($status);
            $this->em->persist($c);
        }
        $this->em->flush();

        /** @var list<\Modules\Customers\Domain\Customer> $customers */
        $customers = $repo->findBy([], ['name' => 'ASC']);

        $tagVip = null;
        $tagEshop = null;
        if (class_exists(\Modules\Customers\Domain\CustomerTag::class)) {
            $tagRepo = $this->em->getRepository(\Modules\Customers\Domain\CustomerTag::class);
            $tagVip = $tagRepo->findOneBy(['slug' => 'vip']) ?? new \Modules\Customers\Domain\CustomerTag('VIP', 'vip', '#0f766e');
            $tagEshop = $tagRepo->findOneBy(['slug' => 'eshop']) ?? new \Modules\Customers\Domain\CustomerTag('E-shop', 'eshop', '#1d4ed8');
            $this->em->persist($tagVip);
            $this->em->persist($tagEshop);
            $this->em->flush();
        }

        $addresses = [
            'petra.dvorakova@moravia.cz' => ['billing', 'Lidická 700/5', 'Brno', '60200', 'CZ'],
            'eva.kralova@plzensky-pivovar.cz' => ['shipping', 'U Prazdroje 64', 'Plzeň', '30100', 'CZ'],
            'klara.benesova@nordsoft.cz' => ['billing', 'Rohanské nábřeží 19', 'Praha', '18600', 'CZ'],
            'helena.richter@logistik-cz.eu' => ['billing', 'Průmyslová 12', 'Ostrava', '70200', 'CZ'],
        ];

        $contacts = [
            'petra.dvorakova@moravia.cz' => ['Jana Procházková', 'jana.prochazkova@moravia.cz', '+420 731 445 200', 'Nákup'],
            'helena.richter@logistik-cz.eu' => ['Martin Richter', 'martin.richter@logistik-cz.eu', '+420 725 661 900', 'IT ředitel'],
            'klara.benesova@nordsoft.cz' => ['Klára Benešová', 'klara.benesova@nordsoft.cz', '+420 777 880 112', 'Jednatelka'],
        ];

        foreach ($customers as $customer) {
            $email = strtolower($customer->email());
            if (isset($addresses[$email]) && class_exists(\Modules\Customers\Domain\CustomerAddress::class)) {
                $existing = $this->em->getRepository(\Modules\Customers\Domain\CustomerAddress::class)
                    ->findOneBy(['customerId' => $customer->id()]);
                if ($existing === null) {
                    [$type, $line1, $city, $zip, $country] = $addresses[$email];
                    $addr = new \Modules\Customers\Domain\CustomerAddress($customer->id(), $type, $line1, $city, $zip, $country);
                    $addr->setIsPrimary(true);
                    $this->em->persist($addr);
                }
            }
            if (isset($contacts[$email]) && class_exists(\Modules\Customers\Domain\CustomerContact::class)) {
                $existing = $this->em->getRepository(\Modules\Customers\Domain\CustomerContact::class)
                    ->findOneBy(['customerId' => $customer->id()]);
                if ($existing === null) {
                    [$cname, $cemail, $cphone, $crole] = $contacts[$email];
                    $contact = new \Modules\Customers\Domain\CustomerContact($customer->id(), $cname);
                    $contact->setEmail($cemail);
                    $contact->setPhone($cphone);
                    $contact->setRole($crole);
                    $contact->setIsPrimary(true);
                    $this->em->persist($contact);
                }
            }
            if (class_exists(\Modules\Customers\Domain\CustomerNote::class)) {
                $noteCount = $this->em->getRepository(\Modules\Customers\Domain\CustomerNote::class)
                    ->count(['customerId' => $customer->id()]);
                if ($noteCount === 0 && in_array($email, ['petra.dvorakova@moravia.cz', 'klara.benesova@nordsoft.cz'], true)) {
                    $this->em->persist(new \Modules\Customers\Domain\CustomerNote(
                        $customer->id(),
                        'Demo poznámka: domluven follow-up na příští týden ohledně nabídky.',
                        $demo->id(),
                    ));
                }
            }
            if ($tagVip !== null && $tagEshop !== null && class_exists(\Modules\Customers\Domain\CustomerTagLink::class)) {
                $linkRepo = $this->em->getRepository(\Modules\Customers\Domain\CustomerTagLink::class);
                if (str_contains($email, 'moravia') || str_contains($email, 'nordsoft')) {
                    if ($linkRepo->findOneBy(['customerId' => $customer->id(), 'tagId' => $tagVip->id()]) === null) {
                        $this->em->persist(new \Modules\Customers\Domain\CustomerTagLink($customer->id(), $tagVip->id()));
                    }
                }
                if (str_contains($email, 'citycafe') || str_contains($email, 'nordsoft')) {
                    if ($linkRepo->findOneBy(['customerId' => $customer->id(), 'tagId' => $tagEshop->id()]) === null) {
                        $this->em->persist(new \Modules\Customers\Domain\CustomerTagLink($customer->id(), $tagEshop->id()));
                    }
                }
            }
        }

        if (count($customers) >= 2 && class_exists(\Modules\Customers\Domain\CustomerRelation::class)) {
            $from = $customers[0];
            $to = $customers[1];
            $relRepo = $this->em->getRepository(\Modules\Customers\Domain\CustomerRelation::class);
            if ($relRepo->findOneBy(['fromCustomerId' => $from->id(), 'toCustomerId' => $to->id()]) === null) {
                $this->em->persist(new \Modules\Customers\Domain\CustomerRelation($from->id(), $to->id(), 'partner'));
            }
        }

        $this->em->flush();
        $log[] = 'Zákazníci: ' . count($customers) . ' (kontakty, adresy, tagy).';

        return $customers;
    }

    /**
     * @param list<string> $log
     * @return list<\Modules\Products\Domain\Product>
     */
    private function seedCatalog(array &$log): array
    {
        if (!class_exists(\Modules\Products\Domain\Product::class)) {
            return [];
        }

        $catDefs = [
            ['Služby', 'sluzby'],
            ['Licence', 'licence'],
            ['Hardware', 'hardware'],
        ];
        $categories = [];
        foreach ($catDefs as [$name, $slug]) {
            $cat = $this->em->getRepository(\Modules\Products\Domain\ProductCategory::class)->findOneBy(['slug' => $slug]);
            if ($cat === null) {
                $cat = new \Modules\Products\Domain\ProductCategory($name, $slug);
                $this->em->persist($cat);
            }
            $categories[$slug] = $cat;
        }
        $this->em->flush();

        $defs = [
            [self::MARKER_SKU, 'Implementace GEREQ Platform', 'Jednorázová implementace včetně školení.', 'hod', '89000', 'sluzby'],
            ['GRQ-SUP-M', 'Měsíční podpora Standard', 'SLA 8×5, e-mail a telefon.', 'měs', '4900', 'sluzby'],
            ['GRQ-LIC-PRO', 'Licence GEREQ Pro (rok)', 'Roční licence pro až 25 uživatelů.', 'ks', '36000', 'licence'],
            ['GRQ-SHOP-ADD', 'Modul E-shop (add-on)', 'Košík, doprava a checkout napojený na objednávky.', 'ks', '12000', 'licence'],
            ['GRQ-CMS-ADD', 'Modul CMS Builder', 'Stránky a block builder pro interní weby.', 'ks', '8500', 'licence'],
            ['GRQ-NB-14', 'Notebook konzultant 14"', 'Zapůjčení / prodej notebooku pro projektový tým.', 'ks', '24990', 'hardware'],
            ['GRQ-MON-27', 'Monitor 27" 4K', 'Kancelářský monitor pro demo stanici.', 'ks', '8990', 'hardware'],
            ['GRQ-TRAIN-1D', 'Školení uživatelů (1 den)', 'On-site nebo online školení administrátorů.', 'den', '18000', 'sluzby'],
        ];

        $products = [];
        foreach ($defs as [$sku, $name, $desc, $unit, $price, $catSlug]) {
            $product = new \Modules\Products\Domain\Product($sku, $name);
            $product->setDescription($desc);
            $product->setUnit($unit);
            $product->setStatus('active');
            $product->addCategory($categories[$catSlug]);
            $this->em->persist($product);
            $this->em->flush();
            $this->em->persist(new \Modules\Products\Domain\ProductPrice($product->id(), 'CZK', $price));
            if (in_array($sku, ['GRQ-LIC-PRO', 'GRQ-SHOP-ADD'], true)) {
                $this->em->persist(new \Modules\Products\Domain\ProductPrice($product->id(), 'EUR', $sku === 'GRQ-LIC-PRO' ? '1450' : '480'));
            }
            $products[] = $product;
        }

        // One draft product for UI states
        $draft = new \Modules\Products\Domain\Product('GRQ-DRAFT-AI', 'AI asistent (připravujeme)');
        $draft->setDescription('Interní draft — ještě neprodávat.');
        $draft->setStatus('draft');
        $this->em->persist($draft);

        $this->em->flush();
        $log[] = 'Katalog: ' . count($products) . ' aktivních produktů + 1 draft.';

        return $products;
    }

    /**
     * @param list<\Modules\Products\Domain\Product> $products
     * @param list<string> $log
     * @return \Modules\Warehouse\Domain\Warehouse|null
     */
    private function seedWarehouse(array $products, User $demo, array &$log): mixed
    {
        if (!class_exists(\Modules\Warehouse\Domain\Warehouse::class) || $products === []) {
            return null;
        }

        $main = new \Modules\Warehouse\Domain\Warehouse('PRG-MAIN', 'Sklad Praha – hlavní');
        $main->setActive(true);
        $this->em->persist($main);

        $brno = new \Modules\Warehouse\Domain\Warehouse('BRN-BUF', 'Sklad Brno – buffer');
        $brno->setActive(true);
        $this->em->persist($brno);
        $this->em->flush();

        $stockMap = [
            'GRQ-NB-14' => ['18', '4'],
            'GRQ-MON-27' => ['12', '2'],
            'GRQ-LIC-PRO' => ['999', '0'],
            'GRQ-SHOP-ADD' => ['999', '0'],
            'GRQ-CMS-ADD' => ['999', '0'],
            'GRQ-TRAIN-1D' => ['40', '0'],
            'GRQ-SUP-M' => ['200', '0'],
            self::MARKER_SKU => ['30', '0'],
        ];

        foreach ($products as $product) {
            $qty = $stockMap[$product->sku()] ?? null;
            if ($qty === null) {
                continue;
            }
            $this->em->persist(new \Modules\Warehouse\Domain\StockItem($main->id(), $product->id(), $qty[0]));
            if ((float) $qty[1] > 0) {
                $this->em->persist(new \Modules\Warehouse\Domain\StockItem($brno->id(), $product->id(), $qty[1]));
            }
            $mov = new \Modules\Warehouse\Domain\StockMovement($main->id(), $product->id(), 'in', $qty[0], $demo->id());
            $mov->setNote('Počáteční naskladnění demo');
            $this->em->persist($mov);
        }

        // Low stock item
        $low = null;
        foreach ($products as $p) {
            if ($p->sku() === 'GRQ-MON-27') {
                $low = $p;
                break;
            }
        }
        if ($low !== null) {
            $out = new \Modules\Warehouse\Domain\StockMovement($main->id(), $low->id(), 'out', '10', $demo->id());
            $out->setNote('Výdej na demo stanice');
            $this->em->persist($out);
            $item = $this->em->getRepository(\Modules\Warehouse\Domain\StockItem::class)
                ->findOneBy(['warehouseId' => $main->id(), 'productId' => $low->id()]);
            if ($item !== null) {
                $item->setQty('2'); // pod LOW_THRESHOLD
            }
        }

        $this->em->flush();
        $log[] = 'Sklady: PRG-MAIN, BRN-BUF + zásoby a pohyby.';

        return $main;
    }

    /**
     * @param list<\Modules\Customers\Domain\Customer> $customers
     * @param list<string> $log
     * @return list<\Modules\CRM\Domain\Deal>
     */
    private function seedCrm(array $customers, array &$log): array
    {
        if (!class_exists(\Modules\CRM\Domain\Deal::class) || $customers === []) {
            return [];
        }

        $byEmail = [];
        foreach ($customers as $c) {
            $byEmail[strtolower($c->email())] = $c;
        }

        $dealDefs = [
            ['petra.dvorakova@moravia.cz', 'Rozšíření GEREQ Pro + Shop', '245000', 'proposal', '+45 days'],
            ['klara.benesova@nordsoft.cz', 'Implementace + školení', '107000', 'qualified', '+20 days'],
            ['ondrej.maly@citycafe.cz', 'E-shop pro pobočky', '48000', 'lead', '+60 days'],
            ['helena.richter@logistik-cz.eu', 'Licence 2026 renewal', '36000', 'won', '-10 days'],
            ['eva.kralova@plzensky-pivovar.cz', 'Interní CMS portál', '29500', 'lost', '-5 days'],
        ];

        $deals = [];
        foreach ($dealDefs as [$email, $title, $amount, $stage, $close]) {
            $customer = $byEmail[$email] ?? $customers[0];
            $deal = new \Modules\CRM\Domain\Deal($customer->id(), $title);
            $deal->setAmount($amount);
            $deal->setCurrency('CZK');
            $deal->setStage($stage);
            $deal->setExpectedCloseAt(new \DateTimeImmutable($close));
            $deal->setNote('Demo obchodní případ.');
            $this->em->persist($deal);
            $deals[] = $deal;
        }
        $this->em->flush();

        $acts = [
            [$deals[0], 'call', 'Hovor s nákupem', 'Domluveno zaslání nabídky do pátku.', '+2 days', false],
            [$deals[0], 'email', 'Nabídka odeslána', 'PDF nabídka + ceník modulů.', '-1 day', true],
            [$deals[1], 'meeting', 'Kick-off schůzka', 'Scope implementace a termíny.', '+5 days', false],
            [$deals[2], 'task', 'Připravit demo e-shopu', 'Ukázat košík a checkout.', '+3 days', false],
            [$deals[3], 'note', 'Obnovení licence', 'Faktura uhrazena, aktivace OK.', '-8 days', true],
        ];

        foreach ($acts as [$deal, $type, $subject, $body, $due, $done]) {
            $activity = new \Modules\CRM\Domain\Activity($deal->customerId(), $subject, $type);
            $activity->setDealId($deal->id());
            $activity->setBody($body);
            $activity->setDueAt(new \DateTimeImmutable($due));
            if ($done) {
                $activity->complete();
            }
            $this->em->persist($activity);
        }

        $this->em->flush();
        $log[] = 'CRM: ' . count($deals) . ' obchodů + aktivity.';

        return $deals;
    }

    /** @param list<string> $log */
    private function seedShipping(array &$log): void
    {
        if (!class_exists(\Modules\Shop\Domain\ShippingMethod::class)) {
            return;
        }

        $repo = $this->em->getRepository(\Modules\Shop\Domain\ShippingMethod::class);
        /** @var list<array{0:string,1:string,2:string,3:?int,4:?string,5:string,6:string,7:int}> $defs */
        $defs = [
            ['PICKUP', 'Osobní odběr — showroom Praha', '0.00', 0, 'own', 'pickup', 'Vyzvednutí na adrese Vinohradská 12, Po–Pá 9–17.', 10],
            ['CP_DR', 'Česká pošta — doporučené psaní', '69.00', 3, 'ceska_posta', 'post', 'Do 2 kg, sledování zásilky.', 20],
            ['CP_BALIK', 'Česká pošta — Balík Do ruky', '119.00', 2, 'ceska_posta', 'post', 'Doručení na adresu, pojištění do 50 000 Kč.', 21],
            ['PPL', 'PPL Parcel CZ', '129.00', 2, 'ppl', 'courier', 'Doručení na adresu v pracovní dny 8–18.', 30],
            ['PPL_PARCELSHOP', 'PPL ParcelShop', '99.00', 2, 'ppl', 'parcel_shop', 'Výdej na více než 3000 místech v ČR.', 31],
            ['DPD', 'DPD Classic', '139.00', 2, 'dpd', 'courier', 'Doručení kurýrem DPD, SMS avízo.', 40],
            ['DPD_PICKUP', 'DPD Pickup', '99.00', 2, 'dpd', 'parcel_shop', 'Výdejní místa DPD Pickup Point.', 41],
            ['ZASILKOVNA', 'Zásilkovna', '79.00', 2, 'packeta', 'parcel_shop', 'Výdejní místa Zásilkovna / Packeta CZ.', 50],
            ['GLS', 'GLS BusinessParcel', '135.00', 2, 'gls', 'courier', 'Firemní doručení GLS.', 60],
            ['WEDO', 'WE|DO Express', '249.00', 1, 'wedo', 'express', 'Expres do 24 hodin (Praha + okolí).', 70],
        ];

        $created = 0;
        $updated = 0;
        $keep = [];
        foreach ($defs as [$code, $name, $price, $days, $carrier, $type, $desc, $sort]) {
            $keep[] = $code;
            $m = $repo->findOneBy(['code' => $code]);
            if ($m === null) {
                $m = new \Modules\Shop\Domain\ShippingMethod($code, $name, $price, 'CZK');
                $this->em->persist($m);
                ++$created;
            } else {
                $m->setName($name);
                $m->setPrice($price);
                ++$updated;
            }
            $m->setEstimatedDays($days);
            $m->setCarrier($carrier);
            $m->setType($type);
            $m->setDescription($desc);
            $m->setSortOrder($sort);
            $m->setActive(true);
        }
        // Disable legacy demo codes (e.g. CP)
        foreach ($repo->findAll() as $legacy) {
            if (!in_array($legacy->code(), $keep, true) && $legacy->isActive()) {
                $legacy->setActive(false);
            }
        }
        $this->em->flush();
        $log[] = sprintf('Doprava: %d nových / %d aktualizovaných (ČP, PPL, DPD, Zásilkovna, GLS, WE|DO, odběr).', $created, $updated);
    }

    /** @param list<string> $log */
    private function seedPayments(array &$log): void
    {
        if (!class_exists(\Modules\Payments\Domain\PaymentMethod::class)) {
            return;
        }

        $repo = $this->em->getRepository(\Modules\Payments\Domain\PaymentMethod::class);
        $defs = [
            // code, name, type, provider, fee_fixed, fee_percent, instructions, sort
            ['CARD_GOPAY', 'Platební karta (GoPay)', 'card', 'gopay', '0', '1.9', 'Okamžitá platba kartou přes GoPay.', 30],
            ['CARD_COMGATE', 'Platební karta (Comgate)', 'card', 'comgate', '0', '1.5', 'Okamžitá platba kartou přes Comgate.', 31],
            ['CARD_STRIPE', 'Platební karta (Stripe)', 'card', 'stripe', '0', '2.9', 'Checkout Session přes Stripe (Apple/Google Pay dle Dashboardu).', 32],
            ['APPLE_PAY', 'Apple Pay', 'wallet', 'gopay', '0', '1.9', 'Platba přes Apple Pay (GoPay).', 50],
            ['GOOGLE_PAY', 'Google Pay', 'wallet', 'gopay', '0', '1.9', 'Platba přes Google Pay (GoPay).', 51],
            ['APPLE_PAY_STRIPE', 'Apple Pay (Stripe)', 'wallet', 'stripe', '0', '2.9', 'Apple Pay přes Stripe Checkout.', 52],
            ['GOOGLE_PAY_STRIPE', 'Google Pay (Stripe)', 'wallet', 'stripe', '0', '2.9', 'Google Pay přes Stripe Checkout.', 53],
            ['BANK', 'Bankovní převod', 'bank_transfer', 'manual', '0', '0', "Účet: 2600123456/2010\nVariabilní symbol = číslo objednávky.\nSplatnost 7 dní.", 10],
            ['COD', 'Dobírka', 'cod', 'manual', '39', '0', 'Platba hotově nebo kartou při převzetí. Poplatek 39 Kč.', 20],
        ];

        // Keep legacy demo methods without a gateway inactive so they do not confuse.
        foreach (['TWISTO', 'SKIPPAY', 'PAYPAL'] as $dead) {
            $old = $repo->findOneBy(['code' => $dead]);
            if ($old instanceof \Modules\Payments\Domain\PaymentMethod) {
                $old->setActive(false);
            }
        }

        $created = 0;
        $updated = 0;
        foreach ($defs as [$code, $name, $type, $provider, $feeFixed, $feePercent, $instructions, $sort]) {
            $m = $repo->findOneBy(['code' => $code]);
            if ($m === null) {
                $m = new \Modules\Payments\Domain\PaymentMethod($code, $name, $type);
                $this->em->persist($m);
                ++$created;
            } else {
                $m->setName($name);
                $m->setType($type);
                ++$updated;
            }
            $m->setProvider($provider);
            $m->setFeeFixed($feeFixed);
            $m->setFeePercent($feePercent);
            $m->setCurrency('CZK');
            $m->setInstructions($instructions);
            $m->setSortOrder($sort);
            $m->setActive(true);
        }
        $this->em->flush();
        $log[] = sprintf('Platby: %d nových / %d aktualizovaných (převod, dobírka, GoPay, Comgate, Stripe, Apple/Google Pay…).', $created, $updated);
    }

    /** @param list<string> $log */
    private function seedCoupons(array &$log): void
    {
        if (!class_exists(\Modules\Coupons\Domain\Coupon::class)) {
            return;
        }

        $repo = $this->em->getRepository(\Modules\Coupons\Domain\Coupon::class);
        $defs = [
            ['WELCOME10', 'Sleva pro nové zákazníky', 'percent', '10', null, '500', 100],
            ['GEREQ500', 'Sleva 500 Kč', 'fixed', '500', 'CZK', '2000', 50],
            ['SHIPFREE', 'Doprava zdarma (sleva 129 Kč)', 'fixed', '129', 'CZK', '1500', null],
        ];

        $n = 0;
        foreach ($defs as [$code, $name, $type, $value, $currency, $minOrder, $maxUses]) {
            if ($repo->findOneBy(['code' => $code]) !== null) {
                continue;
            }
            $c = new \Modules\Coupons\Domain\Coupon($code, $name, $type, $value);
            if ($currency !== null) {
                $c->setCurrency($currency);
            }
            $c->setMinOrder($minOrder);
            $c->setMaxUses($maxUses);
            $c->setActive(true);
            $this->em->persist($c);
            ++$n;
        }
        $this->em->flush();
        if ($n > 0) {
            $log[] = "Kupóny: vytvořeno {$n} (WELCOME10, GEREQ500, SHIPFREE).";
        }
    }

    /** @param list<string> $log */
    private function seedNewsletter(array &$log): void
    {
        if (!class_exists(\Modules\Newsletter\Domain\Subscriber::class)) {
            return;
        }

        $repo = $this->em->getRepository(\Modules\Newsletter\Domain\Subscriber::class);
        $emails = [
            ['info@acme.cz', 'Acme s.r.o.', 'demo'],
            ['nakup@nova-tech.cz', 'Nová Tech', 'landing'],
            ['jan.novak@example.cz', 'Jan Novák', 'checkout'],
            ['odhlaseny@example.cz', 'Odhlášený', 'import'],
        ];
        $n = 0;
        foreach ($emails as [$email, $name, $source]) {
            if ($repo->findOneBy(['email' => $email]) !== null) {
                continue;
            }
            $s = new \Modules\Newsletter\Domain\Subscriber($email, $name);
            $s->setSource($source);
            if ($email === 'odhlaseny@example.cz') {
                $s->unsubscribe();
            }
            $this->em->persist($s);
            ++$n;
        }

        if (class_exists(\Modules\Newsletter\Domain\Campaign::class)) {
            $crepo = $this->em->getRepository(\Modules\Newsletter\Domain\Campaign::class);
            if ($crepo->findOneBy(['subject' => 'Jarní novinky GEREQ']) === null) {
                $c = new \Modules\Newsletter\Domain\Campaign(
                    'Jarní novinky GEREQ',
                    '<h1>Jarní novinky</h1><p>Objevte nové produkty a slevu WELCOME10.</p>',
                );
                $c->markSent();
                $this->em->persist($c);
                ++$n;
            }
            if ($crepo->findOneBy(['subject' => 'Koncept: Black Friday']) === null) {
                $c = new \Modules\Newsletter\Domain\Campaign(
                    'Koncept: Black Friday',
                    '<p>Připravujeme Black Friday nabídku…</p>',
                );
                $this->em->persist($c);
                ++$n;
            }
        }

        $this->em->flush();
        if ($n > 0) {
            $log[] = "Newsletter: přidáno {$n} záznamů (odběratelé + kampaně).";
        }
    }

    /** @param list<string> $log */
    private function seedLoyaltyFeedsBundles(array &$log): void
    {
        if (class_exists(\Modules\Loyalty\Domain\LoyaltyTier::class)) {
            $repo = $this->em->getRepository(\Modules\Loyalty\Domain\LoyaltyTier::class);
            foreach ([
                ['BRONZE', 'Bronz', '0', '0', 10],
                ['SILVER', 'Stříbro', '5000', '3', 20],
                ['GOLD', 'Zlato', '20000', '5', 30],
            ] as [$code, $name, $min, $pct, $sort]) {
                if ($repo->findOneBy(['code' => $code]) !== null) {
                    continue;
                }
                $t = new \Modules\Loyalty\Domain\LoyaltyTier($code, $name);
                $t->setMinSpend($min);
                $t->setDiscountPercent($pct);
                $t->setSortOrder($sort);
                $this->em->persist($t);
            }
        }

        if (class_exists(\Modules\Feeds\Domain\FeedDefinition::class)) {
            $repo = $this->em->getRepository(\Modules\Feeds\Domain\FeedDefinition::class);
            foreach ([
                ['GOOGLE', 'Google Merchant', 'google_merchant', 'xml'],
                ['HEUREKA', 'Heureka', 'heureka', 'xml'],
            ] as [$code, $name, $driver, $format]) {
                if ($repo->findOneBy(['code' => strtolower($code)]) !== null || $repo->findOneBy(['code' => $code]) !== null) {
                    continue;
                }
                $f = new \Modules\Feeds\Domain\FeedDefinition($code, $name, $driver);
                $f->setFormat($format);
                $this->em->persist($f);
            }
        }

        if (class_exists(\Modules\Bundles\Domain\ProductBundle::class)) {
            $repo = $this->em->getRepository(\Modules\Bundles\Domain\ProductBundle::class);
            if ($repo->findOneBy(['code' => 'STARTER']) === null) {
                $b = new \Modules\Bundles\Domain\ProductBundle('STARTER', 'Startovací sestava');
                $b->setDiscountType('percent');
                $b->setDiscountValue('10');
                $this->em->persist($b);
            }
        }

        $this->em->flush();
        $log[] = 'Loyalty / Feedy / Bundles: demo katalogy upsert.';
    }

    /** @param list<string> $log */
    private function seedProductVariantsFromExisting(array &$log): void
    {
        if (!class_exists(\Modules\Products\Domain\ProductVariant::class) || !class_exists(\Modules\Products\Domain\Product::class)) {
            return;
        }
        $existing = (int) $this->em->createQueryBuilder()
            ->select('COUNT(v.id)')
            ->from(\Modules\Products\Domain\ProductVariant::class, 'v')
            ->getQuery()
            ->getSingleScalarResult();
        if ($existing > 0) {
            return;
        }
        $product = $this->em->getRepository(\Modules\Products\Domain\Product::class)->findOneBy([], ['createdAt' => 'ASC']);
        if ($product === null) {
            return;
        }
        $group = new \Modules\Products\Domain\ProductOptionGroup(
            \Modules\Products\Domain\ProductOptionGroup::SCOPE_PRODUCT,
            $product->id(),
            'size',
            'Velikost',
        );
        $this->em->persist($group);
        $this->em->flush();
        foreach ([['s', 'S'], ['m', 'M'], ['l', 'L']] as $i => [$code, $name]) {
            $val = new \Modules\Products\Domain\ProductOptionValue($group->id(), $code, $name);
            $val->setSortOrder($i);
            $this->em->persist($val);
            $variant = new \Modules\Products\Domain\ProductVariant($product->id(), $product->sku() . '-' . strtoupper($code));
            $variant->setName($product->name() . ' / ' . $name);
            $variant->setAttributes(['size' => $code]);
            $variant->setStockQty(25);
            $this->em->persist($variant);
        }
        $tier = new \Modules\Products\Domain\QuantityPriceTier('product', $product->id(), 10, '890.00');
        $this->em->persist($tier);
        $this->em->flush();
        $log[] = 'Varianty: ukázková option group + 3 SKU + quantity tier.';
    }

    /**
     * @param list<\Modules\Customers\Domain\Customer> $customers
     * @param list<\Modules\Products\Domain\Product> $products
     * @param list<string> $log
     */
    private function seedReviews(array $customers, array $products, array &$log): void
    {
        if (!class_exists(\Modules\Reviews\Domain\ProductReview::class) || $products === []) {
            return;
        }

        $existing = (int) $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(\Modules\Reviews\Domain\ProductReview::class, 'r')
            ->getQuery()
            ->getSingleScalarResult();
        if ($existing > 0) {
            return;
        }

        $samples = [
            [5, 'Skvělá spolupráce', 'Dodávka v termínu, komunikace výborná.', 'approved'],
            [4, 'Spokojenost', 'Produkt splnil očekávání, drobné zpoždění dopravy.', 'approved'],
            [3, 'Průměr', 'Funguje, ale dokumentace by mohla být lepší.', 'pending'],
            [5, 'Doporučuji', 'Nasazení bez problémů, podpora rychlá.', 'approved'],
            [2, 'Očekával jsem víc', 'Chybí některé funkce z nabídky.', 'rejected'],
        ];

        $n = 0;
        foreach ($samples as $i => [$rating, $title, $body, $status]) {
            $product = $products[$i % count($products)];
            $customer = $customers !== [] ? $customers[$i % count($customers)] : null;
            $review = new \Modules\Reviews\Domain\ProductReview(
                $product->id(),
                $customer?->name() ?? 'Anonymní zákazník',
                $rating,
                $body,
            );
            $review->setTitle($title);
            if ($customer !== null) {
                $review->setCustomerId($customer->id());
                $review->setAuthorEmail($customer->email());
            }
            if ($status === 'approved') {
                $review->approve();
            } elseif ($status === 'rejected') {
                $review->reject();
            }
            $this->em->persist($review);
            ++$n;
        }
        $this->em->flush();
        $log[] = "Recenze: {$n} ukázkových hodnocení produktů.";
    }

    /**
     * @param list<\Modules\Customers\Domain\Customer> $customers
     * @param list<\Modules\Products\Domain\Product> $products
     * @param list<string> $log
     */
    private function seedReturns(array $customers, array $products, array &$log): void
    {
        if (!class_exists(\Modules\Returns\Domain\ReturnRequest::class)) {
            return;
        }

        $existing = (int) $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(\Modules\Returns\Domain\ReturnRequest::class, 'r')
            ->getQuery()
            ->getSingleScalarResult();
        if ($existing > 0) {
            return;
        }

        $order = null;
        if (class_exists(\Modules\Orders\Domain\Order::class)) {
            $order = $this->em->getRepository(\Modules\Orders\Domain\Order::class)->findOneBy([], ['createdAt' => 'DESC']);
        }
        if ($order === null) {
            return;
        }

        /** @var \Modules\Returns\Application\ReturnsProvider $provider */
        $provider = new \Modules\Returns\Application\ReturnsProvider($this->em);
        $ret = new \Modules\Returns\Domain\ReturnRequest(
            $provider->nextNumber(),
            $order->id(),
            'Zboží neodpovídá popisu — požadavek na výměnu / refundaci.',
        );
        $ret->setCustomerId($order->customerId());
        $ret->setResolution('refund');
        $ret->setRefundAmount('1290.00');
        $ret->setStatus('requested');
        $this->em->persist($ret);

        $product = $products[0] ?? null;
        $item = new \Modules\Returns\Domain\ReturnItem(
            $ret->id(),
            $product?->name() ?? 'Položka objednávky',
            1,
        );
        if ($product !== null) {
            $item->setProductId($product->id());
        }
        $item->setReason('Poškozený obal / neúplný obsah');
        $this->em->persist($item);
        $this->em->flush();

        $ret2 = new \Modules\Returns\Domain\ReturnRequest(
            $provider->nextNumber(),
            $order->id(),
            'Zákazník odstoupil od smlouvy do 14 dnů.',
        );
        $ret2->setCustomerId($order->customerId());
        $ret2->setResolution('store_credit');
        $ret2->setStatus('approved');
        $this->em->persist($ret2);

        $this->em->flush();
        $log[] = 'Reklamace: 2 ukázková RMA (requested + approved).';
    }

    /** @param list<string> $log */
    private function seedReviewsAndReturnsFromExisting(array &$log): void
    {
        $products = [];
        if (class_exists(\Modules\Products\Domain\Product::class)) {
            $products = $this->em->getRepository(\Modules\Products\Domain\Product::class)->findBy([], null, 20);
        }
        $customers = [];
        if (class_exists(\Modules\Customers\Domain\Customer::class)) {
            $customers = $this->em->getRepository(\Modules\Customers\Domain\Customer::class)->findBy([], null, 20);
        }
        $this->seedReviews($customers, $products, $log);
        $this->seedReturns($customers, $products, $log);
    }

    /**
     * @param list<\Modules\Customers\Domain\Customer> $customers
     * @param list<\Modules\Products\Domain\Product> $products
     * @param list<string> $log
     */
    private function seedOrders(array $customers, array $products, array &$log): void
    {
        if (!class_exists(\Modules\Orders\Domain\Order::class) || $customers === [] || $products === []) {
            return;
        }

        $bySku = [];
        foreach ($products as $p) {
            $bySku[$p->sku()] = $p;
        }
        $byEmail = [];
        foreach ($customers as $c) {
            $byEmail[strtolower($c->email())] = $c;
        }

        $year = (new \DateTimeImmutable())->format('Y');

        // 1) paid order
        $c1 = $byEmail['helena.richter@logistik-cz.eu'] ?? $customers[0];
        $o1 = new \Modules\Orders\Domain\Order($c1->id(), "ORD-{$year}-0001", 'CZK');
        $o1->setStatus('fulfilled');
        $o1->setNote('Obnovení licence 2026.');
        $this->em->persist($o1);
        $this->em->flush();
        $lines1 = [];
        $l1 = new \Modules\Orders\Domain\OrderLine($o1->id(), $bySku['GRQ-LIC-PRO']->name(), '1', '36000', '21');
        $l1->setProductId($bySku['GRQ-LIC-PRO']->id());
        $this->em->persist($l1);
        $lines1[] = $l1;
        \Modules\Orders\Application\OrderTotals::recalculate($o1, $lines1);
        $inv1 = new \Modules\Orders\Domain\Invoice($o1->id(), "INV-{$year}-0001", $o1->total());
        $inv1->issue(new \DateTimeImmutable('-20 days'), new \DateTimeImmutable('-6 days'));
        $this->em->persist($inv1);
        $this->em->flush();
        $this->em->persist(new \Modules\Orders\Domain\InvoicePayment($inv1->id(), $inv1->total(), 'bank_transfer', new \DateTimeImmutable('-12 days')));
        $inv1->markPaid();

        // 2) confirmed order, issued invoice unpaid
        $c2 = $byEmail['petra.dvorakova@moravia.cz'] ?? $customers[0];
        $o2 = new \Modules\Orders\Domain\Order($c2->id(), "ORD-{$year}-0002", 'CZK');
        $o2->setStatus('confirmed');
        $o2->setNote('Částečná dodávka modulů.');
        $this->em->persist($o2);
        $this->em->flush();
        $lines2 = [];
        foreach ([['GRQ-SHOP-ADD', '1', '12000'], ['GRQ-CMS-ADD', '1', '8500'], ['GRQ-SUP-M', '3', '4900']] as [$sku, $qty, $price]) {
            $line = new \Modules\Orders\Domain\OrderLine($o2->id(), $bySku[$sku]->name(), $qty, $price, '21');
            $line->setProductId($bySku[$sku]->id());
            $this->em->persist($line);
            $lines2[] = $line;
        }
        \Modules\Orders\Application\OrderTotals::recalculate($o2, $lines2);
        $inv2 = new \Modules\Orders\Domain\Invoice($o2->id(), "INV-{$year}-0002", $o2->total());
        $inv2->issue(new \DateTimeImmutable('-3 days'), new \DateTimeImmutable('+11 days'));
        $this->em->persist($inv2);

        // 3) draft
        $c3 = $byEmail['klara.benesova@nordsoft.cz'] ?? $customers[0];
        $o3 = new \Modules\Orders\Domain\Order($c3->id(), "ORD-{$year}-0003", 'CZK');
        $o3->setStatus('draft');
        $o3->setNote('Návrh implementace — čeká na schválení.');
        $this->em->persist($o3);
        $this->em->flush();
        $lines3 = [];
        foreach ([[self::MARKER_SKU, '1', '89000'], ['GRQ-TRAIN-1D', '2', '18000']] as [$sku, $qty, $price]) {
            $line = new \Modules\Orders\Domain\OrderLine($o3->id(), $bySku[$sku]->name(), $qty, $price, '21');
            $line->setProductId($bySku[$sku]->id());
            $this->em->persist($line);
            $lines3[] = $line;
        }
        \Modules\Orders\Application\OrderTotals::recalculate($o3, $lines3);

        // 4) cancelled
        $o4 = new \Modules\Orders\Domain\Order(($byEmail['jakub.nemec@seznam.cz'] ?? $customers[0])->id(), "ORD-{$year}-0004", 'CZK');
        $o4->setStatus('cancelled');
        $o4->setNote('Zrušeno zákazníkem.');
        $this->em->persist($o4);
        $this->em->flush();
        $l4 = new \Modules\Orders\Domain\OrderLine($o4->id(), $bySku['GRQ-NB-14']->name(), '1', '24990', '21');
        $l4->setProductId($bySku['GRQ-NB-14']->id());
        $this->em->persist($l4);
        \Modules\Orders\Application\OrderTotals::recalculate($o4, [$l4]);

        $this->em->flush();
        $log[] = 'Objednávky: 4 (fulfilled/confirmed/draft/cancelled) + faktury a platba.';
    }

    /**
     * @param list<\Modules\Customers\Domain\Customer> $customers
     * @param list<\Modules\Products\Domain\Product> $products
     * @param list<string> $log
     */
    private function seedCarts(array $customers, array $products, array &$log): void
    {
        if (!class_exists(\Modules\Shop\Domain\Cart::class) || $products === []) {
            return;
        }

        $bySku = [];
        foreach ($products as $p) {
            $bySku[$p->sku()] = $p;
        }
        $byEmail = [];
        foreach ($customers as $c) {
            $byEmail[strtolower($c->email())] = $c;
        }

        $openCustomer = $byEmail['ondrej.maly@citycafe.cz'] ?? ($customers[0] ?? null);
        $open = new \Modules\Shop\Domain\Cart($openCustomer?->id(), 'demo-session-open', 'CZK');
        $open->mergeMeta(['source' => 'demo', 'note' => 'Otevřený košík pro klikání']);
        $this->em->persist($open);
        $this->em->flush();

        foreach ([['GRQ-SHOP-ADD', '1', '12000'], ['GRQ-CMS-ADD', '1', '8500']] as [$sku, $qty, $price]) {
            $p = $bySku[$sku];
            $this->em->persist(new \Modules\Shop\Domain\CartItem($open->id(), $p->id(), $p->name(), $p->sku(), $qty, $price, 'CZK'));
        }

        $abandoned = new \Modules\Shop\Domain\Cart(($byEmail['jakub.nemec@seznam.cz'] ?? null)?->id(), 'demo-session-abandoned', 'CZK');
        $abandoned->setStatus('abandoned');
        $this->em->persist($abandoned);
        $this->em->flush();
        $p = $bySku['GRQ-MON-27'];
        $this->em->persist(new \Modules\Shop\Domain\CartItem($abandoned->id(), $p->id(), $p->name(), $p->sku(), '1', '8990', 'CZK'));

        $this->em->flush();
        $log[] = 'Shop: otevřený + abandoned košík.';
    }

    /**
     * @param list<\Modules\Customers\Domain\Customer> $customers
     * @param list<\Modules\CRM\Domain\Deal> $deals
     * @param list<string> $log
     */
    private function seedProjects(array $customers, array $deals, User $demo, User $manager, User $editor, array &$log): void
    {
        if (!class_exists(\Modules\Projects\Domain\Project::class) || $customers === []) {
            return;
        }

        $byEmail = [];
        foreach ($customers as $c) {
            $byEmail[strtolower($c->email())] = $c;
        }

        $cNord = $byEmail['klara.benesova@nordsoft.cz'] ?? $customers[0];
        $cMora = $byEmail['petra.dvorakova@moravia.cz'] ?? $customers[0];

        $p1 = new \Modules\Projects\Domain\Project($cNord->id(), 'NordSoft — implementace GEREQ');
        $p1->setStatus('active');
        $p1->setOwnerUserId($demo->id());
        $p1->setStartAt(new \DateTimeImmutable('-14 days'));
        $p1->setDueAt(new \DateTimeImmutable('+45 days'));
        if (isset($deals[1])) {
            $p1->setDealId($deals[1]->id());
        }
        $this->em->persist($p1);

        $p2 = new \Modules\Projects\Domain\Project($cMora->id(), 'Moravia — e-shop roll-out');
        $p2->setStatus('planning');
        $p2->setOwnerUserId($manager->id());
        $p2->setStartAt(new \DateTimeImmutable('+7 days'));
        $p2->setDueAt(new \DateTimeImmutable('+90 days'));
        if (isset($deals[0])) {
            $p2->setDealId($deals[0]->id());
        }
        $this->em->persist($p2);

        $p3 = new \Modules\Projects\Domain\Project(($byEmail['helena.richter@logistik-cz.eu'] ?? $customers[0])->id(), 'Logistik — licence 2025');
        $p3->setStatus('done');
        $p3->setOwnerUserId($editor->id());
        $p3->setStartAt(new \DateTimeImmutable('-120 days'));
        $p3->setDueAt(new \DateTimeImmutable('-15 days'));
        $this->em->persist($p3);
        $this->em->flush();

        foreach ([[$p1, $demo, 'owner'], [$p1, $manager, 'member'], [$p1, $editor, 'member'], [$p2, $manager, 'owner'], [$p2, $demo, 'member']] as [$proj, $user, $role]) {
            $this->em->persist(new \Modules\Projects\Domain\ProjectMember($proj->id(), $user->id(), $role));
        }

        $tasks = [
            [$p1, 'Analýza procesů a migrace dat', 'done', $demo, '-7 days'],
            [$p1, 'Nasazení staging prostředí', 'doing', $manager, '+3 days'],
            [$p1, 'Školení administrátorů', 'todo', $editor, '+20 days'],
            [$p1, 'Go-live checklist', 'todo', $demo, '+40 days'],
            [$p2, 'Návrh katalogu produktů', 'todo', $manager, '+14 days'],
            [$p2, 'Nastavení dopravy a DPH', 'todo', $demo, '+21 days'],
            [$p3, 'Aktivace licence', 'done', $editor, '-20 days'],
        ];
        foreach ($tasks as [$proj, $title, $status, $assignee, $due]) {
            $task = new \Modules\Projects\Domain\ProjectTask($proj->id(), $title);
            $task->setStatus($status);
            $task->setAssigneeUserId($assignee->id());
            $task->setDueAt(new \DateTimeImmutable($due));
            $this->em->persist($task);
        }

        $this->em->flush();
        $log[] = 'Projekty: 3 + členové a úkoly.';
    }

    /**
     * @param list<\Modules\Customers\Domain\Customer> $customers
     * @param list<\Modules\CRM\Domain\Deal> $deals
     * @param list<string> $log
     */
    private function seedCalendar(User $demo, User $manager, array $customers, array $deals, array &$log): void
    {
        if (!class_exists(\Modules\Calendar\Domain\CalendarEvent::class)) {
            return;
        }

        $events = [
            ['Kick-off NordSoft', '+2 days 10:00', '+2 days 11:30', 'meeting', 'Úvodní schůzka k implementaci.'],
            ['Deadline nabídky Moravia', '+5 days 17:00', '+5 days 17:00', 'deadline', 'Odeslat finální nabídku.'],
            ['Demo e-shop City Café', '+8 days 14:00', '+8 days 15:00', 'meeting', 'Ukázka košíku a checkoutu.'],
            ['Interní planning', '-1 day 09:00', '-1 day 10:00', 'other', 'Týdenní plánování týmu.'],
        ];

        foreach ($events as $i => [$title, $start, $end, $type, $desc]) {
            $event = new \Modules\Calendar\Domain\CalendarEvent(
                $title,
                new \DateTimeImmutable($start),
                new \DateTimeImmutable($end),
                $type,
            );
            $event->setDescription($desc);
            if ($type === 'deadline') {
                $event->setAllDay(true);
            }
            $this->em->persist($event);
            $this->em->flush();

            $this->em->persist(new \Modules\Calendar\Domain\CalendarAttendee($event->id(), $demo->id(), $demo->email()));
            $this->em->persist(new \Modules\Calendar\Domain\CalendarAttendee($event->id(), $manager->id(), $manager->email()));

            if ($i === 0 && isset($deals[1])) {
                $this->em->persist(new \Modules\Calendar\Domain\CalendarEventLink($event->id(), 'crm.deal', $deals[1]->id()));
            }
            if ($i === 1 && $customers !== []) {
                $mora = null;
                foreach ($customers as $c) {
                    if (str_contains(strtolower($c->email()), 'moravia')) {
                        $mora = $c;
                        break;
                    }
                }
                if ($mora !== null) {
                    $this->em->persist(new \Modules\Calendar\Domain\CalendarEventLink($event->id(), 'customer', $mora->id()));
                }
            }
        }

        $this->em->flush();
        $log[] = 'Kalendář: 4 události s účastníky.';
    }

    /**
     * @param list<\Modules\Customers\Domain\Customer> $customers
     * @param list<\Modules\Products\Domain\Product> $products
     * @param list<string> $log
     */
    private function seedDocumentsAndMedia(User $demo, array $customers, array $products, array &$log): void
    {
        $dir = base_path('storage/uploads/demo');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $offerPath = $dir . '/nabidka-moravia.txt';
        $offerBody = "GEREQ Platform — nabídka pro Moravia IT\nDatum: " . date('Y-m-d') . "\nPoložky: Shop + CMS + podpora.\n";
        file_put_contents($offerPath, $offerBody);

        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="640" height="360" viewBox="0 0 640 360">
  <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#0f766e"/><stop offset="100%" stop-color="#134e4a"/></linearGradient></defs>
  <rect width="640" height="360" fill="url(#g)"/>
  <text x="40" y="180" fill="#ecfdf5" font-family="Georgia, serif" font-size="42">GEREQ</text>
  <text x="40" y="230" fill="#99f6e4" font-family="sans-serif" font-size="18">Interní portál · demo asset</text>
</svg>
SVG;
        $heroPath = $dir . '/hero-gereq.svg';
        file_put_contents($heroPath, $svg);

        if (class_exists(\Modules\Documents\Domain\Document::class)) {
            $doc = new \Modules\Documents\Domain\Document(
                'Nabídka Moravia IT',
                'demo/nabidka-moravia.txt',
                'nabidka-moravia.txt',
                'text/plain',
                strlen($offerBody),
                $demo->id(),
            );
            $this->em->persist($doc);
            $this->em->flush();
            $mora = null;
            foreach ($customers as $c) {
                if (str_contains(strtolower($c->email()), 'moravia')) {
                    $mora = $c;
                    break;
                }
            }
            if ($mora !== null && class_exists(\Modules\Documents\Domain\DocumentLink::class)) {
                $this->em->persist(new \Modules\Documents\Domain\DocumentLink($doc->id(), 'customer', $mora->id()));
            }
        }

        if (class_exists(\Modules\Media\Domain\MediaAsset::class)) {
            $media = new \Modules\Media\Domain\MediaAsset(
                'Hero GEREQ',
                'demo/hero-gereq.svg',
                'image/svg+xml',
                strlen($svg),
                $demo->id(),
                'GEREQ hero banner',
                640,
                360,
            );
            $this->em->persist($media);

            $guide = "Interní návod k CMS builderu.\n1. Vytvořte stránku\n2. Přidejte bloky\n3. Publikujte\n";
            $guidePath = $dir . '/cms-navod.txt';
            file_put_contents($guidePath, $guide);
            $this->em->persist(new \Modules\Media\Domain\MediaAsset(
                'Návod CMS',
                'demo/cms-navod.txt',
                'text/plain',
                strlen($guide),
                $demo->id(),
                null,
            ));
        }

        $this->em->flush();
        $log[] = 'Dokumenty a média: nabídka + hero SVG.';
    }

    /**
     * @param list<\Modules\Products\Domain\Product> $products
     * @param list<string> $log
     */
    private function seedCms(array $products, array &$log): void
    {
        if (!class_exists(\Modules\Cms\Domain\Page::class)) {
            return;
        }

        $pageRepo = $this->em->getRepository(\Modules\Cms\Domain\Page::class);
        unset($products);

        $home = $pageRepo->findOneBy(['slug' => 'home']) ?? $pageRepo->findOneBy(['slug' => 'uvod']);
        if ($home === null) {
            $home = new \Modules\Cms\Domain\Page('home', 'Úvodní stránka');
            $home->setStatus('published');
            $home->setTemplate('landing');
            $home->setMetaDescription('Realitní kancelář RealEstates — prodej a pronájem bytů a domů v Praze a Brně.');
            $this->em->persist($home);
            $this->em->flush();
            $this->replaceHomeBlocks($home->id());
        } elseif ($home->slug() === 'uvod') {
            $home->setSlug('home');
            $home->setTitle('Úvodní stránka');
            $home->setTemplate('landing');
            $home->setMetaDescription('Realitní kancelář RealEstates — prodej a pronájem bytů a domů v Praze a Brně.');
            $this->em->flush();
            $this->replaceHomeBlocks($home->id());
        } else {
            $home->setTemplate('landing');
            if ($home->metaDescription() === null || $home->metaDescription() === '') {
                $home->setMetaDescription('Realitní kancelář RealEstates — prodej a pronájem bytů a domů v Praze a Brně.');
            }
            $this->em->flush();
            // Home blocks are handled by ensureCmsHomeOnePage() on every seed:demo.
        }

        $about = $pageRepo->findOneBy(['slug' => 'o-nas']);
        if ($about === null) {
            $about = new \Modules\Cms\Domain\Page('o-nas', 'O nás');
            $about->setStatus('published');
            $about->setMetaDescription('Kdo jsme a jak pracujeme s klienty v Praze a Brně.');
            $this->em->persist($about);
            $this->em->flush();
            $this->seedAboutBlocks($about->id());
        }

        $draft = $pageRepo->findOneBy(['slug' => 'novinky-draft']);
        if ($draft === null) {
            $draft = new \Modules\Cms\Domain\Page('novinky-draft', 'Novinky (koncept)');
            $draft->setStatus('draft');
            $this->em->persist($draft);
            $this->em->flush();
            $this->em->persist(new \Modules\Cms\Domain\PageBlock($draft->id(), 'heading', 0, [
                'text' => 'Připravujeme…',
                'level' => 2,
            ]));
            $this->em->persist(new \Modules\Cms\Domain\PageBlock($draft->id(), 'text', 1, [
                'body' => '<p>Tato stránka je draft — ještě není veřejná.</p>',
            ]));
        }

        $this->seedLegalPages($pageRepo);

        $this->em->flush();

        if (class_exists(\Modules\Cms\Domain\SiteMenu::class)) {
            $menuRepo = $this->em->getRepository(\Modules\Cms\Domain\SiteMenu::class);
            $header = $menuRepo->findOneBy(['location' => 'header']);
            if ($header === null) {
                $header = new \Modules\Cms\Domain\SiteMenu('Hlavní menu', 'header');
                $this->em->persist($header);
                $this->em->flush();
                $aboutPage = $pageRepo->findOneBy(['slug' => 'o-nas']);
                $i = 0;
                $sale = new \Modules\Cms\Domain\SiteMenuItem($header->id(), 'Koupit', $i++);
                $sale->setUrl('/?offer=sale');
                $this->em->persist($sale);
                $rent = new \Modules\Cms\Domain\SiteMenuItem($header->id(), 'Pronajmout', $i++);
                $rent->setUrl('/?offer=rent');
                $this->em->persist($rent);
                if ($aboutPage !== null) {
                    $item = new \Modules\Cms\Domain\SiteMenuItem($header->id(), 'O nás', $i++);
                    $item->setPageId($aboutPage->id());
                    $this->em->persist($item);
                }
            }

            $footer = $menuRepo->findOneBy(['location' => 'footer']);
            if ($footer === null) {
                $footer = new \Modules\Cms\Domain\SiteMenu('Patička', 'footer');
                $this->em->persist($footer);
                $this->em->flush();
                $privacy = $pageRepo->findOneBy(['slug' => 'ochrana-osobnich-udaju']);
                $cookies = $pageRepo->findOneBy(['slug' => 'cookies']);
                $i = 0;
                if ($privacy !== null) {
                    $item = new \Modules\Cms\Domain\SiteMenuItem($footer->id(), 'Ochrana osobních údajů', $i++);
                    $item->setPageId($privacy->id());
                    $this->em->persist($item);
                }
                if ($cookies !== null) {
                    $item = new \Modules\Cms\Domain\SiteMenuItem($footer->id(), 'Cookies', $i++);
                    $item->setPageId($cookies->id());
                    $this->em->persist($item);
                }
                $item = new \Modules\Cms\Domain\SiteMenuItem($footer->id(), 'Napsat e-mail', $i);
                $item->setUrl('mailto:info@realestates.cz');
                $this->em->persist($item);
            }
        }

        $this->em->flush();
        $log[] = 'CMS: stránky (home/o-nas/cookies/GDPR/draft) + menu pro storefront.';
    }

    /** @param \Doctrine\ORM\EntityRepository<\Modules\Cms\Domain\Page> $pageRepo */
    private function seedLegalPages($pageRepo): void
    {
        $privacy = $pageRepo->findOneBy(['slug' => 'ochrana-osobnich-udaju']);
        if ($privacy === null) {
            $privacy = new \Modules\Cms\Domain\Page('ochrana-osobnich-udaju', 'Ochrana osobních údajů');
            $privacy->setStatus('published');
            $privacy->setMetaDescription('Informace o zpracování osobních údajů podle GDPR.');
            $this->em->persist($privacy);
            $this->em->flush();
            $this->seedPrivacyBlocks($privacy->id());
        }

        $cookies = $pageRepo->findOneBy(['slug' => 'cookies']);
        if ($cookies === null) {
            $cookies = new \Modules\Cms\Domain\Page('cookies', 'Zásady cookies');
            $cookies->setStatus('published');
            $cookies->setMetaDescription('Jaké cookies používáme a jak spravovat souhlas.');
            $this->em->persist($cookies);
            $this->em->flush();
            $this->seedCookiesBlocks($cookies->id());
        }
    }

    private function seedPrivacyBlocks(string $pageId): void
    {
        $blocks = [
            ['heading', 0, ['text' => 'Správce údajů', 'level' => 2]],
            ['text', 1, [
                'body' => '<p>Správcem osobních údajů je <strong>RealEstates</strong>, Křižíkova 12, Praha 8, e-mail <a href="mailto:info@realestates.cz">info@realestates.cz</a>, telefon +420 777 000 000.</p>',
            ]],
            ['heading', 2, ['text' => 'Jaké údaje zpracováváme', 'level' => 2]],
            ['html', 3, [
                'html' => '<ul><li>identifikační a kontaktní údaje (jméno, e-mail, telefon),</li><li>údaje o poptávce a komunikaci s makléřem,</li><li>technické údaje o návštěvě webu (IP adresa, cookies — dle vašeho souhlasu).</li></ul>',
            ]],
            ['heading', 4, ['text' => 'Účel a právní základ', 'level' => 2]],
            ['text', 5, [
                'body' => '<p>Údaje zpracováváme za účelem vyřízení poptávky a prohlídky (plnění smlouvy / oprávněný zájem), odpovědi na dotazy a — jen se souhlasem — pro analytiku a marketing.</p>',
            ]],
            ['heading', 6, ['text' => 'Doba uložení', 'level' => 2]],
            ['text', 7, [
                'body' => '<p>Údaje z poptávek uchováváme po dobu jednání a následně nejvýše 3 roky, není-li delší doba vyžadována právními předpisy. Souhlas s cookies můžete kdykoli odvolat.</p>',
            ]],
            ['heading', 8, ['text' => 'Vaše práva', 'level' => 2]],
            ['html', 9, [
                'html' => '<ul><li>právo na přístup, opravu a výmaz,</li><li>právo na omezení zpracování a přenositelnost,</li><li>právo vznést námitku a podat stížnost u ÚOOÚ,</li><li>právo odvolat souhlas se zpracováním.</li></ul><p>Žádosti zasílejte na <a href="mailto:info@realestates.cz">info@realestates.cz</a>.</p>',
            ]],
        ];
        foreach ($blocks as [$type, $order, $payload]) {
            $this->em->persist(new \Modules\Cms\Domain\PageBlock($pageId, $type, $order, $payload));
        }
        $this->em->flush();
    }

    private function seedCookiesBlocks(string $pageId): void
    {
        $blocks = [
            ['divider', 0, ['style' => 'banner']],
            ['heading', 1, ['text' => 'Soubory cookies', 'level' => 2]],
            ['text', 2, [
                'body' => 'Používáme nezbytné cookies pro běh webu. Analytické a marketingové zapneme jen se vaším souhlasem. Podrobnosti najdete níže a v ochraně osobních údajů.',
            ]],
            ['cta', 3, ['label' => 'Přijmout vše', 'url' => '#', 'style' => 'primary']],
            ['cta', 4, ['label' => 'Pouze nutné', 'url' => '#', 'style' => 'ghost']],
            ['cta', 5, ['label' => 'Nastavení', 'url' => '#', 'style' => 'link']],
            ['divider', 6, ['style' => 'policy']],
            ['heading', 7, ['text' => 'Co jsou cookies', 'level' => 2]],
            ['text', 8, [
                'body' => '<p>Cookies jsou malé textové soubory ukládané do vašeho zařízení. Pomáhají webu fungovat, pamatovat si volby a — se souhlasem — měřit návštěvnost.</p>',
            ]],
            ['heading', 9, ['text' => 'Kategorie', 'level' => 2]],
            ['html', 10, [
                'html' => '<ul><li><strong>Nezbytné</strong> — technický provoz, zabezpečení, uložení souhlasu. Nelze vypnout.</li><li><strong>Analytické</strong> — anonymizovaná statistika používání webu.</li><li><strong>Marketingové</strong> — měření kampaní a relevantnější nabídky.</li></ul>',
            ]],
            ['heading', 11, ['text' => 'Správa souhlasu', 'level' => 2]],
            ['text', 12, [
                'body' => '<p>Souhlas můžete změnit kdykoli odkazem „Nastavení cookies“ v patičce webu. Odvolání souhlasu neovlivní zákonnost zpracování před jeho odvoláním.</p>',
            ]],
        ];
        foreach ($blocks as [$type, $order, $payload]) {
            $this->em->persist(new \Modules\Cms\Domain\PageBlock($pageId, $type, $order, $payload));
        }
        $this->em->flush();
    }

    private function seedHomeBlocks(string $pageId): void
    {
        $this->replaceHomeBlocks($pageId);
    }

    /** @param list<string> $log */
    private function ensureCmsHomeOnePage(array &$log): void
    {
        if (!class_exists(\Modules\Cms\Domain\Page::class)) {
            return;
        }

        $pageRepo = $this->em->getRepository(\Modules\Cms\Domain\Page::class);
        $home = $pageRepo->findOneBy(['slug' => 'home']) ?? $pageRepo->findOneBy(['slug' => 'uvod']);
        if ($home === null) {
            $home = new \Modules\Cms\Domain\Page('home', 'Úvodní stránka');
            $home->setStatus('published');
            $home->setTemplate('landing');
            $home->setMetaDescription('Realitní kancelář RealEstates — prodej a pronájem bytů a domů v Praze a Brně.');
            $this->em->persist($home);
            $this->em->flush();
        } else {
            if ($home->slug() === 'uvod') {
                $home->setSlug('home');
            }
            $home->setTitle('Úvodní stránka');
            $home->setTemplate('landing');
            $home->setStatus('published');
            if ($home->metaDescription() === null || trim((string) $home->metaDescription()) === '') {
                $home->setMetaDescription('Realitní kancelář RealEstates — prodej a pronájem bytů a domů v Praze a Brně.');
            }
            $this->em->flush();
        }

        $this->replaceHomeBlocks($home->id());
        $this->syncHomePublicSnapshot($home);
        $log[] = 'CMS: úvodní one-page (home, template=landing) obnoven.';
    }

    private function replaceHomeBlocks(string $pageId): void
    {
        foreach ($this->em->getRepository(\Modules\Cms\Domain\PageBlock::class)->findBy(['pageId' => $pageId]) as $block) {
            $this->em->remove($block);
        }
        $this->em->flush();

        $blocks = [
            ['divider', 0, ['style' => 'hero']],
            ['text', 1, ['body' => 'Realitní kancelář · Praha a Brno']],
            ['heading', 2, ['text' => 'Najděte byt nebo dům, který sedne', 'level' => 1]],
            ['text', 3, [
                'body' => 'U každé nabídky najdete cenu, metráž, energetickou třídu i kontakt na makléře, který ji vede. Filtrujte podle města, typu nemovitosti a rozpočtu — bez registrace.',
            ]],
            ['image', 4, [
                'url' => 'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?w=1920&q=80',
                'alt' => 'Světlý obývací pokoj s výhledem',
            ]],
            ['divider', 5, ['style' => 'locations']],
            ['heading', 6, ['text' => 'Lokality, kde působíme', 'level' => 2]],
            ['text', 7, [
                'body' => 'Vyberte město a rovnou zobrazte dostupné nabídky. Počty i fotky bereme z aktuálního katalogu.',
            ]],
            ['divider', 8, ['style' => 'listings']],
            ['heading', 9, ['text' => 'Aktuální nabídky', 'level' => 2]],
            ['divider', 10, ['style' => 'how']],
            ['heading', 11, ['text' => 'Jak to funguje', 'level' => 2]],
            ['text', 12, ['body' => 'Od prvního filtru k prohlídce ve třech jasných krocích.']],
            ['heading', 13, ['text' => 'Vyfiltrujte nabídky', 'level' => 3]],
            ['text', 14, [
                'body' => 'Zadejte lokalitu, cenové rozpětí, dispozici a plochu. Filtry fungují okamžitě a bez registrace.',
            ]],
            ['heading', 15, ['text' => 'Kontaktujte makléře', 'level' => 3]],
            ['text', 16, [
                'body' => 'U každé nabídky je uvedený makléř, který ji vede — včetně telefonu a e-mailu. Ptáte se přímo u zdroje.',
            ]],
            ['heading', 17, ['text' => 'Domluvte si prohlídku', 'level' => 3]],
            ['text', 18, [
                'body' => 'Termín potvrzujeme do jednoho pracovního dne. Podklady k nemovitosti dostanete předem.',
            ]],
            ['divider', 19, ['style' => 'cta']],
            ['heading', 20, ['text' => 'Nenašli jste, co hledáte?', 'level' => 2]],
            ['text', 21, [
                'body' => 'Napište nám, co potřebujete. Projdeme i nabídky, které zatím nejsou zveřejněné, a ozveme se s konkrétním tipem.',
            ]],
            ['cta', 22, ['label' => 'Napsat e-mail', 'url' => 'mailto:info@realestates.cz', 'style' => 'primary']],
            ['cta', 23, ['label' => '777 000 000', 'url' => 'tel:+420777000000', 'style' => 'ghost']],
        ];

        foreach ($blocks as [$type, $order, $payload]) {
            $this->em->persist(new \Modules\Cms\Domain\PageBlock($pageId, $type, $order, $payload));
        }
        $this->em->flush();
    }

    private function seedAboutBlocks(string $pageId): void
    {
        $blocks = [
            ['heading', 0, ['text' => 'RealEstates', 'level' => 2]],
            ['text', 1, [
                'body' => '<p>Jsme realitní kancelář zaměřená na Prahu a Brno. Každou nabídku vede konkrétní makléř, kterého klient kontaktuje přímo — bez call centra a zbytečných přeposílání.</p>',
            ]],
            ['heading', 2, ['text' => 'Co nabízíme', 'level' => 2]],
            ['html', 3, [
                'html' => '<ul><li>Prodej a pronájem bytů, domů i pozemků</li><li>Odhad tržní ceny a příprava nabídky</li><li>Prohlídky a jednání bez zbytečné administrativy</li><li>Doporučení ověřených řemeslníků a právníků</li></ul>',
            ]],
            ['heading', 4, ['text' => 'Jak s vámi pracujeme', 'level' => 2]],
            ['text', 5, [
                'body' => '<p>Nejdřív si ujasníme rozpočet a lokalitu. Pak vybíráme jen nabídky, které dávají smysl — a u těch, které vedeme my, držíme termíny prohlídek a podklady připravené předem.</p>',
            ]],
            ['cta', 6, ['label' => 'Napsat e-mail', 'url' => 'mailto:info@realestates.cz', 'style' => 'primary']],
            ['cta', 7, ['label' => '777 000 000', 'url' => 'tel:+420777000000', 'style' => 'ghost']],
        ];
        foreach ($blocks as [$type, $order, $payload]) {
            $this->em->persist(new \Modules\Cms\Domain\PageBlock($pageId, $type, $order, $payload));
        }
        $this->em->flush();
    }

    /** After refreshing blocks, syncs the published snapshot so the public API reads the new one-page immediately. */
    private function syncHomePublicSnapshot(\Modules\Cms\Domain\Page $home): void
    {
        if (!class_exists(\Gereq\Publish\Entity\ContentStagingEntry::class)
            || !class_exists(\Gereq\Publish\Adapter\CmsPagePublishAdapter::class)
        ) {
            return;
        }

        $entry = $this->em->getRepository(\Gereq\Publish\Entity\ContentStagingEntry::class)->findOneBy([
            'category' => 'cms.pages',
            'resourceKey' => $home->id(),
        ]);
        if (!$entry instanceof \Gereq\Publish\Entity\ContentStagingEntry) {
            return;
        }

        // Without staging, the public API uses the live working copy — exactly what we want after demo seed.
        $this->em->remove($entry);
        $this->em->flush();
    }

    /** @param list<string> $log */
    private function seedNotifications(User $demo, User $manager, array &$log): void
    {
        $this->em->persist(new DatabaseNotification($demo->id(), 'projects.task_assigned', [
            'title' => 'Nový úkol',
            'body' => 'Byl vám přiřazen úkol „Nasazení staging prostředí“.',
            'url' => '/admin/projects',
        ]));
        $this->em->persist(new DatabaseNotification($demo->id(), 'orders.invoice_due', [
            'title' => 'Splatná faktura',
            'body' => 'Faktura INV-2026-0002 je splatná za 11 dní.',
            'url' => '/admin/orders',
        ]));
        $this->em->persist(new DatabaseNotification($demo->id(), 'warehouse.low_stock', [
            'title' => 'Nízká zásoba',
            'body' => 'Monitor 27" 4K — zbývají 2 ks ve skladu Praha.',
            'url' => '/admin/warehouse',
        ]));
        $read = new DatabaseNotification($demo->id(), 'crm.deal_won', [
            'title' => 'Obchod vyhraný',
            'body' => 'Licence 2026 renewal — Logistik CZ.',
            'url' => '/admin/crm',
        ]);
        $read->markRead();
        $this->em->persist($read);

        $this->em->persist(new DatabaseNotification($manager->id(), 'shop.cart_open', [
            'title' => 'Otevřený košík',
            'body' => 'City Café má otevřený košík s 2 položkami.',
            'url' => '/admin/shop/carts',
        ]));

        if (class_exists(\Modules\Notifications\Domain\NotificationPreference::class)) {
            foreach (['mail', 'database'] as $channel) {
                foreach (['projects.task_assigned', 'orders.invoice_due', 'warehouse.low_stock'] as $event) {
                    $this->em->persist(new \Modules\Notifications\Domain\NotificationPreference(
                        $demo->id(),
                        $channel,
                        $event,
                        true,
                    ));
                }
            }
        }

        $this->em->flush();
        $log[] = 'Notifikace: inbox pro demo + preference.';
    }
}
