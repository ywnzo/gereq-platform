<?php

declare(strict_types=1);

namespace Modules\BikeService\Application;

use Gereq\Debug\FullServiceLogger;
use Gereq\Module\CapabilityRegistry;
use Modules\BikeService\Domain\BikeOrder;
use Modules\Documents\Domain\Document;

final class FinishOrderService
{
    public function __construct(
        private readonly BikeServiceProvider $bikeservice,
        private readonly CapabilityRegistry $capabilities,
        private readonly ServiceDocumentService $documents,
    ) {
    }

    /**
     * @param array<string, mixed> $finishMeta
     * @return array{order: BikeOrder, document: ?Document, documents: list<Document>}
     */
    public function finish(string $orderId, array $finishMeta = []): array
    {
        FullServiceLogger::log('bikeservice.finish.start', [
            'order_id' => $orderId,
            'meta_keys' => array_keys($finishMeta),
            'documents_capability' => $this->capabilities->has('documents'),
        ]);

        try {
            $order = $this->bikeservice->getOrder($orderId);
            if ($order === null) {
                throw new \InvalidArgumentException('bikeservice.error.order_not_found');
            }
            if (array_key_exists('service_info', $finishMeta)) {
                $order->setServiceInfo($finishMeta['service_info'] !== null ? (string) $finishMeta['service_info'] : null);
            }
            if (array_key_exists('payment_type', $finishMeta)) {
                $order->setPaymentType($finishMeta['payment_type'] !== null ? (string) $finishMeta['payment_type'] : null);
            }
            if (array_key_exists('labor_cost', $finishMeta)) {
                $order->setLaborCost($finishMeta['labor_cost']);
            }
            if (array_key_exists('parts_cost', $finishMeta)) {
                $order->setPartsCost($finishMeta['parts_cost']);
            }
            if (array_key_exists('intake_odometer_km', $finishMeta)) {
                $order->setIntakeOdometerKm(
                    $finishMeta['intake_odometer_km'] !== null && $finishMeta['intake_odometer_km'] !== ''
                        ? (int) $finishMeta['intake_odometer_km']
                        : null,
                );
            }
            if (array_key_exists('intake_battery_pct', $finishMeta)) {
                $order->setIntakeBatteryPct(
                    $finishMeta['intake_battery_pct'] !== null && $finishMeta['intake_battery_pct'] !== ''
                        ? (int) $finishMeta['intake_battery_pct']
                        : null,
                );
            }

            $order = $this->bikeservice->finishOrder($order);
            $documents = [];
            if ($this->capabilities->has('documents') && $this->documents->available()) {
                $documents = $this->generateFinishDocuments($order, $finishMeta);
            } else {
                FullServiceLogger::log('bikeservice.finish.documents.skip', [
                    'order_id' => $order->id(),
                    'reason' => 'documents_unavailable',
                ]);
            }

            $primary = $documents[0] ?? null;
            FullServiceLogger::log('bikeservice.finish.done', [
                'order_id' => $order->id(),
                'status' => $order->status(),
                'document_id' => $primary?->id(),
                'document_count' => count($documents),
            ]);

            return ['order' => $order, 'document' => $primary, 'documents' => $documents];
        } catch (\Throwable $e) {
            FullServiceLogger::log('bikeservice.finish.error', [
                'order_id' => $orderId,
                'error' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $extra
     * @return list<Document>
     */
    private function generateFinishDocuments(BikeOrder $order, array $extra = []): array
    {
        $types = [
            PdfTemplateCatalog::TYPE_WORKSHEET,
            PdfTemplateCatalog::TYPE_HANDOVER,
        ];
        $out = [];
        foreach ($types as $type) {
            FullServiceLogger::log('bikeservice.finish.document.start', [
                'order_id' => $order->id(),
                'type' => $type,
            ]);
            try {
                $document = $this->documents->generate($order, $type, $extra);
                $out[] = $document;
                FullServiceLogger::log('bikeservice.finish.document.done', [
                    'order_id' => $order->id(),
                    'type' => $type,
                    'document_id' => $document->id(),
                ]);
            } catch (\Throwable $e) {
                FullServiceLogger::log('bikeservice.finish.document.error', [
                    'order_id' => $order->id(),
                    'type' => $type,
                    'error' => $e,
                ]);
                if ($type === PdfTemplateCatalog::TYPE_WORKSHEET) {
                    throw $e;
                }
            }
        }

        return $out;
    }
}
