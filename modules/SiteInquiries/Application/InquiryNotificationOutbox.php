<?php

declare(strict_types=1);

namespace Modules\SiteInquiries\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Cms\Cms;
use Modules\SiteInquiries\Domain\Inquiry;
use Ramsey\Uuid\Uuid;

/** Durable work lives on the inquiry itself; no message bodies or recipient copies. */
final class InquiryNotificationOutbox
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InquiryNotificationSender $sender,
        private readonly Cms $cms,
    ) {
    }

    /** @phpstan-impure
     * @return array{status: string, attempts: int, next_at: ?string, sent_at: ?string}
     */
    public function status(string $id): array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT notification_status, notification_attempts, notification_next_at, notification_sent_at FROM site_inquiries WHERE id = ?', [$id]);
        if ($row === false) {
            throw new \RuntimeException('Inquiry no longer exists.');
        }
        return ['status' => $row['notification_status'], 'attempts' => (int) $row['notification_attempts'], 'next_at' => $row['notification_next_at'], 'sent_at' => $row['notification_sent_at']];
    }

    /** Explicit admin retry starts a new bounded cycle, never resends accepted mail. */
    public function retry(string $id): string
    {
        $db = $this->em->getConnection();
        $db->transactional(function () use ($db, $id): void {
            $changed = $db->executeStatement("UPDATE site_inquiries SET notification_status = 'queued', notification_attempts = 0, notification_next_at = ?, notification_lease_until = NULL, notification_lease_token = NULL WHERE id = ? AND notification_status IN ('failed', 'not_configured', 'not_attempted')", [gmdate('Y-m-d H:i:s'), $id]);
            if ($changed === 1) {
                $this->cms->updateFormDeliveryStatus('site_inquiries.create', $id, 'queued');
            }
        });
        $status = $this->status($id)['status'];
        return $status === 'sending' ? 'queued' : $status;
    }

    /** Process bounded work outside request/intake transactions, recovering expired leases. */
    public function process(): void
    {
        $db = $this->em->getConnection();
        if ($db->isTransactionActive()) {
            throw new \LogicException('Notification delivery must run outside intake transactions.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $ids = $db->fetchFirstColumn("SELECT id FROM site_inquiries WHERE (notification_status = 'queued' AND notification_next_at <= ?) OR (notification_status = 'sending' AND notification_lease_until <= ?) ORDER BY notification_next_at, id LIMIT 25", [$now, $now]);
        foreach ($ids as $id) {
            $this->deliver((string) $id);
        }
    }

    private function deliver(string $id): void
    {
        $db = $this->em->getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $token = Uuid::uuid7()->toString();
        $claimed = $db->executeStatement("UPDATE site_inquiries SET notification_status = 'sending', notification_attempts = notification_attempts + 1, notification_lease_token = ?, notification_lease_until = ? WHERE id = ? AND notification_attempts < ? AND ((notification_status = 'queued' AND notification_next_at <= ?) OR (notification_status = 'sending' AND notification_lease_until <= ?))", [$token, gmdate('Y-m-d H:i:s', time() + 600), $id, self::MAX_ATTEMPTS, $now, $now]);
        if ($claimed !== 1) {
            // The final attempt may have died after claiming its lease.
            $db->transactional(function () use ($db, $id, $now): void {
                $expired = $db->executeStatement("UPDATE site_inquiries SET notification_status = 'failed', notification_lease_token = NULL, notification_lease_until = NULL, notification_next_at = NULL WHERE id = ? AND notification_status = 'sending' AND notification_attempts >= ? AND notification_lease_until <= ?", [$id, self::MAX_ATTEMPTS, $now]);
                if ($expired === 1) {
                    $this->cms->updateFormDeliveryStatus('site_inquiries.create', $id, 'failed');
                }
            });
            return;
        }
        $inquiry = $this->em->find(Inquiry::class, $id);
        if (!$inquiry instanceof Inquiry) {
            return;
        }
        try {
            $result = $this->sender->send($inquiry);
        } catch (\Throwable $exception) {
            error_log('Inquiry notification failed: ' . $exception::class);
            $result = 'failed';
        }
        $attempts = $this->status($id)['attempts'];
        $status = $result === 'failed' && $attempts < self::MAX_ATTEMPTS ? 'queued' : $result;
        $next = $status === 'queued' ? gmdate('Y-m-d H:i:s', time() + [60, 300, 900, 3600][$attempts - 1]) : null;
        $db->transactional(function () use ($db, $id, $token, $status, $result, $next): void {
            $changed = $db->executeStatement('UPDATE site_inquiries SET notification_status = ?, notification_next_at = ?, notification_sent_at = ?, notification_lease_token = NULL, notification_lease_until = NULL WHERE id = ? AND notification_lease_token = ?', [$status, $next, $result === 'sent' ? gmdate('Y-m-d H:i:s') : null, $id, $token]);
            if ($changed === 1) {
                $this->cms->updateFormDeliveryStatus('site_inquiries.create', $id, $status);
            }
        });
    }
}
