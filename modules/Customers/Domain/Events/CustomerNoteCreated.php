<?php

declare(strict_types=1);

namespace Modules\Customers\Domain\Events;

final class CustomerNoteCreated extends AbstractCustomerPlatformEvent
{
    /**
     * @param array<string, mixed> $data
     * @param array{type: string, id: ?string}|null $actor
     */
    public static function make(string $noteId, array $data, ?array $actor = null): self
    {
        return new self('customer.note.created', ['type' => 'customer_note', 'id' => $noteId], $data, $actor);
    }
}
