<?php

declare(strict_types=1);

namespace Modules\SiteInquiries\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Customers\Application\LeadIntake;
use Symfony\Contracts\Translation\TranslatorInterface;
use Gereq\Event\EventBus;
use Gereq\Setting\Settings;
use Modules\SiteInquiries\Domain\Inquiry;

final class InquiryIntake
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly Settings $settings,
        private readonly TranslatorInterface $translator,
        private readonly LeadIntake $leads,
        private readonly InquiryNotificationOutbox $notifications,
    ) {
    }

    /** @param array<string, string> $values */
    public static function formDetails(array $values): string
    {
        $details = $values['details'] ?? '';
        foreach (array_diff_key($values, array_flip(['name', 'email', 'phone', 'details', 'date', 'from', 'to'])) as $name => $value) {
            if ($value !== '') {
                $details .= "\n\n" . $name . ': ' . $value;
            }
        }
        return $details;
    }

    /**
     * @return array{id: string, customer_id: string|null, status: string, delivery_status: string}
     */
    public function handle(
        string $name,
        string $email,
        string $phone,
        string $details,
        ?string $preferredDate = null,
        ?string $routeFrom = null,
        ?string $routeTo = null,
        bool $notify = true,
    ): array {
        if ($notify) {
            if ($this->em->getConnection()->isTransactionActive()) {
                throw new \LogicException('Use deferred inquiry completion inside an existing transaction.');
            }
            $result = $this->em->wrapInTransaction(fn (): array => $this->handle($name, $email, $phone, $details, $preferredDate, $routeFrom, $routeTo, notify: false));
            $result['delivery_status'] = $this->complete($result['id']);
            return $result;
        }
        if (!$this->em->getConnection()->isTransactionActive()) {
            return $this->em->wrapInTransaction(fn (): array => $this->handle($name, $email, $phone, $details, $preferredDate, $routeFrom, $routeTo, notify: false));
        }
        $name = trim($name);
        $email = strtolower(trim($email));
        $phone = trim($phone);
        $details = trim($details);

        $inquiry = new Inquiry($name, $email, $phone, $details);
        $inquiry->setPreferredDate($preferredDate);
        $inquiry->setRouteFrom($routeFrom);
        $inquiry->setRouteTo($routeTo);

        $customer = $this->ensureCustomer($name, $email, $phone, $details, $routeFrom, $routeTo, $preferredDate);
        $inquiry->setCustomerId($customer);

        $inquiry->queueNotification();
        $this->em->persist($inquiry);
        $this->em->flush();

        $delivery = 'queued';

        return [
            'id' => $inquiry->id(),
            'customer_id' => $customer,
            'status' => 'received',
            'delivery_status' => $delivery,
        ];
    }

    /** Run creation events and notification only after the owning transaction commits. */
    public function complete(string $id): string
    {
        $inquiry = $this->em->find(Inquiry::class, $id);
        if (!$inquiry instanceof Inquiry) {
            throw new \RuntimeException('Inquiry no longer exists.');
        }
        try {
            $this->events->emit('site_inquiry.created', ['type' => 'site_inquiry', 'id' => $inquiry->id()], $this->serialize($inquiry));
        } catch (\Throwable $exception) {
            // Event delivery must not turn committed intake into an apparent form failure.
            error_log('Inquiry creation event failed: ' . $exception::class);
        }
        return $this->notifications->status($id)['status'];
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
    ): string {
        $noteParts = [$this->translator->trans('site_inquiries.intake.note', ['%site%' => (string) $this->settings->getLive('brand.site_name', 'GEREQ')])];
        if ($routeFrom !== null || $routeTo !== null) {
            $noteParts[] = $this->translator->trans('site_inquiries.mail.route', ['%value%' => trim(($routeFrom ?? '?') . ' → ' . ($routeTo ?? '?'))]);
        }
        if ($preferredDate !== null) {
            $noteParts[] = $this->translator->trans('site_inquiries.mail.date', ['%value%' => $preferredDate]);
        }
        $noteParts[] = $details;
        return $this->leads->findOrCreate($name, $email, $phone, implode("\n", $noteParts));
    }
}
