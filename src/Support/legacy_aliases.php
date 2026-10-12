<?php

declare(strict_types=1);

// Backward-compatible aliases after Gereq\Notification → Gereq\Notifications\Channel move.
class_alias(\Gereq\Notifications\Channel\DatabaseNotification::class, 'Gereq\\Notification\\DatabaseNotification');
class_alias(\Gereq\Notifications\Channel\Notifier::class, 'Gereq\\Notification\\Notifier');
class_alias(\Gereq\Notifications\Channel\MailMessage::class, 'Gereq\\Notification\\MailMessage');
class_alias(\Gereq\Notifications\Channel\Notification::class, 'Gereq\\Notification\\Notification');
