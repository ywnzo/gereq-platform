<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

use Gereq\Debug\FullServiceLogger;
use Gereq\Event\EventEnvelope;
use Gereq\Hook\HookManager;
use Gereq\Module\CapabilityRegistry;
use Gereq\Setting\Settings;
use Modules\CarService\Domain\ServiceOrder;
use Modules\Documents\Application\DocumentsProvider;
use Modules\Documents\Application\Pdf\PdfDocumentGenerator;
use Modules\Documents\Application\Pdf\PdfPersistOptions;
use Modules\Documents\Domain\Document;
use Modules\iDoklad\Application\iDokladService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Po dokončení zakázky: založí fakturu v iDokladu (nebo doplní PDF u už propojené).
 */
final class IdokladFinishHandler
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CapabilityRegistry $capabilities,
        private readonly CarServiceProvider $carservice,
        private readonly HookManager $hooks,
        private readonly RequestStack $requests,
        private readonly TranslatorInterface $translator,
        private readonly ?PdfDocumentGenerator $pdfGenerator = null,
        private readonly ?DocumentsProvider $documents = null,
    ) {
    }

    public function handle(EventEnvelope $envelope): void
    {
        if ($envelope->type !== 'carservice.order_finished') {
            return;
        }

        $orderId = (string) ($envelope->resource['id'] ?? '');
        FullServiceLogger::log('carservice.idoklad.hook.start', [
            'order_id' => $orderId,
            'customer_id' => $envelope->data['customer_id'] ?? null,
            'job_seconds' => $envelope->data['job_seconds'] ?? null,
            'document_id' => $envelope->data['document_id'] ?? null,
        ]);

        if (!(bool) $this->settings->get('carservice.auto_idoklad', false)) {
            FullServiceLogger::log('carservice.idoklad.hook.skip', [
                'order_id' => $orderId,
                'reason' => 'auto_idoklad_disabled',
            ]);

            return;
        }
        if (!$this->capabilities->has('idoklad') || !class_exists(iDokladService::class)) {
            FullServiceLogger::log('carservice.idoklad.hook.skip', [
                'order_id' => $orderId,
                'reason' => 'idoklad_unavailable',
            ]);

            return;
        }
        /** @var iDokladService $idoklad */
        $idoklad = $this->capabilities->get('idoklad');
        if (!$idoklad->isEnabled()) {
            FullServiceLogger::log('carservice.idoklad.hook.skip', [
                'order_id' => $orderId,
                'reason' => 'idoklad_disabled',
            ]);

            return;
        }
        if ($orderId === '') {
            FullServiceLogger::log('carservice.idoklad.hook.skip', [
                'order_id' => $orderId,
                'reason' => 'empty_order_id',
            ]);

            return;
        }

        $customerId = (string) ($envelope->data['customer_id'] ?? '');
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
            if ($stored && !$hadPdf) {
                $this->flash('success', $this->translator->trans('carservice.flash.idoklad_pdf_stored'));
            } else {
                $this->flash('info', $this->translator->trans('carservice.flash.idoklad_already_linked', [
                    '%id%' => $existing->iDokladId(),
                ]));
            }

            return;
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
            $this->flash('warning', $this->translator->trans('carservice.flash.idoklad_partner_missing'));

            return;
        }

        $items = $this->buildInvoiceItems($orderId, $envelope);
        $filtered = $this->hooks->filter('carservice.idoklad.invoice_items', $items, $orderId, $envelope);
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
            'hours' => ((int) ($envelope->data['job_seconds'] ?? 0)) / 3600.0,
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
            $this->flash('error', $this->translator->trans('carservice.flash.idoklad_invoice_failed'));

            return;
        }

        FullServiceLogger::log('carservice.idoklad.invoice.response', [
            'order_id' => $orderId,
            'partner_id' => $partnerId,
            'isSucceed' => !empty($result['isSucceed']),
            'code' => $result['code'] ?? null,
            'message' => $result['message'] ?? null,
            'idoklad_id' => $result['data']['Id'] ?? null,
            'data' => $result['data'] ?? null,
        ]);

        if (empty($result['isSucceed']) || !isset($result['data']['Id'])) {
            FullServiceLogger::log('carservice.idoklad.invoice.failed', [
                'order_id' => $orderId,
                'code' => $result['code'] ?? null,
                'message' => $result['message'] ?? null,
            ]);
            $this->flash('error', $this->translator->trans('carservice.flash.idoklad_invoice_failed'));

            return;
        }

        $idokladId = (int) $result['data']['Id'];
        $idoklad->rememberLink('carservice_order', $orderId, (string) $idokladId, 'invoice', [
            'source' => 'carservice.order_finished',
        ]);
        FullServiceLogger::log('carservice.idoklad.invoice.linked', [
            'order_id' => $orderId,
            'idoklad_id' => (string) $idokladId,
        ]);

        // iDoklad občas potřebuje chvíli, než je PDF report připravený
        usleep(400_000);
        $stored = $this->storeInvoicePdf($idoklad, $orderId, $idokladId, $customerId, skipIfExists: false);
        $this->flash(
            'success',
            $stored
                ? $this->translator->trans('carservice.flash.idoklad_invoice_created')
                : $this->translator->trans('carservice.flash.idoklad_invoice_created_no_pdf'),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildInvoiceItems(string $orderId, EventEnvelope $envelope): array
    {
        $order = $this->carservice->getOrder($orderId);

        $jobSeconds = (int) ($envelope->data['job_seconds'] ?? 0);
        if ($jobSeconds <= 0 && $order instanceof ServiceOrder) {
            $jobSeconds = $this->carservice->totalJobSeconds($orderId);
        }
        $hours = $jobSeconds / 3600.0;

        $external = $envelope->data['external_cost'] ?? null;
        if (($external === null || $external === '') && $order instanceof ServiceOrder) {
            $external = $order->externalCost();
        }
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

    /**
     * @return bool true pokud je PDF u zakázky (nově uložené nebo už existovalo)
     */
    private function storeInvoicePdf(
        iDokladService $idoklad,
        string $orderId,
        int $idokladId,
        string $customerId,
        bool $skipIfExists,
    ): bool {
        if ($this->pdfGenerator === null) {
            FullServiceLogger::log('carservice.idoklad.invoice.pdf.skip', [
                'order_id' => $orderId,
                'idoklad_id' => (string) $idokladId,
                'reason' => 'documents_unavailable',
            ]);

            return false;
        }

        if ($skipIfExists && $this->hasInvoicePdf($orderId)) {
            FullServiceLogger::log('carservice.idoklad.invoice.pdf.skip', [
                'order_id' => $orderId,
                'idoklad_id' => (string) $idokladId,
                'reason' => 'pdf_already_stored',
            ]);

            return true;
        }

        try {
            $pdfBinary = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $pdfBinary = $idoklad->downloadDocumentPdf($idokladId, 'invoice');
                if (is_string($pdfBinary) && $pdfBinary !== '') {
                    break;
                }
                FullServiceLogger::log('carservice.idoklad.invoice.pdf.retry', [
                    'order_id' => $orderId,
                    'idoklad_id' => (string) $idokladId,
                    'attempt' => $attempt,
                ]);
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
                'customer_id' => $customerId !== '' ? $customerId : null,
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
        if ($this->documents === null) {
            return false;
        }
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
        } catch (\Throwable $e) {
            FullServiceLogger::log('carservice.idoklad.invoice.pdf.exists_check_error', [
                'order_id' => $orderId,
                'error' => $e,
            ]);
        }

        return false;
    }

    private function flash(string $type, string $message): void
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return;
        }
        $request->getSession()->getFlashBag()->add($type, $message);
    }
}
