<?php

declare(strict_types=1);

namespace Modules\CRM\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'crm_workflows')]
#[ORM\Index(name: 'idx_crm_workflows_active', columns: ['is_active'])]
class Workflow
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'is_active')]
    private bool $isActive = false;

    /** @var array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} */
    #[ORM\Column(type: 'json')]
    private array $graph = ['nodes' => [], 'edges' => []];

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->graph = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'trigger.event',
                    'x' => 80,
                    'y' => 160,
                    'data' => ['event' => 'deal.stage_changed', 'conditions' => []],
                ],
            ],
            'edges' => [],
        ];
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /** @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} */
    public function graph(): array
    {
        return $this->graph;
    }

    public function triggerEvent(): ?string
    {
        foreach ($this->graph['nodes'] as $node) {
            if (($node['type'] ?? '') !== 'trigger.event') {
                continue;
            }
            $event = trim((string) (($node['data']['event'] ?? '') ?: ''));

            return $event !== '' ? $event : null;
        }

        return null;
    }

    public function nodeCount(): int
    {
        return count($this->graph['nodes']);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description !== '' ? $description : null;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->isActive = $active;
        $this->touch();
    }

    /** @param array{nodes?: list<array<string, mixed>>, edges?: list<array<string, mixed>>} $graph */
    public function setGraph(array $graph): void
    {
        $this->graph = [
            'nodes' => array_values($graph['nodes'] ?? []),
            'edges' => array_values($graph['edges'] ?? []),
        ];
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
