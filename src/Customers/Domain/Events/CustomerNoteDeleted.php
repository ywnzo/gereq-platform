<?php

declare(strict_types=1);

namespace Gereq\Customers\Domain\Events;

final class CustomerNoteDeleted extends AbstractCustomerPlatformEvent
{
    /**
     * @param array<string, mixed> $data
     * @param array{type: string, id: ?string}|null $actor
     */
    public static function make(string $noteId, array $data = [], ?array $actor = null): self
    {
        $payload = $data !== [] ? $data : ['id' => $noteId];

        return new self('customer.note.deleted', ['type' => 'customer_note', 'id' => $noteId], $payload, $actor);
    }
}
