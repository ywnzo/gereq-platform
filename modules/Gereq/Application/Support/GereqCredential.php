<?php

declare(strict_types=1);

namespace Modules\Gereq\Application\Support;

final class GereqCredential
{
    public static function isTest(string $value): bool
    {
        foreach (['pk_test_', 'sk_test_', 'fp_test_'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function publicKey(bool $test = false): string
    {
        return self::randomPrefixed('pk', 32, $test);
    }

    public static function secretKey(bool $test = false): string
    {
        return self::randomPrefixed('sk', 40, $test);
    }

    private static function randomPrefixed(string $kind, int $length, bool $test): string
    {
        $prefix = $test ? $kind . '_test_' : $kind . '_';
        $hex = bin2hex(random_bytes((int) ceil($length / 2)));

        return $prefix . substr($hex, 0, $length);
    }
}
