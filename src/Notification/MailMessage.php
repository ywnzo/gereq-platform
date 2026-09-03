<?php

declare(strict_types=1);

namespace Gereq\Notification;

final class MailMessage
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
    ) {
    }
}
