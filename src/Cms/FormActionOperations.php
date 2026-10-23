<?php

declare(strict_types=1);

namespace Gereq\Cms;

/** Private operations on records created by an action, owned by the registering module. */
interface FormActionOperations
{
    public function viewPermission(): string;
    public function retryPermission(): string;
    /** @return array{route: string, params: array<string, string>}|null */
    public function recordLink(string $id): ?array;
    /** @param list<string> $ids
     * @return array<string, array{route: string, params: array<string, string>}>
     */
    public function recordLinks(array $ids): array;
    public function retryNotification(string $id): string;
}
