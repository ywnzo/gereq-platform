<?php

declare(strict_types=1);

namespace Gereq\Currency\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;

#[ORM\Entity]
#[ORM\Table(name: 'currencies')]
#[FormEntity('currency.save')]
#[FormGenerator(
    exclude: ['iDokladId', 'createdAt', 'updatedAt'],
    form: 'currency.save',
    cols: 1,
)]
class Currency
{
    #[ORM\Id]
    #[ORM\Column(length: 3)]
    #[FormField(required: true, label: 'currency.field.code', hint: 'currency.hint.code_iso', order: 10)]
    private string $code;

    #[ORM\Column(length: 120)]
    #[FormField(required: true, label: 'currency.field.name', order: 20)]
    private string $name;

    #[ORM\Column(length: 16)]
    #[FormField(required: true, label: 'currency.field.symbol', order: 30)]
    private string $symbol;

    #[ORM\Column(name: 'decimal_places', type: 'integer')]
    #[FormField(label: 'currency.field.decimal_places', type: 'number', order: 40)]
    private int $decimalPlaces = 2;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    #[FormField(label: 'currency.field.is_active', type: 'switch', order: 50)]
    private bool $isActive = true;

    #[ORM\Column(name: 'is_default', type: 'boolean')]
    #[FormField(label: 'currency.field.is_default', type: 'switch', order: 60)]
    private bool $isDefault = false;

    #[ORM\Column(name: 'idoklad_id', type: 'integer', nullable: true)]
    private ?int $iDokladId = null;

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

    public function iDokladId(): ?int
    {
        return $this->iDokladId;
    }

    public function setIDokladId(?int $iDokladId): void
    {
        $this->iDokladId = $iDokladId !== null && $iDokladId > 0 ? $iDokladId : null;
        $this->touch();
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
