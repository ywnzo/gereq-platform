<?php

declare(strict_types=1);

namespace Modules\Listings\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'listing_cities')]
#[ORM\UniqueConstraint(name: 'uniq_listing_cities_name', columns: ['name'])]
#[ORM\Index(name: 'idx_listing_cities_active', columns: ['active'])]
#[FormEntity('listings.cities.save')]
#[FormGenerator(
    exclude: ['id', 'imageUrl', 'createdAt', 'updatedAt'],
    form: 'listings.cities.save',
    cols: 1,
)]
class City
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 120)]
    #[FormField(required: true, label: 'listings.cities.field.name', order: 10)]
    private string $name;

    #[ORM\Column(name: 'image_url', length: 500, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column]
    #[FormField(label: 'listings.cities.field.active', type: 'switch', order: 30)]
    private bool $active = true;

    #[ORM\Column(name: 'sort_order')]
    #[FormField(label: 'listings.cities.field.sort_order', type: 'number', name: 'sort_order', order: 20)]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function imageUrl(): ?string
    {
        return $this->imageUrl;
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

    public function setImageUrl(?string $url): void
    {
        $this->imageUrl = $url !== null && $url !== '' ? $url : null;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    public function setSortOrder(int $order): void
    {
        $this->sortOrder = $order;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
