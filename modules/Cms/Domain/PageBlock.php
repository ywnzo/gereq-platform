<?php

declare(strict_types=1);

namespace Modules\Cms\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'cms_page_blocks')]
#[ORM\Index(name: 'idx_cms_page_blocks_page', columns: ['page_id', 'sort_order'])]
class PageBlock
{
    public const TYPES = [
        'heading',
        'text',
        'image',
        'html',
        'cta',
        'columns',
        'divider',
        'products',
        'gallery',
    ];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'page_id', length: 36)]
    private string $pageId;

    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column(name: 'sort_order')]
    private int $sortOrder = 0;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload = [];

    public function __construct(string $pageId, string $type, int $sortOrder = 0, array $payload = [])
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('cms.error.invalid_block_type');
        }
        $this->id = Uuid::uuid7()->toString();
        $this->pageId = $pageId;
        $this->type = $type;
        $this->sortOrder = $sortOrder;
        $this->payload = $payload;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function pageId(): string
    {
        return $this->pageId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function typeLabel(): string
    {
        return 'cms.block.type.' . $this->type;
    }

    public function setType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('cms.error.invalid_block_type');
        }
        $this->type = $type;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    /** @param array<string, mixed> $payload */
    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }
}
