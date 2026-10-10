<?php

declare(strict_types=1);

namespace Gereq\Notifications\Application;

use Gereq\Notifications\Channel\MailMessage;
use Gereq\Notifications\Channel\Notification as NotificationContract;
use Gereq\User\Entity\User;

final class SimpleNotification implements NotificationContract
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        private readonly string $title,
        private readonly string $body,
        private readonly array $meta = [],
        private readonly array $channels = ['database'],
    ) {
    }

    public function via(): array
    {
        return $this->channels;
    }

    public function toArray(User $user): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'meta' => $this->meta,
        ];
    }

    public function toMail(User $user): ?MailMessage
    {
        if (!in_array('email', $this->channels, true)) {
            return null;
        }

        return new MailMessage($this->title, $this->body);
    }
}
