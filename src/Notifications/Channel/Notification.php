<?php

declare(strict_types=1);

namespace Gereq\Notifications\Channel;

use Gereq\User\Entity\User;

interface Notification
{
    /** @return list<string> */
    public function via(): array;

    /** @return array<string, mixed> */
    public function toArray(User $user): array;

    public function toMail(User $user): ?MailMessage;
}
