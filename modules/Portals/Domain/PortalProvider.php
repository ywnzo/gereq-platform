<?php

declare(strict_types=1);

namespace Modules\Portals\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Číselník poskytovatelů realitních portálů (fieldy, API, driver).
 */
#[ORM\Entity]
#[ORM\Table(name: 'portal_providers')]
#[ORM\UniqueConstraint(name: 'uniq_portal_providers_code', columns: ['code'])]
#[ORM\Index(name: 'idx_portal_providers_active', columns: ['is_active'])]
class PortalProvider
{
    public const DRIVERS = ['sreality', 'idnes', 'bezrealitky', 'custom'];
    public const FIELD_TYPES = ['text', 'password', 'url', 'email', 'number'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 190)]
    private string $name;

    /** Driver key used by export adapters. */
    #[ORM\Column(length: 32)]
    private string $driver = 'custom';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'api_base_url', length: 500, nullable: true)]
    private ?string $apiBaseUrl = null;

    /** URL na OpenAPI / Public API specifikaci (JSON). */
    #[ORM\Column(name: 'openapi_url', length: 500, nullable: true)]
    private ?string $openapiUrl = null;

    /**
     * Vestavěná API specifikace (metody, číselníky…) — např. Sreality XML-RPC.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'api_spec', type: 'json', nullable: true)]
    private ?array $apiSpec = null;

    /**
     * Field definitions for portal accounts.
     *
     * @var list<array{key: string, label: string, type: string, required?: bool, hint?: string, placeholder?: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $fields = [];

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 100;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name, string $driver = 'custom')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->code = strtolower(trim($code));
        $this->name = $name;
        $this->driver = $this->normalizeDriver($driver);
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

    public function driver(): string
    {
        return $this->driver;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function apiBaseUrl(): ?string
    {
        return $this->apiBaseUrl;
    }

    public function openapiUrl(): ?string
    {
        return $this->openapiUrl;
    }

    /** @return array<string, mixed> */
    public function apiSpec(): array
    {
        return is_array($this->apiSpec) ? $this->apiSpec : [];
    }

    /**
     * @return list<array{key: string, label: string, type: string, required: bool, hint: string, placeholder: string}>
     */
    public function fields(): array
    {
        $out = [];
        foreach ($this->fields as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = strtolower(trim((string) ($row['key'] ?? '')));
            $label = trim((string) ($row['label'] ?? ''));
            if ($key === '' || $label === '') {
                continue;
            }
            $type = strtolower(trim((string) ($row['type'] ?? 'text')));
            if (!in_array($type, self::FIELD_TYPES, true)) {
                $type = 'text';
            }
            $out[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => (bool) ($row['required'] ?? false),
                'hint' => trim((string) ($row['hint'] ?? '')),
                'placeholder' => trim((string) ($row['placeholder'] ?? '')),
            ];
        }

        return $out;
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
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

    public function setDriver(string $driver): void
    {
        $this->driver = $this->normalizeDriver($driver);
        $this->touch();
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description !== null && $description !== '' ? $description : null;
        $this->touch();
    }

    public function setApiBaseUrl(?string $apiBaseUrl): void
    {
        $this->apiBaseUrl = $apiBaseUrl !== null && $apiBaseUrl !== '' ? $apiBaseUrl : null;
        $this->touch();
    }

    public function setOpenapiUrl(?string $openapiUrl): void
    {
        $this->openapiUrl = $openapiUrl !== null && $openapiUrl !== '' ? $openapiUrl : null;
        $this->touch();
    }

    /** @param array<string, mixed>|null $apiSpec */
    public function setApiSpec(?array $apiSpec): void
    {
        $this->apiSpec = $apiSpec !== null && $apiSpec !== [] ? $apiSpec : null;
        $this->touch();
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    public function setFields(array $fields): void
    {
        $normalized = [];
        foreach ($fields as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = strtolower(trim((string) ($row['key'] ?? '')));
            $label = trim((string) ($row['label'] ?? ''));
            if ($key === '' || $label === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                continue;
            }
            $type = strtolower(trim((string) ($row['type'] ?? 'text')));
            if (!in_array($type, self::FIELD_TYPES, true)) {
                $type = 'text';
            }
            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => !empty($row['required']),
                'hint' => trim((string) ($row['hint'] ?? '')),
                'placeholder' => trim((string) ($row['placeholder'] ?? '')),
            ];
        }
        $this->fields = $normalized;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
        $this->touch();
    }

    public function driverLabel(): string
    {
        return 'portals.driver.' . $this->driver;
    }

    private function normalizeDriver(string $driver): string
    {
        $driver = strtolower(trim($driver));

        return in_array($driver, self::DRIVERS, true) ? $driver : 'custom';
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
