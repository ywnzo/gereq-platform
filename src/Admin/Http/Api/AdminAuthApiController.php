<?php

declare(strict_types=1);

namespace Gereq\Admin\Http\Api;

use Gereq\Api\ApiResponse;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Security\RateLimiter;
use Gereq\User\Entity\User;
use Gereq\Validation\ValidationRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class AdminAuthApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly RateLimiter $limiter,
        private readonly ValidationRegistry $validation,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function login(Request $request): JsonResponse
    {
        $ip = (string) $request->getClientIp();
        if (!$this->limiter->hit('api-admin-login:' . $ip, (int) config('security.login_max_attempts', 5), (int) config('security.login_decay_seconds', 60))) {
            return ApiResponse::error('rate_limited', 'Too many login attempts.', [], 429);
        }

        $body = $this->body($request);
        try {
            $data = $this->validation->validate('auth.login', $body);
        } catch (\Throwable $e) {
            return ApiResponse::error('validation_failed', 'Invalid credentials payload.', [], 422);
        }

        $email = strtolower(trim((string) $data['email']));
        $password = (string) $data['password'];
        $remember = in_array($data['remember'] ?? false, [true, 1, '1', 'on', 'true'], true);

        if (!$this->auth->attempt($email, $password, $remember)) {
            return ApiResponse::error('invalid_credentials', 'Invalid email or password.', [], 401);
        }

        $this->limiter->clear('api-admin-login:' . $ip);

        if ($this->auth->isMfaPending()) {
            $this->auth->logout();

            return ApiResponse::error(
                'mfa_required',
                'MFA is required. Complete login in the platform admin.',
                [],
                403,
            );
        }

        $user = $this->auth->user();
        if (!$user instanceof User) {
            return ApiResponse::unauthorized();
        }

        return ApiResponse::data($this->serializeUser($user));
    }

    public function me(): JsonResponse
    {
        $user = $this->auth->user();
        if (!$user instanceof User) {
            return ApiResponse::unauthorized();
        }

        return ApiResponse::data($this->serializeUser($user));
    }

    public function logout(): JsonResponse
    {
        $this->auth->logout();

        return ApiResponse::data(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function serializeUser(User $user): array
    {
        $roles = [];
        foreach ($user->roles() as $role) {
            $roles[] = $role->name();
        }

        return [
            'id' => $user->id(),
            'name' => $user->name(),
            'email' => $user->email(),
            'roles' => $roles,
            'permissions' => [
                'events.view' => $user->can('events.view'),
                'events.manage' => $user->can('events.manage'),
                'customers.view' => $user->can('customers.view'),
                'customers.manage' => $user->can('customers.manage') || $user->can('customers.edit') || $user->can('customers.create'),
            ],
            'platform_admin_url' => \Gereq\Support\AdminPath::base(),
        ];
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
