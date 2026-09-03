<?php

declare(strict_types=1);

namespace Gereq\Module;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'modules')]
class InstalledModule
{
    #[ORM\Id]
    #[ORM\Column(length: 80)]
    private string $id;

    #[ORM\Column(length: 32)]
    private string $version;

    #[ORM\Column]
    private bool $enabled = false;

    #[ORM\Column(name: 'enabled_at', nullable: true)]
    private ?\DateTimeImmutable $enabledAt = null;

    public function __construct(string $id, string $version, bool $enabled = false)
    {
        $this->id = $id;
        $this->version = $version;
        $this->enabled = $enabled;
        $this->enabledAt = $enabled ? new \DateTimeImmutable() : null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): void
    {
        $this->enabled = true;
        $this->enabledAt = new \DateTimeImmutable();
    }

    public function disable(): void
    {
        $this->enabled = false;
        $this->enabledAt = null;
    }

    public function setVersion(string $version): void
    {
        $this->version = $version;
    }
}
