<?php

declare(strict_types=1);

namespace Gereq\Database;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\PasswordHasher;
use Gereq\Module\InstalledModule;
use Gereq\Module\ModuleFootprint;
use Gereq\Module\ModuleManager;
use Gereq\User\Entity\Permission;
use Gereq\User\Entity\Role;
use Gereq\User\Entity\User;
use Gereq\User\PermissionRegistry;

final class Seeder
{
    private ?\Gereq\Media\Application\MediaImporter $mediaImporter = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasswordHasher $hasher,
        private readonly PermissionRegistry $permissions,
        private readonly ModuleManager $modules,
        private readonly Migrator $migrator,
        private readonly DemoSeeder $demoSeeder,
        private readonly RealityV3Seeder $realityV3Seeder,
    ) {
    }

    /**
     * Core bootstrap only: permissions, roles, and the given admin user.
     * No modules enabled, no demo / sample business data.
     *
     * @param array{name: string, email: string, password: string, role?: string} $admin
     */
    public function seedBare(array $admin): void
    {
        $this->seedPermissions();
        $this->seedRoles();
        $this->seedAdmin($admin);
        $this->em->flush();
    }

    /**
     * @param array{name: string, email: string, password: string, role?: string}|null $admin
     */
    public function seed(bool $withDemo = true, bool $withSampleCustomers = true, ?array $admin = null): void
    {
        if ($admin === null || trim($admin['email']) === '' || $admin['password'] === '') {
            throw new \InvalidArgumentException('seed() requires admin name/email/password (not from .env).');
        }
        $this->seedPermissions();
        $this->seedRoles();
        $this->seedAdmin($admin);
        $this->seedModules();
        $this->em->flush();
        // seedModules writes enabled flags; invalidate caches then migrate only those.
        $this->modules->rediscover();
        $this->migrator->migrate();
        if ($withSampleCustomers) {
            $this->seedCustomers();
            $this->em->flush();
        }
        if (class_exists(\Gereq\Media\Application\MediaImporter::class)) {
            $this->mediaImporter = \Gereq\Media\Application\MediaImporter::fromBasePath($this->em, base_path());
        }

        $forumProfile = strtolower((string) env_value('DEMO_PROFILE', '')) === 'forum';
        if (!$forumProfile && $this->modules->isEnabled('listings')) {
            $brokers = $this->seedBrokers();
            $samples = array_merge($this->coreListingSamples(), $this->moravianSilesianListingSamples());
            $this->seedCities($samples);
            $nearPlaces = $this->seedNearLocationsForSamples($samples);
            $this->seedListings($samples, $nearPlaces, $brokers);
            $this->em->flush();
            $this->seedViewings();
            $this->em->flush();
            $this->seedPortalsDemo();
        }

        if ($withDemo && !$forumProfile) {
            $this->demoSeeder->seed();
        }
        if (RealityV3Seeder::isActive()) {
            $this->realityV3Seeder->seed();
        }
        $this->seedForumDemo();
    }

    private function seedForumDemo(): void
    {
        if (!class_exists(\Modules\Forum\Application\ForumDemoSeeder::class)) {
            return;
        }
        if (!\Modules\Forum\Application\ForumDemoSeeder::isActive()) {
            return;
        }
        try {
            /** @var \Modules\Forum\Application\ForumDemoSeeder $seeder */
            $seeder = gereq()->make(\Modules\Forum\Application\ForumDemoSeeder::class);
            $seeder->seed();
        } catch (\Throwable) {
            // Forum module may not be enabled / DI not ready yet.
        }
    }

    private function seedPortalsDemo(): void
    {
        if (!class_exists(\Modules\Portals\Application\PortalDemoSeeder::class)) {
            return;
        }
        try {
            /** @var \Modules\Portals\Application\PortalDemoSeeder $seeder */
            $seeder = gereq()->make(\Modules\Portals\Application\PortalDemoSeeder::class);
            $seeder->seed();
        } catch (\Throwable) {
            // Portals module may not be enabled / DI not ready yet.
        }
    }

    private function seedPermissions(): void
    {
        $core = [
            ['users.view', 'perm.users.view', 'perm.group.users'],
            ['users.create', 'perm.users.create', 'perm.group.users'],
            ['users.edit', 'perm.users.edit', 'perm.group.users'],
            ['users.delete', 'perm.users.delete', 'perm.group.users'],
            ['users.manage', 'perm.users.manage', 'perm.group.users'],
            ['roles.view', 'perm.roles.view', 'perm.group.roles'],
            ['roles.manage', 'perm.roles.manage', 'perm.group.roles'],
            ['modules.manage', 'perm.modules.manage', 'perm.group.system'],
            ['settings.manage', 'perm.settings.manage', 'perm.group.system'],
            ['audit.view', 'perm.audit.view', 'perm.group.system'],
        ];

        foreach ($core as [$name, $label, $group]) {
            $this->permissions->register($name, $label, $group);
        }

        foreach ($this->permissions->all() as $item) {
            $existing = $this->em->getRepository(Permission::class)->findOneBy(['name' => $item['name']]);
            if ($existing === null) {
                $this->em->persist(new Permission($item['name'], $item['label'], $item['group']));
            }
        }

        $this->em->flush();
    }

    private function seedRoles(): void
    {
        $this->ensureRole('superadmin', 'Superadmin', 'Plný přístup ke všemu (včetně uživatelů a rolí).', ['administrator']);
        $this->ensureRole('admin', 'Admin', 'Plný přístup ke všemu (developer) — včetně modulů, rolí a systémových částí.', ['manager']);
        $this->ensureRole('admin-client', 'Admin klient', 'Plný provozní přístup jako superadmin, bez zapínání a vypínání modulů.', []);
        $this->ensureRole('editor', 'Editor', 'Úpravy obsahu bez správy uživatelů.', []);
        $this->ensureRole('view', 'View', 'Pouze čtení.', ['viewer']);
        $this->ensureRole('broker', 'Makléř', 'Vlastní kalendář, prohlídky, nabídky a CRM dealy přiřazeného makléře.', []);

        $this->em->flush();

        $admin = $this->em->getRepository(Role::class)->findOneBy(['name' => 'admin']);
        $adminClient = $this->em->getRepository(Role::class)->findOneBy(['name' => 'admin-client']);
        $editor = $this->em->getRepository(Role::class)->findOneBy(['name' => 'editor']);
        $view = $this->em->getRepository(Role::class)->findOneBy(['name' => 'view']);
        $broker = $this->em->getRepository(Role::class)->findOneBy(['name' => 'broker']);

        $all = $this->em->getRepository(Permission::class)->findAll();
        // Admin is privileged (isPrivileged); permissions are still synced for UI/DB consistency.
        if ($admin instanceof Role) {
            foreach ($all as $permission) {
                $admin->givePermission($permission);
            }
        }
        if ($adminClient instanceof Role) {
            foreach ($all as $permission) {
                $name = $permission->name();
                if (!str_starts_with($name, 'modules.') && !str_starts_with($name, 'gereq.')) {
                    $adminClient->givePermission($permission);
                }
            }
        }
        if ($editor instanceof Role) {
            foreach ($all as $permission) {
                if (str_ends_with($permission->name(), '.view') || str_ends_with($permission->name(), '.edit') || str_ends_with($permission->name(), '.create')) {
                    if (!str_starts_with($permission->name(), 'users.') && !str_starts_with($permission->name(), 'modules.') && !str_starts_with($permission->name(), 'roles.') && !str_starts_with($permission->name(), 'settings.')) {
                        $editor->givePermission($permission);
                    }
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
        if ($broker instanceof Role) {
            $brokerPerms = [
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
            $byName = [];
            foreach ($all as $permission) {
                $byName[$permission->name()] = $permission;
            }
            $synced = [];
            foreach ($brokerPerms as $name) {
                if (isset($byName[$name])) {
                    $synced[] = $byName[$name];
                }
            }
            $broker->syncPermissions(...$synced);
        }
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

    /**
     * @param array{name: string, email: string, password: string, role?: string} $admin
     */
    private function seedAdmin(array $admin): void
    {
        $email = strtolower(trim((string) ($admin['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Admin e-mail is required to bootstrap the first user.');
        }
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing !== null) {
            return;
        }

        $password = (string) ($admin['password'] ?? '');
        if ($password === '') {
            throw new \InvalidArgumentException('Admin password is required to bootstrap the first user.');
        }

        $user = new User(
            trim((string) ($admin['name'] ?? '')) !== '' ? trim((string) $admin['name']) : 'Superadmin',
            $email,
            $this->hasher->hash($password),
        );

        $roleName = strtolower(trim((string) ($admin['role'] ?? 'superadmin'))) ?: 'superadmin';
        if (RealityV3Seeder::isActive()) {
            $roleName = 'admin-client';
        }
        $adminRole = $this->em->getRepository(Role::class)->findOneBy(['name' => $roleName])
            ?? $this->em->getRepository(Role::class)->findOneBy(['name' => 'superadmin']);
        if ($adminRole instanceof Role) {
            $user->assignRole($adminRole);
        }

        $this->em->persist($user);
    }

    private function seedModules(): void
    {
        // KdeAco: jen portálové moduly (ne RealEstates balík).
        $defaultEnabled = [
            'cms',
            'newsletter',
            'webplus',
            'webplus_ai',
            'events',
            'authorization',
        ];
        $profile = strtolower((string) env_value('DEMO_PROFILE', ''));
        if ($profile === 'forum') {
            // config/module_groups.php → forum
            $defaultEnabled = [
                'forum',
                'newsletter',
            ];
        }
        if (RealityV3Seeder::isActive()) {
            $defaultEnabled = array_values(array_unique(array_merge($defaultEnabled, RealityV3Seeder::MODULES)));
            $defaultEnabled = array_values(array_diff($defaultEnabled, RealityV3Seeder::MODULES_OFF));
        }

        foreach ($this->modules->discover() as $manifest) {
            $row = $this->em->find(InstalledModule::class, $manifest->id);
            $shouldEnable = in_array($manifest->id, $defaultEnabled, true);
            $needsFootprint = $row === null
                || $row->footprint() === null
                || $row->version() !== $manifest->version;

            if ($row === null) {
                $row = new InstalledModule($manifest->id, $manifest->version, $shouldEnable);
                $this->em->persist($row);
            } elseif ($shouldEnable && !$row->enabled()) {
                $row->enable();
                $row->setVersion($manifest->version);
            } elseif (!$shouldEnable && $row->enabled()) {
                $row->disable();
            } else {
                $row->setVersion($manifest->version);
            }

            if ($needsFootprint) {
                $fp = ModuleFootprint::ofDirectory($manifest->path);
                $row->setFootprint($fp !== '' ? $fp : null);
            }

            foreach ($manifest->permissions as $item) {
                $name = (string) ($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $existing = $this->em->getRepository(Permission::class)->findOneBy(['name' => $name]);
                if ($existing === null) {
                    $this->em->persist(new Permission(
                        $name,
                        (string) ($item['label'] ?? $name),
                        (string) ($item['group'] ?? $manifest->name),
                    ));
                }
            }
        }
    }

    private function seedCustomers(): void
    {
        if (!class_exists(\Gereq\Customers\Domain\Customer::class)) {
            return;
        }

        $repo = $this->em->getRepository(\Gereq\Customers\Domain\Customer::class);
        if ($repo->count([]) > 0) {
            return;
        }

        $rows = [
            ['Martin Svoboda', 'martin.svoboda@gereq.cz', '+420 777 214 890', 'GEREQ s.r.o.', 'Hlavní kontakt pro interní projekty.', 'active'],
            ['Petra Dvořáková', 'petra.dvorakova@moravia.cz', '+420 731 445 112', 'Moravia IT s.r.o.', 'Zájem o rozšíření spolupráce v Q4.', 'active'],
            ['Tomáš Horák', 'tomas.horak@brnensoft.cz', '+420 608 332 019', 'Brněnsoft a.s.', null, 'lead'],
            ['Eva Králová', 'eva.kralova@plzensky-pivovar.cz', '+420 724 901 338', 'Plzeňský pivovar', 'Fakturace přes nákupní oddělení.', 'active'],
            ['Jakub Němec', 'jakub.nemec@seznam.cz', '+420 775 010 447', null, 'Osobní živnost, faktury na rodné číslo.', 'inactive'],
        ];

        foreach ($rows as [$name, $email, $phone, $company, $note, $status]) {
            $customer = new \Gereq\Customers\Domain\Customer($name, $email);
            $customer->setPhone($phone);
            $customer->setCompany($company);
            $customer->setNote($note);
            $customer->setStatus($status);
            $this->em->persist($customer);
        }
    }

    /**
     * @return list<\Modules\Listings\Domain\Broker>
     */
    private function seedBrokers(): array
    {
        if (!class_exists(\Modules\Listings\Domain\Broker::class)) {
            return [];
        }

        $repo = $this->em->getRepository(\Modules\Listings\Domain\Broker::class);
        $defs = [
            [
                'first' => 'Jana',
                'last' => 'Svobodová',
                'email' => 'jana.svobodova@realestates.cz',
                'phone' => '+420 777 123 456',
                'position' => 'Senior makléřka',
                'skills' => 'Prodej bytů, Praha',
                'description' => 'Specializace na byty a rodinné domy v Praze a okolí.',
                'photo' => 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=400',
                'sort' => 1,
            ],
            [
                'first' => 'Martin',
                'last' => 'Černý',
                'email' => 'martin.cerny@realestates.cz',
                'phone' => '+420 777 880 221',
                'position' => 'Makléř – Ostravsko',
                'skills' => 'Prodej a pronájem, Ostrava, Havířov, Karviná',
                'description' => 'Lokální specialista na Moravskoslezský kraj, byty i rodinné domy.',
                'photo' => 'https://images.unsplash.com/photo-1560250097-0b93528c311a?w=400',
                'sort' => 2,
            ],
            [
                'first' => 'Petra',
                'last' => 'Horáková',
                'email' => 'petra.horakova@realestates.cz',
                'phone' => '+420 777 880 334',
                'position' => 'Makléřka – Opavsko a Beskydy',
                'skills' => 'Opava, Frýdek-Místek, Nový Jičín, chaty',
                'description' => 'Pomáhá s prodejem nemovitostí v Opavě, Frýdku-Místku a okolí Beskyd.',
                'photo' => 'https://images.unsplash.com/photo-1580489944761-15a19d654956?w=400',
                'sort' => 3,
            ],
        ];

        $brokers = [];
        foreach ($defs as $def) {
            $broker = $repo->findOneBy(['email' => $def['email']]);
            if (!$broker instanceof \Modules\Listings\Domain\Broker) {
                $broker = new \Modules\Listings\Domain\Broker($def['first'], $def['last'], $def['email']);
                $this->em->persist($broker);
            }
            $broker->setPhone($def['phone']);
            $broker->setPosition($def['position']);
            $broker->setSkills($def['skills']);
            $broker->setDescription($def['description']);
            $photoUrl = $def['photo'];
            if ($this->mediaImporter !== null) {
                $asset = $this->mediaImporter->importFromUrl(
                    $def['photo'],
                    $def['first'] . ' ' . $def['last'],
                    'Foto makléře ' . $def['first'] . ' ' . $def['last'],
                );
                if ($asset !== null) {
                    $photoUrl = $this->publicMediaUrl($asset->id());
                }
            }
            $broker->setPhotoUrl($photoUrl);
            $broker->setActive(true);
            $broker->setSortOrder($def['sort']);
            $brokers[] = $broker;
        }
        $this->em->flush();

        return $brokers;
    }

    /**
     * @param list<array<string, mixed>> $samples
     */
    private function seedCities(array $samples): void
    {
        if (!class_exists(\Modules\Listings\Domain\City::class)) {
            return;
        }

        $images = [
            'Praha' => 'https://images.unsplash.com/photo-1541849546-216549ae216d?w=900&q=70',
            'Brno' => 'https://images.unsplash.com/photo-1464938050520-ef2270bb8ce8?w=900&q=70',
            'Ostrava' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=900&q=70',
            'Plzeň' => 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?w=900&q=70',
            'Havířov' => 'https://images.unsplash.com/photo-1449824913935-59a10b8d2000?w=900&q=70',
            'Karviná' => 'https://images.unsplash.com/photo-1477959858617-67f85cf4f1df?w=900&q=70',
            'Opava' => 'https://images.unsplash.com/photo-1480714378408-67cf0d13bc1b?w=900&q=70',
            'Frýdek-Místek' => 'https://images.unsplash.com/photo-1519501025264-65ba15a82390?w=900&q=70',
            'Třinec' => 'https://images.unsplash.com/photo-1496568816309-51d7c20e3b21?w=900&q=70',
            'Nový Jičín' => 'https://images.unsplash.com/photo-1467269204594-9661b134dd2b?w=900&q=70',
            'Rýmařov' => 'https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=900&q=70',
        ];
        $fallback = 'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=900&q=70';

        $names = [];
        foreach ($samples as $row) {
            $city = trim((string) ($row['city'] ?? ''));
            if ($city !== '') {
                $names[$city] = true;
            }
        }
        foreach (array_keys($images) as $extra) {
            $names[$extra] = true;
        }

        $repo = $this->em->getRepository(\Modules\Listings\Domain\City::class);
        $order = 0;
        foreach (array_keys($names) as $name) {
            $order++;
            $city = $repo->findOneBy(['name' => $name]);
            if (!$city instanceof \Modules\Listings\Domain\City) {
                $city = new \Modules\Listings\Domain\City($name);
                $this->em->persist($city);
            }

            $source = $images[$name] ?? $fallback;
            $imageUrl = $source;
            if ($this->mediaImporter !== null) {
                $asset = $this->mediaImporter->importFromUrl(
                    $source,
                    'Město ' . $name,
                    $name,
                );
                if ($asset !== null) {
                    $imageUrl = $this->publicMediaUrl($asset->id());
                }
            }

            $city->setImageUrl($imageUrl);
            $city->setSortOrder($order);
            $city->setActive(true);
        }

        $this->em->flush();
    }

    /**
     * @param list<array<string, mixed>> $samples
     * @return list<\Modules\Listings\Domain\NearLocation>
     */
    private function seedNearLocationsForSamples(array $samples): array
    {
        if (!class_exists(\Modules\Listings\Domain\NearLocation::class)) {
            return [];
        }

        $cities = [];
        foreach ($samples as $row) {
            $city = (string) $row['city'];
            if (!isset($cities[$city])) {
                $cities[$city] = [
                    'lat' => (float) $row['lat'],
                    'lng' => (float) $row['lng'],
                ];
            }
        }

        // Fill in map centers when the first listing in a city would not be representative.
        $cityCenters = [
            'Praha' => [50.0755, 14.4378],
            'Brno' => [49.2105, 16.5750],
            'Ostrava' => [49.8209, 18.2625],
            'Havířov' => [49.7798, 18.4369],
            'Karviná' => [49.8540, 18.5417],
            'Orlová' => [49.8452, 18.4301],
            'Bohumín' => [49.9041, 18.3576],
            'Frýdek-Místek' => [49.6853, 18.3484],
            'Třinec' => [49.6776, 18.6708],
            'Český Těšín' => [49.7461, 18.6261],
            'Opava' => [49.9387, 17.9026],
            'Nový Jičín' => [49.5944, 18.0103],
            'Kopřivnice' => [49.5995, 18.1448],
            'Frenštát pod Radhoštěm' => [49.5483, 18.2108],
            'Příbor' => [49.6409, 18.1450],
            'Bruntál' => [49.9884, 17.4647],
            'Krnov' => [50.0897, 17.7029],
            'Fulnek' => [49.7125, 17.9031],
            'Vítkov' => [49.7744, 17.7493],
            'Hlučín' => [49.8960, 18.1920],
            'Studénka' => [49.7234, 18.0784],
            'Rýmařov' => [49.9318, 17.2718],
        ];
        foreach ($cityCenters as $city => [$lat, $lng]) {
            $cities[$city] = ['lat' => $lat, 'lng' => $lng];
        }

        $templates = [
            ['name' => 'Městský úřad', 'category' => 'office', 'dlat' => 0.0018, 'dlng' => 0.0012],
            ['name' => 'Základní škola', 'category' => 'school', 'dlat' => -0.0021, 'dlng' => 0.0015],
            ['name' => 'Mateřská škola', 'category' => 'kindergarten', 'dlat' => 0.0012, 'dlng' => -0.0020],
            ['name' => 'Autobusové nádraží', 'category' => 'bus', 'dlat' => -0.0015, 'dlng' => -0.0018],
            ['name' => 'Tramvajová / MHD zastávka', 'category' => 'tram', 'dlat' => 0.0008, 'dlng' => 0.0024],
            ['name' => 'Supermarket', 'category' => 'supermarket', 'dlat' => -0.0028, 'dlng' => 0.0006],
            ['name' => 'Městský park', 'category' => 'park', 'dlat' => 0.0026, 'dlng' => -0.0011],
            ['name' => 'Poliklinika', 'category' => 'health', 'dlat' => -0.0009, 'dlng' => 0.0028],
            ['name' => 'Česká pošta', 'category' => 'post', 'dlat' => 0.0016, 'dlng' => -0.0026],
            ['name' => 'Sportovní areál', 'category' => 'sport', 'dlat' => -0.0030, 'dlng' => -0.0014],
        ];

        $repo = $this->em->getRepository(\Modules\Listings\Domain\NearLocation::class);
        $places = [];
        $sort = 1;
        foreach ($cities as $city => $coords) {
            foreach ($templates as $tpl) {
                $name = $tpl['name'] . ' – ' . $city;
                $place = $repo->findOneBy(['name' => $name, 'city' => $city]);
                if (!$place instanceof \Modules\Listings\Domain\NearLocation) {
                    $place = new \Modules\Listings\Domain\NearLocation($name, $tpl['category']);
                    $this->em->persist($place);
                }
                $place->setCategory($tpl['category']);
                $place->setCity($city);
                $place->setAddress($tpl['name'] . ', ' . $city);
                $place->setLatitude($coords['lat'] + $tpl['dlat']);
                $place->setLongitude($coords['lng'] + $tpl['dlng']);
                $place->setActive(true);
                $place->setSortOrder($sort++);
                $places[] = $place;
            }
        }
        $this->em->flush();

        return $places;
    }

    /** @return list<array<string, mixed>> */
    private function coreListingSamples(): array
    {
        return [
            [
                'title' => 'Byt 3+kk Vinohrady',
                'slug' => 'byt-3kk-vinohrady',
                'offer' => 'sale',
                'type' => 'apartment',
                'price' => '8950000',
                'area' => 78.0,
                'rooms' => 3,
                'floor' => 3,
                'floors' => 5,
                'city' => 'Praha',
                'city_part' => 'Vinohrady',
                'street' => 'Korunní 120',
                'zip' => '10100',
                'lat' => 50.0758,
                'lng' => 14.4478,
                'desc' => 'Světlý byt po rekonstrukci, výhled do vnitrobloku, sklep a výtah.',
                'images' => $this->pickGallery([
                    'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=1200',
                    'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=1200',
                    'https://images.unsplash.com/photo-1484154218962-a197022b5858?w=1200',
                    'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=1200',
                    'https://images.unsplash.com/photo-1493809842364-78817add7ffb?w=1200',
                    'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=1200',
                    'https://images.unsplash.com/photo-1600566753190-17f0baa2a6c3?w=1200',
                    'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?w=1200',
                ], 0, 8),
                'featured' => true,
                'amenities' => ['balcony' => true, 'cellar' => true, 'elevator' => true],
                'energy' => 'B',
                'broker' => 0,
            ],
            [
                'title' => 'Rodinný dům Brno-Žabovřesky',
                'slug' => 'dum-brno-zabovresky',
                'offer' => 'sale',
                'type' => 'house',
                'price' => '12900000',
                'area' => 160.0,
                'rooms' => 5,
                'floor' => null,
                'floors' => 2,
                'city' => 'Brno',
                'city_part' => 'Žabovřesky',
                'street' => 'Údolní 45',
                'zip' => '61600',
                'lat' => 49.2105,
                'lng' => 16.5750,
                'desc' => 'Samostatný dům se zahradou, garáž, klidná lokalita.',
                'images' => $this->pickGallery([
                    'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1200',
                    'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=1200',
                    'https://images.unsplash.com/photo-1605276374104-dee2a0ed3cd6?w=1200',
                    'https://images.unsplash.com/photo-1600566753190-17f0baa2a6c3?w=1200',
                    'https://images.unsplash.com/photo-1613490493576-7fde63acd811?w=1200',
                    'https://images.unsplash.com/photo-1613977257363-707ba9348227?w=1200',
                    'https://images.unsplash.com/photo-1605146769289-440113cc3d00?w=1200',
                    'https://images.unsplash.com/photo-1570129477492-45c003edd2be?w=1200',
                ], 1, 8),
                'featured' => true,
                'amenities' => ['garage' => true, 'terrace' => true, 'cellar' => true],
                'energy' => 'C',
                'parking' => 2,
                'broker' => 0,
            ],
            [
                'title' => 'Pronájem 2+1 Karlín',
                'slug' => 'pronajem-2-1-karlin',
                'offer' => 'rent',
                'type' => 'apartment',
                'price' => '28500',
                'area' => 54.0,
                'rooms' => 2,
                'floor' => 2,
                'floors' => 6,
                'city' => 'Praha',
                'city_part' => 'Karlín',
                'street' => 'Thámova 8',
                'zip' => '18600',
                'lat' => 50.0923,
                'lng' => 14.4508,
                'desc' => 'Zařízený byt blízko metra B, ihned k nastěhování.',
                'images' => $this->pickGallery([
                    'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=1200',
                    'https://images.unsplash.com/photo-1493809842364-78817add7ffb?w=1200',
                    'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?w=1200',
                    'https://images.unsplash.com/photo-1493663284031-b7e3aefcae8e?w=1200',
                    'https://images.unsplash.com/photo-1486304873000-235643847519?w=1200',
                    'https://images.unsplash.com/photo-1505691723518-36a5ac3be353?w=1200',
                    'https://images.unsplash.com/photo-1554995207-c18c203602cb?w=1200',
                ], 2, 8),
                'featured' => false,
                'amenities' => ['elevator' => true, 'balcony' => true],
                'energy' => 'B',
                'broker' => 0,
            ],
        ];
    }

    /**
     * @param list<string> $pool
     * @return list<string>
     */
    private function pickGallery(array $pool, int $seed, int $count = 8): array
    {
        $n = count($pool);
        if ($n === 0) {
            return [];
        }
        $count = max(1, min($count, $n));
        $start = ($seed * 3) % $n;
        $out = [];
        for ($k = 0; $k < $count; ++$k) {
            $out[] = $pool[($start + $k) % $n];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function moravianSilesianListingSamples(): array
    {
        $imgs = [
            'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=1200',
            'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=1200',
            'https://images.unsplash.com/photo-1484154218962-a197022b5858?w=1200',
            'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=1200',
            'https://images.unsplash.com/photo-1493809842364-78817add7ffb?w=1200',
            'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=1200',
            'https://images.unsplash.com/photo-1605276374104-dee2a0ed3cd6?w=1200',
            'https://images.unsplash.com/photo-1600566753190-17f0baa2a6c3?w=1200',
            'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?w=1200',
            'https://images.unsplash.com/photo-1600607687644-c7171b42498f?w=1200',
            'https://images.unsplash.com/photo-1600210492486-724fe5c67fb0?w=1200',
            'https://images.unsplash.com/photo-1600566753086-00f18fb6b3ea?w=1200',
            'https://images.unsplash.com/photo-1600573472592-401b489a3cdc?w=1200',
            'https://images.unsplash.com/photo-1600585154526-990dced4db0d?w=1200',
            'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=1200',
            'https://images.unsplash.com/photo-1560184897-ae75f418493e?w=1200',
            'https://images.unsplash.com/photo-1560185127-6ed189bf02f4?w=1200',
            'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?w=1200',
            'https://images.unsplash.com/photo-1493663284031-b7e3aefcae8e?w=1200',
            'https://images.unsplash.com/photo-1486304873000-235643847519?w=1200',
            'https://images.unsplash.com/photo-1505691723518-36a5ac3be353?w=1200',
            'https://images.unsplash.com/photo-1554995207-c18c203602cb?w=1200',
            'https://images.unsplash.com/photo-1613490493576-7fde63acd811?w=1200',
            'https://images.unsplash.com/photo-1613977257363-707ba9348227?w=1200',
            'https://images.unsplash.com/photo-1605146769289-440113cc3d00?w=1200',
            'https://images.unsplash.com/photo-1570129477492-45c003edd2be?w=1200',
            'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1200',
            'https://images.unsplash.com/photo-1600607687920-4e2a09cf159d?w=1200',
            'https://images.unsplash.com/photo-1600566753086-00f18fb6b3ea?w=1200',
            'https://images.unsplash.com/photo-1600585154363-67eb9e2e2099?w=1200',
            'https://images.unsplash.com/photo-1600210492493-0946911123ea?w=1200',
            'https://images.unsplash.com/photo-1560184897-ae75f418493e?w=1200',
            'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=1200',
            'https://images.unsplash.com/photo-1484154218962-a197022b5858?w=1200',
            'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=1200',
            'https://images.unsplash.com/photo-1493809842364-78817add7ffb?w=1200',
        ];

        $rows = [
            ['Byt 3+1 Ostrava-Poruba', 'byt-3-1-ostrava-poruba', 'sale', 'apartment', '2890000', 72.0, 3, 4, 8, 'Ostrava', 'Poruba', 'Hlavní třída 12', '70800', 49.8275, 18.1702, 'Prostorný byt u kampusu VŠB, výtah a sklep.', true, ['balcony' => true, 'elevator' => true, 'cellar' => true], 'C', null, 1],
            ['Byt 2+kk Ostrava centrum', 'byt-2kk-ostrava-centrum', 'sale', 'apartment', '2450000', 48.0, 2, 3, 6, 'Ostrava', 'Moravská Ostrava', 'Nádražní 28', '70200', 49.8352, 18.2921, 'Po rekonstrukci, blízko náměstí a MHD.', true, ['elevator' => true], 'B', null, 1],
            ['Pronájem 1+1 Ostrava-Zábřeh', 'pronajem-1-1-ostrava-zabreh', 'rent', 'apartment', '12500', 38.0, 1, 2, 4, 'Ostrava', 'Zábřeh', 'Výškovická 40', '70030', 49.7948, 18.2315, 'Kompaktní byt k nastěhování, klidný vnitroblok.', false, ['balcony' => true], 'D', null, 1],
            ['Rodinný dům Slezská Ostrava', 'dum-slezska-ostrava', 'sale', 'house', '4590000', 140.0, 5, null, 2, 'Ostrava', 'Slezská Ostrava', 'Muglinovská 8', '71000', 49.8512, 18.3024, 'Dům se zahradou a garáží, výhled na město.', true, ['garage' => true, 'terrace' => true, 'cellar' => true], 'D', 2, 1],
            ['Byt 4+1 Ostrava-Hrabůvka', 'byt-4-1-ostrava-hrabuvka', 'sale', 'apartment', '3190000', 86.0, 4, 5, 8, 'Ostrava', 'Hrabůvka', 'Dr. Martínka 15', '70030', 49.7881, 18.2548, 'Velký byt pro rodinu, panel po revitalizaci.', false, ['balcony' => true, 'elevator' => true, 'cellar' => true], 'C', null, 1],
            ['Byt 3+kk Havířov', 'byt-3kk-havirov', 'sale', 'apartment', '2290000', 68.0, 3, 3, 6, 'Havířov', 'Město', 'Hlavní třída 64', '73601', 49.7809, 18.4305, 'Slunný byt v centru Havířova, obchodní zóna nablízku.', true, ['balcony' => true, 'elevator' => true], 'C', null, 1],
            ['Pronájem 2+1 Havířov-Šumbark', 'pronajem-2-1-havirov-sumbark', 'rent', 'apartment', '11900', 56.0, 2, 1, 4, 'Havířov', 'Šumbark', 'Studentská 9', '73601', 49.7688, 18.4582, 'Klidná lokalita, škola a zastávka MHD u domu.', false, ['cellar' => true], 'D', null, 1],
            ['Byt 2+1 Karviná-Fryštát', 'byt-2-1-karvina', 'sale', 'apartment', '1690000', 58.0, 2, 2, 5, 'Karviná', 'Fryštát', 'Masarykovo nám. 3', '73301', 49.8548, 18.5410, 'Byt v historickém centru, po částečné rekonstrukci.', false, ['cellar' => true], 'E', null, 1],
            ['Rodinný dům Orlová-Lutyně', 'dum-orlova-lutyne', 'sale', 'house', '3490000', 125.0, 4, null, 2, 'Orlová', 'Lutyně', 'Energetiků 22', '73514', 49.8515, 18.4220, 'Samostatný dům, pozemek 620 m², nová střecha.', true, ['garage' => true, 'terrace' => true], 'C', 2, 1],
            ['Byt 3+1 Bohumín', 'byt-3-1-bohumin', 'sale', 'apartment', '1990000', 74.0, 3, 4, 7, 'Bohumín', 'Nový Bohumín', 'Štefánikova 11', '73581', 49.9048, 18.3552, 'Byt u nádraží, dobré spojení do Ostravy i Polska.', false, ['balcony' => true, 'elevator' => true], 'C', null, 1],
            ['Byt 3+kk Frýdek-Místek', 'byt-3kk-frydek-mistek', 'sale', 'apartment', '3690000', 71.0, 3, 2, 5, 'Frýdek-Místek', 'Frýdek', 'Hlavní 45', '73801', 49.6859, 18.3512, 'Moderní byt blízko zámku a nákupní zóny.', true, ['balcony' => true, 'elevator' => true], 'B', null, 2],
            ['Pronájem 2+kk Frýdek-Místek', 'pronajem-2kk-frydek-mistek', 'rent', 'apartment', '14500', 52.0, 2, 3, 6, 'Frýdek-Místek', 'Místek', 'Palackého 18', '73801', 49.6785, 18.3398, 'Zařízený byt, ideální pro pár nebo malou rodinu.', false, ['elevator' => true], 'C', null, 2],
            ['Byt 2+1 Třinec', 'byt-2-1-trinec', 'sale', 'apartment', '2590000', 61.0, 2, 5, 9, 'Třinec', 'Lyžbice', 'Těšínská 120', '73961', 49.6779, 18.6701, 'Byt s výhledem na Beskydy, výtah a lodžie.', true, ['balcony' => true, 'elevator' => true], 'C', null, 2],
            ['Rodinný dům Český Těšín', 'dum-cesky-tesin', 'sale', 'house', '4890000', 155.0, 5, null, 2, 'Český Těšín', 'Svibice', 'Hlavní 7', '73701', 49.7388, 18.6215, 'Prostorný dům u hranic, garáž a zahrada.', false, ['garage' => true, 'cellar' => true, 'terrace' => true], 'D', 2, 2],
            ['Byt 3+1 Opava-centrum', 'byt-3-1-opava', 'sale', 'apartment', '3290000', 82.0, 3, 2, 4, 'Opava', 'Město', 'Ostrožná 14', '74601', 49.9389, 17.9022, 'Reprezentativní byt v centru Opavy, vysoké stropy.', true, ['cellar' => true], 'D', null, 2],
            ['Pronájem 1+kk Opava-Kateřinky', 'pronajem-1kk-opava-katerinky', 'rent', 'apartment', '9900', 32.0, 1, 1, 3, 'Opava', 'Kateřinky', 'Kylešovská 5', '74705', 49.9521, 17.8874, 'Startovací bydlení, nízké náklady.', false, [], 'E', null, 2],
            ['Byt 4+kk Nový Jičín', 'byt-4kk-novy-jicin', 'sale', 'apartment', '3990000', 95.0, 4, 3, 5, 'Nový Jičín', 'Centrum', 'Masarykovo nám. 12', '74101', 49.5948, 18.0108, 'Velký byt na náměstí, po kompletní rekonstrukci.', true, ['balcony' => true, 'elevator' => true], 'B', null, 2],
            ['Rodinný dům Kopřivnice', 'dum-koprivnice', 'sale', 'house', '4290000', 148.0, 5, null, 2, 'Kopřivnice', 'Centrum', 'Štefánikova 33', '74221', 49.5998, 18.1455, 'Dům u Tatra muzea, pozemek 780 m².', false, ['garage' => true, 'terrace' => true], 'C', 2, 2],
            ['Chata Frenštát pod Radhoštěm', 'chata-frenstat', 'sale', 'house', '2790000', 68.0, 3, null, 1, 'Frenštát pod Radhoštěm', 'Centrum', 'Rožnovská 4', '74401', 49.5478, 18.2125, 'Rekreační objekt s pozemkem, lyžařské areály nablízku.', true, ['terrace' => true], 'G', 1, 2],
            ['Byt 2+kk Příbor', 'byt-2kk-pribor', 'sale', 'apartment', '2190000', 49.0, 2, 2, 3, 'Příbor', 'Centrum', 'náměstí Sigmunda Freuda 2', '74258', 49.6405, 18.1442, 'Byt v rodném městě Freuda, klid a historie.', false, ['cellar' => true], 'D', null, 2],
            ['Byt 3+1 Bruntál', 'byt-3-1-bruntal', 'sale', 'apartment', '1890000', 76.0, 3, 3, 5, 'Bruntál', 'Centrum', 'nám. Míru 9', '79201', 49.9889, 17.4651, 'Byt v podhůří Jeseníků, levné bydlení.', false, ['balcony' => true, 'elevator' => true], 'D', null, 2],
            ['Rodinný dům Krnov', 'dum-krnov', 'sale', 'house', '3590000', 132.0, 4, null, 2, 'Krnov', 'Centrum', 'Hlavní 21', '79401', 50.0901, 17.7035, 'Dům u parku, zateplení a nová okna.', true, ['garage' => true, 'cellar' => true], 'C', 1, 2],
            ['Byt 2+1 Fulnek', 'byt-2-1-fulnek', 'sale', 'apartment', '1590000', 55.0, 2, 1, 2, 'Fulnek', 'Centrum', 'Masarykova 6', '74245', 49.7129, 17.9035, 'Útulný byt v malebném městečku.', false, ['cellar' => true], 'E', null, 1],
            ['Rodinný dům Vítkov', 'dum-vitkov', 'sale', 'house', '2490000', 118.0, 4, null, 1, 'Vítkov', 'Centrum', 'Opavská 15', '74901', 49.7748, 17.7498, 'Přízemní dům se zahradou, klidná lokalita.', false, ['terrace' => true, 'garage' => true], 'D', 1, 1],
            ['Byt 3+kk Hlučín', 'byt-3kk-hlucin', 'sale', 'apartment', '2790000', 70.0, 3, 2, 4, 'Hlučín', 'Centrum', 'Mírové nám. 8', '74801', 49.8965, 18.1925, 'Byt blízko Hlučínského jezera, výtah.', true, ['balcony' => true, 'elevator' => true], 'C', null, 1],
            ['Pronájem 2+1 Studénka', 'pronajem-2-1-studenka', 'rent', 'apartment', '10500', 58.0, 2, 3, 5, 'Studénka', 'Centrum', 'nám. Republiky 3', '74213', 49.7238, 18.0789, 'Dostupné bydlení u železničního uzlu.', false, ['elevator' => true], 'D', null, 1],
            ['Chalupa Rýmařov', 'chalupa-rymarov', 'sale', 'house', '2190000', 95.0, 3, null, 1, 'Rýmařov', 'Centrum', 'Jelení 2', '79301', 49.9322, 17.2725, 'Chalupa v Jeseníkách, ideální na rekreaci i bydlení.', true, ['terrace' => true, 'cellar' => true], 'G', 1, 2],
        ];

        $out = [];
        foreach ($rows as $i => $r) {
            [$title, $slug, $offer, $type, $price, $area, $rooms, $floor, $floors, $city, $part, $street, $zip, $lat, $lng, $desc, $featured, $amenities, $energy, $parking, $broker] = $r;
            $out[] = [
                'title' => $title,
                'slug' => $slug,
                'offer' => $offer,
                'type' => $type,
                'price' => $price,
                'area' => $area,
                'rooms' => $rooms,
                'floor' => $floor,
                'floors' => $floors,
                'city' => $city,
                'city_part' => $part,
                'street' => $street,
                'zip' => $zip,
                'lat' => $lat,
                'lng' => $lng,
                'desc' => $this->buildListingDescription([
                    'title' => $title,
                    'slug' => $slug,
                    'offer' => $offer,
                    'type' => $type,
                    'area' => $area,
                    'rooms' => $rooms,
                    'floor' => $floor,
                    'floors' => $floors,
                    'city' => $city,
                    'city_part' => $part,
                    'street' => $street,
                    'blurb' => $desc,
                    'amenities' => $amenities,
                    'energy' => $energy,
                ]),
                'images' => $this->pickGallery($imgs, $i + 3, 8),
                'featured' => $featured,
                'amenities' => $amenities,
                'energy' => $energy,
                'parking' => $parking,
                'broker' => $broker,
            ];
        }

        return $out;
    }

    /**
     * Formatted description for the frontend (paragraphs, ## headings, bullets, **bold**).
     *
     * @param array{
     *   title: string,
     *   slug: string,
     *   offer: string,
     *   type: string,
     *   area: float|int,
     *   rooms: int|null,
     *   floor: int|null,
     *   floors: int|null,
     *   city: string,
     *   city_part: string,
     *   street: string,
     *   blurb: string,
     *   amenities: array<string, bool>,
     *   energy: string|null
     * } $row
     */
    private function buildListingDescription(array $row): string
    {
        $custom = $this->customListingDescriptions();
        if (isset($custom[$row['slug']])) {
            return $custom[$row['slug']];
        }

        $offerLabel = $row['offer'] === 'rent' ? 'k pronájmu' : 'k prodeji';
        $typeLabel = match ($row['type']) {
            'house' => 'rodinný dům',
            'land' => 'pozemek',
            'commercial' => 'komerční prostor',
            default => 'byt',
        };
        $amenityLines = [];
        if (!empty($row['amenities']['balcony'])) {
            $amenityLines[] = 'balkon / lodžie';
        }
        if (!empty($row['amenities']['terrace'])) {
            $amenityLines[] = 'terasa';
        }
        if (!empty($row['amenities']['elevator'])) {
            $amenityLines[] = 'výtah';
        }
        if (!empty($row['amenities']['cellar'])) {
            $amenityLines[] = 'sklep';
        }
        if (!empty($row['amenities']['garage'])) {
            $amenityLines[] = 'garáž';
        }

        $parts = [];
        $parts[] = sprintf(
            'Nabízíme **%s** %s v lokalitě **%s%s**. %s',
            $typeLabel,
            $offerLabel,
            $row['city'],
            $row['city_part'] !== '' ? ' – ' . $row['city_part'] : '',
            rtrim($row['blurb'], '.') . '.'
        );

        $parts[] = '## Dispozice a technické údaje';
        $detail = [];
        if ($row['area']) {
            $detail[] = sprintf('užitná plocha **%s m²**', $row['area']);
        }
        if ($row['rooms']) {
            $detail[] = sprintf('**%s** pokoje / místnosti', $row['rooms']);
        }
        if ($row['floor'] !== null) {
            $detail[] = $row['floors'] !== null
                ? sprintf('patro **%s** z %s', $row['floor'], $row['floors'])
                : sprintf('patro **%s**', $row['floor']);
        }
        if ($row['energy']) {
            $detail[] = sprintf('energetická náročnost **%s**', $row['energy']);
        }
        $parts[] = 'Nemovitost nabízí ' . implode(', ', $detail) . '. Dispozice je praktická a světlo jde do hlavních obytných místností.';

        if ($amenityLines !== []) {
            $parts[] = "## Co je součástí";
            foreach ($amenityLines as $line) {
                $parts[] = '- ' . $line;
            }
        }

        $parts[] = "## Lokalita";
        $parts[] = sprintf(
            'Adresa **%s, %s**. V okolí najdete občanskou vybavenost, MHD a denní služby – ideální volba pro bydlení i investici.',
            $row['street'],
            $row['city']
        );

        $parts[] = "## Proč se ozvat";
        $parts[] = '- aktuální stav nabídky ověříme při prohlídce';
        $parts[] = '- rádi připravíme hypotéční / financování konzultaci';
        $parts[] = '- nezávazná prohlídka po domluvě termínu';

        return implode("\n\n", $parts);
    }

    /** @return array<string, string> */
    private function customListingDescriptions(): array
    {
        return [
            'byt-3kk-hlucin' => <<<'TXT'
Slunný **byt 3+kk** v samém centru Hlučína – pár minut od Mírového náměstí a zároveň v docházkové vzdálenosti k **Hlučínskému jezeru**. Ideální pro rodinu, pár i investici s výnosným pronájmem.

## Dispozice
Byt o užitné ploše **70 m²** je ve **2. patře** čtyřpodlažního domu s **výtahem**. Dispozice 3+kk nabízí oddělené ložnice, světlý obývací pokoj propojený s kuchyní a praktickou předsíň.

- obývací pokoj s kuchyní a jídelním koutem
- dvě ložnice / dětské pokoje
- koupelna + WC
- **balkon** pro ranní kávu i večerní posezení

## Stav a technika
Byt je připravený k nastěhování. Okna jsou plastová, vytápění centrální, energetická náročnost budovy **PENB C**. Společné prostory domu jsou udržované, dům má výtah.

## Lokalita Hlučín
**Mírové nám. 8** – peší dostupnost úřadu, školky, MHD i polikliniky. O víkendu je to kousek k jezeru, cyklostezkám a zeleni. Do Ostravy dojíždíte autem i MHD během desítek minut.

## Pro koho se hodí
- rodiny, které chtějí klidnější město a přitom kontakt s Ostravskem
- investory hledající stabilní lokalitu s poptávkou po nájmu
- zájemce o první větší byt bez nutnosti okamžité rekonstrukce

## Další kroky
Rádi domluvíme prohlídku, připravíme podklady k financování a projdeme si okolní vybavenost přímo na místě. Ozvěte se přes formulář nebo makléři – ozveme se obvykle do jednoho pracovního dne.
TXT,
            'byt-3-1-ostrava-poruba' => <<<'TXT'
Prostorný **byt 3+1** v Ostravě-Porubě blízko kampusu VŠB. Světlé pokoje, **výtah**, **balkon** i **sklep** – praktická volba pro rodinu nebo spolubydlení studentů.

## Dispozice
Užitná plocha **72 m²**, 4. patro z 8. Oddělené pokoje dávají soukromí; kuchyň a obývák lze provozovat jako denní zónu.

## Lokalita
Hlavní třída – MHD, obchody a služby v docházkové vzdálenosti. Snadné spojení do centra Ostravy i na dálnici.

## Shrnutí
- balkon a sklep
- výtah v domě
- PENB **C**
- vhodné i jako investice u univerzity
TXT,
            'dum-slezska-ostrava' => <<<'TXT'
**Rodinný dům** ve Slezské Ostravě se zahradou, **garáží** a **terasou**. Výhled na město, prostor pro rodinu i hobby zónu.

## Co nabízí
Užitná plocha cca **140 m²**, dispozice pro více generací. Na pozemku je místo na grilování, děti i parkování mimo ulici.

## Technika a stav
Dům je připravený k bydlení; energetický štítek **D**. Garáž a sklep usnadní skladování i sezónní věci.

## Lokalita
Muglinovská – klidnější část s rychlým napojením do centra. Ideální, když chcete dům ve městě bez vzdáleného dojíždění.
TXT,
        ];
    }

    /**
     * @param list<array<string, mixed>> $samples
     * @param list<\Modules\Listings\Domain\NearLocation> $nearPlaces
     * @param list<\Modules\Listings\Domain\Broker> $brokers
     */
    private function seedListings(array $samples, array $nearPlaces = [], array $brokers = []): void
    {
        if (!class_exists(\Modules\Listings\Domain\Listing::class)) {
            return;
        }

        $repo = $this->em->getRepository(\Modules\Listings\Domain\Listing::class);

        foreach ($samples as $row) {
            $listing = $repo->findOneBy(['slug' => $row['slug']]);
            if (!$listing instanceof \Modules\Listings\Domain\Listing) {
                $listing = new \Modules\Listings\Domain\Listing($row['title'], $row['slug']);
                $this->em->persist($listing);
            } else {
                $listing->setTitle($row['title']);
            }
            $listing->setOfferType($row['offer']);
            $listing->setPropertyType($row['type']);
            $listing->setPrice(number_format((float) $row['price'], 2, '.', ''));
            $listing->setAreaM2($row['area']);
            $listing->setRooms($row['rooms']);
            $listing->setFloor($row['floor']);
            $listing->setFloors($row['floors']);
            $listing->setCity($row['city']);
            $listing->setCityPart($row['city_part']);
            $listing->setStreet($row['street']);
            $listing->setZip($row['zip']);
            $listing->setAddress($row['street'] . ', ' . $row['city']);
            $listing->setLatitude($row['lat']);
            $listing->setLongitude($row['lng']);
            $listing->setDescription($row['desc']);
            $listing->setFeatured($row['featured']);
            $listing->setBalcony((bool) ($row['amenities']['balcony'] ?? false));
            $listing->setTerrace((bool) ($row['amenities']['terrace'] ?? false));
            $listing->setCellar((bool) ($row['amenities']['cellar'] ?? false));
            $listing->setGarage((bool) ($row['amenities']['garage'] ?? false));
            $listing->setElevator((bool) ($row['amenities']['elevator'] ?? false));
            $listing->setParkingLots($row['parking'] ?? null);
            $listing->setEnergyRating($row['energy']);

            $brokerIndex = (int) ($row['broker'] ?? 0);
            $broker = $brokers[$brokerIndex] ?? ($brokers[0] ?? null);
            if ($broker instanceof \Modules\Listings\Domain\Broker) {
                $listing->setBrokerId($broker->id());
                $listing->setBrokerName($broker->fullName());
                $listing->setBrokerEmail($broker->email());
                $listing->setBrokerPhone($broker->phone());
                $listing->setBrokerPosition($broker->position());
                $listing->setBrokerPhotoUrl($broker->photoUrl());
            }
            $listing->publish();
            $this->em->flush();

            $this->syncListingImages($listing, $row['images'], (string) $row['title']);
            $this->seedListingNearLinks($listing, $nearPlaces, (string) $row['city'], 10);
        }
    }

    /**
     * @param list<string> $sourceUrls
     */
    private function syncListingImages(
        \Modules\Listings\Domain\Listing $listing,
        array $sourceUrls,
        string $title,
    ): void {
        if ($sourceUrls === [] || !class_exists(\Modules\Listings\Domain\ListingImage::class)) {
            return;
        }

        $repo = $this->em->getRepository(\Modules\Listings\Domain\ListingImage::class);
        /** @var list<\Modules\Listings\Domain\ListingImage> $existing */
        $existing = $repo->findBy(['listingId' => $listing->id()]);
        foreach ($existing as $old) {
            $this->em->remove($old);
        }
        $this->em->flush();

        $resolvedUrls = [];
        foreach (array_values($sourceUrls) as $i => $url) {
            $image = null;
            if ($this->mediaImporter !== null && class_exists(\Gereq\Media\Domain\MediaAsset::class)) {
                $asset = $this->mediaImporter->importFromUrl(
                    $url,
                    $title . ' – foto ' . ($i + 1),
                    $title,
                );
                if ($asset !== null) {
                    $image = \Modules\Listings\Domain\ListingImage::fromMedia($listing->id(), $asset->id(), $i);
                    $resolvedUrls[] = $this->publicMediaUrl($asset->id());
                }
            }

            if ($image === null) {
                $image = \Modules\Listings\Domain\ListingImage::fromUrl($listing->id(), $url, $i);
                $resolvedUrls[] = $url;
            }

            if ($i === 0) {
                $image->setPrimary(true);
            }
            $image->setAlt($title);
            $this->em->persist($image);
        }

        if ($resolvedUrls !== []) {
            $listing->setImages($resolvedUrls);
            $listing->setCoverImageUrl($resolvedUrls[0]);
        }
        $this->em->flush();
    }

    private function publicMediaUrl(string $assetId): string
    {
        return '/media/' . $assetId . '/file';
    }

    private function seedViewings(): void
    {
        if (!class_exists(\Modules\Viewings\Domain\Viewing::class)
            || !class_exists(\Modules\Listings\Domain\Listing::class)
            || !class_exists(\Modules\Viewings\Application\ViewingsProvider::class)
        ) {
            return;
        }

        $existing = (int) $this->em->getRepository(\Modules\Viewings\Domain\Viewing::class)->count([]);
        if ($existing > 0) {
            return;
        }

        /** @var list<\Modules\Listings\Domain\Listing> $listings */
        $listings = $this->em->getRepository(\Modules\Listings\Domain\Listing::class)->findBy(
            [],
            ['createdAt' => 'DESC'],
            12,
        );
        if ($listings === []) {
            return;
        }

        $provider = new \Modules\Viewings\Application\ViewingsProvider($this->em);
        $contacts = [
            ['Anna Svobodová', 'anna.svobodova@email.cz', '+420 777 101 202', 'Zájem o odpolední termín.'],
            ['Petr Novák', 'petr.novak@seznam.cz', '+420 603 445 778', 'Chce vidět i sklep.'],
            ['Lucie Horáková', 'lucie.horakova@gmail.com', '+420 731 990 112', 'S hypotečním poradcem.'],
            ['Martin Dvořák', 'martin.dvorak@firma.cz', '+420 608 221 334', null],
            ['Eva Černá', 'eva.cerna@outlook.cz', '+420 724 556 889', 'Preferuje víkend.'],
            ['Tomáš Veselý', 'tomas.vesely@email.cz', '+420 775 333 001', 'Druhá prohlídka.'],
        ];
        $statuses = ['scheduled', 'confirmed', 'requested', 'scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'];

        foreach ($listings as $i => $listing) {
            $contact = $contacts[$i % count($contacts)];
            $status = $statuses[$i % count($statuses)];
            $days = match ($status) {
                'completed', 'cancelled', 'no_show' => -($i + 1),
                'requested' => $i + 2,
                default => $i + 1,
            };
            $hour = 9 + ($i % 7);
            $at = (new \DateTimeImmutable('today'))
                ->modify(sprintf('%+d days', $days))
                ->setTime($hour, $i % 2 === 0 ? 0 : 30);

            $viewing = new \Modules\Viewings\Domain\Viewing(
                $provider->nextNumber(),
                $listing->id(),
                $contact[0],
            );
            $viewing->setStatus($status);
            $viewing->setScheduledAt($at);
            $viewing->setContactEmail($contact[1]);
            $viewing->setContactPhone($contact[2]);
            if ($contact[3] !== null) {
                $viewing->setNote($contact[3]);
            }
            if ($listing->brokerId()) {
                $viewing->setBrokerId($listing->brokerId());
            }
            $this->em->persist($viewing);
            $this->em->flush(); // nextNumber() counts persisted rows
        }
    }

    /**
     * @param list<\Modules\Listings\Domain\NearLocation> $nearPlaces
     */
    private function seedListingNearLinks(
        \Modules\Listings\Domain\Listing $listing,
        array $nearPlaces,
        string $city,
        int $limit = 10,
    ): void {
        if ($nearPlaces === [] || !class_exists(\Modules\Listings\Domain\ListingNearLocation::class)) {
            return;
        }

        $linkRepo = $this->em->getRepository(\Modules\Listings\Domain\ListingNearLocation::class);
        $existing = $linkRepo->count(['listingId' => $listing->id()]);
        if ($existing >= $limit) {
            return;
        }

        $candidates = array_values(array_filter(
            $nearPlaces,
            static fn ($place) => $place->city() === $city,
        ));
        if ($candidates === []) {
            return;
        }

        $need = $limit - $existing;
        $order = $existing;
        $added = 0;
        foreach ($candidates as $place) {
            if ($added >= $need) {
                break;
            }
            $already = $linkRepo->findOneBy([
                'listingId' => $listing->id(),
                'nearLocationId' => $place->id(),
            ]);
            if ($already instanceof \Modules\Listings\Domain\ListingNearLocation) {
                continue;
            }

            $link = new \Modules\Listings\Domain\ListingNearLocation($listing->id(), $place->id());
            $computed = \Modules\Listings\Application\GeoDistance::between(
                $listing->latitude(),
                $listing->longitude(),
                $place->latitude(),
                $place->longitude(),
            );
            if ($computed !== null) {
                $link->setDistanceM($computed['distance_m']);
                $link->setWalkMinutes($computed['walk_minutes']);
            }
            $link->setSortOrder($order++);
            $this->em->persist($link);
            $added++;
        }
        $this->em->flush();
    }
}
