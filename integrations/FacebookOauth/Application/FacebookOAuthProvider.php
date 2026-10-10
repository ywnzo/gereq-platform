<?php

declare(strict_types=1);

namespace Integrations\FacebookOauth\Application;

use Modules\Authorization\Application\AbstractOAuth2Provider;

final class FacebookOAuthProvider extends AbstractOAuth2Provider
{
    public function id(): string
    {
        return 'facebook';
    }

    public function label(): string
    {
        return 'Facebook';
    }

    protected function clientIdKey(): string
    {
        return 'auth.facebook.app_id';
    }

    protected function clientSecretKey(): string
    {
        return 'auth.facebook.app_secret';
    }

    protected function clientIdEnv(): string
    {
        return 'FACEBOOK_APP_ID';
    }

    protected function clientSecretEnv(): string
    {
        return 'FACEBOOK_APP_SECRET';
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return 'https://www.facebook.com/v21.0/dialog/oauth?' . http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => 'email,public_profile',
        ]);
    }

    public function profile(string $code, string $redirectUri): array
    {
        $token = $this->getJson('https://graph.facebook.com/v21.0/oauth/access_token?' . http_build_query([
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]));
        $access = (string) ($token['access_token'] ?? '');
        if ($access === '') {
            throw new \RuntimeException('Facebook token se nepodařilo získat.');
        }
        $info = $this->getJson('https://graph.facebook.com/me?fields=id,name,email&access_token=' . rawurlencode($access));
        $email = strtolower(trim((string) ($info['email'] ?? '')));
        if ($email === '') {
            throw new \RuntimeException('Facebook účet nemá e-mail.');
        }

        return [
            'id' => (string) ($info['id'] ?? ''),
            'email' => $email,
            'name' => trim((string) ($info['name'] ?? $email)),
        ];
    }
}
