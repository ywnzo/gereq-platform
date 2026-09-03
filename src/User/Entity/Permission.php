<?php

declare(strict_types=1);

namespace Gereq\User\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'permissions')]
class Permission
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 120, unique: true)]
    private string $name;

    #[ORM\Column(length: 180)]
    private string $label;

    #[ORM\Column(name: 'group_name', length: 80)]
    private string $groupName;

    public function __construct(string $name, string $label, string $groupName)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $this->label = $label;
        $this->groupName = $groupName;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function groupName(): string
    {
        return $this->groupName;
    }
}
