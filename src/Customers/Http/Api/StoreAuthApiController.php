<?php

declare(strict_types=1);

namespace Gereq\Customers\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Gereq\Customers\Application\StoreAuthService;
use Gereq\Customers\Application\StoreCompletionTokenService;
use Gereq\Customers\Application\StoreCookieBridge;
use Gereq\Customers\Application\StoreTokenService;
use Gereq\Customers\Domain\CustomerAccount;
use Gereq\Security\RateLimiter;
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
        private readonly StoreCompletionTokenService $completionTokens,
        private readonly StoreCookieBridge $cookies,
        private readonly RateLimiter $limiter,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function register(Request $request): Response
    {
        if (($denied = $this->gereqGuard($request)) !== null) {
            return $denied;
        }
        $data = gereq()->make(ValidationRegistry::class)->validate('customers.store.register', $this->body($request));
        $throttled = $this->throttleAuth($request, (string) $data['email']);
        if ($throttled !== null) {
            return $throttled;
        }
        try {
            $account = $this->auth->register(
                (string) $data['email'],
                (string) $data['password'],
                trim((string) ($data['name'] ?? '')),
                isset($data['phone']) ? (string) $data['phone'] : null,
                [
                    'region' => $data['region'] ?? null,
                    'locality' => $data['locality'] ?? null,
                    'newsletter' => $data['newsletter'] ?? false,
                    'categories' => $data['categories'] ?? [],
                ],
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }

        return $this->authedResponse($account, 201);
    }

    public function softRegister(Request $request): JsonResponse
    {
        $data = gereq()->make(ValidationRegistry::class)->validate('customers.store.soft_register', $this->body($request));
        $email = (string) $data['email'];
        $throttled = $this->throttleAuth($request, $email);
        if ($throttled !== null) {
            return $throttled;
        }
        $existing = $this->auth->findAccountByEmail($email);

        // Fully registered account: never issue tokens or leak profile (account-takeover vector).
        if ($existing instanceof CustomerAccount && !$existing->isSoft()) {
            return ApiResponse::data(['ok' => true], 200);
        }

        $created = $existing === null;
        $account = $this->auth->softRegister(
            $email,
            trim((string) ($data['name'] ?? $data['email'])),
            isset($data['phone']) ? (string) $data['phone'] : null,
        );

        // Soft accounts stay unauthenticated here. Access tokens are only issued after
        // password completion (with completion_token), login, or OAuth.
        if (!$created) {
            return ApiResponse::data(['ok' => true, 'is_soft' => true], 200);
        }

        return ApiResponse::data(array_merge(
            $this->auth->serialize($account),
            $this->completionTokens->issue($account),
        ), 201);
    }

    public function complete(Request $request): Response
    {
        $data = gereq()->make(ValidationRegistry::class)->validate('customers.store.complete', $this->body($request));
        $throttled = $this->throttleAuth($request, (string) $data['email']);
        if ($throttled !== null) {
            return $throttled;
        }
        $account = $this->auth->findAccountByEmail((string) $data['email']);
        if ($account === null) {
            return ApiResponse::notFound();
        }
        if (!$account->isSoft()) {
            return ApiResponse::error(
                'already_registered',
                'customers.error.already_registered',
                [],
                409,
            );
        }
        if (!$this->completionTokens->matches($account, (string) $data['completion_token'])) {
            return ApiResponse::error(
                'invalid_completion_token',
                'customers.error.completion_token',
                ['completion_token' => ['customers.error.completion_token']],
                422,
            );
        }

        try {
            $this->auth->completeRegistration(
                $account,
                (string) $data['password'],
                isset($data['name']) ? (string) $data['name'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }

        return $this->authedResponse($account);
    }

    public function login(Request $request): Response
    {
        if (($denied = $this->gereqGuard($request)) !== null) {
            return $denied;
        }
        $data = gereq()->make(ValidationRegistry::class)->validate('customers.store.login', $this->body($request));
        $email = (string) $data['email'];
        $throttled = $this->throttleAuth($request, $email);
        if ($throttled !== null) {
            return $throttled;
        }
        $account = $this->auth->attempt($email, (string) $data['password']);
        if ($account === null) {
            return ApiResponse::error('auth_failed', 'Invalid credentials', [], 401);
        }
        $this->clearAuthThrottle($request, $email);

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
        foreach (['name', 'phone', 'current_password', 'password', 'region', 'locality'] as $key) {
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

    public function erasure(Request $request): JsonResponse
    {
        $account = $this->accountFromRequest($request);
        if ($account === null) {
            return ApiResponse::error('unauthorized', 'Missing bearer/cookie token', [], 401);
        }
        $this->auth->requestErasure($account);

        return ApiResponse::data($this->auth->serialize($account));
    }

    public function destroy(Request $request): Response
    {
        $account = $this->accountFromRequest($request);
        if ($account === null) {
            return ApiResponse::error('unauthorized', 'Missing bearer/cookie token', [], 401);
        }
        $this->auth->deleteStoreAccount($account);
        $response = ApiResponse::data(['ok' => true, 'deleted' => true]);

        return $this->cookies->clear($response);
    }

    private function gereqGuard(Request $request): ?Response
    {
        if (!class_exists(\Modules\Gereq\Application\GereqFormGuard::class)) {
            return null;
        }

        return \Modules\Gereq\Application\GereqFormGuard::denyIfInvalid($request);
    }

    private function throttleAuth(Request $request, string $email): ?JsonResponse
    {
        $ip = (string) ($request->getClientIp() ?? 'anon');
        $emailKey = strtolower(trim($email));
        $max = (int) config('security.store_login_max_attempts', 5);
        $decay = (int) config('security.store_login_decay_seconds', 60);

        if (!$this->limiter->hit('store-auth:ip:' . $ip, $max, $decay)) {
            return ApiResponse::error('rate_limited', 'Too many auth attempts. Try again later.', [], 429);
        }
        if ($emailKey !== '' && !$this->limiter->hit('store-auth:email:' . $emailKey, $max, $decay)) {
            return ApiResponse::error('rate_limited', 'Too many auth attempts. Try again later.', [], 429);
        }

        return null;
    }

    private function clearAuthThrottle(Request $request, string $email): void
    {
        $ip = (string) ($request->getClientIp() ?? 'anon');
        $emailKey = strtolower(trim($email));
        $this->limiter->clear('store-auth:ip:' . $ip);
        if ($emailKey !== '') {
            $this->limiter->clear('store-auth:email:' . $emailKey);
        }
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
