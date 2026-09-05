<?php

declare(strict_types=1);

namespace Modules\Cms\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'cms_site_menu_items')]
#[ORM\Index(name: 'idx_cms_menu_items_menu', columns: ['menu_id', 'sort_order'])]
class SiteMenuItem
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'menu_id', length: 36)]
    private string $menuId;

    #[ORM\Column(length: 190)]
    private string $label;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(name: 'page_id', length: 36, nullable: true)]
    private ?string $pageId = null;

    #[ORM\Column(name: 'sort_order')]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'parent_id', length: 36, nullable: true)]
    private ?string $parentId = null;

    public function __construct(string $menuId, string $label, int $sortOrder = 0)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->menuId = $menuId;
        $this->label = $label;
        $this->sortOrder = $sortOrder;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function menuId(): string
    {
        return $this->menuId;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function pageId(): ?string
    {
        return $this->pageId;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function parentId(): ?string
    {
        return $this->parentId;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function setUrl(?string $url): void
    {
        $this->url = $url !== null && $url !== '' ? $url : null;
    }

    public function setPageId(?string $pageId): void
    {
        $this->pageId = $pageId !== null && $pageId !== '' ? $pageId : null;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function setParentId(?string $parentId): void
    {
        $this->parentId = $parentId !== null && $parentId !== '' ? $parentId : null;
    }

}
