<?php

declare(strict_types=1);

namespace Gereq\User\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'roles')]
class Role
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 80, unique: true)]
    private string $name;

    #[ORM\Column(length: 180)]
    private string $label;

    #[ORM\Column(type: 'text')]
    private string $description = '';

    /** @var Collection<int, Permission> */
    #[ORM\ManyToMany(targetEntity: Permission::class)]
    #[ORM\JoinTable(name: 'role_permissions')]
    private Collection $permissions;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'roles')]
    private Collection $users;

    public function __construct(string $name, string $label, string $description = '')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $this->label = $label;
        $this->description = $description;
        $this->permissions = new ArrayCollection();
        $this->users = new ArrayCollection();
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

    public function description(): string
    {
        return $this->description;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    /** @return Collection<int, Permission> */
    public function permissions(): Collection
    {
        return $this->permissions;
    }

    public function givePermission(Permission $permission): void
    {
        if (!$this->permissions->contains($permission)) {
            $this->permissions->add($permission);
        }
    }

    public function revokePermission(Permission $permission): void
    {
        $this->permissions->removeElement($permission);
    }

    public function hasPermission(string $name): bool
    {
        if ($this->name === 'administrator') {
            return true;
        }

        foreach ($this->permissions as $permission) {
            if ($permission->name() === $name) {
                return true;
            }
        }

        return false;
    }

    public function syncPermissions(Permission ...$permissions): void
    {
        $this->permissions->clear();
        foreach ($permissions as $permission) {
            $this->permissions->add($permission);
        }
    }
}
