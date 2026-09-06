<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\Debug\FullServiceLogger;
use Gereq\Module\CapabilityRegistry;
use Modules\CarService\Domain\ServiceOrder;
use Modules\Customers\Domain\Customer;
use Modules\ESign\Application\ESignProvider;
use Modules\Fleet\Domain\Vehicle;
use Symfony\Component\HttpFoundation\Request;

/**
 * Soft-dep na eSign: digitální předání vs. ruční podpis na PDF.
 */
final class OrderEsignService
{
    public const MODE_MANUAL = 'manual';
    public const MODE_DIGITAL = 'digital';

    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly EntityManagerInterface $em,
        private readonly CarServiceProvider $carservice,
        private readonly Authenticator $auth,
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

    /** Jméno přihlášeného uživatele pro podpis servisu. */
    public function currentSignerName(): ?string
    {
        $esign = $this->esign();
        if ($esign !== null) {
            $name = trim($esign->signing()->providerSignerName());

            return $name !== '' ? $name : null;
        }
        $user = $this->auth->user();
        if ($user === null) {
            return null;
        }
        $name = trim($user->name() ?: (string) ($user->email() ?? ''));

        return $name !== '' ? $name : null;
    }

    /** Výchozí obrázek podpisu servisu z nastavení eSign. */
    public function defaultProviderSignatureImage(): ?string
    {
        return $this->esign()?->signing()->defaultProviderSignatureDataUri();
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   mode: string,
     *   signature_service: ?string,
     *   signature_customer: ?string,
     *   signature_service_image: ?string,
     *   signature_customer_image: ?string,
     *   esign_id: ?string,
     *   signing_url: ?string,
     *   message: ?string
     * }
     */
    public function resolveHandoverSignatures(
        ServiceOrder $order,
        array $data,
        ?Request $request = null,
        ?string $bodyHtml = null,
    ): array {
        $mode = trim((string) ($data['sign_mode'] ?? self::MODE_MANUAL));
        if ($mode !== self::MODE_DIGITAL || !$this->available()) {
            $service = trim((string) ($data['signature_service'] ?? ''));
            if ($service === '') {
                $service = (string) ($this->currentSignerName() ?? '');
            }
            $customer = trim((string) ($data['signature_customer'] ?? ''));

            return [
                'mode' => self::MODE_MANUAL,
                'signature_service' => $service !== '' ? $service : null,
                'signature_customer' => $customer !== '' ? $customer : null,
                'signature_service_image' => $this->defaultProviderSignatureImage(),
                'signature_customer_image' => null,
                'esign_id' => null,
                'signing_url' => null,
                'message' => null,
            ];
        }

        $esign = $this->esign();
        if ($esign === null) {
            throw new \RuntimeException('carservice.error.esign_unavailable');
        }

        $customer = $order->customerId() !== null
            ? $this->em->find(Customer::class, $order->customerId())
            : null;
        $vehicle = $this->em->find(Vehicle::class, $order->vehicleId());
        $counterpartyName = $customer instanceof Customer
            ? $customer->name()
            : trim((string) ($data['signature_customer'] ?? 'Zákazník'));
        if ($counterpartyName === '') {
            $counterpartyName = 'Zákazník';
        }
        $counterpartyEmail = $customer instanceof Customer ? $customer->email() : null;
        $postedEmail = trim((string) ($data['esign_email'] ?? ''));
        if ($postedEmail !== '') {
            $counterpartyEmail = $postedEmail;
        }

        $short = substr($order->id(), 0, 8);
        $plate = $vehicle instanceof Vehicle ? $vehicle->plate() : '—';
        $body = $bodyHtml !== null && trim($bodyHtml) !== ''
            ? $bodyHtml
            : $this->handoverBodyHtml($order, $vehicle instanceof Vehicle ? $vehicle : null, $customer instanceof Customer ? $customer : null, $data);

        FullServiceLogger::log('carservice.esign.handover.start', [
            'order_id' => $order->id(),
            'counterparty' => $counterpartyName,
            'has_customer_pad' => trim((string) ($data['signature_customer_png'] ?? '')) !== '',
            'has_email' => $counterpartyEmail !== null && $counterpartyEmail !== '',
            'body_full_pdf' => str_contains($body, 'pdf-footer__lbl'),
        ]);

        $result = $esign->signHandover(
            ESignProvider::SOURCE_CARSERVICE_ORDER,
            $order->id(),
            'Předávací protokol ' . $short . ' · ' . $plate,
            $body,
            $counterpartyName,
            $counterpartyEmail,
            $customer instanceof Customer ? $customer->company() : null,
            trim((string) ($data['signature_provider_png'] ?? '')) ?: null,
            trim((string) ($data['signature_customer_png'] ?? '')) ?: null,
            $request,
        );

        $message = $result['mode'] === 'remote'
            ? 'carservice.flash.esign_sent'
            : 'carservice.flash.esign_signed';

        FullServiceLogger::log('carservice.esign.handover.done', [
            'order_id' => $order->id(),
            'esign_id' => $result['document']->id(),
            'mode' => $result['mode'],
        ]);

        return [
            'mode' => self::MODE_DIGITAL,
            'signature_service' => $result['document']->signedBy() ?: $this->currentSignerName(),
            'signature_customer' => $counterpartyName,
            'signature_service_image' => ($result['provider_image'] ?? null) ?: $this->defaultProviderSignatureImage(),
            'signature_customer_image' => $result['counterparty_image'] ?? null,
            'esign_id' => $result['document']->id(),
            'signing_url' => $result['signing_url'] ?? null,
            'message' => $message,
        ];
    }

