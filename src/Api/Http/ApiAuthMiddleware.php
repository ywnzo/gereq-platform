<?php

declare(strict_types=1);

namespace Gereq\Api\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Api\Auth\ApiTokenService;
use Gereq\Api\Entity\ApiToken;
use Gereq\Auth\Authenticator;
use Gereq\Http\Middleware\MiddlewareInterface;
use Gereq\Http\Route;
use Gereq\User\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Authenticator $auth,
        private readonly ApiTokenService $tokens,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $token = $this->authenticateBearer($request);
        if ($token !== null) {
            $user = $this->em->find(User::class, $token->userId());
            if (!$user instanceof User || !$user->isActive()) {
                return ApiResponse::unauthorized();
            }
            $request->attributes->set('_api_token', $token);
            $request->attributes->set('_api_actor', ['type' => 'api_token', 'id' => $token->id()]);
            $this->tokens->touch($token);
            $this->authViaRequest($request, $user);
        } elseif ($this->auth->check()) {
            $request->attributes->set('_api_actor', ['type' => 'user', 'id' => $this->auth->id()]);
        } else {
            return ApiResponse::unauthorized();
        }

        $route = $this->currentRoute($request);
        if ($route !== null) {
            $scopes = $route->getScopes();
            $user = $this->auth->user();
            if ($user === null) {
                return ApiResponse::forbidden();
            }

            $apiToken = $request->attributes->get('_api_token');
            if ($apiToken instanceof ApiToken) {
                if ($scopes !== [] && !$apiToken->hasAnyScope($scopes)) {
                    return ApiResponse::forbidden('Missing required API scope.');
                }
                // Token scopes are an upper bound; owner RBAC still applies.
                if ($scopes !== [] && !$this->sessionAllows($user, $scopes, $route->getPermission())) {
                    return ApiResponse::forbidden();
                }
                if ($route->getPermission() !== null && !$user->can($route->getPermission())) {
                    return ApiResponse::forbidden();
                }
            } else {
                if ($scopes !== [] && !$this->sessionAllows($user, $scopes, $route->getPermission())) {
                    return ApiResponse::forbidden();
                }
                if ($route->getPermission() !== null && !$user->can($route->getPermission())) {
                    return ApiResponse::forbidden();
                }
            }
        }

        return $next($request);
    }

    private function authenticateBearer(Request $request): ?ApiToken
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!preg_match('/^Bearer\s+(\S+)/i', $header, $m)) {
            return null;
        }

        return $this->tokens->findByPlain($m[1]);
    }

    private function authViaRequest(Request $request, User $user): void
    {
        // Soft-bind user for Authenticator::user() during this request without session write.
        $ref = new \ReflectionClass($this->auth);
        if ($ref->hasProperty('user')) {
            $prop = $ref->getProperty('user');
            $prop->setAccessible(true);
            $prop->setValue($this->auth, $user);
        }
        $request->attributes->set('_api_user', $user);
    }

    private function currentRoute(Request $request): ?Route
    {
        $route = $request->attributes->get('_gereq_route');

        return $route instanceof Route ? $route : null;
    }

    /** @param list<string> $scopes */
    private function sessionAllows(User $user, array $scopes, ?string $permission): bool
    {
        if ($permission !== null && $user->can($permission)) {
            return true;
        }
        foreach ($scopes as $scope) {
            if ($user->can($scope)) {
                return true;
            }
            // customers.read -> customers.view / customers.manage; crm.deals.read -> crm.view / crm.deals.manage
            if (str_ends_with($scope, '.read')) {
                $base = substr($scope, 0, -5);
                $module = explode('.', $base)[0] ?? $base;
                if (
                    $user->can($base . '.view')
                    || $user->can($base . '.manage')
                    || $user->can($module . '.view')
                    || $user->can($module . '.manage')
                ) {
                    return true;
                }
            }
            if (str_ends_with($scope, '.write')) {
                $base = substr($scope, 0, -6);
                $module = explode('.', $base)[0] ?? $base;
                if (
                    $user->can($base . '.edit')
                    || $user->can($base . '.create')
                    || $user->can($base . '.manage')
                    || $user->can($module . '.manage')
                    || $user->can($module . '.edit')
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}
