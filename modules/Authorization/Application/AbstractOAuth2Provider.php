<?php

declare(strict_types=1);

namespace Modules\Authorization\Application;

use Gereq\Setting\Settings;

abstract class AbstractOAuth2Provider implements OAuthProviderInterface
{
    public function __construct(protected readonly Settings $settings)
    {
    }

    abstract protected function clientIdKey(): string;

    abstract protected function clientSecretKey(): string;

    abstract protected function clientIdEnv(): string;

    abstract protected function clientSecretEnv(): string;

    public function configured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    protected function clientId(): string
    {
        return $this->credential($this->clientIdKey(), $this->clientIdEnv());
    }

    protected function clientSecret(): string
    {
        return $this->credential($this->clientSecretKey(), $this->clientSecretEnv());
    }

    protected function credential(string $settingKey, string $envKey): string
    {
        $value = trim((string) $this->settings->get($settingKey, ''));
        if ($value !== '') {
            return $value;
        }

        return trim((string) env_value($envKey, ''));
    }

    /** @param array<string, string> $fields */
    protected function postForm(string $url, array $fields, array $headers = []): array
    {
        $header = "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n";
        foreach ($headers as $line) {
            $header .= $line . "\r\n";
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $header,
                'content' => http_build_query($fields),
                'timeout' => 20,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('OAuth token request selhal.');
        }
        $json = json_decode($raw, true);

        return is_array($json) ? $json : [];
    }

    protected function getJson(string $url, ?string $bearer = null, array $headers = []): array
    {
        $header = "Accept: application/json\r\n";
        if ($bearer !== null) {
            $header .= 'Authorization: Bearer ' . $bearer . "\r\n";
        }
        foreach ($headers as $line) {
            $header .= $line . "\r\n";
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $header,
                'timeout' => 20,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('OAuth profile request selhal.');
        }
        $json = json_decode($raw, true);

        return is_array($json) ? $json : [];
    }
}
