<?php

declare(strict_types=1);

namespace Modules\Listings\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'near_locations')]
#[ORM\Index(name: 'idx_near_locations_category', columns: ['category'])]
#[ORM\Index(name: 'idx_near_locations_active', columns: ['active'])]
#[FormEntity('listings.near.save')]
#[FormGenerator(
    exclude: ['id', 'address', 'city', 'createdAt', 'updatedAt'],
    form: 'listings.near.save',
    cols: 2,
)]
class NearLocation
{
    public const CATEGORIES = [
        'school',
        'kindergarten',
        'shop',
        'supermarket',
        'transport',
        'metro',
        'tram',
        'bus',
        'office',
        'post',
        'park',
        'restaurant',
        'health',
        'sport',
        'other',
    ];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'listings.near.field.name', group: 'basic', order: 10)]
    private string $name;

    #[ORM\Column(length: 32)]
    #[FormField(
        required: true,
        label: 'listings.near.field.category',
        type: 'select',
        group: 'basic',
        choicesFrom: 'CATEGORIES',
        choiceLabel: 'listings.near.category.',
        order: 20,
    )]
    private string $category = 'other';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[FormField(label: 'listings.near.field.description', type: 'editor', group: 'details', extra: 'field--full', order: 10)]
    private ?string $description = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[FormField(label: 'listings.near.field.latitude', type: 'number', group: 'location', options: ['step' => 'any'], order: 10)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[FormField(label: 'listings.near.field.longitude', type: 'number', group: 'location', options: ['step' => 'any'], order: 20)]
    private ?float $longitude = null;

    #[ORM\Column]
    #[FormField(label: 'listings.near.field.active', type: 'switch', group: 'details', order: 30)]
    private bool $active = true;

    #[ORM\Column(name: 'sort_order')]
    #[FormField(label: 'listings.near.field.sort_order', type: 'number', group: 'details', order: 20)]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $category = 'other')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $this->setCategory($category);
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string { return $this->id; }
    public function name(): string { return $this->name; }
    public function category(): string { return $this->category; }
    public function address(): ?string { return $this->address; }
    public function city(): ?string { return $this->city; }
    public function description(): ?string { return $this->description; }
    public function latitude(): ?float { return $this->latitude; }
    public function longitude(): ?float { return $this->longitude; }
    public function active(): bool { return $this->active; }
    public function sortOrder(): int { return $this->sortOrder; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setName(string $v): void { $this->name = $v; $this->touch(); }

    public function setCategory(string $v): void
    {
        $this->category = in_array($v, self::CATEGORIES, true) ? $v : 'other';
        $this->touch();
    }

    public function setAddress(?string $v): void { $this->address = $v !== null && $v !== '' ? $v : null; $this->touch(); }
    public function setCity(?string $v): void { $this->city = $v !== null && $v !== '' ? $v : null; $this->touch(); }
    public function setDescription(?string $v): void { $this->description = $v !== null && $v !== '' ? $v : null; $this->touch(); }
    public function setLatitude(?float $v): void { $this->latitude = $v; $this->touch(); }
    public function setLongitude(?float $v): void { $this->longitude = $v; $this->touch(); }
    public function setActive(bool $v): void { $this->active = $v; $this->touch(); }
    public function setSortOrder(int $v): void { $this->sortOrder = $v; $this->touch(); }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
