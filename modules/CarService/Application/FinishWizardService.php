<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Debug\FullServiceLogger;
use Gereq\Module\CapabilityRegistry;
use Gereq\Setting\Settings;
use Modules\CarService\Domain\ServiceOrder;
use Modules\Documents\Domain\Document;
use Modules\PartsCatalog\Domain\OrderPart;

final class FinishWizardService
{
    public function __construct(
        private readonly CarServiceProvider $carservice,
        private readonly ServiceDocumentService $documents,
        private readonly CapabilityRegistry $capabilities,
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
        private readonly IdokladFinishHandler $idoklad,
        private readonly OrderEsignService $esign,
    ) {
    }

    public function hasParts(string $orderId): bool
    {
        if (!class_exists(OrderPart::class)) {
            return false;
        }
        try {
            $parts = $this->em->getRepository(OrderPart::class)->findBy(['orderId' => $orderId], null, 1);

            return $parts !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    public function includeIdoklad(): bool
    {
        if (!(bool) $this->settings->get('carservice.auto_idoklad', false)) {
            return false;
        }
        if (!$this->capabilities->has('idoklad')) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function stepsForOrder(string $orderId): array
    {
        return FinishWizardCatalog::steps(
            $this->hasParts($orderId),
            $this->includeIdoklad(),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   order: ServiceOrder,
     *   step: string,
     *   next: ?string,
     *   done: bool,
     *   document: ?Document,
     *   idoklad: ?array{ok: bool, message: string, idoklad_id?: string|null},
     *   esign: ?array{ok: bool, message: ?string, signing_url?: ?string, esign_id?: ?string}
     * }
     */
    public function processStep(string $orderId, string $step, array $data, ?\Symfony\Component\HttpFoundation\Request $request = null): array
    {
        $order = $this->carservice->getOrder($orderId);
        if ($order === null) {
            throw new \InvalidArgumentException('carservice.error.not_found');
        }
        if (!$order->isOpen()) {
            throw new \InvalidArgumentException('carservice.error.order_closed');
        }

        $steps = $this->stepsForOrder($orderId);
        if (!FinishWizardCatalog::isValid($step, $steps)) {
            throw new \InvalidArgumentException('carservice.error.invalid_finish_step');
        }

        FullServiceLogger::log('carservice.finish.wizard.step', [
            'order_id' => $orderId,
            'step' => $step,
            'steps' => $steps,
        ]);

        $document = null;
        $idokladResult = null;
        $esignResult = null;

        match ($step) {
            FinishWizardCatalog::STEP_QUOTE => $document = $this->stepQuote($order, $data),
            FinishWizardCatalog::STEP_WORKSHEET => $document = $this->stepWorksheet($order, $data),
            FinishWizardCatalog::STEP_COST_SHEET => $document = $this->stepCostSheet($order, $data),
            FinishWizardCatalog::STEP_HANDOVER => [$document, $esignResult] = $this->stepHandover($order, $data, $request),
            FinishWizardCatalog::STEP_IDOKLAD => $idokladResult = $this->stepIdoklad($order),
            default => throw new \InvalidArgumentException('carservice.error.invalid_finish_step'),
        };

        if ($step === FinishWizardCatalog::STEP_IDOKLAD && is_array($idokladResult) && empty($idokladResult['ok'])) {
            FullServiceLogger::log('carservice.finish.wizard.idoklad_blocked', [
                'order_id' => $orderId,
                'message' => $idokladResult['message'] ?? null,
            ]);

            return [
                'order' => $order,
                'step' => $step,
                'next' => $step,
                'done' => false,
                'document' => null,
                'idoklad' => $idokladResult,
                'esign' => null,
                'error' => (string) ($idokladResult['message'] ?? ''),
            ];
        }

        $next = FinishWizardCatalog::next($step, $steps);
        $done = $next === null;

        if ($done) {
            $order = $this->carservice->finishOrder($orderId, $this->metaFromData($data));
            FullServiceLogger::log('carservice.finish.wizard.done', [
                'order_id' => $orderId,
                'last_step' => $step,
            ]);
        }

        return [
            'order' => $order,
            'step' => $step,
            'next' => $next,
            'done' => $done,
            'document' => $document,
            'idoklad' => $idokladResult,
            'esign' => $esignResult,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stepQuote(ServiceOrder $order, array $data): Document
    {
        $this->carservice->updateOrderMeta($order->id(), $this->metaFromData($data));
        $order = $this->carservice->getOrder($order->id()) ?? $order;

        return $this->documents->generate($order, PdfTemplateCatalog::TYPE_QUOTE, [
            'note' => $data['note'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stepWorksheet(ServiceOrder $order, array $data): Document
    {
        $this->carservice->updateOrderMeta($order->id(), $this->metaFromData($data));
        $order = $this->carservice->getOrder($order->id()) ?? $order;

        return $this->documents->generate($order, PdfTemplateCatalog::TYPE_WORKSHEET, [
            'signature' => $data['signature'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stepCostSheet(ServiceOrder $order, array $data): Document
    {
        $this->carservice->updateOrderMeta($order->id(), $this->metaFromData($data));
        $order = $this->carservice->getOrder($order->id()) ?? $order;

        return $this->documents->generate($order, PdfTemplateCatalog::TYPE_COST_SHEET);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: Document, 1: ?array{ok: bool, message: ?string, signing_url?: ?string, esign_id?: ?string}}
     */
    private function stepHandover(ServiceOrder $order, array $data, ?\Symfony\Component\HttpFoundation\Request $request): array
    {
        $this->carservice->updateOrderMeta($order->id(), $this->metaFromData($data));
        $order = $this->carservice->getOrder($order->id()) ?? $order;

        try {
            $esignBody = null;
            if (trim((string) ($data['sign_mode'] ?? '')) === OrderEsignService::MODE_DIGITAL && $this->esign->available()) {
                $esignBody = $this->documents->renderHandoverHtmlForEsign($order, [
                    'note' => $data['note'] ?? null,
                    'defects' => $this->parseDefects($data['defects'] ?? ''),
                    'defectDeadline' => trim((string) ($data['defect_deadline'] ?? '')) ?: '—',
                    'signature_service' => $this->esign->currentSignerName(),
                ]);
            }
            $sig = $this->esign->resolveHandoverSignatures($order, $data, $request, $esignBody);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException($e->getMessage());
        }

        $document = $this->documents->generate($order, PdfTemplateCatalog::TYPE_HANDOVER, [
            'signature' => $sig['signature_service'],
            'signature_service' => $sig['signature_service'],
            'signature_customer' => $sig['signature_customer'],
            'signature_service_image' => $sig['signature_service_image'],
            'signature_customer_image' => $sig['signature_customer_image'],
            'sign_mode' => $sig['mode'],
            'note' => $data['note'] ?? null,
            'defects' => $this->parseDefects($data['defects'] ?? ''),
            'defectDeadline' => trim((string) ($data['defect_deadline'] ?? '')) ?: '—',
        ]);

        $esignInfo = null;
        if ($sig['mode'] === OrderEsignService::MODE_DIGITAL) {
            $esignInfo = [
                'ok' => true,
                'message' => $sig['message'],
                'signing_url' => $sig['signing_url'],
                'esign_id' => $sig['esign_id'],
            ];
        }

        return [$document, $esignInfo];
    }

    /**
     * @return array{ok: bool, message: string, idoklad_id?: string|null}
     */
    private function stepIdoklad(ServiceOrder $order): array
    {
        return $this->idoklad->runForOrder($order->id());
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function metaFromData(array $data): array
    {
        $meta = [];
        foreach (['payment_type', 'service_info', 'intake_fuel_level', 'note'] as $key) {
            if (array_key_exists($key, $data)) {
                $v = trim((string) ($data[$key] ?? ''));
                $meta[$key] = $v !== '' ? $v : null;
            }
        }
        if (array_key_exists('internal_cost', $data)) {
            $v = trim((string) ($data['internal_cost'] ?? ''));
            $meta['internal_cost'] = $v !== '' ? $v : null;
        }
        if (array_key_exists('external_cost', $data)) {
            $v = trim((string) ($data['external_cost'] ?? ''));
            $meta['external_cost'] = $v !== '' ? $v : null;
        }
        if (array_key_exists('intake_mileage', $data)) {
            $v = trim((string) ($data['intake_mileage'] ?? ''));
            $meta['intake_mileage'] = $v !== '' ? (int) $v : null;
        }

        return $meta;
    }

    /**
     * @return list<string>
     */
    private function parseDefects(mixed $raw): array
    {
        $text = trim((string) $raw);
        if ($text === '') {
            return [];
        }
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }
}
