<?php

declare(strict_types=1);

namespace Modules\Gereq\Domain;

use Modules\Gereq\Application\Support\GereqCredential;
use Modules\Gereq\Application\Support\HttpOrigin;
use Modules\Gereq\Application\Security\Captcha;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'gereq_security_sites')]
#[ORM\UniqueConstraint(name: 'uniq_gereq_sec_public_key', columns: ['public_key'])]
#[ORM\UniqueConstraint(name: 'uniq_gereq_sec_test_public_key', columns: ['test_public_key'])]
class SecuritySite
{
    public const MODES = ['observe', 'challenge', 'block'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(name: 'public_key', length: 64)]
    private string $publicKey;

    #[ORM\Column(name: 'test_public_key', length: 64, nullable: true)]
    private ?string $testPublicKey = null;

    #[ORM\Column(name: 'secret_key_hash', length: 64)]
    private string $secretKeyHash;

    #[ORM\Column(name: 'test_secret_key_hash', length: 64, nullable: true)]
    private ?string $testSecretKeyHash = null;

    /** @var list<string> */
    #[ORM\Column(name: 'allowed_origins', type: 'json')]
    private array $allowedOrigins = [];

    #[ORM\Column(name: 'form_enabled', options: ['default' => true])]
    private bool $formEnabled = true;

    #[ORM\Column(name: 'form_captcha_always', options: ['default' => false])]
    private bool $formCaptchaAlways = false;

    #[ORM\Column(name: 'monitor_enabled', options: ['default' => true])]
    private bool $monitorEnabled = true;

    #[ORM\Column(name: 'protection_mode', length: 32, options: ['default' => 'observe'])]
    private string $protectionMode = 'observe';

    #[ORM\Column(name: 'primary_color', length: 16, options: ['default' => '#0A1F38'])]
    private string $primaryColor = '#0A1F38';

    #[ORM\Column(name: 'badge_text', length: 190, nullable: true)]
    private ?string $badgeText = null;

    #[ORM\Column(name: 'captcha_difficulty', type: 'integer', options: ['default' => 2])]
    private int $captchaDifficulty = 2;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    private bool $usingTestKey = false;

    public function __construct(string $name)
    {
        $this->id = Uuid::uuid4()->toString();
        $this->name = $name;
        $this->publicKey = GereqCredential::publicKey();
        $plain = GereqCredential::secretKey();
        $this->secretKeyHash = self::hashSecret($plain);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->lastIssuedSecret = $plain;
    }

    private ?string $lastIssuedSecret = null;

    public function consumeIssuedSecret(): ?string
    {
        $s = $this->lastIssuedSecret;
        $this->lastIssuedSecret = null;

        return $s;
    }

