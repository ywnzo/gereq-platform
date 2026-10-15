<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Config\Repository as Config;
use Gereq\Security\CookieCipher;
use PHPUnit\Framework\TestCase;

final class CookieCipherTest extends TestCase
{
    public function testEncryptRoundTrips(): void
    {
        $cipher = new CookieCipher(new Config(['app' => ['key' => 'test-key-32-chars-minimum!!!!!!']]));
        $encrypted = $cipher->encrypt('plain-session-id');
        self::assertStringStartsWith('gcm:', $encrypted);
        self::assertSame('plain-session-id', $cipher->decrypt($encrypted));
    }

    public function testDecryptAcceptsLegacyPlaintext(): void
    {
        $cipher = new CookieCipher(new Config(['app' => ['key' => 'test-key-32-chars-minimum!!!!!!']]));
        self::assertSame('legacy-value', $cipher->decrypt('legacy-value'));
    }

    public function testEncryptIsIdempotentForAlreadyEncrypted(): void
    {
        $cipher = new CookieCipher(new Config(['app' => ['key' => 'test-key-32-chars-minimum!!!!!!']]));
        $once = $cipher->encrypt('token');
        self::assertSame($once, $cipher->encrypt($once));
    }

    public function testEncryptWithoutAppKeyPassesThroughPlaintext(): void
    {
        $cipher = new CookieCipher(new Config(['app' => ['key' => '']]));
        self::assertSame('session-id', $cipher->encrypt('session-id'));
    }

    public function testEncryptedPayloadIsCookieSafeBase64Url(): void
    {
        $cipher = new CookieCipher(new Config(['app' => ['key' => 'test-key-32-chars-minimum!!!!!!']]));
        // Encrypt enough times that +/ would almost certainly appear with std base64.
        for ($i = 0; $i < 40; $i++) {
            $plain = 'session-' . $i . '-' . bin2hex(random_bytes(8));
            $enc = $cipher->encrypt($plain);
            self::assertStringStartsWith('gcm:', $enc);
            self::assertStringNotContainsString('+', $enc);
            self::assertStringNotContainsString('/', $enc);
            self::assertStringNotContainsString('=', $enc);
            self::assertSame($plain, $cipher->decrypt($enc));
        }
    }
}
