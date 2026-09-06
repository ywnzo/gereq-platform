<?php

declare(strict_types=1);

namespace Modules\Documents\Application\Pdf;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\File\FileManager;
use Modules\Documents\Domain\Document;
use Modules\Documents\Domain\DocumentLink;

/**
 * Orchestrates: PdfDocumentType → Twig/HTML → PDF binary → Document (+ optional link).
 */
final class PdfDocumentGenerator
{
    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly FileManager $files,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function render(PdfDocumentType $document, ?PdfRenderOptions $options = null): PdfRenderResult
    {
        return $this->renderer->renderDocument($document, $options);
    }

    public function generate(PdfDocumentType $document, PdfPersistOptions $persist, ?PdfRenderOptions $options = null): Document
    {
        $result = $this->render($document, $options);
        $filename = $this->resolveFilename($document, $persist, $result->extension);

        return $this->persistBinary(
            title: $document->title(),
            binary: $result->binary,
            originalName: $filename,
            mime: $result->mime,
            persist: $persist,
        );
    }

    /**
     * Uloží hotové PDF (např. stažené z iDokladu) jako Document + vazby.
     */
    public function storeBinary(
        string $title,
        string $binary,
        string $originalName,
        string $mime,
        PdfPersistOptions $persist,
    ): Document {
        $name = basename(str_replace(['\\', "\0"], '', $originalName));
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'document.pdf';
        }
        if (!str_contains($name, '.')) {
            $name .= '.pdf';
        }

        return $this->persistBinary($title, $binary, $name, $mime, $persist);
    }

    private function persistBinary(
        string $title,
        string $binary,
        string $originalName,
        string $mime,
        PdfPersistOptions $persist,
    ): Document {
        $directory = trim(str_replace('\\', '/', $persist->directory), '/');
        $path = ($directory !== '' ? $directory . '/' : '') . $this->uniqueBasename($originalName);

        $this->files->filesystem()->write($path, $binary);

        $entity = new Document(
            $title,
            $path,
            $originalName,
            $mime,
            strlen($binary),
            $persist->uploadedBy,
        );
        $this->em->persist($entity);

        $this->attachLinks($entity->id(), $persist);
        $this->em->flush();

        return $entity;
    }

    private function attachLinks(string $documentId, PdfPersistOptions $persist): void
    {
        $links = [];
        if ($persist->entityType !== null && $persist->entityId !== null && $persist->entityType !== '' && $persist->entityId !== '') {
            $links[] = ['entityType' => $persist->entityType, 'entityId' => $persist->entityId];
        }
        foreach ($persist->extraLinks as $link) {
            $type = trim((string) ($link['entityType'] ?? ''));
            $id = trim((string) ($link['entityId'] ?? ''));
            if ($type === '' || $id === '') {
                continue;
            }
            $links[] = ['entityType' => $type, 'entityId' => $id];
        }

        $seen = [];
        foreach ($links as $link) {
            $key = $link['entityType'] . "\0" . $link['entityId'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $this->em->persist(new DocumentLink($documentId, $link['entityType'], $link['entityId']));
        }
    }

    private function resolveFilename(PdfDocumentType $document, PdfPersistOptions $persist, string $extension): string
    {
        $name = $persist->filename ?? $document->suggestedFilename();
        $name = basename(str_replace(['\\', "\0"], '', $name));
        if ($name === '' || $name === '.' || $name === '..') {
            $name = $document->type() . '.' . $extension;
        }
        if (!str_contains($name, '.')) {
            $name .= '.' . $extension;
        }

        return $name;
    }

    private function uniqueBasename(string $filename): string
    {
        $dot = strrpos($filename, '.');
        if ($dot === false) {
            return $filename . '-' . bin2hex(random_bytes(4));
        }

        return substr($filename, 0, $dot) . '-' . bin2hex(random_bytes(4)) . substr($filename, $dot);
    }
}
