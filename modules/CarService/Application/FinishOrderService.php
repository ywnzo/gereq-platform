<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

use Gereq\Debug\FullServiceLogger;
use Gereq\Module\CapabilityRegistry;
use Modules\CarService\Domain\ServiceOrder;
use Modules\Documents\Domain\Document;

/**
 * Uzavření zakázky bez automatického generování dokumentů (ty řeší FinishWizardService).
 */
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
            'auto_documents' => false,
        ]);

        try {
            $order = $this->carservice->finishOrder($orderId, $finishMeta);
            FullServiceLogger::log('carservice.finish.done', [
                'order_id' => $order->id(),
                'status' => $order->status(),
                'document_id' => null,
                'document_count' => 0,
                'document_ids' => [],
            ]);

            return ['order' => $order, 'document' => null, 'documents' => []];
        } catch (\Throwable $e) {
            FullServiceLogger::log('carservice.finish.error', [
                'order_id' => $orderId,
                'error' => $e,
            ]);
            throw $e;
        }
    }

    /** @param array<string, mixed> $extra */
    public function generateWorksheet(ServiceOrder $order, array $extra = []): Document
    {
        return $this->documents->generate($order, PdfTemplateCatalog::TYPE_WORKSHEET, $extra);
    }
}
