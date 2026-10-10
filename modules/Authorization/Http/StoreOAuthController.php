<?php

declare(strict_types=1);

namespace Modules\Authorization\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Customers\Application\StoreCookieBridge;
use Gereq\Customers\Application\StoreTokenService;
use Gereq\Customers\Domain\CustomerAccount;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Authorization\Application\OauthUnlinkBlockedException;
use Modules\Authorization\Application\OAuthProviderRegistry;
use Modules\Authorization\Application\StoreOAuthService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class StoreOAuthController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly StoreOAuthService $oauth,
        private readonly OAuthProviderRegistry $registry,
        private readonly StoreCookieBridge $cookies,
        private readonly StoreTokenService $tokens,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $account = $this->accountFromRequest($request);
        if ($account === null) {
            return ApiResponse::error('unauthorized', 'Missing bearer/cookie token', [], 401);
        }
        $linked = [];
        foreach ($this->oauth->publicLinks($account->customerId()) as $row) {
            $linked[$row['id']] = $row;
        }
        $providers = [];
        foreach ($this->registry->publicStatus()['providers'] as $provider) {
            $id = (string) ($provider['id'] ?? '');
            $row = $linked[$id] ?? null;
            $providers[] = [
                'id' => $id,
                'label' => $provider['label'] ?? $id,
                'linked' => $row !== null,
                'email' => $row['email'] ?? null,
            ];
        }

        return ApiResponse::data(['providers' => $providers]);
    }

    public function destroy(Request $request, string $provider): Response
    {
        $account = $this->accountFromRequest($request);
        if ($account === null) {
            return ApiResponse::error('unauthorized', 'Missing bearer/cookie token', [], 401);
        }
        try {
            $this->oauth->unlink($account, strtolower($provider));
        } catch (OauthUnlinkBlockedException) {
            return ApiResponse::error(
                'oauth_unlink_blocked',
                'Nejdřív nastavte heslo, nebo nechte aspoň jedno sociální přihlášení.',
                [],
                422,
            );
        }

        return $this->index($request);
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
}
