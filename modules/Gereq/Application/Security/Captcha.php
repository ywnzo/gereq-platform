<?php

declare(strict_types=1);

namespace Modules\Gereq\Application\Security;

use Gereq\Cache\AppCache;

final class Captcha
{
    private const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const CODE_LEN = 5;
    private const TTL_SECONDS = 600;
    private const MIN_SOLVE_MS = 1400;
    public const DIFFICULTY_MIN = 1;
    public const DIFFICULTY_MAX = 10;
    public const DIFFICULTY_DEFAULT = 2;

    public function __construct(private readonly AppCache $cache)
    {
    }

    /** @return array{kind: string, token: string, image: string} */
    public function create(?int $difficulty = null, ?string $siteId = null, bool $requireRaster = false): array
    {
        $difficulty = self::normalizeDifficulty($difficulty);
        $answer = '';
        $chars = self::CODE_CHARS;
        $max = strlen($chars) - 1;
        for ($i = 0; $i < self::CODE_LEN; $i++) {
            $answer .= $chars[random_int(0, $max)];
        }
        $token = bin2hex(random_bytes(20));
        $exp = (int) (microtime(true) * 1000) + (self::TTL_SECONDS * 1000);
        $image = $this->pngDataUri($answer, $token, $difficulty, $requireRaster);
        $this->cache->put(self::cacheKey($token), [
            'answer' => $answer,
            'exp' => $exp,
            'created_at' => (int) (microtime(true) * 1000),
            'difficulty' => $difficulty,
            'site_id' => $siteId,
        ], self::TTL_SECONDS);

        return ['kind' => 'code', 'token' => $token, 'image' => $image];
    }

    public static function normalizeDifficulty(mixed $difficulty): int
    {
        if (!is_numeric($difficulty)) {
            return self::DIFFICULTY_DEFAULT;
        }

        return max(self::DIFFICULTY_MIN, min(self::DIFFICULTY_MAX, (int) $difficulty));
    }

    public static function cacheKey(string $token): string
    {
        return 'gereq:captcha:' . hash('sha256', $token);
    }

    /** @return array{ok: true}|array{ok: false, error: string} */
    public function verify(mixed $token, mixed $answer, ?string $expectedSiteId = null): array
    {
        if (!is_string($token) || $token === '') {
            return ['ok' => false, 'error' => 'Kontrola chybí. Obnovte výzvu.'];
        }
        $payload = $this->cache->get(self::cacheKey($token));
        $this->cache->forget(self::cacheKey($token));
        if (!is_array($payload)) {
            return ['ok' => false, 'error' => 'Kontrola je neplatná. Obnovte výzvu.'];
        }
        $payloadSiteId = $payload['site_id'] ?? null;
        if ($expectedSiteId !== null) {
            if (!is_string($payloadSiteId) || $payloadSiteId !== $expectedSiteId) {
                return ['ok' => false, 'error' => 'Kontrola nepatří k tomuto webu.'];
            }
        }
        $now = (int) (microtime(true) * 1000);
        if ($now > (int) ($payload['exp'] ?? 0)) {
            return ['ok' => false, 'error' => 'Kontrola vypršela. Obnovte výzvu.'];
        }
        $createdAt = (int) ($payload['created_at'] ?? 0);
        $minSolve = (bool) env_value('APP_DEBUG', false) ? 0 : self::MIN_SOLVE_MS;
        if ($minSolve > 0 && $createdAt > 0 && ($now - $createdAt) < $minSolve) {
            return ['ok' => false, 'error' => 'Ověření bylo příliš rychlé. Zkuste to znovu.'];
        }
        $typed = is_string($answer) ? strtoupper(preg_replace('/\s+/', '', trim($answer)) ?? '') : '';
        if ($typed === '') {
            return ['ok' => false, 'error' => 'Opište kontrolní kód.'];
        }
        $expected = is_string($payload['answer'] ?? null) ? $payload['answer'] : '';
        if ($expected === '' || !hash_equals($expected, $typed)) {
            return ['ok' => false, 'error' => 'Špatný kód. Zkuste to znovu.'];
        }

        return ['ok' => true];
    }

    private function pngDataUri(string $answer, string $seed, int $difficulty, bool $requireRaster): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            if ($requireRaster) {
                throw new \RuntimeException('GD extension is required for GEREQ Security captcha.');
            }
            return $this->svgFallback($answer, $seed, $difficulty);
        }
        $width = 200;
        $height = 64;
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            if ($requireRaster) {
                throw new \RuntimeException('Unable to allocate captcha image.');
            }
            return $this->svgFallback($answer, $seed, $difficulty);
        }
        $bg = imagecolorallocate($image, 238, 240, 242) ?: 0;
        $border = imagecolorallocate($image, 180, 188, 198) ?: 0;
        imagefilledrectangle($image, 0, 0, $width, $height, $bg);
        imagerectangle($image, 0, 0, $width - 1, $height - 1, $border);
        $ink = imagecolorallocate($image, 10, 31, 56) ?: 0;
        $chars = str_split($answer);
        foreach ($chars as $i => $ch) {
            imagestring($image, 5, 20 + ($i * 32), 22, $ch, $ink);
        }
        for ($i = 0; $i < 8; $i++) {
            $h = abs(crc32($seed . '~' . $i));
            $noise = imagecolorallocate($image, 136, 146, 160) ?: 0;
            imageline($image, $h % $width, ($h >> 8) % $height, ($h >> 16) % $width, ($h >> 24) % $height, $noise);
        }
        ob_start();
        imagepng($image, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($image);
        if ($png === '') {
            if ($requireRaster) {
                throw new \RuntimeException('Unable to encode captcha PNG.');
            }
            return $this->svgFallback($answer, $seed, $difficulty);
        }

        return 'data:image/png;base64,' . base64_encode($png);
    }

    private function svgFallback(string $answer, string $seed, int $difficulty): string
    {
        $safe = htmlspecialchars($answer, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="168" height="52"><rect width="100%" height="100%" fill="#EEF0F2"/><text x="20" y="34" fill="#0A1F38" font-family="monospace" font-size="26" font-weight="700">' . $safe . '</text></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
