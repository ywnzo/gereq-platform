<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Auth\PasskeyService;
use Gereq\Auth\PasswordHasher;
use Gereq\File\FileManager;
use Gereq\File\StoredFile;
use Gereq\Hook\HookManager;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\User\Entity\Role;
use Gereq\User\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
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
        private readonly TranslatorInterface $translator,
        private readonly HookManager $hooks,
        private readonly FileManager $files,
        private readonly PasskeyService $passkeys,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $qb = $this->em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->leftJoin('u.roles', 'r')
            ->addSelect('r')
            ->orderBy('u.name', 'ASC');
        self_repair_search($qb, 'u', ['name', 'firstName', 'lastName', 'email', 'phone'], $search);

        $users = $qb->getQuery()->getResult();

        return $this->page('admin/users/index.html.twig', [
            'pageTitle' => $this->translator->trans('admin.users.title'),
            'users' => $users,
            'search' => $search,
        ]);
    }

    public function create(): Response
    {
        return $this->page('admin/users/form.html.twig', [
            'pageTitle' => $this->translator->trans('admin.users.new'),
            'item' => null,
            'roles' => $this->em->getRepository(Role::class)->findBy([], ['label' => 'ASC']),
            'activeTab' => 'profile',
        ]);
    }

    public function store(Request $request): Response
    {
        $email = strtolower(trim((string) $request->request->get('email')));
        $password = (string) $request->request->get('password');
        $firstName = trim((string) $request->request->get('first_name'));
        $lastName = trim((string) $request->request->get('last_name'));
        $displayName = trim($firstName . ' ' . $lastName);

        if ($displayName === '' || $email === '' || strlen($password) < 10) {
            $this->flash('error', $this->translator->trans('admin.users.validation'));

            return $this->redirect('admin.users.create');
        }

        if ($this->em->getRepository(User::class)->findOneBy(['email' => $email])) {
            $this->flash('error', $this->translator->trans('admin.users.exists'));

            return $this->redirect('admin.users.create');
        }

        $user = new User($displayName, $email, $this->hasher->hash($password));
        $this->applyProfile($user, $request);
        $this->assignRoles($user, $request->request->all('roles') ?: []);
        $this->storeAvatar($request, $user);
        $this->storeBanner($request, $user);
        $this->em->persist($user);
        $this->hooks->doAction('admin.users.saving', $user, $request);
        $this->em->flush();
        $this->auditor->log('user.created', 'user', $user->id(), null, ['email' => $email]);
        $this->flash('success', $this->translator->trans('admin.users.created'));

        return $this->redirect('admin.users.edit', ['id' => $user->id()]);
    }

    public function edit(Request $request, string $id): Response
    {
        $user = $this->findUser($id);

        return $this->page('admin/users/form.html.twig', [
            'pageTitle' => $this->translator->trans('admin.users.edit'),
            'item' => $user,
            'roles' => $this->em->getRepository(Role::class)->findBy([], ['label' => 'ASC']),
            'activeTab' => (string) $request->query->get('tab', 'profile'),
            'passkeys' => $this->passkeys->credentialsFor($user),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $user = $this->findUser($id);

        $old = [
            'name' => $user->name(),
            'email' => $user->email(),
            'status' => $user->status(),
        ];
        $email = strtolower(trim((string) $request->request->get('email')));
        if ($email === '') {
            $this->flash('error', $this->translator->trans('admin.users.validation'));

            return $this->redirect('admin.users.edit', ['id' => $id]);
        }

        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing instanceof User && $existing->id() !== $user->id()) {
            $this->flash('error', $this->translator->trans('admin.users.exists'));

            return $this->redirect('admin.users.edit', ['id' => $id]);
        }

        $user->setEmail($email);
        $user->setStatus((string) $request->request->get('status', 'active'));
        $this->applyProfile($user, $request);

        $password = (string) $request->request->get('password');
        if ($password !== '') {
            if (strlen($password) < 10) {
                $this->flash('error', $this->translator->trans('admin.users.password_min'));

                return $this->redirect('admin.users.edit', ['id' => $id, 'tab' => 'security']);
            }
            $user->setPasswordHash($this->hasher->hash($password));
        }

        $wasPrivileged = $user->isAdministrator();
        $user->roles()->clear();
        $this->assignRoles($user, $request->request->all('roles') ?: [], keepPrivileged: $wasPrivileged);
        $this->storeAvatar($request, $user);
        if ($request->request->getBoolean('remove_avatar')) {
            $user->setAvatarFileId(null);
        }
        $this->storeBanner($request, $user);
        if ($request->request->getBoolean('remove_banner')) {
            $user->setBannerFileId(null);
        }
        $this->hooks->doAction('admin.users.saving', $user, $request);
        $this->em->flush();
        $this->auditor->log('user.updated', 'user', $user->id(), $old, [
            'name' => $user->name(),
            'email' => $user->email(),
            'status' => $user->status(),
        ]);
        $this->flash('success', $this->translator->trans('admin.users.saved'));

        $tab = (string) $request->request->get('settings_tab', 'profile');

        return $this->redirect('admin.users.edit', ['id' => $id, 'tab' => $tab]);
    }

    public function avatar(string $id): Response
    {
        return $this->serveUserImage($id, 'avatar');
    }

    public function banner(string $id): Response
    {
        return $this->serveUserImage($id, 'banner');
    }

    public function destroy(string $id): Response
    {
        $user = $this->findUser($id);

        if ($this->auth->id() === $user->id()) {
            $this->flash('error', $this->translator->trans('admin.users.cannot_delete_self'));

            return $this->redirect('admin.users.index');
        }

        $this->em->remove($user);
        $this->em->flush();
        $this->auditor->log('user.deleted', 'user', $id);
        $this->flash('success', $this->translator->trans('admin.users.deleted'));

        return $this->redirect('admin.users.index');
    }

    public function resetMfa(string $id): Response
    {
        $user = $this->findUser($id);
        $this->passkeys->deleteAllFor($user);
        $user->resetMfa();
        $this->auth->revokeRememberTokens($user);
        $this->em->flush();
        $this->auditor->log('auth.mfa_reset', 'user', $user->id(), null, [
            'by' => $this->auth->id(),
        ]);
        $this->flash('success', $this->translator->trans('admin.users.mfa_reset'));

        return $this->redirect('admin.users.edit', ['id' => $id, 'tab' => 'security']);
    }

    private function applyProfile(User $user, Request $request): void
    {
        $user->setFirstName(trim((string) $request->request->get('first_name')));
        $user->setLastName(trim((string) $request->request->get('last_name')));
        $user->setDescription(trim((string) $request->request->get('description')));
        $user->setPhone(trim((string) $request->request->get('phone')));

        $birth = trim((string) $request->request->get('birth_date'));
        if ($birth !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) === 1) {
            $user->setBirthDate(new \DateTimeImmutable($birth));
        } else {
            $user->setBirthDate(null);
        }

        $user->setBillingAddress(
            trim((string) $request->request->get('billing_street')),
            trim((string) $request->request->get('billing_city')),
            trim((string) $request->request->get('billing_zip')),
            trim((string) $request->request->get('billing_country')) ?: 'CZ',
        );

        if ($request->request->getBoolean('shipping_same')) {
            $user->setShippingAddress(
                $user->billingStreet(),
                $user->billingCity(),
                $user->billingZip(),
                $user->billingCountry(),
            );
        } else {
            $user->setShippingAddress(
                trim((string) $request->request->get('shipping_street')),
                trim((string) $request->request->get('shipping_city')),
                trim((string) $request->request->get('shipping_zip')),
                trim((string) $request->request->get('shipping_country')) ?: 'CZ',
            );
        }
    }

    private function storeAvatar(Request $request, User $user): void
    {
        $this->storeUserUpload($request, $user, 'avatar');
    }

    private function storeBanner(Request $request, User $user): void
    {
        $this->storeUserUpload($request, $user, 'banner');
    }

    /** @param 'avatar'|'banner' $kind */
    private function storeUserUpload(Request $request, User $user, string $kind): void
    {
        $upload = $request->files->get($kind);
        if (!$upload instanceof UploadedFile || !$upload->isValid()) {
            return;
        }
        try {
            $stored = $this->files->store($upload, $this->auth->user());
            $this->em->persist($stored);
            if ($kind === 'banner') {
                $user->setBannerFileId($stored->id());
            } else {
                $user->setAvatarFileId($stored->id());
            }
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
    }

    /** @param 'avatar'|'banner' $kind */
    private function serveUserImage(string $id, string $kind): Response
    {
        $user = $this->findUser($id);
        $fileId = $kind === 'banner' ? $user->bannerFileId() : $user->avatarFileId();
        $missingKey = $kind === 'banner' ? 'admin.users.banner_missing' : 'admin.users.avatar_missing';
        if ($fileId === null) {
            $this->abort(404, $this->translator->trans($missingKey));
        }
        $file = $this->em->find(StoredFile::class, $fileId);
        if (!$file instanceof StoredFile) {
            $this->abort(404, $this->translator->trans($missingKey));
        }
        try {
            $contents = $this->files->filesystem()->read($file->path());
        } catch (\Throwable) {
            $this->abort(404, $this->translator->trans($missingKey));
        }

        return new Response($contents, 200, [
            'Content-Type' => $file->mime(),
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function findUser(string $id): User
    {
        $user = $this->em->createQueryBuilder()
            ->select('u', 'r')
            ->from(User::class, 'u')
            ->leftJoin('u.roles', 'r')
            ->where('u.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$user instanceof User) {
            $this->abort(404, $this->translator->trans('admin.users.not_found'));
        }

        return $user;
    }

    /** @param list<string> $roleIds */
    private function assignRoles(User $user, array $roleIds, bool $keepPrivileged = false): void
    {
        $actor = $this->auth->user();
        $actorIsPrivileged = $actor instanceof User && $actor->isAdministrator();
        $preservePrivileged = $keepPrivileged && !$actorIsPrivileged;

        foreach ($roleIds as $roleId) {
            $role = $this->em->find(Role::class, (string) $roleId);
            if (!$role instanceof Role) {
                continue;
            }
            if ($role->isPrivileged() && !$actorIsPrivileged) {
                continue;
            }
            $user->assignRole($role);
        }

        if ($preservePrivileged) {
            $super = $this->em->getRepository(Role::class)->findOneBy(['name' => 'superadmin'])
                ?? $this->em->getRepository(Role::class)->findOneBy(['name' => 'administrator']);
            if ($super instanceof Role) {
                $user->assignRole($super);
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
