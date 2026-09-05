<?php

declare(strict_types=1);

namespace Modules\Currency\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'currencies')]
class Currency
{
    #[ORM\Id]
    #[ORM\Column(length: 3)]
    private string $code;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 16)]
    private string $symbol;

    #[ORM\Column(name: 'decimal_places', type: 'integer')]
    private int $decimalPlaces = 2;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(name: 'is_default', type: 'boolean')]
    private bool $isDefault = false;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name, string $symbol, int $decimalPlaces = 2)
    {
        $normalized = strtoupper(trim($code));
        if (!preg_match('/^[A-Z]{3}$/', $normalized)) {
            throw new \InvalidArgumentException('Kód měny musí mít 3 písmena.');
        }

        $this->code = $normalized;
        $this->name = $name;
        $this->symbol = $symbol;
        $this->decimalPlaces = max(0, min(6, $decimalPlaces));
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function symbol(): string
    {
        return $this->symbol;
    }

    public function decimalPlaces(): int
    {
        return $this->decimalPlaces;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function setSymbol(string $symbol): void
    {
        $this->symbol = $symbol;
        $this->touch();
    }

    public function setDecimalPlaces(int $decimalPlaces): void
    {
        $this->decimalPlaces = max(0, min(6, $decimalPlaces));
        $this->touch();
    }

    public function activate(): void
    {
        $this->isActive = true;
        $this->touch();
    }

    public function deactivate(): void
    {
        if ($this->isDefault) {
            throw new \LogicException('Výchozí měnu nelze deaktivovat.');
        }
        $this->isActive = false;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        if ($active) {
            $this->activate();
        } else {
            $this->deactivate();
        }
    }

    public function markDefault(bool $isDefault = true): void
    {
        $this->isDefault = $isDefault;
        if ($isDefault) {
            $this->isActive = true;
        }
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
