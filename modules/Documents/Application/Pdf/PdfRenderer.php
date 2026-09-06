<?php

declare(strict_types=1);

namespace Modules\Documents\Application\Pdf;

use Twig\Environment;

/**
 * Renders Twig HTML and converts it to PDF (Dompdf) with HTML fallback.
 */
final class PdfRenderer
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderHtml(string $view, array $data = []): string
    {
        return $this->twig->render($view, $data);
    }

    public function htmlToBinary(string $html, ?PdfRenderOptions $options = null): PdfRenderResult
    {
        $options ??= PdfRenderOptions::a4();

        if (class_exists(\Dompdf\Dompdf::class)) {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => $options->remoteEnabled]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper($options->paper, $options->orientation);
            $dompdf->render();

            return new PdfRenderResult($dompdf->output(), 'application/pdf', 'pdf');
        }

        return new PdfRenderResult($html, 'text/html', 'html');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $view, array $data = [], ?PdfRenderOptions $options = null): PdfRenderResult
    {
        return $this->htmlToBinary($this->renderHtml($view, $data), $options);
    }

    public function renderDocument(PdfDocumentType $document, ?PdfRenderOptions $options = null): PdfRenderResult
    {
        return $this->render($document->view(), $document->data(), $options);
    }
}
