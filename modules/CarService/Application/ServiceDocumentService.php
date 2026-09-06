<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\Config\Repository as Config;
use Gereq\Debug\FullServiceLogger;
use Gereq\Setting\Settings;
use Modules\CarService\Domain\ServiceBranch;
use Modules\CarService\Domain\ServiceJob;
use Modules\CarService\Domain\ServiceOrder;
use Modules\Currency\Application\CurrencyBuilder;
use Modules\Customers\Domain\Customer;
use Modules\Documents\Application\Pdf\ArrayPdfDocument;
use Modules\Documents\Application\Pdf\PdfDocumentGenerator;
use Modules\Documents\Application\Pdf\PdfPersistOptions;
use Modules\Documents\Application\Pdf\PdfRenderer;
use Modules\Documents\Application\Pdf\PdfRenderResult;
use Modules\Documents\Domain\Document;
use Modules\Fleet\Domain\Vehicle;

/**
 * Generuje PDF dokumenty servisní zakázky (Twig → Dompdf → Documents).
 */
final class ServiceDocumentService
{
    public const DOCUMENT_ENTITY = 'carservice_order';

    private const VAT_RATE = 21.0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CarServiceProvider $carservice,
        private readonly Config $config,
        private readonly Settings $settings,
        private readonly Authenticator $auth,
        private readonly PdfTemplateService $pdfTemplates,
        private readonly PdfDocumentGenerator $pdfDocuments,
        private readonly PdfRenderer $pdfRenderer,
        private readonly CurrencyBuilder $money,
    ) {
    }

    public function viewFor(string $type): string
    {
        if (!in_array($type, PdfTemplateCatalog::types(), true)) {
            throw new \InvalidArgumentException('carservice.error.invalid_pdf_type');
        }

        return '@carservice/pdf/' . $type . '.html.twig';
    }

    public function filenameSlug(string $type): string
    {
        return match ($type) {
            PdfTemplateCatalog::TYPE_WORKSHEET => 'zakazkovy-list',
            PdfTemplateCatalog::TYPE_INTAKE => 'protokol-prijmu',
            PdfTemplateCatalog::TYPE_HANDOVER => 'predavaci-protokol',
            PdfTemplateCatalog::TYPE_QUOTE => 'nabidka',
            PdfTemplateCatalog::TYPE_COST_SHEET => 'nakladovy-list',
            default => $type,
        };
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function generate(ServiceOrder $order, string $type, array $extra = []): Document
    {
        FullServiceLogger::log('carservice.document.generate.start', [
            'order_id' => $order->id(),
            'type' => $type,
            'status' => $order->status(),
            'extra_keys' => array_keys($extra),
        ]);

        try {
            $docTitle = $this->pdfTemplates->documentTitle($type);
            $short = substr($order->id(), 0, 8);
            $context = $this->buildContext($order, $type, $extra);
            $definition = new ArrayPdfDocument(
                $type,
                $this->viewFor($type),
                $context,
                $docTitle . ' ' . $short,
                $this->filenameSlug($type) . '-' . $short . '.pdf',
            );

            $extraLinks = [];
            $customerId = $order->customerId();
            if (is_string($customerId) && $customerId !== '') {
                $extraLinks[] = ['entityType' => 'customer', 'entityId' => $customerId];
            }

            $document = $this->pdfDocuments->generate($definition, new PdfPersistOptions(
                directory: 'carservice/' . date('Y/m'),
                entityType: self::DOCUMENT_ENTITY,
                entityId: $order->id(),
                uploadedBy: $this->auth->user()?->id(),
                extraLinks: $extraLinks,
            ));

            FullServiceLogger::log('carservice.document.generate.done', [
                'order_id' => $order->id(),
                'type' => $type,
                'document_id' => $document->id(),
                'title' => $document->title(),
                'path' => $document->path(),
                'size' => $document->size(),
            ]);

            return $document;
        } catch (\Throwable $e) {
            FullServiceLogger::log('carservice.document.generate.error', [
                'order_id' => $order->id(),
                'type' => $type,
                'error' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Má zakázka už vygenerovaný PDF daného typu?
     * Pozn.: original_name je slug (protokol-prijmu-…); title je lokalizovaný název.
     *
     * @param list<Document>|null $documents pokud null, načte z DB
     */
    public function orderHasDocumentType(string $orderId, string $type, ?array $documents = null): bool
    {
        if (!in_array($type, PdfTemplateCatalog::types(), true)) {
            return false;
        }
        $prefix = strtolower($this->filenameSlug($type) . '-');
        $titlePrefix = mb_strtolower($this->pdfTemplates->documentTitle($type));

        $docs = $documents;
        if ($docs === null) {
            $docs = [];
            $links = $this->em->getRepository(\Modules\Documents\Domain\DocumentLink::class)
                ->findBy(['entityType' => self::DOCUMENT_ENTITY, 'entityId' => $orderId]);
            foreach ($links as $link) {
                $doc = $this->em->find(Document::class, $link->documentId());
                if ($doc instanceof Document) {
                    $docs[] = $doc;
                }
            }
        }

        foreach ($docs as $doc) {
            if (!$doc instanceof Document) {
                continue;
            }
            $name = strtolower($doc->originalName());
            $pathBase = strtolower(basename($doc->path()));
            $title = mb_strtolower($doc->title());
            if (
                str_starts_with($name, $prefix)
                || str_starts_with($pathBase, $prefix)
                || str_starts_with($title, $titlePrefix)
            ) {
                return true;
            }
        }

        return false;
    }

    public function generateForOrderId(string $orderId, string $type, array $extra = []): Document
    {
        $order = $this->carservice->getOrder($orderId);
        if (!$order instanceof ServiceOrder) {
            throw new \InvalidArgumentException('carservice.error.order_not_found');
        }

        return $this->generate($order, $type, $extra);
    }

    /**
     * @param array{header?: string, footer?: string}|null $chrome
     */
    public function preview(string $type, ?array $chrome = null, ?ServiceOrder $order = null): PdfRenderResult
    {
        $context = $order instanceof ServiceOrder
            ? $this->buildContext($order, $type, ['isPreview' => true, 'chrome' => $chrome])
            : $this->sampleContext($type, $chrome);

        return $this->pdfRenderer->render($this->viewFor($type), $context);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function buildContext(ServiceOrder $order, string $type, array $extra = []): array
    {
        $vehicle = $this->em->find(Vehicle::class, $order->vehicleId());
        $customer = $order->customerId() !== null ? $this->em->find(Customer::class, $order->customerId()) : null;
        $branch = $order->branchId() !== null ? $this->em->find(ServiceBranch::class, $order->branchId()) : null;
        /** @var list<ServiceJob> $jobs */
        $jobs = $this->carservice->jobsForOrder($order->id());
        $appName = (string) $this->config->get('app.name', 'EShop');
        $docTitle = $this->pdfTemplates->documentTitle($type);
        $company = $this->companyProfile($appName);
        $docNumber = strtoupper(substr($order->id(), 0, 8));

        $chrome = $extra['chrome'] ?? null;
        if (!is_array($chrome)) {
            $chrome = $this->pdfTemplates->chrome($type, [
                'brand' => $company['name'],
                'doc_title' => $docTitle,
            ]);
        }

        $internal = $order->internalCost() !== null ? (float) $order->internalCost() : null;
        $external = $order->externalCost() !== null ? (float) $order->externalCost() : null;
        $margin = ($internal !== null && $external !== null) ? $external - $internal : null;
        $lines = $extra['lines'] ?? $this->buildLines($order, $jobs);
        $totals = $this->totalsFromLines($lines);
        $quoteLines = $extra['quoteLines'] ?? $this->quoteLinesFromOrder($order, $jobs, $external);
        $quoteTotals = $extra['quoteTotals'] ?? $this->quoteTotalsFromLines($quoteLines);
        $protocol = IntakeProtocolCatalog::normalize(
            $extra['protocol'] ?? $order->intakeProtocol() ?? []
        );

        $user = $this->auth->user();
        $preparedBy = $user !== null
            ? trim($user->name() ?: ($user->email() ?? ''))
            : $company['name'];

        return array_merge($this->formDefaults(), [
            'order' => $order,
            'vehicle' => $vehicle instanceof Vehicle ? $vehicle : null,
            'customer' => $customer instanceof Customer ? $customer : null,
            'branch' => $branch instanceof ServiceBranch ? $branch : null,
            'jobs' => $jobs,
            'jobSeconds' => $this->carservice->totalJobSeconds($order->id(), $jobs),
            'appName' => $appName,
            'docTitle' => $docTitle,
            'docNumber' => $docNumber,
            'company' => $company,
            'issuedAt' => (new \DateTimeImmutable())->format('d.m.Y'),
            'handoverDate' => $order->handoverAt()?->format('d.m.Y')
                ?? $order->finishedAt()?->format('d.m.Y')
                ?? (new \DateTimeImmutable())->format('d.m.Y'),
            'handoverPlace' => $branch instanceof ServiceBranch ? $branch->name() : ($company['city'] ?: '……………………'),
            'preparedBy' => $preparedBy !== '' ? $preparedBy : $company['name'],
            'serviceHeading' => $order->serviceInfo()
                ? mb_substr(preg_replace("/\s+/u", ' ', $order->serviceInfo()) ?? '', 0, 80)
                : 'Servisní práce na vozidle',
            'lines' => $lines,
            'totals' => $totals,
            'pdfHeader' => (string) ($chrome['header'] ?? ''),
            'pdfFooter' => (string) ($chrome['footer'] ?? ''),
            'signature' => isset($extra['signature']) ? (trim((string) $extra['signature']) ?: null) : null,
            'signatureDate' => $extra['signatureDate'] ?? null,
            'isPreview' => (bool) ($extra['isPreview'] ?? false),
            'quoteLines' => $quoteLines,
            'quoteTotal' => $extra['quoteTotal'] ?? $external,
            'quoteTotals' => $quoteTotals,
            'quoteValidUntil' => $extra['quoteValidUntil'] ?? '14 dní',
            'margin' => $margin,
            'defects' => $extra['defects'] ?? [],
            'defectDeadline' => $extra['defectDeadline'] ?? '—',
            'protocol' => $protocol,
            'zoneLabels' => IntakeProtocolCatalog::zones(),
            'axleItems' => IntakeProtocolCatalog::axleItems(),
        ]);
    }

    /**
     * @param array{header?: string, footer?: string}|null $chrome
     * @return array<string, mixed>
     */
    public function sampleContext(string $type, ?array $chrome = null): array
    {
        $appName = $this->pdfTemplates->brandName();
        $docTitle = $this->pdfTemplates->documentTitle($type);
        $company = $this->companyProfile($appName);
        if ($chrome === null) {
            $chrome = $this->pdfTemplates->chrome($type, [
                'brand' => $company['name'],
                'doc_title' => $docTitle,
            ]);
        }

        $order = new class {
            public function id(): string
            {
                return 'a1b2c3d4-demo-order-preview';
            }

            public function statusLabel(): string
            {
                return 'carservice.status.in_progress';
            }

            public function teamLabel(): string
            {
                return 'carservice.team.body_shop';
            }

            public function typeLabel(): string
            {
                return 'carservice.type.external_customer';
            }

            public function serviceInfo(): ?string
            {
                return "Diagnostika řídicí jednotky\nVýměna oleje a filtru\nKontrola brzd";
            }

            public function paymentType(): ?string
            {
                return 'cash';
            }

            public function internalCost(): ?string
            {
                return '1850.00';
            }

            public function externalCost(): ?string
            {
                return '2560.00';
            }

            public function intakeMileage(): ?int
            {
                return 128450;
            }

            public function intakeFuelLevel(): ?string
            {
                return 'half';
            }

            public function note(): ?string
            {
                return 'Ukázková poznámka k zakázce.';
            }

            public function finishedAt(): ?\DateTimeImmutable
            {
                return null;
            }

            public function intakeAt(): ?\DateTimeImmutable
            {
                return new \DateTimeImmutable('today');
            }

            public function handoverAt(): ?\DateTimeImmutable
            {
                return new \DateTimeImmutable('+3 days');
            }
        };

        $customer = new class {
            public function name(): string
            {
                return 'Jan Novák';
            }

            public function email(): string
            {
                return 'jan.novak@email.cz';
            }

            public function phone(): ?string
            {
                return '+420 777 000 111';
            }

            public function company(): ?string
            {
                return null;
            }
        };

        $vehicle = new class {
            public function plate(): string
            {
                return '1AB 2345';
            }

            public function brand(): ?string
            {
                return 'Škoda';
            }

            public function displayModel(): string
            {
                return 'Octavia 1.6 TDI';
            }

            public function vin(): ?string
            {
                return 'TMBJJ9NE5E0123456';
            }

            public function fuel(): ?string
            {
                return 'Diesel';
            }
        };

        $job = new class {
            public function type(): string
            {
                return 'Diagnostika';
            }

            public function note(): ?string
            {
                return 'Kontrola chybových kódů';
            }

            public function startedAt(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('-2 hours');
            }

            public function endedAt(): ?\DateTimeImmutable
            {
                return new \DateTimeImmutable('-30 minutes');
            }

            public function durationSeconds(): ?int
            {
                return 5400;
            }
        };

        $lines = [
            $this->line('Motorový olej 5W-30', 4.5, 'l', 180.0),
            $this->line('Olejový filtr', 1, 'ks', 320.0),
            $this->line('Servisní práce', 1.5, 'h', 900.0),
        ];
        $sampleQuoteLines = [
            $this->quoteLine('Diagnostika', 'Kontrola řídicí jednotky', 890.0),
            $this->quoteLine('Práce', 'Montáž a seřízení · 1,5 h', 1350.0),
            $this->quoteLine('Díly', 'Filtr oleje', 320.0),
        ];

        return array_merge($this->formDefaults(), [
            'order' => $order,
            'vehicle' => $vehicle,
            'customer' => $customer,
            'branch' => null,
            'jobs' => [$job],
            'jobSeconds' => 5400,
            'appName' => $appName,
            'docTitle' => $docTitle,
            'docNumber' => 'A1B2C3D4',
            'company' => $company,
            'issuedAt' => (new \DateTimeImmutable())->format('d.m.Y'),
            'handoverDate' => (new \DateTimeImmutable())->format('d.m.Y'),
            'handoverPlace' => $company['city'] ?: 'Praha',
            'preparedBy' => 'Technik servisu',
            'serviceHeading' => 'Servisní práce na vozidle',
            'lines' => $lines,
            'totals' => $this->totalsFromLines($lines),
            'pdfHeader' => (string) ($chrome['header'] ?? ''),
            'pdfFooter' => (string) ($chrome['footer'] ?? ''),
            'signature' => 'Jan Novák',
            'signatureDate' => (new \DateTimeImmutable())->format('d.m.Y H:i'),
            'isPreview' => true,
            'quoteLines' => $sampleQuoteLines,
            'quoteTotal' => 2560.0,
            'quoteTotals' => $this->quoteTotalsFromLines($sampleQuoteLines),
            'quoteValidUntil' => '14 dní',
            'margin' => 710.0,
            'defects' => [],
            'defectDeadline' => '—',
            'protocol' => IntakeProtocolCatalog::empty(),
            'zoneLabels' => IntakeProtocolCatalog::zones(),
            'axleItems' => IntakeProtocolCatalog::axleItems(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function companyProfile(string $fallbackName): array
    {
        $name = trim((string) $this->settings->get('invoices.seller_name', '')) ?: $fallbackName;
        $address = trim((string) $this->settings->get('invoices.seller_address', ''));
        $iban = trim((string) $this->settings->get('payments.bank_iban', ''));
        $short = preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'SERVIS';
        $short = strtoupper(substr($short, 0, 8));

        return [
            'name' => $name,
            'short' => $short,
            'address' => $address,
            'city' => $this->guessCity($address),
            'ico' => trim((string) $this->settings->get('invoices.seller_ico', '')),
            'dic' => trim((string) $this->settings->get('invoices.seller_dic', '')),
            'email' => trim((string) $this->settings->get('invoices.seller_email', '')),
            'phone' => '',
            'web' => '',
            'iban' => $iban,
            'bank' => '',
            'vat_payer' => trim((string) $this->settings->get('invoices.seller_dic', '')) !== '',
        ];
    }

    private function guessCity(string $address): string
    {
        if ($address === '') {
            return '';
        }
        $parts = preg_split('/[,\n]/', $address) ?: [];
        $last = trim((string) end($parts));

        return $last !== '' ? $last : '';
    }

    /**
     * @param list<ServiceJob> $jobs
     * @return list<array<string, mixed>>
     */
    private function buildLines(ServiceOrder $order, array $jobs): array
    {
        $lines = [];
        foreach ($jobs as $job) {
            $hours = $job->durationSeconds() !== null ? round($job->durationSeconds() / 3600, 2) : null;
            if ($hours === null || $hours <= 0) {
                continue;
            }
            $lines[] = [
                'description' => $job->type(),
                'note' => $job->note(),
                'qty' => $hours,
                'unit' => 'h',
                'unit_price' => null,
                'net' => null,
                'vat_rate' => null,
                'gross' => null,
            ];
        }

        $external = $order->externalCost() !== null ? (float) $order->externalCost() : null;
        if ($external !== null && $external > 0) {
            $lines[] = $this->line(
                $order->serviceInfo() ? 'Servisní práce a materiál' : 'Cena zakázky',
                1,
                'kpl',
                $external,
            );
        }

        return $lines;
    }

    /**
     * @param list<ServiceJob> $jobs
     * @return list<array{label: string, note: string, amount: float|null, net: float|null, vat_rate: int|null, gross: float|null}>
     */
    private function quoteLinesFromOrder(ServiceOrder $order, array $jobs, ?float $external): array
    {
        $lines = [];
        foreach ($jobs as $job) {
            $hours = $job->durationSeconds() !== null ? round($job->durationSeconds() / 3600, 2) : null;
            $lines[] = [
                'label' => $job->type(),
                'note' => $job->note() ?: ($hours !== null ? $hours . ' h' : ''),
                'amount' => null,
                'net' => null,
                'vat_rate' => null,
                'gross' => null,
            ];
        }
        if ($external !== null) {
            $lines[] = $this->quoteLine('Odhad celkem', 'Orientční cena pro zákazníka', $external);
        }

        return $lines;
    }

    /**
     * Částka je včetně DPH (zákaznická cena).
     *
     * @return array{label: string, note: string, amount: float, net: float, vat_rate: int, gross: float}
     */
    private function quoteLine(string $label, string $note, float $gross): array
    {
        $net = round($gross / (1 + self::VAT_RATE / 100), 2);

        return [
            'label' => $label,
            'note' => $note,
            'amount' => $gross,
            'net' => $net,
            'vat_rate' => (int) self::VAT_RATE,
            'gross' => $gross,
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array{net: ?float, vat: ?float, gross: ?float}
     */
    private function quoteTotalsFromLines(array $lines): array
    {
        $net = 0.0;
        $gross = 0.0;
        $has = false;
        foreach ($lines as $line) {
            if (!isset($line['gross']) || $line['gross'] === null) {
                continue;
            }
            $has = true;
            $gross += (float) $line['gross'];
            $net += (float) ($line['net'] ?? round((float) $line['gross'] / (1 + self::VAT_RATE / 100), 2));
        }
        if (!$has) {
            return ['net' => null, 'vat' => null, 'gross' => null];
        }

        return [
            'net' => round($net, 2),
            'vat' => round($gross - $net, 2),
            'gross' => round($gross, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $description, float $qty, string $unit, float $unitPrice): array
    {
        $net = round($qty * $unitPrice, 2);
        $vat = round($net * (self::VAT_RATE / 100), 2);

        return [
            'description' => $description,
            'note' => null,
            'qty' => $qty,
            'unit' => $unit,
            'unit_price' => $unitPrice,
            'net' => $net,
            'vat_rate' => (int) self::VAT_RATE,
            'gross' => round($net + $vat, 2),
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array{net: ?float, vat: ?float, gross: ?float, words: ?string}
     */
    private function totalsFromLines(array $lines): array
    {
        $net = 0.0;
        $vat = 0.0;
        $gross = 0.0;
        $has = false;
        foreach ($lines as $line) {
            if ($line['net'] === null || $line['gross'] === null) {
                continue;
            }
            $has = true;
            $net += (float) $line['net'];
            $gross += (float) $line['gross'];
            $vat += (float) $line['gross'] - (float) $line['net'];
        }
        if (!$has) {
            return ['net' => null, 'vat' => null, 'gross' => null, 'words' => null];
        }

        return [
            'net' => round($net, 2),
            'vat' => round($vat, 2),
            'gross' => round($gross, 2),
            'words' => $this->amountInWords(round($gross, 2)),
        ];
    }

    private function amountInWords(float $amount): string
    {
        $whole = (int) floor($amount);
        $halere = (int) round(($amount - $whole) * 100);
        $units = ['', 'jedna', 'dvě', 'tři', 'čtyři', 'pět', 'šest', 'sedm', 'osm', 'devět'];
        $teens = ['deset', 'jedenáct', 'dvanáct', 'třináct', 'čtrnáct', 'patnáct', 'šestnáct', 'sedmnáct', 'osmnáct', 'devatenáct'];
        $tens = ['', '', 'dvacet', 'třicet', 'čtyřicet', 'padesát', 'šedesát', 'sedmdesát', 'osmdesát', 'devadesát'];
        $hundreds = ['', 'sto', 'dvě stě', 'tři sta', 'čtyři sta', 'pět set', 'šest set', 'sedm set', 'osm set', 'devět set'];

        if ($whole === 0) {
            $words = 'nula';
        } elseif ($whole >= 1000000) {
            return $this->money->format($amount);
        } else {
            $parts = [];
            $thousands = intdiv($whole, 1000);
            $rest = $whole % 1000;
            if ($thousands > 0) {
                if ($thousands === 1) {
                    $parts[] = 'tisíc';
                } elseif ($thousands >= 2 && $thousands <= 4) {
                    $parts[] = $this->underThousand($thousands, $units, $teens, $tens, $hundreds) . ' tisíce';
                } else {
                    $parts[] = $this->underThousand($thousands, $units, $teens, $tens, $hundreds) . ' tisíc';
                }
            }
            if ($rest > 0 || $thousands === 0) {
                $parts[] = $this->underThousand($rest, $units, $teens, $tens, $hundreds);
            }
            $words = trim(implode(' ', $parts));
        }

        $unit = match ($this->money->defaultCode()) {
            'CZK' => 'korun',
            'EUR' => 'eur',
            default => $this->money->symbol(),
        };

        return $words . ' ' . $unit . ($halere > 0 ? sprintf(' %02d/100', $halere) : '');
    }

    /**
     * @param list<string> $units
     * @param list<string> $teens
     * @param list<string> $tens
     * @param list<string> $hundreds
     */
    private function underThousand(int $n, array $units, array $teens, array $tens, array $hundreds): string
    {
        $h = intdiv($n, 100);
        $r = $n % 100;
        $out = [];
        if ($h > 0) {
            $out[] = $hundreds[$h];
        }
        if ($r >= 10 && $r < 20) {
            $out[] = $teens[$r - 10];
        } else {
            $t = intdiv($r, 10);
            $u = $r % 10;
            if ($t > 0) {
                $out[] = $tens[$t];
            }
            if ($u > 0) {
                $out[] = $units[$u];
            }
        }

        return trim(implode(' ', $out));
    }

    /**
     * @return array<string, mixed>
     */
    private function formDefaults(): array
    {
        return [
            'equipment' => IntakeProtocolCatalog::equipment(),
            'services' => IntakeProtocolCatalog::services(),
            'inspectionItems' => IntakeProtocolCatalog::inspectionItems(),
            'bodyItems' => IntakeProtocolCatalog::bodyItems(),
            'protocol' => IntakeProtocolCatalog::empty(),
            'zoneLabels' => IntakeProtocolCatalog::zones(),
            'axleItems' => IntakeProtocolCatalog::axleItems(),
        ];
    }
}
