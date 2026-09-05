<?php
declare(strict_types=1);
namespace Modules\Documents\Domain\Events;
final class DocumentUploaded extends AbstractDocumentEvent
{
    public static function make(string $id, array $data = [], ?array $actor = null): self
    {
        return new self('document.uploaded', ['type' => 'document', 'id' => $id], $data, $actor);
    }
}