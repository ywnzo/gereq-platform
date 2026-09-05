<?php

declare(strict_types=1);

namespace Modules\CarService\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'carservice_costs')]
#[ORM\Index(name: 'idx_carservice_costs_month', columns: ['year_month'])]
class ServiceCost
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    /** Format YYYY-MM */
    #[ORM\Column(name: 'year_month', length: 7)]
    private string $yearMonth;

    #[ORM\Column(length: 64)]
    private string $category = 'general';

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $yearMonth, string $amount, string $category = 'general')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->yearMonth = $yearMonth;
        $this->category = $category !== '' ? $category : 'general';
        $this->amount = $amount;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function yearMonth(): string
    {
        return $this->yearMonth;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setYearMonth(string $v): void
    {
        $this->yearMonth = $v;
        $this->touch();
    }

    public function setCategory(string $v): void
    {
        $this->category = $v !== '' ? $v : 'general';
        $this->touch();
    }

    public function setAmount(string $v): void
    {
        $this->amount = $v;
        $this->touch();
    }

    public function setNote(?string $v): void
    {
        $this->note = $v !== null && $v !== '' ? $v : null;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