    /**
     * Náhled podepsaného eSign dokumentu ve formátu plného CarService PDF.
     * Opraví i starší záznamy se zjednodušeným body HTML.
     */
    public function previewSignedHandoverHtml(\Modules\ESign\Domain\SignableDocument $document): ?string
    {
        $orderId = $document->sourceId();
        if ($orderId === null || $orderId === '') {
            return null;
        }
        $order = $this->carservice->getOrder($orderId);
        if ($order === null) {
            return null;
        }

        $esign = $this->esign();
        if ($esign === null || !class_exists(ServiceDocumentService::class)) {
            return null;
        }

        /** @var ServiceDocumentService $documents */
        $documents = gereq()->make(ServiceDocumentService::class);
        $signing = $esign->signing();
        $provider = $signing->findSignature($document->id(), \Modules\ESign\Domain\Signature::PARTY_PROVIDER);
        $counterparty = $signing->findSignature($document->id(), \Modules\ESign\Domain\Signature::PARTY_COUNTERPARTY);

        $html = $documents->renderHandoverHtmlForEsign($order, [
            'signature_service' => $document->signedBy() ?: $this->currentSignerName(),
            'signature_customer' => $document->counterpartyName(),
            'defectDeadline' => '—',
        ]);

        $renderer = gereq()->make(\Modules\ESign\Application\DocumentRenderer::class);

        return $renderer->renderTokens($html, $document, $counterparty);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function handoverBodyHtml(
        ServiceOrder $order,
        ?Vehicle $vehicle,
        ?Customer $customer,
        array $data,
    ): string {
        $short = substr($order->id(), 0, 8);
        $jobs = $this->carservice->jobsForOrder($order->id());
        $hours = round($this->carservice->totalJobSeconds($order->id(), $jobs) / 3600, 2);
        $serviceInfo = htmlspecialchars((string) ($order->serviceInfo() ?? ''), ENT_QUOTES, 'UTF-8');
        $plate = $vehicle ? htmlspecialchars($vehicle->plate(), ENT_QUOTES, 'UTF-8') : '—';
        $vehicleLabel = $vehicle
            ? htmlspecialchars(trim($vehicle->brand() . ' ' . $vehicle->displayModel()), ENT_QUOTES, 'UTF-8')
            : '—';
        $customerName = $customer ? htmlspecialchars($customer->name(), ENT_QUOTES, 'UTF-8') : '—';
        $defectsRaw = trim((string) ($data['defects'] ?? ''));
        $defectsHtml = $defectsRaw !== ''
            ? '<ul>' . implode('', array_map(
                static fn (string $l): string => '<li>' . htmlspecialchars(trim($l), ENT_QUOTES, 'UTF-8') . '</li>',
                array_values(array_filter(preg_split("/\r\n|\n|\r/", $defectsRaw) ?: [])),
            )) . '</ul>'
            : '<p>Nebyly zjištěny / bez výhrad</p>';

        return '<h1>Protokol o předání a převzetí</h1>'
            . '<p>Zakázka <strong>' . htmlspecialchars($short, ENT_QUOTES, 'UTF-8') . '</strong>'
            . ' · vozidlo <strong>' . $plate . '</strong> (' . $vehicleLabel . ')'
            . ' · odpracováno ' . htmlspecialchars((string) $hours, ENT_QUOTES, 'UTF-8') . ' h</p>'
            . '<p>Zákazník: <strong>' . $customerName . '</strong></p>'
            . ($serviceInfo !== '' ? '<p>' . nl2br($serviceInfo) . '</p>' : '')
            . '<h2>Nedostatky</h2>' . $defectsHtml
            . '<h2>Podpisy</h2>'
            . '<table style="width:100%"><tr>'
            . '<td style="width:50%;vertical-align:top"><h3>Servis</h3>{{ provider_signature }}</td>'
            . '<td style="width:50%;vertical-align:top"><h3>Zákazník</h3>{{ counterparty_signature }}</td>'
            . '</tr></table>';
    }
}
