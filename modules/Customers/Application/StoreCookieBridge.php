<?php

declare(strict_types=1);

namespace Modules\Customers\Application;

use Gereq\Config\Repository as Config;
use Modules\Customers\Domain\CustomerAccount;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Cookie bridge for storefront FE (HttpOnly cookie + Bearer). */
final class StoreCookieBridge
{
    public const COOKIE = 'gereq_store_token';

    public function __construct(
        private readonly StoreTokenService $tokens,
        private readonly Config $config,
    ) {
    }

    public function attach(Response $response, CustomerAccount $account, int $ttlSeconds = 604800): Response
    {
        $issued = $this->tokens->issue($account, $ttlSeconds);
        $secure = str_starts_with((string) $this->config->get('app.url', ''), 'https://');
        $cookie = Cookie::create(self::COOKIE)
            ->withValue($issued['access_token'])
            ->withExpires(time() + $ttlSeconds)
            ->withPath('/')
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);

        $response->headers->setCookie($cookie);

        return $response;
    }

    public function clear(Response $response): Response
    {
        $response->headers->clearCookie(self::COOKIE, '/');

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
}
