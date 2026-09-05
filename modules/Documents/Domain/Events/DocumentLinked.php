<?php
declare(strict_types=1);
namespace Modules\Documents\Domain\Events;
final class DocumentLinked extends AbstractDocumentEvent
{
    public static function make(string $id, array $data = [], ?array $actor = null): self
    {
        return new self('document.linked', ['type' => 'document', 'id' => $id], $data, $actor);
    }
}