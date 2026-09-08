<?php

declare(strict_types=1);

namespace Modules\Portals\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'portal_accounts')]
#[ORM\UniqueConstraint(name: 'uniq_portal_accounts_code', columns: ['code'])]
#[ORM\Index(name: 'idx_portal_accounts_provider', columns: ['provider_id'])]
#[FormEntity('portals.account.save')]
#[FormGenerator(
    exclude: ['id', 'providerId', 'config', 'createdAt', 'updatedAt'],
    form: 'portals.account.save',
    cols: 2,
)]
class PortalAccount
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64)]
    #[FormField(required: true, label: 'portals.field.code', group: 'identity', hint: 'portals.field.code.hint', order: 10)]
    private string $code;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'portals.field.name', group: 'identity', order: 20)]
    private string $name;

    /** Provider card picker — ruční Twig. */
    #[ORM\Column(name: 'provider_id', length: 36)]
    #[FormField(required: true, name: 'provider_id')]
    private string $providerId;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    #[FormField(name: 'active', label: 'portals.field.active', type: 'switch', group: 'status', hint: 'portals.field.active.hint', order: 10)]
    private bool $active = true;

    /** Values for fields defined on the provider číselník. @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $config = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name, string $providerId)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->code = strtolower(trim($code));
        $this->name = $name;
        $this->providerId = $providerId;
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

    public function providerId(): string
    {
        return $this->providerId;
    }

    public function active(): bool
    {
        return $this->active;
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config ?? [];
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

    public function setProviderId(string $providerId): void
    {
        $this->providerId = $providerId;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    /** @param array<string, mixed>|null $config */
    public function setConfig(?array $config): void
    {
        $this->config = $config;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
