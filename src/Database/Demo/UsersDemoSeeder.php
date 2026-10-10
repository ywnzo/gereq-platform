<?php

declare(strict_types=1);

namespace Gereq\Database\Demo;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\PasswordHasher;
use Gereq\Database\DemoSeeder;
use Gereq\User\Entity\Permission;
use Gereq\User\Entity\Role;
use Gereq\User\Entity\User;

/** Demo admin users and role permission sync. */
final class UsersDemoSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasswordHasher $hasher,
    ) {
    }

    /** @param list<string> $log */
    public function syncRolePermissions(array &$log): void
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
        $all = $this->em->getRepository(Permission::class)->findAll();

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

    /**
     * @param list<string> $log
     * @return array{demo: User, manager: User, editor: User, view: User, admin: ?User}
     */
    public function seed(string $password, array &$log): array
    {
        $defs = [
            'demo' => [DemoSeeder::DEMO_EMAIL, 'Anna Demo', 'superadmin', 'cs'],
            'manager' => ['manazer@gereq.cz', 'Petr Manažer', 'admin', 'cs'],
            'editor' => ['editor@gereq.cz', 'Lucie Editorová', 'editor', 'cs'],
            'view' => ['view@gereq.cz', 'Jan View', 'view', 'cs'],
        ];

        $out = ['demo' => null, 'manager' => null, 'editor' => null, 'view' => null, 'admin' => null];

        foreach ($defs as $key => [$email, $name, $roleName, $locale]) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user === null) {
                $user = new User($name, $email, $this->hasher->hash($password));
                $role = $this->em->getRepository(Role::class)->findOneBy(['name' => $roleName]);
                if ($role instanceof Role) {
                    $user->assignRole($role);
                }
                $user->setLocale($locale);
                $this->em->persist($user);
                $log[] = "Uživatel {$email} vytvořen (heslo: {$password}, role: {$roleName}).";
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

        $admin = $this->em->getRepository(User::class)->findOneBy([], ['id' => 'ASC']);
        $out['admin'] = $admin instanceof User ? $admin : null;

        $this->em->flush();

        /** @var array{demo: User, manager: User, editor: User, view: User, admin: ?User} */
        return $out;
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
}
