<?php

declare(strict_types=1);

namespace Modules\Payments\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Event\EventBus;
use Gereq\Setting\Settings;
use Modules\Orders\Domain\Order;
use Modules\Payments\Domain\PaymentIntent;

/**
 * IMAP bank matching scaffold — polls mailbox for VS / amount and marks intents paid.
 * Configure payments.imap_mailbox, payments.imap_user, payments.imap_password.
 */
final class ImapBankMatcher
{
    public function __construct(
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
        private readonly PaymentService $payments,
        private readonly EventBus $events,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->settings->get('payments.imap_mailbox', '')) !== ''
            && trim((string) $this->settings->get('payments.imap_user', '')) !== '';
    }

    /**
     * @return array{matched: int, scanned: int, message: string}
     */
    public function run(int $limit = 50): array
    {
        if (!$this->isConfigured()) {
            return ['matched' => 0, 'scanned' => 0, 'message' => 'IMAP not configured'];
        }
        if (!function_exists('imap_open')) {
            return ['matched' => 0, 'scanned' => 0, 'message' => 'PHP imap extension missing'];
        }

        $mailbox = (string) $this->settings->get('payments.imap_mailbox', '');
        $user = (string) $this->settings->get('payments.imap_user', '');
        $pass = (string) $this->settings->get('payments.imap_password', '');
        $inbox = @imap_open($mailbox, $user, $pass);
        if ($inbox === false) {
            return ['matched' => 0, 'scanned' => 0, 'message' => 'IMAP connect failed'];
        }

        $ids = imap_search($inbox, 'UNSEEN') ?: [];
        $ids = array_slice($ids, 0, max(1, $limit));
        $matched = 0;
        foreach ($ids as $id) {
            $body = (string) imap_body($inbox, (int) $id);
            $overview = imap_fetch_overview($inbox, (string) $id);
            $subject = is_array($overview) && isset($overview[0]->subject) ? (string) $overview[0]->subject : '';
            $hay = $subject . "\n" . $body;
            if ($this->matchMessage($hay)) {
                $matched++;
                imap_setflag_full($inbox, (string) $id, '\\Seen');
            }
        }
        imap_close($inbox);

        $this->events->emit('payment.imap_scan', ['type' => 'system', 'id' => null], [
            'matched' => $matched,
            'scanned' => count($ids),
        ]);

        return ['matched' => $matched, 'scanned' => count($ids), 'message' => 'ok'];
    }

    private function matchMessage(string $hay): bool
    {
        if (!preg_match('/\b(\d{4,16})\b/', $hay, $m)) {
            return false;
        }
        $vs = $m[1];
        $amount = null;
        if (preg_match('/(\d+[.,]\d{2})\s*(CZK|Kč|EUR)?/iu', $hay, $am)) {
            $amount = str_replace(',', '.', $am[1]);
        }

        $intent = $this->em->getRepository(PaymentIntent::class)->findOneBy(
            ['paymentReference' => $vs, 'status' => 'pending'],
            ['createdAt' => 'DESC'],
        );
        if (!$intent instanceof PaymentIntent) {
            $order = $this->em->getRepository(Order::class)->findOneBy(['number' => $vs]);
            if ($order instanceof Order) {
                $intent = $this->em->getRepository(PaymentIntent::class)->findOneBy(
                    ['orderId' => $order->id(), 'status' => 'pending'],
                    ['createdAt' => 'DESC'],
                );
            }
        }
        if (!$intent instanceof PaymentIntent) {
            return false;
        }
        if ($amount !== null && abs((float) $intent->amount() - (float) $amount) > 0.05) {
            return false;
        }
        $this->payments->markPaid($intent, 'imap:' . $vs);

        return true;
    }
}
