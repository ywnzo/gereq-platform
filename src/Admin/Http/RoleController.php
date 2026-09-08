<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\User\Entity\Permission;
use Gereq\User\Entity\Role;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class RoleController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $qb = $this->em->getRepository(Role::class)
            ->createQueryBuilder('r')
            ->orderBy('r.label', 'ASC');
        self_repair_search($qb, 'r', ['name', 'label', 'description'], $search);

        $roles = $qb->getQuery()->getResult();
        // Warm user counts for the list (few roles).
        foreach ($roles as $role) {
            if ($role instanceof Role) {
                $role->users()->count();
            }
        }

        return $this->page('admin/roles/index.html.twig', [
            'pageTitle' => $this->translator->trans('admin.roles.title'),
            'roles' => $roles,
            'search' => $search,
        ]);
    }

    public function create(): Response
    {
        return $this->page('admin/roles/form.html.twig', [
            'pageTitle' => $this->translator->trans('admin.roles.new'),
            'item' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'roles.save');
        $name = strtolower(trim((string) $data['name']));
        $label = trim((string) $data['label']);
        $description = trim((string) ($data['description'] ?? ''));

        if ($this->em->getRepository(Role::class)->findOneBy(['name' => $name])) {
            $this->flash('error', $this->translator->trans('admin.roles.exists'));

            return $this->redirect('admin.roles.create');
        }

        $role = new Role($name, $label, $description);
        $this->em->persist($role);
        $this->em->flush();
        $this->auditor->log('role.created', 'role', $role->id(), null, [
            'name' => $role->name(),
            'label' => $role->label(),
        ]);
        $this->forgetForm('roles.save');
        $this->flash('success', $this->translator->trans('admin.roles.created', ['%label%' => $role->label()]));

        return $this->redirect('admin.roles.edit', ['id' => $role->id()]);
    }

    public function edit(string $id): Response
    {
        $role = $this->findRole($id);

        $permissions = $this->em->getRepository(Permission::class)->findBy([], ['groupName' => 'ASC', 'name' => 'ASC']);
        $grouped = [];
        foreach ($permissions as $permission) {
            $grouped[$permission->groupName()][] = $permission;
        }

        $assigned = [];
        foreach ($role->permissions() as $permission) {
            $assigned[$permission->id()] = true;
        }

        return $this->page('admin/roles/edit.html.twig', [
            'pageTitle' => $this->translator->trans('admin.roles.edit_title', ['%label%' => $role->label()]),
            'role' => $role,
            'grouped' => $grouped,
            'assigned' => $assigned,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $role = $this->findRole($id);
        $data = $this->validate($request, 'roles.update');

        $old = ['label' => $role->label(), 'description' => $role->description()];
        $role->setLabel(trim((string) $data['label']));
        $role->setDescription(trim((string) ($data['description'] ?? '')));

        if (!$role->isPrivileged()) {
            $ids = $request->request->all('permissions') ?: [];
            $selected = [];
            foreach ($ids as $permissionId) {
                $permission = $this->em->find(Permission::class, (string) $permissionId);
                if ($permission instanceof Permission) {
                    $selected[] = $permission;
                }
            }
            $role->syncPermissions(...$selected);
        }

        $this->em->flush();
        $this->auditor->log('role.updated', 'role', $role->id(), $old, [
            'label' => $role->label(),
            'description' => $role->description(),
        ]);
        $this->forgetForm('roles.update');
        $this->flash('success', $this->translator->trans('admin.roles.saved'));

        return $this->redirect('admin.users.roles');
    }

    public function destroy(string $id): Response
    {
        $role = $this->findRole($id);
        if (!$this->deleteRole($role)) {
            $this->flash('error', $this->translator->trans('admin.roles.cannot_delete_privileged'));

            return $this->redirect('admin.users.roles');
        }

        $label = $role->label();
        $this->em->flush();
        $this->auditor->log('role.deleted', 'role', $id, null, ['label' => $label]);
        $this->flash('success', $this->translator->trans('admin.roles.deleted', ['%label%' => $label]));

        return $this->redirect('admin.users.roles');
    }

    public function bulk(Request $request): Response
    {
        $data = $this->validate($request, 'roles.bulk');
        $action = (string) $data['action'];

        if ($action !== 'delete') {
            $this->abort(400);
        }

        $ids = $request->request->all('ids');
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $ids,
        ), static fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            $this->flash('error', $this->translator->trans('admin.roles.bulk.error.empty'));

            return $this->redirect('admin.users.roles');
        }
        if (!$request->request->getBoolean('confirmed')) {
            $this->flash('error', $this->translator->trans('admin.roles.bulk.error.confirm'));

            return $this->redirect('admin.users.roles');
        }

        $affected = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $role = $this->em->find(Role::class, $id);
            if (!$role instanceof Role) {
                continue;
            }
            if (!$this->deleteRole($role)) {
                $skipped++;
                continue;
            }
            $this->auditor->log('role.deleted', 'role', $id, null, ['label' => $role->label()]);
            $affected++;
        }

        $this->em->flush();

        if ($affected > 0) {
            $this->flash('success', $this->translator->trans('admin.roles.bulk.flash.delete', [
                '%count%' => (string) $affected,
            ]));
        }
        if ($skipped > 0) {
            $this->flash('error', $this->translator->trans('admin.roles.bulk.flash.skipped', [
                '%count%' => (string) $skipped,
            ]));
        }
        if ($affected === 0 && $skipped === 0) {
            $this->flash('error', $this->translator->trans('admin.roles.bulk.error.empty'));
        }

        return $this->redirect('admin.users.roles');
    }

    private function findRole(string $id): Role
    {
        $role = $this->em->find(Role::class, $id);
        if (!$role instanceof Role) {
            $this->abort(404, $this->translator->trans('admin.roles.not_found'));
        }

        return $role;
    }

    private function deleteRole(Role $role): bool
    {
        if ($role->isPrivileged()) {
            return false;
        }

        foreach ($role->users()->toArray() as $user) {
            $user->removeRole($role);
        }
        $role->syncPermissions();
        $this->em->remove($role);

        return true;
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
