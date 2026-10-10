<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Gereq\Customers\Application\StoreAuthService;
use Gereq\Notifications\Application\MemberNotifier;
use Modules\Events\Domain\CatalogEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EventSubmitterNotifier
{
    public function __construct(
        private readonly StoreAuthService $accounts,
        private readonly MemberNotifier $members,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function notifyRejected(CatalogEvent $event): void
    {
        $reason = trim((string) $event->rejectionReason());
        $this->push(
            $event,
            'events.rejected',
            $this->translator->trans('events.notify.rejected.title'),
            $this->translator->trans('events.notify.rejected.body', [
                '%title%' => $event->title(),
            ]),
            [
                'event_id' => $event->id(),
                'slug' => $event->slug(),
                'reason' => $reason,
                'html' => $reason,
            ],
        );
    }

    public function notifyPublished(CatalogEvent $event): void
    {
        $this->push(
            $event,
            'events.published',
            $this->translator->trans('events.notify.published.title'),
            $this->translator->trans('events.notify.published.body', [
                '%title%' => $event->title(),
            ]),
            [
                'event_id' => $event->id(),
                'slug' => $event->slug(),
            ],
        );
    }

    /** @param array<string, mixed> $meta */
    private function push(CatalogEvent $event, string $type, string $title, string $body, array $meta): void
    {
        $customerId = $event->submittedByCustomerId();
        if ($customerId === null || $customerId === '') {
            return;
        }
        $account = $this->accounts->findAccountByCustomerId($customerId);
        if ($account === null) {
            return;
        }

        try {
            $this->members->notify(
                $account->id(),
                $type,
                $title,
                $body,
                'events',
                $meta,
            );
        } catch (\Throwable) {
        }
    }
}
