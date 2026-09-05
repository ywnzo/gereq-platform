<?php

declare(strict_types=1);

namespace Modules\CarService\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'carservice_order_links')]
#[ORM\Index(name: 'idx_carservice_order_links_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_carservice_order_links_target', columns: ['link_type', 'link_id'])]
class ServiceOrderLink
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'order_id', length: 36)]
    private string $orderId;

    #[ORM\Column(name: 'link_type', length: 64)]
    private string $linkType;

    #[ORM\Column(name: 'link_id', length: 36)]
    private string $linkId;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $orderId, string $linkType, string $linkId)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->orderId = $orderId;
        $this->linkType = $linkType;
        $this->linkId = $linkId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function linkType(): string
    {
        return $this->linkType;
    }

    public function linkId(): string
    {
        return $this->linkId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
