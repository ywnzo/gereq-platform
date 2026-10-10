<?php

declare(strict_types=1);

namespace Modules\SiteInquiries\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Customers\Domain\Customer;
use Gereq\Event\EventBus;
use Gereq\Setting\Settings;
use Modules\SiteInquiries\Domain\Inquiry;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class InquiryIntake
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly Settings $settings,
        private readonly MailerInterface $mailer,
    ) {
    }

    /**
     * @return array{id: string, customer_id: string|null, status: string}
     */
    public function handle(
        string $name,
        string $email,
        string $phone,
        string $details,
        ?string $preferredDate = null,
        ?string $routeFrom = null,
        ?string $routeTo = null,
    ): array {
        $name = trim($name);
        $email = strtolower(trim($email));
        $phone = trim($phone);
        $details = trim($details);

        $inquiry = new Inquiry($name, $email, $phone, $details);
        $inquiry->setPreferredDate($preferredDate);
        $inquiry->setRouteFrom($routeFrom);
        $inquiry->setRouteTo($routeTo);

        $customer = $this->ensureCustomer($name, $email, $phone, $details, $routeFrom, $routeTo, $preferredDate);
        $inquiry->setCustomerId($customer->id());

        $this->em->persist($inquiry);
        $this->em->flush();

        $payload = $this->serialize($inquiry);
        $this->events->emit('site_inquiry.created', ['type' => 'site_inquiry', 'id' => $inquiry->id()], $payload);
        $this->notify($inquiry);

        return [
            'id' => $inquiry->id(),
            'customer_id' => $customer->id(),
            'status' => 'received',
        ];
    }

    /** @return array<string, mixed> */
    public function serialize(Inquiry $inquiry): array
    {
        return [
            'id' => $inquiry->id(),
            'name' => $inquiry->name(),
            'email' => $inquiry->email(),
            'phone' => $inquiry->phone(),
            'preferred_date' => $inquiry->preferredDate(),
            'route_from' => $inquiry->routeFrom(),
            'route_to' => $inquiry->routeTo(),
            'details' => $inquiry->details(),
            'status' => $inquiry->status(),
            'resolved' => $inquiry->isResolved(),
            'resolved_at' => $inquiry->resolvedAt()?->format(\DateTimeInterface::ATOM),
            'customer_id' => $inquiry->customerId(),
            'created_at' => $inquiry->createdAt()->format(\DateTimeInterface::ATOM),
            'created_day' => $inquiry->createdAt()->format('Y-m-d'),
        ];
    }

    private function ensureCustomer(
        string $name,
        string $email,
        string $phone,
        string $details,
        ?string $routeFrom,
        ?string $routeTo,
        ?string $preferredDate,
    ): Customer {
        $existing = $this->em->getRepository(Customer::class)->findOneBy(['email' => $email]);
        $noteParts = ['Poptávka z webu Doprovody Vohralík.'];
        if ($routeFrom !== null || $routeTo !== null) {
            $noteParts[] = 'Trasa: ' . trim(($routeFrom ?? '?') . ' → ' . ($routeTo ?? '?'));
        }
        if ($preferredDate !== null) {
            $noteParts[] = 'Termín: ' . $preferredDate;
        }
        $noteParts[] = $details;
        $note = implode("\n", $noteParts);

        if ($existing instanceof Customer) {
            if ($existing->status() === 'inactive') {
                $existing->setStatus('lead');
            }
            if ($existing->phone() === null || $existing->phone() === '') {
                $existing->setPhone($phone);
            }
            $existing->setNote(trim(($existing->note() ?? '') . "\n\n" . $note));
            $this->em->flush();

            return $existing;
        }

        $customer = new Customer($name, $email);
        $customer->setPhone($phone);
        $customer->setStatus('lead');
        $customer->setNote($note);
        $this->em->persist($customer);
        $this->em->flush();

        return $customer;
    }

    private function notify(Inquiry $inquiry): void
    {
        $to = trim((string) $this->settings->get(
            'site_inquiries.notify_email',
            $this->settings->get('site_inquiries.contact_email', 'info@doprovodyvohralik.cz'),
        ));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $fromAddress = (string) config('mail.from.address', 'noreply@gereq.cz');
        $fromName = (string) config('mail.from.name', 'Doprovody Vohralík');

        $body = implode("\n", array_filter([
            'Nová poptávka z webu doprovodyvohralik.cz',
            '',
            'Jméno / firma: ' . $inquiry->name(),
            'Telefon: ' . $inquiry->phone(),
            'E-mail: ' . $inquiry->email(),
            $inquiry->preferredDate() !== null ? 'Termín: ' . $inquiry->preferredDate() : null,
            $inquiry->routeFrom() !== null ? 'Odkud: ' . $inquiry->routeFrom() : null,
            $inquiry->routeTo() !== null ? 'Kam: ' . $inquiry->routeTo() : null,
            '',
            'Informace o přepravě:',
            $inquiry->details(),
        ], static fn (?string $line): bool => $line !== null));

        try {
            $email = (new Email())
                ->from(new Address($fromAddress, $fromName))
                ->to($to)
                ->replyTo(new Address($inquiry->email(), $inquiry->name()))
                ->subject('Poptávka: ' . $inquiry->name())
                ->text($body);
            $this->mailer->send($email);
        } catch (\Throwable) {
            // Intake must succeed even when mail transport is unavailable.
        }
    }
}
