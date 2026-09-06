<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

use Gereq\Debug\FullServiceLogger;
use Gereq\Module\CapabilityRegistry;
use Modules\CarService\Domain\ServiceOrder;
use Modules\Documents\Domain\Document;

final class FinishOrderService
{
    public const DOCUMENT_ENTITY = ServiceDocumentService::DOCUMENT_ENTITY;

    public function __construct(
        private readonly CarServiceProvider $carservice,
        private readonly CapabilityRegistry $capabilities,
        private readonly ServiceDocumentService $documents,
    ) {
    }

    /**
     * @param array<string, mixed> $finishMeta
     * @return array{order: ServiceOrder, document: ?Document, documents: list<Document>}
     */
    public function finish(string $orderId, array $finishMeta = []): array
    {
        FullServiceLogger::log('carservice.finish.start', [
            'order_id' => $orderId,
            'meta_keys' => array_keys($finishMeta),
            'documents_capability' => $this->capabilities->has('documents'),
        ]);

        try {
            $order = $this->carservice->finishOrder($orderId, $finishMeta);
            $documents = [];
            if ($this->capabilities->has('documents') && class_exists(Document::class)) {
                $documents = $this->generateFinishDocuments($order, $finishMeta);
            } else {
                FullServiceLogger::log('carservice.finish.documents.skip', [
                    'order_id' => $order->id(),
                    'reason' => 'documents_unavailable',
                ]);
            }

            $primary = $documents[0] ?? null;
            FullServiceLogger::log('carservice.finish.done', [
                'order_id' => $order->id(),
                'status' => $order->status(),
                'document_id' => $primary?->id(),
                'document_count' => count($documents),
                'document_ids' => array_map(static fn (Document $d): string => $d->id(), $documents),
            ]);

            return ['order' => $order, 'document' => $primary, 'documents' => $documents];
        } catch (\Throwable $e) {
            FullServiceLogger::log('carservice.finish.error', [
                'order_id' => $orderId,
                'error' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Zakázkový list, předávací protokol, nákladový list; nabídka oprav jen pokud ještě není.
     *
     * @param array<string, mixed> $extra
     * @return list<Document>
     */
    public function generateFinishDocuments(ServiceOrder $order, array $extra = []): array
    {
        $types = [
            PdfTemplateCatalog::TYPE_WORKSHEET,
            PdfTemplateCatalog::TYPE_HANDOVER,
            PdfTemplateCatalog::TYPE_COST_SHEET,
        ];

        $out = [];
        foreach ($types as $type) {
            FullServiceLogger::log('carservice.finish.document.start', [
                'order_id' => $order->id(),
                'type' => $type,
            ]);
            try {
                $document = $this->documents->generate($order, $type, $extra);
                $out[] = $document;
                FullServiceLogger::log('carservice.finish.document.done', [
                    'order_id' => $order->id(),
                    'type' => $type,
                    'document_id' => $document->id(),
                    'title' => $document->title(),
                    'path' => $document->path(),
                ]);
            } catch (\Throwable $e) {
                FullServiceLogger::log('carservice.finish.document.error', [
                    'order_id' => $order->id(),
                    'type' => $type,
                    'error' => $e,
                ]);
                if ($type === PdfTemplateCatalog::TYPE_WORKSHEET) {
                    throw $e;
                }
            }
        }

        if (!$this->documents->orderHasDocumentType($order->id(), PdfTemplateCatalog::TYPE_QUOTE)) {
            FullServiceLogger::log('carservice.finish.quote.start', [
                'order_id' => $order->id(),
            ]);
            try {
                $quote = $this->documents->generate($order, PdfTemplateCatalog::TYPE_QUOTE, $extra);
                $out[] = $quote;
                FullServiceLogger::log('carservice.finish.quote.done', [
                    'order_id' => $order->id(),
                    'document_id' => $quote->id(),
                ]);
            } catch (\Throwable $e) {
                FullServiceLogger::log('carservice.finish.quote.error', [
                    'order_id' => $order->id(),
                    'error' => $e,
                ]);
            }
        } else {
            FullServiceLogger::log('carservice.finish.quote.skip', [
                'order_id' => $order->id(),
                'reason' => 'already_exists',
            ]);
        }

        return $out;
    }

    /** @param array<string, mixed> $extra */
    public function generateWorksheet(ServiceOrder $order, array $extra = []): Document
    {
        return $this->documents->generate($order, PdfTemplateCatalog::TYPE_WORKSHEET, $extra);
    }
}
