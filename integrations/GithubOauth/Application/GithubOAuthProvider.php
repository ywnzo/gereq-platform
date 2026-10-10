<?php

declare(strict_types=1);

namespace Integrations\GithubOauth\Application;

use Modules\Authorization\Application\AbstractOAuth2Provider;

final class GithubOAuthProvider extends AbstractOAuth2Provider
{
    public function id(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub';
    }

    protected function clientIdKey(): string
    {
        return 'auth.github.client_id';
    }

    protected function clientSecretKey(): string
    {
        return 'auth.github.client_secret';
    }

    protected function clientIdEnv(): string
    {
        return 'GITHUB_CLIENT_ID';
    }

    protected function clientSecretEnv(): string
    {
        return 'GITHUB_CLIENT_SECRET';
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return 'https://github.com/login/oauth/authorize?' . http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'scope' => 'read:user user:email',
            'state' => $state,
        ]);
    }

    public function profile(string $code, string $redirectUri): array
    {
        $token = $this->postForm('https://github.com/login/oauth/access_token', [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ], ['User-Agent: GEREQ-Authorization']);
        $access = (string) ($token['access_token'] ?? '');
        if ($access === '') {
            throw new \RuntimeException('GitHub token se nepodařilo získat.');
        }
        $info = $this->getJson('https://api.github.com/user', $access, ['User-Agent: GEREQ-Authorization']);
        $email = strtolower(trim((string) ($info['email'] ?? '')));
        if ($email === '') {
            $emails = $this->getJson('https://api.github.com/user/emails', $access, ['User-Agent: GEREQ-Authorization']);
            if (is_array($emails)) {
                foreach ($emails as $row) {
                    if (is_array($row) && !empty($row['primary']) && !empty($row['email'])) {
                        $email = strtolower(trim((string) $row['email']));
                        break;
                    }
                }
                if ($email === '') {
                    foreach ($emails as $row) {
                        if (is_array($row) && !empty($row['email'])) {
                            $email = strtolower(trim((string) $row['email']));
                            break;
                        }
                    }
                }
            }
        }
        if ($email === '') {
            throw new \RuntimeException('GitHub účet nemá e-mail.');
        }
        $name = trim((string) ($info['name'] ?? $info['login'] ?? $email));

        return [
            'id' => (string) ($info['id'] ?? ''),
            'email' => $email,
            'name' => $name !== '' ? $name : $email,
        ];
    }
}
