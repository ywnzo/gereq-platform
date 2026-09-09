<?php

declare(strict_types=1);

namespace Modules\Emailing\Domain\Events;

final class MailboxFolderReconciled extends AbstractEmailingEvent
{
    /** @param array<string, mixed> $data */
    public static function make(string $folderId, array $data = [], ?array $actor = null): self
    {
        return new self('emailing.mailbox.folder_reconciled', ['type' => 'emailing_folder', 'id' => $folderId], $data, $actor);
    }
}
