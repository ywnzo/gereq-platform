<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Gereq\Api\Auth\ApiTokenService;
use Gereq\Api\Entity\ApiToken;
use Gereq\Api\Http\ApiAuthMiddleware;
use Gereq\Auth\Authenticator;
use Gereq\Config\Repository;
use Gereq\Security\Csrf;
use Gereq\Http\Route;
use Gereq\User\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ApiAuthCsrfTest extends TestCase
{
    /** @return array{ApiAuthMiddleware, Csrf, Request} */
    private function fixture(string $method, bool $validBearer = false): array
    {
        $request = Request::create('/api/v1/cms/pages/test/blocks', $method);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);
        $csrf = new Csrf($stack);
        $user = new User('Editor', 'editor@example.test', 'hash');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($user);
        $token = new ApiToken('Test', $user->id(), 'grq_test_', hash('sha256', 'valid-token'), ['cms.write']);
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($validBearer ? $token : null);
        $em->method('getRepository')->willReturn($repo);
        $authRef = new \ReflectionClass(Authenticator::class);
        $auth = $authRef->newInstanceWithoutConstructor();
        $authRef->getProperty('requestStack')->setValue($auth, $stack);
        $authRef->getProperty('user')->setValue($auth, $user);
        $authRef->getProperty('identityResolved')->setValue($auth, true);
        $tokens = new ApiTokenService($em, new Repository([]));

        return [new ApiAuthMiddleware($auth, $tokens, $em, $csrf), $csrf, $request];
    }

    public function testSessionMutationsRequireCsrfIncludingInvalidBearerFallback(): void
    {
        foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
            [$middleware, , $request] = $this->fixture($method);
            $request->headers->set('Origin', 'https://untrusted.example.test');
            $next = static fn (): Response => new Response('mutation ran');
            self::assertSame(419, $middleware->process($request, $next)->getStatusCode());
            $request->headers->set('Authorization', 'Bearer invalid-token');
            self::assertSame(419, $middleware->process($request, $next)->getStatusCode());
        }
    }

    public function testValidSessionTokensAllowHeaderAndFormMutations(): void
    {
        foreach (['header', 'form'] as $transport) {
            [$middleware, $csrf, $request] = $this->fixture('POST');
            if ($transport === 'header') {
                $request->headers->set('X-CSRF-TOKEN', $csrf->token());
            } else {
                $request->request->set('_csrf', $csrf->token());
            }
            self::assertSame(200, $middleware->process($request, static fn (): Response => new Response('ok'))->getStatusCode());
        }
    }

    public function testInvalidAndArrayTokensAreRejected(): void
    {
        [$middleware, , $request] = $this->fixture('POST');
        foreach (['invalid', ['invalid']] as $value) {
            $request->request->set('_csrf', $value);
            self::assertSame(419, $middleware->process($request, static fn (): Response => new Response('bad'))->getStatusCode());
        }
    }

    public function testAuthenticatedBearerMutationsDoNotRequireCsrf(): void
    {
        [$middleware, , $request] = $this->fixture('POST', true);
        $request->headers->set('Authorization', 'Bearer valid-token');
        self::assertSame(200, $middleware->process($request, static fn (): Response => new Response('ok'))->getStatusCode());
    }

    public function testSafeSessionRequestsDoNotRequireCsrf(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            [$middleware, , $request] = $this->fixture($method);
            self::assertSame(200, $middleware->process($request, static fn (): Response => new Response('ok'))->getStatusCode());
        }
    }
    public function testCsrfDoesNotBypassSessionPermissionsOrBearerScopes(): void
    {
        [$middleware, $csrf, $request] = $this->fixture('POST');
        $request->headers->set('X-CSRF-TOKEN', $csrf->token());
        $request->attributes->set('_gereq_route', (new Route('/api/test', ['POST'], static fn (): Response => new Response()))->scope('cms.write'));
        self::assertSame(403, $middleware->process($request, static fn (): Response => new Response('bad'))->getStatusCode());

        [$middleware, , $request] = $this->fixture('POST', true);
        $request->headers->set('Authorization', 'Bearer valid-token');
        $request->attributes->set('_gereq_route', (new Route('/api/test', ['POST'], static fn (): Response => new Response()))->scope('billing.write'));
        self::assertSame(403, $middleware->process($request, static fn (): Response => new Response('bad'))->getStatusCode());
    }
}
