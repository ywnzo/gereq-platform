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
        // Public/storefront API is token-based — do not require Redis/file session.
        if (str_starts_with($request->getPathInfo(), '/api/')) {
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
                    'name' => (string) $this->config->get('session.cookie', 'gereq_session'),
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
            $request->getSession()->save();
        }

        return $response;
    }
}
