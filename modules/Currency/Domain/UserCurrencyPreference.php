<?php

declare(strict_types=1);

namespace Modules\Currency\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Preferovaná zobrazovaná měna uživatele (Currency modul — ne core User).
 */
#[ORM\Entity]
#[ORM\Table(name: 'currency_user_prefs')]
class UserCurrencyPreference
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', length: 36)]
    private string $userId;

    #[ORM\Column(name: 'currency_code', length: 3, nullable: true)]
    private ?string $currencyCode = null;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $userId)
    {
        $this->userId = $userId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function currencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(?string $code): void
    {
        if ($code === null || trim($code) === '') {
            $this->currencyCode = null;
        } else {
            $normalized = strtoupper(trim($code));
            $this->currencyCode = preg_match('/^[A-Z]{3}$/', $normalized) === 1 ? $normalized : null;
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
