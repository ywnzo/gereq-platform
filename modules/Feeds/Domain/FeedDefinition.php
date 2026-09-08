<?php

declare(strict_types=1);

namespace Modules\Feeds\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'feed_definitions')]
#[ORM\UniqueConstraint(name: 'uniq_feed_definitions_code', columns: ['code'])]
#[FormEntity('feeds.save')]
#[FormGenerator(
    exclude: ['id', 'config', 'createdAt', 'updatedAt'],
    form: 'feeds.save',
    cols: 1,
)]
class FeedDefinition
{
    public const DRIVERS = [
        'google_merchant',
        'heureka',
        'custom',
    ];

    public const FORMATS = [
        'xml',
        'tsv',
        'csv',
    ];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64)]
    #[FormField(required: true, label: 'feeds.field.code', order: 10)]
    private string $code;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'feeds.field.name', order: 20)]
    private string $name;

    #[ORM\Column(length: 32)]
    #[FormField(
        required: true,
        label: 'feeds.field.driver',
        type: 'select',
        choicesFrom: 'DRIVERS',
        choiceLabel: 'feeds.driver.',
        order: 30,
    )]
    private string $driver = 'google_merchant';

    #[ORM\Column(length: 16)]
    #[FormField(
        required: true,
        label: 'feeds.field.format',
        type: 'select',
        choices: [
            ['value' => 'xml', 'label' => 'XML'],
            ['value' => 'tsv', 'label' => 'TSV'],
            ['value' => 'csv', 'label' => 'CSV'],
        ],
        order: 40,
    )]
    private string $format = 'xml';

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    #[FormField(name: 'active', label: 'feeds.field.active', type: 'switch', order: 50)]
    private bool $active = true;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $config = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name, string $driver = 'google_merchant')
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

    public function format(): string
    {
        return $this->format;
    }

    public function isActive(): bool
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

    public function setCode(string $code): void
    {
        $this->code = strtolower(trim($code));
        $this->touch();
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

    public function setFormat(string $format): void
    {
        $format = strtolower(trim($format));
        $this->format = in_array($format, self::FORMATS, true) ? $format : 'xml';
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
        $this->config = $config === [] ? null : $config;
        $this->touch();
    }

    private function normalizeDriver(string $driver): string
    {
        $driver = strtolower(trim($driver));
        if (!in_array($driver, self::DRIVERS, true)) {
            return 'google_merchant';
        }

        return $driver;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
