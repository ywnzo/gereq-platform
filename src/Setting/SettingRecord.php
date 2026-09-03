<?php

declare(strict_types=1);

namespace Gereq\Setting;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'settings')]
class SettingRecord
{
    public const TYPE_STRING = 'string';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_JSON = 'json';
    public const TYPE_ENCRYPTED = 'encrypted';

    #[ORM\Id]
    #[ORM\Column(length: 180)]
    private string $keyName;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = null;

    #[ORM\Column(length: 32)]
    private string $type = self::TYPE_STRING;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $module = null;

    public function __construct(string $keyName, ?string $value, string $type, ?string $module = null)
    {
        $this->keyName = $keyName;
        $this->value = $value;
        $this->type = $type;
        $this->module = $module;
    }

    public function keyName(): string
    {
        return $this->keyName;
    }

    public function value(): ?string
    {
        return $this->value;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function module(): ?string
    {
        return $this->module;
    }

    public function setValue(?string $value): void
    {
        $this->value = $value;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }
}
