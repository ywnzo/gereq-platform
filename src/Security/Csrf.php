<?php

declare(strict_types=1);

namespace Gereq\Security;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class Csrf
{
    private ?CsrfTokenManager $manager = null;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function token(): string
    {
        return $this->manager()->getToken('gereq')->getValue();
    }

    public function isValid(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        return $this->manager()->isTokenValid(new CsrfToken('gereq', $token));
    }

    public function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    private function manager(): CsrfTokenManager
    {
        if ($this->manager === null) {
            $this->manager = new CsrfTokenManager(null, new SessionTokenStorage($this->requestStack));
        }

        return $this->manager;
    }
}
