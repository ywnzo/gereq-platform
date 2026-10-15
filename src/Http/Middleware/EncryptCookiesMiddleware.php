<?php

declare(strict_types=1);

namespace Gereq\Http\Middleware;

use Gereq\Auth\Authenticator;
use Gereq\Config\Repository as Config;
use Gereq\Customers\Application\StoreCookieBridge;
use Gereq\Security\CookieCipher;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decrypt allowlisted cookies on the request (before session) and encrypt
 * their values on the response Set-Cookie headers.
 */
final class EncryptCookiesMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CookieCipher $cipher,
        private readonly Config $config,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $this->decryptRequestCookies($request);

        return $this->encryptResponseCookies($next($request));
    }

    public function decryptRequestCookies(Request $request): void
    {
        foreach ($this->names() as $name) {
            if (!$request->cookies->has($name)) {
                continue;
            }
            $raw = (string) $request->cookies->get($name, '');
            // Some clients echo Set-Cookie encoding (gcm%3A…) — normalize before decrypt.
            if (str_contains($raw, '%') && !str_starts_with($raw, 'gcm:')) {
                $raw = rawurldecode($raw);
            }
            $plain = $this->cipher->decrypt($raw);
            $request->cookies->set($name, $plain);
            $_COOKIE[$name] = $plain;
        }
    }

    public function encryptResponseCookies(Response $response): Response
    {
        $names = $this->names();
        foreach ($response->headers->getCookies() as $cookie) {
            if (!in_array($cookie->getName(), $names, true)) {
                continue;
            }
            $value = (string) $cookie->getValue();
            if ($value === '' || str_starts_with($value, 'gcm:')) {
                continue;
            }

            $encrypted = $this->cipher->encrypt($value);
            $response->headers->setCookie($this->cloneCookie($cookie, $encrypted));
        }

        return $response;
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_values(array_unique([
            StoreCookieBridge::COOKIE,
            StoreCookieBridge::HINT_COOKIE,
            (string) $this->config->get('session.cookie', 'gereq_session'),
            Authenticator::REMEMBER_COOKIE,
        ]));
    }

    private function cloneCookie(Cookie $cookie, string $value): Cookie
    {
        $clone = Cookie::create($cookie->getName())
            ->withValue($value)
            ->withPath($cookie->getPath())
            ->withSecure($cookie->isSecure())
            ->withHttpOnly($cookie->isHttpOnly())
            ->withSameSite($cookie->getSameSite() ?? Cookie::SAMESITE_LAX);

        $expire = $cookie->getExpiresTime();
        if ($expire > 0) {
            $clone = $clone->withExpires($expire);
        }

        $domain = $cookie->getDomain();
        if (is_string($domain) && $domain !== '') {
            $clone = $clone->withDomain($domain);
        }

        return $clone;
    }
}
