<?php

declare(strict_types=1);

namespace Gereq\Customers\Application;

use Gereq\Config\Repository as Config;
use Gereq\Customers\Domain\CustomerAccount;

/**
 * One-time HMAC tokens for completing soft storefront registration.
 * Not an access token — cannot call authenticated store APIs.
 */
final class StoreCompletionTokenService
{
    private const PURPOSE = 'store_complete';

    public function __construct(private readonly Config $config)
    {
    }

    /** @return array{completion_token: string, expires_in: int, expires_at: string} */
    public function issue(CustomerAccount $account, int $ttlSeconds = 86400): array
    {
        if (!$account->isSoft()) {
            throw new \InvalidArgumentException('customers.error.already_registered');
        }

        $exp = time() + max(300, $ttlSeconds);
        $payload = [
            'purpose' => self::PURPOSE,
            'sub' => $account->id(),
            'email' => $account->email(),
            'ver' => $account->tokenVersion(),
            'exp' => $exp,
            'iat' => time(),
        ];
        $body = $this->b64(json_encode($payload, JSON_THROW_ON_ERROR));
        $sig = hash_hmac('sha256', $body, $this->key());

        return [
            'completion_token' => $body . '.' . $sig,
            'expires_in' => $exp - time(),
            'expires_at' => (new \DateTimeImmutable('@' . $exp))->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array{sub: string, email: string, ver: int, exp: int}|null */
    public function parse(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $sig] = $parts;
        if (!hash_equals(hash_hmac('sha256', $body, $this->key()), $sig)) {
            return null;
        }
        $json = json_decode((string) $this->ub64($body), true);
        if (!is_array($json) || ($json['purpose'] ?? null) !== self::PURPOSE) {
            return null;
        }
        if (!isset($json['sub'], $json['email'], $json['exp'])) {
            return null;
        }
        if ((int) $json['exp'] < time()) {
            return null;
        }

        return [
            'sub' => (string) $json['sub'],
            'email' => strtolower(trim((string) $json['email'])),
            'ver' => (int) ($json['ver'] ?? 0),
            'exp' => (int) $json['exp'],
        ];
    }

    public function matches(CustomerAccount $account, string $token): bool
    {
        $claims = $this->parse($token);
        if ($claims === null) {
            return false;
        }
        if ($claims['sub'] !== $account->id()) {
            return false;
        }
        if ($claims['email'] !== $account->email()) {
            return false;
        }
        if ($claims['ver'] !== $account->tokenVersion()) {
            return false;
        }

        return $account->isSoft();
    }

    private function key(): string
    {
        $key = trim((string) $this->config->get('app.key', ''));
        if ($key === '') {
            throw new \RuntimeException('APP_KEY is required for store completion tokens.');
        }

        return $key;
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function ub64(string $raw): string
    {
        $remainder = strlen($raw) % 4;
        if ($remainder > 0) {
            $raw .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($raw, '-_', '+/'), true);
    }
}
