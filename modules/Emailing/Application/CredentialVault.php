<?php

declare(strict_types=1);

namespace Modules\Emailing\Application;

use Gereq\Config\Repository;

final class CredentialVault
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function encrypt(string $value): string
    {
        $key = (string) $this->config->get('app.key', '');
        if ($key === '') {
            throw new \RuntimeException('APP_KEY není nastavený. Heslo nelze uložit.');
        }

        $binaryKey = hash('sha256', $key, true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', $binaryKey, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false || $tag === '') {
            throw new \RuntimeException('Šifrování hesla selhalo.');
        }

        return 'gcm:' . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        $key = (string) $this->config->get('app.key', '');
        $binaryKey = hash('sha256', $key, true);

        if (!str_starts_with($payload, 'gcm:')) {
            return '';
        }

        $raw = base64_decode(substr($payload, 4), true);
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
