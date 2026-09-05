<?php
declare(strict_types=1);
namespace Modules\Documents\Domain\Events;
final class DocumentDeleted extends AbstractDocumentEvent
{
    public static function make(string $id, array $data = [], ?array $actor = null): self
    {
        return new self('document.deleted', ['type' => 'document', 'id' => $id], $data, $actor);
    }
}