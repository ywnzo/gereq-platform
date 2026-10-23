<?php

declare(strict_types=1);

namespace Gereq\Cms;

/** Attachment lifecycle contract; business modules never access CMS storage tables. */
interface FormAttachmentStorage
{
    /** @return list<array{id: string, filename: string, route: string, params: array<string, string>}> */
    public function forRecord(string $action, string $recordId): array;
    public function removeForRecord(string $action, string $recordId): void;
}
