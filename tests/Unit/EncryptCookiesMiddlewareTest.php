<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Config\Repository as Config;
use Gereq\Customers\Application\StoreCookieBridge;
use Gereq\Http\Middleware\EncryptCookiesMiddleware;
use Gereq\Security\CookieCipher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class EncryptCookiesMiddlewareTest extends TestCase
{
    public function testDecryptsRequestAndEncryptsResponse(): void
    {
        $config = new Config([
            'app' => ['key' => 'test-key-32-chars-minimum!!!!!!'],
            'session' => ['cookie' => 'gereq_session'],
        ]);
        $cipher = new CookieCipher($config);
        $middleware = new EncryptCookiesMiddleware($cipher, $config);

        $jwt = 'a.b.c';
        $encryptedJwt = $cipher->encrypt($jwt);
        $request = Request::create('/api/v1/store/me', 'GET', cookies: [
            StoreCookieBridge::COOKIE => $encryptedJwt,
            StoreCookieBridge::HINT_COOKIE => $cipher->encrypt('1'),
        ]);

        $response = $middleware->process($request, static function (Request $req): Response {
            self::assertSame('a.b.c', $req->cookies->get(StoreCookieBridge::COOKIE));
            self::assertSame('1', $req->cookies->get(StoreCookieBridge::HINT_COOKIE));

            $out = new Response('ok');
            $out->headers->setCookie(
                Cookie::create(StoreCookieBridge::COOKIE)
                    ->withValue('x.y.z')
                    ->withPath('/')
                    ->withHttpOnly(true)
                    ->withSameSite(Cookie::SAMESITE_LAX),
            );
            $out->headers->setCookie(
                Cookie::create(StoreCookieBridge::HINT_COOKIE)
                    ->withValue('1')
                    ->withPath('/')
                    ->withHttpOnly(false)
                    ->withSameSite(Cookie::SAMESITE_LAX),
            );

            return $out;
        });

        $jwtOut = null;
        $hintOut = null;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === StoreCookieBridge::COOKIE) {
                $jwtOut = (string) $cookie->getValue();
            }
            if ($cookie->getName() === StoreCookieBridge::HINT_COOKIE) {
                $hintOut = (string) $cookie->getValue();
            }
        }

        self::assertNotNull($jwtOut);
        self::assertStringStartsWith('gcm:', $jwtOut);
        self::assertSame('x.y.z', $cipher->decrypt($jwtOut));
        self::assertNotNull($hintOut);
        self::assertStringStartsWith('gcm:', $hintOut);
        self::assertSame('1', $cipher->decrypt($hintOut));
    }
}
