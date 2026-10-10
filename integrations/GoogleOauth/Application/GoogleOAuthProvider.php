<?php

declare(strict_types=1);

namespace Integrations\GoogleOauth\Application;

use Modules\Authorization\Application\AbstractOAuth2Provider;

final class GoogleOAuthProvider extends AbstractOAuth2Provider
{
    public function id(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google';
    }

    protected function clientIdKey(): string
    {
        return 'auth.google.client_id';
    }

    protected function clientSecretKey(): string
    {
        return 'auth.google.client_secret';
    }

    protected function clientIdEnv(): string
    {
        return 'GOOGLE_CLIENT_ID';
    }

    protected function clientSecretEnv(): string
    {
        return 'GOOGLE_CLIENT_SECRET';
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);
    }

    public function profile(string $code, string $redirectUri): array
    {
        $token = $this->postForm('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);
        $access = (string) ($token['access_token'] ?? '');
        if ($access === '') {
            throw new \RuntimeException('Google token se nepodařilo získat.');
        }
        $info = $this->getJson('https://www.googleapis.com/oauth2/v2/userinfo', $access);
        $email = strtolower(trim((string) ($info['email'] ?? '')));
        if ($email === '') {
            throw new \RuntimeException('Google účet nemá e-mail.');
        }

        return [
            'id' => (string) ($info['id'] ?? $info['sub'] ?? ''),
            'email' => $email,
            'name' => trim((string) ($info['name'] ?? $email)),
        ];
    }
}
