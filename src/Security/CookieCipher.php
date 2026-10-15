<?php

declare(strict_types=1);

namespace Gereq\Security;

use Gereq\Config\Repository as Config;

/** AES-256-GCM cookie value cipher (same payload shape as settings vault). */
final class CookieCipher
{
    public function __construct(private readonly Config $config)
    {
    }

    public function encrypt(string $value): string
    {
        if ($value === '' || str_starts_with($value, 'gcm:')) {
            return $value;
        }

        $key = trim((string) $this->config->get('app.key', ''));
        if ($key === '') {
            // Soft skip: missing APP_KEY must not 500 the whole request pipeline.
            // Cookies stay plaintext until a key is configured (same as pre-encryption installs).
            return $value;
        }

        $binaryKey = hash('sha256', $key, true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', $binaryKey, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false || $tag === '') {
            throw new \RuntimeException('Cookie encryption failed.');
        }

        // base64url — PHP cookie parsing turns "+" into space and breaks std base64.
        return 'gcm:' . rtrim(strtr(base64_encode($iv . $tag . $cipher), '+/', '-_'), '=');
    }

    /**
     * Decrypt a cookie value. Legacy plaintext (no gcm: prefix) is returned as-is
     * so existing sessions keep working until they expire.
     */
    public function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        if (!str_starts_with($payload, 'gcm:')) {
            return $payload;
        }

        $key = trim((string) $this->config->get('app.key', ''));
        if ($key === '') {
            return '';
        }

        $binaryKey = hash('sha256', $key, true);
        $b64 = substr($payload, 4);
        // Accept base64url (cookies) and legacy standard base64.
        $b64 = strtr($b64, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $binaryKey, OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? '' : $plain;
    }
}
