<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\Auth\Entity\PasswordResetToken;
use Gereq\Auth\PasswordHasher;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Security\RateLimiter;
use Gereq\User\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class AuthController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly RateLimiter $limiter,
        private readonly EntityManagerInterface $em,
        private readonly PasswordHasher $hasher,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function show(): Response
    {
        return $this->render('auth/login.html.twig', [
            'pageTitle' => $this->translator->trans('auth.login.title'),
        ]);
    }

    public function login(Request $request): Response
    {
        $ip = (string) $request->getClientIp();
        if (!$this->limiter->hit('login:' . $ip, (int) config('security.login_max_attempts', 5), (int) config('security.login_decay_seconds', 60))) {
            $this->flash('error', $this->translator->trans('auth.login.rate_limited'));

            return $this->redirect('admin.login');
        }

        $data = $this->validate($request, 'auth.login');
        $email = strtolower(trim((string) $data['email']));
        $password = (string) $data['password'];
        $remember = in_array($data['remember'] ?? false, [true, 1, '1', 'on'], true);

        if (!$this->auth->attempt($email, $password, $remember)) {
            $this->formState()->store('auth.login', [
                'email' => $this->translator->trans('auth.login.invalid'),
            ], ['email' => $email], $this->translator->trans('auth.login.invalid'));

            return $this->redirect('admin.login');
        }

        $this->limiter->clear('login:' . $ip);
        $this->forgetForm('auth.login');

        $intended = $request->getSession()->get('_intended');
        $request->getSession()->remove('_intended');
        if (is_string($intended) && str_starts_with($intended, '/admin')) {
            return $this->redirectTo($intended);
        }

        return $this->redirect('admin.dashboard');
    }

    public function logout(): Response
    {
        $this->auth->logout();
        $this->flash('success', $this->translator->trans('auth.logout.success'));

        return $this->redirect('admin.login');
    }

    public function forgot(): Response
    {
        return $this->render('auth/forgot.html.twig', [
            'pageTitle' => $this->translator->trans('auth.forgot.title'),
        ]);
    }

    public function sendReset(Request $request): Response
    {
        $email = strtolower(trim((string) $request->request->get('email')));
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($user instanceof User) {
            $plain = bin2hex(random_bytes(32));
            $token = new PasswordResetToken($user, hash('sha256', $plain), new \DateTimeImmutable('+1 hour'));
            $this->em->persist($token);
            $this->em->flush();
        }

        $this->flash('success', $this->translator->trans('auth.forgot.sent'));

        return $this->redirect('admin.login');
    }

    public function reset(string $token): Response
    {
        return $this->render('auth/reset.html.twig', [
            'pageTitle' => $this->translator->trans('auth.reset.title'),
            'token' => $token,
        ]);
    }

    public function updatePassword(Request $request, string $token): Response
    {
        $hash = hash('sha256', $token);
        $record = $this->em->getRepository(PasswordResetToken::class)->findOneBy(['tokenHash' => $hash]);
        $password = (string) $request->request->get('password');
        $confirm = (string) $request->request->get('password_confirmation');

        if (!$record instanceof PasswordResetToken || $record->isExpired()) {
            $this->flash('error', $this->translator->trans('auth.reset.invalid'));

            return $this->redirect('admin.login');
        }

        if (strlen($password) < (int) config('security.password_min_length', 10) || $password !== $confirm) {
            $this->flash('error', $this->translator->trans('auth.reset.mismatch'));

            return $this->redirectTo($this->router->url('admin.password.reset', ['token' => $token], $request));
        }

        $record->user()->setPasswordHash($this->hasher->hash($password));
        $this->em->remove($record);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('auth.reset.success'));

        return $this->redirect('admin.login');
    }
}
