<?php

declare(strict_types=1);

namespace Modules\BikeService\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Debug\FullServiceLogger;
use Gereq\Module\CapabilityRegistry;
use Modules\BikeService\Domain\BikeOrder;
use Modules\Customers\Domain\Customer;
use Modules\ESign\Application\ESignProvider;
use Symfony\Component\HttpFoundation\Request;

final class OrderEsignService
{
    public const MODE_MANUAL = 'manual';
    public const MODE_DIGITAL = 'digital';

    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function available(): bool
    {
        return $this->capabilities->has('esign') && class_exists(ESignProvider::class);
    }

    public function esign(): ?ESignProvider
    {
        if (!$this->available()) {
            return null;
        }
        $cap = $this->capabilities->get('esign');

        return $cap instanceof ESignProvider ? $cap : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   mode: string,
     *   signature: ?string,
     *   signature_image: ?string,
     *   signature_service_image: ?string,
     *   esign_id: ?string,
     *   signing_url: ?string,
     *   message: ?string
     * }
     */
    public function resolveFinishSignatures(BikeOrder $order, array $data, ?Request $request = null): array
    {
        $mode = trim((string) ($data['sign_mode'] ?? self::MODE_MANUAL));
        if ($mode !== self::MODE_DIGITAL || !$this->available()) {
            $sig = trim((string) ($data['signature'] ?? ''));
            if ($sig === '') {
                $sig = trim((string) ($data['signature_customer'] ?? ''));
            }

            return [
                'mode' => self::MODE_MANUAL,
                'signature' => $sig !== '' ? $sig : null,
                'signature_image' => null,
                'signature_service_image' => null,
                'esign_id' => null,
                'signing_url' => null,
                'message' => null,
            ];
        }

        $esign = $this->esign();
        if ($esign === null) {
            throw new \RuntimeException('bikeservice.error.esign_unavailable');
        }

        $customer = $order->customerId() !== null
            ? $this->em->find(Customer::class, $order->customerId())
            : null;
        $counterpartyName = $customer instanceof Customer
            ? $customer->name()
            : (trim((string) ($data['signature'] ?? '')) ?: 'Zákazník');
        $counterpartyEmail = $customer instanceof Customer ? $customer->email() : null;
        $postedEmail = trim((string) ($data['esign_email'] ?? ''));
        if ($postedEmail !== '') {
            $counterpartyEmail = $postedEmail;
        }

        $short = substr($order->id(), 0, 8);
        $body = $this->handoverBodyHtml($order, $customer instanceof Customer ? $customer : null);

        FullServiceLogger::log('bikeservice.esign.finish.start', [
            'order_id' => $order->id(),
            'counterparty' => $counterpartyName,
        ]);

        $result = $esign->signHandover(
            ESignProvider::SOURCE_BIKESERVICE_ORDER,
            $order->id(),
            'Předání kola ' . $short,
            $body,
            $counterpartyName,
            $counterpartyEmail,
            $customer instanceof Customer ? $customer->company() : null,
            trim((string) ($data['signature_provider_png'] ?? '')) ?: null,
            trim((string) ($data['signature_customer_png'] ?? '')) ?: null,
            $request,
        );

        FullServiceLogger::log('bikeservice.esign.finish.done', [
            'order_id' => $order->id(),
            'esign_id' => $result['document']->id(),
            'mode' => $result['mode'],
        ]);

        return [
            'mode' => self::MODE_DIGITAL,
            'signature' => $counterpartyName,
            'signature_image' => $result['counterparty_image'] ?? null,
            'signature_service_image' => $result['provider_image'] ?? null,
            'esign_id' => $result['document']->id(),
            'signing_url' => $result['signing_url'] ?? null,
            'message' => $result['mode'] === 'remote'
                ? 'bikeservice.flash.esign_sent'
                : 'bikeservice.flash.esign_signed',
        ];
    }

    private function handoverBodyHtml(BikeOrder $order, ?Customer $customer): string
    {
        $short = substr($order->id(), 0, 8);
        $info = htmlspecialchars((string) ($order->serviceInfo() ?? ''), ENT_QUOTES, 'UTF-8');
        $name = $customer ? htmlspecialchars($customer->name(), ENT_QUOTES, 'UTF-8') : '—';

        return '<h1>Protokol předání kola</h1>'
            . '<p>Zakázka <strong>' . htmlspecialchars($short, ENT_QUOTES, 'UTF-8') . '</strong></p>'
            . '<p>Zákazník: <strong>' . $name . '</strong></p>'
            . ($info !== '' ? '<p>' . nl2br($info) . '</p>' : '')
            . '<h2>Podpisy</h2>'
            . '<table style="width:100%"><tr>'
            . '<td style="width:50%;vertical-align:top"><h3>Servis</h3>{{ provider_signature }}</td>'
            . '<td style="width:50%;vertical-align:top"><h3>Zákazník</h3>{{ counterparty_signature }}</td>'
            . '</tr></table>';
    }
}
