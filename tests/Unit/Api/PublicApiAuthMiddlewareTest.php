<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Api;

use Gereq\Api\Auth\PublicApiKey;
use Gereq\Api\Http\PublicApiAuthMiddleware;
use Gereq\Config\Repository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicApiAuthMiddlewareTest extends TestCase
{
    public function testRejectsMissingKey(): void
    {
        $key = 'grq_pub_' . str_repeat('ab', 24);
        $mw = new PublicApiAuthMiddleware(new PublicApiKey(new Repository([
            'api' => ['public_key' => $key],
        ])));

        $response = $mw->process(
            Request::create('/api/public/listings', 'GET'),
            static fn () => new Response('ok'),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testAcceptsBearerKey(): void
    {
        $key = 'grq_pub_' . str_repeat('cd', 24);
        $mw = new PublicApiAuthMiddleware(new PublicApiKey(new Repository([
            'api' => ['public_key' => $key],
        ])));

        $request = Request::create('/api/public/listings', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $key);

        $response = $mw->process($request, static fn () => new Response('ok'));

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($request->attributes->get('_api_public_key'));
        self::assertFalse($request->attributes->get('_api_public_first_party'));
    }

    public function testAcceptsXApiKeyHeader(): void
    {
        $key = 'grq_pub_' . str_repeat('ef', 24);
        $mw = new PublicApiAuthMiddleware(new PublicApiKey(new Repository([
            'api' => ['public_key' => $key],
        ])));

        $request = Request::create('/api/public/pages/home', 'GET');
        $request->headers->set('X-Api-Key', $key);

        $response = $mw->process($request, static fn () => new Response('ok'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAcceptsFirstPartySecFetchSiteWithoutKey(): void
    {
        $mw = new PublicApiAuthMiddleware(new PublicApiKey(new Repository([
            'api' => ['public_key' => 'grq_pub_' . str_repeat('11', 24)],
        ])));

        $request = Request::create('/api/public/listings', 'GET');
        $request->headers->set('Sec-Fetch-Site', 'same-origin');

        $response = $mw->process($request, static fn () => new Response('ok'));

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($request->attributes->get('_api_public_first_party'));
    }

    public function testAcceptsAllowlistedOriginWithoutKey(): void
    {
        $prev = getenv('FRONTEND_ORIGIN');
        putenv('FRONTEND_ORIGIN=https://shop.example.test');
        $_ENV['FRONTEND_ORIGIN'] = 'https://shop.example.test';

        try {
            $mw = new PublicApiAuthMiddleware(new PublicApiKey(new Repository([
                'api' => ['public_key' => 'grq_pub_' . str_repeat('22', 24)],
            ])));

            $request = Request::create('/api/public/listings', 'GET');
            $request->headers->set('Origin', 'https://shop.example.test');

            $response = $mw->process($request, static fn () => new Response('ok'));

            self::assertSame(200, $response->getStatusCode());
        } finally {
            if ($prev === false) {
                putenv('FRONTEND_ORIGIN');
                unset($_ENV['FRONTEND_ORIGIN']);
            } else {
                putenv('FRONTEND_ORIGIN=' . $prev);
                $_ENV['FRONTEND_ORIGIN'] = $prev;
            }
        }
    }

    public function testAcceptsAllowlistedRefererWithoutKey(): void
    {
        $prev = getenv('FRONTEND_ORIGIN');
        putenv('FRONTEND_ORIGIN=http://192.168.100.74:5174');
        $_ENV['FRONTEND_ORIGIN'] = 'http://192.168.100.74:5174';

        try {
            $mw = new PublicApiAuthMiddleware(new PublicApiKey(new Repository([
                'api' => ['public_key' => 'grq_pub_' . str_repeat('33', 24)],
            ])));

            $request = Request::create('/api/public/pages/home', 'GET');
            $request->headers->set('Referer', 'http://192.168.100.74:5174/');

            $response = $mw->process($request, static fn () => new Response('ok'));

            self::assertSame(200, $response->getStatusCode());
            self::assertTrue($request->attributes->get('_api_public_first_party'));
        } finally {
            if ($prev === false) {
                putenv('FRONTEND_ORIGIN');
                unset($_ENV['FRONTEND_ORIGIN']);
            } else {
                putenv('FRONTEND_ORIGIN=' . $prev);
                $_ENV['FRONTEND_ORIGIN'] = $prev;
            }
        }
    }

    public function testSkipsOptions(): void
    {
        $mw = new PublicApiAuthMiddleware(new PublicApiKey(new Repository([
            'api' => ['public_key' => 'grq_pub_x'],
        ])));

        $response = $mw->process(
            Request::create('/api/public/listings', 'OPTIONS'),
            static fn () => new Response('', 204),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testIgnoresNonPublicPaths(): void
    {
        $mw = new PublicApiAuthMiddleware(new PublicApiKey(new Repository([
            'api' => ['public_key' => 'grq_pub_x'],
        ])));

        $response = $mw->process(
            Request::create('/api/v1/crm/deals', 'GET'),
            static fn () => new Response('private'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('private', $response->getContent());
    }
}
