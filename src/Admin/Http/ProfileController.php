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
use Gereq\Hook\HookManager;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\I18n\LocaleCatalog;
use Gereq\User\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ProfileController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly EntityManagerInterface $em,
        private readonly PasswordHasher $hasher,
        private readonly Auditor $auditor,
        private readonly LocaleCatalog $locales,
        private readonly TranslatorInterface $translator,
        private readonly HookManager $hooks,
        private readonly PasskeyService $passkeys,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function edit(): Response
    {
        $user = $this->auth->user();
        $passkeyRows = [];
        if ($user instanceof User) {
            foreach ($this->passkeys->credentialsFor($user) as $credential) {
                $passkeyRows[] = [
                    'id' => $credential->id(),
                    'name' => $credential->name(),
                    'created_at' => $credential->createdAt()->format('d.m.Y H:i'),
                ];
            }
        }

        return $this->page('admin/profile.html.twig', [
            'pageTitle' => $this->translator->trans('profile.title'),
            'locales' => $this->locales->all(),
            'passkeys' => $passkeyRows,
            'securityUrls' => [
                'totpStart' => $this->router->url('admin.profile.mfa.totp.start', [], $this->request()),
                'totpConfirm' => $this->router->url('admin.profile.mfa.totp.confirm', [], $this->request()),
                'totpDisable' => $this->router->url('admin.profile.mfa.totp.disable', [], $this->request()),
                'recovery' => $this->router->url('admin.profile.mfa.recovery', [], $this->request()),
                'passkeyOptions' => $this->router->url('admin.profile.passkeys.options', [], $this->request()),
                'passkeyRegister' => $this->router->url('admin.profile.passkeys.register', [], $this->request()),
                'passkeyDelete' => $this->router->url('admin.profile.passkeys.delete', ['id' => '__ID__'], $this->request()),
            ],
        ]);
    }

    public function update(Request $request): Response
    {
        $user = $this->auth->user();
        if (!$user instanceof User) {
            return $this->redirect('admin.login');
        }

        $user->setName(trim((string) $request->request->get('name')));

        $locale = trim((string) $request->request->get('locale', ''));
        if ($locale === '' || $locale === '_default') {
            $user->setLocale(null);
        } elseif ($this->locales->isSupported($locale)) {
            $user->setLocale($locale);
        }

        $password = (string) $request->request->get('password');
        if ($password !== '') {
            $current = (string) $request->request->get('current_password', '');
            if ($current === '' || !$this->hasher->verify($current, $user->passwordHash())) {
                $this->flash('error', $this->translator->trans('profile.password.current_invalid'));

                return $this->redirect('admin.profile');
            }
            if (strlen($password) < 10) {
                $this->flash('error', $this->translator->trans('profile.password.min'));

                return $this->redirect('admin.profile');
            }
            $user->setPasswordHash($this->hasher->hash($password));
            $this->auth->revokeRememberTokens($user);
            $request->attributes->set('_clear_remember', true);
            // Force re-login with a fresh session after password change.
            $this->hooks->doAction('admin.profile.saving', $user, $request);
            $this->em->flush();
            $this->auditor->log('profile.password_changed', 'user', $user->id());
            $this->auth->logout();
            $this->flash('success', $this->translator->trans('profile.password.changed'));

            return $this->redirect('admin.login');
        }

        $this->hooks->doAction('admin.profile.saving', $user, $request);
        $this->em->flush();
        $this->auditor->log('profile.updated', 'user', $user->id());
        $this->flash('success', $this->translator->trans('profile.saved'));

        return $this->redirect('admin.profile');
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
