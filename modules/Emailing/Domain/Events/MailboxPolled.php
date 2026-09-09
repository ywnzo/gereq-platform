<?php

declare(strict_types=1);

namespace Modules\Emailing\Domain\Events;

final class MailboxPolled extends AbstractEmailingEvent
{
    /** @param array<string, mixed> $data */
    public static function make(string $accountId, array $data = [], ?array $actor = null): self
    {
        return new self('emailing.mailbox.polled', ['type' => 'emailing_account', 'id' => $accountId], $data, $actor);
    }
}
