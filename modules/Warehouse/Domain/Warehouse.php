<?php

declare(strict_types=1);

namespace Modules\Warehouse\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'warehouses')]
#[ORM\UniqueConstraint(name: 'uniq_warehouses_code', columns: ['code'])]
#[FormEntity('warehouse.save')]
#[FormGenerator(
    exclude: ['id', 'createdAt', 'updatedAt'],
    form: 'warehouse.save',
    cols: 1,
)]
class Warehouse
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64)]
    #[FormField(required: true, label: 'warehouse.field.code', order: 10)]
    private string $code;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'warehouse.field.name', order: 20)]
    private string $name;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    #[FormField(name: 'active', label: 'warehouse.field.active', type: 'switch', order: 30)]
    private bool $active = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->code = strtoupper(trim($code));
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setCode(string $code): void
    {
        $this->code = strtoupper(trim($code));
        $this->touch();
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
