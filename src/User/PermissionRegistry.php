<?php

declare(strict_types=1);

namespace Gereq\User;

final class PermissionRegistry
{
    /** @var array<string, array{name: string, label: string, group: string}> */
    private array $permissions = [];

    public function register(string $name, string $label, string $group): void
    {
        $this->permissions[$name] = [
            'name' => $name,
            'label' => $label,
            'group' => $group,
        ];
    }

    /**
     * @return array<string, array{name: string, label: string, group: string}>
     */
    public function all(): array
    {
        return $this->permissions;
    }

    public function has(string $name): bool
    {
        return isset($this->permissions[$name]);
    }
}
