<?php

declare(strict_types=1);

namespace Modules\Emailing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'emailing_rules')]
#[ORM\Index(name: 'idx_emailing_rules_account', columns: ['account_id'])]
#[ORM\Index(name: 'idx_emailing_rules_active_prio', columns: ['is_active', 'priority'])]
class MailRule
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    /** Null = platí pro všechny účty. */
    #[ORM\Column(name: 'account_id', length: 36, nullable: true)]
    private ?string $accountId = null;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(name: 'is_active')]
    private bool $isActive = true;

    #[ORM\Column]
    private int $priority = 100;

    #[ORM\Column(name: 'stop_processing')]
    private bool $stopProcessing = false;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    private array $conditions = [];

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    private array $actions = [];

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function accountId(): ?string
    {
        return $this->accountId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function stopProcessing(): bool
    {
        return $this->stopProcessing;
    }

    /** @return list<array<string, mixed>> */
    public function conditions(): array
    {
        return $this->conditions;
    }

    /** @return list<array<string, mixed>> */
    public function actions(): array
    {
        return $this->actions;
    }

    public function setAccountId(?string $accountId): void
    {
        $this->accountId = $accountId !== null && $accountId !== '' ? $accountId : null;
        $this->touch();
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->isActive = $active;
        $this->touch();
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
        $this->touch();
    }

    public function setStopProcessing(bool $stop): void
    {
        $this->stopProcessing = $stop;
        $this->touch();
    }

    /** @param list<array<string, mixed>> $conditions */
    public function setConditions(array $conditions): void
    {
        $this->conditions = array_values($conditions);
        $this->touch();
    }

    /** @param list<array<string, mixed>> $actions */
    public function setActions(array $actions): void
    {
        $this->actions = array_values($actions);
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