    public static function hashSecret(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function id(): string { return $this->id; }
    public function name(): string { return $this->name; }
    public function publicKey(): string { return $this->publicKey; }
    public function testPublicKey(): ?string { return $this->testPublicKey; }
    public function secretKeyHash(): string { return $this->secretKeyHash; }
    public function testSecretKeyHash(): ?string { return $this->testSecretKeyHash; }
    /** @return list<string> */
    public function allowedOrigins(): array { return $this->allowedOrigins; }
    public function formEnabled(): bool { return $this->formEnabled; }
    public function formCaptchaAlways(): bool { return $this->formCaptchaAlways; }
    public function monitorEnabled(): bool { return $this->monitorEnabled; }
    public function protectionMode(): string { return $this->protectionMode; }
    public function primaryColor(): string { return $this->primaryColor; }
    public function badgeText(): ?string { return $this->badgeText; }
    public function captchaDifficulty(): int { return Captcha::normalizeDifficulty($this->captchaDifficulty); }
    public function isActive(): bool { return $this->isActive; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function usingTestKey(): bool { return $this->usingTestKey; }

    public function markCredentialMode(bool $test): void
    {
        $this->usingTestKey = $test;
    }

    public function badgeLabel(): string
    {
        $custom = trim((string) $this->badgeText);
        return $custom !== '' ? $custom : 'Formulář chráněn GEREQ Security';
    }

    public function setName(string $name): void
    {
        $this->name = trim($name);
        $this->touch();
    }

    /** @param list<string>|string $origins */
    public function setAllowedOrigins(array|string $origins): void
    {
        if (is_string($origins)) {
            $origins = preg_split('/[\s,]+/', $origins) ?: [];
        }
        $normalized = [];
        foreach ($origins as $o) {
            if (!is_string($o)) {
                continue;
            }
            $n = HttpOrigin::normalize($o);
            if ($n !== '' && !in_array($n, $normalized, true)) {
                $normalized[] = $n;
            }
        }
        $this->allowedOrigins = $normalized;
        $this->touch();
    }

    public function setFormEnabled(bool $v): void { $this->formEnabled = $v; $this->touch(); }
    public function setFormCaptchaAlways(bool $v): void { $this->formCaptchaAlways = $v; $this->touch(); }
    public function setMonitorEnabled(bool $v): void { $this->monitorEnabled = $v; $this->touch(); }

    public function setProtectionMode(string $mode): void
    {
        $this->protectionMode = in_array($mode, self::MODES, true) ? $mode : 'observe';
        $this->touch();
    }

    public function setPrimaryColor(string $color): void
    {
        $color = strtoupper(trim($color));
        $this->primaryColor = $color !== '' ? $color : '#0A1F38';
        $this->touch();
    }

    public function setBadgeText(?string $text): void
    {
        $text = $text !== null ? trim($text) : null;
        $this->badgeText = $text !== '' ? $text : null;
        $this->touch();
    }

    public function setCaptchaDifficulty(int $d): void
    {
        $this->captchaDifficulty = Captcha::normalizeDifficulty($d);
        $this->touch();
    }

    public function setActive(bool $v): void { $this->isActive = $v; $this->touch(); }

    public function secretMatches(string $plain): bool
    {
        if ($plain === '') {
            return false;
        }
        $hash = self::hashSecret($plain);
        if (hash_equals($this->secretKeyHash, $hash)) {
            return true;
        }
        return is_string($this->testSecretKeyHash)
            && $this->testSecretKeyHash !== ''
            && hash_equals($this->testSecretKeyHash, $hash);
    }

    public function rotateSecret(): string
    {
        $plain = GereqCredential::secretKey();
        $this->secretKeyHash = self::hashSecret($plain);
        $this->touch();
        return $plain;
    }

    /** @return array{public_key: string, secret: string} */
    public function issueTestKeys(): array
    {
        $public = GereqCredential::publicKey(true);
        $plain = GereqCredential::secretKey(true);
        $this->testPublicKey = $public;
        $this->testSecretKeyHash = self::hashSecret($plain);
        $this->touch();
        return ['public_key' => $public, 'secret' => $plain];
    }

    public function allowsOrigin(string $origin): bool
    {
        $normalized = HttpOrigin::normalize($origin);
        if ($normalized === '') {
            return false;
        }
        if ($this->usingTestKey && HttpOrigin::isLoopback($normalized)) {
            return true;
        }
        return in_array($normalized, $this->allowedOrigins, true);
    }

    public function banCacheKey(string $visitor): string
    {
        return 'gereq:sec:ban:' . $this->id . ':' . ($this->usingTestKey ? 'test:' : 'live:') . hash('sha256', $visitor);
    }

    public function ipBanCacheKey(string $ip): string
    {
        return 'gereq:sec:ipban:' . $this->id . ':' . ($this->usingTestKey ? 'test:' : 'live:') . hash('sha256', $ip);
    }

    public function embedSnippet(string $baseUrl): string
    {
        $src = rtrim($baseUrl, '/') . '/js/gereq-security.js';
        return '<script src="' . htmlspecialchars($src, ENT_QUOTES) . '" data-key="' . htmlspecialchars($this->publicKey, ENT_QUOTES) . '" defer></script>';
    }

    public function monitorSnippet(string $baseUrl): string
    {
        $src = rtrim($baseUrl, '/') . '/js/gereq-monitor.js';
        return '<script src="' . htmlspecialchars($src, ENT_QUOTES) . '" data-key="' . htmlspecialchars($this->publicKey, ENT_QUOTES) . '" defer></script>';
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
