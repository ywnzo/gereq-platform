<?php

declare(strict_types=1);

namespace Gereq\Customers\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Gereq\Customers\Application\StoreAuthService;
use Gereq\Customers\Application\StoreCookieBridge;
use Gereq\Customers\Application\StoreTokenService;
use Gereq\Customers\Domain\CustomerAccount;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Storefront customer auth — Bearer + HttpOnly cookie bridge. */
final class StoreAuthApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly StoreAuthService $auth,
        private readonly StoreTokenService $tokens,
        private readonly StoreCookieBridge $cookies,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function register(Request $request): Response
    {
        $data = gereq()->make(ValidationRegistry::class)->validate('customers.store.register', $this->body($request));
        try {
            $account = $this->auth->register(
                (string) $data['email'],
                (string) $data['password'],
                trim((string) ($data['name'] ?? '')),
                isset($data['phone']) ? (string) $data['phone'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }

        return $this->authedResponse($account, 201);
    }

    public function softRegister(Request $request): JsonResponse
    {
        $data = gereq()->make(ValidationRegistry::class)->validate('customers.store.soft_register', $this->body($request));
        $account = $this->auth->softRegister(
            (string) $data['email'],
            trim((string) ($data['name'] ?? $data['email'])),
            isset($data['phone']) ? (string) $data['phone'] : null,
        );

        $payload = $this->auth->serialize($account);
        if ($account->isActive()) {
            $payload = array_merge($payload, $this->tokens->issue($account));
        }

        return ApiResponse::data($payload, 201);
    }

    public function complete(Request $request): Response
    {
        $data = gereq()->make(ValidationRegistry::class)->validate('customers.store.complete', $this->body($request));
        $account = $this->auth->findAccountByEmail((string) $data['email']);
        if ($account === null) {
            return ApiResponse::notFound();
        }
        $this->auth->completeRegistration(
            $account,
            (string) $data['password'],
            isset($data['name']) ? (string) $data['name'] : null,
        );

        return $this->authedResponse($account);
    }

    public function login(Request $request): Response
    {
        $data = gereq()->make(ValidationRegistry::class)->validate('customers.store.login', $this->body($request));
        $account = $this->auth->attempt((string) $data['email'], (string) $data['password']);
        if ($account === null) {
            return ApiResponse::error('auth_failed', 'Invalid credentials', [], 401);
        }

        return $this->authedResponse($account);
    }

    public function logout(): Response
    {
        $response = ApiResponse::data(['ok' => true]);

        return $this->cookies->clear($response);
    }

    public function me(Request $request): JsonResponse
    {
        $account = $this->accountFromRequest($request);
        if ($account === null) {
            return ApiResponse::error('unauthorized', 'Missing bearer/cookie token', [], 401);
        }

        return ApiResponse::data($this->auth->serialize($account));
    }

    public function update(Request $request): Response
    {
        $account = $this->accountFromRequest($request);
        if ($account === null) {
            return ApiResponse::error('unauthorized', 'Missing bearer/cookie token', [], 401);
        }

        $raw = $this->body($request);
        foreach (['name', 'phone', 'current_password', 'password'] as $key) {
            if (array_key_exists($key, $raw) && is_string($raw[$key]) && trim($raw[$key]) === '') {
                $raw[$key] = null;
            }
        }
        $data = gereq()->make(ValidationRegistry::class)->validate('customers.store.update', $raw);
        try {
            $account = $this->auth->updateProfile($account, $data);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }

        // Password change bumps token version — re-issue cookie/bearer.
        if (!empty($data['password'])) {
            return $this->authedResponse($account);
        }

        return ApiResponse::data($this->auth->serialize($account));
    }

    private function accountFromRequest(Request $request): ?CustomerAccount
    {
        $token = $this->cookies->tokenFromRequest($request);
        if ($token === null) {
            return null;
        }
        $claims = $this->tokens->parse($token);
        if ($claims === null) {
            return null;
        }
        $account = $this->em->find(CustomerAccount::class, $claims['sub']);
        if (!$account instanceof CustomerAccount) {
            return null;
        }
        if ($account->tokenVersion() !== $claims['ver']) {
            return null;
        }

        return $account;
    }

    private function authedResponse(CustomerAccount $account, int $status = 200): Response
    {
        $response = ApiResponse::data(array_merge(
            $this->auth->serialize($account),
            $this->tokens->issue($account),
        ), $status);

        return $this->cookies->attach($response, $account);
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = $request->request->all();
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_merge($data, $decoded);
            }
        }

        return $data;
    }
}
