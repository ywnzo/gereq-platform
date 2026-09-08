<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Http\FormState;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class FormStateTest extends TestCase
{
    public function testPersistsOldInputAndErrorsUntilForget(): void
    {
        $state = $this->state();
        $state->fail(new ValidationException(
            ['email' => 'Zadejte platný e-mail.'],
            ['email' => 'neplatny', 'password' => 'secret'],
            'auth.login',
        ));

        self::assertTrue($state->hasErrors('auth.login'));
        self::assertSame('neplatny', $state->old('email'));
        self::assertNull($state->old('password'));
        self::assertSame('Zadejte platný e-mail.', $state->error('email'));

        $state->forget('auth.login');
        self::assertFalse($state->hasErrors('auth.login'));
        self::assertNull($state->old('email', null, 'auth.login'));
    }

    public function testForgetFlashesMatchingRequestFingerprint(): void
    {
        $request = Request::create('/admin/login', 'POST', ['_gereq_fp' => 'abc123fingerprint']);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);
        $state = new FormState($stack, new PassthroughTranslator());

        $state->fail(new ValidationException(
            ['email' => 'Zadejte platný e-mail.'],
            ['email' => 'neplatny'],
            'auth.login',
        ));
        $state->forget('auth.login');

        self::assertSame(['auth.login'], $session->getFlashBag()->get('form_cleared'));
        self::assertSame(['abc123fingerprint'], $session->getFlashBag()->get('form_fp_cleared'));
    }

    public function testHtmlRedirectStaysOnSameOrigin(): void
    {
        $request = Request::create('/design.html', 'POST', server: [
            'HTTP_HOST' => 'example.test',
            'HTTP_REFERER' => 'https://evil.example/steal',
        ]);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);
        $state = new FormState($stack, new PassthroughTranslator());

        $response = $state->respond($request, new ValidationException(
            ['name' => 'Toto pole je povinné.'],
            [],
            'design.demo',
        ));

        self::assertNotNull($response);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/design.html', $response->headers->get('Location'));
    }

    private function state(): FormState
    {
        $request = Request::create('/admin/login', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);

        return new FormState($stack, new PassthroughTranslator());
    }
}
