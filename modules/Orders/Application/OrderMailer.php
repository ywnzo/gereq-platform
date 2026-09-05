<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use Gereq\Config\Repository as Config;
use Gereq\Setting\Settings;
use Modules\Customers\Domain\Customer;
use Modules\Orders\Domain\Order;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/** Customer-facing order lifecycle e-mails (confirmation, shipped, review request). */
final class OrderMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Settings $settings,
        private readonly Config $config,
    ) {
    }

    public function sendStatus(Order $order, Customer $customer, string $template): void
    {
        $to = $customer->email();
        if ($to === '') {
            return;
        }
        $app = (string) $this->settings->get('app.name', $this->config->get('app.name', 'GEREQ'));
        [$subject, $body] = match ($template) {
            'confirmed' => [
                sprintf('%s — objednávka %s potvrzena', $app, $order->number()),
                "Dobrý den,\n\nvaše objednávka {$order->number()} byla potvrzena.\nCelkem: {$order->total()} {$order->currency()}\n\n{$app}\n",
            ],
            'fulfilled', 'shipped' => [
                sprintf('%s — objednávka %s odeslána', $app, $order->number()),
                "Dobrý den,\n\nobjednávka {$order->number()} byla odeslána."
                    . ($order->trackingNumber() ? "\nTracking: {$order->trackingNumber()}" : '')
                    . "\n\n{$app}\n",
            ],
            'review_request' => [
                sprintf('%s — ohodnoťte nákup %s', $app, $order->number()),
                "Dobrý den,\n\nděkujeme za nákup {$order->number()}. Budeme rádi za krátké hodnocení.\n\n{$app}\n",
            ],
            default => [
                sprintf('%s — objednávka %s', $app, $order->number()),
                "Dobrý den,\n\naktualizace objednávky {$order->number()} (stav: {$order->status()}).\n\n{$app}\n",
            ],
        };

        try {
            $this->mailer->send((new Email())->to($to)->subject($subject)->text($body));
        } catch (\Throwable) {
        }
    }
}
