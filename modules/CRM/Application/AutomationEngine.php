<?php

declare(strict_types=1);

namespace Modules\CRM\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Event\EventEnvelope;
use Gereq\Queue\Queue;
use Modules\CRM\Application\Jobs\RunWorkflowJob;
use Modules\CRM\Domain\Workflow;
use Modules\CRM\Domain\WorkflowRun;

final class AutomationEngine
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Queue $queue,
    ) {
    }

    public function onPlatformEvent(EventEnvelope $envelope): void
    {
        /** @var list<Workflow> $workflows */
        $workflows = $this->em->getRepository(Workflow::class)->findBy(['isActive' => true]);
        foreach ($workflows as $workflow) {
            if (!$this->matchesTrigger($workflow, $envelope)) {
                continue;
            }
            $run = new WorkflowRun($workflow->id(), $envelope->type, $envelope->toArray());
            $this->em->persist($run);
            $this->em->flush();
            $this->queue->dispatch(new RunWorkflowJob($run->id()));
        }
    }

    private function matchesTrigger(Workflow $workflow, EventEnvelope $envelope): bool
    {
        $graph = $workflow->graph();
        foreach ($graph['nodes'] as $node) {
            if (($node['type'] ?? '') !== 'trigger.event') {
                continue;
            }
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            $event = (string) ($data['event'] ?? '');
            if ($event === '' || $event !== $envelope->type) {
                continue;
            }
            $conditions = is_array($data['conditions'] ?? null) ? $data['conditions'] : [];
            if ($this->conditionsMatch($conditions, $envelope->data)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>>|array<string, mixed> $conditions */
    private function conditionsMatch(array $conditions, array $payload): bool
    {
        if ($conditions === []) {
            return true;
        }

        // Support list of {field, op, value} or map field => value
        if (array_is_list($conditions)) {
            foreach ($conditions as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $field = (string) ($rule['field'] ?? '');
                $op = (string) ($rule['op'] ?? 'eq');
                $expected = $rule['value'] ?? null;
                $actual = $payload[$field] ?? null;
                if (!$this->compare($actual, $op, $expected)) {
                    return false;
                }
            }

            return true;
        }

        foreach ($conditions as $field => $expected) {
            if (($payload[(string) $field] ?? null) != $expected) {
                return false;
            }
        }

        return true;
    }

    private function compare(mixed $actual, string $op, mixed $expected): bool
    {
        return match ($op) {
            'neq', '!=' => $actual != $expected,
            'gt' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            default => $actual == $expected,
        };
    }
}
