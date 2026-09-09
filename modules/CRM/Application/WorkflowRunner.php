<?php

declare(strict_types=1);

namespace Modules\CRM\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Module\CapabilityRegistry;
use Gereq\Queue\Queue;
use Gereq\Security\HtmlSanitizer;
use Modules\CRM\Application\Jobs\DelayedWorkflowContinueJob;
use Modules\CRM\Domain\Activity;
use Modules\CRM\Domain\Deal;
use Modules\CRM\Domain\Workflow;
use Modules\CRM\Domain\WorkflowRun;
use Modules\Customers\Domain\Customer;

final class WorkflowRunner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CapabilityRegistry $capabilities,
        private readonly Queue $queue,
        private readonly HtmlSanitizer $htmlSanitizer,
    ) {
    }

    public function run(string $runId, ?string $resumeFromNodeId = null): void
    {
        $run = $this->em->find(WorkflowRun::class, $runId);
        if (!$run instanceof WorkflowRun) {
            return;
        }
        if (in_array($run->status(), [WorkflowRun::STATUS_COMPLETED, WorkflowRun::STATUS_FAILED], true)
            && $resumeFromNodeId === null) {
            return;
        }

        $workflow = $this->em->find(Workflow::class, $run->workflowId());
        if (!$workflow instanceof Workflow) {
            $run->markFailed('Workflow neexistuje.');
            $this->em->flush();

            return;
        }

        $graph = $workflow->graph();
        $nodes = [];
        foreach ($graph['nodes'] as $node) {
            if (isset($node['id'])) {
                $nodes[(string) $node['id']] = $node;
            }
        }
        $edges = $graph['edges'];

        $run->markRunning($resumeFromNodeId);
        $this->em->flush();

        $context = $this->buildContext($run->triggerPayload());
        $queue = $resumeFromNodeId !== null
            ? [$resumeFromNodeId]
            : $this->startNodes($nodes, $edges);

        $visited = 0;
        while ($queue !== []) {
            if (++$visited > 200) {
                $run->markFailed('Workflow překročil limit kroků.');
                $this->em->flush();

                return;
            }

            $nodeId = array_shift($queue);
            $node = $nodes[$nodeId] ?? null;
            if ($node === null) {
                continue;
            }

            $type = (string) ($node['type'] ?? '');
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];

            try {
                if ($type === 'trigger.event') {
                    $run->appendLog(['node' => $nodeId, 'type' => $type, 'ok' => true]);
                    $queue = array_merge($queue, $this->nextNodes($edges, $nodeId, 'out'));
                    continue;
                }

                if ($type === 'logic.delay') {
                    $seconds = max(1, (int) ($data['seconds'] ?? 60));
                    $next = $this->nextNodes($edges, $nodeId, 'out');
                    $continueId = $next[0] ?? null;
                    $run->appendLog(['node' => $nodeId, 'type' => $type, 'ok' => true, 'delay' => $seconds]);
                    if ($continueId === null) {
                        $run->markCompleted();
                        $this->em->flush();

                        return;
                    }
                    $run->markWaiting($continueId);
                    $this->em->flush();
                    $this->queue->dispatch(new DelayedWorkflowContinueJob($run->id(), $continueId, $seconds));

                    return;
                }

                if ($type === 'logic.if') {
                    $pass = $this->evalIf($data, $context);
                    $port = $pass ? 'true' : 'false';
                    $run->appendLog(['node' => $nodeId, 'type' => $type, 'ok' => true, 'branch' => $port]);
                    $queue = array_merge($queue, $this->nextNodes($edges, $nodeId, $port));
                    continue;
                }

                $this->executeAction($type, $data, $context, $run, $nodeId);
                $queue = array_merge($queue, $this->nextNodes($edges, $nodeId, 'out'));
            } catch (\Throwable $e) {
                $run->appendLog(['node' => $nodeId, 'type' => $type, 'ok' => false, 'error' => $e->getMessage()]);
                $run->markFailed($e->getMessage());
                $this->em->flush();

                return;
            }

            $this->em->flush();
        }

        $run->markCompleted();
        $this->em->flush();
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $edges
     * @return list<string>
     */
    private function startNodes(array $nodes, array $edges): array
    {
        $targets = [];
        foreach ($edges as $edge) {
            $targets[(string) ($edge['to'] ?? '')] = true;
        }
        $starts = [];
        foreach ($nodes as $id => $node) {
            if (($node['type'] ?? '') === 'trigger.event' || !isset($targets[$id])) {
                if (($node['type'] ?? '') === 'trigger.event') {
                    $starts[] = $id;
                }
            }
        }

        return $starts !== [] ? $starts : array_keys($nodes);
    }

    /**
     * @param list<array<string, mixed>> $edges
     * @return list<string>
     */
    private function nextNodes(array $edges, string $from, string $port): array
    {
        $out = [];
        foreach ($edges as $edge) {
            if ((string) ($edge['from'] ?? '') !== $from) {
                continue;
            }
            $edgePort = (string) ($edge['from_port'] ?? $edge['port'] ?? 'out');
            if ($edgePort !== $port) {
                continue;
            }
            $to = (string) ($edge['to'] ?? '');
            if ($to !== '') {
                $out[] = $to;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $envelope */
    private function buildContext(array $envelope): array
    {
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : $envelope;
        $context = [
            'event' => (string) ($envelope['type'] ?? ''),
            'deal' => [],
            'customer' => [],
            'activity' => [],
            'message' => [],
            'data' => $data,
        ];

        $dealId = (string) ($data['id'] ?? '');
        $resourceType = (string) (($envelope['resource']['type'] ?? '') ?: '');
        if (str_starts_with((string) ($envelope['type'] ?? ''), 'deal.') && $dealId !== '') {
            $deal = $this->em->find(Deal::class, $dealId);
            if ($deal instanceof Deal) {
                $context['deal'] = [
                    'id' => $deal->id(),
                    'title' => $deal->title(),
                    'stage' => $deal->stage(),
                    'amount' => $deal->amount(),
                    'currency' => $deal->currency(),
                    'customer_id' => $deal->customerId(),
                    'note' => $deal->note(),
                ];
                $customer = $this->em->find(Customer::class, $deal->customerId());
                if ($customer instanceof Customer) {
                    $context['customer'] = $this->customerPayload($customer);
                }
            }
        }

        if (str_starts_with((string) ($envelope['type'] ?? ''), 'activity.')) {
            $activityId = (string) ($data['id'] ?? '');
            $activity = $activityId !== '' ? $this->em->find(Activity::class, $activityId) : null;
            if ($activity instanceof Activity) {
                $context['activity'] = [
                    'id' => $activity->id(),
                    'subject' => $activity->subject(),
                    'type' => $activity->type(),
                    'customer_id' => $activity->customerId(),
                    'deal_id' => $activity->dealId(),
                ];
                $customer = $this->em->find(Customer::class, $activity->customerId());
                if ($customer instanceof Customer) {
                    $context['customer'] = $this->customerPayload($customer);
                }
            }
        }

        if (str_starts_with((string) ($envelope['type'] ?? ''), 'emailing.message.')) {
            $resourceId = (string) (($envelope['resource']['id'] ?? '') ?: ($data['id'] ?? ''));
            $context['message'] = [
                'id' => $resourceId !== '' ? $resourceId : (string) ($data['message_id'] ?? ''),
                'account_id' => $data['account_id'] ?? null,
                'folder_id' => $data['folder_id'] ?? null,
                'folder_role' => $data['folder_role'] ?? null,
                'from' => $data['from'] ?? null,
                'from_name' => $data['from_name'] ?? null,
                'to' => $data['to'] ?? null,
                'subject' => $data['subject'] ?? null,
                'has_attachments' => $data['has_attachments'] ?? false,
                'is_seen' => $data['is_seen'] ?? false,
                'keywords' => $data['keywords'] ?? [],
                'message_id' => $data['message_id'] ?? null,
            ];
        }

        unset($resourceType);

        return $context;
    }

    /** @return array<string, mixed> */
    private function customerPayload(Customer $customer): array
    {
        return [
            'id' => $customer->id(),
            'name' => $customer->name(),
            'email' => method_exists($customer, 'email') ? $customer->email() : null,
            'phone' => method_exists($customer, 'phone') ? $customer->phone() : null,
            'company' => method_exists($customer, 'company') ? $customer->company() : null,
            'ico' => method_exists($customer, 'ico') ? $customer->ico() : null,
        ];
    }

    /** @param array<string, mixed> $data */
    private function evalIf(array $data, array $context): bool
    {
        $field = (string) ($data['field'] ?? '');
        $op = (string) ($data['op'] ?? 'eq');
        $expected = $data['value'] ?? null;
        $actual = $this->resolvePath($field, $context);

        return match ($op) {
            'neq', '!=' => $actual != $expected,
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            default => $actual == $expected,
        };
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    private function executeAction(string $type, array $data, array &$context, WorkflowRun $run, string $nodeId): void
    {
        if ($type === 'action.create_activity') {
            $customerId = (string) ($data['customer_id'] ?? $context['customer']['id'] ?? $context['deal']['customer_id'] ?? '');
            if ($customerId === '') {
                throw new \RuntimeException('create_activity: chybí customer_id.');
            }
            $subject = $this->interpolate((string) ($data['subject'] ?? 'CRM úkol'), $context);
            $activity = new Activity($customerId, $subject, (string) ($data['activity_type'] ?? 'task'));
            $dealId = (string) ($data['deal_id'] ?? $context['deal']['id'] ?? '');
            if ($dealId !== '') {
                $activity->setDealId($dealId);
            }
            if (isset($data['body'])) {
                $activity->setBody($this->interpolate((string) $data['body'], $context));
            }
            $this->em->persist($activity);
            $run->appendLog(['node' => $nodeId, 'type' => $type, 'ok' => true, 'activity_id' => $activity->id()]);

            return;
        }

        if ($type === 'action.set_stage') {
            $dealId = (string) ($data['deal_id'] ?? $context['deal']['id'] ?? '');
            $stage = (string) ($data['stage'] ?? '');
            $deal = $dealId !== '' ? $this->em->find(Deal::class, $dealId) : null;
            if (!$deal instanceof Deal) {
                throw new \RuntimeException('set_stage: deal nenalezen.');
            }
            $deal->setStage($stage);
            $context['deal']['stage'] = $stage;
            $run->appendLog(['node' => $nodeId, 'type' => $type, 'ok' => true, 'stage' => $stage]);

            return;
        }

        if ($type === 'action.send_email') {
            if (!$this->capabilities->has('emailing')) {
                throw new \RuntimeException('Modul Emailing není zapnutý.');
            }
            $provider = $this->capabilities->get('emailing');
            $to = $this->interpolate((string) ($data['to'] ?? '{{customer.email}}'), $context);
            $subject = $this->interpolate((string) ($data['subject'] ?? ''), $context);
            $html = $this->htmlSanitizer->sanitize(
                $this->interpolate((string) ($data['body_html'] ?? $data['body'] ?? ''), $context)
            );
            if ($to === '' || $subject === '') {
                throw new \RuntimeException('send_email: chybí to/subject.');
            }
            $accountId = isset($data['account_id']) ? (string) $data['account_id'] : null;
            $result = $provider->send($to, $subject, $html !== '' ? $html : null, null, $accountId);
            $run->appendLog([
                'node' => $nodeId,
                'type' => $type,
                'ok' => true,
                'outbox_id' => is_object($result) && method_exists($result, 'id') ? $result->id() : null,
            ]);

            return;
        }

        throw new \RuntimeException('Neznámý typ uzlu: ' . $type);
    }

    /** @param array<string, mixed> $context */
    private function interpolate(string $template, array $context): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function (array $m) use ($context): string {
            $value = $this->resolvePath($m[1], $context);

            return $value === null ? '' : (string) $value;
        }, $template);
    }

    /** @param array<string, mixed> $context */
    private function resolvePath(string $path, array $context): mixed
    {
        $parts = explode('.', $path);
        $cur = $context;
        foreach ($parts as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return null;
            }
            $cur = $cur[$part];
        }

        return $cur;
    }
}
