<?php

declare(strict_types=1);

namespace Gereq\Debug;

final class Redactor
{
    /** @var list<string> */
    private const KEYS = [
        'password', 'password_hash', 'passwd', 'secret', 'token', 'authorization',
        'cookie', 'csrf', 'app_key', 'key', 'totp', 'recovery', 'remember', 'dsn',
    ];

    public function key(string $key): bool
    {
        $haystack = strtolower($key);

        foreach (self::KEYS as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function value(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = is_string($key) && $this->key($key) ? '••••' : $this->value($item);
            }

            return $out;
        }

        if (is_string($value) && strlen($value) > 4000) {
            return substr($value, 0, 4000) . '…';
        }

        return $value;
    }
}
