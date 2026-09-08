<?php

declare(strict_types=1);

namespace Modules\Listings\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'listings')]
#[ORM\UniqueConstraint(name: 'uniq_listings_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_listings_status', columns: ['status'])]
#[ORM\Index(name: 'idx_listings_city', columns: ['city'])]
#[ORM\Index(name: 'idx_listings_featured', columns: ['featured'])]
#[FormEntity('listings.save')]
#[FormGenerator(
    exclude: ['id', 'images', 'coverImageUrl', 'publishedAt', 'createdAt', 'updatedAt', 'brokerName', 'brokerEmail', 'brokerPhone', 'brokerPosition', 'brokerPhotoUrl'],
    form: 'listings.save',
    cols: 3,
)]
class Listing
{
    public const STATUSES = ['draft', 'published', 'archived'];
    public const MARKET_STATUSES = ['available', 'reserved', 'sold', 'rented'];
    public const OFFERS = ['sale', 'rent'];
    public const TYPES = ['apartment', 'house', 'land', 'commercial'];
    public const ENERGY = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'listings.field.title', group: 'basic', extra: 'field--full', order: 10)]
    private string $title;

    #[ORM\Column(length: 190)]
    #[FormField(label: 'listings.field.slug', group: 'basic', options: ['placeholder' => 'auto'], order: 20)]
    private string $slug;

    #[ORM\Column(length: 32)]
    #[FormField(
        label: 'listings.field.status',
        type: 'select',
        group: 'basic',
        choicesFrom: 'STATUSES',
        choiceLabel: 'listings.status.',
        order: 30,
    )]
    private string $status = 'draft';

    /** available | reserved | sold | rented */
    #[ORM\Column(name: 'market_status', length: 32)]
    #[FormField(
        label: 'listings.field.market_status',
        type: 'select',
        group: 'basic',
        choicesFrom: 'MARKET_STATUSES',
        choiceLabel: 'listings.market.',
        order: 40,
    )]
    private string $marketStatus = 'available';

    #[ORM\Column(name: 'offer_type', length: 16)]
    #[FormField(
        label: 'listings.field.offer',
        type: 'select',
        group: 'basic',
        choicesFrom: 'OFFERS',
        choiceLabel: 'listings.offer.',
        order: 50,
    )]
    private string $offerType = 'sale';

    #[ORM\Column(name: 'property_type', length: 32)]
    #[FormField(
        label: 'listings.field.type',
        type: 'select',
        group: 'basic',
        choicesFrom: 'TYPES',
        choiceLabel: 'listings.type.',
        order: 60,
    )]
    private string $propertyType = 'apartment';

    #[ORM\Column(type: 'boolean')]
    #[FormField(label: 'listings.field.featured', type: 'switch', group: 'basic', order: 70)]
    private bool $featured = false;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    #[FormField(label: 'listings.field.price', type: 'number', group: 'basic', options: ['step' => '0.01'], order: 80)]
    private string $price = '0.00';

    #[ORM\Column(length: 3)]
    #[FormField(label: 'listings.field.currency', group: 'basic', options: ['maxlength' => 3], order: 90)]
    private string $currency = 'CZK';

    #[ORM\Column(name: 'area_m2', type: 'float', nullable: true)]
    #[FormField(label: 'listings.field.area', type: 'number', group: 'basic', options: ['step' => '0.1'], order: 100)]
    private ?float $areaM2 = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[FormField(label: 'listings.field.rooms', type: 'number', group: 'basic', order: 110)]
    private ?int $rooms = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[FormField(label: 'listings.field.floor', type: 'number', group: 'basic', order: 120)]
    private ?int $floor = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[FormField(label: 'listings.field.floors', type: 'number', group: 'basic', order: 130)]
    private ?int $floors = null;

    #[ORM\Column(length: 120)]
    #[FormField(label: 'listings.field.city', group: 'location', attrs: 'data-ares-fill="city"', order: 20)]
    private string $city = '';

    #[ORM\Column(name: 'city_part', length: 120, nullable: true)]
    #[FormField(label: 'listings.field.city_part', group: 'location', attrs: 'data-ares-fill="city_part"', order: 30)]
    private ?string $cityPart = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[FormField(label: 'listings.field.street', group: 'location', attrs: 'data-ares-fill="street"', order: 40)]
    private ?string $street = null;

    #[ORM\Column(length: 16, nullable: true)]
    #[FormField(label: 'listings.field.zip', group: 'location', attrs: 'data-ares-fill="zip"', order: 50)]
    private ?string $zip = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[FormField(label: 'listings.field.latitude', type: 'number', group: 'location', options: ['step' => 'any'], order: 60)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[FormField(label: 'listings.field.longitude', type: 'number', group: 'location', options: ['step' => 'any'], order: 70)]
    private ?float $longitude = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[FormField(label: 'listings.field.description', type: 'editor', group: 'basic', extra: 'field--full', order: 160)]
    private ?string $description = null;

    #[ORM\Column(name: 'cover_image_url', length: 500, nullable: true)]
    private ?string $coverImageUrl = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $images = [];

    #[ORM\Column(type: 'boolean')]
    #[FormField(label: 'listings.amenity.balcony', type: 'checkbox', group: 'amenities', order: 10)]
    private bool $balcony = false;

    #[ORM\Column(type: 'boolean')]
    #[FormField(label: 'listings.amenity.terrace', type: 'checkbox', group: 'amenities', order: 20)]
    private bool $terrace = false;

    #[ORM\Column(type: 'boolean')]
    #[FormField(label: 'listings.amenity.cellar', type: 'checkbox', group: 'amenities', order: 30)]
    private bool $cellar = false;

    #[ORM\Column(type: 'boolean')]
    #[FormField(label: 'listings.amenity.garage', type: 'checkbox', group: 'amenities', order: 40)]
    private bool $garage = false;

    #[ORM\Column(type: 'boolean')]
    #[FormField(label: 'listings.amenity.elevator', type: 'checkbox', group: 'amenities', order: 50)]
    private bool $elevator = false;

    #[ORM\Column(name: 'parking_lots', type: 'integer', nullable: true)]
    #[FormField(label: 'listings.field.parking', type: 'number', group: 'amenities', order: 60)]
    private ?int $parkingLots = null;

    #[ORM\Column(name: 'energy_rating', length: 1, nullable: true)]
    #[FormField(
        label: 'listings.field.energy',
        type: 'select',
        group: 'basic',
        choicesFrom: 'ENERGY',
        emptyLabel: '—',
        order: 150,
    )]
    private ?string $energyRating = null;

    #[ORM\Column(name: 'broker_id', length: 36, nullable: true)]
    #[FormField(
        label: 'listings.field.broker',
        type: 'select',
        group: 'broker',
        extra: 'field--full',
        emptyLabel: 'listings.field.broker.none',
        options: ['searchable' => true],
        order: 10,
    )]
    private ?string $brokerId = null;

    #[ORM\Column(name: 'broker_name', length: 120, nullable: true)]
    private ?string $brokerName = null;

    #[ORM\Column(name: 'broker_email', length: 190, nullable: true)]
    private ?string $brokerEmail = null;

    #[ORM\Column(name: 'broker_phone', length: 64, nullable: true)]
    private ?string $brokerPhone = null;

    #[ORM\Column(name: 'broker_position', length: 120, nullable: true)]
    private ?string $brokerPosition = null;

    #[ORM\Column(name: 'broker_photo_url', length: 500, nullable: true)]
    private ?string $brokerPhotoUrl = null;

    #[ORM\Column(name: 'published_at', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $title, string $slug)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->title = $title;
        $this->slug = $slug;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string { return $this->id; }
    public function title(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = $title; $this->touch(); }
    public function slug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = $slug; $this->touch(); }
    public function status(): string { return $this->status; }
    public function marketStatus(): string { return $this->marketStatus; }

    public function setMarketStatus(string $marketStatus): void
    {
        if (in_array($marketStatus, self::MARKET_STATUSES, true)) {
            $this->marketStatus = $marketStatus;
            $this->touch();
        }
    }

    public function offerType(): string { return $this->offerType; }
    public function setOfferType(string $offerType): void { $this->offerType = $offerType; $this->touch(); }
    public function propertyType(): string { return $this->propertyType; }
    public function setPropertyType(string $propertyType): void { $this->propertyType = $propertyType; $this->touch(); }
    public function featured(): bool { return $this->featured; }
    public function setFeatured(bool $featured): void { $this->featured = $featured; $this->touch(); }
    public function price(): string { return $this->price; }
    public function setPrice(string $price): void { $this->price = $price; $this->touch(); }
    public function currency(): string { return $this->currency; }
    public function setCurrency(string $currency): void { $this->currency = strtoupper($currency); $this->touch(); }
    public function areaM2(): ?float { return $this->areaM2; }
    public function setAreaM2(?float $areaM2): void { $this->areaM2 = $areaM2; $this->touch(); }
    public function rooms(): ?int { return $this->rooms; }
    public function setRooms(?int $rooms): void { $this->rooms = $rooms; $this->touch(); }
    public function floor(): ?int { return $this->floor; }
    public function setFloor(?int $floor): void { $this->floor = $floor; $this->touch(); }
    public function floors(): ?int { return $this->floors; }
    public function setFloors(?int $floors): void { $this->floors = $floors; $this->touch(); }
    public function city(): string { return $this->city; }
    public function setCity(string $city): void { $this->city = $city; $this->touch(); }
    public function cityPart(): ?string { return $this->cityPart; }
    public function setCityPart(?string $cityPart): void { $this->cityPart = $cityPart; $this->touch(); }
    public function street(): ?string { return $this->street; }
    public function setStreet(?string $street): void { $this->street = $street; $this->touch(); }
    public function zip(): ?string { return $this->zip; }
    public function setZip(?string $zip): void { $this->zip = $zip; $this->touch(); }
    public function address(): ?string { return $this->address; }
    public function setAddress(?string $address): void { $this->address = $address; $this->touch(); }
    public function latitude(): ?float { return $this->latitude; }
    public function setLatitude(?float $latitude): void { $this->latitude = $latitude; $this->touch(); }
    public function longitude(): ?float { return $this->longitude; }
    public function setLongitude(?float $longitude): void { $this->longitude = $longitude; $this->touch(); }
    public function description(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description; $this->touch(); }
    public function coverImageUrl(): ?string { return $this->coverImageUrl; }
    public function setCoverImageUrl(?string $coverImageUrl): void { $this->coverImageUrl = $coverImageUrl; $this->touch(); }

    /** @return list<string> */
    public function images(): array
    {
        return array_values(array_filter($this->images, static fn ($u) => is_string($u) && $u !== ''));
    }

    /** @param list<string> $images */
    public function setImages(array $images): void
    {
        $this->images = array_values(array_filter(array_map(
            static fn ($u) => is_string($u) ? trim($u) : '',
            $images,
        )));
        if ($this->coverImageUrl === null && $this->images !== []) {
            $this->coverImageUrl = $this->images[0];
        }
        $this->touch();
    }

    public function balcony(): bool { return $this->balcony; }
    public function setBalcony(bool $v): void { $this->balcony = $v; $this->touch(); }
    public function terrace(): bool { return $this->terrace; }
    public function setTerrace(bool $v): void { $this->terrace = $v; $this->touch(); }
    public function cellar(): bool { return $this->cellar; }
    public function setCellar(bool $v): void { $this->cellar = $v; $this->touch(); }
    public function garage(): bool { return $this->garage; }
    public function setGarage(bool $v): void { $this->garage = $v; $this->touch(); }
    public function elevator(): bool { return $this->elevator; }
    public function setElevator(bool $v): void { $this->elevator = $v; $this->touch(); }
    public function parkingLots(): ?int { return $this->parkingLots; }
    public function setParkingLots(?int $parkingLots): void { $this->parkingLots = $parkingLots; $this->touch(); }
    public function energyRating(): ?string { return $this->energyRating; }

    public function setEnergyRating(?string $energyRating): void
    {
        $energyRating = $energyRating !== null ? strtoupper($energyRating) : null;
        $this->energyRating = $energyRating !== null && in_array($energyRating, self::ENERGY, true) ? $energyRating : null;
        $this->touch();
    }

    public function brokerId(): ?string { return $this->brokerId; }
    public function setBrokerId(?string $v): void { $this->brokerId = $v !== null && $v !== '' ? $v : null; $this->touch(); }
    public function brokerName(): ?string { return $this->brokerName; }
    public function setBrokerName(?string $v): void { $this->brokerName = $v; $this->touch(); }
    public function brokerEmail(): ?string { return $this->brokerEmail; }
    public function setBrokerEmail(?string $v): void { $this->brokerEmail = $v; $this->touch(); }
    public function brokerPhone(): ?string { return $this->brokerPhone; }
    public function setBrokerPhone(?string $v): void { $this->brokerPhone = $v; $this->touch(); }
    public function brokerPosition(): ?string { return $this->brokerPosition; }
    public function setBrokerPosition(?string $v): void { $this->brokerPosition = $v; $this->touch(); }
    public function brokerPhotoUrl(): ?string { return $this->brokerPhotoUrl; }
    public function setBrokerPhotoUrl(?string $v): void { $this->brokerPhotoUrl = $v; $this->touch(); }

    public function publishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function publish(): void
    {
        $this->status = 'published';
        $this->publishedAt ??= new \DateTimeImmutable();
        $this->touch();
    }

    public function unpublish(): void
    {
        $this->status = 'draft';
        $this->touch();
    }

    public function archive(): void
    {
        $this->status = 'archived';
        $this->touch();
    }

    public function statusLabel(): string
    {
        return 'listings.status.' . $this->status;
    }

    /** @return list<string> */
    public function amenityLabels(): array
    {
        $out = [];
        if ($this->balcony) $out[] = 'balcony';
        if ($this->terrace) $out[] = 'terrace';
        if ($this->cellar) $out[] = 'cellar';
        if ($this->garage) $out[] = 'garage';
        if ($this->elevator) $out[] = 'elevator';
        if (($this->parkingLots ?? 0) > 0) $out[] = 'parking';

        return $out;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
