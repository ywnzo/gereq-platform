<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Modules\Documents\Application\Pdf\ArrayPdfDocument;
use Modules\Documents\Application\Pdf\PdfRenderer;
use Modules\Documents\Application\Pdf\PdfRenderOptions;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class PdfRendererTest extends TestCase
{
    public function testRendersHtmlFromTwig(): void
    {
        $twig = new Environment(new ArrayLoader([
            'doc.html.twig' => '<h1>{{ title }}</h1><p>{{ body }}</p>',
        ]));
        $renderer = new PdfRenderer($twig);

        $html = $renderer->renderHtml('doc.html.twig', ['title' => 'Test', 'body' => 'Hello']);

        self::assertStringContainsString('<h1>Test</h1>', $html);
        self::assertStringContainsString('<p>Hello</p>', $html);
    }

    public function testHtmlToBinaryProducesPdfWhenDompdfAvailable(): void
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            self::markTestSkipped('dompdf not installed');
        }

        $twig = new Environment(new ArrayLoader([]));
        $renderer = new PdfRenderer($twig);
        $result = $renderer->htmlToBinary(
            '<html><body><h1>Invoice</h1></body></html>',
            PdfRenderOptions::a4(),
        );

        self::assertTrue($result->isPdf());
        self::assertSame('pdf', $result->extension);
        self::assertStringStartsWith('%PDF', $result->binary);
    }

    public function testArrayPdfDocumentContract(): void
    {
        $doc = new ArrayPdfDocument(
            'simple',
            '@documents/pdf/simple.html.twig',
            ['title' => 'X', 'rows' => [['label' => 'A', 'value' => '1']]],
            'Document X',
            'document-x.pdf',
        );

        self::assertSame('simple', $doc->type());
        self::assertSame('Document X', $doc->title());
        self::assertSame('document-x.pdf', $doc->suggestedFilename());
        self::assertArrayHasKey('rows', $doc->data());
    }
}
