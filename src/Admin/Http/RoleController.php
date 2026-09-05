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

    public function index(): Response
    {
        $roles = $this->em->getRepository(Role::class)->findBy([], ['label' => 'ASC']);

        return $this->page('admin/roles/index.html.twig', [
            'pageTitle' => $this->translator->trans('admin.roles.title'),
            'roles' => $roles,
        ]);
    }

    public function edit(string $id): Response
    {
        $role = $this->em->find(Role::class, $id);
        if (!$role instanceof Role) {
            $this->abort(404, $this->translator->trans('admin.roles.not_found'));
        }

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
        $role = $this->em->find(Role::class, $id);
        if (!$role instanceof Role) {
            $this->abort(404, $this->translator->trans('admin.roles.not_found'));
        }

        $role->setLabel(trim((string) $request->request->get('label')));
        $role->setDescription(trim((string) $request->request->get('description')));

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
        $this->auditor->log('role.updated', 'role', $role->id());
        $this->flash('success', $this->translator->trans('admin.roles.saved'));

        return $this->redirect('admin.roles.index');
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
