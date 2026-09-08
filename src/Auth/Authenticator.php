<?php

declare(strict_types=1);

namespace Gereq\Auth;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Audit\Auditor;
use Gereq\Auth\Entity\RememberToken;
use Gereq\User\Entity\User;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

final class Authenticator
{
    private const SESSION_KEY = 'auth.user_id';
    private const MFA_USER_KEY = 'auth.mfa_pending_user_id';
    private const MFA_REMEMBER_KEY = 'auth.mfa_remember';
    private const MFA_EXPIRES_KEY = 'auth.mfa_expires';
    private const REMEMBER_COOKIE = 'gereq_remember';

    private ?User $user = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
        private readonly PasswordHasher $hasher,
        private readonly Auditor $auditor,
    ) {
    }

    public function attempt(string $email, string $password, bool $remember = false): bool
    {
        $user = $this->em->getRepository(User::class)->findOneBy([
            'email' => strtolower(trim($email)),
        ]);

        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }

        if (!$this->hasher->verify($password, $user->passwordHash())) {
            return false;
        }

        if ($this->hasher->needsRehash($user->passwordHash())) {
            $user->setPasswordHash($this->hasher->hash($password));
            $this->em->flush();
        }

        if ($user->hasMfa()) {
            $this->beginMfaChallenge($user, $remember);

            return true;
        }

        $this->login($user, $remember);

        return true;
    }

    public function beginMfaChallenge(User $user, bool $remember = false): void
    {
        $session = $this->requestStack->getSession();
        $session->migrate(true);
        $ttl = (int) config('security.mfa_challenge_ttl', 600);
        $session->set(self::MFA_USER_KEY, $user->id());
        $session->set(self::MFA_REMEMBER_KEY, $remember);
        $session->set(self::MFA_EXPIRES_KEY, time() + max(60, $ttl));
    }

    public function isMfaPending(): bool
    {
        return $this->pendingMfaUser() !== null;
    }

    public function pendingMfaUser(): ?User
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();
        $expires = (int) $session->get(self::MFA_EXPIRES_KEY, 0);
        if ($expires > 0 && $expires < time()) {
            $this->clearMfaChallenge();

            return null;
        }

        $id = $session->get(self::MFA_USER_KEY);
        if (!is_string($id) || $id === '') {
            return null;
        }

        $user = $this->em->find(User::class, $id);
        if (!$user instanceof User || !$user->isActive() || !$user->hasMfa()) {
            $this->clearMfaChallenge();

            return null;
        }

        return $user;
    }

    public function pendingMfaRemember(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return false;
        }

        return (bool) $request->getSession()->get(self::MFA_REMEMBER_KEY, false);
    }

    public function completeMfaLogin(?bool $remember = null): void
    {
        $user = $this->pendingMfaUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('No MFA challenge in progress.');
        }

        $remember ??= $this->pendingMfaRemember();
        $this->clearMfaChallenge();
        $this->login($user, $remember);
        $this->auditor->log('auth.mfa_verified', 'user', $user->id());
    }

    public function clearMfaChallenge(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $session->remove(self::MFA_USER_KEY);
        $session->remove(self::MFA_REMEMBER_KEY);
        $session->remove(self::MFA_EXPIRES_KEY);
    }

    public function login(User $user, bool $remember = false): void
    {
        $this->clearMfaChallenge();
        $session = $this->requestStack->getSession();
        $session->migrate(true);
        $session->set(self::SESSION_KEY, $user->id());

        $user->markLoggedIn();
        $this->em->flush();
        $this->user = $user;

        if ($remember) {
            $this->queueRememberCookie($user);
        }

        $this->auditor->log('auth.login', 'user', $user->id(), null, ['email' => $user->email()]);
    }

    public function logout(): void
    {
        $user = $this->user();
        $session = $this->requestStack->getSession();
        $session->remove(self::SESSION_KEY);
        $this->clearMfaChallenge();
        $session->migrate(true);
        $this->user = null;

        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            $request->attributes->set('_clear_remember', true);
        }

        if ($user !== null) {
            $this->revokeRememberTokens($user);
            $this->auditor->log('auth.logout', 'user', $user->id());
        }
    }

    public function applyCookies(Response $response): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return;
        }

        if ($request->attributes->get('_clear_remember')) {
            $response->headers->clearCookie(
                self::REMEMBER_COOKIE,
                '/',
                null,
                $request->isSecure(),
                true,
                Cookie::SAMESITE_LAX
            );
        }

        $raw = $request->attributes->get('_remember_cookie');
        if (is_string($raw) && $raw !== '') {
            $response->headers->setCookie(Cookie::create(self::REMEMBER_COOKIE)
                ->withValue($raw)
                ->withExpires(time() + 60 * 60 * 24 * 30)
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX));
        }
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function user(): ?User
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }

        $id = $request->getSession()->get(self::SESSION_KEY);
        if (is_string($id) && $id !== '') {
            $user = $this->em->find(User::class, $id);
            if ($user instanceof User && $user->isActive()) {
                $this->user = $user;

                return $user;
            }
        }

        $this->attemptRemember();

        return $this->user;
    }

    public function id(): ?string
    {
        return $this->user()?->id();
    }

    private function queueRememberCookie(User $user): void
    {
        $plain = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plain);
        $token = new RememberToken($user, $hash, new \DateTimeImmutable('+30 days'));
        $this->em->persist($token);
        $this->em->flush();

        $request = $this->requestStack->getCurrentRequest();
        $request?->attributes->set('_remember_cookie', $user->id() . '|' . $plain);
    }

    private function attemptRemember(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return;
        }

        $cookie = (string) $request->cookies->get(self::REMEMBER_COOKIE, '');
        if (!str_contains($cookie, '|')) {
            return;
        }

        [$userId, $plain] = explode('|', $cookie, 2);
        $hash = hash('sha256', $plain);
        $token = $this->em->getRepository(RememberToken::class)->findOneBy(['tokenHash' => $hash]);

        if (!$token instanceof RememberToken || $token->isExpired() || $token->user()->id() !== $userId) {
            $request->attributes->set('_clear_remember', true);

            return;
        }

        $user = $token->user();
        if (!$user->isActive()) {
            $this->em->remove($token);
            $this->em->flush();
            $request->attributes->set('_clear_remember', true);

            return;
        }

        // Rotate remember token on each use.
        $this->em->remove($token);
        $this->em->flush();
        $this->queueRememberCookie($user);

        $session = $request->getSession();
        $session->migrate(true);
        $session->set(self::SESSION_KEY, $user->id());
        $this->user = $user;
    }

    public function revokeRememberTokens(User $user): void
    {
        $tokens = $this->em->getRepository(RememberToken::class)->findBy(['user' => $user]);
        foreach ($tokens as $token) {
            $this->em->remove($token);
        }
        $this->em->flush();
    }
}
