<?php

declare(strict_types=1);

namespace Gereq\Http\Middleware;

use Gereq\Config\Repository;
use Gereq\Http\Session\SessionHandlerFactory;
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
        $isApi = str_starts_with($request->getPathInfo(), '/api/');
        $cookieName = (string) $this->config->get('session.cookie', 'gereq_session');

        // Public/storefront API is token-based — skip session unless browser sent admin cookie
        // (so Developer API tester / same-origin fetch with credentials works).
        if ($isApi && !$request->cookies->has($cookieName)) {
            return $next($request);
        }

        // Public media files must stay cookie-free so browsers can cache images.
        if ((bool) preg_match('#^/media/[^/]+/file$#', $request->getPathInfo())) {
            return $next($request);
        }

        if (!$request->hasSession()) {
            $lifetime = max(1, (int) $this->config->get('session.lifetime', 120)) * 60;
            $secure = $request->isSecure();
            $storage = new NativeSessionStorage(
                [
                    'name' => $cookieName,
                    'cookie_lifetime' => $lifetime,
                    'cookie_httponly' => true,
                    'cookie_secure' => $secure,
                    'cookie_samesite' => (string) $this->config->get('session.samesite', 'lax'),
                    'cookie_path' => (string) $this->config->get('session.path', '/'),
                    'gc_maxlifetime' => $lifetime,
                    'use_strict_mode' => true,
                ],
                $this->handlers->create()
            );

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
            }
        }

        return $response;
    }
}
