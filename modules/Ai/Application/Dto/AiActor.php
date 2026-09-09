<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Dto;

final class AiActor
{
    /**
     * @param list<string> $permissions
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $id,
        public readonly string $surface,
        public readonly array $permissions = [],
        public readonly array $scopes = [],
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true)
            || in_array('*', $this->permissions, true);
    }

    public function hasScope(string $scope): bool
    {
        foreach ($this->scopes as $s) {
            if ($s === '*' || $s === 'full' || $s === $scope) {
                return true;
            }
            if (str_ends_with($s, '.*')) {
                $prefix = substr($s, 0, -1);
                if (str_starts_with($scope, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
