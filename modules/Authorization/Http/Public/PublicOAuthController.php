<?php

declare(strict_types=1);

namespace Modules\Authorization\Http\Public;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Customers\Application\StoreCookieBridge;
use Gereq\Customers\Application\StoreTokenService;
use Gereq\Customers\Domain\CustomerAccount;
use Gereq\Http\PublicCors;
use Gereq\Http\PublicUrl;
use Modules\Authorization\Application\OauthIdentityTakenException;
use Modules\Authorization\Application\OauthNotLinkedException;
use Modules\Authorization\Application\OAuthProviderRegistry;
use Modules\Authorization\Application\OAuthUrls;
use Modules\Authorization\Application\StoreOAuthService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicOAuthController
{
    public function __construct(
        private readonly OAuthProviderRegistry $registry,
        private readonly OAuthUrls $urls,
        private readonly StoreOAuthService $accounts,
        private readonly StoreCookieBridge $cookies,
        private readonly StoreTokenService $tokens,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function options(Request $request): Response
    {
        return PublicCors::apply(new Response('', 204), $request, 'GET, OPTIONS');
    }

    public function providers(Request $request): Response
    {
        return PublicCors::apply(ApiResponse::data($this->registry->publicStatus()), $request);
    }

    public function start(Request $request, string $provider): Response
    {
        $driver = $this->registry->get(strtolower($provider));
        if ($driver === null || !$driver->configured()) {
            return PublicCors::apply(
                ApiResponse::error('oauth_not_configured', 'Přihlášení přes ' . $provider . ' ještě není nastavené.', [], 503),
                $request,
            );
        }
        $intent = (string) $request->query->get('intent', 'login');
        $next = (string) $request->query->get('next', '');
        if ($intent === 'link') {
            $account = $this->accountFromRequest($request);
            if ($account === null) {
                return $this->frontendRedirect($request, 'need_login', $next);
            }
            $request->getSession()->set('auth_oauth_link_customer', $account->customerId());
        } else {
            $request->getSession()->remove('auth_oauth_link_customer');
        }
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set('auth_oauth_state', $state);
        $request->getSession()->set('auth_oauth_next', $next);
        $request->getSession()->set('auth_oauth_intent', $intent === 'link' ? 'link' : 'login');
        $redirect = $this->urls->callbackUrl($request, $driver->id());

        return new RedirectResponse($driver->authorizeUrl($redirect, $state));
    }

    public function callback(Request $request, string $provider): Response
    {
        $driver = $this->registry->get(strtolower($provider));
        if ($driver === null) {
            return PublicCors::apply(ApiResponse::error('oauth_not_configured', 'Neznámý poskytovatel.', [], 404), $request);
        }
        $state = (string) $request->query->get('state', '');
        $expected = (string) $request->getSession()->get('auth_oauth_state', '');
        if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
            return PublicCors::apply(ApiResponse::error('oauth_state', 'Neplatný OAuth stav.', [], 400), $request);
        }
        $code = (string) $request->query->get('code', '');
        $next = (string) $request->getSession()->get('auth_oauth_next', '');
        $intent = (string) $request->getSession()->get('auth_oauth_intent', 'login');
        $linkCustomer = (string) $request->getSession()->get('auth_oauth_link_customer', '');
        $request->getSession()->remove('auth_oauth_state');
        $request->getSession()->remove('auth_oauth_intent');
        $request->getSession()->remove('auth_oauth_link_customer');
        if ($code === '') {
            return $this->frontendRedirect($request, 'denied', $next);
        }
        $redirect = $this->urls->callbackUrl($request, $driver->id());
        try {
            $profile = $driver->profile($code, $redirect);
        } catch (\Throwable $e) {
            return $this->frontendRedirect($request, 'failed', $next);
        }
        if ($profile['id'] === '') {
            return $this->frontendRedirect($request, 'failed', $next);
        }

        if ($intent === 'link') {
            $account = $this->accounts->accountByCustomerId($linkCustomer);
            if ($account === null) {
                return $this->frontendRedirect($request, 'need_login', $next !== '' ? $next : '/profil/nastaveni');
            }
            try {
                $this->accounts->link($account, $driver->id(), $profile['id'], $profile['email']);
            } catch (OauthIdentityTakenException) {
                return $this->frontendRedirect($request, 'taken', $next !== '' ? $next : '/profil/nastaveni');
            }

            return $this->frontendRedirect($request, 'linked', $next !== '' ? $next : '/profil/nastaveni');
        }

        try {
            $result = $this->accounts->loginOrRegister($driver->id(), $profile['id'], $profile['email'], $profile['name']);
        } catch (OauthNotLinkedException) {
            return $this->frontendRedirect($request, 'unlinked', $next);
        }
        $account = $result['account'];
        $authFlag = $result['created'] ? 'registered' : 'ok';

        $payload = array_merge(
            [
                'id' => $account->id(),
                'customer_id' => $account->customerId(),
                'email' => $account->email(),
            ],
            $this->tokens->issue($account),
        );
        $fe = $this->urls->frontendOrigin();
        if ($fe === '') {
            $response = ApiResponse::data($payload);
            $this->cookies->attach($response, $account);

            return PublicCors::apply($response, $request);
        }
        $path = ($next !== '' && str_starts_with($next, '/')) ? $next : '/';
        // Put credentials in the URL hash (not query): not sent to servers, proxies, or Referer.
        // HttpOnly cookie is also attached for same-site API calls.
        $url = $fe . $path . '#auth=' . rawurlencode($authFlag)
            . '&store_token=' . rawurlencode((string) $payload['access_token']);
        $response = new RedirectResponse($url);
        $this->cookies->attach($response, $account);

        return $response;
    }

    private function frontendRedirect(Request $request, string $auth, string $next): Response
    {
        $fe = $this->urls->frontendOrigin();
        if ($fe === '') {
            $fe = PublicUrl::app();
        }
        if ($fe === '') {
            return PublicCors::apply(ApiResponse::error('oauth_' . $auth, $auth, [], 400), $request);
        }
        $path = ($next !== '' && str_starts_with($next, '/')) ? $next : '/';
        $sep = str_contains($path, '?') ? '&' : '?';

        return new RedirectResponse($fe . $path . $sep . 'auth=' . rawurlencode($auth));
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
