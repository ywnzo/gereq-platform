<?php

declare(strict_types=1);

namespace Modules\Emailing\Application\Jobs;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Queue\Job;
use Modules\Emailing\Application\MailboxSyncService;
use Modules\Emailing\Domain\MailAccount;

final class SyncAccountJob implements Job
{
    public function __construct(private readonly string $accountId)
    {
    }

    public function handle(): void
    {
        $em = gereq()->make(EntityManagerInterface::class);
        $sync = gereq()->make(MailboxSyncService::class);
        $account = $em->find(MailAccount::class, $this->accountId);
        if (!$account instanceof MailAccount || !$account->isActive()) {
            return;
        }

        try {
            $sync->syncAccount($account);
        } catch (\Throwable $e) {
            error_log('[emailing.SyncAccountJob] ' . $this->accountId . ': ' . $e->getMessage());
        }
    }
}
