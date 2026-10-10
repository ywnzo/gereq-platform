<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Gereq\Config\Repository;
use Gereq\Http\Router;
use Gereq\Notifications\Application\NotifierBridge;
use Modules\Events\Domain\CatalogEvent;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EventAdminNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Repository $config,
        private readonly TranslatorInterface $translator,
        private readonly NotifierBridge $admins,
        private readonly Router $router,
    ) {
    }

    public function notifySubmitted(CatalogEvent $event): void
    {
        $auto = $event->status() === 'published';
        $title = $this->translator->trans($auto ? 'events.notify.submitted.auto.title' : 'events.notify.submitted.title');
        $body = $this->translator->trans($auto ? 'events.notify.submitted.auto.body' : 'events.notify.submitted.body', [
            '%title%' => $event->title(),
            '%place%' => $event->place(),
            '%when%' => $event->startsAt()->format('j. n. Y H:i'),
        ]);
        $adminUrl = $this->router->absoluteUrl('admin.events.show', ['id' => $event->id()]);
        try {
            $this->admins->notifyAdmins('events.submitted', $title, $body, [
                'event_id' => $event->id(),
                'url' => $adminUrl,
                'status' => $event->status(),
            ]);
        } catch (\Throwable) {
        }

        $to = trim((string) env_value('EVENTS_NOTIFY_EMAIL', 'info@kdeaco.cz'));
        if ($to === '') {
            return;
        }
        $fromAddress = (string) $this->config->get('mail.from.address', 'noreply@gereq.cz');
        $fromName = (string) $this->config->get('mail.from.name', 'KdeAco');
        $message = (new Email())
            ->from(new Address($fromAddress, $fromName))
            ->to($to)
            ->subject($title)
            ->text($body . "\n\n" . $adminUrl);
        try {
            $this->mailer->send($message);
        } catch (\Throwable) {
        }
    }

    public function notifyRevised(CatalogEvent $event): void
    {
        $title = $this->translator->trans('events.notify.revised.title');
        $body = $this->translator->trans('events.notify.revised.body', [
            '%title%' => $event->title(),
            '%place%' => $event->place(),
            '%when%' => $event->startsAt()->format('j. n. Y H:i'),
        ]);
        $adminUrl = $this->router->absoluteUrl('admin.events.show', ['id' => $event->id()]);
        try {
            $this->admins->notifyAdmins('events.submitted', $title, $body, [
                'event_id' => $event->id(),
                'url' => $adminUrl,
                'status' => $event->status(),
            ]);
        } catch (\Throwable) {
        }

        $to = trim((string) env_value('EVENTS_NOTIFY_EMAIL', 'info@kdeaco.cz'));
        if ($to === '') {
            return;
        }
        $fromAddress = (string) $this->config->get('mail.from.address', 'noreply@gereq.cz');
        $fromName = (string) $this->config->get('mail.from.name', 'KdeAco');
        $message = (new Email())
            ->from(new Address($fromAddress, $fromName))
            ->to($to)
            ->subject($title)
            ->text($body . "\n\n" . $adminUrl);
        try {
            $this->mailer->send($message);
        } catch (\Throwable) {
        }
    }
}
