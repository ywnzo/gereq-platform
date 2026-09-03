<?php

declare(strict_types=1);

namespace Gereq\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;

final class StartSessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$request->hasSession()) {
            $path = $this->basePath . '/storage/sessions';
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }

            $secure = $request->isSecure();
            $storage = new NativeSessionStorage(
                [
                    'cookie_httponly' => true,
                    'cookie_secure' => $secure,
                    'cookie_samesite' => 'lax',
                    'cookie_path' => '/',
                    'use_strict_mode' => true,
                ],
                new NativeFileSessionHandler($path)
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
        $request->getSession()->save();

        return $response;
    }
}
