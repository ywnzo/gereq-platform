<?php

declare(strict_types=1);

namespace Gereq\Http\Middleware;

use Gereq\Config\Repository;
use Gereq\Http\Session\SessionHandlerFactory;
use Gereq\Media\Http\MediaFilePath;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;

final class StartSessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionHandlerFactory $handlers,
        private readonly Repository $config,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $pathInfo = $request->getPathInfo();
        $isApi = str_starts_with($pathInfo, '/api/');
        $cookieName = (string) $this->config->get('session.cookie', 'gereq_session');

        // Public/storefront API is token-based — skip session unless browser sent admin cookie
        // (so Developer API tester / same-origin fetch with credentials works).
        if ($isApi && !$request->cookies->has($cookieName)) {
            return $next($request);
        }

        // Public media files must stay cookie-free so browsers can cache images.
        if (MediaFilePath::matches($pathInfo) && !\Gereq\Support\AdminPath::matches($pathInfo)) {
            return $next($request);
        }

        $lifetime = max(1, (int) $this->config->get('session.lifetime', 120)) * 60;
        $secure = $request->isSecure();
        $path = (string) $this->config->get('session.path', '/');
        $sameSite = (string) $this->config->get('session.samesite', 'lax');

        if (!$request->hasSession()) {
            $storage = new NativeSessionStorage(
                [
                    'name' => $cookieName,
                    // EncryptCookiesMiddleware owns Set-Cookie — disable PHP auto cookies.
                    'use_cookies' => 0,
                    'cookie_lifetime' => $lifetime,
                    'cookie_httponly' => true,
                    'cookie_secure' => $secure,
                    'cookie_samesite' => $sameSite,
                    'cookie_path' => $path,
                    'gc_maxlifetime' => $lifetime,
                    'use_strict_mode' => true,
                ],
                $this->handlers->create()
            );

            // With use_cookies=0 PHP will not read the cookie — restore id from the
            // (already decrypted) request cookie or every request starts a new session (419 CSRF).
            $existingId = (string) $request->cookies->get($cookieName, '');
            if ($existingId !== '' && preg_match('/^[a-zA-Z0-9,-]{16,128}$/', $existingId) === 1) {
                $storage->setId($existingId);
            }

            $session = new Session($storage);
            $session->start();
            $request->setSession($session);

            if (!$session->has('_gereq_init')) {
                $session->migrate(true);
                $session->set('_gereq_init', true);
            }
        }

        $response = $next($request);
        if ($request->hasSession()) {
            $session = $request->getSession();
            // Controller may intentionally close the session before long I/O (session_write_close).
            if ($session->isStarted()) {
                $session->save();
                $response->headers->setCookie(
                    Cookie::create($cookieName)
                        ->withValue($session->getId())
                        ->withExpires(time() + $lifetime)
                        ->withPath($path)
                        ->withSecure($secure)
                        ->withHttpOnly(true)
                        ->withSameSite($sameSite),
                );
            }
        }

        return $response;
    }
}
