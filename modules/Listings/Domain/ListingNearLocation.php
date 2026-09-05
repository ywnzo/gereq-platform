<?php

declare(strict_types=1);

namespace Modules\Listings\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'listing_near_locations')]
#[ORM\UniqueConstraint(name: 'uniq_listing_near_location', columns: ['listing_id', 'near_location_id'])]
#[ORM\Index(name: 'idx_listing_near_listing', columns: ['listing_id'])]
class ListingNearLocation
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'listing_id', length: 36)]
    private string $listingId;

    #[ORM\Column(name: 'near_location_id', length: 36)]
    private string $nearLocationId;

    #[ORM\Column(name: 'distance_m', type: 'integer', nullable: true)]
    private ?int $distanceM = null;

    #[ORM\Column(name: 'walk_minutes', type: 'integer', nullable: true)]
    private ?int $walkMinutes = null;

    #[ORM\Column(name: 'sort_order')]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $listingId, string $nearLocationId)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->listingId = $listingId;
        $this->nearLocationId = $nearLocationId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string { return $this->id; }
    public function listingId(): string { return $this->listingId; }
    public function nearLocationId(): string { return $this->nearLocationId; }
    public function distanceM(): ?int { return $this->distanceM; }
    public function walkMinutes(): ?int { return $this->walkMinutes; }
    public function sortOrder(): int { return $this->sortOrder; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }

    public function setDistanceM(?int $v): void { $this->distanceM = $v; }
    public function setWalkMinutes(?int $v): void { $this->walkMinutes = $v; }
    public function setSortOrder(int $v): void { $this->sortOrder = $v; }
}
