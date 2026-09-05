<?php

declare(strict_types=1);

namespace Modules\Customers\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Customers\Application\StoreAuthService;
use Modules\Customers\Application\StoreCookieBridge;
use Modules\Customers\Application\StoreTokenService;
use Modules\Customers\Domain\CustomerAccount;
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
        $token = $this->cookies->tokenFromRequest($request);
        if ($token === null) {
            return ApiResponse::error('unauthorized', 'Missing bearer/cookie token', [], 401);
        }
        $claims = $this->tokens->parse($token);
        if ($claims === null) {
            return ApiResponse::error('unauthorized', 'Invalid token', [], 401);
        }
        $account = $this->em->find(CustomerAccount::class, $claims['sub']);
        if (!$account instanceof CustomerAccount) {
            return ApiResponse::error('unauthorized', 'Account not found', [], 401);
        }
        if ($account->tokenVersion() !== $claims['ver']) {
            return ApiResponse::error('unauthorized', 'Token revoked', [], 401);
        }

        return ApiResponse::data($this->auth->serialize($account));
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
