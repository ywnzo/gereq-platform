<?php

declare(strict_types=1);

namespace Modules\SiteInquiries\Application;

use Gereq\Setting\Settings;
use Modules\SiteInquiries\Domain\Inquiry;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InquiryNotificationSender
{
    public function __construct(
        private readonly Settings $settings,
        private readonly MailerInterface $mailer,
        private readonly \Gereq\Config\Repository $config,
        private readonly TranslatorInterface $translator,
        private readonly \Gereq\Cms\Cms $cms,
    ) {
    }

    public function send(Inquiry $inquiry): string
    {
        $to = trim((string) $this->settings->get(
            'site_inquiries.notify_email',
            $this->settings->get('site_inquiries.contact_email', ''),
        ));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return 'not_configured';
        }

        $fromAddress = (string) $this->config->get('mail.from.address', 'noreply@gereq.cz');
        $fromName = (string) $this->config->get('mail.from.name', 'GEREQ');

        $attachments = $this->cms->recordAttachments('site_inquiries.create', $inquiry->id());
        $unscanned = array_filter($attachments, static fn (array $file): bool => !in_array($file['scan_status'], ['clean', 'infected'], true));
        $infected = array_filter($attachments, static fn (array $file): bool => $file['scan_status'] === 'infected');
        $body = implode("\n", array_filter([
            $this->translator->trans('site_inquiries.mail.intro', ['%site%' => (string) $this->settings->getLive('brand.site_name', 'GEREQ')]),
            '',
            $this->translator->trans('site_inquiries.mail.name', ['%value%' => $inquiry->name()]),
            $this->translator->trans('site_inquiries.mail.phone', ['%value%' => $inquiry->phone()]),
            $this->translator->trans('site_inquiries.mail.email', ['%value%' => $inquiry->email()]),
            $inquiry->preferredDate() !== null ? $this->translator->trans('site_inquiries.mail.date', ['%value%' => $inquiry->preferredDate()]) : null,
            $inquiry->routeFrom() !== null ? $this->translator->trans('site_inquiries.mail.from', ['%value%' => $inquiry->routeFrom()]) : null,
            $inquiry->routeTo() !== null ? $this->translator->trans('site_inquiries.mail.to', ['%value%' => $inquiry->routeTo()]) : null,
            '',
            $this->translator->trans('site_inquiries.mail.details'),
            $inquiry->details(),
            $unscanned !== [] ? $this->translator->trans('site_inquiries.scan.warning') : null,
            $infected !== [] ? $this->translator->trans('site_inquiries.scan.blocked') : null,
        ], static fn (?string $line): bool => $line !== null));

        try {
            $email = (new Email())
                ->from(new Address($fromAddress, $fromName))
                ->to($to)
                ->replyTo(new Address($inquiry->email(), $inquiry->name()))
                ->subject($this->translator->trans('site_inquiries.mail.subject', ['%name%' => $inquiry->name()]))
                ->text($body);
            $this->mailer->send($email);
            return 'sent';
        } catch (\Throwable) {
            // Intake must succeed even when mail transport is unavailable.
            return 'failed';
        }
    }
}
