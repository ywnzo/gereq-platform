<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Auth\PasswordHasher;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\User\Entity\Role;
use Gereq\User\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class UserController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly PasswordHasher $hasher,
        private readonly Auditor $auditor,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $qb = $this->em->getRepository(User::class)->createQueryBuilder('u')->orderBy('u.name', 'ASC');
        if ($search !== '') {
            $qb->andWhere('u.name LIKE :q OR u.email LIKE :q')->setParameter('q', '%' . $search . '%');
        }

        $users = $qb->getQuery()->getResult();

        return $this->page('admin/users/index.html.twig', [
            'pageTitle' => 'Uživatelé',
            'users' => $users,
            'search' => $search,
        ]);
    }

    public function create(): Response
    {
        return $this->page('admin/users/form.html.twig', [
            'pageTitle' => 'Nový uživatel',
            'item' => null,
            'roles' => $this->em->getRepository(Role::class)->findBy([], ['label' => 'ASC']),
        ]);
    }

    public function store(Request $request): Response
    {
        $name = trim((string) $request->request->get('name'));
        $email = strtolower(trim((string) $request->request->get('email')));
        $password = (string) $request->request->get('password');

        if ($name === '' || $email === '' || strlen($password) < 10) {
            $this->flash('error', 'Vyplňte jméno, e-mail a heslo (min. 10 znaků).');

            return $this->redirect('admin.users.create');
        }

        if ($this->em->getRepository(User::class)->findOneBy(['email' => $email])) {
            $this->flash('error', 'Uživatel s tímto e-mailem už existuje.');

            return $this->redirect('admin.users.create');
        }

        $user = new User($name, $email, $this->hasher->hash($password));
        $this->assignRoles($user, $request->request->all('roles') ?: []);
        $this->em->persist($user);
        $this->em->flush();
        $this->auditor->log('user.created', 'user', $user->id(), null, ['email' => $email]);
        $this->flash('success', 'Uživatel byl vytvořen.');

        return $this->redirect('admin.users.index');
    }

    public function edit(string $id): Response
    {
        $user = $this->em->find(User::class, $id);
        if (!$user instanceof User) {
            $this->abort(404, 'Uživatel neexistuje.');
        }

        return $this->page('admin/users/form.html.twig', [
            'pageTitle' => 'Upravit uživatele',
            'item' => $user,
            'roles' => $this->em->getRepository(Role::class)->findBy([], ['label' => 'ASC']),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $user = $this->em->find(User::class, $id);
        if (!$user instanceof User) {
            $this->abort(404, 'Uživatel neexistuje.');
        }

        $old = ['name' => $user->name(), 'email' => $user->email(), 'status' => $user->status()];
        $user->setName(trim((string) $request->request->get('name')));
        $user->setEmail(trim((string) $request->request->get('email')));
        $user->setStatus((string) $request->request->get('status', 'active'));
        $password = (string) $request->request->get('password');
        if ($password !== '') {
            if (strlen($password) < 10) {
                $this->flash('error', 'Heslo musí mít alespoň 10 znaků.');

                return $this->redirect('admin.users.edit', ['id' => $id]);
            }
            $user->setPasswordHash($this->hasher->hash($password));
        }

        $user->roles()->clear();
        $this->assignRoles($user, $request->request->all('roles') ?: []);
        $this->em->flush();
        $this->auditor->log('user.updated', 'user', $user->id(), $old, [
            'name' => $user->name(),
            'email' => $user->email(),
            'status' => $user->status(),
        ]);
        $this->flash('success', 'Uživatel byl uložen.');

        return $this->redirect('admin.users.index');
    }

    public function destroy(string $id): Response
    {
        $user = $this->em->find(User::class, $id);
        if (!$user instanceof User) {
            $this->abort(404, 'Uživatel neexistuje.');
        }

        if ($this->auth->id() === $user->id()) {
            $this->flash('error', 'Nemůžete smazat vlastní účet.');

            return $this->redirect('admin.users.index');
        }

        $this->em->remove($user);
        $this->em->flush();
        $this->auditor->log('user.deleted', 'user', $id);
        $this->flash('success', 'Uživatel byl smazán.');

        return $this->redirect('admin.users.index');
    }

    /** @param list<string> $roleIds */
    private function assignRoles(User $user, array $roleIds): void
    {
        foreach ($roleIds as $roleId) {
            $role = $this->em->find(Role::class, (string) $roleId);
            if ($role instanceof Role) {
                $user->assignRole($role);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();
        $request = $this->request();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $request),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
