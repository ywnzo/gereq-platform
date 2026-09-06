<?php

declare(strict_types=1);

namespace Modules\CarService\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Výchozí pobočka uživatele (CarService) — použije se, dokud si v session nevybere jinak.
 */
#[ORM\Entity]
#[ORM\Table(name: 'carservice_user_branch_prefs')]
class UserBranchPreference
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', length: 36)]
    private string $userId;

    #[ORM\Column(name: 'default_branch_id', length: 36, nullable: true)]
    private ?string $defaultBranchId = null;

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

    public function defaultBranchId(): ?string
    {
        return $this->defaultBranchId;
    }

    public function setDefaultBranchId(?string $branchId): void
    {
        $this->defaultBranchId = $branchId !== null && $branchId !== '' ? $branchId : null;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
