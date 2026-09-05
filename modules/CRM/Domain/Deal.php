<?php

declare(strict_types=1);

namespace Modules\CRM\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'crm_deals')]
#[ORM\Index(name: 'idx_crm_deals_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_crm_deals_stage', columns: ['stage'])]
class Deal
{
    public const STAGES = ['lead', 'qualified', 'proposal', 'won', 'lost'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'customer_id', length: 36)]
    private string $customerId;

    #[ORM\Column(length: 190)]
    private string $title;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, nullable: true)]
    private ?string $amount = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'CZK';

    #[ORM\Column(length: 32)]
    private string $stage = 'lead';

    #[ORM\Column(name: 'expected_close_at', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $expectedCloseAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $customerId, string $title)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->customerId = $customerId;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function amount(): ?string
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function stage(): string
    {
        return $this->stage;
    }

    public function expectedCloseAt(): ?\DateTimeImmutable
    {
        return $this->expectedCloseAt;
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

    public function setCustomerId(string $customerId): void
    {
        $this->customerId = $customerId;
        $this->touch();
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->touch();
    }

    public function setAmount(?string $amount): void
    {
        $this->amount = $amount;
        $this->touch();
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = strtoupper($currency);
        $this->touch();
    }

    public function setStage(string $stage): void
    {
        if (!in_array($stage, self::STAGES, true)) {
            throw new \InvalidArgumentException('Neplatná fáze obchodu.');
        }
        $this->stage = $stage;
        $this->touch();
    }

    public function setExpectedCloseAt(?\DateTimeImmutable $date): void
    {
        $this->expectedCloseAt = $date;
        $this->touch();
    }

    public function setNote(?string $note): void
    {
        $this->note = $note !== '' ? $note : null;
        $this->touch();
    }

    public function stageLabel(): string
    {
        return match ($this->stage) {
            'lead' => 'crm.stage.lead',
            'qualified' => 'crm.stage.qualified',
            'proposal' => 'crm.stage.proposal',
            'won' => 'crm.stage.won',
            'lost' => 'crm.stage.lost',
            default => $this->stage,
        };
    }

    public function amountFormatted(): string
    {
        if ($this->amount === null) {
            return '—';
        }

        return number_format((float) $this->amount, 0, ',', ' ') . ' ' . $this->currency;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
