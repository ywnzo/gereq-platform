<?php

declare(strict_types=1);

namespace Modules\Cms\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'cms_nav_links')]
#[ORM\Index(name: 'idx_cms_nav_links_status', columns: ['status'])]
#[FormEntity('cms.link.save')]
#[FormGenerator(
    exclude: ['id', 'publishedAt', 'createdAt', 'updatedAt', 'menuSortOrder', 'menuLocations'],
    form: 'cms.link.save',
    cols: 1,
)]
class NavLink
{
    public const STATUSES = ['draft', 'published', 'archived'];
    public const MENU_LOCATIONS = ['header', 'footer', 'custom'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'cms.field.title', order: 10, group: 'content')]
    private string $title;

    #[ORM\Column(length: 2048)]
    #[FormField(
        required: true,
        label: 'cms.field.url',
        type: 'text',
        hint: 'cms.field.link_url.hint',
        order: 20,
        group: 'content',
    )]
    private string $url;

    #[ORM\Column(length: 32)]
    #[FormField(
        required: true,
        label: 'cms.field.status',
        type: 'select',
        choicesFrom: 'STATUSES',
        choiceLabel: 'cms.page.status.',
        order: 30,
        group: 'publishing',
    )]
    private string $status = 'draft';

    /** @var list<string> */
    #[ORM\Column(name: 'menu_locations', type: 'json')]
    private array $menuLocations = [];

    #[ORM\Column(name: 'menu_sort_order', options: ['default' => 0])]
    private int $menuSortOrder = 0;

    #[ORM\Column(name: 'published_at', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $title, string $url)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->title = $title;
        $this->url = $this->normalizeUrl($url);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return list<string> */
    public function menuLocations(): array
    {
        return $this->menuLocations;
    }

    public function inMenu(string $location): bool
    {
        return in_array($location, $this->menuLocations, true);
    }

    public function menuSortOrder(): int
    {
        return $this->menuSortOrder;
    }

    public function publishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function statusLabel(): string
    {
        return 'cms.page.status.' . $this->status;
    }

    /** @return list<string> Translation keys for assigned menus */
    public function menuLocationLabels(): array
    {
        $labels = [];
        foreach ($this->menuLocations as $location) {
            $labels[] = 'cms.menu.location.' . $location;
        }

        return $labels;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->touch();
    }

    public function setUrl(string $url): void
    {
        $this->url = $this->normalizeUrl($url);
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('cms.error.invalid_status');
        }
        $wasPublished = $this->status === 'published';
        $this->status = $status;
        if ($status === 'published' && !$wasPublished) {
            $this->publishedAt = new \DateTimeImmutable();
        }
        $this->touch();
    }

    /** @param list<string>|string|null $locations */
    public function setMenuLocations(array|string|null $locations): void
    {
        if (is_string($locations)) {
            $locations = $locations !== '' ? [$locations] : [];
        }
        if ($locations === null) {
            $locations = [];
        }

        $normalized = [];
        foreach ($locations as $location) {
            if (!is_string($location)) {
                continue;
            }
            $location = trim($location);
            if ($location === '' || !in_array($location, self::MENU_LOCATIONS, true)) {
                continue;
            }
            if (!in_array($location, $normalized, true)) {
                $normalized[] = $location;
            }
        }

        $this->menuLocations = $normalized;
        $this->touch();
    }

    public function setMenuSortOrder(int $order): void
    {
        $this->menuSortOrder = max(0, $order);
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        return $url !== '' ? $url : '/';
    }
}
