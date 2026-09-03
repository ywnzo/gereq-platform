<?php

declare(strict_types=1);

namespace Gereq\Admin\Resource;

final class ResourceDefinition
{
    /**
     * @param list<ResourceField> $fields
     * @param class-string $entityClass
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $permission,
        public readonly string $entityClass,
        public readonly ?string $icon,
        public readonly string $singular,
        public readonly ?string $controller,
        public readonly array $fields,
    ) {
    }

    /** @return list<ResourceField> */
    public function indexFields(): array
    {
        return array_values(array_filter($this->fields, static fn (ResourceField $f): bool => $f->showOnIndex));
    }

    /** @return list<ResourceField> */
    public function formFields(): array
    {
        return array_values(array_filter($this->fields, static fn (ResourceField $f): bool => $f->showOnForm));
    }

    /** @return list<ResourceField> */
    public function detailFields(): array
    {
        return array_values(array_filter($this->fields, static fn (ResourceField $f): bool => $f->showOnDetail));
    }
}
