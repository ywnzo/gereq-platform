<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Auth\PasskeyService;
use Gereq\Auth\PasswordHasher;
use Gereq\Auth\TotpService;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\User\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SecurityController extends Controller
{
    private const SETUP_SECRET = 'mfa.totp_setup_secret';

    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly EntityManagerInterface $em,
        private readonly PasswordHasher $hasher,
        private readonly TotpService $totp,
        private readonly PasskeyService $passkeys,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function totpStart(Request $request): Response
    {
        $user = $this->requireUser();
        if ($user->totpEnabled()) {
            return $this->jsonError('totp_enabled', $this->translator->trans('security.totp.already'), [], 422);
        }

        $secret = $this->totp->generateSecret();
        $request->getSession()->set(self::SETUP_SECRET, $secret);
        $uri = $this->totp->provisioningUri($secret, $user);

        return $this->json([
            'secret' => $secret,
            'otpauth' => $uri,
            'qr' => $this->totp->qrDataUri($uri),
        ]);
    }

    public function totpConfirm(Request $request): Response
    {
        $user = $this->requireUser();
        $secret = $request->getSession()->get(self::SETUP_SECRET);
        if (!is_string($secret) || $secret === '') {
            return $this->jsonError('setup_expired', $this->translator->trans('security.totp.setup_expired'), [], 422);
        }

        $code = (string) ($this->jsonBody($request)['code'] ?? $request->request->get('code', ''));
        if (!$this->totp->verify($secret, $code)) {
            return $this->jsonError('invalid_code', $this->translator->trans('security.totp.invalid'), [], 422);
        }

        $plainCodes = $this->totp->generateRecoveryCodes();
        $user->enableTotp($secret, $this->totp->hashRecoveryCodes($plainCodes));
        $this->auth->revokeRememberTokens($user);
        $request->getSession()->remove(self::SETUP_SECRET);
        $this->em->flush();
        $this->auditor->log('auth.totp_enabled', 'user', $user->id());

        return $this->json([
            'recovery_codes' => $plainCodes,
            'message' => $this->translator->trans('security.totp.enabled'),
        ]);
    }

    public function totpDisable(Request $request): Response
    {
        $user = $this->requireUser();
        if (!$user->totpEnabled()) {
            return $this->jsonError('totp_disabled', $this->translator->trans('security.totp.not_enabled'), [], 422);
        }

        $body = $this->jsonBody($request);
        $password = (string) ($body['password'] ?? $request->request->get('password', ''));
        $code = (string) ($body['code'] ?? $request->request->get('code', ''));

        $ok = $password !== '' && $this->hasher->verify($password, $user->passwordHash());
        if (!$ok && $user->totpSecret() !== null && $this->totp->verify($user->totpSecret(), $code)) {
            $ok = true;
        }
        if (!$ok) {
            return $this->jsonError('invalid', $this->translator->trans('security.confirm_failed'), [], 422);
        }

        $user->disableTotp();
        $this->em->flush();
        $this->auditor->log('auth.totp_disabled', 'user', $user->id());

        if ($this->wantsJson($request)) {
            return $this->json(['message' => $this->translator->trans('security.totp.disabled')]);
        }

        $this->flash('success', $this->translator->trans('security.totp.disabled'));

        return $this->redirect('admin.profile');
    }

    public function recoveryRegenerate(Request $request): Response
    {
        $user = $this->requireUser();
        if (!$user->totpEnabled()) {
            return $this->jsonError('totp_disabled', $this->translator->trans('security.totp.not_enabled'), [], 422);
        }

        $body = $this->jsonBody($request);
        $password = (string) ($body['password'] ?? '');
        $code = (string) ($body['code'] ?? '');
        $ok = $password !== '' && $this->hasher->verify($password, $user->passwordHash());
        if (!$ok && $user->totpSecret() !== null && $this->totp->verify($user->totpSecret(), $code)) {
            $ok = true;
        }
        if (!$ok) {
            return $this->jsonError('invalid', $this->translator->trans('security.confirm_failed'), [], 422);
        }

        $plainCodes = $this->totp->generateRecoveryCodes();
        $user->setRecoveryCodes($this->totp->hashRecoveryCodes($plainCodes));
        $this->em->flush();
        $this->auditor->log('auth.recovery_regenerated', 'user', $user->id());

        return $this->json([
            'recovery_codes' => $plainCodes,
            'message' => $this->translator->trans('security.recovery.regenerated'),
        ]);
    }

    public function passkeyOptions(): Response
    {
        $user = $this->requireUser();
        try {
            $options = $this->passkeys->registrationOptions($user);
        } catch (\Throwable $e) {
            return $this->jsonError('passkey', $e->getMessage(), [], 422);
        }

        return $this->json(['options' => $options]);
    }

    public function passkeyRegister(Request $request): Response
    {
        $user = $this->requireUser();
        $body = $this->jsonBody($request);
        $name = trim((string) ($body['name'] ?? 'Passkey'));
        $credential = $body['credential'] ?? null;
        if (!is_array($credential)) {
            return $this->jsonError('invalid', $this->translator->trans('security.passkey.invalid'), [], 422);
        }

        try {
            $stored = $this->passkeys->completeRegistration($user, $credential, $name);
            $this->auth->revokeRememberTokens($user);
            $this->auditor->log('auth.passkey_added', 'user', $user->id(), null, ['credential' => $stored->id()]);
        } catch (\Throwable $e) {
            return $this->jsonError('passkey', $e->getMessage(), [], 422);
        }

        return $this->json([
            'message' => $this->translator->trans('security.passkey.added'),
            'credential' => [
                'id' => $stored->id(),
                'name' => $stored->name(),
                'created_at' => $stored->createdAt()->format(DATE_ATOM),
            ],
        ]);
    }

    public function passkeyDelete(Request $request, string $id): Response
    {
        $user = $this->requireUser();
        $body = $this->jsonBody($request);
        $password = (string) ($body['password'] ?? $request->request->get('password', ''));
        if ($password === '' || !$this->hasher->verify($password, $user->passwordHash())) {
            if ($this->wantsJson($request)) {
                return $this->jsonError('invalid', $this->translator->trans('security.confirm_failed'), [], 422);
            }
            $this->flash('error', $this->translator->trans('security.confirm_failed'));

            return $this->redirect('admin.profile');
        }

        if (!$this->passkeys->deleteCredential($user, $id)) {
            if ($this->wantsJson($request)) {
                return $this->jsonError('not_found', $this->translator->trans('security.passkey.missing'), [], 404);
            }
            $this->flash('error', $this->translator->trans('security.passkey.missing'));

            return $this->redirect('admin.profile');
        }

        $this->auditor->log('auth.passkey_removed', 'user', $user->id(), null, ['credential' => $id]);

        if ($this->wantsJson($request)) {
            return $this->json(['message' => $this->translator->trans('security.passkey.removed')]);
        }

        $this->flash('success', $this->translator->trans('security.passkey.removed'));

        return $this->redirect('admin.profile');
    }

    private function requireUser(): User
    {
        $user = $this->auth->user();
        if (!$user instanceof User) {
            $this->abort(401);
        }

        return $user;
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
