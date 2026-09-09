<?php

declare(strict_types=1);

namespace Modules\Emailing\Application\Jobs;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Queue\Job;
use Modules\Emailing\Application\SmtpSender;
use Modules\Emailing\Domain\OutboxMessage;

final class SendOutboxJob implements Job
{
    public function __construct(private readonly string $outboxId)
    {
    }

    public function handle(): void
    {
        $em = gereq()->make(EntityManagerInterface::class);
        $sender = gereq()->make(SmtpSender::class);
        $outbox = $em->find(OutboxMessage::class, $this->outboxId);
        if (!$outbox instanceof OutboxMessage) {
            return;
        }
        if ($outbox->status() === OutboxMessage::STATUS_SENT) {
            return;
        }

        try {
            $sender->sendNow($outbox);
        } catch (\Throwable) {
            // already marked failed in sender
        }
    }
}
