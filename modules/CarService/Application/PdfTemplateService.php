<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

use Gereq\Config\Repository as Config;
use Gereq\Setting\Settings;

/**
 * Resolvuje HTML hlavičku/patičku PDF: typ → společné → výchozí.
 * Placeholdery: {{brand}}, {{app_name}}, {{date}}, {{doc_title}}.
 */
final class PdfTemplateService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, string> $vars
     */
    public function header(string $documentType, array $vars = []): string
    {
        return $this->resolve($documentType, PdfTemplateCatalog::PART_HEADER, $vars);
    }

    /**
     * @param array<string, string> $vars
     */
    public function footer(string $documentType, array $vars = []): string
    {
        return $this->resolve($documentType, PdfTemplateCatalog::PART_FOOTER, $vars);
    }

    /**
     * @param array<string, string> $vars
     * @return array{header: string, footer: string}
     */
    public function chrome(string $documentType, array $vars = []): array
    {
        return [
            'header' => $this->header($documentType, $vars),
            'footer' => $this->footer($documentType, $vars),
        ];
    }

    /**
     * Náhled z aktuálního formuláře (uložené hodnoty netřeba).
     *
     * @param array<string, mixed> $posted form field => value
     * @param array<string, string> $vars
     * @return array{header: string, footer: string}
     */
    public function chromeFromPosted(string $documentType, array $posted, array $vars = []): array
    {
        $typeHeader = trim((string) ($posted[PdfTemplateCatalog::formField(PdfTemplateCatalog::typeKey($documentType, PdfTemplateCatalog::PART_HEADER))] ?? ''));
        $typeFooter = trim((string) ($posted[PdfTemplateCatalog::formField(PdfTemplateCatalog::typeKey($documentType, PdfTemplateCatalog::PART_FOOTER))] ?? ''));
        $globalHeader = trim((string) ($posted[PdfTemplateCatalog::formField(PdfTemplateCatalog::globalKey(PdfTemplateCatalog::PART_HEADER))] ?? ''));
        $globalFooter = trim((string) ($posted[PdfTemplateCatalog::formField(PdfTemplateCatalog::globalKey(PdfTemplateCatalog::PART_FOOTER))] ?? ''));

        $headerSrc = $typeHeader !== ''
            ? $typeHeader
            : PdfTemplateCatalog::effectiveTemplate($globalHeader, PdfTemplateCatalog::PART_HEADER);
        $footerSrc = $typeFooter !== ''
            ? $typeFooter
            : PdfTemplateCatalog::effectiveTemplate($globalFooter, PdfTemplateCatalog::PART_FOOTER);

        return [
            'header' => $this->applyPlaceholders($headerSrc, $documentType, $vars),
            'footer' => $this->applyPlaceholders($footerSrc, $documentType, $vars),
        ];
    }

    public function documentTitle(string $documentType): string
    {
        return $this->defaultDocTitle($documentType);
    }

    public function brandName(): string
    {
        return (string) $this->config->get('app.name', 'EShop');
    }

    /**
     * @param array<string, string> $vars
     */
    private function resolve(string $documentType, string $part, array $vars): string
    {
        $typeHtml = trim((string) $this->settings->get(
            PdfTemplateCatalog::typeKey($documentType, $part),
            '',
        ));
        if ($typeHtml !== '') {
            return $this->applyPlaceholders($typeHtml, $documentType, $vars);
        }

        $globalHtml = PdfTemplateCatalog::effectiveTemplate(
            (string) $this->settings->get(PdfTemplateCatalog::globalKey($part), ''),
            $part,
        );

        return $this->applyPlaceholders($globalHtml, $documentType, $vars);
    }

    /**
     * @param array<string, string> $vars
     */
    private function applyPlaceholders(string $html, string $documentType, array $vars): string
    {
        $brand = (string) ($vars['brand'] ?? $vars['app_name'] ?? $this->config->get('app.name', 'EShop'));
        $docTitle = (string) ($vars['doc_title'] ?? $this->defaultDocTitle($documentType));
        $date = (string) ($vars['date'] ?? (new \DateTimeImmutable())->format('d.m.Y H:i'));

        $map = [
            '{{brand}}' => $brand,
            '{{app_name}}' => $brand,
            '{{date}}' => $date,
            '{{doc_title}}' => $docTitle,
        ];
        foreach ($vars as $key => $value) {
            $map['{{' . $key . '}}'] = $value;
        }

        return strtr($html, $map);
    }

    private function defaultDocTitle(string $documentType): string
    {
        return match ($documentType) {
            PdfTemplateCatalog::TYPE_WORKSHEET => 'Zakázkový list',
            PdfTemplateCatalog::TYPE_INTAKE => 'Protokol příjmu',
            PdfTemplateCatalog::TYPE_HANDOVER => 'Předávací protokol',
            PdfTemplateCatalog::TYPE_QUOTE => 'Nabídka oprav',
            PdfTemplateCatalog::TYPE_COST_SHEET => 'Nákladový list',
            default => 'Dokument',
        };
    }

    private function builtinDefault(string $part): string
    {
        return $part === PdfTemplateCatalog::PART_HEADER
            ? PdfTemplateCatalog::defaultHeaderHtml()
            : PdfTemplateCatalog::defaultFooterHtml();
    }
}
