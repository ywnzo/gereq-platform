<?php

declare(strict_types=1);

namespace Modules\Customers\Application;

use Gereq\Config\Repository as Config;
use Modules\Customers\Domain\CustomerAccount;

/** HMAC-signed storefront access tokens (JWT-like, no external JWT lib). */
final class StoreTokenService
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @return array{access_token: string, token_type: string, expires_in: int, expires_at: string} */
    public function issue(CustomerAccount $account, int $ttlSeconds = 604800): array
    {
        $exp = time() + max(300, $ttlSeconds);
        $payload = [
            'sub' => $account->id(),
            'cid' => $account->customerId(),
            'email' => $account->email(),
            'ver' => $account->tokenVersion(),
            'exp' => $exp,
            'iat' => time(),
        ];
        $body = $this->b64(json_encode($payload, JSON_THROW_ON_ERROR));
        $sig = hash_hmac('sha256', $body, $this->key());

        return [
            'access_token' => $body . '.' . $sig,
            'token_type' => 'Bearer',
            'expires_in' => $exp - time(),
            'expires_at' => (new \DateTimeImmutable('@' . $exp))->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array{sub: string, cid: string, email: string, ver: int, exp: int}|null */
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
        if (!is_array($json) || !isset($json['sub'], $json['exp'])) {
            return null;
        }
        if ((int) $json['exp'] < time()) {
            return null;
        }

        return [
            'sub' => (string) $json['sub'],
            'cid' => (string) ($json['cid'] ?? ''),
            'email' => (string) ($json['email'] ?? ''),
            'ver' => (int) ($json['ver'] ?? 0),
            'exp' => (int) $json['exp'],
        ];
    }

    private function key(): string
    {
        $key = (string) $this->config->get('app.key', '');

        return $key !== '' ? $key : 'gereq-store-token';
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
