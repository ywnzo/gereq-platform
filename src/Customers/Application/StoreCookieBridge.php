<?php

declare(strict_types=1);

namespace Gereq\Customers\Application;

use Gereq\Config\Repository as Config;
use Gereq\Customers\Domain\CustomerAccount;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Storefront session: HttpOnly JWT cookie + readable session hint for the FE.
 * Prefer cookie auth; Bearer remains supported for non-browser clients.
 */
final class StoreCookieBridge
{
    /** Opaque HttpOnly session JWT cookie name (not a brand prefix). */
    public const COOKIE = 'grq827e';

    /** Opaque readable session-hint cookie — the FE must not read the JWT itself. */
    public const HINT_COOKIE = 'grq3k91';

    public function __construct(
        private readonly StoreTokenService $tokens,
        private readonly Config $config,
    ) {
    }

    public function attach(Response $response, CustomerAccount $account, int $ttlSeconds = 604800): Response
    {
        $issued = $this->tokens->issue($account, $ttlSeconds);
        $expires = time() + $ttlSeconds;
        $secure = $this->secure();

        $response->headers->setCookie(
            Cookie::create(self::COOKIE)
                ->withValue($issued['access_token'])
                ->withExpires($expires)
                ->withPath('/')
                ->withSecure($secure)
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX),
        );
        $response->headers->setCookie(
            Cookie::create(self::HINT_COOKIE)
                ->withValue('1')
                ->withExpires($expires)
                ->withPath('/')
                ->withSecure($secure)
                ->withHttpOnly(false)
                ->withSameSite(Cookie::SAMESITE_LAX),
        );

        return $response;
    }

    public function clear(Response $response): Response
    {
        $secure = $this->secure();
        $response->headers->clearCookie(self::COOKIE, '/', null, $secure, true, Cookie::SAMESITE_LAX);
        $response->headers->clearCookie(self::HINT_COOKIE, '/', null, $secure, false, Cookie::SAMESITE_LAX);

        return $response;
    }

    public function tokenFromRequest(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (preg_match('/^Bearer\s+(\S+)/i', $header, $m)) {
            return $m[1];
        }
        $cookie = $request->cookies->get(self::COOKIE);

        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }

    private function secure(): bool
    {
        return str_starts_with((string) $this->config->get('app.url', ''), 'https://');
    }
}
