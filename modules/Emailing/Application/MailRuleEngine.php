<?php

declare(strict_types=1);

namespace Modules\Emailing\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Event\EventBus;
use Gereq\Queue\Queue;
use Modules\Emailing\Domain\Events\MessageReceived;
use Modules\Emailing\Domain\MailAccount;
use Modules\Emailing\Domain\MailFolder;
use Modules\Emailing\Domain\MailMessage;
use Modules\Emailing\Domain\MailRule;

final class MailRuleEngine
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailboxActionService $actions,
        private readonly EventBus $events,
        private readonly Queue $queue,
    ) {
    }

    /**
     * @param list<string> $messageIds
     */
    public function processNewMessages(MailAccount $account, MailFolder $folder, array $messageIds): void
    {
        foreach ($messageIds as $messageId) {
            $message = $this->em->find(MailMessage::class, $messageId);
            if (!$message instanceof MailMessage) {
                continue;
            }
            $this->apply($account, $folder, $message);
            $message = $this->em->find(MailMessage::class, $messageId) ?? $message;
            $this->events->dispatch(MessageReceived::make($message->id(), [
                'account_id' => $account->id(),
                'folder_id' => $message->folderId(),
                'folder_role' => $folder->id() === $message->folderId() ? $folder->role() : null,
                'from' => $message->fromAddress(),
                'from_name' => $message->fromName(),
                'to' => $message->toAddresses(),
                'subject' => $message->subject(),
                'has_attachments' => $message->hasAttachments(),
                'is_seen' => $message->isSeen(),
                'keywords' => $message->keywords(),
                'message_id' => $message->messageId(),
            ]));
        }
    }

    public function apply(MailAccount $account, MailFolder $folder, MailMessage $message): void
    {
        /** @var list<MailRule> $rules */
        $rules = $this->em->createQueryBuilder()
            ->select('r')
            ->from(MailRule::class, 'r')
            ->where('r.isActive = true')
            ->andWhere('r.accountId IS NULL OR r.accountId = :aid')
            ->setParameter('aid', $account->id())
            ->orderBy('r.priority', 'ASC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()->getResult();

        foreach ($rules as $rule) {
            if (!$this->matches($rule, $account, $folder, $message)) {
                continue;
            }
            $this->runActions($rule, $account, $folder, $message);
            if ($rule->stopProcessing()) {
                break;
            }
            // Po move může být zpráva jinde — načíst znovu pokud ještě existuje.
            $fresh = $this->em->find(MailMessage::class, $message->id());
            if (!$fresh instanceof MailMessage) {
                break;
            }
            $message = $fresh;
        }
    }

    private function matches(MailRule $rule, MailAccount $account, MailFolder $folder, MailMessage $message): bool
    {
        unset($account);
        foreach ($rule->conditions() as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $field = (string) ($condition['field'] ?? '');
            $op = (string) ($condition['op'] ?? 'eq');
            $expected = $condition['value'] ?? null;
            $actual = $this->fieldValue($field, $folder, $message);
            if (!$this->compare($actual, $op, $expected)) {
                return false;
            }
        }

        return true;
    }

    private function fieldValue(string $field, MailFolder $folder, MailMessage $message): mixed
    {
        return match ($field) {
            'from' => (string) ($message->fromAddress() ?? ''),
            'from_name' => (string) ($message->fromName() ?? ''),
            'subject' => (string) ($message->subject() ?? ''),
            'to' => (string) ($message->toAddresses() ?? ''),
            'folder_id' => $message->folderId(),
            'folder_role' => $folder->role(),
            'has_attachments' => $message->hasAttachments() ? '1' : '0',
            'keyword' => implode(',', $message->keywords()),
            default => null,
        };
    }

    private function compare(mixed $actual, string $op, mixed $expected): bool
    {
        $a = is_bool($actual) ? ($actual ? '1' : '0') : (string) ($actual ?? '');
        $e = is_bool($expected) ? ($expected ? '1' : '0') : (string) ($expected ?? '');

        return match ($op) {
            'neq', '!=' => mb_strtolower($a) !== mb_strtolower($e),
            'contains' => $e !== '' && mb_stripos($a, $e) !== false,
            'not_contains' => $e === '' || mb_stripos($a, $e) === false,
            'starts_with' => $e !== '' && str_starts_with(mb_strtolower($a), mb_strtolower($e)),
            default => mb_strtolower($a) === mb_strtolower($e),
        };
    }

    private function runActions(MailRule $rule, MailAccount $account, MailFolder $folder, MailMessage $message): void
    {
        unset($folder);
        foreach ($rule->actions() as $action) {
            if (!is_array($action)) {
                continue;
            }
            $type = (string) ($action['type'] ?? '');
            try {
                match ($type) {
                    'mark_read' => $this->actions->markSeen($account, $message, true),
                    'mark_unread' => $this->actions->markSeen($account, $message, false),
                    'flag' => $this->actions->markFlagged($account, $message, true),
                    'unflag' => $this->actions->markFlagged($account, $message, false),
                    'pin' => $this->actions->markPinned($account, $message, true),
                    'unpin' => $this->actions->markPinned($account, $message, false),
                    'apply_label' => $this->applyLabel($account, $message, $action),
                    'move' => $this->move($account, $message, $action),
                    'start_workflow' => $this->startWorkflow($action, $message),
                    'emit_automation' => null,
                    default => null,
                };
            } catch (\Throwable $e) {
                error_log('[emailing.MailRuleEngine] rule=' . $rule->id() . ' action=' . $type . ': ' . $e->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $action */
    private function applyLabel(MailAccount $account, MailMessage $message, array $action): void
    {
        $keyword = (string) ($action['keyword'] ?? $action['value'] ?? '');
        if ($keyword === '') {
            return;
        }
        $this->actions->toggleKeyword($account, $message, $keyword, true);
    }

    /** @param array<string, mixed> $action */
    private function move(MailAccount $account, MailMessage $message, array $action): void
    {
        $folderId = (string) ($action['folder_id'] ?? '');
        $role = (string) ($action['folder_role'] ?? '');
        $target = null;
        if ($folderId !== '') {
            $target = $this->em->find(MailFolder::class, $folderId);
        } elseif ($role !== '') {
            $target = $this->em->getRepository(MailFolder::class)->findOneBy([
                'accountId' => $account->id(),
                'role' => $role,
            ]);
        }
        if ($target instanceof MailFolder) {
            $this->actions->moveMessage($account, $message, $target);
        }
    }

    /** @param array<string, mixed> $action */
    private function startWorkflow(array $action, MailMessage $message): void
    {
        $workflowId = (string) ($action['workflow_id'] ?? '');
        if ($workflowId === '' || !class_exists(\Modules\CRM\Domain\Workflow::class)) {
            return;
        }
        $workflow = $this->em->find(\Modules\CRM\Domain\Workflow::class, $workflowId);
        if (!$workflow instanceof \Modules\CRM\Domain\Workflow || !$workflow->isActive()) {
            return;
        }
        $run = new \Modules\CRM\Domain\WorkflowRun(
            $workflow->id(),
            'emailing.message.received',
            [
                'type' => 'emailing.message.received',
                'resource' => ['type' => 'emailing_message', 'id' => $message->id()],
                'data' => [
                    'id' => $message->id(),
                    'account_id' => $message->accountId(),
                    'from' => $message->fromAddress(),
                    'subject' => $message->subject(),
                ],
            ],
        );
        $this->em->persist($run);
        $this->em->flush();
        $this->queue->dispatch(new \Modules\CRM\Application\Jobs\RunWorkflowJob($run->id()));
    }
}
