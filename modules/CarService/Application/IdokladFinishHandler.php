<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

use Gereq\Debug\FullServiceLogger;
use Gereq\Hook\HookManager;
use Gereq\Module\CapabilityRegistry;
use Gereq\Setting\Settings;
use Modules\CarService\Domain\ServiceOrder;
use Modules\Documents\Application\DocumentsProvider;
use Modules\Documents\Application\Pdf\PdfDocumentGenerator;
use Modules\Documents\Application\Pdf\PdfPersistOptions;
use Modules\Documents\Domain\Document;
use Modules\iDoklad\Application\iDokladService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Založení faktury v iDokladu + stažení PDF (volané z finish wizardu, ne auto z eventu).
 */
final class IdokladFinishHandler
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CapabilityRegistry $capabilities,
        private readonly CarServiceProvider $carservice,
        private readonly HookManager $hooks,
        private readonly TranslatorInterface $translator,
        private readonly PdfDocumentGenerator $pdfGenerator,
        private readonly DocumentsProvider $documents,
    ) {
    }

    /**
     * @return array{ok: bool, message: string, idoklad_id?: string|null, pdf_stored?: bool}
     */
    public function runForOrder(string $orderId): array
    {
        FullServiceLogger::log('carservice.idoklad.wizard.start', [
            'order_id' => $orderId,
        ]);

        if (!$this->capabilities->has('idoklad') || !class_exists(iDokladService::class)) {
            return $this->fail('idoklad_unavailable', 'carservice.flash.idoklad_invoice_failed');
        }
        /** @var iDokladService $idoklad */
        $idoklad = $this->capabilities->get('idoklad');
        if (!$idoklad->isEnabled()) {
            return $this->fail('idoklad_disabled', 'carservice.flash.idoklad_invoice_failed');
        }
        if ($orderId === '') {
            return $this->fail('empty_order_id', 'carservice.flash.idoklad_invoice_failed');
        }

        $order = $this->carservice->getOrder($orderId);
        $customerId = $order instanceof ServiceOrder ? (string) ($order->customerId() ?? '') : '';

        $existing = $idoklad->findLink('carservice_order', $orderId, 'invoice');
        if ($existing !== null) {
            $idokladId = (int) $existing->iDokladId();
            FullServiceLogger::log('carservice.idoklad.hook.skip', [
                'order_id' => $orderId,
                'reason' => 'invoice_already_linked',
                'idoklad_id' => $existing->iDokladId(),
            ]);
            $hadPdf = $this->hasInvoicePdf($orderId);
            $stored = $this->storeInvoicePdf($idoklad, $orderId, $idokladId, $customerId, skipIfExists: true);

            return [
                'ok' => true,
                'message' => $stored && !$hadPdf
                    ? $this->translator->trans('carservice.flash.idoklad_pdf_stored')
                    : $this->translator->trans('carservice.flash.idoklad_already_linked', [
                        '%id%' => $existing->iDokladId(),
                    ]),
                'idoklad_id' => $existing->iDokladId(),
                'pdf_stored' => $stored,
            ];
        }

        $partnerId = 0;
        if ($customerId !== '') {
            $contactLink = $idoklad->findLink('customer', $customerId, 'contact');
            if ($contactLink !== null) {
                $partnerId = (int) $contactLink->iDokladId();
            }
        }
        if ($partnerId <= 0) {
            FullServiceLogger::log('carservice.idoklad.hook.skip', [
                'order_id' => $orderId,
                'reason' => 'partner_not_linked',
                'customer_id' => $customerId !== '' ? $customerId : null,
            ]);

            return $this->fail('partner_not_linked', 'carservice.flash.idoklad_partner_missing');
        }

        $items = $this->buildInvoiceItems($orderId);
        $filtered = $this->hooks->filter('carservice.idoklad.invoice_items', $items, $orderId, null);
        if (is_array($filtered)) {
            /** @var list<array<string, mixed>> $items */
            $items = array_values($filtered);
        }
        if ($items === []) {
            $items[] = [
                'Name' => 'Servisní zakázka ' . substr($orderId, 0, 8),
                'Amount' => 1,
                'Unit' => 'kpl',
                'UnitPrice' => 0,
            ];
        }

        $payload = [
            'documentType' => 'invoice',
            'Description' => 'Servisní zakázka ' . substr($orderId, 0, 8),
            'Items' => array_values($items),
        ];
        FullServiceLogger::log('carservice.idoklad.invoice.request', [
            'order_id' => $orderId,
            'partner_id' => $partnerId,
            'customer_id' => $customerId,
            'items_count' => count($items),
            'payload' => $payload,
        ]);

        try {
            $result = $idoklad->setDocument($payload, $partnerId);
        } catch (\Throwable $e) {
            FullServiceLogger::log('carservice.idoklad.invoice.error', [
                'order_id' => $orderId,
                'partner_id' => $partnerId,
                'error' => $e,
            ]);

            return $this->fail('api_error', 'carservice.flash.idoklad_invoice_failed');
        }

        FullServiceLogger::log('carservice.idoklad.invoice.response', [
            'order_id' => $orderId,
            'partner_id' => $partnerId,
            'isSucceed' => !empty($result['isSucceed']),
            'code' => $result['code'] ?? null,
            'message' => $result['message'] ?? null,
            'idoklad_id' => $result['data']['Id'] ?? null,
        ]);

        if (empty($result['isSucceed']) || !isset($result['data']['Id'])) {
            return $this->fail('create_failed', 'carservice.flash.idoklad_invoice_failed');
        }

        $idokladId = (int) $result['data']['Id'];
        $idoklad->rememberLink('carservice_order', $orderId, (string) $idokladId, 'invoice', [
            'source' => 'carservice.finish.wizard',
        ]);
        FullServiceLogger::log('carservice.idoklad.invoice.linked', [
            'order_id' => $orderId,
            'idoklad_id' => (string) $idokladId,
        ]);

        usleep(400_000);
        $stored = $this->storeInvoicePdf($idoklad, $orderId, $idokladId, $customerId, skipIfExists: false);

        return [
            'ok' => true,
            'message' => $this->translator->trans(
                $stored
                    ? 'carservice.flash.idoklad_invoice_created'
                    : 'carservice.flash.idoklad_invoice_created_no_pdf',
            ),
            'idoklad_id' => (string) $idokladId,
            'pdf_stored' => $stored,
        ];
    }

    /**
     * @return array{ok: false, message: string, idoklad_id?: null}
     */
    private function fail(string $reason, string $messageKey): array
    {
        FullServiceLogger::log('carservice.idoklad.wizard.fail', [
            'reason' => $reason,
        ]);

        return [
            'ok' => false,
            'message' => $this->translator->trans($messageKey),
            'idoklad_id' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildInvoiceItems(string $orderId): array
    {
        $order = $this->carservice->getOrder($orderId);
        $jobSeconds = $order instanceof ServiceOrder ? $this->carservice->totalJobSeconds($orderId) : 0;
        $hours = $jobSeconds / 3600.0;

        $external = $order instanceof ServiceOrder ? $order->externalCost() : null;
        $externalAmount = is_numeric($external) ? (float) $external : null;

        if ($externalAmount !== null && $externalAmount > 0) {
            return [[
                'Name' => 'Servisní práce',
                'Amount' => 1,
                'Unit' => 'kpl',
                'UnitPrice' => round($externalAmount, 2),
            ]];
        }

        if ($hours > 0) {
            $team = $order instanceof ServiceOrder ? $order->team() : null;
            $costRate = LaborRateCatalog::resolve(
                fn (string $key, string $default): string => (string) $this->settings->get($key, $default),
                $team,
            );
            $margin = (float) $this->settings->get('carservice.margin_percent', '30');
            $sellRate = round($costRate * (1 + max(0.0, $margin) / 100.0), 2);

            return [[
                'Name' => 'Servisní práce',
                'Amount' => round(max($hours, 0.01), 2),
                'Unit' => 'hod',
                'UnitPrice' => $sellRate,
            ]];
        }

        return [];
    }

    private function storeInvoicePdf(
        iDokladService $idoklad,
        string $orderId,
        int $idokladId,
        string $customerId,
        bool $skipIfExists,
    ): bool {
        if ($skipIfExists && $this->hasInvoicePdf($orderId)) {
            return true;
        }

        try {
            $pdfBinary = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $pdfBinary = $idoklad->downloadDocumentPdf($idokladId, 'invoice');
                if (is_string($pdfBinary) && $pdfBinary !== '') {
                    break;
                }
                usleep(500_000 * $attempt);
            }

            if (!is_string($pdfBinary) || $pdfBinary === '') {
                FullServiceLogger::log('carservice.idoklad.invoice.pdf.empty', [
                    'order_id' => $orderId,
                    'idoklad_id' => (string) $idokladId,
                ]);

                return false;
            }

            $extraLinks = [];
            if ($customerId !== '') {
                $extraLinks[] = ['entityType' => 'customer', 'entityId' => $customerId];
            }

            $invoiceDoc = $this->pdfGenerator->storeBinary(
                'Faktura iDoklad ' . substr($orderId, 0, 8),
                $pdfBinary,
                'faktura-idoklad-' . substr($orderId, 0, 8) . '.pdf',
                'application/pdf',
                new PdfPersistOptions(
                    directory: 'carservice/' . date('Y/m'),
                    entityType: 'carservice_order',
                    entityId: $orderId,
                    uploadedBy: null,
                    extraLinks: $extraLinks,
                ),
            );
            FullServiceLogger::log('carservice.idoklad.invoice.pdf.stored', [
                'order_id' => $orderId,
                'idoklad_id' => (string) $idokladId,
                'document_id' => $invoiceDoc->id(),
                'path' => $invoiceDoc->path(),
                'bytes' => $invoiceDoc->size(),
            ]);

            return true;
        } catch (\Throwable $e) {
            FullServiceLogger::log('carservice.idoklad.invoice.pdf.error', [
                'order_id' => $orderId,
                'idoklad_id' => (string) $idokladId,
                'error' => $e,
            ]);

            return false;
        }
    }

    private function hasInvoicePdf(string $orderId): bool
    {
        try {
            foreach ($this->documents->forEntity('carservice_order', $orderId) as $doc) {
                if (!$doc instanceof Document) {
                    continue;
                }
                if (str_contains($doc->title(), 'Faktura iDoklad')
                    || str_starts_with($doc->originalName(), 'faktura-idoklad-')) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}
