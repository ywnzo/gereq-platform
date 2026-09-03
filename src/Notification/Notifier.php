<?php

declare(strict_types=1);

namespace Gereq\Notification;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\User\Entity\User;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class Notifier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
    ) {
    }

    public function send(User $user, Notification $notification): void
    {
        foreach ($notification->via() as $channel) {
            match ($channel) {
                'database' => $this->toDatabase($user, $notification),
                'email' => $this->toEmail($user, $notification),
                'websocket' => null,
                default => null,
            };
        }
    }

    private function toDatabase(User $user, Notification $notification): void
    {
        $record = new DatabaseNotification($user->id(), $notification::class, $notification->toArray($user));
        $this->em->persist($record);
        $this->em->flush();
    }

    private function toEmail(User $user, Notification $notification): void
    {
        $message = $notification->toMail($user);
        if ($message === null) {
            return;
        }

        $email = (new Email())
            ->to($user->email())
            ->subject($message->subject)
            ->text($message->body);

        $this->mailer->send($email);
    }
}
