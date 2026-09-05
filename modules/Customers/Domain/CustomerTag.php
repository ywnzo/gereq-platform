<?php

declare(strict_types=1);

namespace Modules\Customers\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'customer_tags')]
class CustomerTag
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 120, unique: true)]
    private string $slug;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, ?string $slug = null, ?string $color = null)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $this->slug = $slug !== null && $slug !== '' ? self::normalizeSlug($slug) : self::slugify($name);
        $this->color = $color !== null && $color !== '' ? $color : null;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function color(): ?string
    {
        return $this->color;
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

    public function setSlug(string $slug): void
    {
        $this->slug = self::normalizeSlug($slug);
        $this->touch();
    }

    public function setColor(?string $color): void
    {
        $this->color = $color !== null && $color !== '' ? $color : null;
        $this->touch();
    }

    public static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'tag';
    }

    public static function normalizeSlug(string $slug): string
    {
        return self::slugify($slug);
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
