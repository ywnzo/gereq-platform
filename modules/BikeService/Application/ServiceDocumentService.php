<?php

declare(strict_types=1);

namespace Modules\BikeService\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\Debug\FullServiceLogger;
use Gereq\Module\CapabilityRegistry;
use Modules\BikeService\Domain\Bike;
use Modules\BikeService\Domain\BikeJob;
use Modules\BikeService\Domain\BikeOrder;
use Modules\Customers\Domain\Customer;
use Modules\Documents\Application\Pdf\ArrayPdfDocument;
use Modules\Documents\Application\Pdf\PdfDocumentGenerator;
use Modules\Documents\Application\Pdf\PdfPersistOptions;
use Modules\Documents\Domain\Document;

/**
 * Generuje PDF dokumenty bike zakázky (Twig → Dompdf → Documents).
 * Soft: volá se jen když je capability documents dostupná.
 */
final class ServiceDocumentService
{
    public const DOCUMENT_ENTITY = 'bikeservice_order';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BikeServiceProvider $bikeservice,
        private readonly Authenticator $auth,
        private readonly CapabilityRegistry $capabilities,
        private readonly PdfDocumentGenerator $pdfDocuments,
    ) {
    }

    public function available(): bool
    {
        return $this->capabilities->has('documents')
            && class_exists(Document::class)
            && class_exists(PdfDocumentGenerator::class);
    }

    public function generate(BikeOrder $order, string $type, array $extra = []): Document
    {
        FullServiceLogger::log('bikeservice.document.generate.start', [
            'order_id' => $order->id(),
            'type' => $type,
            'status' => $order->status(),
            'extra_keys' => array_keys($extra),
        ]);

        try {
            if (!$this->available()) {
                throw new \RuntimeException('bikeservice.error.documents_unavailable');
            }
            if (!in_array($type, PdfTemplateCatalog::types(), true)) {
                throw new \InvalidArgumentException('bikeservice.error.invalid_pdf_type');
            }

            $short = substr($order->id(), 0, 8);
            $titles = [
                PdfTemplateCatalog::TYPE_WORKSHEET => 'Zakázkový list',
                PdfTemplateCatalog::TYPE_INTAKE => 'Protokol příjmu',
                PdfTemplateCatalog::TYPE_HANDOVER => 'Předávací protokol',
            ];
            $title = ($titles[$type] ?? $type) . ' ' . $short;
            $definition = new ArrayPdfDocument(
                $type,
                '@bikeservice/pdf/' . $type . '.html.twig',
                $this->buildContext($order, $type, $extra),
                $title,
                PdfTemplateCatalog::filenameSlug($type) . '-' . $short . '.pdf',
            );

            $extraLinks = [];
            $customerId = $order->customerId();
            if (is_string($customerId) && $customerId !== '') {
                $extraLinks[] = ['entityType' => 'customer', 'entityId' => $customerId];
            }

            $document = $this->pdfDocuments->generate($definition, new PdfPersistOptions(
                directory: 'bikeservice/' . date('Y/m'),
                entityType: self::DOCUMENT_ENTITY,
                entityId: $order->id(),
                uploadedBy: $this->auth->user()?->id(),
                extraLinks: $extraLinks,
            ));

            FullServiceLogger::log('bikeservice.document.generate.done', [
                'order_id' => $order->id(),
                'type' => $type,
                'document_id' => $document->id(),
                'title' => $document->title(),
                'path' => $document->path(),
                'size' => $document->size(),
            ]);

            return $document;
        } catch (\Throwable $e) {
            FullServiceLogger::log('bikeservice.document.generate.error', [
                'order_id' => $order->id(),
                'type' => $type,
                'error' => $e,
            ]);
            throw $e;
        }
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function buildContext(BikeOrder $order, string $type, array $extra): array
    {
        $bike = $this->bikeservice->getBike($order->bikeId());
        $customer = $order->customerId() !== null
            ? $this->em->find(Customer::class, $order->customerId())
            : null;
        $jobs = $this->em->getRepository(BikeJob::class)->findBy(['orderId' => $order->id()], ['startedAt' => 'ASC']);

        $labor = (float) ($order->laborCost() ?? 0);
        $parts = (float) ($order->partsCost() ?? 0);
        $net = $labor + $parts;
        $vat = round($net * 0.21, 2);
        $gross = $net + $vat;

        return [
            'type' => $type,
            'order' => $order,
            'bike' => $bike instanceof Bike ? $bike : null,
            'customer' => $customer instanceof Customer ? $customer : null,
            'jobs' => $jobs,
            'docNumber' => strtoupper(substr($order->id(), 0, 8)),
            'issuedAt' => (new \DateTimeImmutable())->format('d. m. Y'),
            'signature' => isset($extra['signature']) ? (string) $extra['signature'] : '',
            'signatureImage' => isset($extra['signature_image']) ? (trim((string) $extra['signature_image']) ?: null) : null,
            'signatureServiceImage' => isset($extra['signature_service_image']) ? (trim((string) $extra['signature_service_image']) ?: null) : null,
            'signMode' => (string) ($extra['sign_mode'] ?? 'manual'),
            'totals' => [
                'labor' => $labor,
                'parts' => $parts,
                'net' => $net,
                'vat' => $vat,
                'gross' => $gross,
            ],
        ];
    }
}
