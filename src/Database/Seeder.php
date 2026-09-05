<?php

declare(strict_types=1);

namespace Gereq\Database;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\PasswordHasher;
use Gereq\Module\InstalledModule;
use Gereq\Module\ModuleManager;
use Gereq\User\Entity\Permission;
use Gereq\User\Entity\Role;
use Gereq\User\Entity\User;
use Gereq\User\PermissionRegistry;

final class Seeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasswordHasher $hasher,
        private readonly PermissionRegistry $permissions,
        private readonly ModuleManager $modules,
        private readonly DemoSeeder $demoSeeder,
    ) {
    }

    public function seed(): void
    {
        $this->seedPermissions();
        $this->seedRoles();
        $this->seedAdmin();
        $this->seedModules();
        $this->em->flush();
        $this->seedCustomers();
        $this->em->flush();
        $this->demoSeeder->seed();
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
        $defs = [
            ['administrator', 'Administrátor', 'Plný přístup k platformě.'],
            ['manager', 'Manažer', 'Správa běžného provozu bez systémových nastavení.'],
            ['editor', 'Editor', 'Úpravy obsahu bez správy uživatelů.'],
            ['viewer', 'Prohlížeč', 'Pouze čtení.'],
        ];

        foreach ($defs as [$name, $label, $description]) {
            $existing = $this->em->getRepository(Role::class)->findOneBy(['name' => $name]);
            if ($existing === null) {
                $this->em->persist(new Role($name, $label, $description));
            }
        }

        $this->em->flush();

        $manager = $this->em->getRepository(Role::class)->findOneBy(['name' => 'manager']);
        $editor = $this->em->getRepository(Role::class)->findOneBy(['name' => 'editor']);
        $viewer = $this->em->getRepository(Role::class)->findOneBy(['name' => 'viewer']);

        $all = $this->em->getRepository(Permission::class)->findAll();
        if ($manager instanceof Role) {
            foreach ($all as $permission) {
                if (!str_starts_with($permission->name(), 'modules.') && !str_starts_with($permission->name(), 'roles.')) {
                    $manager->givePermission($permission);
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
        if ($viewer instanceof Role) {
            foreach ($all as $permission) {
                if (str_ends_with($permission->name(), '.view')) {
                    $viewer->givePermission($permission);
                }
            }
        }
    }

    private function seedAdmin(): void
    {
        $email = strtolower((string) env_value('ADMIN_EMAIL', 'jiri.novak@gereq.cz'));
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing !== null) {
            return;
        }

        $user = new User(
            (string) env_value('ADMIN_NAME', 'Jiří Novák'),
            $email,
            $this->hasher->hash((string) env_value('ADMIN_PASSWORD', 'change-me')),
        );

        $adminRole = $this->em->getRepository(Role::class)->findOneBy(['name' => 'administrator']);
        if ($adminRole instanceof Role) {
            $user->assignRole($adminRole);
        }

        $this->em->persist($user);
    }

    private function seedModules(): void
    {
        $defaultEnabled = [
            'customers', 'currency', 'crm', 'webhooks',
            'notifications', 'documents', 'products', 'warehouse',
            'orders', 'projects', 'calendar',
            'shop', 'payments', 'coupons', 'bundles', 'loyalty', 'feeds',
            'returns', 'reviews', 'newsletter', 'customer_uploads',
            'media', 'cms',
        ];

        foreach ($this->modules->discover() as $manifest) {
            $row = $this->em->find(InstalledModule::class, $manifest->id);
            $shouldEnable = in_array($manifest->id, $defaultEnabled, true);
            if ($row === null) {
                $this->em->persist(new InstalledModule($manifest->id, $manifest->version, $shouldEnable));
            } elseif ($shouldEnable && !$row->enabled()) {
                $row->enable();
                $row->setVersion($manifest->version);
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
        if (!class_exists(\Modules\Customers\Domain\Customer::class)) {
            return;
        }

        $repo = $this->em->getRepository(\Modules\Customers\Domain\Customer::class);
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
            $customer = new \Modules\Customers\Domain\Customer($name, $email);
            $customer->setPhone($phone);
            $customer->setCompany($company);
            $customer->setNote($note);
            $customer->setStatus($status);
            $this->em->persist($customer);
        }
    }
}
