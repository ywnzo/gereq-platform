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

    /** SHA-256 content footprint of the installed module tree (immutable per version). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $footprint = null;

    #[ORM\Column]
    private bool $enabled = false;

    #[ORM\Column(name: 'enabled_at', nullable: true)]
    private ?\DateTimeImmutable $enabledAt = null;

    #[ORM\Column(length: 16, options: ['default' => 'local'])]
    private string $source = 'local';

    public function __construct(string $id, string $version, bool $enabled = false, string $source = 'local')
    {
        $this->id = $id;
        $this->version = $version;
        $this->enabled = $enabled;
        $this->enabledAt = $enabled ? new \DateTimeImmutable() : null;
        $this->source = $source === 'gitlab' ? 'gitlab' : 'local';
    }

    public function id(): string
    {
        return $this->id;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function footprint(): ?string
    {
        return $this->footprint;
    }

    public function setFootprint(?string $footprint): void
    {
        $this->footprint = $footprint !== null && $footprint !== '' ? $footprint : null;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function enabledAt(): ?\DateTimeImmutable
    {
        return $this->enabledAt;
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

    public function source(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source === 'gitlab' ? 'gitlab' : 'local';
    }
}
