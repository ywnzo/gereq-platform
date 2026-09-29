<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\Auth\Entity\PasswordResetToken;
use Gereq\Auth\PasskeyService;
use Gereq\Auth\PasswordHasher;
use Gereq\Auth\TotpService;
use Gereq\Config\Repository as ConfigRepository;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Security\RateLimiter;
use Gereq\Setup\SetupState;
use Gereq\User\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
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
        private readonly SetupState $setup,
        private readonly TotpService $totp,
        private readonly PasskeyService $passkeys,
        private readonly MailerInterface $mailer,
        private readonly ConfigRepository $config,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function show(): Response
    {
        if ($this->auth->isMfaPending()) {
            return $this->redirect('admin.login.mfa');
        }

        return $this->render('auth/login.html.twig', [
            'pageTitle' => $this->translator->trans('auth.login.title'),
            'passkeyLoginUrl' => $this->router->url('admin.login.passkey.options', [], $this->request()),
            'passkeyVerifyUrl' => $this->router->url('admin.login.passkey.verify', [], $this->request()),
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

        if ($this->auth->isMfaPending()) {
            return $this->redirect('admin.login.mfa');
        }

        return $this->afterLogin($request);
    }

    public function mfaShow(): Response
    {
        $user = $this->auth->pendingMfaUser();
        if (!$user instanceof User) {
            $this->flash('error', $this->translator->trans('auth.mfa.expired'));

            return $this->redirect('admin.login');
        }

        return $this->render('auth/mfa.html.twig', [
            'pageTitle' => $this->translator->trans('auth.mfa.title'),
            'user' => $user,
            'totpEnabled' => $user->totpEnabled(),
            'passkeyEnabled' => $user->passkeyEnabled(),
            'passkeyOptionsUrl' => $this->router->url('admin.login.mfa.passkey.options', [], $this->request()),
            'passkeyVerifyUrl' => $this->router->url('admin.login.mfa.passkey.verify', [], $this->request()),
        ]);
    }

    public function mfaVerify(Request $request): Response
    {
        $ip = (string) $request->getClientIp();
        if (!$this->limiter->hit('mfa:' . $ip, 10, 60)) {
            $this->flash('error', $this->translator->trans('auth.login.rate_limited'));

            return $this->redirect('admin.login.mfa');
        }

        $user = $this->auth->pendingMfaUser();
        if (!$user instanceof User) {
            $this->flash('error', $this->translator->trans('auth.mfa.expired'));

            return $this->redirect('admin.login');
        }

        $code = trim((string) $request->request->get('code', ''));
        $ok = false;

        if ($user->totpEnabled() && $user->totpSecret() !== null && $this->totp->verify($user->totpSecret(), $code)) {
            $ok = true;
        } elseif ($user->consumeRecoveryCode($code)) {
            $this->em->flush();
            $ok = true;
            $this->flash('success', $this->translator->trans('auth.mfa.recovery_used'));
        }

        if (!$ok) {
            $this->flash('error', $this->translator->trans('auth.mfa.invalid'));

            return $this->redirect('admin.login.mfa');
        }

        $this->limiter->clear('mfa:' . $ip);
        $this->auth->completeMfaLogin();

        return $this->afterLogin($request);
    }

    public function mfaCancel(): Response
    {
        $this->auth->clearMfaChallenge();
        $this->flash('success', $this->translator->trans('auth.mfa.cancelled'));

        return $this->redirect('admin.login');
    }

    public function mfaPasskeyOptions(): Response
    {
        $user = $this->auth->pendingMfaUser();
        if (!$user instanceof User || !$user->passkeyEnabled()) {
            return $this->jsonError('mfa_expired', $this->translator->trans('auth.mfa.expired'), [], 422);
        }

        try {
            $options = $this->passkeys->authenticationOptions($user);
        } catch (\Throwable $e) {
            return $this->jsonError('passkey', $e->getMessage(), [], 422);
        }

        return $this->json(['options' => $options]);
    }

    public function mfaPasskeyVerify(Request $request): Response
    {
        $user = $this->auth->pendingMfaUser();
        if (!$user instanceof User) {
            return $this->jsonError('mfa_expired', $this->translator->trans('auth.mfa.expired'), [], 422);
        }

        $payload = $this->jsonBody($request);
        try {
            $this->passkeys->completeAuthentication($payload, $user);
            $this->auth->completeMfaLogin();
        } catch (\Throwable $e) {
            return $this->jsonError('passkey', $e->getMessage() !== '' ? $e->getMessage() : $this->translator->trans('auth.mfa.invalid'), [], 422);
        }

        return $this->json([
            'redirect' => $this->postLoginUrl($request),
        ]);
    }

    public function passkeyLoginOptions(Request $request): Response
    {
        $email = strtolower(trim((string) ($request->request->get('email') ?? $request->query->get('email', ''))));
        $user = null;
        if ($email !== '') {
            $found = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($found instanceof User && $found->isActive() && $found->passkeyEnabled()) {
                $user = $found;
            } elseif ($email !== '') {
                return $this->jsonError('passkey', $this->translator->trans('auth.passkey.unavailable'), [], 422);
            }
        }

        try {
            $options = $this->passkeys->authenticationOptions($user);
        } catch (\Throwable $e) {
            return $this->jsonError('passkey', $e->getMessage(), [], 422);
        }

        return $this->json(['options' => $options]);
    }

    public function passkeyLoginVerify(Request $request): Response
    {
        $ip = (string) $request->getClientIp();
        if (!$this->limiter->hit('login-passkey:' . $ip, 10, 60)) {
            return $this->jsonError('rate_limited', $this->translator->trans('auth.login.rate_limited'), [], 429);
        }

        $payload = $this->jsonBody($request);
        $remember = in_array($payload['remember'] ?? false, [true, 1, '1', 'on'], true);

        try {
            $user = $this->passkeys->completeAuthentication($payload['credential'] ?? $payload);
            $this->auth->login($user, $remember);
        } catch (\Throwable $e) {
            return $this->jsonError('passkey', $e->getMessage() !== '' ? $e->getMessage() : $this->translator->trans('auth.login.invalid'), [], 422);
        }

        $this->limiter->clear('login-passkey:' . $ip);

        return $this->json([
            'redirect' => $this->postLoginUrl($request),
        ]);
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
        $ip = (string) ($request->getClientIp() ?? 'unknown');
        $email = strtolower(trim((string) $request->request->get('email')));

        if (!$this->limiter->hit('password-reset:ip:' . $ip, 5, 600)) {
            $this->flash('error', $this->translator->trans('auth.forgot.rate_limited'));

            return $this->redirect('admin.password.forgot');
        }
        if ($email !== '' && !$this->limiter->hit('password-reset:email:' . $email, 3, 3600)) {
            $this->flash('error', $this->translator->trans('auth.forgot.rate_limited'));

            return $this->redirect('admin.password.forgot');
        }

        $user = $email !== ''
            ? $this->em->getRepository(User::class)->findOneBy(['email' => $email])
            : null;

        if ($user instanceof User && $user->isActive()) {
            $existing = $this->em->getRepository(PasswordResetToken::class)->findBy(['user' => $user]);
            foreach ($existing as $old) {
                $this->em->remove($old);
            }

            $plain = bin2hex(random_bytes(32));
            $token = new PasswordResetToken($user, hash('sha256', $plain), new \DateTimeImmutable('+1 hour'));
            $this->em->persist($token);
            $this->em->flush();

            $resetUrl = $this->router->url('admin.password.reset', ['token' => $plain], $request);
            $fromAddress = (string) $this->config->get('mail.from.address', 'noreply@gereq.cz');
            $fromName = (string) $this->config->get('mail.from.name', 'GEREQ Platform');
            $message = (new Email())
                ->from(new Address($fromAddress, $fromName))
                ->to($user->email())
                ->subject($this->translator->trans('auth.forgot.mail_subject'))
                ->text($this->translator->trans('auth.forgot.mail_body', ['%url%' => $resetUrl]));

            try {
                $this->mailer->send($message);
            } catch (\Throwable) {
                // Still show the generic success flash — do not reveal delivery failures.
            }
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

        $user = $record->user();
        $user->setPasswordHash($this->hasher->hash($password));
        $this->em->remove($record);
        $this->auth->revokeRememberTokens($user);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('auth.reset.success'));

        return $this->redirect('admin.login');
    }

    private function afterLogin(Request $request): Response
    {
        return $this->redirectTo($this->postLoginUrl($request));
    }

    private function postLoginUrl(Request $request): string
    {
        if ($this->setup->isPending()) {
            return $this->router->url('admin.setup', [], $request);
        }

        $intended = $request->getSession()->get('_intended');
        $request->getSession()->remove('_intended');
        if (is_string($intended) && $intended !== '') {
            $path = (string) (parse_url($intended, PHP_URL_PATH) ?? $intended);
            $prefix = \Gereq\Support\UrlPrefix::get();
            $internal = $path;
            if ($prefix !== '' && (str_starts_with($internal, $prefix . '/') || $internal === $prefix)) {
                $internal = substr($internal, strlen($prefix)) ?: '/';
            }
            $adminBase = \Gereq\Support\AdminPath::base();
            $setupBase = $adminBase . '/setup';
            if (
                \Gereq\Support\AdminPath::matches($internal)
                && $internal !== $setupBase
                && !str_starts_with($internal, $setupBase . '/')
            ) {
                $query = parse_url($intended, PHP_URL_QUERY);
                $target = \Gereq\Support\UrlPrefix::path($internal);

                return is_string($query) && $query !== '' ? $target . '?' . $query : $target;
            }
        }

        return $this->router->url('admin.dashboard', [], $request);
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }
}
