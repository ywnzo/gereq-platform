<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application\Health;

/** Single health check. */
final class HealthCheck
{
    public function __construct(
        public readonly string $id,
        public readonly string $group,
        public readonly string $label,
        public readonly string $status, // pass|warn|fail
        public readonly int $weight,
        public readonly int $score,
        public readonly string $detail,
        public readonly ?string $fix = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'group' => $this->group,
            'label' => $this->label,
            'status' => $this->status,
            'weight' => $this->weight,
            'score' => $this->score,
            'detail' => $this->detail,
            'fix' => $this->fix,
        ];
    }
}
