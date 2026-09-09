<?php

declare(strict_types=1);

namespace Modules\Emailing\Domain\Events;

final class MessageReceived extends AbstractEmailingEvent
{
    /** @param array<string, mixed> $data */
    public static function make(string $messageId, array $data = [], ?array $actor = null): self
    {
        return new self('emailing.message.received', ['type' => 'emailing_message', 'id' => $messageId], $data, $actor);
    }
}
